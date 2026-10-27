<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('sales.view');

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??0);
$userId=(int)($_SESSION['user_id']??0);
$businessName=(string)($_SESSION['business_name']??'Business');
$canAdd=hasPermission('sales.add');
$canUpdate=hasPermission('sales.update');

if($businessId<=0||$branchId<=0||$userId<=0){http_response_code(403);exit('Business/branch/user not available.');}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function flashSale($m,$t='success'){$_SESSION['sale_flash']=$m;$_SESSION['sale_flash_type']=$t;}
if(empty($_SESSION['sale_csrf']))$_SESSION['sale_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['sale_csrf'];

function saleNo(PDO $conn,int $businessId):string{
    $prefix='SAL-'.date('Ymd').'-';
    $s=$conn->prepare("SELECT sale_no FROM sales WHERE business_id=? AND sale_no LIKE ? ORDER BY id DESC LIMIT 1");
    $s->execute([$businessId,$prefix.'%']); $last=$s->fetchColumn();
    $n=$last?(int)substr($last,-4)+1:1;
    return $prefix.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add'){
    if(!$canAdd){http_response_code(403);exit('Access denied.');}
    if(!hash_equals($_SESSION['sale_csrf'],(string)($_POST['csrf_token']??''))){flashSale('Invalid security token.','error');header('Location: sales.php');exit;}
    try{
        $customerId=(int)($_POST['customer_id']??0); $customerId=$customerId>0?$customerId:null;
        $saleDate=(string)($_POST['sale_date']??date('Y-m-d\TH:i'));
        $discount=max(0,(float)($_POST['discount_amount']??0));
        $tax=max(0,(float)($_POST['tax_amount']??0));
        $paid=max(0,(float)($_POST['paid_amount']??0));
        $note=trim((string)($_POST['note']??''));
        $items=$_POST['items']??[];
        if(!$items)throw new RuntimeException('Add at least one sale item.');

        if($customerId){
            $q=$conn->prepare("SELECT id FROM customers WHERE id=? AND business_id=? AND status='Active'");
            $q->execute([$customerId,$businessId]); if(!$q->fetch())throw new RuntimeException('Customer is invalid.');
        }

        $conn->beginTransaction();
        $validated=[];$subtotal=0;
        foreach($items as $it){
            $productId=(int)($it['product_id']??0); $puId=(int)($it['product_unit_id']??0);
            $qty=(float)($it['qty']??0); $price=(float)($it['unit_price']??0);
            if($productId<=0||$puId<=0||$qty<=0||$price<0)throw new RuntimeException('Invalid sale item.');

            $q=$conn->prepare("SELECT p.product_name,p.track_expiry,pu.conversion_qty
                FROM products p JOIN product_units pu ON pu.product_id=p.id AND pu.business_id=p.business_id
                WHERE p.id=? AND p.business_id=? AND p.status='Active' AND pu.id=? AND pu.status='Active' AND pu.can_sell=1 FOR UPDATE");
            $q->execute([$productId,$businessId,$puId]); $x=$q->fetch(PDO::FETCH_ASSOC);
            if(!$x)throw new RuntimeException('Product/unit is not available for sale.');

            $conv=(float)$x['conversion_qty']; $baseQty=$qty*$conv;
            $q=$conn->prepare("SELECT quantity,average_cost FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? FOR UPDATE");
            $q->execute([$businessId,$branchId,$productId]); $stock=$q->fetch(PDO::FETCH_ASSOC);
            if(!$stock || (float)$stock['quantity']+0.0005<$baseQty)
                throw new RuntimeException($x['product_name'].' has insufficient stock.');

            $line=$qty*$price; $subtotal+=$line;
            $validated[]=[$productId,$puId,$qty,$price,$conv,$baseQty,(float)$stock['average_cost'],(int)$x['track_expiry'],$x['product_name']];
        }
        $total=max(0,$subtotal-$discount+$tax);
        if($paid>$total+0.005)throw new RuntimeException('Paid Amount cannot exceed Total Amount.');
        $balance=max(0,$total-$paid);
        $payment=$balance<0.005?'Paid':($paid>0?'Partial':'Unpaid');

        $no=saleNo($conn,$businessId);
        $q=$conn->prepare("INSERT INTO sales(business_id,branch_id,sale_no,customer_id,sale_date,subtotal,discount_amount,tax_amount,total_amount,paid_amount,balance_due,payment_status,status,note,created_by)
                           VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'Completed',?,?)");
        $q->execute([$businessId,$branchId,$no,$customerId,date('Y-m-d H:i:s',strtotime($saleDate)),$subtotal,$discount,$tax,$total,$paid,$balance,$payment,$note?:null,$userId]);
        $saleId=(int)$conn->lastInsertId();

        foreach($validated as [$productId,$puId,$qty,$price,$conv,$baseQty,$avgCost,$trackExpiry,$productName]){
            $basePrice=$conv>0?$price/$conv:0; $cost=$avgCost*$baseQty; $line=$qty*$price;
            $q=$conn->prepare("INSERT INTO sale_items(business_id,sale_id,product_id,product_unit_id,transaction_qty,conversion_qty,base_qty,unit_price,base_unit_price,unit_cost,total_cost,total_price)
                               VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
            $q->execute([$businessId,$saleId,$productId,$puId,$qty,$conv,$baseQty,$price,$basePrice,$avgCost,$cost,$line]);

            $q=$conn->prepare("UPDATE product_stock SET quantity=quantity-? WHERE business_id=? AND branch_id=? AND product_id=?");
            $q->execute([$baseQty,$businessId,$branchId,$productId]);
            $newBalance=(float)$conn->query("SELECT quantity FROM product_stock WHERE business_id={$businessId} AND branch_id={$branchId} AND product_id={$productId}")->fetchColumn();

            $q=$conn->prepare("INSERT INTO stock_movements(business_id,branch_id,product_id,product_unit_id,movement_type,reference_type,reference_id,transaction_qty,conversion_qty,base_qty,qty_in,qty_out,unit_cost,total_cost,balance_after,note,created_by)
                               VALUES(?,?,?,?, 'SALE','SALE',?,?,?,?,0,?,?,?,?,?,?)");
            $q->execute([$businessId,$branchId,$productId,$puId,$saleId,$qty,$conv,$baseQty,$baseQty,$avgCost,$cost,$newBalance,'Sale '.$no,$userId]);

            if($trackExpiry){
                $remaining=$baseQty;
                $q=$conn->prepare("SELECT id,quantity,expiry_date FROM product_batches
                    WHERE business_id=? AND branch_id=? AND product_id=? AND quantity>0
                    ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC, id ASC FOR UPDATE");
                $q->execute([$businessId,$branchId,$productId]);
                $batches=$q->fetchAll(PDO::FETCH_ASSOC);
                foreach($batches as $b){
                    if($remaining<=0.0005)break;
                    $take=min($remaining,(float)$b['quantity']); if($take<=0)continue;
                    $after=(float)$b['quantity']-$take;
                    $status=$after<=0.0005?'Depleted':(($b['expiry_date']&&$b['expiry_date']<date('Y-m-d'))?'Expired':'Active');
                    $u=$conn->prepare("UPDATE product_batches SET quantity=?,status=? WHERE id=?");$u->execute([max(0,$after),$status,$b['id']]);
                    $bm=$conn->prepare("INSERT INTO batch_movements(business_id,branch_id,batch_id,product_id,movement_type,reference_type,reference_id,qty_in,qty_out,balance_after,note,created_by)
                                        VALUES(?,?,?,?, 'SALE','SALE',?,0,?,?,?,?)");
                    $bm->execute([$businessId,$branchId,$b['id'],$productId,$saleId,$take,max(0,$after),'FEFO sale '.$no,$userId]);
                    $remaining-=$take;
                }
                if($remaining>0.0005)throw new RuntimeException($productName.' stock is not fully allocated to expiry batches.');
            }
        }
        $conn->commit();
        try{if(function_exists('logActivity'))logActivity('CREATE','Sales',$saleId,'Created sale '.$no);}catch(Throwable $ignore){}
        flashSale('Sale '.$no.' completed successfully.');
    }catch(Throwable $ex){
        if($conn->inTransaction())$conn->rollBack();
        flashSale($ex->getMessage(),'error');
    }
    header('Location: sales.php');exit;
}


if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update'){
    if(!$canUpdate){http_response_code(403);exit('Access denied.');}
    if(!hash_equals($_SESSION['sale_csrf'],(string)($_POST['csrf_token']??''))){flashSale('Invalid security token.','error');header('Location: sales.php');exit;}
    try{
        $saleId=(int)($_POST['sale_id']??0); if($saleId<=0)throw new RuntimeException('Invalid sale.');
        $customerId=(int)($_POST['customer_id']??0);$customerId=$customerId>0?$customerId:null;
        $saleDate=(string)($_POST['sale_date']??date('Y-m-d\TH:i'));$discount=max(0,(float)($_POST['discount_amount']??0));
        $tax=max(0,(float)($_POST['tax_amount']??0));$paid=max(0,(float)($_POST['paid_amount']??0));$note=trim((string)($_POST['note']??''));$items=$_POST['items']??[];
        if(!$items)throw new RuntimeException('Add at least one sale item.');
        if($customerId){$q=$conn->prepare("SELECT id FROM customers WHERE id=? AND business_id=? AND status='Active'");$q->execute([$customerId,$businessId]);if(!$q->fetch())throw new RuntimeException('Customer is invalid.');}
        $conn->beginTransaction();
        $q=$conn->prepare("SELECT * FROM sales WHERE id=? AND business_id=? AND branch_id=? AND status='Completed' FOR UPDATE");$q->execute([$saleId,$businessId,$branchId]);$oldSale=$q->fetch(PDO::FETCH_ASSOC);
        if(!$oldSale)throw new RuntimeException('Sale not found.');
        $q=$conn->prepare("SELECT * FROM sale_items WHERE sale_id=? AND business_id=? FOR UPDATE");$q->execute([$saleId,$businessId]);$oldItems=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($oldItems as $oi){$u=$conn->prepare("UPDATE product_stock SET quantity=quantity+? WHERE business_id=? AND branch_id=? AND product_id=?");$u->execute([(float)$oi['base_qty'],$businessId,$branchId,(int)$oi['product_id']]);}
        $q=$conn->prepare("SELECT batch_id,SUM(qty_out) qty FROM batch_movements WHERE business_id=? AND branch_id=? AND reference_type='SALE' AND reference_id=? AND movement_type='SALE' GROUP BY batch_id FOR UPDATE");$q->execute([$businessId,$branchId,$saleId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $bm){$bq=$conn->prepare("SELECT quantity,expiry_date FROM product_batches WHERE id=? AND business_id=? AND branch_id=? FOR UPDATE");$bq->execute([$bm['batch_id'],$businessId,$branchId]);$b=$bq->fetch(PDO::FETCH_ASSOC);if(!$b)throw new RuntimeException('Original sale batch is missing.');$after=(float)$b['quantity']+(float)$bm['qty'];$st=($b['expiry_date']&&$b['expiry_date']<date('Y-m-d'))?'Expired':'Active';$bu=$conn->prepare("UPDATE product_batches SET quantity=?,status=? WHERE id=?");$bu->execute([$after,$st,$bm['batch_id']]);}
        $conn->prepare("DELETE FROM batch_movements WHERE business_id=? AND branch_id=? AND reference_type='SALE' AND reference_id=? AND movement_type='SALE'")->execute([$businessId,$branchId,$saleId]);
        $conn->prepare("DELETE FROM stock_movements WHERE business_id=? AND branch_id=? AND reference_type='SALE' AND reference_id=? AND movement_type='SALE'")->execute([$businessId,$branchId,$saleId]);
        $conn->prepare("DELETE FROM sale_items WHERE business_id=? AND sale_id=?")->execute([$businessId,$saleId]);
        $validated=[];$subtotal=0;
        foreach($items as $it){
            $productId=(int)($it['product_id']??0);$puId=(int)($it['product_unit_id']??0);$qty=(float)($it['qty']??0);$price=(float)($it['unit_price']??0);
            if($productId<=0||$puId<=0||$qty<=0||$price<0)throw new RuntimeException('Invalid sale item.');
            $q=$conn->prepare("SELECT p.product_name,p.track_expiry,pu.conversion_qty FROM products p JOIN product_units pu ON pu.product_id=p.id AND pu.business_id=p.business_id WHERE p.id=? AND p.business_id=? AND p.status='Active' AND pu.id=? AND pu.status='Active' AND pu.can_sell=1 FOR UPDATE");$q->execute([$productId,$businessId,$puId]);$x=$q->fetch(PDO::FETCH_ASSOC);if(!$x)throw new RuntimeException('Product/unit is not available for sale.');
            $conv=(float)$x['conversion_qty'];$baseQty=$qty*$conv;$q=$conn->prepare("SELECT quantity,average_cost FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? FOR UPDATE");$q->execute([$businessId,$branchId,$productId]);$stock=$q->fetch(PDO::FETCH_ASSOC);if(!$stock||(float)$stock['quantity']+0.0005<$baseQty)throw new RuntimeException($x['product_name'].' has insufficient stock.');
            $subtotal+=$qty*$price;$validated[]=[$productId,$puId,$qty,$price,$conv,$baseQty,(float)$stock['average_cost'],(int)$x['track_expiry'],$x['product_name']];
        }
        $total=max(0,$subtotal-$discount+$tax);if($paid>$total+0.005)throw new RuntimeException('Paid Amount cannot exceed Total Amount.');$balance=max(0,$total-$paid);$payment=$balance<0.005?'Paid':($paid>0?'Partial':'Unpaid');
        $q=$conn->prepare("UPDATE sales SET customer_id=?,sale_date=?,subtotal=?,discount_amount=?,tax_amount=?,total_amount=?,paid_amount=?,balance_due=?,payment_status=?,note=? WHERE id=? AND business_id=? AND branch_id=?");$q->execute([$customerId,date('Y-m-d H:i:s',strtotime($saleDate)),$subtotal,$discount,$tax,$total,$paid,$balance,$payment,$note?:null,$saleId,$businessId,$branchId]);
        foreach($validated as [$productId,$puId,$qty,$price,$conv,$baseQty,$avgCost,$trackExpiry,$productName]){
            $basePrice=$conv>0?$price/$conv:0;$cost=$avgCost*$baseQty;$line=$qty*$price;
            $q=$conn->prepare("INSERT INTO sale_items(business_id,sale_id,product_id,product_unit_id,transaction_qty,conversion_qty,base_qty,unit_price,base_unit_price,unit_cost,total_cost,total_price) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");$q->execute([$businessId,$saleId,$productId,$puId,$qty,$conv,$baseQty,$price,$basePrice,$avgCost,$cost,$line]);
            $conn->prepare("UPDATE product_stock SET quantity=quantity-? WHERE business_id=? AND branch_id=? AND product_id=?")->execute([$baseQty,$businessId,$branchId,$productId]);
            $q=$conn->prepare("SELECT quantity FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=?");$q->execute([$businessId,$branchId,$productId]);$newBalance=(float)$q->fetchColumn();
            $q=$conn->prepare("INSERT INTO stock_movements(business_id,branch_id,product_id,product_unit_id,movement_type,reference_type,reference_id,transaction_qty,conversion_qty,base_qty,qty_in,qty_out,unit_cost,total_cost,balance_after,note,created_by) VALUES(?,?,?,?, 'SALE','SALE',?,?,?,?,0,?,?,?,?,?,?)");$q->execute([$businessId,$branchId,$productId,$puId,$saleId,$qty,$conv,$baseQty,$baseQty,$avgCost,$cost,$newBalance,'Updated sale '.$oldSale['sale_no'],$userId]);
            if($trackExpiry){$remaining=$baseQty;$q=$conn->prepare("SELECT id,quantity,expiry_date FROM product_batches WHERE business_id=? AND branch_id=? AND product_id=? AND quantity>0 ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,expiry_date ASC,id ASC FOR UPDATE");$q->execute([$businessId,$branchId,$productId]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $b){if($remaining<=0.0005)break;$take=min($remaining,(float)$b['quantity']);if($take<=0)continue;$after=(float)$b['quantity']-$take;$st=$after<=0.0005?'Depleted':(($b['expiry_date']&&$b['expiry_date']<date('Y-m-d'))?'Expired':'Active');$conn->prepare("UPDATE product_batches SET quantity=?,status=? WHERE id=?")->execute([max(0,$after),$st,$b['id']]);$bm=$conn->prepare("INSERT INTO batch_movements(business_id,branch_id,batch_id,product_id,movement_type,reference_type,reference_id,qty_in,qty_out,balance_after,note,created_by) VALUES(?,?,?,?, 'SALE','SALE',?,0,?,?,?,?)");$bm->execute([$businessId,$branchId,$b['id'],$productId,$saleId,$take,max(0,$after),'Updated FEFO sale '.$oldSale['sale_no'],$userId]);$remaining-=$take;}if($remaining>0.0005)throw new RuntimeException($productName.' stock is not fully allocated to expiry batches.');}
        }
        $conn->commit();try{if(function_exists('logActivity'))logActivity('UPDATE','Sales',$saleId,'Updated sale '.$oldSale['sale_no']);}catch(Throwable $ignore){}flashSale('Sale '.$oldSale['sale_no'].' updated successfully.');
    }catch(Throwable $ex){if($conn->inTransaction())$conn->rollBack();flashSale($ex->getMessage(),'error');}
    header('Location: sales.php');exit;
}

$customers=$conn->prepare("SELECT id,customer_name,phone FROM customers WHERE business_id=? AND status='Active' ORDER BY customer_name");
$customers->execute([$businessId]);$customers=$customers->fetchAll(PDO::FETCH_ASSOC);

$q=$conn->prepare("SELECT p.id,p.product_name,p.product_code,p.barcode,p.track_expiry,ps.quantity
 FROM products p JOIN product_stock ps ON ps.product_id=p.id AND ps.business_id=p.business_id AND ps.branch_id=?
 WHERE p.business_id=? AND p.status='Active' AND ps.quantity>0 ORDER BY p.product_name");
$q->execute([$branchId,$businessId]);$products=$q->fetchAll(PDO::FETCH_ASSOC);

$q=$conn->prepare("SELECT pu.id,pu.product_id,pu.unit_id,pu.conversion_qty,pu.selling_price,u.unit_name,u.short_name
 FROM product_units pu JOIN units u ON u.id=pu.unit_id AND u.business_id=pu.business_id
 WHERE pu.business_id=? AND pu.status='Active' AND pu.can_sell=1 AND u.status='Active' ORDER BY u.unit_name");
$q->execute([$businessId]);$units=$q->fetchAll(PDO::FETCH_ASSOC);
$unitsByProduct=[];foreach($units as $u)$unitsByProduct[(int)$u['product_id']][]=$u;

$q=$conn->prepare("SELECT s.*,c.customer_name,u.full_name AS created_by_name,
 COALESCE((SELECT SUM(sr.total_amount)
           FROM sales_returns sr
           WHERE sr.business_id=s.business_id
             AND sr.branch_id=s.branch_id
             AND sr.sale_id=s.id
             AND sr.status='Completed'),0) AS returned_amount
 FROM sales s
 LEFT JOIN customers c ON c.id=s.customer_id
 LEFT JOIN users u ON u.id=s.created_by
 WHERE s.business_id=? AND s.branch_id=? ORDER BY s.id DESC LIMIT 300");
$q->execute([$businessId,$branchId]);$sales=$q->fetchAll(PDO::FETCH_ASSOC);
$saleEditData=[];
if($canUpdate && $sales){
    $ids=array_map(fn($x)=>(int)$x['id'],$sales);$ph=implode(',',array_fill(0,count($ids),'?'));
    $qq=$conn->prepare("SELECT si.sale_id,si.product_id,si.product_unit_id,si.transaction_qty,si.unit_price FROM sale_items si WHERE si.business_id=? AND si.sale_id IN ($ph) ORDER BY si.id");
    $qq->execute(array_merge([$businessId],$ids));foreach($qq->fetchAll(PDO::FETCH_ASSOC) as $it)$saleEditData[(int)$it['sale_id']][]=$it;
}

/* Sales report: ALL original sale items, including products never returned. */
$saleReportItems=[];
if($sales){
    $ids=array_map(fn($x)=>(int)$x['id'],$sales);
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $rq=$conn->prepare("SELECT si.sale_id,si.id sale_item_id,si.transaction_qty sold_qty,si.unit_price,si.total_price,
        p.product_name,u.unit_name,u.short_name,
        COALESCE((SELECT SUM(sri.transaction_qty)
                  FROM sales_return_items sri
                  JOIN sales_returns sr ON sr.id=sri.sales_return_id
                  WHERE sri.sale_item_id=si.id
                    AND sr.business_id=si.business_id
                    AND sr.branch_id=?
                    AND sr.status='Completed'),0) returned_qty
        FROM sale_items si
        JOIN products p ON p.id=si.product_id
        LEFT JOIN product_units pu ON pu.id=si.product_unit_id
        LEFT JOIN units u ON u.id=pu.unit_id
        WHERE si.business_id=? AND si.sale_id IN ($ph)
        ORDER BY si.sale_id,si.id");
    $rq->execute(array_merge([$branchId,$businessId],$ids));
    foreach($rq->fetchAll(PDO::FETCH_ASSOC) as $it)$saleReportItems[(int)$it['sale_id']][]=$it;
}

$totalSales=count($sales);$revenue=0;$netRevenue=0;$returnedTotal=0;$due=0;$paidSales=0;
$todayDiscountStmt=$conn->prepare("SELECT COALESCE(SUM(discount_amount),0) FROM sales WHERE business_id=? AND branch_id=? AND status='Completed' AND DATE(sale_date)=CURDATE()");
$todayDiscountStmt->execute([$businessId,$branchId]);
$todayDiscount=(float)$todayDiscountStmt->fetchColumn();

$monthDiscountStmt=$conn->prepare("SELECT COALESCE(SUM(discount_amount),0) FROM sales WHERE business_id=? AND branch_id=? AND status='Completed' AND YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE())");
$monthDiscountStmt->execute([$businessId,$branchId]);
$monthDiscount=(float)$monthDiscountStmt->fetchColumn();
$topDiscountUserStmt=$conn->prepare("
    SELECT u.full_name, COUNT(s.id) AS sales_count, COALESCE(SUM(s.discount_amount),0) AS discount_total
    FROM sales s
    JOIN users u ON u.id=s.created_by
    WHERE s.business_id=? AND s.branch_id=? AND s.status='Completed' AND s.discount_amount>0
    GROUP BY s.created_by,u.full_name
    ORDER BY discount_total DESC
    LIMIT 1
");
$topDiscountUserStmt->execute([$businessId,$branchId]);
$topDiscountUser=$topDiscountUserStmt->fetch(PDO::FETCH_ASSOC);

foreach($sales as $s){
    if($s['status']==='Completed'){
        $returned=(float)($s['returned_amount']??0);
        $revenue+=(float)$s['total_amount'];
        $returnedTotal+=$returned;
        $netRevenue+=max(0,(float)$s['total_amount']-$returned);
        $due+=(float)$s['balance_due'];
    }
    if($s['payment_status']==='Paid')$paidSales++;
}
$flash=$_SESSION['sale_flash']??null;$flashType=$_SESSION['sale_flash_type']??'success';unset($_SESSION['sale_flash'],$_SESSION['sale_flash_type']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sales - <?=e($businessName)?></title><script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script>
<style>
.field{height:40px;width:100%;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:0 12px;font-size:10px;outline:none;transition:.15s}
.field:focus{border-color:#3b82f6;background:#fff;box-shadow:0 0 0 3px #dbeafe}
.drop{position:absolute;z-index:70;top:44px;left:0;right:0;max-height:240px;overflow:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff;box-shadow:0 18px 40px #0f172a22}
.opt{padding:10px 12px;font-size:10px;cursor:pointer;border-bottom:1px solid #f1f5f9}.opt:last-child{border-bottom:0}.opt:hover{background:#eff6ff;color:#1d4ed8}
.cart-scroll{max-height:430px;overflow-y:auto}.cart-scroll::-webkit-scrollbar{width:5px}.cart-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px}
</style>
</head><body class="min-h-screen bg-slate-50 text-slate-700">
<?php require_once __DIR__.'/includes/sidebar.php';?><div class="min-h-screen lg:pl-64"><?php require_once __DIR__.'/includes/header.php';?>
<main class="p-4 sm:p-5 lg:p-6">
<div class="mb-5 flex items-center justify-between"><div><h1 class="text-lg font-extrabold text-slate-900">Sales</h1><p class="mt-1 text-[10px] text-slate-400">One sale per customer. Complete one customer before serving the next.</p></div>
<?php if($canAdd):?><button onclick="openSale()" class="h-9 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white">+ Add Sale</button><?php endif;?></div>
<?php if($flash):?><div class="mb-5 rounded-xl border px-4 py-3 text-[10px] font-bold <?=$flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><?=e($flash)?></div><?php endif;?>
<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4 2xl:grid-cols-9">
<?php foreach([
['Total Sales',$totalSales],
['Sales Amount','$'.number_format($revenue,2)],
['Returned Amount','$'.number_format($returnedTotal,2)],
['Net Sales','$'.number_format($netRevenue,2)],
['Balance Due','$'.number_format($due,2)],
['Paid Sales',$paidSales],
["Today's Discount",'$'.number_format($todayDiscount,2)],
["Monthly Discount",'$'.number_format($monthDiscount,2)],
['Top Discount User',$topDiscountUser ? $topDiscountUser['full_name'].' · $'.number_format((float)$topDiscountUser['discount_total'],2) : 'No discount']
] as $x):?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-[9px] font-bold uppercase text-slate-400"><?=e($x[0])?></p><p class="mt-2 text-xl font-extrabold text-blue-600"><?=e($x[1])?></p></div><?php endforeach;?></div>
<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
<div class="flex items-center justify-between border-b p-4"><div><h2 class="text-[12px] font-extrabold">Sales List</h2></div><div class="relative w-72"><i data-lucide="search" class="absolute left-3 top-2.5 h-4 w-4 text-slate-400"></i><input id="search" class="field pl-9" placeholder="Search automatically..."></div></div>
<div class="overflow-x-auto"><table class="w-full text-left text-[10px]"><thead class="bg-slate-50 text-[9px] uppercase text-slate-400"><tr><th class="px-5 py-3">Sale No</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Date</th><th class="px-5 py-3">Discount</th><th class="px-5 py-3">Discount By</th><th class="px-5 py-3">Original Total</th><th class="px-5 py-3">Returned</th><th class="px-5 py-3">Net Sale</th><th class="px-5 py-3">Paid</th><th class="px-5 py-3">Due</th><th class="px-5 py-3">Payment</th><th class="px-5 py-3">Report</th><?php if($canUpdate):?><th class="px-5 py-3 text-right">Actions</th><?php endif;?></tr></thead><tbody>
<?php foreach($sales as $s):$st=strtolower(implode(' ',[$s['sale_no'],$s['customer_name'],$s['sale_date'],$s['payment_status'],$s['total_amount'],$s['returned_amount'],max(0,(float)$s['total_amount']-(float)$s['returned_amount'])]));?>
<tr class="sale-row border-t" data-search="<?=e($st)?>"><td class="px-5 py-3.5 font-bold text-blue-600"><?=e($s['sale_no'])?></td><td class="px-5 py-3.5"><?=e($s['customer_name']?:'Walk-in Customer')?></td><td class="px-5 py-3.5"><?=e(date('d M Y H:i',strtotime($s['sale_date'])))?></td><td class="px-5 py-3.5 font-bold text-rose-600">$<?=number_format($s['discount_amount'],2)?></td><td class="px-5 py-3.5"><?=e(((float)$s['discount_amount']>0)?($s['created_by_name']?:'Unknown'):'—')?></td><td class="px-5 py-3.5 font-bold">$<?=number_format($s['total_amount'],2)?></td><td class="px-5 py-3.5 font-bold text-rose-600">$<?=number_format((float)$s['returned_amount'],2)?></td><td class="px-5 py-3.5 font-extrabold text-emerald-600">$<?=number_format(max(0,(float)$s['total_amount']-(float)$s['returned_amount']),2)?></td><td class="px-5 py-3.5">$<?=number_format($s['paid_amount'],2)?></td><td class="px-5 py-3.5">$<?=number_format($s['balance_due'],2)?></td><td class="px-5 py-3.5"><span class="rounded-full bg-blue-50 px-2 py-1 font-bold text-blue-700"><?=e($s['payment_status'])?></span></td><td class="px-5 py-3.5"><button type="button" onclick="printSaleReport(<?= (int)$s['id'] ?>)" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100" title="Print"><i data-lucide="printer" class="h-4 w-4"></i></button></td><?php if($canUpdate):?><td class="px-5 py-3.5 text-right"><button type="button" onclick="editSale(<?= (int)$s['id'] ?>)" class="rounded-lg bg-amber-50 px-3 py-2 text-[9px] font-bold text-amber-700">Edit</button></td><?php endif;?></tr>
<?php endforeach;?><tr id="none" class="hidden"><td colspan="<?= $canUpdate?13:12 ?>" class="p-10 text-center text-slate-400">No matching sales.</td></tr></tbody></table></div></div>
</main></div>

<div id="modal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm">
<div class="max-h-[96vh] w-full max-w-7xl overflow-y-auto rounded-3xl bg-slate-50 shadow-2xl">
<form method="post" id="saleForm">
<input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="sale_id" id="saleId">

<div class="sticky top-0 z-30 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur">
  <div class="flex items-center gap-3">
    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-600 text-white"><i data-lucide="shopping-cart" class="h-5 w-5"></i></div>
    <div><h3 id="saleModalTitle" class="text-sm font-extrabold text-slate-900">New Sale</h3><p class="text-[9px] text-slate-400">Create one sale for one customer. Complete it, then start the next customer's sale.</p></div>
  </div>
  <button type="button" onclick="closeSale()" class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-slate-500 hover:bg-red-50 hover:text-red-600"><i data-lucide="x" class="h-4 w-4"></i></button>
</div>

<div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_350px]">
  <section class="min-w-0 space-y-4">
    <div class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-3">
      <div class="relative">
        <label class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-400">Customer</label>
        <input type="hidden" name="customer_id" id="customerId">
        <div class="relative"><i data-lucide="user" class="absolute left-3 top-3 h-3.5 w-3.5 text-slate-400"></i><input id="customerSearch" class="field pl-9" placeholder="Search one customer / Walk-in..." autocomplete="off"></div>
        <div id="customerDrop" class="drop hidden"></div>
      </div>
      <label><span class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-400">Sale Date</span><input type="datetime-local" id="saleDate" name="sale_date" value="<?=date('Y-m-d\TH:i')?>" class="field"></label>
      <label><span class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-400">Note</span><input id="saleNote" name="note" class="field" placeholder="Optional note..."></label>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="flex items-center justify-between border-b border-slate-100 p-4">
        <div><h4 class="text-[12px] font-extrabold text-slate-900">Products</h4><p class="mt-1 text-[9px] text-slate-400">Search a product, choose unit, quantity and selling price.</p></div>
        <button type="button" onclick="addRow()" class="inline-flex h-9 items-center gap-2 rounded-xl bg-blue-50 px-3 text-[9px] font-extrabold text-blue-600 hover:bg-blue-100"><i data-lucide="plus" class="h-3.5 w-3.5"></i>Add Product</button>
      </div>
      <div class="hidden grid-cols-[2fr_1.25fr_.65fr_.85fr_.85fr_34px] gap-2 bg-slate-50 px-4 py-2 text-[8px] font-extrabold uppercase tracking-wide text-slate-400 lg:grid">
        <span>Product</span><span>Unit</span><span>Qty</span><span>Price</span><span>Total</span><span></span>
      </div>
      <div id="items" class="space-y-2 p-4"></div>
      <div id="emptyCartHint" class="px-4 pb-5 text-center text-[10px] text-slate-400">No products in cart yet.</div>
    </div>
  </section>

  <aside class="xl:sticky xl:top-24 xl:self-start">
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-lg shadow-slate-200/50">
      <div class="bg-slate-900 p-4 text-white">
        <div class="flex items-center justify-between"><div class="flex items-center gap-2"><i data-lucide="shopping-bag" class="h-4 w-4"></i><h4 class="text-[12px] font-extrabold">Customer Sale Cart</h4></div><span id="cartCount" class="rounded-full bg-blue-500 px-2.5 py-1 text-[9px] font-extrabold">0 items</span></div>
        <p class="mt-1 text-[9px] text-slate-400">Products for this customer appear here automatically.</p>
      </div>

      <div id="cartPreview" class="cart-scroll divide-y divide-slate-100">
        <div id="cartEmpty" class="p-8 text-center"><div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><i data-lucide="shopping-cart" class="h-5 w-5"></i></div><p class="mt-3 text-[10px] font-bold text-slate-500">Customer cart is empty</p><p class="mt-1 text-[9px] text-slate-400">Add a product from the left.</p></div>
      </div>

      <div class="space-y-3 border-t border-slate-100 bg-slate-50 p-4">
        <div class="grid grid-cols-2 gap-2">
          <label><span class="mb-1 block text-[8px] font-bold uppercase text-slate-400">Discount</span><input id="discount" name="discount_amount" type="number" min="0" step=".01" value="0" class="field"></label>
          <label><span class="mb-1 block text-[8px] font-bold uppercase text-slate-400">Tax</span><input id="tax" name="tax_amount" type="number" min="0" step=".01" value="0" class="field"></label>
        </div>
        <div class="space-y-2 rounded-xl bg-white p-3 text-[10px]">
          <div class="flex justify-between text-slate-500"><span>Subtotal</span><strong>$<span id="subtotalText">0.00</span></strong></div>
          <div class="flex justify-between text-rose-600"><span>Discount</span><strong>-$<span id="discountText">0.00</span></strong></div>
          <div class="flex justify-between border-t border-dashed pt-2 text-sm font-extrabold text-slate-900"><span>Total</span><span class="text-blue-600">$<span id="totalText">0.00</span></span></div>
        </div>
        <label><span class="mb-1 block text-[8px] font-bold uppercase text-slate-400">Paid Amount</span><input id="paid" name="paid_amount" type="number" min="0" step=".01" value="0" class="field" placeholder="0.00"></label>
        <div class="flex items-center justify-between rounded-xl border border-amber-100 bg-amber-50 px-3 py-2.5 text-[10px]"><span class="font-bold text-amber-700">Balance Due</span><strong class="text-amber-700">$<span id="balance">0.00</span></strong></div>
        <div class="flex items-center justify-between"><span class="text-[9px] font-bold text-slate-400">Payment Status</span><span id="payStatus" class="rounded-full bg-slate-200 px-2.5 py-1 text-[9px] font-extrabold text-slate-600">Unpaid</span></div>
        <input id="subtotal" type="hidden" value="0.00"><input id="total" type="hidden" value="0.00">
        <button type="submit" class="flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 text-[10px] font-extrabold text-white shadow-lg shadow-blue-200 transition hover:bg-blue-700"><i data-lucide="check-circle-2" class="h-4 w-4"></i><span id="completeButtonText">Complete Sale</span></button>
        <button type="button" onclick="closeSale()" class="h-9 w-full rounded-xl border border-slate-200 bg-white text-[9px] font-bold text-slate-500 hover:bg-slate-50">Cancel</button>
      </div>
    </div>
  </aside>
</div>
</form></div></div>
<script>
lucide.createIcons();
const products=<?=json_encode($products,JSON_UNESCAPED_UNICODE)?>, units=<?=json_encode($unitsByProduct,JSON_UNESCAPED_UNICODE)?>, customers=<?=json_encode($customers,JSON_UNESCAPED_UNICODE)?>;
const saleEditData=<?=json_encode($saleEditData,JSON_UNESCAPED_UNICODE)?>;
const salesData=<?=json_encode(array_column($sales,null,'id'),JSON_UNESCAPED_UNICODE)?>;
const saleReportItems=<?=json_encode($saleReportItems,JSON_UNESCAPED_UNICODE)?>;
let idx=0;

function openSale(){
  formAction.value='add';saleId.value='';saleModalTitle.textContent='New Sale';completeButtonText.textContent='Complete Sale';
  saleForm.reset();customerId.value='';customerSearch.value='';items.innerHTML='';idx=0;saleDate.value='<?=date('Y-m-d\TH:i')?>';
  const m=document.getElementById('modal');m.classList.remove('hidden');m.classList.add('flex');addRow();calc();
}
function closeSale(){const m=document.getElementById('modal');m.classList.add('hidden');m.classList.remove('flex')}

function dropdown(input,box,data,label,onpick){
  if(input.dataset.dropdownReady==='1') return;
  input.dataset.dropdownReady='1';
  function render(){
    const q=input.value.toLowerCase().trim();box.innerHTML='';
    data.filter(x=>label(x).toLowerCase().includes(q)).slice(0,80).forEach(x=>{
      const d=document.createElement('div');d.className='opt';d.textContent=label(x);
      d.onclick=()=>{input.value=label(x);box.classList.add('hidden');onpick(x)};box.appendChild(d);
    });
    box.classList.toggle('hidden',box.children.length===0);
  }
  input.addEventListener('focus',render);input.addEventListener('input',render);
  document.addEventListener('click',e=>{if(e.target!==input&&!box.contains(e.target))box.classList.add('hidden')});
}
dropdown(customerSearch,customerDrop,customers,x=>`#${x.id} - ${x.customer_name}${x.phone?' - '+x.phone:''}`,x=>customerId.value=x.id);

function addRow(prefill=null){
  const n=idx++,wrap=document.createElement('div');
  wrap.className='item grid gap-2 rounded-xl border border-slate-200 bg-white p-3 transition hover:border-blue-200 hover:shadow-sm lg:grid-cols-[2fr_1.25fr_.65fr_.85fr_.85fr_34px]';
  wrap.dataset.productName='';
  wrap.innerHTML=`<div class="relative"><input type="hidden" name="items[${n}][product_id]" class="pid"><input class="field psearch" placeholder="Search ID / product / code / barcode"><div class="drop pdrop hidden"></div><p class="mt-1 text-[8px] text-slate-400 stock">Select product</p></div>
  <div class="relative"><input type="hidden" name="items[${n}][product_unit_id]" class="uid"><input class="field usearch" placeholder="Select unit" disabled><div class="drop udrop hidden"></div><p class="mt-1 text-[8px] text-slate-400 conversion"></p></div>
  <input name="items[${n}][qty]" class="field qty" type="number" min=".001" step=".001" placeholder="Qty" required>
  <input name="items[${n}][unit_price]" class="field price" type="number" min="0" step=".01" placeholder="Price" required>
  <input class="field line font-extrabold text-blue-600" value="0.00" readonly>
  <button type="button" class="flex h-10 w-9 items-center justify-center rounded-xl bg-red-50 text-red-500 hover:bg-red-100" title="Remove" onclick="this.closest('.item').remove();calc()"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i></button>`;
  items.appendChild(wrap);lucide.createIcons();

  const ps=wrap.querySelector('.psearch'),pd=wrap.querySelector('.pdrop'),us=wrap.querySelector('.usearch'),ud=wrap.querySelector('.udrop');
  dropdown(ps,pd,products,x=>`#${x.id} - ${x.product_name}${x.product_code?' - '+x.product_code:''}${x.barcode?' - '+x.barcode:''}`,x=>{
    wrap.querySelector('.pid').value=x.id;wrap.dataset.productName=x.product_name;
    wrap.querySelector('.stock').textContent=`Available: ${Number(x.quantity).toFixed(3)} Base Units`;
    us.disabled=false;us.value='';wrap.querySelector('.uid').value='';wrap.dataset.unitName='';
    const uu=units[x.id]||[];
    dropdown(us,ud,uu,z=>`#${z.id} - ${z.unit_name} (${z.short_name}) · x${z.conversion_qty}`,z=>{
      wrap.querySelector('.uid').value=z.id;wrap.dataset.unitName=z.short_name||z.unit_name;
      wrap.querySelector('.conversion').textContent=`1 ${z.short_name||z.unit_name} = ${Number(z.conversion_qty).toFixed(3)} Base Units`;
      wrap.querySelector('.price').value=Number(z.selling_price).toFixed(2);calc();
    });
    calc();
  });
  wrap.querySelectorAll('.qty,.price').forEach(x=>x.addEventListener('input',calc));
  if(prefill){
    const product=products.find(x=>Number(x.id)===Number(prefill.product_id));
    if(product){wrap.querySelector('.pid').value=product.id;wrap.dataset.productName=product.product_name;ps.value=`#${product.id} - ${product.product_name}${product.product_code?' - '+product.product_code:''}${product.barcode?' - '+product.barcode:''}`;wrap.querySelector('.stock').textContent=`Available: ${Number(product.quantity).toFixed(3)} Base Units`;us.disabled=false;const unit=(units[product.id]||[]).find(z=>Number(z.id)===Number(prefill.product_unit_id));if(unit){wrap.querySelector('.uid').value=unit.id;wrap.dataset.unitName=unit.short_name||unit.unit_name;us.value=`#${unit.id} - ${unit.unit_name} (${unit.short_name}) · x${unit.conversion_qty}`;wrap.querySelector('.conversion').textContent=`1 ${unit.short_name||unit.unit_name} = ${Number(unit.conversion_qty).toFixed(3)} Base Units`;}}
    wrap.querySelector('.qty').value=Number(prefill.transaction_qty);wrap.querySelector('.price').value=Number(prefill.unit_price).toFixed(2);
  }
  calc();
}


function editSale(id){
  const sale=salesData[id];if(!sale)return;
  formAction.value='update';saleId.value=id;saleModalTitle.textContent=`Update Sale - ${sale.sale_no}`;completeButtonText.textContent='Update Sale';
  customerId.value=sale.customer_id||'';const c=customers.find(x=>Number(x.id)===Number(sale.customer_id));customerSearch.value=c?`#${c.id} - ${c.customer_name}${c.phone?' - '+c.phone:''}`:'';
  saleDate.value=String(sale.sale_date).replace(' ','T').slice(0,16);saleNote.value=sale.note||'';discount.value=Number(sale.discount_amount||0).toFixed(2);tax.value=Number(sale.tax_amount||0).toFixed(2);paid.value=Number(sale.paid_amount||0).toFixed(2);
  items.innerHTML='';idx=0;(saleEditData[id]||[]).forEach(x=>addRow(x));const m=document.getElementById('modal');m.classList.remove('hidden');m.classList.add('flex');calc();lucide.createIcons();
}

function updateCart(){
  const rows=[...document.querySelectorAll('.item')].filter(r=>r.querySelector('.pid').value);
  cartCount.textContent=`${rows.length} item${rows.length===1?'':'s'}`;
  emptyCartHint.classList.toggle('hidden',document.querySelectorAll('.item').length>0);
  cartPreview.innerHTML='';
  if(!rows.length){
    cartPreview.innerHTML=`<div id="cartEmpty" class="p-8 text-center"><div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><i data-lucide="shopping-cart" class="h-5 w-5"></i></div><p class="mt-3 text-[10px] font-bold text-slate-500">Customer cart is empty</p><p class="mt-1 text-[9px] text-slate-400">Add a product from the left.</p></div>`;
    lucide.createIcons();return;
  }
  rows.forEach((r,i)=>{
    const qty=+r.querySelector('.qty').value||0,price=+r.querySelector('.price').value||0,line=qty*price;
    const d=document.createElement('div');d.className='p-3';
    d.innerHTML=`<div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate text-[10px] font-extrabold text-slate-800">${escapeHtml(r.dataset.productName||'Product')}</p><p class="mt-1 text-[8px] text-slate-400">${qty.toFixed(3)} ${escapeHtml(r.dataset.unitName||'')} × $${price.toFixed(2)}</p></div><strong class="whitespace-nowrap text-[10px] text-blue-600">$${line.toFixed(2)}</strong></div>`;
    cartPreview.appendChild(d);
  });
}
function printSaleReport(id){
  const sale=salesData[id]; if(!sale)return;
  const rows=saleReportItems[id]||[];
  const customer=sale.customer_name||'Walk-in Customer';
  const itemRows=rows.map((x,i)=>{
    const qty=Number(x.sold_qty||0), price=Number(x.unit_price||0);
    return `<tr>
      <td>${i+1}</td>
      <td><b>${escapeHtml(x.product_name||'')}</b></td>
      <td>${escapeHtml(x.short_name||x.unit_name||'')}</td>
      <td class="num">${qty.toFixed(3)}</td>
      <td class="num">$${price.toFixed(2)}</td>
      <td class="num"><b>$${(qty*price).toFixed(2)}</b></td>
    </tr>`;
  }).join('');

  const subtotal=Number(sale.subtotal||0);
  const discount=Number(sale.discount_amount||0);
  const tax=Number(sale.tax_amount||0);
  const total=Number(sale.total_amount||0);
  const paid=Number(sale.paid_amount||0);
  const due=Number(sale.balance_due||0);

  const w=window.open('','_blank','width=900,height=760');
  if(!w){alert('Please allow pop-ups to print.');return;}
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(sale.sale_no)} Report</title>
  <style>
  *{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#0f172a;padding:30px;font-size:11px}
  .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0f172a;padding-bottom:15px;margin-bottom:18px}
  h1{font-size:21px;margin:0}.muted{color:#64748b;font-size:10px}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
  .box{border:1px solid #e2e8f0;border-radius:11px;padding:11px}.label{font-size:8px;color:#94a3b8;text-transform:uppercase;font-weight:700}
  .value{font-size:12px;font-weight:800;margin-top:5px}table{width:100%;border-collapse:collapse;margin-top:12px}
  th{background:#f8fafc;color:#64748b;text-transform:uppercase;font-size:8px;padding:9px;border-bottom:2px solid #cbd5e1;text-align:left}
  td{padding:10px 9px;border-bottom:1px solid #e2e8f0}.num{text-align:right}.summary{width:330px;margin:20px 0 0 auto}
  .sum{display:flex;justify-content:space-between;padding:7px 2px;border-bottom:1px dashed #cbd5e1}.discount{color:#dc2626;font-weight:800}
  .total{font-size:16px;font-weight:900;border-top:2px solid #0f172a;border-bottom:0;margin-top:4px}.due{color:#b45309;font-weight:800}
  .footer{text-align:center;margin-top:30px;color:#94a3b8;font-size:9px}@media print{body{padding:8mm}.print-btn{display:none}}
  </style></head><body>
  <div class="head"><div><h1><?=e($businessName)?></h1><div class="muted">CUSTOMER SALES REPORT</div></div>
  <div style="text-align:right"><b>${escapeHtml(sale.sale_no)}</b><div class="muted">${escapeHtml(String(sale.sale_date||''))}</div></div></div>

  <div class="meta">
    <div class="box"><div class="label">Customer</div><div class="value">${escapeHtml(customer)}</div></div>
    <div class="box"><div class="label">Payment Status</div><div class="value">${escapeHtml(sale.payment_status||'')}</div></div>
    <div class="box"><div class="label">Sold By</div><div class="value">${escapeHtml(sale.created_by_name||'')}</div></div>
  </div>

  <div style="font-size:12px;font-weight:900;margin-bottom:5px">Products Taken by Customer</div>
  <table><thead><tr><th>#</th><th>Product</th><th>Unit</th><th class="num">Quantity</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
  <tbody>${itemRows||'<tr><td colspan="6">No products found.</td></tr>'}</tbody></table>

  <div class="summary">
    <div class="sum"><span>Subtotal</span><b>$${subtotal.toFixed(2)}</b></div>
    <div class="sum discount"><span>Discount</span><span>-$${discount.toFixed(2)}</span></div>
    ${tax>0?`<div class="sum"><span>Tax</span><b>$${tax.toFixed(2)}</b></div>`:''}
    <div class="sum total"><span>Total After Discount</span><span>$${total.toFixed(2)}</span></div>
    <div class="sum"><span>Paid</span><b>$${paid.toFixed(2)}</b></div>
    <div class="sum due"><span>Balance Due</span><span>$${due.toFixed(2)}</span></div>
  </div>

  <div class="footer">Printed from <?=e($businessName)?></div>
  </body></html>`);
  w.document.close();
  w.focus();
  setTimeout(()=>w.print(),250);
}
function escapeHtml(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML}

function calc(){
  let sub=0;
  document.querySelectorAll('.item').forEach(r=>{
    const q=+r.querySelector('.qty').value||0,p=+r.querySelector('.price').value||0,l=q*p;
    r.querySelector('.line').value=l.toFixed(2);sub+=l;
  });
  subtotal.value=sub.toFixed(2);subtotalText.textContent=sub.toFixed(2);discountText.textContent=(+discount.value||0).toFixed(2);
  const t=Math.max(0,sub-(+discount.value||0)+(+tax.value||0));
  total.value=t.toFixed(2);totalText.textContent=t.toFixed(2);
  const pa=+paid.value||0,b=Math.max(0,t-pa);balance.textContent=b.toFixed(2);
  const status=b<.005?'Paid':pa>0?'Partial':'Unpaid';payStatus.textContent=status;
  payStatus.className='rounded-full px-2.5 py-1 text-[9px] font-extrabold '+(status==='Paid'?'bg-emerald-100 text-emerald-700':status==='Partial'?'bg-amber-100 text-amber-700':'bg-slate-200 text-slate-600');
  updateCart();
}
[discount,tax,paid].forEach(x=>x.addEventListener('input',calc));
search.addEventListener('input',function(){let q=this.value.toLowerCase(),v=0;document.querySelectorAll('.sale-row').forEach(r=>{let ok=r.dataset.search.includes(q);r.classList.toggle('hidden',!ok);if(ok)v++});none.classList.toggle('hidden',v!==0)});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeSale()});
</script></body></html>