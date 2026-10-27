<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('batches.view');

$businessId=(int)($_SESSION['business_id']??0);
$businessName=(string)($_SESSION['business_name']??'Business');
$branchId=(int)($_SESSION['branch_id']??0);
$branchName=(string)($_SESSION['branch_name']??'Branch');
$userId=(int)($_SESSION['user_id']??0);

$canAdd=hasPermission('batches.add');
$canDelete=hasPermission('batches.delete');

if($businessId<=0||$branchId<=0){http_response_code(403);exit('Business or branch not available.');}

if(!function_exists('be')){function be($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}}
if(!function_exists('bflash')){function bflash(string $m,string $t='success'):void{$_SESSION['bflash']=$m;$_SESSION['bflash_type']=$t;}}

if(empty($_SESSION['batch_csrf']))$_SESSION['batch_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['batch_csrf'];

try{
    $s=$conn->prepare("UPDATE product_batches
        SET status=CASE
            WHEN quantity<=0 THEN 'Depleted'
            WHEN expiry_date IS NOT NULL AND expiry_date<CURDATE() THEN 'Expired'
            ELSE 'Active'
        END
        WHERE business_id=? AND branch_id=?");
    $s->execute([$businessId,$branchId]);
}catch(Throwable $e){}


/*
|--------------------------------------------------------------------------
| AUTO SYNC: STOCK OUT -> BATCH OUT (FEFO)
|--------------------------------------------------------------------------
| If aggregate product_stock has gone below the total quantity held by
| batches, reduce the difference automatically from the earliest-expiring
| active batches. This keeps batch quantities aligned with real stock even
| when stock was reduced by another module that is not yet batch-aware.
|
| Important:
| - Stock increases are NOT auto-added to a batch because an expiry date is
|   unknown. New incoming expiry stock must get its own batch.
| - Existing batch records are never deleted. When quantity reaches zero,
|   the batch becomes Depleted and remains as history.
*/
try{
    $syncProducts=$conn->prepare("
        SELECT p.id,
               COALESCE(ps.quantity,0) AS stock_qty,
               COALESCE((
                   SELECT SUM(pb.quantity)
                   FROM product_batches pb
                   WHERE pb.business_id=p.business_id
                     AND pb.branch_id=?
                     AND pb.product_id=p.id
               ),0) AS batch_qty
        FROM products p
        LEFT JOIN product_stock ps
          ON ps.business_id=p.business_id
         AND ps.branch_id=?
         AND ps.product_id=p.id
        WHERE p.business_id=?
          AND p.track_expiry=1
    ");
    $syncProducts->execute([$branchId,$branchId,$businessId]);

    foreach($syncProducts->fetchAll(PDO::FETCH_ASSOC) as $sp){
        $realStock=max(0,(float)$sp['stock_qty']);
        $batchStock=max(0,(float)$sp['batch_qty']);
        $difference=round($batchStock-$realStock,3);

        if($difference<=0)continue;

        $conn->beginTransaction();

        $getBatches=$conn->prepare("
            SELECT id,quantity
            FROM product_batches
            WHERE business_id=?
              AND branch_id=?
              AND product_id=?
              AND quantity>0
            ORDER BY
              CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
              expiry_date ASC,
              id ASC
            FOR UPDATE
        ");
        $getBatches->execute([$businessId,$branchId,(int)$sp['id']]);

        foreach($getBatches->fetchAll(PDO::FETCH_ASSOC) as $sb){
            if($difference<=0)break;

            $currentQty=(float)$sb['quantity'];
            $take=min($currentQty,$difference);
            $newQty=round($currentQty-$take,3);

            $updateBatch=$conn->prepare("
                UPDATE product_batches
                SET quantity=?,
                    status=CASE
                        WHEN ?<=0 THEN 'Depleted'
                        WHEN expiry_date IS NOT NULL AND expiry_date<CURDATE() THEN 'Expired'
                        ELSE 'Active'
                    END
                WHERE id=? AND business_id=? AND branch_id=?
            ");
            $updateBatch->execute([$newQty,$newQty,(int)$sb['id'],$businessId,$branchId]);

            $move=$conn->prepare("
                INSERT INTO batch_movements
                (business_id,branch_id,batch_id,product_id,movement_type,
                 reference_type,reference_id,qty_in,qty_out,balance_after,note,created_by)
                VALUES(?,?,?,?,'ADJUSTMENT_OUT','AUTO_STOCK_SYNC',NULL,0,?,?,?,?)
            ");
            $move->execute([
                $businessId,
                $branchId,
                (int)$sb['id'],
                (int)$sp['id'],
                $take,
                $newQty,
                'Automatic FEFO batch deduction because product stock decreased',
                $userId
            ]);

            $difference=round($difference-$take,3);
        }

        $conn->commit();
    }
}catch(Throwable $e){
    if($conn->inTransaction())$conn->rollBack();
}


/*
|--------------------------------------------------------------------------
| REPAIR TRANSFER-IN BATCHES (ROBUST)
|--------------------------------------------------------------------------
| Repairs destination batches for stock transfers that increased product_stock
| but did not create/update the destination product batch.
|
| Priority:
| 1) Use source TRANSFER_OUT batch_movements when available.
| 2) If old transfer has no batch movement, fall back to source batch metadata
|    for the transferred product (FEFO order). This allows old broken transfers
|    to be repaired when the source batch record still exists, even if depleted.
*/
try{
    $q=$conn->prepare("
        SELECT sm.reference_id AS transfer_id,
               sm.product_id,
               sm.base_qty,
               st.from_branch_id,
               st.to_branch_id,
               st.transfer_date
        FROM stock_movements sm
        INNER JOIN stock_transfers st
            ON st.id=sm.reference_id
           AND st.business_id=sm.business_id
        WHERE sm.business_id=?
          AND sm.branch_id=?
          AND sm.movement_type='TRANSFER_IN'
          AND sm.reference_type='STOCK_TRANSFER'
          AND st.to_branch_id=?
        ORDER BY sm.id ASC
    ");
    $q->execute([$businessId,$branchId,$branchId]);

    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $tr){
        $transferId=(int)$tr['transfer_id'];
        $productId=(int)$tr['product_id'];
        $transferQty=round((float)$tr['base_qty'],3);
        $fromBranchId=(int)$tr['from_branch_id'];

        if($transferQty<=0)continue;

        // If destination already has a batch TRANSFER_IN for this transfer/product,
        // do not apply it again.
        $chk=$conn->prepare("
            SELECT COALESCE(SUM(qty_in),0)
            FROM batch_movements
            WHERE business_id=?
              AND branch_id=?
              AND product_id=?
              AND movement_type='TRANSFER_IN'
              AND reference_type='STOCK_TRANSFER'
              AND reference_id=?
        ");
        $chk->execute([$businessId,$branchId,$productId,$transferId]);
        $already=(float)$chk->fetchColumn();

        $remaining=round($transferQty-$already,3);
        if($remaining<=0.0005)continue;

        // First try exact source batch transfer history.
        $src=$conn->prepare("
            SELECT pb.id,pb.batch_number,pb.purchase_date,pb.expiry_date,
                   bm.qty_out AS source_move_qty
            FROM batch_movements bm
            INNER JOIN product_batches pb ON pb.id=bm.batch_id
            WHERE bm.business_id=?
              AND bm.branch_id=?
              AND bm.product_id=?
              AND bm.movement_type='TRANSFER_OUT'
              AND bm.reference_type='STOCK_TRANSFER'
              AND bm.reference_id=?
            ORDER BY bm.id ASC
        ");
        $src->execute([$businessId,$fromBranchId,$productId,$transferId]);
        $sources=$src->fetchAll(PDO::FETCH_ASSOC);

        // Fallback for transfers created before batch-aware transfer logic.
        if(!$sources){
            $src=$conn->prepare("
                SELECT pb.id,pb.batch_number,pb.purchase_date,pb.expiry_date,
                       pb.quantity AS current_batch_qty
                FROM product_batches pb
                WHERE pb.business_id=?
                  AND pb.branch_id=?
                  AND pb.product_id=?
                ORDER BY
                    CASE WHEN pb.expiry_date IS NULL THEN 1 ELSE 0 END,
                    pb.expiry_date ASC,
                    pb.id ASC
            ");
            $src->execute([$businessId,$fromBranchId,$productId]);
            $raw=$src->fetchAll(PDO::FETCH_ASSOC);

            // For an old broken transfer we cannot recover the exact historical
            // split if several source batches existed. Use FEFO metadata.
            foreach($raw as $r){
                $r['source_move_qty']=$remaining;
                $sources[]=$r;
                break;
            }
        }

        if(!$sources)continue;

        $conn->beginTransaction();

        foreach($sources as $sr){
            if($remaining<=0.0005)break;

            $suggested=isset($sr['source_move_qty']) ? (float)$sr['source_move_qty'] : $remaining;
            $moveQty=min($remaining,max(0,$suggested));
            if($moveQty<=0)continue;

            // Match destination by Product + Batch Number.
            if($sr['batch_number']!==null && trim((string)$sr['batch_number'])!==''){
                $find=$conn->prepare("
                    SELECT id,quantity
                    FROM product_batches
                    WHERE business_id=?
                      AND branch_id=?
                      AND product_id=?
                      AND batch_number=?
                    ORDER BY id ASC
                    LIMIT 1
                    FOR UPDATE
                ");
                $find->execute([$businessId,$branchId,$productId,$sr['batch_number']]);
            }else{
                $find=$conn->prepare("
                    SELECT id,quantity
                    FROM product_batches
                    WHERE business_id=?
                      AND branch_id=?
                      AND product_id=?
                      AND batch_number IS NULL
                      AND expiry_date <=> ?
                    ORDER BY id ASC
                    LIMIT 1
                    FOR UPDATE
                ");
                $find->execute([$businessId,$branchId,$productId,$sr['expiry_date']]);
            }

            $dst=$find->fetch(PDO::FETCH_ASSOC);

            if($dst){
                $batchId=(int)$dst['id'];
                $newQty=round((float)$dst['quantity']+$moveQty,3);

                $up=$conn->prepare("
                    UPDATE product_batches
                    SET quantity=?,
                        purchase_date=?,
                        expiry_date=?,
                        status=CASE
                            WHEN ?<=0 THEN 'Depleted'
                            WHEN ? IS NOT NULL AND ?<CURDATE() THEN 'Expired'
                            ELSE 'Active'
                        END
                    WHERE id=? AND business_id=? AND branch_id=?
                ");
                $up->execute([
                    $newQty,
                    $sr['purchase_date'],
                    $sr['expiry_date'],
                    $newQty,
                    $sr['expiry_date'],
                    $sr['expiry_date'],
                    $batchId,$businessId,$branchId
                ]);
            }else{
                $status=($sr['expiry_date']!==null && $sr['expiry_date']<date('Y-m-d'))
                    ?'Expired':'Active';

                $ins=$conn->prepare("
                    INSERT INTO product_batches
                    (business_id,branch_id,product_id,batch_number,purchase_item_id,quantity,
                     purchase_date,expiry_date,status,created_by)
                    VALUES(?,?,?,?,NULL,?,?,?,?,?)
                ");
                $ins->execute([
                    $businessId,$branchId,$productId,
                    $sr['batch_number'],$moveQty,
                    $sr['purchase_date'],$sr['expiry_date'],
                    $status,$userId
                ]);
                $batchId=(int)$conn->lastInsertId();
                $newQty=$moveQty;
            }

            $mv=$conn->prepare("
                INSERT INTO batch_movements
                (business_id,branch_id,batch_id,product_id,movement_type,
                 reference_type,reference_id,qty_in,qty_out,balance_after,note,created_by)
                VALUES(?,?,?,?,'TRANSFER_IN','STOCK_TRANSFER',?,?,0,?,?,?)
            ");
            $mv->execute([
                $businessId,$branchId,$batchId,$productId,$transferId,
                $moveQty,$newQty,
                'Automatic destination batch repair for stock transfer',
                $userId
            ]);

            $remaining=round($remaining-$moveQty,3);
        }

        $conn->commit();
    }
}catch(Throwable $e){
    if($conn->inTransaction())$conn->rollBack();
}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='add_batch'){
    if(!$canAdd){http_response_code(403);exit('Access denied.');}
    if(!hash_equals($_SESSION['batch_csrf'],(string)($_POST['csrf_token']??''))){
        bflash('Security token is invalid.','error');header('Location: batches.php');exit;
    }

    $productId=(int)($_POST['product_id']??0);
    $batchNo=trim((string)($_POST['batch_number']??''));
    $qty=(float)($_POST['quantity']??0);
    $purchaseDate=trim((string)($_POST['purchase_date']??''));
    $expiryDate=trim((string)($_POST['expiry_date']??''));

    try{
        if($productId<=0||$qty<=0||$expiryDate==='')throw new RuntimeException('Product, Quantity and Expiry Date are required.');

        $s=$conn->prepare("SELECT p.id,p.product_name,p.track_expiry,p.status,u.unit_name base_unit_name,
            COALESCE(ps.quantity,0) stock_qty
            FROM products p
            INNER JOIN units u ON u.id=p.base_unit_id AND u.business_id=p.business_id
            LEFT JOIN product_stock ps ON ps.business_id=p.business_id AND ps.branch_id=? AND ps.product_id=p.id
            WHERE p.id=? AND p.business_id=? LIMIT 1");
        $s->execute([$branchId,$productId,$businessId]);
        $pr=$s->fetch(PDO::FETCH_ASSOC);

        if(!$pr)throw new RuntimeException('Product not found.');
        if($pr['status']!=='Active')throw new RuntimeException('Product is inactive.');
        if((int)$pr['track_expiry']!==1)throw new RuntimeException('This product is not configured for expiry tracking.');

        $stockQty=(float)$pr['stock_qty'];
        if($stockQty<=0)throw new RuntimeException('This product has no stock in this branch.');

        $s=$conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM product_batches
            WHERE business_id=? AND branch_id=? AND product_id=?");
        $s->execute([$businessId,$branchId,$productId]);
        $allocated=(float)$s->fetchColumn();
        $available=max(0,round($stockQty-$allocated,3));

        if($qty>$available)throw new RuntimeException('Only '.number_format($available,3).' '.$pr['base_unit_name'].' is available to allocate.');

        if($purchaseDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$purchaseDate))throw new RuntimeException('Purchase Date is invalid.');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expiryDate))throw new RuntimeException('Expiry Date is invalid.');
        if($purchaseDate!==''&&$expiryDate<$purchaseDate)throw new RuntimeException('Expiry Date cannot be earlier than Purchase Date.');

        if($batchNo!==''){
            $s=$conn->prepare("SELECT COUNT(*) FROM product_batches
                WHERE business_id=? AND branch_id=? AND product_id=? AND batch_number=?");
            $s->execute([$businessId,$branchId,$productId,$batchNo]);
            if((int)$s->fetchColumn()>0)throw new RuntimeException('This Batch Number already exists for this product.');
        }

        $status=$expiryDate<date('Y-m-d')?'Expired':'Active';

        $conn->beginTransaction();

        $s=$conn->prepare("INSERT INTO product_batches
            (business_id,branch_id,product_id,batch_number,purchase_item_id,quantity,purchase_date,expiry_date,status,created_by)
            VALUES(?,?,?,?,NULL,?,?,?,?,?)");
        $s->execute([$businessId,$branchId,$productId,$batchNo!==''?$batchNo:null,$qty,$purchaseDate!==''?$purchaseDate:null,$expiryDate,$status,$userId]);
        $batchId=(int)$conn->lastInsertId();

        $s=$conn->prepare("INSERT INTO batch_movements
            (business_id,branch_id,batch_id,product_id,movement_type,reference_type,reference_id,qty_in,qty_out,balance_after,note,created_by)
            VALUES(?,?,?,?,'OPENING','BATCH_ALLOCATION',?,?,0,?,?,?)");
        $s->execute([$businessId,$branchId,$batchId,$productId,$batchId,$qty,$qty,'Existing stock allocated to batch',$userId]);

        $conn->commit();
        bflash('Batch registered successfully. Existing stock was allocated; stock was not added again.');
    }catch(Throwable $e){
        if($conn->inTransaction())$conn->rollBack();
        bflash($e->getMessage(),'error');
    }
    header('Location: batches.php');exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete_batch'){
    if(!$canDelete){http_response_code(403);exit('Access denied.');}
    if(!hash_equals($_SESSION['batch_csrf'],(string)($_POST['csrf_token']??''))){
        bflash('Security token is invalid.','error');header('Location: batches.php');exit;
    }

    $batchId=(int)($_POST['batch_id']??0);
    try{
        $s=$conn->prepare("SELECT id FROM product_batches WHERE id=? AND business_id=? AND branch_id=?");
        $s->execute([$batchId,$businessId,$branchId]);
        if(!$s->fetch())throw new RuntimeException('Batch not found.');

        $s=$conn->prepare("SELECT COUNT(*) FROM batch_movements WHERE batch_id=? AND movement_type<>'OPENING'");
        $s->execute([$batchId]);
        if((int)$s->fetchColumn()>0)throw new RuntimeException('This batch has transaction history and cannot be deleted.');

        $s=$conn->prepare("DELETE FROM product_batches WHERE id=? AND business_id=? AND branch_id=?");
        $s->execute([$batchId,$businessId,$branchId]);
        bflash('Batch deleted successfully.');
    }catch(Throwable $e){bflash($e->getMessage(),'error');}
    header('Location: batches.php');exit;
}

try{
    $s=$conn->prepare("SELECT p.id,p.product_name,p.product_code,p.barcode,
        COALESCE(ps.quantity,0) stock_qty,u.unit_name base_unit_name,u.short_name base_unit_short_name,
        COALESCE((SELECT SUM(pb.quantity) FROM product_batches pb
            WHERE pb.business_id=p.business_id AND pb.branch_id=? AND pb.product_id=p.id),0) allocated_qty
        FROM products p
        INNER JOIN units u ON u.id=p.base_unit_id AND u.business_id=p.business_id
        LEFT JOIN product_stock ps ON ps.business_id=p.business_id AND ps.branch_id=? AND ps.product_id=p.id
        WHERE p.business_id=? AND p.status='Active' AND p.track_expiry=1
          AND COALESCE(ps.quantity,0) > 0
        ORDER BY p.product_name");
    $s->execute([$branchId,$branchId,$businessId]);
    $products=$s->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){$products=[];}

$productMap=[];
foreach($products as $x){
    $productMap[(int)$x['id']]=[
        'stock_qty'=>(float)$x['stock_qty'],
        'allocated_qty'=>(float)$x['allocated_qty'],
        'available_qty'=>max(0,round((float)$x['stock_qty']-(float)$x['allocated_qty'],3)),
        'base_unit_name'=>$x['base_unit_name']
    ];
}

try{
    $s=$conn->prepare("SELECT pb.id,pb.product_id,pb.batch_number,pb.quantity,pb.purchase_date,pb.expiry_date,pb.status,
        p.product_name,p.product_code,u.short_name base_unit_short_name,usr.full_name created_by_name,
        DATEDIFF(pb.expiry_date,CURDATE()) days_left
        FROM product_batches pb
        INNER JOIN products p ON p.id=pb.product_id AND p.business_id=pb.business_id
        INNER JOIN units u ON u.id=p.base_unit_id AND u.business_id=p.business_id
        INNER JOIN users usr ON usr.id=pb.created_by
        WHERE pb.business_id=? AND pb.branch_id=?
        ORDER BY pb.expiry_date ASC,pb.id DESC");
    $s->execute([$businessId,$branchId]);
    $batches=$s->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){$batches=[];}

$stats=['total'=>0,'expired'=>0,'critical'=>0,'soon'=>0,'safe'=>0];
foreach($batches as $b){
    $stats['total']++;
    if((float)$b['quantity']<=0)continue;
    $d=$b['days_left']!==null?(int)$b['days_left']:null;
    if($d===null)continue;
    if($d<0)$stats['expired']++;
    elseif($d<=7)$stats['critical']++;
    elseif($d<=30)$stats['soon']++;
    else $stats['safe']++;
}

$flash=$_SESSION['bflash']??null;
$flashType=$_SESSION['bflash_type']??'success';
unset($_SESSION['bflash'],$_SESSION['bflash_type']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Batch & Expiry - <?=be($businessName)?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={darkMode:'class'}</script>
<script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="../assets/css/app.css">
<style>
.fi{height:44px;width:100%;border-radius:.75rem;border:1px solid #e2e8f0;background:#f8fafc;padding:.7rem .85rem;font-size:.75rem;outline:none}
.fi:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgb(59 130 246/.08)}
.dark .fi{border-color:#334155;background:#0f172a;color:#e2e8f0}
</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
<?php require_once __DIR__.'/includes/sidebar.php'; ?>
<div class="min-h-screen transition-all duration-300 lg:ml-[270px]">
<?php require_once __DIR__.'/includes/header.php'; ?>
<main class="p-4 md:p-6">

<div class="mb-5 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
<div>
<div class="mb-1 text-[9px] font-bold uppercase tracking-wider text-slate-400">Inventory / Batch & Expiry</div>
<h1 class="text-xl font-black text-slate-900 dark:text-white">Batch & Expiry</h1>
<p class="mt-1 text-[10px] text-slate-500">Track product batches and expiry dates in <?=be($branchName)?>.</p>
</div>
<?php if($canAdd): ?>
<button onclick="openBatchModal()" class="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white">
<i data-lucide="package-plus" class="h-4 w-4"></i> Register Batch
</button>
<?php endif; ?>
</div>

<?php if($flash): ?>
<div class="mb-5 rounded-2xl border px-4 py-3 text-[11px] font-semibold <?=$flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>">
<?=be($flash)?>
</div>
<?php endif; ?>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
<?php
$cards=[
['Total Batches',$stats['total'],'boxes'],
['Expired',$stats['expired'],'circle-x'],
['Critical ≤ 7',$stats['critical'],'triangle-alert'],
['Soon ≤ 30',$stats['soon'],'clock-3'],
['Safe',$stats['safe'],'shield-check']
];
foreach($cards as $c):
?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
<div class="flex items-center gap-3">
<div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800"><i data-lucide="<?=be($c[2])?>" class="h-5 w-5"></i></div>
<div><p class="text-[8px] font-bold uppercase text-slate-400"><?=be($c[0])?></p><p class="text-lg font-black"><?=be($c[1])?></p></div>
</div></div>
<?php endforeach; ?>
</div>

<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
<div class="flex flex-col gap-3 border-b p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
<div><h2 class="text-sm font-black">Batch List</h2><p class="mt-1 text-[9px] text-slate-500">Expiry status updates automatically.</p></div>
<div class="relative w-full sm:w-80">
<i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-slate-400"></i>
<input id="batchSearch" class="h-10 w-full rounded-xl border bg-slate-50 pl-9 pr-3 text-[10px] dark:border-slate-700 dark:bg-slate-800" placeholder="Search ID, product, batch...">
</div>
</div>

<div class="overflow-x-auto"><table class="min-w-full">
<thead class="bg-slate-50 dark:bg-slate-800"><tr>
<?php foreach(['ID','Product','Batch No','Qty','Purchase Date','Expiry Date','Expiry Status','Status','Created By','Actions'] as $h): ?>
<th class="whitespace-nowrap px-4 py-3 text-left text-[9px] font-bold uppercase text-slate-500"><?=be($h)?></th>
<?php endforeach; ?>
</tr></thead>
<tbody class="divide-y dark:divide-slate-800">
<?php if(!$batches): ?><tr><td colspan="10" class="px-5 py-14 text-center text-xs text-slate-500">No batches found.</td></tr><?php endif; ?>

<?php foreach($batches as $b):
$d=$b['days_left']!==null?(int)$b['days_left']:null;
if((float)$b['quantity']<=0){$label='Depleted';$cls='bg-slate-100 text-slate-600';}
elseif($d<0){$label='Expired';$cls='bg-red-50 text-red-700';}
elseif($d<=7){$label=$d.' day(s) left';$cls='bg-orange-50 text-orange-700';}
elseif($d<=30){$label=$d.' days left';$cls='bg-amber-50 text-amber-700';}
else{$label='Safe · '.$d.' days';$cls='bg-emerald-50 text-emerald-700';}
$search=implode(' ',[$b['id'],$b['product_id'],$b['product_name'],$b['product_code'],$b['batch_number'],$b['expiry_date'],$label,$b['status'],$b['created_by_name']]);
?>
<tr class="batch-row hover:bg-slate-50 dark:hover:bg-slate-800/50" data-search="<?=be($search)?>">
<td class="px-4 py-4 text-[10px] font-bold">#<?=$b['id']?></td>
<td class="px-4 py-4"><div class="text-[10px] font-black"><?=be($b['product_name'])?></div><div class="text-[8px] text-slate-400">Product #<?=$b['product_id']?></div></td>
<td class="px-4 py-4 text-[10px] font-bold"><?=be($b['batch_number']?:'—')?></td>
<td class="px-4 py-4 text-[10px] font-black"><?=number_format((float)$b['quantity'],3)?> <?=be($b['base_unit_short_name'])?></td>
<td class="px-4 py-4 text-[10px]"><?=be($b['purchase_date']?:'—')?></td>
<td class="px-4 py-4 text-[10px] font-bold"><?=be($b['expiry_date'])?></td>
<td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-[9px] font-bold <?=$cls?>"><?=be($label)?></span></td>
<td class="px-4 py-4 text-[9px] font-bold"><?=be($b['status'])?></td>
<td class="px-4 py-4 text-[10px]"><?=be($b['created_by_name'])?></td>
<td class="px-4 py-4">
<?php if($canDelete): ?>
<button type="button" onclick='openDelete(<?=$b["id"]?>,<?=json_encode(($b["batch_number"]?:("#".$b["id"]))." · ".$b["product_name"],JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="flex h-8 w-8 items-center justify-center rounded-lg text-red-600 hover:bg-red-50">
<i data-lucide="trash-2" class="h-4 w-4"></i>
</button>
<?php else: ?>—<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<tr id="searchEmpty" class="hidden"><td colspan="10" class="px-5 py-14 text-center text-xs">No batch matches your search.</td></tr>
</tbody></table></div>
</section>
</main>
<?php require_once __DIR__.'/includes/footer.php'; ?>
</div>

<?php if($canAdd): ?>
<div id="batchModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
<div class="flex max-h-[88vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900">
<div class="flex items-center justify-between border-b p-5 dark:border-slate-800">
<div><h3 class="font-extrabold">Register Batch</h3><p class="text-[10px] text-slate-500">Allocate existing stock to an expiry batch.</p></div>
<button type="button" onclick="closeBatchModal()" class="rounded-xl p-2"><i data-lucide="x"></i></button>
</div>

<form method="post" class="overflow-y-auto p-5" onsubmit="return validateBatch()">
<input type="hidden" name="csrf_token" value="<?=be($csrf)?>">
<input type="hidden" name="action" value="add_batch">
<input type="hidden" name="product_id" id="productId">

<div id="err" class="mb-4 hidden rounded-xl border border-red-200 bg-red-50 p-3 text-[10px] font-bold text-red-700"></div>

<div class="mb-4">
<label class="mb-1 block text-[10px] font-bold">Product *</label>
<div class="relative" id="productSel">
<button type="button" class="fi flex items-center justify-between text-left" onclick="productMenu.classList.toggle('hidden')">
<span id="productLabel">Select Expiry Product</span><i data-lucide="chevron-down" class="h-4 w-4"></i>
</button>
<div id="productMenu" class="absolute z-[150] mt-1 hidden w-full rounded-xl border bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
<div class="p-2"><input id="productSearch" class="fi !h-9" placeholder="Search ID, product, code, barcode..."></div>
<div class="max-h-52 overflow-y-auto p-1">
<?php foreach($products as $x): ?>
<button type="button" class="popt w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800"
data-search="<?=be($x['id'].' '.$x['product_name'].' '.($x['product_code']??'').' '.($x['barcode']??''))?>"
onclick='pickProduct(<?=$x["id"]?>,<?=json_encode("#".$x["id"]." · ".$x["product_name"],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>
<span class="block text-[10px] font-bold"><?=be($x['product_name'])?></span>
<span class="text-[8px] text-slate-400">#<?=$x['id']?> · Stock <?=number_format((float)$x['stock_qty'],3)?> · Unallocated <?=number_format(max(0,(float)$x['stock_qty']-(float)$x['allocated_qty']),3)?></span>
</button>
<?php endforeach; ?>
</div></div></div></div>

<div id="stockInfo" class="mb-4 hidden rounded-xl bg-blue-50 p-3 text-[10px] font-semibold text-blue-700">
<div id="stockText" class="font-black"></div><div id="allocatedText" class="mt-1"></div>
</div>

<div class="mb-4"><label class="mb-1 block text-[10px] font-bold">Batch Number</label><input name="batch_number" class="fi" placeholder="e.g. LOT-2026-001"></div>
<div class="mb-4"><label class="mb-1 block text-[10px] font-bold">Quantity (Base Unit) *</label><input id="qty" name="quantity" type="number" min=".001" step=".001" class="fi" required></div>
<div class="grid gap-4 sm:grid-cols-2">
<div><label class="mb-1 block text-[10px] font-bold">Purchase Date</label><input name="purchase_date" type="date" class="fi"></div>
<div><label class="mb-1 block text-[10px] font-bold">Expiry Date *</label><input id="expiryDate" name="expiry_date" type="date" class="fi" required></div>
</div>

<div id="preview" class="mt-4 rounded-xl border bg-slate-50 p-3 text-[10px] font-bold dark:border-slate-700 dark:bg-slate-800">Choose Expiry Date to see alert status.</div>

<div class="mt-5 flex justify-end gap-2 border-t pt-4 dark:border-slate-800">
<button type="button" onclick="closeBatchModal()" class="h-10 rounded-xl border px-4 text-[10px] font-bold">Cancel</button>
<button class="h-10 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white">Save Batch</button>
</div>
</form>
</div></div>
<?php endif; ?>

<?php if($canDelete): ?>
<div id="deleteModal" class="fixed inset-0 z-[130] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
<div class="w-full max-w-sm rounded-3xl bg-white p-5 shadow-2xl dark:bg-slate-900">
<h3 class="text-center text-base font-black">Delete Batch?</h3>
<p id="deleteText" class="mt-2 text-center text-[10px] text-slate-500"></p>
<form method="post" class="mt-5 flex justify-center gap-2">
<input type="hidden" name="csrf_token" value="<?=be($csrf)?>">
<input type="hidden" name="action" value="delete_batch">
<input type="hidden" name="batch_id" id="deleteId">
<button type="button" onclick="closeDelete()" class="h-10 rounded-xl border px-4 text-[10px] font-bold">Cancel</button>
<button class="h-10 rounded-xl bg-red-600 px-4 text-[10px] font-bold text-white">Delete</button>
</form></div></div>
<?php endif; ?>

<script>
const products=<?=json_encode($productMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
let pid='';

function openBatchModal(){batchModal.classList.remove('hidden');batchModal.classList.add('flex')}
function closeBatchModal(){batchModal.classList.add('hidden');batchModal.classList.remove('flex')}
function pickProduct(id,label){
    pid=String(id);productId.value=id;productLabel.textContent=label;productMenu.classList.add('hidden');
    const p=products[id];if(!p)return;
    stockInfo.classList.remove('hidden');
    stockText.textContent='Current Stock: '+fmt(p.stock_qty)+' '+p.base_unit_name;
    allocatedText.textContent='Allocated: '+fmt(p.allocated_qty)+' · Available: '+fmt(p.available_qty)+' '+p.base_unit_name;

    // Auto-fill the quantity from the product's currently unallocated stock.
    // It remains editable so one stock can still be split into multiple expiry batches.
    qty.value=Number(p.available_qty||0).toFixed(3);
}
function validateBatch(){
    err.classList.add('hidden');
    if(!productId.value)return fail('Please select a product.');
    const p=products[pid],q=Number(qty.value||0);
    if(q<=0)return fail('Quantity must be greater than zero.');
    if(p&&q>Number(p.available_qty))return fail('Only '+fmt(p.available_qty)+' '+p.base_unit_name+' is available to allocate.');
    if(!expiryDate.value)return fail('Expiry Date is required.');
    return true;
}
function fail(m){err.textContent=m;err.classList.remove('hidden');return false}
function fmt(v){return Number(v||0).toFixed(3).replace(/\.?0+$/,'')}
function expiryPreview(){
    if(!expiryDate.value){preview.textContent='Choose Expiry Date to see alert status.';return}
    const a=new Date();a.setHours(0,0,0,0);
    const b=new Date(expiryDate.value+'T00:00:00');
    const d=Math.ceil((b-a)/86400000);
    preview.textContent=d<0?'Expired · '+Math.abs(d)+' day(s) ago':d<=7?'Critical · '+d+' day(s) left':d<=30?'Expiring Soon · '+d+' days left':'Safe · '+d+' days left';
}
function openDelete(id,label){deleteId.value=id;deleteText.textContent=label;deleteModal.classList.remove('hidden');deleteModal.classList.add('flex')}
function closeDelete(){deleteModal.classList.add('hidden');deleteModal.classList.remove('flex')}

expiryDate?.addEventListener('change',expiryPreview);
productSearch?.addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('.popt').forEach(x=>x.style.display=(x.dataset.search||'').toLowerCase().includes(q)?'':'none')});
batchSearch?.addEventListener('input',function(){const q=this.value.toLowerCase();let n=0;document.querySelectorAll('.batch-row').forEach(x=>{const ok=(x.dataset.search||'').toLowerCase().includes(q);x.style.display=ok?'':'none';if(ok)n++});searchEmpty.classList.toggle('hidden',n!==0)});
document.addEventListener('click',e=>{if(!e.target.closest('#productSel'))productMenu?.classList.add('hidden')});
document.addEventListener('DOMContentLoaded',()=>lucide?.createIcons());
</script>
</body></html>
]=