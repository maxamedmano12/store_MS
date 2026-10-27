<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('sales_returns.view');

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??0);
$userId=(int)($_SESSION['user_id']??0);
$businessName=(string)($_SESSION['business_name']??'Business');
$canAdd=hasPermission('sales_returns.add');
$canUpdate=hasPermission('sales_returns.update');

if($businessId<=0||$branchId<=0||$userId<=0){http_response_code(403);exit('Business/branch/user not available.');}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function flashSR($m,$t='success'){$_SESSION['sr_flash']=$m;$_SESSION['sr_flash_type']=$t;}
if(empty($_SESSION['sr_csrf']))$_SESSION['sr_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['sr_csrf'];

function returnNo(PDO $conn,int $businessId):string{
    $prefix='SRT-'.date('Ymd').'-';
    $q=$conn->prepare("SELECT return_no FROM sales_returns WHERE business_id=? AND return_no LIKE ? ORDER BY id DESC LIMIT 1");
    $q->execute([$businessId,$prefix.'%']);$last=$q->fetchColumn();
    $n=$last?(int)substr($last,-4)+1:1;
    return $prefix.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}

if($_SERVER['REQUEST_METHOD']==='POST' && in_array(($_POST['action']??''),['add','update'],true)){
    $postAction=(string)$_POST['action'];
    if($postAction==='add'&&!$canAdd){http_response_code(403);exit('Access denied.');}
    if($postAction==='update'&&!$canUpdate){http_response_code(403);exit('Access denied.');}
    if(!hash_equals($_SESSION['sr_csrf'],(string)($_POST['csrf_token']??''))){flashSR('Invalid security token.','error');header('Location: sales-returns.php');exit;}
    try{
        $returnIdToUpdate=(int)($_POST['return_id']??0);
        $saleId=(int)($_POST['sale_id']??0);
        $returnDate=(string)($_POST['return_date']??date('Y-m-d\TH:i'));
        $refund=max(0,(float)($_POST['refund_amount']??0));
        $reason=trim((string)($_POST['reason']??''));
        $note=trim((string)($_POST['note']??''));
        $items=$_POST['items']??[];
        if($saleId<=0)throw new RuntimeException('Select a sale.');
        if(!$items)throw new RuntimeException('Select at least one item to return.');

        $conn->beginTransaction();
        $oldReturn=null;
        if($postAction==='update'){
            $q=$conn->prepare("SELECT * FROM sales_returns WHERE id=? AND business_id=? AND branch_id=? AND status='Completed' FOR UPDATE");
            $q->execute([$returnIdToUpdate,$businessId,$branchId]);$oldReturn=$q->fetch(PDO::FETCH_ASSOC);
            if(!$oldReturn)throw new RuntimeException('Sales return not found.');
            if((int)$oldReturn['sale_id']!==$saleId)throw new RuntimeException('Original Sale cannot be changed while updating.');
            $q=$conn->prepare("SELECT * FROM sales_return_items WHERE business_id=? AND sales_return_id=? FOR UPDATE");
            $q->execute([$businessId,$returnIdToUpdate]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $oi){
                $sq=$conn->prepare("SELECT quantity FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? FOR UPDATE");
                $sq->execute([$businessId,$branchId,$oi['product_id']]);$stock=(float)$sq->fetchColumn();
                $after=$stock-(float)$oi['base_qty'];
                if($after < -0.0005)throw new RuntimeException('Cannot update: returned stock has already been used.');
                $conn->prepare("UPDATE product_stock SET quantity=? WHERE business_id=? AND branch_id=? AND product_id=?")->execute([max(0,$after),$businessId,$branchId,$oi['product_id']]);
            }
            $q=$conn->prepare("SELECT bm.batch_id,bm.qty_in,pb.quantity,pb.expiry_date FROM batch_movements bm JOIN product_batches pb ON pb.id=bm.batch_id WHERE bm.business_id=? AND bm.branch_id=? AND bm.movement_type='SALE_RETURN' AND bm.reference_type='SALE_RETURN' AND bm.reference_id=? FOR UPDATE");
            $q->execute([$businessId,$branchId,$returnIdToUpdate]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $bo){
                $after=(float)$bo['quantity']-(float)$bo['qty_in'];
                if($after < -0.0005)throw new RuntimeException('Cannot update: returned batch stock has already been used.');
                $after=max(0,$after);$st=$after<=0.0005?'Depleted':(($bo['expiry_date']&&$bo['expiry_date']<date('Y-m-d'))?'Expired':'Active');
                $conn->prepare("UPDATE product_batches SET quantity=?,status=? WHERE id=?")->execute([$after,$st,$bo['batch_id']]);
            }
            $conn->prepare("DELETE FROM batch_movements WHERE business_id=? AND branch_id=? AND movement_type='SALE_RETURN' AND reference_type='SALE_RETURN' AND reference_id=?")->execute([$businessId,$branchId,$returnIdToUpdate]);
            $conn->prepare("DELETE FROM stock_movements WHERE business_id=? AND branch_id=? AND movement_type='SALE_RETURN' AND reference_type='SALE_RETURN' AND reference_id=?")->execute([$businessId,$branchId,$returnIdToUpdate]);
            $conn->prepare("DELETE FROM sales_return_items WHERE business_id=? AND sales_return_id=?")->execute([$businessId,$returnIdToUpdate]);
        }
        $q=$conn->prepare("SELECT * FROM sales WHERE id=? AND business_id=? AND branch_id=? AND status='Completed' FOR UPDATE");
        $q->execute([$saleId,$businessId,$branchId]);$sale=$q->fetch(PDO::FETCH_ASSOC);
        if(!$sale)throw new RuntimeException('Sale not found.');

        $valid=[];$total=0;
        foreach($items as $saleItemId=>$it){
            $qty=(float)($it['qty']??0);
            if($qty<=0)continue;
            $q=$conn->prepare("SELECT si.*,p.product_name,p.track_expiry,u.unit_name,u.short_name
              FROM sale_items si JOIN products p ON p.id=si.product_id
              JOIN product_units pu ON pu.id=si.product_unit_id
              JOIN units u ON u.id=pu.unit_id
              WHERE si.id=? AND si.sale_id=? AND si.business_id=? FOR UPDATE");
            $q->execute([(int)$saleItemId,$saleId,$businessId]);$x=$q->fetch(PDO::FETCH_ASSOC);
            if(!$x)throw new RuntimeException('Invalid sale item.');

            $q=$conn->prepare("SELECT COALESCE(SUM(transaction_qty),0) FROM sales_return_items sri
              JOIN sales_returns sr ON sr.id=sri.sales_return_id
              WHERE sri.business_id=? AND sri.sale_item_id=? AND sr.status='Completed'");
            $q->execute([$businessId,(int)$saleItemId]);$returned=(float)$q->fetchColumn();
            $remaining=(float)$x['transaction_qty']-$returned;
            if($qty>$remaining+0.0005)throw new RuntimeException($x['product_name'].' return quantity exceeds remaining quantity.');

            $baseQty=$qty*(float)$x['conversion_qty'];
            $amount=$qty*(float)$x['unit_price'];
            $cost=$baseQty*(float)$x['unit_cost'];
            $total+=$amount;
            $valid[]=[$x,$qty,$baseQty,$amount,$cost];
        }
        if(!$valid)throw new RuntimeException('Enter a return quantity.');
        if($refund>$total+0.005)throw new RuntimeException('Refund Amount cannot exceed Return Total.');
        $adjust=max(0,$total-$refund);
        if($postAction==='update'){
            $no=(string)$oldReturn['return_no'];$returnId=$returnIdToUpdate;
            $q=$conn->prepare("UPDATE sales_returns SET return_date=?,total_amount=?,refund_amount=?,balance_adjustment=?,reason=?,note=? WHERE id=? AND business_id=? AND branch_id=?");
            $q->execute([date('Y-m-d H:i:s',strtotime($returnDate)),$total,$refund,$adjust,$reason?:null,$note?:null,$returnId,$businessId,$branchId]);
        }else{
            $no=returnNo($conn,$businessId);
            $q=$conn->prepare("INSERT INTO sales_returns(business_id,branch_id,sale_id,customer_id,return_no,return_date,total_amount,refund_amount,balance_adjustment,status,reason,note,created_by) VALUES(?,?,?,?,?,?,?,?,?,'Completed',?,?,?)");
            $q->execute([$businessId,$branchId,$saleId,$sale['customer_id'],$no,date('Y-m-d H:i:s',strtotime($returnDate)),$total,$refund,$adjust,$reason?:null,$note?:null,$userId]);
            $returnId=(int)$conn->lastInsertId();
        }

        foreach($valid as [$x,$qty,$baseQty,$amount,$cost]){
            $q=$conn->prepare("INSERT INTO sales_return_items(business_id,sales_return_id,sale_item_id,product_id,product_unit_id,transaction_qty,conversion_qty,base_qty,unit_price,base_unit_price,unit_cost,total_cost,total_amount)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $q->execute([$businessId,$returnId,$x['id'],$x['product_id'],$x['product_unit_id'],$qty,$x['conversion_qty'],$baseQty,$x['unit_price'],$x['base_unit_price'],$x['unit_cost'],$cost,$amount]);

            $q=$conn->prepare("SELECT quantity FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? FOR UPDATE");
            $q->execute([$businessId,$branchId,$x['product_id']]);$old=(float)$q->fetchColumn();
            $new=$old+$baseQty;
            $conn->prepare("UPDATE product_stock SET quantity=? WHERE business_id=? AND branch_id=? AND product_id=?")->execute([$new,$businessId,$branchId,$x['product_id']]);

            $q=$conn->prepare("INSERT INTO stock_movements(business_id,branch_id,product_id,product_unit_id,movement_type,reference_type,reference_id,transaction_qty,conversion_qty,base_qty,qty_in,qty_out,unit_cost,total_cost,balance_after,note,created_by)
              VALUES(?,?,?,?, 'SALE_RETURN','SALE_RETURN',?,?,?,?,?,0,?,?,?,?,?)");
            $q->execute([$businessId,$branchId,$x['product_id'],$x['product_unit_id'],$returnId,$qty,$x['conversion_qty'],$baseQty,$baseQty,$x['unit_cost'],$cost,$new,'Sales return '.$no,$userId]);

            if((int)$x['track_expiry']===1){
                /* Restore to batches consumed by the original sale, in original allocation order. */
                $need=$baseQty;
                $q=$conn->prepare("SELECT bm.batch_id,SUM(bm.qty_out) sold_qty
                  FROM batch_movements bm
                  WHERE bm.business_id=? AND bm.branch_id=? AND bm.product_id=? AND bm.movement_type='SALE'
                    AND bm.reference_type='SALE' AND bm.reference_id=?
                  GROUP BY bm.batch_id ORDER BY MIN(bm.id)");
                $q->execute([$businessId,$branchId,$x['product_id'],$saleId]);
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $b){
                    if($need<=0.0005)break;
                    /* subtract quantities already returned to this batch for this sale */
                    $aq=$conn->prepare("SELECT COALESCE(SUM(qty_in),0) FROM batch_movements WHERE business_id=? AND branch_id=? AND batch_id=? AND movement_type='SALE_RETURN' AND note LIKE ?");
                    $aq->execute([$businessId,$branchId,$b['batch_id'],'%Sale #'.$saleId.'%']);
                    $already=(float)$aq->fetchColumn();
                    $available=max(0,(float)$b['sold_qty']-$already);
                    $put=min($need,$available);if($put<=0)continue;
                    $bq=$conn->prepare("SELECT quantity,expiry_date FROM product_batches WHERE id=? AND business_id=? AND branch_id=? FOR UPDATE");
                    $bq->execute([$b['batch_id'],$businessId,$branchId]);$batch=$bq->fetch(PDO::FETCH_ASSOC);
                    if(!$batch)throw new RuntimeException('Original batch is missing.');
                    $after=(float)$batch['quantity']+$put;
                    $status=($batch['expiry_date']&&$batch['expiry_date']<date('Y-m-d'))?'Expired':'Active';
                    $conn->prepare("UPDATE product_batches SET quantity=?,status=? WHERE id=?")->execute([$after,$status,$b['batch_id']]);
                    $bm=$conn->prepare("INSERT INTO batch_movements(business_id,branch_id,batch_id,product_id,movement_type,reference_type,reference_id,qty_in,qty_out,balance_after,note,created_by)
                      VALUES(?,?,?,?, 'SALE_RETURN','SALE_RETURN',?,?,0,?,?,?)");
                    $bm->execute([$businessId,$branchId,$b['batch_id'],$x['product_id'],$returnId,$put,$after,'Sale #'.$saleId.' return '.$no,$userId]);
                    $need-=$put;
                }
                if($need>0.0005)throw new RuntimeException($x['product_name'].' original batch allocation is insufficient for this return.');
            }
        }
        $conn->commit();
        try{if(function_exists('logActivity'))logActivity($postAction==='update'?'UPDATE':'CREATE','Sales Returns',$returnId,($postAction==='update'?'Updated ':'Created ').$no);}catch(Throwable $ignore){}
        flashSR('Sales Return '.$no.($postAction==='update'?' updated':' completed').' successfully.');
    }catch(Throwable $ex){
        if($conn->inTransaction())$conn->rollBack();
        flashSR($ex->getMessage(),'error');
    }
    header('Location: sales-returns.php');exit;
}

/* Sales and returnable items */
$q=$conn->prepare("SELECT s.id,s.sale_no,s.sale_date,s.customer_id,c.customer_name,s.total_amount,
 COALESCE((SELECT SUM(sr.total_amount) FROM sales_returns sr
           WHERE sr.business_id=s.business_id AND sr.branch_id=s.branch_id
             AND sr.sale_id=s.id AND sr.status='Completed'),0) returned_amount
 FROM sales s LEFT JOIN customers c ON c.id=s.customer_id
 WHERE s.business_id=? AND s.branch_id=? AND s.status='Completed'
 ORDER BY s.id DESC LIMIT 500");
$q->execute([$businessId,$branchId]);$sales=$q->fetchAll(PDO::FETCH_ASSOC);

$q=$conn->prepare("SELECT si.id,si.sale_id,si.product_id,si.product_unit_id,si.transaction_qty,si.conversion_qty,si.unit_price,
 p.product_name,p.product_code,u.unit_name,u.short_name,
 COALESCE((SELECT SUM(sri.transaction_qty) FROM sales_return_items sri JOIN sales_returns sr ON sr.id=sri.sales_return_id
 WHERE sri.business_id=si.business_id AND sri.sale_item_id=si.id AND sr.status='Completed'),0) returned_qty
 FROM sale_items si JOIN products p ON p.id=si.product_id
 JOIN product_units pu ON pu.id=si.product_unit_id JOIN units u ON u.id=pu.unit_id
 WHERE si.business_id=? ORDER BY si.id");
$q->execute([$businessId]);$allItems=$q->fetchAll(PDO::FETCH_ASSOC);
$itemsBySale=[];foreach($allItems as $x){$x['remaining_qty']=(float)$x['transaction_qty']-(float)$x['returned_qty'];if($x['remaining_qty']>0.0005)$itemsBySale[(int)$x['sale_id']][]=$x;}

$q=$conn->prepare("SELECT sr.*,s.sale_no,s.total_amount original_sale_total,s.balance_due,c.customer_name,u.full_name created_by_name,
 COALESCE((SELECT SUM(sr2.total_amount) FROM sales_returns sr2
           WHERE sr2.business_id=sr.business_id AND sr2.branch_id=sr.branch_id
             AND sr2.sale_id=sr.sale_id AND sr2.status='Completed'),0) total_returned_for_sale
 FROM sales_returns sr JOIN sales s ON s.id=sr.sale_id
 LEFT JOIN customers c ON c.id=sr.customer_id LEFT JOIN users u ON u.id=sr.created_by
 WHERE sr.business_id=? AND sr.branch_id=? ORDER BY sr.id DESC LIMIT 300");
$q->execute([$businessId,$branchId]);$returns=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$conn->prepare("SELECT sri.sales_return_id,sri.sale_item_id,sri.transaction_qty FROM sales_return_items sri JOIN sales_returns sr ON sr.id=sri.sales_return_id WHERE sri.business_id=? AND sr.branch_id=? AND sr.status='Completed'");
$q->execute([$businessId,$branchId]);$returnEditItems=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $ri){$returnEditItems[(int)$ri['sales_return_id']][(int)$ri['sale_item_id']]=(float)$ri['transaction_qty'];}

/* Print report: show ALL items from the original sale, including items never returned. */
$q=$conn->prepare("SELECT
 sr.id sales_return_id,
 si.id sale_item_id,
 si.transaction_qty sold_qty,
 si.unit_price,
 p.product_name,
 u.unit_name,u.short_name,
 COALESCE(cur.transaction_qty,0) return_qty,
 COALESCE(cur.total_amount,0) total_amount,
 COALESCE((SELECT SUM(sri2.transaction_qty)
   FROM sales_return_items sri2
   JOIN sales_returns sr2 ON sr2.id=sri2.sales_return_id
   WHERE sri2.sale_item_id=si.id
     AND sr2.business_id=sr.business_id
     AND sr2.branch_id=sr.branch_id
     AND sr2.status='Completed'),0) cumulative_returned
 FROM sales_returns sr
 JOIN sale_items si ON si.sale_id=sr.sale_id AND si.business_id=sr.business_id
 JOIN products p ON p.id=si.product_id
 LEFT JOIN product_units pu ON pu.id=si.product_unit_id
 LEFT JOIN units u ON u.id=pu.unit_id
 LEFT JOIN sales_return_items cur
   ON cur.sales_return_id=sr.id AND cur.sale_item_id=si.id AND cur.business_id=sr.business_id
 WHERE sr.business_id=? AND sr.branch_id=? AND sr.status='Completed'
 ORDER BY sr.id,si.id");
$q->execute([$businessId,$branchId]);$printReturnItems=[];
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $pi){$printReturnItems[(int)$pi['sales_return_id']][]=$pi;}

$totalReturns=count($returns);$returnAmount=0;$todayReturnAmount=0.0;$monthReturnAmount=0.0;
foreach($returns as $r){if($r['status']==='Completed')$returnAmount+=(float)$r['total_amount'];}
$q=$conn->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_returns WHERE business_id=? AND branch_id=? AND status='Completed' AND DATE(return_date)=CURDATE()");$q->execute([$businessId,$branchId]);$todayReturnAmount=(float)$q->fetchColumn();
$q=$conn->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_returns WHERE business_id=? AND branch_id=? AND status='Completed' AND YEAR(return_date)=YEAR(CURDATE()) AND MONTH(return_date)=MONTH(CURDATE())");$q->execute([$businessId,$branchId]);$monthReturnAmount=(float)$q->fetchColumn();
$flash=$_SESSION['sr_flash']??null;$flashType=$_SESSION['sr_flash_type']??'success';unset($_SESSION['sr_flash'],$_SESSION['sr_flash_type']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sales Returns - <?=e($businessName)?></title><script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script>
<style>
.field{height:40px;width:100%;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:0 12px;font-size:10px;outline:none;transition:.15s}.field:focus{border-color:#3b82f6;background:#fff;box-shadow:0 0 0 3px #dbeafe}
.drop{position:absolute;z-index:70;top:44px;left:0;right:0;max-height:240px;overflow:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff;box-shadow:0 18px 40px #0f172a22}.opt{padding:10px 12px;font-size:10px;cursor:pointer;border-bottom:1px solid #f1f5f9}.opt:hover{background:#eff6ff;color:#1d4ed8}
</style></head><body class="min-h-screen bg-slate-50 text-slate-700">
<?php require_once __DIR__.'/includes/sidebar.php';?><div class="min-h-screen lg:pl-64"><?php require_once __DIR__.'/includes/header.php';?>
<main class="p-4 sm:p-5 lg:p-6">
<div class="mb-5 flex items-center justify-between"><div><h1 class="text-lg font-extrabold text-slate-900">Sales Returns</h1><p class="mt-1 text-[10px] text-slate-400">Return customer items from the original completed sale.</p></div>
<?php if($canAdd):?><button onclick="openReturn()" class="h-9 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white">+ Add Sales Return</button><?php endif;?></div>
<?php if($flash):?><div class="mb-5 rounded-xl border px-4 py-3 text-[10px] font-bold <?=$flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><?=e($flash)?></div><?php endif;?>
<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
<?php foreach([['Total Returns',$totalReturns],['Return Amount','$'.number_format($returnAmount,2)],["Today's Returns",'$'.number_format($todayReturnAmount,2)],['Monthly Returns','$'.number_format($monthReturnAmount,2)]] as $x):?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-[9px] font-bold uppercase text-slate-400"><?=e($x[0])?></p><p class="mt-2 text-xl font-extrabold text-blue-600"><?=e($x[1])?></p></div><?php endforeach;?></div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
<div class="flex items-center justify-between border-b p-4"><h2 class="text-[12px] font-extrabold">Sales Return List</h2><div class="relative w-72"><i data-lucide="search" class="absolute left-3 top-2.5 h-4 w-4 text-slate-400"></i><input id="search" class="field pl-9" placeholder="Search automatically..."></div></div>
<div class="overflow-x-auto"><table class="w-full text-left text-[10px]"><thead class="bg-slate-50 text-[9px] uppercase text-slate-400"><tr><th class="px-5 py-3">Return No</th><th class="px-5 py-3">Sale No</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Date</th><th class="px-5 py-3">Original Total</th><th class="px-5 py-3">Returned</th><th class="px-5 py-3">New Total</th><th class="px-5 py-3">Refund</th><th class="px-5 py-3">Balance Adj.</th><th class="px-5 py-3">By User</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody>
<?php foreach($returns as $r):$st=strtolower(implode(' ',[$r['return_no'],$r['sale_no'],$r['customer_name'],$r['return_date'],$r['created_by_name']]));?>
<tr class="return-row border-t" data-search="<?=e($st)?>"><td class="px-5 py-3.5 font-bold text-blue-600"><?=e($r['return_no'])?></td><td class="px-5 py-3.5"><?=e($r['sale_no'])?></td><td class="px-5 py-3.5"><?=e($r['customer_name']?:'Walk-in Customer')?></td><td class="px-5 py-3.5"><?=e(date('d M Y H:i',strtotime($r['return_date'])))?></td><td class="px-5 py-3.5">$<?=number_format($r['original_sale_total'],2)?></td><td class="px-5 py-3.5 font-bold text-rose-600">$<?=number_format($r['total_returned_for_sale'],2)?></td><td class="px-5 py-3.5 font-extrabold text-blue-600">$<?=number_format(max(0,(float)$r['original_sale_total']-(float)$r['total_returned_for_sale']),2)?></td><td class="px-5 py-3.5 text-emerald-600">$<?=number_format($r['refund_amount'],2)?></td><td class="px-5 py-3.5">$<?=number_format($r['balance_adjustment'],2)?></td><td class="px-5 py-3.5"><?=e($r['created_by_name']?:'—')?></td><td class="px-5 py-3.5 text-right"><div class="inline-flex gap-1"><?php if($canUpdate):?><button type="button" onclick='openEditReturn(<?=json_encode($r,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100" title="Update"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button><?php endif;?><button type="button" onclick='printReturn(<?=json_encode($r,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200" title="Print"><i data-lucide="printer" class="h-3.5 w-3.5"></i></button></div></td></tr>
<?php endforeach;?><tr id="none" class="hidden"><td colspan="11" class="p-10 text-center text-slate-400">No matching sales returns.</td></tr></tbody></table></div></div>
</main></div>

<div id="modal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm"><div class="max-h-[96vh] w-full max-w-5xl overflow-y-auto rounded-3xl bg-slate-50 shadow-2xl">
<form method="post" id="returnForm"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="return_id" id="returnId"><input type="hidden" name="sale_id" id="saleId">
<div class="sticky top-0 z-30 flex items-center justify-between border-b bg-white/95 px-5 py-4"><div class="flex items-center gap-3"><div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-600 text-white"><i data-lucide="rotate-ccw" class="h-5 w-5"></i></div><div><h3 class="text-sm font-extrabold text-slate-900" id="returnModalTitle">New Sales Return</h3><p class="text-[9px] text-slate-400">Choose the original sale and returnable items.</p></div></div><button type="button" onclick="closeReturn()" class="h-9 w-9 rounded-xl bg-slate-100">×</button></div>
<div class="space-y-4 p-5">
<div class="grid gap-3 rounded-2xl border bg-white p-4 md:grid-cols-4">
<label><span class="mb-1.5 block text-[9px] font-extrabold uppercase text-slate-400">Sale Date Filter</span><input id="saleDateFilter" type="date" class="field"><span class="mt-1 block text-[8px] text-slate-400">Use this for Walk-in sales with no customer name.</span></label>
<label><span class="mb-1.5 block text-[9px] font-extrabold uppercase text-slate-400">Customer Filter (Optional)</span><input id="customerFilter" class="field" placeholder="Name / ID..." autocomplete="off"><span class="mt-1 block text-[8px] text-slate-400">Leave empty to include Walk-in Customer.</span></label>
<div class="relative"><label class="mb-1.5 block text-[9px] font-extrabold uppercase text-slate-400">Original Sale</label><input id="saleSearch" class="field" placeholder="Search Sale No / Customer / ID" autocomplete="off"><div id="saleDrop" class="drop hidden"></div></div>
<label><span class="mb-1.5 block text-[9px] font-extrabold uppercase text-slate-400">Return Date</span><input name="return_date" type="datetime-local" value="<?=date('Y-m-d\TH:i')?>" class="field"></label>
</div>
<div class="overflow-hidden rounded-2xl border bg-white"><div class="border-b p-4"><h4 class="text-[12px] font-extrabold">Returnable Items</h4><p class="mt-1 text-[9px] text-slate-400">Remaining Qty is calculated automatically from previous returns.</p></div><div id="returnItems" class="p-4 text-[10px] text-slate-400">Select a sale first.</div></div>
<div class="grid gap-3 rounded-2xl border bg-white p-4 md:grid-cols-3">
<label><span class="mb-1 block text-[8px] font-bold uppercase text-slate-400">Refund Amount</span><input id="refund" name="refund_amount" type="number" min="0" step=".01" value="0" class="field"></label>
<label><span class="mb-1 block text-[8px] font-bold uppercase text-slate-400">Reason</span><input name="reason" class="field" placeholder="Return reason..."></label>
<label><span class="mb-1 block text-[8px] font-bold uppercase text-slate-400">Note</span><input name="note" class="field" placeholder="Optional note..."></label>
</div>
<div class="flex items-center justify-between rounded-2xl bg-slate-900 p-4 text-white"><div><p class="text-[9px] text-slate-400">Return Total</p><p class="text-xl font-extrabold">$<span id="returnTotal">0.00</span></p></div><div class="flex gap-2"><button type="button" onclick="closeReturn()" class="h-10 rounded-xl bg-white/10 px-4 text-[10px] font-bold">Cancel</button><button id="submitReturnBtn" type="submit" class="h-10 rounded-xl bg-blue-600 px-5 text-[10px] font-extrabold">Complete Return</button></div></div>
</div></form></div></div>
<script>
lucide.createIcons();
const sales=<?=json_encode($sales,JSON_UNESCAPED_UNICODE)?>,itemsBySale=<?=json_encode($itemsBySale,JSON_UNESCAPED_UNICODE)?>,returnEditItems=<?=json_encode($returnEditItems,JSON_UNESCAPED_UNICODE)?>,printReturnItems=<?=json_encode($printReturnItems,JSON_UNESCAPED_UNICODE)?>;
function openReturn(){modal.classList.remove('hidden');modal.classList.add('flex');returnForm.reset();formAction.value='add';returnId.value='';returnModalTitle.textContent='New Sales Return';submitReturnBtn.textContent='Complete Return';saleId.value='';saleSearch.value='';saleSearch.disabled=false;saleDateFilter.disabled=false;customerFilter.disabled=false;saleDateFilter.value='';customerFilter.value='';returnItems.innerHTML='Use Sale Date / Customer filters, then select the original sale.';returnTotal.textContent='0.00'}
function closeReturn(){modal.classList.add('hidden');modal.classList.remove('flex')}
function renderSales(){
 const q=saleSearch.value.toLowerCase().trim(), date=saleDateFilter.value, customer=customerFilter.value.toLowerCase().trim();
 saleDrop.innerHTML='';
 const found=sales.filter(s=>{
   const hay=`#${s.id} ${s.sale_no} ${s.customer_name||'Walk-in Customer'}`.toLowerCase();
   const saleDay=(s.sale_date||'').substring(0,10);
   const customerHay=`#${s.customer_id||''} ${s.customer_name||'Walk-in Customer'}`.toLowerCase();
   return (!date||saleDay===date)&&(!customer||customerHay.includes(customer))&&hay.includes(q);
 }).slice(0,100);
 found.forEach(s=>{
   const d=document.createElement('div');d.className='opt';
   const returned=Number(s.returned_amount||0), net=Math.max(0,Number(s.total_amount)-returned);
   d.innerHTML=`<b>#${s.id} - ${esc(s.sale_no)}</b> · ${esc(s.customer_name||'Walk-in Customer')} · ${esc((s.sale_date||'').substring(0,10))}<br><span class="text-[8px] text-slate-400">Original $${Number(s.total_amount).toFixed(2)} · Returned $${returned.toFixed(2)} · New Total $${net.toFixed(2)}</span>`;
   d.onclick=()=>{saleId.value=s.id;saleSearch.value=`#${s.id} - ${s.sale_no} - ${s.customer_name||'Walk-in Customer'}`;saleDrop.classList.add('hidden');showItems(s.id)};
   saleDrop.appendChild(d)
 });
 if(!found.length){const d=document.createElement('div');d.className='opt text-slate-400';d.textContent='No sales match these filters.';saleDrop.appendChild(d)}
 saleDrop.classList.remove('hidden')
}
saleSearch.addEventListener('focus',renderSales);saleSearch.addEventListener('input',renderSales);
saleDateFilter.addEventListener('change',()=>{saleId.value='';saleSearch.value='';returnItems.innerHTML='Select a sale from the filtered results.';renderSales()});
customerFilter.addEventListener('input',()=>{saleId.value='';saleSearch.value='';returnItems.innerHTML='Select a sale from the filtered results.';renderSales()});
function showItems(id,prefill={}){const arr=(itemsBySale[id]||[]).map(x=>({...x}));Object.entries(prefill).forEach(([iid,q])=>{let x=arr.find(a=>Number(a.id)===Number(iid));if(x){x.remaining_qty=Number(x.remaining_qty)+Number(q);x.returned_qty=Math.max(0,Number(x.returned_qty)-Number(q))}});if(!arr.length){returnItems.innerHTML='<div class="p-5 text-center text-amber-600">This sale has no remaining returnable items.</div>';return}returnItems.innerHTML=`<div class="space-y-2">${arr.map(x=>`<div class="grid items-center gap-2 rounded-xl border border-slate-200 p-3 md:grid-cols-[2fr_1fr_1fr_1fr]"><div><p class="font-extrabold text-slate-800">${esc(x.product_name)}</p><p class="text-[8px] text-slate-400">${esc(x.unit_name)} (${esc(x.short_name||'')}) · Sold ${Number(x.transaction_qty).toFixed(3)} · Returned ${Number(x.returned_qty).toFixed(3)}</p></div><div><span class="text-[8px] uppercase text-slate-400">Remaining</span><p class="font-bold text-blue-600">${Number(x.remaining_qty).toFixed(3)}</p></div><div><span class="text-[8px] uppercase text-slate-400">Price</span><p class="font-bold">$${Number(x.unit_price).toFixed(2)}</p></div><input name="items[${x.id}][qty]" class="field rq" data-price="${x.unit_price}" type="number" min="0" max="${x.remaining_qty}" step=".001" value="${Number(prefill[x.id]||0)}" placeholder="Return Qty"></div>`).join('')}</div>`;document.querySelectorAll('.rq').forEach(x=>x.addEventListener('input',calc));calc()}
function openEditReturn(r){
 modal.classList.remove('hidden');modal.classList.add('flex');returnForm.reset();formAction.value='update';returnId.value=r.id;
 returnModalTitle.textContent='Update Sales Return - '+r.return_no;submitReturnBtn.textContent='Save Changes';saleId.value=r.sale_id;
 const sale=sales.find(x=>Number(x.id)===Number(r.sale_id));saleSearch.value=sale?`#${sale.id} - ${sale.sale_no} - ${sale.customer_name||'Walk-in Customer'}`:r.sale_no;
 saleSearch.disabled=true;saleDateFilter.disabled=true;customerFilter.disabled=true;customerFilter.value=r.customer_name||'';
 refund.value=Number(r.refund_amount||0).toFixed(2);returnForm.querySelector('[name="reason"]').value=r.reason||'';returnForm.querySelector('[name="note"]').value=r.note||'';
 const raw=String(r.return_date||'').replace(' ','T').substring(0,16);returnForm.querySelector('[name="return_date"]').value=raw;
 showItems(r.sale_id,returnEditItems[r.id]||{});calc();
}
function printReturn(r){
 const original=Number(r.original_sale_total||0), returned=Number(r.total_returned_for_sale||r.total_amount||0);
 const newTotal=Math.max(0,original-returned), due=Number(r.balance_due||0);
 const rows=(printReturnItems[r.id]||[]).map((x,i)=>{
   const sold=Number(x.sold_qty||0), thisReturned=Number(x.return_qty||0), cumulative=Number(x.cumulative_returned||0);
   const newQty=Math.max(0,sold-cumulative), price=Number(x.unit_price||0), total=Number(x.total_amount||thisReturned*price);
   const status=thisReturned>0?'<span class="status returned">RETURNED</span>':'<span class="status kept">KEPT</span>';
   return `<tr><td>${i+1}</td><td><b>${esc(x.product_name||'')}</b><br>${status}</td><td>${esc(x.short_name||x.unit_name||'')}</td><td class="num">${sold.toFixed(3)}</td><td class="num ret">${thisReturned.toFixed(3)}</td><td class="num"><b>${newQty.toFixed(3)}</b></td><td class="num">$${price.toFixed(2)}</td><td class="num"><b>$${total.toFixed(2)}</b></td></tr>`;
 }).join('');
 const w=window.open('','_blank','width=950,height=950');if(!w){alert('Please allow pop-ups to print.');return}
 w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(r.return_no)}</title><style>
 *{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#172033;margin:0;padding:32px;background:#fff}.top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #172033;padding-bottom:18px}.brand{font-size:23px;font-weight:800}.tag{font-size:11px;color:#64748b;margin-top:5px}.badge{display:inline-block;padding:6px 10px;border-radius:20px;background:#eef2ff;font-size:10px;font-weight:800}.info{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:18px 0}.card{border:1px solid #e2e8f0;border-radius:12px;padding:11px}.label{font-size:9px;text-transform:uppercase;color:#94a3b8;font-weight:700}.value{font-size:12px;font-weight:700;margin-top:4px}table{width:100%;border-collapse:collapse;margin-top:15px;font-size:10px}th{background:#f1f5f9;text-align:left;padding:9px 7px;border-bottom:2px solid #cbd5e1}td{padding:9px 7px;border-bottom:1px solid #e2e8f0}.num{text-align:right}.ret{color:#dc2626;font-weight:700}.status{display:inline-block;margin-top:4px;padding:2px 6px;border-radius:999px;font-size:7px;font-weight:800}.status.returned{background:#fee2e2;color:#b91c1c}.status.kept{background:#dcfce7;color:#15803d}.summary{width:360px;margin:20px 0 0 auto}.sumrow{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #e2e8f0;font-size:11px}.new{font-size:16px;font-weight:800;border-top:2px solid #172033;margin-top:5px}.owed{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px;margin-top:10px}.notes{margin-top:20px;border:1px solid #e2e8f0;border-radius:12px;padding:12px;font-size:10px}.footer{text-align:center;color:#94a3b8;font-size:9px;margin-top:28px}@media print{body{padding:10mm}.no-print{display:none}}
 </style></head><body>
 <div class="top"><div><div class="brand"><?=e($businessName)?></div><div class="tag">SALES RETURN RECEIPT</div></div><div style="text-align:right"><span class="badge">${esc(r.return_no)}</span><div class="tag">${esc(r.return_date)}</div></div></div>
 <div class="info"><div class="card"><div class="label">Original Sale</div><div class="value">${esc(r.sale_no)}</div></div><div class="card"><div class="label">Customer</div><div class="value">${esc(r.customer_name||'Walk-in Customer')}</div></div><div class="card"><div class="label">Processed By</div><div class="value">${esc(r.created_by_name||'—')}</div></div></div>
 <div style="margin-top:18px;font-size:12px;font-weight:800">ALL ORIGINAL SALE ITEMS</div><div class="tag">Includes returned and non-returned products.</div><table><thead><tr><th>#</th><th>Product</th><th>Unit</th><th style="text-align:right">Sold Qty</th><th style="text-align:right">Returned Qty</th><th style="text-align:right">New Qty</th><th style="text-align:right">Price</th><th style="text-align:right">Return Total</th></tr></thead><tbody>${rows||'<tr><td colspan="8">No item details found.</td></tr>'}</tbody></table>
 <div class="summary"><div class="sumrow"><span>Original Sale Total</span><b>$${original.toFixed(2)}</b></div><div class="sumrow"><span>Total Returned</span><b style="color:#dc2626">-$${returned.toFixed(2)}</b></div><div class="sumrow new"><span>New Total</span><span>$${newTotal.toFixed(2)}</span></div><div class="sumrow"><span>This Return</span><b>$${Number(r.total_amount||0).toFixed(2)}</b></div><div class="sumrow"><span>Cash Refunded</span><b>$${Number(r.refund_amount||0).toFixed(2)}</b></div><div class="sumrow"><span>Debt Reduced</span><b>$${Number(r.balance_adjustment||0).toFixed(2)}</b></div>${due>0?`<div class="owed"><div class="label">Amount Owed Before Return</div><b>$${due.toFixed(2)}</b><br><div class="label" style="margin-top:7px">Estimated Amount Owed After Return</div><b>$${Math.max(0,due-Number(r.balance_adjustment||0)).toFixed(2)}</b></div>`:''}</div>
 <div class="notes"><b>Reason:</b> ${esc(r.reason||'—')}<br><br><b>Note:</b> ${esc(r.note||'—')}</div><div class="footer">Printed from <?=e($businessName)?> · ${new Date().toLocaleString()}</div>
 <script>window.onload=()=>window.print()<\/script></body></html>`);w.document.close();
}
function calc(){let t=0;document.querySelectorAll('.rq').forEach(x=>t+=(+x.value||0)*(+x.dataset.price||0));returnTotal.textContent=t.toFixed(2);if((+refund.value||0)>t)refund.value=t.toFixed(2)}
refund.addEventListener('input',calc);
function esc(s){const d=document.createElement('div');d.textContent=s??'';return d.innerHTML}
search.addEventListener('input',function(){let q=this.value.toLowerCase(),v=0;document.querySelectorAll('.return-row').forEach(r=>{let ok=r.dataset.search.includes(q);r.classList.toggle('hidden',!ok);if(ok)v++});none.classList.toggle('hidden',v!==0)});
document.addEventListener('click',e=>{if(e.target!==saleSearch&&!saleDrop.contains(e.target))saleDrop.classList.add('hidden')});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeReturn()});
</script></body></html>
