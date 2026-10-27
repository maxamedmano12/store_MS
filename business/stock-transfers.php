<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('stock.view');

$businessId=(int)($_SESSION['business_id']??0);
$businessName=(string)($_SESSION['business_name']??'Business');
$branchId=(int)($_SESSION['branch_id']??0);
$branchName=(string)($_SESSION['branch_name']??'Branch');
$userId=(int)($_SESSION['user_id']??0);
$canAdd=hasPermission('stock.add');

if($businessId<=0||$branchId<=0){http_response_code(403);exit('Business or branch not available.');}
function trE($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function trFlash(string $m,string $t='success'):void{$_SESSION['tr_flash']=$m;$_SESSION['tr_flash_type']=$t;}
if(empty($_SESSION['tr_csrf']))$_SESSION['tr_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['tr_csrf'];

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='create_transfer'){
    if(!$canAdd){http_response_code(403);exit('Access denied.');}
    if(!hash_equals($_SESSION['tr_csrf'],(string)($_POST['csrf_token']??''))){
        trFlash('Security token is invalid.','error');header('Location: stock-transfers.php');exit;
    }
    $to=(int)($_POST['to_branch_id']??0);
    $product=(int)($_POST['product_id']??0);
    $pu=(int)($_POST['product_unit_id']??0);
    $qty=(float)($_POST['quantity']??0);
    $note=trim((string)($_POST['note']??''));
    try{
        if($to<=0||$product<=0||$pu<=0||$qty<=0)throw new RuntimeException('Destination Branch, Product, Unit and Quantity are required.');
        if($to===$branchId)throw new RuntimeException('Source and destination branch cannot be the same.');

        $s=$conn->prepare("SELECT id,branch_name FROM branches WHERE id=? AND business_id=? AND status='Active' LIMIT 1");
        $s->execute([$to,$businessId]); $toBranch=$s->fetch(PDO::FETCH_ASSOC);
        if(!$toBranch)throw new RuntimeException('Destination branch is invalid or inactive.');

        $s=$conn->prepare("SELECT p.product_name,p.status product_status,pu.conversion_qty,pu.status unit_status,u.unit_name,u.short_name
            FROM products p JOIN product_units pu ON pu.product_id=p.id AND pu.business_id=p.business_id
            JOIN units u ON u.id=pu.unit_id AND u.business_id=p.business_id
            WHERE p.id=? AND p.business_id=? AND pu.id=? LIMIT 1");
        $s->execute([$product,$businessId,$pu]); $item=$s->fetch(PDO::FETCH_ASSOC);
        if(!$item)throw new RuntimeException('Selected Product Unit is invalid.');
        if($item['product_status']!=='Active'||$item['unit_status']!=='Active')throw new RuntimeException('Product or Product Unit is inactive.');
        $conversion=(float)$item['conversion_qty'];
        if($conversion<=0)throw new RuntimeException('Invalid conversion quantity.');
        $baseQty=round($qty*$conversion,3);

        $conn->beginTransaction();

        $s=$conn->prepare("SELECT id,quantity,average_cost FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? FOR UPDATE");
        $s->execute([$businessId,$branchId,$product]); $src=$s->fetch(PDO::FETCH_ASSOC);
        if(!$src)throw new RuntimeException('This product has no stock in the source branch.');
        $srcQty=(float)$src['quantity']; $srcAvg=(float)$src['average_cost'];
        if($srcQty<$baseQty)throw new RuntimeException('Insufficient stock. Available: '.number_format($srcQty,3).' Base Units.');

        $transferNo='TRF-'.date('YmdHis').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
        $s=$conn->prepare("INSERT INTO stock_transfers
            (business_id,transfer_no,from_branch_id,to_branch_id,transfer_date,status,note,created_by,completed_by,completed_at)
            VALUES(?,?,?,?,NOW(),'Completed',?,?,?,NOW())");
        $s->execute([$businessId,$transferNo,$branchId,$to,$note!==''?$note:null,$userId,$userId]);
        $transferId=(int)$conn->lastInsertId();

        $totalCost=round($baseQty*$srcAvg,4);
        $s=$conn->prepare("INSERT INTO stock_transfer_items
            (business_id,transfer_id,product_id,product_unit_id,transaction_qty,conversion_qty,base_qty,unit_cost,total_cost)
            VALUES(?,?,?,?,?,?,?,?,?)");
        $s->execute([$businessId,$transferId,$product,$pu,$qty,$conversion,$baseQty,$srcAvg,$totalCost]);

        $newSrc=round($srcQty-$baseQty,3);
        $s=$conn->prepare("UPDATE product_stock SET quantity=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $s->execute([$newSrc,$src['id']]);

        $s=$conn->prepare("SELECT id,quantity,average_cost FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? FOR UPDATE");
        $s->execute([$businessId,$to,$product]); $dst=$s->fetch(PDO::FETCH_ASSOC);
        if($dst){
            $dq=(float)$dst['quantity'];$da=(float)$dst['average_cost'];$newDst=round($dq+$baseQty,3);
            $newAvg=$newDst>0?round((($dq*$da)+($baseQty*$srcAvg))/$newDst,4):$srcAvg;
            $s=$conn->prepare("UPDATE product_stock SET quantity=?,average_cost=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $s->execute([$newDst,$newAvg,$dst['id']]);
        }else{
            $newDst=$baseQty;
            $s=$conn->prepare("INSERT INTO product_stock(business_id,branch_id,product_id,quantity,average_cost) VALUES(?,?,?,?,?)");
            $s->execute([$businessId,$to,$product,$newDst,$srcAvg]);
        }

        $s=$conn->prepare("INSERT INTO stock_movements
            (business_id,branch_id,product_id,product_unit_id,movement_type,reference_type,reference_id,transaction_qty,conversion_qty,base_qty,qty_in,qty_out,unit_cost,total_cost,balance_after,note,created_by)
            VALUES(?,?,?,?,'TRANSFER_OUT','STOCK_TRANSFER',?,?,?,?,0,?,?,?,?,?,?)");
        $s->execute([$businessId,$branchId,$product,$pu,$transferId,$qty,$conversion,$baseQty,$baseQty,$srcAvg,$totalCost,$newSrc,'Transfer '.$transferNo.' to '.$toBranch['branch_name'],$userId]);

        $s=$conn->prepare("INSERT INTO stock_movements
            (business_id,branch_id,product_id,product_unit_id,movement_type,reference_type,reference_id,transaction_qty,conversion_qty,base_qty,qty_in,qty_out,unit_cost,total_cost,balance_after,note,created_by)
            VALUES(?,?,?,?,'TRANSFER_IN','STOCK_TRANSFER',?,?,?,?,?,0,?,?,?,?,?)");
        $s->execute([$businessId,$to,$product,$pu,$transferId,$qty,$conversion,$baseQty,$baseQty,$srcAvg,$totalCost,$newDst,'Transfer '.$transferNo.' from '.$branchName,$userId]);

        /*
         * AUTO BATCH TRANSFER (FEFO)
         * Same batch number, purchase date and expiry date move automatically.
         */
        $s=$conn->prepare("SELECT track_expiry FROM products WHERE id=? AND business_id=? LIMIT 1");
        $s->execute([$product,$businessId]);
        $trackExpiry=(int)$s->fetchColumn();

        if($trackExpiry===1){
            $s=$conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM product_batches
                WHERE business_id=? AND branch_id=? AND product_id=? AND quantity>0");
            $s->execute([$businessId,$branchId,$product]);
            $allocatedBatchQty=(float)$s->fetchColumn();

            if($allocatedBatchQty+0.0005 >= $baseQty){
                $remainingBatchQty=$baseQty;

                $s=$conn->prepare("SELECT id,batch_number,quantity,purchase_date,expiry_date
                    FROM product_batches
                    WHERE business_id=? AND branch_id=? AND product_id=? AND quantity>0
                    ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
                             expiry_date ASC,id ASC
                    FOR UPDATE");
                $s->execute([$businessId,$branchId,$product]);

                foreach($s->fetchAll(PDO::FETCH_ASSOC) as $sourceBatch){
                    if($remainingBatchQty<=0.0005) break;

                    $sourceBatchQty=(float)$sourceBatch['quantity'];
                    $moveQty=min($sourceBatchQty,$remainingBatchQty);
                    $sourceAfter=round($sourceBatchQty-$moveQty,3);

                    $u=$conn->prepare("UPDATE product_batches
                        SET quantity=?,
                            status=CASE
                                WHEN ?<=0 THEN 'Depleted'
                                WHEN expiry_date IS NOT NULL AND expiry_date<CURDATE() THEN 'Expired'
                                ELSE 'Active'
                            END
                        WHERE id=? AND business_id=? AND branch_id=?");
                    $u->execute([$sourceAfter,$sourceAfter,(int)$sourceBatch['id'],$businessId,$branchId]);

                    $m=$conn->prepare("INSERT INTO batch_movements
                        (business_id,branch_id,batch_id,product_id,movement_type,reference_type,reference_id,
                         qty_in,qty_out,balance_after,note,created_by)
                        VALUES(?,?,?,?,'TRANSFER_OUT','STOCK_TRANSFER',?,0,?,?,?,?)");
                    $m->execute([$businessId,$branchId,(int)$sourceBatch['id'],$product,$transferId,
                        $moveQty,$sourceAfter,'Transfer '.$transferNo.' to '.$toBranch['branch_name'],$userId]);

                    /*
                     * Destination matching rule:
                     * Same Product + same Batch Number = same batch.
                     * This also finds an old Depleted batch whose quantity is 0.
                     * Purchase/expiry dates are refreshed from the source batch.
                     */
                    if($sourceBatch['batch_number']!==null && trim((string)$sourceBatch['batch_number'])!==''){
                        $d=$conn->prepare("SELECT id,quantity FROM product_batches
                            WHERE business_id=? AND branch_id=? AND product_id=? AND batch_number=?
                            ORDER BY id ASC LIMIT 1 FOR UPDATE");
                        $d->execute([$businessId,$to,$product,$sourceBatch['batch_number']]);
                    }else{
                        $d=$conn->prepare("SELECT id,quantity FROM product_batches
                            WHERE business_id=? AND branch_id=? AND product_id=?
                              AND batch_number IS NULL
                              AND purchase_date <=> ?
                              AND expiry_date <=> ?
                            ORDER BY id ASC LIMIT 1 FOR UPDATE");
                        $d->execute([$businessId,$to,$product,$sourceBatch['purchase_date'],$sourceBatch['expiry_date']]);
                    }
                    $destinationBatch=$d->fetch(PDO::FETCH_ASSOC);

                    if($destinationBatch){
                        $destinationBatchId=(int)$destinationBatch['id'];
                        $destinationAfter=round((float)$destinationBatch['quantity']+$moveQty,3);
                        $u=$conn->prepare("UPDATE product_batches
                            SET quantity=?,
                                purchase_date=?,
                                expiry_date=?,
                                status=CASE
                                    WHEN ?<=0 THEN 'Depleted'
                                    WHEN ? IS NOT NULL AND ?<CURDATE() THEN 'Expired'
                                    ELSE 'Active'
                                END
                            WHERE id=? AND business_id=? AND branch_id=?");
                        $u->execute([
                            $destinationAfter,
                            $sourceBatch['purchase_date'],
                            $sourceBatch['expiry_date'],
                            $destinationAfter,
                            $sourceBatch['expiry_date'],
                            $sourceBatch['expiry_date'],
                            $destinationBatchId,$businessId,$to
                        ]);
                    }else{
                        $destinationStatus=($sourceBatch['expiry_date']!==null &&
                            $sourceBatch['expiry_date']<date('Y-m-d'))?'Expired':'Active';

                        $i=$conn->prepare("INSERT INTO product_batches
                            (business_id,branch_id,product_id,batch_number,purchase_item_id,quantity,
                             purchase_date,expiry_date,status,created_by)
                            VALUES(?,?,?,?,NULL,?,?,?,?,?)");
                        $i->execute([$businessId,$to,$product,$sourceBatch['batch_number'],$moveQty,
                            $sourceBatch['purchase_date'],$sourceBatch['expiry_date'],$destinationStatus,$userId]);
                        $destinationBatchId=(int)$conn->lastInsertId();
                        $destinationAfter=$moveQty;
                    }

                    $m=$conn->prepare("INSERT INTO batch_movements
                        (business_id,branch_id,batch_id,product_id,movement_type,reference_type,reference_id,
                         qty_in,qty_out,balance_after,note,created_by)
                        VALUES(?,?,?,?,'TRANSFER_IN','STOCK_TRANSFER',?,?,0,?,?,?)");
                    $m->execute([$businessId,$to,$destinationBatchId,$product,$transferId,
                        $moveQty,$destinationAfter,'Transfer '.$transferNo.' from '.$branchName,$userId]);

                    $remainingBatchQty=round($remainingBatchQty-$moveQty,3);
                }

                if($remainingBatchQty>0.0005){
                    throw new RuntimeException('Batch quantities could not fully cover this transfer.');
                }
            }
        }

        $conn->commit();
        if(function_exists('logActivity')){try{logActivity($conn,'CREATE','Stock Transfer',$transferId,'Completed '.$transferNo,null,['product_id'=>$product,'base_qty'=>$baseQty,'to_branch_id'=>$to],$businessId,$branchId);}catch(Throwable $e){}}
        trFlash('Transfer completed successfully: '.$qty.' '.$item['unit_name'].' × '.$conversion.' = '.$baseQty.' Base Units.');
    }catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();trFlash($e->getMessage(),'error');}
    header('Location: stock-transfers.php');exit;
}

try{$s=$conn->prepare("SELECT id,branch_name FROM branches WHERE business_id=? AND status='Active' AND id<>? ORDER BY branch_name");$s->execute([$businessId,$branchId]);$branches=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$branches=[];}
try{$s=$conn->prepare("SELECT p.id,p.product_name,p.product_code,p.barcode,ps.quantity,ps.average_cost,u.unit_name base_unit_name,u.short_name base_unit_short_name
FROM product_stock ps JOIN products p ON p.id=ps.product_id AND p.business_id=ps.business_id JOIN units u ON u.id=p.base_unit_id AND u.business_id=p.business_id
WHERE ps.business_id=? AND ps.branch_id=? AND ps.quantity>0 AND p.status='Active' ORDER BY p.product_name");$s->execute([$businessId,$branchId]);$products=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$products=[];}
try{$s=$conn->prepare("SELECT pu.id,pu.product_id,pu.conversion_qty,pu.is_base_unit,u.unit_name,u.short_name FROM product_units pu
JOIN products p ON p.id=pu.product_id AND p.business_id=pu.business_id JOIN units u ON u.id=pu.unit_id AND u.business_id=pu.business_id
WHERE pu.business_id=? AND pu.status='Active' AND p.status='Active' ORDER BY pu.product_id,pu.is_base_unit DESC,pu.conversion_qty");$s->execute([$businessId]);$pus=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$pus=[];}
$unitsMap=[];foreach($pus as $u)$unitsMap[(int)$u['product_id']][]=['id'=>(int)$u['id'],'unit_name'=>$u['unit_name'],'short_name'=>$u['short_name'],'conversion_qty'=>(float)$u['conversion_qty'],'is_base_unit'=>(int)$u['is_base_unit']];
$stockMap=[];foreach($products as $p)$stockMap[(int)$p['id']]=['quantity'=>(float)$p['quantity'],'base_unit_name'=>$p['base_unit_name'],'base_unit_short_name'=>$p['base_unit_short_name']];
try{$s=$conn->prepare("SELECT st.id,st.transfer_no,st.transfer_date,st.status,fb.branch_name from_name,tb.branch_name to_name,u.full_name,
COUNT(sti.id) item_count,COALESCE(SUM(sti.base_qty),0) total_qty,COALESCE(SUM(sti.total_cost),0) total_value
FROM stock_transfers st JOIN branches fb ON fb.id=st.from_branch_id JOIN branches tb ON tb.id=st.to_branch_id JOIN users u ON u.id=st.created_by
LEFT JOIN stock_transfer_items sti ON sti.transfer_id=st.id WHERE st.business_id=? GROUP BY st.id,st.transfer_no,st.transfer_date,st.status,fb.branch_name,tb.branch_name,u.full_name ORDER BY st.id DESC LIMIT 100");
$s->execute([$businessId]);$transfers=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$transfers=[];}
$flash=$_SESSION['tr_flash']??null;$flashType=$_SESSION['tr_flash_type']??'success';unset($_SESSION['tr_flash'],$_SESSION['tr_flash_type']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stock Transfers - <?=trE($businessName)?></title>
<script src="https://cdn.tailwindcss.com"></script><script>tailwind.config={darkMode:'class'}</script><script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="../assets/css/app.css">
<style>.fi{height:44px;width:100%;border-radius:.75rem;border:1px solid #e2e8f0;background:#f8fafc;padding:.7rem .85rem;font-size:.75rem;outline:none}.fi:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgb(59 130 246/.08)}.dark .fi{border-color:#334155;background:#0f172a;color:#e2e8f0}</style></head>
<body class="min-h-screen bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
<?php require_once __DIR__.'/includes/sidebar.php';?><div class="min-h-screen transition-all duration-300 lg:ml-[270px]"><?php require_once __DIR__.'/includes/header.php';?>
<main class="p-4 md:p-6">
<div class="mb-5 flex flex-col gap-4 md:flex-row md:items-center md:justify-between"><div><div class="mb-1 text-[9px] font-bold uppercase tracking-wider text-slate-400">Inventory / Stock Transfers</div><h1 class="text-xl font-black text-slate-900 dark:text-white">Stock Transfers</h1><p class="mt-1 text-[10px] text-slate-500">Move stock from <?=trE($branchName)?> to another branch.</p></div>
<?php if($canAdd):?><button onclick="openModal()" class="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white"><i data-lucide="arrow-right-left" class="h-4 w-4"></i> New Transfer</button><?php endif;?></div>
<?php if($flash):?><div class="mb-5 rounded-2xl border px-4 py-3 text-[11px] font-semibold <?=$flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><?=trE($flash)?></div><?php endif;?>
<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
<div class="flex flex-col gap-3 border-b p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-sm font-black">Transfer History</h2><p class="mt-1 text-[9px] text-slate-500">All branch transfers for this business.</p></div><div class="relative w-full sm:w-80"><i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-slate-400"></i><input id="search" class="h-10 w-full rounded-xl border bg-slate-50 pl-9 pr-3 text-[10px] dark:border-slate-700 dark:bg-slate-800" placeholder="Search ID, transfer, branch, user..."></div></div>
<div class="overflow-x-auto"><table class="min-w-full"><thead class="bg-slate-50 dark:bg-slate-800"><tr><?php foreach(['ID','Transfer No','From','To','Items','Base Qty','Value','Status','By','Date'] as $h):?><th class="whitespace-nowrap px-4 py-3 text-left text-[9px] font-bold uppercase text-slate-500"><?=trE($h)?></th><?php endforeach;?></tr></thead><tbody class="divide-y dark:divide-slate-800">
<?php if(!$transfers):?><tr><td colspan="10" class="px-5 py-14 text-center text-xs text-slate-500">No stock transfers yet.</td></tr><?php endif;?>
<?php foreach($transfers as $t):$ds=implode(' ',[$t['id'],$t['transfer_no'],$t['from_name'],$t['to_name'],$t['full_name'],$t['status']]);?>
<tr class="row" data-search="<?=trE($ds)?>"><td class="px-4 py-4 text-[10px] font-bold">#<?=$t['id']?></td><td class="px-4 py-4 text-[10px] font-black text-blue-600"><?=trE($t['transfer_no'])?></td><td class="px-4 py-4 text-[10px]"><?=trE($t['from_name'])?></td><td class="px-4 py-4 text-[10px]"><?=trE($t['to_name'])?></td><td class="px-4 py-4 text-[10px]"><?=$t['item_count']?></td><td class="px-4 py-4 text-[10px] font-bold"><?=number_format((float)$t['total_qty'],3)?></td><td class="px-4 py-4 text-[10px]">$<?=number_format((float)$t['total_value'],2)?></td><td class="px-4 py-4"><span class="rounded-full bg-emerald-50 px-2 py-1 text-[9px] font-bold text-emerald-700"><?=trE($t['status'])?></span></td><td class="px-4 py-4 text-[10px]"><?=trE($t['full_name'])?></td><td class="whitespace-nowrap px-4 py-4 text-[9px]"><?=date('d M Y h:i A',strtotime($t['transfer_date']))?></td></tr><?php endforeach;?>
<tr id="empty" class="hidden"><td colspan="10" class="px-5 py-14 text-center text-xs">No transfer matches your search.</td></tr></tbody></table></div></section></main>
<?php require_once __DIR__.'/includes/footer.php';?></div>

<?php if($canAdd):?><div id="modal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm"><div class="flex max-h-[88vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900">
<div class="flex items-center justify-between border-b p-5 dark:border-slate-800"><div><h3 class="font-extrabold">New Stock Transfer</h3><p class="text-[10px] text-slate-500">From: <?=trE($branchName)?> · Conversion is automatic.</p></div><button type="button" onclick="closeModal()" class="rounded-xl p-2"><i data-lucide="x"></i></button></div>
<form method="post" class="overflow-y-auto p-5" onsubmit="return validateForm()"><input type="hidden" name="csrf_token" value="<?=trE($csrf)?>"><input type="hidden" name="action" value="create_transfer"><input type="hidden" name="to_branch_id" id="branchId"><input type="hidden" name="product_id" id="productId"><input type="hidden" name="product_unit_id" id="unitId">
<div id="err" class="mb-4 hidden rounded-xl border border-red-200 bg-red-50 p-3 text-[10px] font-bold text-red-700"></div>
<?php
function menuBox($id,$label,$placeholder,$options){ /* rendered separately below */ }
?>
<div class="mb-4"><label class="mb-1 block text-[10px] font-bold">To Branch *</label><div class="relative sel" id="branchSel"><button type="button" class="fi flex items-center justify-between text-left" onclick="toggle('branchMenu')"><span id="branchLabel">Select Destination Branch</span><i data-lucide="chevron-down" class="h-4 w-4"></i></button><div id="branchMenu" class="absolute z-[150] mt-1 hidden w-full rounded-xl border bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"><div class="p-2"><input id="branchSearch" class="fi !h-9" placeholder="Search branch ID or name..."></div><div class="max-h-48 overflow-y-auto p-1"><?php foreach($branches as $b):?><button type="button" class="bopt w-full rounded-lg px-3 py-2 text-left text-[10px] hover:bg-slate-50 dark:hover:bg-slate-800" data-search="<?=trE($b['id'].' '.$b['branch_name'])?>" onclick='pickBranch(<?=$b['id']?>,<?=json_encode('#'.$b['id'].' · '.$b['branch_name'],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>#<?=$b['id']?> · <?=trE($b['branch_name'])?></button><?php endforeach;?></div></div></div></div>
<div class="mb-4"><label class="mb-1 block text-[10px] font-bold">Product *</label><div class="relative sel" id="productSel"><button type="button" class="fi flex items-center justify-between text-left" onclick="toggle('productMenu')"><span id="productLabel">Select Product</span><i data-lucide="chevron-down" class="h-4 w-4"></i></button><div id="productMenu" class="absolute z-[150] mt-1 hidden w-full rounded-xl border bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"><div class="p-2"><input id="productSearch" class="fi !h-9" placeholder="Search ID, product, code, barcode..."></div><div class="max-h-52 overflow-y-auto p-1"><?php foreach($products as $p):?><button type="button" class="popt w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" data-search="<?=trE($p['id'].' '.$p['product_name'].' '.($p['product_code']??'').' '.($p['barcode']??''))?>" onclick='pickProduct(<?=$p['id']?>,<?=json_encode('#'.$p['id'].' · '.$p['product_name'],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><span class="block text-[10px] font-bold"><?=trE($p['product_name'])?></span><span class="text-[8px] text-slate-400">#<?=$p['id']?> · Available <?=number_format((float)$p['quantity'],3)?> <?=trE($p['base_unit_short_name'])?></span></button><?php endforeach;?></div></div></div></div>
<div class="mb-4"><label class="mb-1 block text-[10px] font-bold">Unit *</label><div class="relative sel" id="unitSel"><button type="button" class="fi flex items-center justify-between text-left" onclick="toggle('unitMenu')"><span id="unitLabel">Select Product First</span><i data-lucide="chevron-down" class="h-4 w-4"></i></button><div id="unitMenu" class="absolute z-[150] mt-1 hidden w-full rounded-xl border bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"><div class="p-2"><input id="unitSearch" class="fi !h-9" placeholder="Search unit ID or name..."></div><div id="unitOptions" class="max-h-52 overflow-y-auto p-1"><div class="p-3 text-center text-[9px] text-slate-400">Select a product first.</div></div></div></div></div>
<div id="info" class="mb-4 hidden rounded-xl bg-blue-50 p-3 text-[10px] font-semibold text-blue-700"><p id="available"></p><p id="conversion" class="mt-1"></p></div>
<div class="mb-4"><label class="mb-1 block text-[10px] font-bold">Quantity *</label><input id="qty" name="quantity" type="number" min=".001" step=".001" class="fi" required></div>
<div class="rounded-xl border bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800"><p class="text-[8px] font-bold uppercase text-slate-400">Automatic Conversion</p><p id="calc" class="mt-1 text-[11px] font-black">Select Product + Unit and enter Quantity.</p></div>
<div class="mt-4"><label class="mb-1 block text-[10px] font-bold">Note</label><textarea name="note" rows="3" class="w-full rounded-xl border bg-slate-50 p-3 text-[11px] dark:border-slate-700 dark:bg-slate-800"></textarea></div>
<div class="mt-5 flex justify-end gap-2 border-t pt-4 dark:border-slate-800"><button type="button" onclick="closeModal()" class="h-10 rounded-xl border px-4 text-[10px] font-bold">Cancel</button><button class="h-10 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white">Complete Transfer</button></div></form></div></div><?php endif;?>

<script>
const units=<?=json_encode($unitsMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,stocks=<?=json_encode($stockMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
let pid='',unit=null;
function openModal(){modal.classList.remove('hidden');modal.classList.add('flex')}
function closeModal(){modal.classList.add('hidden');modal.classList.remove('flex')}
function toggle(id){['branchMenu','productMenu','unitMenu'].forEach(x=>{if(x!==id)document.getElementById(x)?.classList.add('hidden')});document.getElementById(id)?.classList.toggle('hidden')}
function pickBranch(id,l){branchId.value=id;branchLabel.textContent=l;branchMenu.classList.add('hidden')}
function pickProduct(id,l){pid=String(id);productId.value=id;productLabel.textContent=l;productMenu.classList.add('hidden');unit=null;unitId.value='';unitLabel.textContent='Select Unit';buildUnits(id);showInfo();calcQty()}
function buildUnits(id){unitOptions.innerHTML='';const a=units[id]||[];a.forEach(u=>{const b=document.createElement('button');b.type='button';b.className='uopt w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800';b.dataset.search=(u.id+' '+u.unit_name+' '+u.short_name+' '+u.conversion_qty).toLowerCase();b.innerHTML='<span class="block text-[10px] font-bold">'+esc(u.unit_name)+'</span><span class="text-[8px] text-slate-400">#'+u.id+' · Conversion '+fmt(u.conversion_qty)+(u.is_base_unit==1?' · BASE':'')+'</span>';b.onclick=()=>pickUnit(u);unitOptions.appendChild(b)});if(a.length===1)pickUnit(a[0])}
function pickUnit(u){unit=u;unitId.value=u.id;unitLabel.textContent=u.unit_name+' ('+u.short_name+')';unitMenu.classList.add('hidden');showInfo();calcQty()}
function showInfo(){const s=stocks[pid];if(!s){info.classList.add('hidden');return}info.classList.remove('hidden');available.textContent='Available Stock: '+fmt(s.quantity)+' '+s.base_unit_name;conversion.textContent=unit?'1 '+unit.unit_name+' = '+fmt(unit.conversion_qty)+' '+s.base_unit_name:'Select Unit to see conversion.'}
function calcQty(){const q=Number(qty.value||0),s=stocks[pid];calc.textContent=(!unit||!s||q<=0)?'Select Product + Unit and enter Quantity.':fmt(q)+' '+unit.unit_name+' × '+fmt(unit.conversion_qty)+' = '+fmt(q*unit.conversion_qty)+' '+s.base_unit_name+' will be transferred'}
function fail(m){err.textContent=m;err.classList.remove('hidden')}
function validateForm(){err.classList.add('hidden');if(!branchId.value){fail('Please select destination branch.');return false}if(!productId.value){fail('Please select product.');return false}if(!unitId.value){fail('Please select unit.');return false}const q=Number(qty.value||0),s=stocks[pid],need=q*Number(unit?.conversion_qty||0);if(q<=0){fail('Quantity must be greater than zero.');return false}if(s&&need>Number(s.quantity)){fail('Insufficient stock. Available '+fmt(s.quantity)+' '+s.base_unit_name+', required '+fmt(need)+'.');return false}return true}
function fmt(v){return Number(v||0).toFixed(3).replace(/\.?0+$/,'')}function esc(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
qty?.addEventListener('input',calcQty);
[['branchSearch','.bopt'],['productSearch','.popt'],['unitSearch','.uopt']].forEach(([i,c])=>document.getElementById(i)?.addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll(c).forEach(x=>x.style.display=(x.dataset.search||'').toLowerCase().includes(q)?'':'none')}));
search?.addEventListener('input',function(){const q=this.value.toLowerCase();let n=0;document.querySelectorAll('.row').forEach(x=>{const ok=(x.dataset.search||'').toLowerCase().includes(q);x.style.display=ok?'':'none';if(ok)n++});empty.classList.toggle('hidden',n!==0)});
document.addEventListener('click',e=>{if(!e.target.closest('#branchSel'))branchMenu?.classList.add('hidden');if(!e.target.closest('#productSel'))productMenu?.classList.add('hidden');if(!e.target.closest('#unitSel'))unitMenu?.classList.add('hidden')});
document.addEventListener('DOMContentLoaded',()=>lucide?.createIcons());
</script></body></html>