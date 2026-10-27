<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('customers.view');

$businessId=(int)($_SESSION['business_id']??0);
$userId=(int)($_SESSION['user_id']??0);
$businessName=(string)($_SESSION['business_name']??'Business');

$canAdd=hasPermission('customers.add');
$canUpdate=hasPermission('customers.update');
$canDelete=hasPermission('customers.delete');

if($businessId<=0||$userId<=0){http_response_code(403);exit('Business/user not available.');}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function flashCustomer($m,$t='success'){$_SESSION['customer_flash']=$m;$_SESSION['customer_flash_type']=$t;}
if(empty($_SESSION['customer_csrf']))$_SESSION['customer_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['customer_csrf'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($_SESSION['customer_csrf'],(string)($_POST['csrf_token']??''))){
        flashCustomer('Invalid security token.','error');header('Location: customers.php');exit;
    }
    $action=(string)($_POST['action']??'');
    try{
        if($action==='add'){
            if(!$canAdd)throw new RuntimeException('You do not have permission to add customers.');
            $name=trim((string)($_POST['customer_name']??''));
            $phone=trim((string)($_POST['phone']??''));
            $email=trim((string)($_POST['email']??''));
            $address=trim((string)($_POST['address']??''));
            $opening=max(0,(float)($_POST['opening_balance']??0));
            $status=in_array($_POST['status']??'', ['Active','Inactive'],true)?$_POST['status']:'Active';
            if($name==='')throw new RuntimeException('Customer Name is required.');
            if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Email address is invalid.');
            $q=$conn->prepare("SELECT id FROM customers WHERE business_id=? AND customer_name=? LIMIT 1");
            $q->execute([$businessId,$name]);if($q->fetch())throw new RuntimeException('Customer name already exists.');
            $q=$conn->prepare("INSERT INTO customers(business_id,customer_name,phone,email,address,opening_balance,status) VALUES(?,?,?,?,?,?,?)");
            $q->execute([$businessId,$name,$phone?:null,$email?:null,$address?:null,$opening,$status]);
            $id=(int)$conn->lastInsertId();
            try{if(function_exists('logActivity'))logActivity('CREATE','Customers',$id,'Created customer '.$name);}catch(Throwable $ignore){}
            flashCustomer('Customer added successfully.');
        }elseif($action==='update'){
            if(!$canUpdate)throw new RuntimeException('You do not have permission to update customers.');
            $id=(int)($_POST['customer_id']??0);
            $name=trim((string)($_POST['customer_name']??''));
            $phone=trim((string)($_POST['phone']??''));
            $email=trim((string)($_POST['email']??''));
            $address=trim((string)($_POST['address']??''));
            $opening=max(0,(float)($_POST['opening_balance']??0));
            $status=in_array($_POST['status']??'', ['Active','Inactive'],true)?$_POST['status']:'Active';
            if($id<=0||$name==='')throw new RuntimeException('Invalid customer.');
            if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Email address is invalid.');
            $q=$conn->prepare("SELECT id FROM customers WHERE business_id=? AND customer_name=? AND id<>? LIMIT 1");
            $q->execute([$businessId,$name,$id]);if($q->fetch())throw new RuntimeException('Customer name already exists.');
            $q=$conn->prepare("UPDATE customers SET customer_name=?,phone=?,email=?,address=?,opening_balance=?,status=? WHERE id=? AND business_id=?");
            $q->execute([$name,$phone?:null,$email?:null,$address?:null,$opening,$status,$id,$businessId]);
            if(!$q->rowCount()){
                $check=$conn->prepare("SELECT id FROM customers WHERE id=? AND business_id=?");$check->execute([$id,$businessId]);
                if(!$check->fetch())throw new RuntimeException('Customer not found.');
            }
            try{if(function_exists('logActivity'))logActivity('UPDATE','Customers',$id,'Updated customer '.$name);}catch(Throwable $ignore){}
            flashCustomer('Customer updated successfully.');
        }elseif($action==='toggle'){
            if(!$canUpdate)throw new RuntimeException('You do not have permission to update customers.');
            $id=(int)($_POST['customer_id']??0);
            $q=$conn->prepare("SELECT customer_name,status FROM customers WHERE id=? AND business_id=?");
            $q->execute([$id,$businessId]);$c=$q->fetch(PDO::FETCH_ASSOC);
            if(!$c)throw new RuntimeException('Customer not found.');
            $new=$c['status']==='Active'?'Inactive':'Active';
            $conn->prepare("UPDATE customers SET status=? WHERE id=? AND business_id=?")->execute([$new,$id,$businessId]);
            flashCustomer('Customer status changed to '.$new.'.');
        }elseif($action==='delete'){
            if(!$canDelete)throw new RuntimeException('You do not have permission to delete customers.');
            $id=(int)($_POST['customer_id']??0);
            $q=$conn->prepare("SELECT customer_name FROM customers WHERE id=? AND business_id=?");
            $q->execute([$id,$businessId]);$name=$q->fetchColumn();
            if(!$name)throw new RuntimeException('Customer not found.');
            $q=$conn->prepare("SELECT COUNT(*) FROM sales WHERE business_id=? AND customer_id=?");
            $q->execute([$businessId,$id]);
            if((int)$q->fetchColumn()>0)throw new RuntimeException('This customer has sales history. Set the customer Inactive instead of deleting.');
            $conn->prepare("DELETE FROM customers WHERE id=? AND business_id=?")->execute([$id,$businessId]);
            try{if(function_exists('logActivity'))logActivity('DELETE','Customers',$id,'Deleted customer '.$name);}catch(Throwable $ignore){}
            flashCustomer('Customer deleted successfully.');
        }
    }catch(Throwable $ex){flashCustomer($ex->getMessage(),'error');}
    header('Location: customers.php');exit;
}

/* Customer balances:
   opening_balance = amount customer already owed when the system started.
   Current debt = opening + completed sale balances - completed sales-return balance adjustments. */
$q=$conn->prepare("
SELECT c.*,
 COALESCE((SELECT COUNT(*) FROM sales s WHERE s.business_id=c.business_id AND s.customer_id=c.id AND s.status='Completed'),0) sales_count,
 COALESCE((SELECT SUM(s.total_amount) FROM sales s WHERE s.business_id=c.business_id AND s.customer_id=c.id AND s.status='Completed'),0) sales_total,
 COALESCE((SELECT SUM(s.balance_due) FROM sales s WHERE s.business_id=c.business_id AND s.customer_id=c.id AND s.status='Completed'),0) sales_due,
 COALESCE((SELECT SUM(sr.total_amount) FROM sales_returns sr WHERE sr.business_id=c.business_id AND sr.customer_id=c.id AND sr.status='Completed'),0) returned_total,
 COALESCE((SELECT SUM(sr.balance_adjustment) FROM sales_returns sr WHERE sr.business_id=c.business_id AND sr.customer_id=c.id AND sr.status='Completed'),0) return_balance_adjustment
FROM customers c
WHERE c.business_id=?
ORDER BY c.id DESC");
$q->execute([$businessId]);$customers=$q->fetchAll(PDO::FETCH_ASSOC);

$totalCustomers=count($customers);$activeCustomers=0;$totalDebt=0;$totalSales=0;
foreach($customers as &$c){
    if($c['status']==='Active')$activeCustomers++;
    $c['current_debt']=max(0,(float)$c['opening_balance']+(float)$c['sales_due']-(float)$c['return_balance_adjustment']);
    $c['net_sales']=max(0,(float)$c['sales_total']-(float)$c['returned_total']);
    $totalDebt+=(float)$c['current_debt'];$totalSales+=(float)$c['net_sales'];
}unset($c);

$flash=$_SESSION['customer_flash']??null;$flashType=$_SESSION['customer_flash_type']??'success';
unset($_SESSION['customer_flash'],$_SESSION['customer_flash_type']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Customers - <?=e($businessName)?></title>
<script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script>
<style>
.field{height:40px;width:100%;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:0 12px;font-size:10px;outline:none;transition:.15s}.field:focus{border-color:#3b82f6;background:#fff;box-shadow:0 0 0 3px #dbeafe}
</style></head><body class="min-h-screen bg-slate-50 text-slate-700">
<?php require_once __DIR__.'/includes/sidebar.php';?>
<div class="min-h-screen lg:pl-64"><?php require_once __DIR__.'/includes/header.php';?>
<main class="p-4 sm:p-5 lg:p-6">
<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
<div><h1 class="text-lg font-extrabold text-slate-900">Customers</h1><p class="mt-1 text-[10px] text-slate-400">Manage customers, opening balances, sales and outstanding debt.</p></div>
<?php if($canAdd):?><button onclick="openAdd()" class="inline-flex h-9 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white shadow-sm hover:bg-blue-700"><i data-lucide="user-plus" class="h-4 w-4"></i>Add Customer</button><?php endif;?>
</div>
<?php if($flash):?><div class="mb-5 rounded-xl border px-4 py-3 text-[10px] font-bold <?=$flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><?=e($flash)?></div><?php endif;?>
<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
<?php foreach([['Total Customers',$totalCustomers],['Active Customers',$activeCustomers],['Net Sales','$'.number_format($totalSales,2)],['Customer Debt','$'.number_format($totalDebt,2)]] as $x):?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-[9px] font-bold uppercase text-slate-400"><?=e($x[0])?></p><p class="mt-2 text-xl font-extrabold text-blue-600"><?=e($x[1])?></p></div><?php endforeach;?>
</div>
<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
<div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-[12px] font-extrabold text-slate-900">Customer List</h2><p class="text-[9px] text-slate-400">Search by ID, name, phone, email, address or status.</p></div>
<div class="relative w-full sm:w-80"><i data-lucide="search" class="absolute left-3 top-2.5 h-4 w-4 text-slate-400"></i><input id="search" class="field pl-9" placeholder="Search automatically..."></div></div>
<div class="overflow-x-auto"><table class="w-full text-left text-[10px]"><thead class="bg-slate-50 text-[9px] uppercase text-slate-400"><tr>
<th class="px-5 py-3">ID</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Contact</th><th class="px-5 py-3">Opening</th><th class="px-5 py-3">Sales</th><th class="px-5 py-3">Returned</th><th class="px-5 py-3">Current Debt</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Actions</th>
</tr></thead><tbody>
<?php foreach($customers as $c):$st=strtolower(implode(' ',[$c['id'],$c['customer_name'],$c['phone'],$c['email'],$c['address'],$c['status']]));?>
<tr class="customer-row border-t border-slate-100 hover:bg-slate-50/70" data-search="<?=e($st)?>">
<td class="px-5 py-3.5 font-bold text-slate-400">#<?=e($c['id'])?></td>
<td class="px-5 py-3.5"><p class="font-extrabold text-slate-800"><?=e($c['customer_name'])?></p><p class="mt-0.5 max-w-[220px] truncate text-[8px] text-slate-400"><?=e($c['address']?:'No address')?></p></td>
<td class="px-5 py-3.5"><p><?=e($c['phone']?:'—')?></p><p class="text-[8px] text-slate-400"><?=e($c['email']?:'—')?></p></td>
<td class="px-5 py-3.5">$<?=number_format($c['opening_balance'],2)?></td>
<td class="px-5 py-3.5"><p class="font-bold">$<?=number_format($c['net_sales'],2)?></p><p class="text-[8px] text-slate-400"><?=number_format($c['sales_count'])?> sales</p></td>
<td class="px-5 py-3.5 font-bold text-rose-600">$<?=number_format($c['returned_total'],2)?></td>
<td class="px-5 py-3.5 font-extrabold <?=$c['current_debt']>0?'text-amber-600':'text-emerald-600'?>">$<?=number_format($c['current_debt'],2)?></td>
<td class="px-5 py-3.5"><span class="rounded-full px-2 py-1 text-[8px] font-extrabold <?=$c['status']==='Active'?'bg-emerald-50 text-emerald-700':'bg-slate-100 text-slate-500'?>"><?=e($c['status'])?></span></td>
<td class="px-5 py-3.5"><div class="flex justify-end gap-1">
<?php if($canUpdate):?><button type="button" onclick='editCustomer(<?=json_encode($c,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600" title="Edit"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="customer_id" value="<?=$c['id']?>"><button class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600" title="Toggle Status"><i data-lucide="power" class="h-3.5 w-3.5"></i></button></form><?php endif;?>
<?php if($canDelete):?><button type="button" onclick="askDelete(<?=$c['id']?>,<?=json_encode($c['customer_name'])?>)" class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600" title="Delete"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i></button><?php endif;?>
</div></td></tr><?php endforeach;?>
<tr id="none" class="hidden"><td colspan="9" class="p-10 text-center text-slate-400">No matching customers.</td></tr>
</tbody></table></div></div>
</main></div>

<div id="modal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm">
<div class="w-full max-w-xl overflow-hidden rounded-3xl bg-white shadow-2xl"><form method="post" id="customerForm">
<input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="customer_id" id="customerId">
<div class="flex items-center justify-between border-b p-5"><div><h3 id="modalTitle" class="text-sm font-extrabold text-slate-900">Add Customer</h3><p class="mt-1 text-[9px] text-slate-400">Customer information and opening debt balance.</p></div><button type="button" onclick="closeModal()" class="h-9 w-9 rounded-xl bg-slate-100 text-lg">×</button></div>
<div class="grid gap-4 p-5 sm:grid-cols-2">
<label class="sm:col-span-2"><span class="mb-1 block text-[9px] font-bold uppercase text-slate-400">Customer Name *</span><input id="customerName" name="customer_name" class="field" required></label>
<label><span class="mb-1 block text-[9px] font-bold uppercase text-slate-400">Phone</span><input id="phone" name="phone" class="field"></label>
<label><span class="mb-1 block text-[9px] font-bold uppercase text-slate-400">Email</span><input id="email" name="email" type="email" class="field"></label>
<label class="sm:col-span-2"><span class="mb-1 block text-[9px] font-bold uppercase text-slate-400">Address</span><input id="address" name="address" class="field"></label>
<label><span class="mb-1 block text-[9px] font-bold uppercase text-slate-400">Opening Balance</span><input id="openingBalance" name="opening_balance" type="number" min="0" step=".01" value="0" class="field"><span class="mt-1 block text-[8px] text-slate-400">Amount this customer already owes the business.</span></label>
<label><span class="mb-1 block text-[9px] font-bold uppercase text-slate-400">Status</span><select id="status" name="status" class="field"><option>Active</option><option>Inactive</option></select></label>
</div><div class="flex justify-end gap-2 border-t bg-slate-50 p-4"><button type="button" onclick="closeModal()" class="h-9 rounded-xl border bg-white px-4 text-[10px] font-bold">Cancel</button><button class="h-9 rounded-xl bg-blue-600 px-5 text-[10px] font-extrabold text-white">Save Customer</button></div>
</form></div></div>

<div id="deleteModal" class="fixed inset-0 z-[110] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm"><div class="w-full max-w-sm rounded-3xl bg-white p-6 text-center shadow-2xl"><div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-rose-50 text-rose-600"><i data-lucide="trash-2"></i></div><h3 class="font-extrabold text-slate-900">Delete Customer?</h3><p id="deleteText" class="mt-2 text-[10px] text-slate-500"></p><div class="mt-5 flex justify-center gap-2"><button onclick="closeDelete()" class="h-9 rounded-xl border px-4 text-[10px] font-bold">Cancel</button><form method="post"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="customer_id" id="deleteId"><button class="h-9 rounded-xl bg-rose-600 px-4 text-[10px] font-bold text-white">Delete</button></form></div></div></div>

<script>
lucide.createIcons();
function openAdd(){customerForm.reset();formAction.value='add';customerId.value='';modalTitle.textContent='Add Customer';openingBalance.value='0';status.value='Active';modal.classList.remove('hidden');modal.classList.add('flex')}
function editCustomer(c){customerForm.reset();formAction.value='update';customerId.value=c.id;modalTitle.textContent='Edit Customer - '+c.customer_name;customerName.value=c.customer_name||'';phone.value=c.phone||'';email.value=c.email||'';address.value=c.address||'';openingBalance.value=Number(c.opening_balance||0).toFixed(2);status.value=c.status||'Active';modal.classList.remove('hidden');modal.classList.add('flex')}
function closeModal(){modal.classList.add('hidden');modal.classList.remove('flex')}
function askDelete(id,name){deleteId.value=id;deleteText.textContent='Delete '+name+'? Customers with sales history cannot be deleted.';deleteModal.classList.remove('hidden');deleteModal.classList.add('flex')}
function closeDelete(){deleteModal.classList.add('hidden');deleteModal.classList.remove('flex')}
search.addEventListener('input',function(){let q=this.value.toLowerCase().trim(),v=0;document.querySelectorAll('.customer-row').forEach(r=>{let ok=r.dataset.search.includes(q);r.classList.toggle('hidden',!ok);if(ok)v++});none.classList.toggle('hidden',v!==0)});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal();closeDelete()}});
</script></body></html>
