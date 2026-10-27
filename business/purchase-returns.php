<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('purchase_returns.view');

$businessId   = (int)($_SESSION['business_id'] ?? 0);
$businessName = (string)($_SESSION['business_name'] ?? 'Business');
$branchId     = (int)($_SESSION['branch_id'] ?? 0);
$branchName   = (string)($_SESSION['branch_name'] ?? 'Branch');
$userId       = (int)($_SESSION['user_id'] ?? 0);
$canAdd       = hasPermission('purchase_returns.add');

if ($businessId <= 0 || $branchId <= 0) { http_response_code(403); exit('Business or branch not available.'); }
if (!function_exists('pre')) { function pre($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('prflash')) { function prflash(string $m, string $t='success'): void { $_SESSION['prflash']=$m; $_SESSION['prflash_type']=$t; } }
if (empty($_SESSION['purchase_return_csrf'])) $_SESSION['purchase_return_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['purchase_return_csrf'];

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_return') {
    if (!$canAdd) { http_response_code(403); exit('Access denied.'); }
    if (!hash_equals($_SESSION['purchase_return_csrf'], (string)($_POST['csrf_token']??''))) {
        prflash('Security token is invalid.','error'); header('Location: purchase-returns.php'); exit;
    }
    try {
        $purchaseId=(int)($_POST['purchase_id']??0);
        $returnDateRaw=trim((string)($_POST['return_date']??''));
        $reason=trim((string)($_POST['reason']??''));
        $note=trim((string)($_POST['note']??''));
        $refundAmount=max(0,(float)($_POST['refund_amount']??0));
        $items=json_decode((string)($_POST['items_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
        if ($purchaseId<=0 || !is_array($items) || !$items) throw new RuntimeException('Select a purchase and add at least one return item.');
        $returnDate=$returnDateRaw===''?date('Y-m-d H:i:s'):date('Y-m-d H:i:s',strtotime($returnDateRaw)?:throw new RuntimeException('Return Date is invalid.'));

        $s=$conn->prepare("SELECT id,purchase_no,supplier_id,total_amount,paid_amount,balance_due,status FROM purchases WHERE id=? AND business_id=? AND branch_id=? LIMIT 1");
        $s->execute([$purchaseId,$businessId,$branchId]); $purchase=$s->fetch(PDO::FETCH_ASSOC);
        if(!$purchase || $purchase['status']!=='Completed') throw new RuntimeException('Purchase not found or not available for return.');

        $validated=[]; $totalAmount=0.0;
        foreach($items as $i=>$row){
            $purchaseItemId=(int)($row['purchase_item_id']??0); $qty=(float)($row['qty']??0);
            if($purchaseItemId<=0 || $qty<=0) throw new RuntimeException('Invalid return item on row '.($i+1).'.');
            $s=$conn->prepare("SELECT pi.*,p.product_name,p.track_expiry,u.unit_name,u.short_name FROM purchase_items pi INNER JOIN products p ON p.id=pi.product_id AND p.business_id=pi.business_id INNER JOIN product_units pu ON pu.id=pi.product_unit_id AND pu.business_id=pi.business_id LEFT JOIN units u ON u.id=pu.unit_id WHERE pi.id=? AND pi.purchase_id=? AND pi.business_id=? LIMIT 1");
            $s->execute([$purchaseItemId,$purchaseId,$businessId]); $it=$s->fetch(PDO::FETCH_ASSOC);
            if(!$it) throw new RuntimeException('Purchase item not found on row '.($i+1).'.');
            $s=$conn->prepare("SELECT COALESCE(SUM(pri.transaction_qty),0) FROM purchase_return_items pri INNER JOIN purchase_returns pr ON pr.id=pri.purchase_return_id WHERE pri.purchase_item_id=? AND pri.business_id=? AND pr.status='Completed'");
            $s->execute([$purchaseItemId,$businessId]); $already=(float)$s->fetchColumn();
            $remaining=round((float)$it['transaction_qty']-$already,3);
            if($qty>$remaining+0.0005) throw new RuntimeException($it['product_name'].': maximum return quantity is '.number_format(max(0,$remaining),3).'.');
            $baseQty=round($qty*(float)$it['conversion_qty'],3);
            $lineTotal=round($qty*(float)$it['unit_cost'],4);
            $validated[]=['purchase_item_id'=>$purchaseItemId,'product_id'=>(int)$it['product_id'],'product_name'=>$it['product_name'],'product_unit_id'=>(int)$it['product_unit_id'],'qty'=>$qty,'conversion'=>(float)$it['conversion_qty'],'base_qty'=>$baseQty,'unit_cost'=>(float)$it['unit_cost'],'base_unit_cost'=>(float)$it['base_unit_cost'],'line_total'=>$lineTotal,'track_expiry'=>(int)$it['track_expiry'],'batch_number'=>$it['batch_number'],'expiry_date'=>$it['expiry_date']];
            $totalAmount+=$lineTotal;
        }
        $totalAmount=round($totalAmount,2);
        if($refundAmount>$totalAmount) throw new RuntimeException('Refund Amount cannot be greater than Return Total.');
        $balanceAdjustment=round($totalAmount-$refundAmount,2);

        $conn->beginTransaction();
        $prefix='PRT-'.date('Ymd',strtotime($returnDate)).'-';
        $s=$conn->prepare("SELECT return_no FROM purchase_returns WHERE business_id=? AND return_no LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE"); $s->execute([$businessId,$prefix.'%']); $last=$s->fetchColumn(); $seq=1; if($last&&preg_match('/-(\\d+)$/',$last,$m))$seq=(int)$m[1]+1; $returnNo=$prefix.str_pad((string)$seq,4,'0',STR_PAD_LEFT);
        $s=$conn->prepare("INSERT INTO purchase_returns (business_id,branch_id,purchase_id,supplier_id,return_no,return_date,total_amount,refund_amount,balance_adjustment,status,reason,note,created_by) VALUES(?,?,?,?,?,?,?,?,?,'Completed',?,?,?)");
        $s->execute([$businessId,$branchId,$purchaseId,$purchase['supplier_id'],$returnNo,$returnDate,$totalAmount,$refundAmount,$balanceAdjustment,$reason!==''?$reason:null,$note!==''?$note:null,$userId]);
        $returnId=(int)$conn->lastInsertId();

        foreach($validated as $it){
            $s=$conn->prepare("SELECT id,quantity,average_cost FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? LIMIT 1 FOR UPDATE"); $s->execute([$businessId,$branchId,$it['product_id']]); $stock=$s->fetch(PDO::FETCH_ASSOC);
            $stockQty=$stock?(float)$stock['quantity']:0.0;
            if(!$stock || $stockQty+0.0005<$it['base_qty']) throw new RuntimeException($it['product_name'].': current stock is not enough for this return. Available '.number_format($stockQty,3).'.');

            $batchId=null; $batchAfter=null;
            if($it['track_expiry']===1){
                if($it['batch_number']!==null && trim((string)$it['batch_number'])!==''){
                    $s=$conn->prepare("SELECT id,quantity FROM product_batches WHERE business_id=? AND branch_id=? AND product_id=? AND batch_number=? ORDER BY id ASC LIMIT 1 FOR UPDATE");
                    $s->execute([$businessId,$branchId,$it['product_id'],$it['batch_number']]);
                } else {
                    $s=$conn->prepare("SELECT id,quantity FROM product_batches WHERE business_id=? AND branch_id=? AND product_id=? AND purchase_item_id=? ORDER BY id ASC LIMIT 1 FOR UPDATE");
                    $s->execute([$businessId,$branchId,$it['product_id'],$it['purchase_item_id']]);
                }
                $batch=$s->fetch(PDO::FETCH_ASSOC);
                if(!$batch) throw new RuntimeException($it['product_name'].': original Batch & Expiry record was not found.');
                if((float)$batch['quantity']+0.0005<$it['base_qty']) throw new RuntimeException($it['product_name'].': Batch & Expiry quantity is not enough. Available '.number_format((float)$batch['quantity'],3).'.');
                $batchId=(int)$batch['id']; $batchAfter=round((float)$batch['quantity']-$it['base_qty'],3);
            }

            $s=$conn->prepare("INSERT INTO purchase_return_items (business_id,purchase_return_id,purchase_item_id,product_id,product_unit_id,transaction_qty,conversion_qty,base_qty,unit_cost,base_unit_cost,total_cost) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$businessId,$returnId,$it['purchase_item_id'],$it['product_id'],$it['product_unit_id'],$it['qty'],$it['conversion'],$it['base_qty'],$it['unit_cost'],$it['base_unit_cost'],$it['line_total']]);

            $newStock=round($stockQty-$it['base_qty'],3);
            $newAvg=$newStock>0?(float)$stock['average_cost']:0.0;
            $s=$conn->prepare("UPDATE product_stock SET quantity=?,average_cost=? WHERE id=? AND business_id=? AND branch_id=?"); $s->execute([$newStock,$newAvg,(int)$stock['id'],$businessId,$branchId]);
            $s=$conn->prepare("INSERT INTO stock_movements (business_id,branch_id,product_id,product_unit_id,movement_type,reference_type,reference_id,transaction_qty,conversion_qty,base_qty,qty_in,qty_out,unit_cost,total_cost,balance_after,note,created_by) VALUES(?,?,?,?,'PURCHASE_RETURN','PURCHASE_RETURN',?,?,?,?,0,?,?,?,?,?,?)");
            $s->execute([$businessId,$branchId,$it['product_id'],$it['product_unit_id'],$returnId,$it['qty'],$it['conversion'],$it['base_qty'],$it['base_qty'],$it['base_unit_cost'],$it['line_total'],$newStock,'Purchase Return '.$returnNo,$userId]);

            if($batchId!==null){
                $s=$conn->prepare("UPDATE product_batches SET quantity=?,status=CASE WHEN ?<=0 THEN 'Depleted' WHEN expiry_date IS NOT NULL AND expiry_date<CURDATE() THEN 'Expired' ELSE 'Active' END WHERE id=? AND business_id=? AND branch_id=?");
                $s->execute([$batchAfter,$batchAfter,$batchId,$businessId,$branchId]);
                $s=$conn->prepare("INSERT INTO batch_movements (business_id,branch_id,batch_id,product_id,movement_type,reference_type,reference_id,qty_in,qty_out,balance_after,note,created_by) VALUES(?,?,?,?,'PURCHASE_RETURN','PURCHASE_RETURN',?,0,?,?,?,?)");
                $s->execute([$businessId,$branchId,$batchId,$it['product_id'],$returnId,$it['base_qty'],$batchAfter,'Purchase Return '.$returnNo,$userId]);
            }
        }
        try { if(function_exists('logActivity')) logActivity('CREATE_PURCHASE_RETURN','purchase_returns',$returnId,'Created purchase return '.$returnNo); } catch(Throwable $ignore){}
        $conn->commit(); prflash('Purchase Return '.$returnNo.' saved successfully. Stock and Batch quantities were reduced automatically.');
    } catch(Throwable $e){ if($conn->inTransaction())$conn->rollBack(); prflash($e->getMessage(),'error'); }
    header('Location: purchase-returns.php'); exit;
}

try{
$s=$conn->prepare("SELECT p.id,p.purchase_no,p.purchase_date,p.supplier_id,COALESCE(s.supplier_name,'No Supplier') supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id AND s.business_id=p.business_id WHERE p.business_id=? AND p.branch_id=? AND p.status='Completed' ORDER BY p.id DESC LIMIT 500"); $s->execute([$businessId,$branchId]); $purchases=$s->fetchAll(PDO::FETCH_ASSOC);
$s=$conn->prepare("SELECT pi.id purchase_item_id,pi.purchase_id,pi.product_id,pi.product_unit_id,pi.transaction_qty,pi.conversion_qty,pi.unit_cost,pi.total_cost,pi.batch_number,pi.expiry_date,p.product_name,p.product_code,p.track_expiry,u.unit_name,u.short_name,COALESCE((SELECT SUM(pri.transaction_qty) FROM purchase_return_items pri INNER JOIN purchase_returns pr ON pr.id=pri.purchase_return_id WHERE pri.purchase_item_id=pi.id AND pr.status='Completed'),0) returned_qty FROM purchase_items pi INNER JOIN products p ON p.id=pi.product_id AND p.business_id=pi.business_id INNER JOIN product_units pu ON pu.id=pi.product_unit_id LEFT JOIN units u ON u.id=pu.unit_id WHERE pi.business_id=? ORDER BY pi.id"); $s->execute([$businessId]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){$purchases=[];$rows=[];}
$itemMap=[]; foreach($rows as $r){$remaining=max(0,(float)$r['transaction_qty']-(float)$r['returned_qty']); if($remaining<=0.0005)continue; $r['remaining_qty']=$remaining; $itemMap[(int)$r['purchase_id']][]=$r;}
try{$s=$conn->prepare("SELECT pr.id,pr.return_no,pr.return_date,pr.total_amount,pr.refund_amount,pr.balance_adjustment,pr.reason,p.purchase_no,COALESCE(s.supplier_name,'No Supplier') supplier_name,(SELECT COUNT(*) FROM purchase_return_items x WHERE x.purchase_return_id=pr.id) item_count FROM purchase_returns pr INNER JOIN purchases p ON p.id=pr.purchase_id LEFT JOIN suppliers s ON s.id=pr.supplier_id WHERE pr.business_id=? AND pr.branch_id=? ORDER BY pr.id DESC LIMIT 300");$s->execute([$businessId,$branchId]);$returns=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$returns=[];}
$flash=$_SESSION['prflash']??null;$flashType=$_SESSION['prflash_type']??'success';unset($_SESSION['prflash'],$_SESSION['prflash_type']);
?>
<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Purchase Returns - <?=pre($businessName)?></title><link rel="icon" type="image/png" href="../assets/images/favicon.png"><script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script><script>tailwind.config={darkMode:'class'}</script></head>
<body class="min-h-screen bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
<?php require_once __DIR__.'/includes/sidebar.php'; ?><div class="min-h-screen lg:pl-64"><?php require_once __DIR__.'/includes/header.php'; ?>
<main class="p-4 sm:p-5 lg:p-6">
<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h1 class="text-lg font-extrabold text-slate-900 dark:text-white">Purchase Returns</h1><p class="mt-1 text-[10px] text-slate-400">Return purchased goods to suppliers. Stock and Batch & Expiry are updated automatically.</p></div><?php if($canAdd):?><button onclick="openReturnModal()" class="inline-flex h-9 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white shadow-sm hover:bg-blue-700"><i data-lucide="undo-2" class="h-4 w-4"></i>Add Purchase Return</button><?php endif;?></div>
<?php if($flash):?><div class="mb-5 flex items-center gap-3 rounded-xl border px-4 py-3 text-[10px] font-semibold <?= $flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700' ?>"><i data-lucide="<?= $flashType==='error'?'circle-alert':'circle-check' ?>" class="h-4 w-4"></i><?=pre($flash)?></div><?php endif;?>
<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4"><div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><p class="text-[9px] font-bold uppercase text-slate-400">Total Returns</p><p class="mt-2 text-xl font-extrabold"><?=number_format(count($returns))?></p></div><div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><p class="text-[9px] font-bold uppercase text-slate-400">Return Value</p><p class="mt-2 text-xl font-extrabold text-red-500">$<?=number_format(array_sum(array_column($returns,'total_amount')),2)?></p></div><div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><p class="text-[9px] font-bold uppercase text-slate-400">Refunded</p><p class="mt-2 text-xl font-extrabold text-emerald-600">$<?=number_format(array_sum(array_column($returns,'refund_amount')),2)?></p></div><div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><p class="text-[9px] font-bold uppercase text-slate-400">Branch</p><p class="mt-2 truncate text-sm font-extrabold text-blue-600"><?=pre($branchName)?></p></div></div>
<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"><div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800"><div><h2 class="text-[12px] font-extrabold text-slate-900 dark:text-white">Purchase Return List</h2><p class="mt-1 text-[9px] text-slate-400">Search automatically.</p></div><div class="relative w-full sm:w-72"><i data-lucide="search" class="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"></i><input id="returnSearch" autocomplete="off" placeholder="Search ID, return, purchase, supplier..." class="h-9 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"></div></div>
<div class="overflow-x-auto"><table class="w-full text-left"><thead class="bg-slate-50 text-[9px] font-bold uppercase text-slate-400 dark:bg-slate-950/60"><tr><th class="px-5 py-3">ID / Return</th><th class="px-5 py-3">Purchase</th><th class="px-5 py-3">Supplier</th><th class="px-5 py-3">Items</th><th class="px-5 py-3 text-right">Total</th><th class="px-5 py-3 text-right">Refund</th><th class="px-5 py-3">Date</th></tr></thead><tbody id="returnBody"><?php if(!$returns):?><tr><td colspan="7" class="px-5 py-14 text-center text-[10px] text-slate-400">No purchase returns found.</td></tr><?php endif;?><?php foreach($returns as $r): $search=strtolower($r['id'].' '.$r['return_no'].' '.$r['purchase_no'].' '.$r['supplier_name'].' '.$r['reason']);?><tr class="return-row border-t border-slate-100 text-[10px] dark:border-slate-800" data-search="<?=pre($search)?>"><td class="px-5 py-3.5"><p class="font-bold text-slate-900 dark:text-white"><?=pre($r['return_no'])?></p><p class="text-[9px] text-slate-400">#<?=pre($r['id'])?></p></td><td class="px-5 py-3.5 font-semibold"><?=pre($r['purchase_no'])?></td><td class="px-5 py-3.5"><?=pre($r['supplier_name'])?></td><td class="px-5 py-3.5"><?=number_format((int)$r['item_count'])?></td><td class="px-5 py-3.5 text-right font-bold">$<?=number_format((float)$r['total_amount'],2)?></td><td class="px-5 py-3.5 text-right text-emerald-600">$<?=number_format((float)$r['refund_amount'],2)?></td><td class="px-5 py-3.5 text-slate-500"><?=pre(date('Y-m-d H:i',strtotime($r['return_date'])))?></td></tr><?php endforeach;?></tbody></table></div></div>
</main></div>

<div id="returnModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4"><div class="max-h-[94vh] w-full max-w-5xl overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-slate-900"><div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><div><h3 class="text-sm font-extrabold text-slate-900 dark:text-white">Add Purchase Return</h3><p class="mt-1 text-[9px] text-slate-400">Quantity will be deducted from Current Stock and Batch & Expiry automatically.</p></div><button type="button" onclick="closeReturnModal()" class="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800"><i data-lucide="x" class="h-4 w-4"></i></button></div>
<form id="returnForm" method="post" class="p-5"><input type="hidden" name="action" value="add_return"><input type="hidden" name="csrf_token" value="<?=pre($csrf)?>"><input type="hidden" name="purchase_id" id="purchaseId"><input type="hidden" name="items_json" id="itemsJson">
<div class="grid gap-4 md:grid-cols-2"><div class="relative"><label class="mb-1.5 block text-[9px] font-bold uppercase text-slate-500">Purchase *</label><input id="purchaseSearchInput" type="text" autocomplete="off" placeholder="Search Purchase ID, No, Supplier..." class="h-10 w-full rounded-xl border border-slate-200 px-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><div id="purchaseOptions" class="absolute z-30 mt-1 hidden max-h-56 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900"></div></div><div><label class="mb-1.5 block text-[9px] font-bold uppercase text-slate-500">Return Date</label><input type="datetime-local" name="return_date" value="<?=date('Y-m-d\TH:i')?>" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"></div></div>
<div id="purchaseInfo" class="mt-4 hidden rounded-xl border border-blue-100 bg-blue-50 p-3 text-[10px] text-blue-700 dark:border-blue-900 dark:bg-blue-950/30"></div>
<div class="mt-5 rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><div class="mb-3 flex items-center justify-between"><div><h4 class="text-[11px] font-extrabold">Return Items</h4><p class="text-[9px] text-slate-400">Only remaining quantities from the selected purchase are shown.</p></div></div><div class="grid gap-3 md:grid-cols-[1.7fr_.7fr_auto]"><div class="relative"><input id="itemSearchInput" type="text" autocomplete="off" disabled placeholder="Select purchase first..." class="h-10 w-full rounded-xl border border-slate-200 px-3 text-[10px] outline-none focus:border-blue-500 disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-950"><div id="itemOptions" class="absolute z-20 mt-1 hidden max-h-56 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900"></div></div><input id="returnQty" type="number" min="0.001" step="0.001" placeholder="Quantity" class="h-10 rounded-xl border border-slate-200 px-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><button type="button" onclick="addReturnItem()" class="h-10 rounded-xl bg-slate-900 px-4 text-[10px] font-bold text-white dark:bg-blue-600">Add Item</button></div><div id="itemHint" class="mt-2 text-[9px] text-slate-400"></div><div class="mt-4 overflow-x-auto"><table class="w-full text-left"><thead class="bg-slate-50 text-[9px] uppercase text-slate-400 dark:bg-slate-950"><tr><th class="px-3 py-2">Product</th><th class="px-3 py-2">Unit</th><th class="px-3 py-2">Qty</th><th class="px-3 py-2">Batch / Expiry</th><th class="px-3 py-2 text-right">Total</th><th></th></tr></thead><tbody id="selectedItems"><tr id="emptyItems"><td colspan="6" class="px-3 py-8 text-center text-[10px] text-slate-400">No return items added.</td></tr></tbody></table></div></div>
<div class="mt-5 grid gap-4 md:grid-cols-3"><div><label class="mb-1.5 block text-[9px] font-bold uppercase text-slate-500">Reason</label><input name="reason" placeholder="Damaged, wrong item..." class="h-10 w-full rounded-xl border border-slate-200 px-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"></div><div><label class="mb-1.5 block text-[9px] font-bold uppercase text-slate-500">Refund Amount</label><input name="refund_amount" id="refundAmount" type="number" min="0" step="0.01" value="0.00" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"></div><div><label class="mb-1.5 block text-[9px] font-bold uppercase text-slate-500">Return Total</label><div id="returnTotal" class="flex h-10 items-center rounded-xl bg-slate-100 px-3 text-sm font-extrabold dark:bg-slate-800">$0.00</div></div></div><div class="mt-4"><label class="mb-1.5 block text-[9px] font-bold uppercase text-slate-500">Note</label><textarea name="note" rows="2" class="w-full rounded-xl border border-slate-200 p-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"></textarea></div><div class="mt-5 flex justify-end gap-2"><button type="button" onclick="closeReturnModal()" class="h-9 rounded-xl border border-slate-200 px-4 text-[10px] font-bold dark:border-slate-700">Cancel</button><button type="submit" class="h-9 rounded-xl bg-blue-600 px-5 text-[10px] font-bold text-white hover:bg-blue-700">Save Purchase Return</button></div></form></div></div>
<script>
const purchases=<?=json_encode($purchases,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const itemMap=<?=json_encode($itemMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
let activePurchase=null,activeItem=null,selected=[];
const $=id=>document.getElementById(id); const money=n=>Number(n||0).toFixed(2); const qty=n=>Number(n||0).toFixed(3);
function openReturnModal(){ $('returnModal').classList.remove('hidden');$('returnModal').classList.add('flex');resetReturn(); }
function closeReturnModal(){ $('returnModal').classList.add('hidden');$('returnModal').classList.remove('flex'); }
function resetReturn(){activePurchase=null;activeItem=null;selected=[];$('purchaseId').value='';$('purchaseSearchInput').value='';$('itemSearchInput').value='';$('itemSearchInput').disabled=true;$('purchaseInfo').classList.add('hidden');renderItems();renderPurchaseOptions('');}
function renderPurchaseOptions(q){q=(q||'').toLowerCase().trim();const list=purchases.filter(p=>(`${p.id} ${p.purchase_no} ${p.supplier_name}`).toLowerCase().includes(q)&&itemMap[p.id]?.length).slice(0,80);$('purchaseOptions').innerHTML=list.length?list.map(p=>`<button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-[10px] hover:bg-blue-50 dark:hover:bg-slate-800" onclick="selectPurchase(${p.id})"><b>${p.purchase_no}</b><span class="ml-2 text-slate-400">#${p.id} · ${p.supplier_name}</span></button>`).join(''):'<div class="px-3 py-3 text-[10px] text-slate-400">No returnable purchases found.</div>';$('purchaseOptions').classList.remove('hidden');}
function selectPurchase(id){activePurchase=purchases.find(p=>Number(p.id)===Number(id));selected=[];activeItem=null;$('purchaseId').value=id;$('purchaseSearchInput').value=`${activePurchase.purchase_no} - ${activePurchase.supplier_name}`;$('purchaseOptions').classList.add('hidden');$('itemSearchInput').disabled=false;$('itemSearchInput').placeholder='Search product, ID, code, batch...';$('purchaseInfo').innerHTML=`<b>Purchase:</b> ${activePurchase.purchase_no} &nbsp; <b>Supplier:</b> ${activePurchase.supplier_name} &nbsp; <b>Date:</b> ${activePurchase.purchase_date}`;$('purchaseInfo').classList.remove('hidden');renderItemOptions('');renderItems();}
function renderItemOptions(q){if(!activePurchase)return;q=(q||'').toLowerCase().trim();const list=(itemMap[activePurchase.id]||[]).filter(i=>(`${i.purchase_item_id} ${i.product_id} ${i.product_name} ${i.product_code||''} ${i.batch_number||''} ${i.expiry_date||''}`).toLowerCase().includes(q)&&!selected.some(x=>Number(x.purchase_item_id)===Number(i.purchase_item_id)));$('itemOptions').innerHTML=list.length?list.map(i=>`<button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-[10px] hover:bg-blue-50 dark:hover:bg-slate-800" onclick="selectItem(${i.purchase_item_id})"><b>${i.product_name}</b> <span class="text-slate-400">#${i.product_id} · ${i.unit_name} (${i.short_name||''}) · Remaining ${qty(i.remaining_qty)}${i.batch_number?` · Batch ${i.batch_number}`:''}</span></button>`).join(''):'<div class="px-3 py-3 text-[10px] text-slate-400">No items found.</div>';$('itemOptions').classList.remove('hidden');}
function selectItem(id){activeItem=(itemMap[activePurchase.id]||[]).find(i=>Number(i.purchase_item_id)===Number(id));$('itemSearchInput').value=activeItem.product_name;$('returnQty').value=qty(activeItem.remaining_qty);$('itemHint').textContent=`Maximum return: ${qty(activeItem.remaining_qty)} ${activeItem.short_name||activeItem.unit_name}${activeItem.batch_number?' | Batch: '+activeItem.batch_number:''}${activeItem.expiry_date?' | Expiry: '+activeItem.expiry_date:''}`;$('itemOptions').classList.add('hidden');}
function addReturnItem(){if(!activeItem){alert('Select a purchase item first.');return;}const q=Number($('returnQty').value||0);if(q<=0||q>Number(activeItem.remaining_qty)+0.0005){alert(`Quantity must be between 0.001 and ${qty(activeItem.remaining_qty)}.`);return;}selected.push({...activeItem,qty:q});activeItem=null;$('itemSearchInput').value='';$('returnQty').value='';$('itemHint').textContent='';renderItems();renderItemOptions('');$('itemOptions').classList.add('hidden');}
function removeItem(i){selected.splice(i,1);renderItems();}
function renderItems(){const body=$('selectedItems');if(!selected.length){body.innerHTML='<tr><td colspan="6" class="px-3 py-8 text-center text-[10px] text-slate-400">No return items added.</td></tr>';}else body.innerHTML=selected.map((i,x)=>`<tr class="border-t border-slate-100 text-[10px] dark:border-slate-800"><td class="px-3 py-2 font-bold">${i.product_name}</td><td class="px-3 py-2">${i.unit_name} (${i.short_name||''})</td><td class="px-3 py-2">${qty(i.qty)}</td><td class="px-3 py-2"><div>${i.batch_number||'—'}</div><div class="text-[9px] text-slate-400">${i.expiry_date||'No expiry'}</div></td><td class="px-3 py-2 text-right font-bold">$${money(Number(i.qty)*Number(i.unit_cost))}</td><td class="px-3 py-2 text-right"><button type="button" onclick="removeItem(${x})" class="text-red-500"><i data-lucide="trash-2" class="h-4 w-4"></i></button></td></tr>`).join('');const total=selected.reduce((a,i)=>a+Number(i.qty)*Number(i.unit_cost),0);$('returnTotal').textContent='$'+money(total);$('itemsJson').value=JSON.stringify(selected.map(i=>({purchase_item_id:i.purchase_item_id,qty:i.qty})));if(window.lucide)lucide.createIcons();}
$('purchaseSearchInput')?.addEventListener('input',e=>renderPurchaseOptions(e.target.value));$('purchaseSearchInput')?.addEventListener('focus',e=>renderPurchaseOptions(e.target.value));$('itemSearchInput')?.addEventListener('input',e=>renderItemOptions(e.target.value));$('itemSearchInput')?.addEventListener('focus',e=>renderItemOptions(e.target.value));
$('returnSearch')?.addEventListener('input',e=>{const q=e.target.value.toLowerCase().trim();document.querySelectorAll('.return-row').forEach(r=>r.style.display=(r.dataset.search||'').includes(q)?'':'none');});
$('returnForm')?.addEventListener('submit',e=>{if(!$('purchaseId').value||!selected.length){e.preventDefault();alert('Select a purchase and add at least one return item.');return;}renderItems();});
document.addEventListener('click',e=>{if(!e.target.closest('#purchaseSearchInput')&&!e.target.closest('#purchaseOptions'))$('purchaseOptions')?.classList.add('hidden');if(!e.target.closest('#itemSearchInput')&&!e.target.closest('#itemOptions'))$('itemOptions')?.classList.add('hidden');});
if(window.lucide)lucide.createIcons();
</script></body></html>
