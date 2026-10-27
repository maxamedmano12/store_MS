<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';
requireBusinessUser();
requirePermission('expenses.view');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId   = (int)($_SESSION['branch_id'] ?? 0);
$userId     = (int)($_SESSION['user_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Business';

$qUser=$conn->prepare("SELECT full_name FROM users WHERE id=? AND business_id=? LIMIT 1");
$qUser->execute([$userId,$businessId]);
$currentUserName=(string)($qUser->fetchColumn() ?: ($_SESSION['full_name'] ?? 'User'));

$canAdd    = hasPermission('expenses.add');
$canUpdate = hasPermission('expenses.update');
$canDelete = hasPermission('expenses.delete');

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function flashExp($m,$t='success'){ $_SESSION['expense_flash']=['m'=>$m,'t'=>$t]; }
function expenseNo(PDO $conn,int $businessId): string {
    $prefix='EXP-'.date('Ymd').'-';
    $q=$conn->prepare("SELECT expense_no FROM expenses WHERE business_id=? AND expense_no LIKE ? ORDER BY id DESC LIMIT 1");
    $q->execute([$businessId,$prefix.'%']);
    $last=(string)$q->fetchColumn();
    $n=$last ? ((int)substr($last,-4)+1) : 1;
    return $prefix.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}

if(empty($_SESSION['expense_csrf'])) $_SESSION['expense_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['expense_csrf'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf_token']??''))) throw new RuntimeException('Invalid request token.');
        $action=(string)($_POST['action']??'');

        if($action==='add_category'){
            if(!$canAdd) throw new RuntimeException('Access denied.');
            $name=trim((string)($_POST['category_name']??''));
            $desc=trim((string)($_POST['category_description']??''));
            if($name==='') throw new RuntimeException('Category name is required.');
            $q=$conn->prepare("SELECT id FROM expense_categories WHERE business_id=? AND category_name=? LIMIT 1");
            $q->execute([$businessId,$name]);
            if($q->fetch()) throw new RuntimeException('Expense category already exists.');
            $q=$conn->prepare("INSERT INTO expense_categories(business_id,category_name,description,status) VALUES(?,?,?,'Active')");
            $q->execute([$businessId,$name,$desc?:null]);
            flashExp('Expense category added successfully.');
        }
        elseif(in_array($action,['add','update'],true)){
            if($action==='add'&&!$canAdd) throw new RuntimeException('Access denied.');
            if($action==='update'&&!$canUpdate) throw new RuntimeException('Access denied.');

            $id=(int)($_POST['expense_id']??0);
            $cat=(int)($_POST['expense_category_id']??0);
            $date=trim((string)($_POST['expense_date']??''));
            $amount=(float)($_POST['amount']??0);
            $method=(string)($_POST['payment_method']??'Cash');
            $payee=$currentUserName;
            $ref=trim((string)($_POST['reference_no']??''));
            $desc=trim((string)($_POST['description']??''));

            if($cat<=0) throw new RuntimeException('Select expense category.');
            if($amount<=0) throw new RuntimeException('Amount must be greater than zero.');
            if(!in_array($method,['Cash','EVC','Bank','Other'],true)) throw new RuntimeException('Invalid payment method.');
            $q=$conn->prepare("SELECT id FROM expense_categories WHERE id=? AND business_id=? AND status='Active'");
            $q->execute([$cat,$businessId]);
            if(!$q->fetch()) throw new RuntimeException('Expense category not found.');
            $dt=date('Y-m-d H:i:s',strtotime($date?:'now'));

            if($action==='add'){
                $no=expenseNo($conn,$businessId);
                $q=$conn->prepare("INSERT INTO expenses(business_id,branch_id,expense_category_id,expense_no,expense_date,amount,payment_method,payee,reference_no,description,status,created_by)
                VALUES(?,?,?,?,?,?,?,?,?,?,'Completed',?)");
                $q->execute([$businessId,$branchId,$cat,$no,$dt,$amount,$method,$payee?:null,$ref?:null,$desc?:null,$userId]);
                $eid=(int)$conn->lastInsertId();
                try{if(function_exists('logActivity'))logActivity('CREATE','Expenses',$eid,'Created expense '.$no);}catch(Throwable $x){}
                flashExp('Expense '.$no.' added successfully.');
            }else{
                $q=$conn->prepare("SELECT expense_no FROM expenses WHERE id=? AND business_id=? AND branch_id=? AND status='Completed'");
                $q->execute([$id,$businessId,$branchId]);$no=$q->fetchColumn();
                if(!$no) throw new RuntimeException('Expense not found.');
                $q=$conn->prepare("UPDATE expenses SET expense_category_id=?,expense_date=?,amount=?,payment_method=?,payee=?,reference_no=?,description=? WHERE id=? AND business_id=? AND branch_id=?");
                $q->execute([$cat,$dt,$amount,$method,$payee?:null,$ref?:null,$desc?:null,$id,$businessId,$branchId]);
                try{if(function_exists('logActivity'))logActivity('UPDATE','Expenses',$id,'Updated expense '.$no);}catch(Throwable $x){}
                flashExp('Expense '.$no.' updated successfully.');
            }
        }
        elseif($action==='cancel'){
            if(!$canDelete) throw new RuntimeException('Access denied.');
            $id=(int)($_POST['expense_id']??0);
            $q=$conn->prepare("UPDATE expenses SET status='Cancelled' WHERE id=? AND business_id=? AND branch_id=? AND status='Completed'");
            $q->execute([$id,$businessId,$branchId]);
            flashExp('Expense cancelled successfully.');
        }
    }catch(Throwable $ex){ flashExp($ex->getMessage(),'error'); }
    header('Location: expenses.php');exit;
}

$q=$conn->prepare("SELECT id,category_name,description FROM expense_categories WHERE business_id=? AND status='Active' ORDER BY category_name");
$q->execute([$businessId]);$categories=$q->fetchAll(PDO::FETCH_ASSOC);

$q=$conn->prepare("SELECT e.*,ec.category_name,u.full_name created_by_name
FROM expenses e
JOIN expense_categories ec ON ec.id=e.expense_category_id
LEFT JOIN users u ON u.id=e.created_by
WHERE e.business_id=? AND e.branch_id=?
ORDER BY e.id DESC LIMIT 500");
$q->execute([$businessId,$branchId]);$expenses=$q->fetchAll(PDO::FETCH_ASSOC);

/* Expense summary: calculate directly from DB so Today/Month are accurate
   even when the list is limited to the latest 500 rows. */
$summary=$conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status='Completed' THEN amount ELSE 0 END),0) AS total_expenses,
        COALESCE(SUM(CASE WHEN status='Completed' AND DATE(expense_date)=CURDATE() THEN amount ELSE 0 END),0) AS today_expenses,
        COALESCE(SUM(CASE WHEN status='Completed'
                          AND YEAR(expense_date)=YEAR(CURDATE())
                          AND MONTH(expense_date)=MONTH(CURDATE())
                     THEN amount ELSE 0 END),0) AS month_expenses,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS completed_count
    FROM expenses
    WHERE business_id=? AND branch_id=?
");
$summary->execute([$businessId,$branchId]);
$summaryRow=$summary->fetch(PDO::FETCH_ASSOC) ?: [];
$total=(float)($summaryRow['total_expenses']??0);
$todayExpenseAmount=(float)($summaryRow['today_expenses']??0);
$monthExpenseAmount=(float)($summaryRow['month_expenses']??0);
$completed=(int)($summaryRow['completed_count']??0);
$flash=$_SESSION['expense_flash']??null;unset($_SESSION['expense_flash']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Expenses - <?=e($businessName)?></title>
<script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script>
</head><body class="bg-slate-50 text-slate-700">
<?php include 'includes/sidebar.php'; ?>
<div class="min-h-screen lg:pl-64">
<?php include 'includes/header.php'; ?>
<main class="p-4 md:p-6">
<div class="mx-auto max-w-7xl">
<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
<div><h1 class="text-xl font-black text-slate-900">Expenses</h1><p class="text-xs text-slate-500">Manage branch operating expenses.</p></div>
<div class="flex gap-2">
<?php if($canAdd):?><button onclick="openCategory()" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-[10px] font-extrabold"><i data-lucide="folder-plus" class="mr-1 inline h-4 w-4"></i> Category</button>
<button onclick="openExpense()" class="rounded-xl bg-blue-600 px-4 py-2.5 text-[10px] font-extrabold text-white"><i data-lucide="plus" class="mr-1 inline h-4 w-4"></i> Add Expense</button><?php endif;?>
</div></div>

<?php if($flash):?><div class="mb-4 rounded-xl border px-4 py-3 text-xs font-bold <?=$flash['t']==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><?=e($flash['m'])?></div><?php endif;?>

<div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
<?php foreach([['Total Expenses','$'.number_format($total,2),'wallet-cards'],["Today's Expenses",'$'.number_format($todayExpenseAmount,2),'calendar-days'],['Monthly Expenses','$'.number_format($monthExpenseAmount,2),'calendar-range'],['Completed',$completed,'circle-check']] as $c):?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-[9px] font-bold uppercase text-slate-400"><?=e($c[0])?></p><p class="mt-1 text-lg font-black text-slate-900"><?=e($c[1])?></p></div><i data-lucide="<?=e($c[2])?>" class="h-5 w-5 text-blue-600"></i></div></div>
<?php endforeach;?></div>

<div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
<div class="border-b border-slate-100 p-4"><div class="relative max-w-md"><i data-lucide="search" class="absolute left-3 top-2.5 h-4 w-4 text-slate-400"></i><input id="expenseSearch" oninput="filterRows()" placeholder="Search ID, expense no, category, payee, payment..." class="h-9 w-full rounded-xl border border-slate-200 pl-9 pr-3 text-xs outline-none focus:border-blue-500"></div></div>
<div class="overflow-x-auto"><table class="w-full text-left text-[10px]"><thead class="bg-slate-50 uppercase text-slate-400"><tr>
<th class="px-5 py-3">ID</th><th class="px-5 py-3">Expense No</th><th class="px-5 py-3">Date</th><th class="px-5 py-3">Category</th><th class="px-5 py-3">Payee</th><th class="px-5 py-3">Payment</th><th class="px-5 py-3">Amount</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">By User</th><th class="px-5 py-3 text-right">Actions</th>
</tr></thead><tbody id="expenseRows">
<?php foreach($expenses as $x):?><tr class="expense-row border-t border-slate-100" data-search="<?=e(strtolower($x['id'].' '.$x['expense_no'].' '.$x['category_name'].' '.$x['payee'].' '.$x['payment_method'].' '.$x['reference_no'].' '.$x['description'].' '.$x['status']))?>">
<td class="px-5 py-3.5 font-bold">#<?=e($x['id'])?></td><td class="px-5 py-3.5 font-bold text-blue-700"><?=e($x['expense_no'])?></td><td class="px-5 py-3.5"><?=e(date('d M Y H:i',strtotime($x['expense_date'])))?></td><td class="px-5 py-3.5"><?=e($x['category_name'])?></td><td class="px-5 py-3.5"><?=e($x['payee']?:'—')?></td><td class="px-5 py-3.5"><?=e($x['payment_method'])?></td><td class="px-5 py-3.5 font-black">$<?=number_format($x['amount'],2)?></td>
<td class="px-5 py-3.5"><span class="rounded-full px-2 py-1 font-bold <?=$x['status']==='Completed'?'bg-emerald-50 text-emerald-700':'bg-red-50 text-red-700'?>"><?=e($x['status'])?></span></td><td class="px-5 py-3.5"><?=e($x['created_by_name']?:'—')?></td>
<td class="px-5 py-3.5 text-right"><div class="inline-flex gap-1">
<?php if($canUpdate&&$x['status']==='Completed'):?><button onclick='editExpense(<?=json_encode($x,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-700" title="Update"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button><?php endif;?>
<button onclick='printExpense(<?=json_encode($x,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-700" title="Print"><i data-lucide="printer" class="h-3.5 w-3.5"></i></button>
<?php if($canDelete&&$x['status']==='Completed'):?><button onclick="askCancel(<?= (int)$x['id']?>,'<?=e($x['expense_no'])?>')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-700" title="Cancel"><i data-lucide="x-circle" class="h-3.5 w-3.5"></i></button><?php endif;?>
</div></td></tr><?php endforeach;?>
<?php if(!$expenses):?><tr><td colspan="10" class="p-10 text-center text-slate-400">No expenses found.</td></tr><?php endif;?>
</tbody></table></div></div>
</div></main></div>

<div id="expenseModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
<div class="flex items-center justify-between border-b p-4"><div><h3 id="modalTitle" class="text-sm font-black text-slate-900">Add Expense</h3><p class="text-[9px] text-slate-400">Expense information</p></div><button onclick="closeExpense()"><i data-lucide="x" class="h-5 w-5"></i></button></div>
<form method="post" id="expenseForm" class="p-5"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="expense_id" id="expenseId">
<div class="grid gap-4 md:grid-cols-2">
<div class="relative"><label class="mb-1 block text-[9px] font-bold uppercase text-slate-500">Category *</label><input type="hidden" name="expense_category_id" id="categoryId"><input id="categorySearch" autocomplete="off" placeholder="Search category by ID or name..." class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs outline-none focus:border-blue-500" onfocus="showCats()" oninput="showCats()"><div id="categoryList" class="absolute z-20 mt-1 hidden max-h-48 w-full overflow-auto rounded-xl border bg-white p-1 shadow-xl"></div></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase text-slate-500">Expense Date *</label><input type="datetime-local" name="expense_date" id="expenseDate" required class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase text-slate-500">Amount *</label><input type="number" step=".01" min=".01" name="amount" id="amount" required class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase text-slate-500">Payment Method *</label><select name="payment_method" id="paymentMethod" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs"><option>Cash</option><option>zaad</option><option>Edahab</option><option>Other</option></select></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase text-slate-500">Payee / User</label><input name="payee" id="payee" value="<?=e($currentUserName)?>" readonly class="h-10 w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-bold text-slate-700" title="Current logged-in user"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase text-slate-500">Reference No</label><input name="reference_no" id="referenceNo" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs" placeholder="Receipt / transaction no"></div>
<div class="md:col-span-2"><label class="mb-1 block text-[9px] font-bold uppercase text-slate-500">Description</label><textarea name="description" id="description" rows="3" class="w-full rounded-xl border border-slate-200 p-3 text-xs" placeholder="Expense details..."></textarea></div>
</div><div class="mt-5 flex justify-end gap-2"><button type="button" onclick="closeExpense()" class="rounded-xl border px-4 py-2 text-[10px] font-bold">Cancel</button><button id="saveBtn" class="rounded-xl bg-blue-600 px-5 py-2 text-[10px] font-extrabold text-white">Save Expense</button></div></form></div></div>

<div id="categoryModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-md rounded-2xl bg-white shadow-2xl"><div class="flex justify-between border-b p-4"><h3 class="text-sm font-black">Add Expense Category</h3><button onclick="closeCategory()"><i data-lucide="x" class="h-5 w-5"></i></button></div><form method="post" class="p-5"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" value="add_category"><label class="mb-1 block text-[9px] font-bold uppercase">Category Name *</label><input name="category_name" required class="mb-3 h-10 w-full rounded-xl border px-3 text-xs"><label class="mb-1 block text-[9px] font-bold uppercase">Description</label><textarea name="category_description" class="w-full rounded-xl border p-3 text-xs"></textarea><div class="mt-4 text-right"><button class="rounded-xl bg-blue-600 px-5 py-2.5 text-[10px] font-bold text-white">Save Category</button></div></form></div></div>

<div id="cancelModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl"><h3 class="font-black text-slate-900">Cancel Expense?</h3><p id="cancelText" class="mt-2 text-xs text-slate-500"></p><form method="post" class="mt-5 flex justify-end gap-2"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="expense_id" id="cancelId"><button type="button" onclick="cancelModal.classList.add('hidden')" class="rounded-xl border px-4 py-2 text-xs">No</button><button class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white">Yes, Cancel</button></form></div></div>

<script>
lucide.createIcons();
const categories=<?=json_encode($categories,JSON_UNESCAPED_UNICODE)?>;
function filterRows(){const q=expenseSearch.value.toLowerCase().trim();document.querySelectorAll('.expense-row').forEach(r=>r.style.display=r.dataset.search.includes(q)?'':'none')}
function localDT(v=new Date()){const d=new Date(v),z=n=>String(n).padStart(2,'0');return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}T${z(d.getHours())}:${z(d.getMinutes())}`}
function openExpense(){expenseForm.reset();formAction.value='add';expenseId.value='';modalTitle.textContent='Add Expense';saveBtn.textContent='Save Expense';categoryId.value='';categorySearch.value='';payee.value=<?=json_encode($currentUserName,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)?>;expenseDate.value=localDT();expenseModal.classList.remove('hidden');expenseModal.classList.add('flex')}
function closeExpense(){expenseModal.classList.add('hidden');expenseModal.classList.remove('flex')}
function editExpense(x){expenseForm.reset();formAction.value='update';expenseId.value=x.id;modalTitle.textContent='Update Expense - '+x.expense_no;saveBtn.textContent='Save Changes';categoryId.value=x.expense_category_id;const c=categories.find(v=>Number(v.id)===Number(x.expense_category_id));categorySearch.value=c?`#${c.id} - ${c.category_name}`:'';expenseDate.value=String(x.expense_date).replace(' ','T').substring(0,16);amount.value=x.amount;paymentMethod.value=x.payment_method;payee.value=<?=json_encode($currentUserName,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)?>;referenceNo.value=x.reference_no||'';description.value=x.description||'';expenseModal.classList.remove('hidden');expenseModal.classList.add('flex')}
function showCats(){const q=categorySearch.value.toLowerCase().trim();const a=categories.filter(c=>('#'+c.id+' '+c.category_name+' '+(c.description||'')).toLowerCase().includes(q));categoryList.innerHTML=a.map(c=>`<button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-xs hover:bg-blue-50" onclick="pickCat(${c.id})"><b>#${c.id}</b> - ${esc(c.category_name)}</button>`).join('')||'<div class="p-3 text-xs text-slate-400">No category found.</div>';categoryList.classList.remove('hidden')}
function pickCat(id){const c=categories.find(v=>Number(v.id)===Number(id));if(!c)return;categoryId.value=c.id;categorySearch.value=`#${c.id} - ${c.category_name}`;categoryList.classList.add('hidden')}
function openCategory(){categoryModal.classList.remove('hidden');categoryModal.classList.add('flex')}function closeCategory(){categoryModal.classList.add('hidden');categoryModal.classList.remove('flex')}
function askCancel(id,no){cancelId.value=id;cancelText.textContent='Expense '+no+' will be marked Cancelled. History will remain.';cancelModal.classList.remove('hidden');cancelModal.classList.add('flex')}
function printExpense(x){const w=window.open('','_blank','width=700,height=700');if(!w){alert('Allow pop-ups to print.');return}w.document.write(`<!doctype html><html><head><title>${esc(x.expense_no)}</title><style>body{font-family:Arial;padding:35px;color:#0f172a}.head{display:flex;justify-content:space-between;border-bottom:2px solid #0f172a;padding-bottom:15px}.box{margin-top:20px;border:1px solid #ddd;border-radius:12px;padding:15px}.row{display:flex;justify-content:space-between;padding:9px;border-bottom:1px solid #eee}.amount{font-size:22px;font-weight:bold}</style></head><body><div class="head"><div><h2 style="margin:0"><?=e($businessName)?></h2><small>EXPENSE RECEIPT</small></div><div><b>${esc(x.expense_no)}</b><br><small>${esc(x.expense_date)}</small></div></div><div class="box"><div class="row"><span>Category</span><b>${esc(x.category_name)}</b></div><div class="row"><span>Payee</span><b>${esc(x.payee||'—')}</b></div><div class="row"><span>Payment Method</span><b>${esc(x.payment_method)}</b></div><div class="row"><span>Reference</span><b>${esc(x.reference_no||'—')}</b></div><div class="row"><span>Description</span><b>${esc(x.description||'—')}</b></div><div class="row amount"><span>Amount</span><span>$${Number(x.amount).toFixed(2)}</span></div></div><script>window.onload=()=>window.print()<\/script></body></html>`);w.document.close()}
function esc(s){const d=document.createElement('div');d.textContent=s??'';return d.innerHTML}
document.addEventListener('click',e=>{if(!categoryList.contains(e.target)&&e.target!==categorySearch)categoryList.classList.add('hidden')});
</script></body></html>
