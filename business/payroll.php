<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';
requireBusinessUser();
requirePermission('payroll.view');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??0);
$userId=(int)($_SESSION['user_id']??0);
$businessName=$_SESSION['business_name']??'Business';

$canAdd=hasPermission('payroll.add');
$canUpdate=hasPermission('payroll.update');
$canDelete=hasPermission('payroll.delete');

function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function flashPay($m,$t='success'){$_SESSION['payroll_flash']=['m'=>$m,'t'=>$t];}
function payrollNo(PDO $conn,int $businessId):string{
    $prefix='PAY-'.date('Ymd').'-';
    $q=$conn->prepare("SELECT payroll_no FROM payrolls WHERE business_id=? AND payroll_no LIKE ? ORDER BY id DESC LIMIT 1");
    $q->execute([$businessId,$prefix.'%']); $last=(string)$q->fetchColumn();
    return $prefix.str_pad((string)($last?((int)substr($last,-4)+1):1),4,'0',STR_PAD_LEFT);
}
function monthDate($v):string{
    $t=strtotime($v?:date('Y-m-01'));
    return date('Y-m-01',$t?:time());
}

if(empty($_SESSION['payroll_csrf'])) $_SESSION['payroll_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['payroll_csrf'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf_token']??''))) throw new RuntimeException('Invalid request token.');
        $action=(string)($_POST['action']??'');

        if(in_array($action,['add_employee','update_employee'],true)){
            if($action==='add_employee'&&!$canAdd) throw new RuntimeException('Access denied.');
            if($action==='update_employee'&&!$canUpdate) throw new RuntimeException('Access denied.');
            $eid=(int)($_POST['employee_id']??0);
            $name=trim((string)($_POST['full_name']??''));
            $phone=trim((string)($_POST['phone']??''));
            $job=trim((string)($_POST['job_title']??''));
            $hire=trim((string)($_POST['hire_date']??''));
            $basic=(float)($_POST['basic_salary']??0);
            if($name==='') throw new RuntimeException('Employee name is required.');
            if($basic<0) throw new RuntimeException('Basic salary cannot be negative.');
            if($action==='add_employee'){
                $q=$conn->prepare("SELECT COALESCE(MAX(id),0)+1 FROM employees WHERE business_id=?");$q->execute([$businessId]);
                $eno='EMP-'.str_pad((string)((int)$q->fetchColumn()),4,'0',STR_PAD_LEFT);
                $q=$conn->prepare("INSERT INTO employees(business_id,branch_id,employee_no,full_name,phone,job_title,hire_date,basic_salary,status) VALUES(?,?,?,?,?,?,?,?,'Active')");
                $q->execute([$businessId,$branchId,$eno,$name,$phone?:null,$job?:null,$hire?:null,$basic]);
                flashPay('Employee '.$eno.' added successfully.');
            }else{
                $q=$conn->prepare("UPDATE employees SET full_name=?,phone=?,job_title=?,hire_date=?,basic_salary=? WHERE id=? AND business_id=? AND branch_id=?");
                $q->execute([$name,$phone?:null,$job?:null,$hire?:null,$basic,$eid,$businessId,$branchId]);
                if(!$q->rowCount()) {
                    $chk=$conn->prepare("SELECT id FROM employees WHERE id=? AND business_id=? AND branch_id=?");$chk->execute([$eid,$businessId,$branchId]);
                    if(!$chk->fetch()) throw new RuntimeException('Employee not found.');
                }
                flashPay('Employee updated successfully.');
            }
        }
        elseif($action==='toggle_employee'){
            if(!$canUpdate) throw new RuntimeException('Access denied.');
            $eid=(int)($_POST['employee_id']??0);
            $q=$conn->prepare("SELECT status FROM employees WHERE id=? AND business_id=? AND branch_id=?");
            $q->execute([$eid,$businessId,$branchId]); $st=$q->fetchColumn();
            if($st===false) throw new RuntimeException('Employee not found.');
            $newStatus=$st==='Active'?'Inactive':'Active';
            $q=$conn->prepare("UPDATE employees SET status=? WHERE id=? AND business_id=? AND branch_id=?");
            $q->execute([$newStatus,$eid,$businessId,$branchId]);
            flashPay('Employee status changed to '.$newStatus.'.');
        }
        elseif($action==='delete_employee'){
            if(!$canDelete) throw new RuntimeException('Access denied.');
            $eid=(int)($_POST['employee_id']??0);
            $q=$conn->prepare("SELECT employee_no FROM employees WHERE id=? AND business_id=? AND branch_id=?");
            $q->execute([$eid,$businessId,$branchId]); $eno=$q->fetchColumn();
            if(!$eno) throw new RuntimeException('Employee not found.');
            $q=$conn->prepare("SELECT COUNT(*) FROM payrolls WHERE employee_id=? AND business_id=?");
            $q->execute([$eid,$businessId]);
            if((int)$q->fetchColumn()>0) throw new RuntimeException('Employee has payroll history. Set employee to Inactive instead.');
            $q=$conn->prepare("DELETE FROM employees WHERE id=? AND business_id=? AND branch_id=?");
            $q->execute([$eid,$businessId,$branchId]);
            flashPay('Employee '.$eno.' deleted successfully.');
        }
        elseif(in_array($action,['add','update'],true)){
            if($action==='add'&&!$canAdd) throw new RuntimeException('Access denied.');
            if($action==='update'&&!$canUpdate) throw new RuntimeException('Access denied.');
            $id=(int)($_POST['payroll_id']??0);
            $emp=(int)($_POST['employee_id']??0);
            $month=monthDate((string)($_POST['payroll_month']??''));
            $allow=max(0,(float)($_POST['allowance_amount']??0));
            $bonus=max(0,(float)($_POST['bonus_amount']??0));
            $overtime=max(0,(float)($_POST['overtime_amount']??0));
            $deduction=max(0,(float)($_POST['deduction_amount']??0));
            $advance=max(0,(float)($_POST['advance_amount']??0));
            $method=(string)($_POST['payment_method']??'Cash');
            $status=(string)($_POST['status']??'Pending');
            $note=trim((string)($_POST['note']??''));
            if(!in_array($method,['Cash','Zaad','Edahab'],true)) throw new RuntimeException('Invalid payment method.');
            if(!in_array($status,['Pending','Paid'],true)) throw new RuntimeException('Invalid payroll status.');
            $q=$conn->prepare("SELECT basic_salary,full_name FROM employees WHERE id=? AND business_id=? AND branch_id=? AND status='Active'");
            $q->execute([$emp,$businessId,$branchId]);$er=$q->fetch(PDO::FETCH_ASSOC);
            if(!$er) throw new RuntimeException('Employee not found.');
            $basic=(float)$er['basic_salary'];
            $net=$basic+$allow+$bonus+$overtime-$deduction-$advance;
            if($net<0) throw new RuntimeException('Net salary cannot be negative.');
            $paidAt=$status==='Paid'?date('Y-m-d H:i:s'):null;

            if($action==='add'){
                $no=payrollNo($conn,$businessId);
                $q=$conn->prepare("INSERT INTO payrolls(business_id,branch_id,employee_id,payroll_no,payroll_month,basic_salary,allowance_amount,bonus_amount,overtime_amount,deduction_amount,advance_amount,net_salary,payment_method,status,paid_at,note,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $q->execute([$businessId,$branchId,$emp,$no,$month,$basic,$allow,$bonus,$overtime,$deduction,$advance,$net,$method,$status,$paidAt,$note?:null,$userId]);
                $id=(int)$conn->lastInsertId();
                try{if(function_exists('logActivity'))logActivity('CREATE','Payroll',$id,'Created payroll '.$no);}catch(Throwable $x){}
                flashPay('Payroll '.$no.' added successfully.');
            }else{
                $q=$conn->prepare("SELECT payroll_no,status FROM payrolls WHERE id=? AND business_id=? AND branch_id=?");
                $q->execute([$id,$businessId,$branchId]);$old=$q->fetch(PDO::FETCH_ASSOC);
                if(!$old) throw new RuntimeException('Payroll not found.');
                if($old['status']==='Cancelled') throw new RuntimeException('Cancelled payroll cannot be updated.');
                $q=$conn->prepare("UPDATE payrolls SET employee_id=?,payroll_month=?,basic_salary=?,allowance_amount=?,bonus_amount=?,overtime_amount=?,deduction_amount=?,advance_amount=?,net_salary=?,payment_method=?,status=?,paid_at=?,note=? WHERE id=? AND business_id=? AND branch_id=?");
                $q->execute([$emp,$month,$basic,$allow,$bonus,$overtime,$deduction,$advance,$net,$method,$status,$paidAt,$note?:null,$id,$businessId,$branchId]);
                try{if(function_exists('logActivity'))logActivity('UPDATE','Payroll',$id,'Updated payroll '.$old['payroll_no']);}catch(Throwable $x){}
                flashPay('Payroll '.$old['payroll_no'].' updated successfully.');
            }
        }
        elseif($action==='cancel'){
            if(!$canDelete) throw new RuntimeException('Access denied.');
            $id=(int)($_POST['payroll_id']??0);
            $q=$conn->prepare("UPDATE payrolls SET status='Cancelled' WHERE id=? AND business_id=? AND branch_id=? AND status<>'Cancelled'");
            $q->execute([$id,$businessId,$branchId]);
            flashPay('Payroll cancelled successfully.');
        }
    }catch(PDOException $ex){
        if($ex->getCode()==='23000') flashPay('This employee already has payroll for the selected month.','error');
        else flashPay($ex->getMessage(),'error');
    }catch(Throwable $ex){flashPay($ex->getMessage(),'error');}
    header('Location: payroll.php');exit;
}

$q=$conn->prepare("SELECT id,employee_no,full_name,phone,job_title,hire_date,basic_salary,status FROM employees WHERE business_id=? AND branch_id=? ORDER BY full_name");
$q->execute([$businessId,$branchId]);$employees=$q->fetchAll(PDO::FETCH_ASSOC);

$q=$conn->prepare("SELECT p.*,e.employee_no,e.full_name,e.job_title,u.full_name created_by_name FROM payrolls p JOIN employees e ON e.id=p.employee_id LEFT JOIN users u ON u.id=p.created_by WHERE p.business_id=? AND p.branch_id=? ORDER BY p.payroll_month DESC,p.id DESC LIMIT 500");
$q->execute([$businessId,$branchId]);$payrolls=$q->fetchAll(PDO::FETCH_ASSOC);

$q=$conn->prepare("SELECT COALESCE(SUM(CASE WHEN status='Paid' THEN net_salary ELSE 0 END),0) paid_total,COALESCE(SUM(CASE WHEN status='Pending' THEN net_salary ELSE 0 END),0) pending_total,COALESCE(SUM(CASE WHEN status='Paid' AND YEAR(payroll_month)=YEAR(CURDATE()) AND MONTH(payroll_month)=MONTH(CURDATE()) THEN net_salary ELSE 0 END),0) month_total,SUM(CASE WHEN status<>'Cancelled' THEN 1 ELSE 0 END) payroll_count FROM payrolls WHERE business_id=? AND branch_id=?");
$q->execute([$businessId,$branchId]);$sum=$q->fetch(PDO::FETCH_ASSOC)?:[];
$flash=$_SESSION['payroll_flash']??null;unset($_SESSION['payroll_flash']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payroll - <?=e($businessName)?></title><script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script></head>
<body class="bg-slate-50 text-slate-700"><?php include 'includes/sidebar.php'; ?><div class="min-h-screen lg:pl-64"><?php include 'includes/header.php'; ?>
<main class="p-4 md:p-6"><div class="mx-auto max-w-7xl">
<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h1 class="text-xl font-black text-slate-900">Payroll</h1><p class="text-xs text-slate-500">Manage employees and branch payroll.</p></div>
<?php if($canAdd):?><div class="flex gap-2"><button onclick="openEmployee()" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-[10px] font-extrabold"><i data-lucide="user-plus" class="mr-1 inline h-4 w-4"></i> Employee</button><button onclick="openPayroll()" class="rounded-xl bg-blue-600 px-4 py-2.5 text-[10px] font-extrabold text-white"><i data-lucide="plus" class="mr-1 inline h-4 w-4"></i> Add Payroll</button></div><?php endif;?></div>
<?php if($flash):?><div class="mb-4 rounded-xl border px-4 py-3 text-xs font-bold <?=$flash['t']==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><?=e($flash['m'])?></div><?php endif;?>
<div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><?php foreach([['Total Paid','$'.number_format((float)($sum['paid_total']??0),2),'circle-check'],['Pending','$'.number_format((float)($sum['pending_total']??0),2),'clock'],['This Month','$'.number_format((float)($sum['month_total']??0),2),'calendar-range'],['Payroll Records',(int)($sum['payroll_count']??0),'banknote']] as $c):?><div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-[9px] font-bold uppercase text-slate-400"><?=e($c[0])?></p><p class="mt-1 text-lg font-black text-slate-900"><?=e($c[1])?></p></div><i data-lucide="<?=e($c[2])?>" class="h-5 w-5 text-blue-600"></i></div></div><?php endforeach;?></div>

<div class="rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-100 p-4"><div class="relative max-w-md"><i data-lucide="search" class="absolute left-3 top-2.5 h-4 w-4 text-slate-400"></i><input id="paySearch" oninput="filterRows()" placeholder="Search ID, payroll no, employee, month, status..." class="h-9 w-full rounded-xl border border-slate-200 pl-9 pr-3 text-xs outline-none focus:border-blue-500"></div></div>
<div class="overflow-x-auto"><table class="w-full text-left text-[10px]"><thead class="bg-slate-50 uppercase text-slate-400"><tr><th class="px-5 py-3">ID</th><th class="px-5 py-3">Payroll No</th><th class="px-5 py-3">Month</th><th class="px-5 py-3">Employee</th><th class="px-5 py-3">Basic</th><th class="px-5 py-3">Additions</th><th class="px-5 py-3">Deductions</th><th class="px-5 py-3">Net Salary</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody>
<?php foreach($payrolls as $x): $adds=(float)$x['allowance_amount']+(float)$x['bonus_amount']+(float)$x['overtime_amount'];$subs=(float)$x['deduction_amount']+(float)$x['advance_amount'];?><tr class="pay-row border-t border-slate-100" data-search="<?=e(strtolower($x['id'].' '.$x['payroll_no'].' '.$x['employee_no'].' '.$x['full_name'].' '.$x['job_title'].' '.$x['payroll_month'].' '.$x['status'].' '.$x['payment_method']))?>"><td class="px-5 py-3.5 font-bold">#<?=e($x['id'])?></td><td class="px-5 py-3.5 font-bold text-blue-700"><?=e($x['payroll_no'])?></td><td class="px-5 py-3.5"><?=e(date('M Y',strtotime($x['payroll_month'])))?></td><td class="px-5 py-3.5"><b><?=e($x['full_name'])?></b><br><span class="text-slate-400"><?=e($x['employee_no'])?></span></td><td class="px-5 py-3.5">$<?=number_format($x['basic_salary'],2)?></td><td class="px-5 py-3.5 text-emerald-700">+$<?=number_format($adds,2)?></td><td class="px-5 py-3.5 text-red-600">-$<?=number_format($subs,2)?></td><td class="px-5 py-3.5 font-black">$<?=number_format($x['net_salary'],2)?></td><td class="px-5 py-3.5"><span class="rounded-full px-2 py-1 font-bold <?=$x['status']==='Paid'?'bg-emerald-50 text-emerald-700':($x['status']==='Pending'?'bg-amber-50 text-amber-700':'bg-red-50 text-red-700')?>"><?=e($x['status'])?></span></td><td class="px-5 py-3.5 text-right"><div class="inline-flex gap-1"><?php if($canUpdate&&$x['status']!=='Cancelled'):?><button onclick='editPayroll(<?=json_encode($x,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-700"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button><?php endif;?><button onclick='printPayroll(<?=json_encode($x,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-700"><i data-lucide="printer" class="h-3.5 w-3.5"></i></button><?php if($canDelete&&$x['status']!=='Cancelled'):?><button onclick="askCancel(<?=(int)$x['id']?>,'<?=e($x['payroll_no'])?>')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-700"><i data-lucide="x-circle" class="h-3.5 w-3.5"></i></button><?php endif;?></div></td></tr><?php endforeach;?>
<?php if(!$payrolls):?><tr><td colspan="10" class="p-10 text-center text-slate-400">No payroll records found.</td></tr><?php endif;?></tbody></table></div></div>
</div></main></div>

<div id="payModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b p-4"><div><h3 id="payTitle" class="text-sm font-black">Add Payroll</h3><p class="text-[9px] text-slate-400">Salary calculation</p></div><button onclick="closePayroll()"><i data-lucide="x" class="h-5 w-5"></i></button></div>
<form method="post" id="payForm" class="p-5"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" id="payAction" value="add"><input type="hidden" name="payroll_id" id="payId"><input type="hidden" name="employee_id" id="empId">
<div class="grid gap-4 md:grid-cols-3"><div class="relative md:col-span-2"><label class="mb-1 block text-[9px] font-bold uppercase">Employee *</label><input id="empSearch" autocomplete="off" placeholder="Search employee by ID, no or name..." onfocus="showEmployees()" oninput="showEmployees()" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs"><div id="empList" class="absolute z-20 mt-1 hidden max-h-52 w-full overflow-auto rounded-xl border bg-white p-1 shadow-xl"></div></div><div><label class="mb-1 block text-[9px] font-bold uppercase">Payroll Month *</label><input type="month" name="payroll_month" id="payMonth" required class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Basic Salary</label><input id="basicSalary" readonly class="h-10 w-full rounded-xl border bg-slate-50 px-3 text-xs font-bold"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Allowance</label><input type="number" step=".01" min="0" name="allowance_amount" id="allowance" value="0" oninput="calcNet()" class="h-10 w-full rounded-xl border px-3 text-xs"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Bonus</label><input type="number" step=".01" min="0" name="bonus_amount" id="bonus" value="0" oninput="calcNet()" class="h-10 w-full rounded-xl border px-3 text-xs"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Overtime</label><input type="number" step=".01" min="0" name="overtime_amount" id="overtime" value="0" oninput="calcNet()" class="h-10 w-full rounded-xl border px-3 text-xs"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Deduction</label><input type="number" step=".01" min="0" name="deduction_amount" id="deduction" value="0" oninput="calcNet()" class="h-10 w-full rounded-xl border px-3 text-xs"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Advance</label><input type="number" step=".01" min="0" name="advance_amount" id="advance" value="0" oninput="calcNet()" class="h-10 w-full rounded-xl border px-3 text-xs"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Net Salary</label><input id="netSalary" readonly class="h-10 w-full rounded-xl border bg-blue-50 px-3 text-xs font-black text-blue-700"></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Payment Method</label><select name="payment_method" id="payMethod" class="h-10 w-full rounded-xl border px-3 text-xs"><option>Cash</option><option>Zaad</option><option>Edahab</option></select></div>
<div><label class="mb-1 block text-[9px] font-bold uppercase">Status</label><select name="status" id="payStatus" class="h-10 w-full rounded-xl border px-3 text-xs"><option>Pending</option><option>Paid</option></select></div>
<div class="md:col-span-3"><label class="mb-1 block text-[9px] font-bold uppercase">Note</label><textarea name="note" id="payNote" rows="2" class="w-full rounded-xl border p-3 text-xs"></textarea></div></div>
<div class="mt-5 flex justify-end gap-2"><button type="button" onclick="closePayroll()" class="rounded-xl border px-4 py-2 text-[10px] font-bold">Cancel</button><button id="paySave" class="rounded-xl bg-blue-600 px-5 py-2 text-[10px] font-extrabold text-white">Save Payroll</button></div></form></div></div>

<div id="employeeModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-4xl rounded-2xl bg-white shadow-2xl">
<div class="flex items-center justify-between border-b p-4"><div><h3 class="text-sm font-black">Manage Employees</h3><p class="text-[9px] text-slate-400">Add, update, activate/inactivate or delete employees.</p></div><button onclick="closeEmployee()"><i data-lucide="x" class="h-5 w-5"></i></button></div>
<div class="grid gap-4 p-5 lg:grid-cols-2">
<div><div class="relative mb-3"><i data-lucide="search" class="absolute left-3 top-2.5 h-4 w-4 text-slate-400"></i><input id="employeeManageSearch" oninput="filterEmployeeRows()" placeholder="Search employee..." class="h-9 w-full rounded-xl border border-slate-200 pl-9 pr-3 text-xs"></div>
<div class="max-h-[430px] overflow-auto rounded-xl border"><table class="w-full text-left text-[10px]"><thead class="sticky top-0 bg-slate-50 uppercase text-slate-400"><tr><th class="px-3 py-2">Employee</th><th class="px-3 py-2">Salary</th><th class="px-3 py-2">Status</th><th class="px-3 py-2 text-right">Actions</th></tr></thead><tbody>
<?php foreach($employees as $em):?><tr class="employee-manage-row border-t" data-search="<?=e(strtolower($em['id'].' '.$em['employee_no'].' '.$em['full_name'].' '.$em['phone'].' '.$em['job_title'].' '.$em['status']))?>"><td class="px-3 py-3"><b><?=e($em['full_name'])?></b><br><span class="text-slate-400"><?=e($em['employee_no'])?> · <?=e($em['job_title']?:'—')?></span></td><td class="px-3 py-3 font-bold">$<?=number_format($em['basic_salary'],2)?></td><td class="px-3 py-3"><?=e($em['status'])?></td><td class="px-3 py-3 text-right"><div class="inline-flex gap-1">
<?php if($canUpdate):?><button type="button" onclick='editEmployee(<?=json_encode($em,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-700" title="Update"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button><form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" value="toggle_employee"><input type="hidden" name="employee_id" value="<?=(int)$em['id']?>"><button class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600" title="Active / Inactive"><i data-lucide="power" class="h-3.5 w-3.5"></i></button></form><?php endif;?>
<?php if($canDelete):?><button type="button" onclick="askDeleteEmployee(<?=(int)$em['id']?>,'<?=e($em['employee_no'])?>','<?=e($em['full_name'])?>')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-700" title="Delete"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i></button><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="rounded-xl border p-4"><div class="mb-4 flex justify-between"><h4 id="employeeFormTitle" class="text-xs font-black">Add Employee</h4><button type="button" onclick="resetEmployeeForm()" class="rounded-lg border px-3 py-1.5 text-[9px] font-bold">New</button></div>
<form method="post" id="employeeForm"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" id="employeeAction" value="add_employee"><input type="hidden" name="employee_id" id="employeeId"><div class="grid gap-3 md:grid-cols-2">
<div class="md:col-span-2"><label class="text-[9px] font-bold uppercase">Full Name *</label><input name="full_name" id="employeeName" required class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div><div><label class="text-[9px] font-bold uppercase">Phone</label><input name="phone" id="employeePhone" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div><div><label class="text-[9px] font-bold uppercase">Job Title</label><input name="job_title" id="employeeJob" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div><div><label class="text-[9px] font-bold uppercase">Hire Date</label><input type="date" name="hire_date" id="employeeHire" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div><div><label class="text-[9px] font-bold uppercase">Basic Salary *</label><input type="number" step=".01" min="0" name="basic_salary" id="employeeSalary" required class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div></div><div class="mt-5 text-right"><button id="employeeSaveBtn" class="rounded-xl bg-blue-600 px-5 py-2.5 text-[10px] font-bold text-white">Save Employee</button></div></form></div></div></div></div>
<div id="deleteEmployeeModal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl"><h3 class="font-black">Delete Employee?</h3><p id="deleteEmployeeText" class="mt-2 text-xs text-slate-500"></p><form method="post" class="mt-5 flex justify-end gap-2"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" value="delete_employee"><input type="hidden" name="employee_id" id="deleteEmployeeId"><button type="button" onclick="deleteEmployeeModal.classList.add('hidden')" class="rounded-xl border px-4 py-2 text-xs">No</button><button class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white">Yes, Delete</button></form></div></div>

<div id="cancelModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl"><h3 class="font-black">Cancel Payroll?</h3><p id="cancelText" class="mt-2 text-xs text-slate-500"></p><form method="post" class="mt-5 flex justify-end gap-2"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="payroll_id" id="cancelId"><button type="button" onclick="cancelModal.classList.add('hidden')" class="rounded-xl border px-4 py-2 text-xs">No</button><button class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white">Yes, Cancel</button></form></div></div>

<script>
lucide.createIcons();const employees=<?=json_encode($employees,JSON_UNESCAPED_UNICODE)?>;
const money=v=>Number(v||0);
function filterRows(){const q=paySearch.value.toLowerCase().trim();document.querySelectorAll('.pay-row').forEach(r=>r.style.display=r.dataset.search.includes(q)?'':'none')}
function showEmployees(){const q=empSearch.value.toLowerCase().trim();const a=employees.filter(x=>x.status==='Active'&&('#'+x.id+' '+x.employee_no+' '+x.full_name+' '+(x.phone||'')+' '+(x.job_title||'')).toLowerCase().includes(q));empList.innerHTML=a.map(x=>`<button type="button" onclick="pickEmployee(${x.id})" class="block w-full rounded-lg px-3 py-2 text-left text-xs hover:bg-blue-50"><b>#${x.id} ${esc(x.employee_no)}</b> - ${esc(x.full_name)} <span class="text-slate-400">${esc(x.job_title||'')}</span></button>`).join('')||'<div class="p-3 text-xs text-slate-400">No employee found.</div>';empList.classList.remove('hidden')}
function pickEmployee(id){const x=employees.find(v=>Number(v.id)===Number(id));if(!x)return;empId.value=x.id;empSearch.value=`#${x.id} - ${x.employee_no} - ${x.full_name}`;basicSalary.value=Number(x.basic_salary).toFixed(2);empList.classList.add('hidden');calcNet()}
function calcNet(){netSalary.value=(money(basicSalary.value)+money(allowance.value)+money(bonus.value)+money(overtime.value)-money(deduction.value)-money(advance.value)).toFixed(2)}
function openPayroll(){payForm.reset();payAction.value='add';payId.value='';empId.value='';empSearch.value='';basicSalary.value='0.00';allowance.value=bonus.value=overtime.value=deduction.value=advance.value=0;payMonth.value=new Date().toISOString().slice(0,7);payTitle.textContent='Add Payroll';paySave.textContent='Save Payroll';calcNet();payModal.classList.remove('hidden');payModal.classList.add('flex')}
function closePayroll(){payModal.classList.add('hidden');payModal.classList.remove('flex')}
function editPayroll(x){payForm.reset();payAction.value='update';payId.value=x.id;empId.value=x.employee_id;const e=employees.find(v=>Number(v.id)===Number(x.employee_id));empSearch.value=e?`#${e.id} - ${e.employee_no} - ${e.full_name}`:x.full_name;basicSalary.value=Number(x.basic_salary).toFixed(2);payMonth.value=String(x.payroll_month).slice(0,7);allowance.value=x.allowance_amount;bonus.value=x.bonus_amount;overtime.value=x.overtime_amount;deduction.value=x.deduction_amount;advance.value=x.advance_amount;payMethod.value=x.payment_method;payStatus.value=x.status;payNote.value=x.note||'';payTitle.textContent='Update Payroll - '+x.payroll_no;paySave.textContent='Save Changes';calcNet();payModal.classList.remove('hidden');payModal.classList.add('flex')}
function openEmployee(){resetEmployeeForm();employeeModal.classList.remove('hidden');employeeModal.classList.add('flex');lucide.createIcons()}
function closeEmployee(){employeeModal.classList.add('hidden');employeeModal.classList.remove('flex')}
function resetEmployeeForm(){employeeForm.reset();employeeAction.value='add_employee';employeeId.value='';employeeFormTitle.textContent='Add Employee';employeeSaveBtn.textContent='Save Employee'}
function editEmployee(x){employeeAction.value='update_employee';employeeId.value=x.id;employeeName.value=x.full_name||'';employeePhone.value=x.phone||'';employeeJob.value=x.job_title||'';employeeHire.value=x.hire_date||'';employeeSalary.value=x.basic_salary||0;employeeFormTitle.textContent='Update Employee - '+x.employee_no;employeeSaveBtn.textContent='Save Changes'}
function filterEmployeeRows(){const q=employeeManageSearch.value.toLowerCase().trim();document.querySelectorAll('.employee-manage-row').forEach(r=>r.style.display=r.dataset.search.includes(q)?'':'none')}
function askDeleteEmployee(id,no,name){deleteEmployeeId.value=id;deleteEmployeeText.textContent=no+' - '+name+' will be deleted if there is no payroll history.';deleteEmployeeModal.classList.remove('hidden');deleteEmployeeModal.classList.add('flex')}
function askCancel(id,no){cancelId.value=id;cancelText.textContent='Payroll '+no+' will be marked Cancelled.';cancelModal.classList.remove('hidden');cancelModal.classList.add('flex')}
function printPayroll(x){const w=window.open('','_blank','width=760,height=720');if(!w)return;const adds=money(x.allowance_amount)+money(x.bonus_amount)+money(x.overtime_amount),subs=money(x.deduction_amount)+money(x.advance_amount);w.document.write(`<!doctype html><html><head><title>${esc(x.payroll_no)}</title><style>body{font-family:Arial;padding:35px;color:#0f172a}.head{display:flex;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:15px}.box{margin-top:20px;border:1px solid #ddd;border-radius:12px;padding:15px}.row{display:flex;justify-content:space-between;padding:9px;border-bottom:1px solid #eee}.net{font-size:20px;font-weight:bold}</style></head><body><div class="head"><div><h2><?=e($businessName)?></h2><small>PAYROLL SLIP</small></div><b>${esc(x.payroll_no)}</b></div><div class="box"><div class="row"><span>Employee</span><b>${esc(x.full_name)}</b></div><div class="row"><span>Month</span><b>${esc(x.payroll_month)}</b></div><div class="row"><span>Basic Salary</span><b>$${money(x.basic_salary).toFixed(2)}</b></div><div class="row"><span>Allowance + Bonus + Overtime</span><b>+$${adds.toFixed(2)}</b></div><div class="row"><span>Deduction + Advance</span><b>-$${subs.toFixed(2)}</b></div><div class="row net"><span>Net Salary</span><span>$${money(x.net_salary).toFixed(2)}</span></div><div class="row"><span>Payment / Status</span><b>${esc(x.payment_method)} / ${esc(x.status)}</b></div></div><script>window.onload=()=>window.print()<\/script></body></html>`);w.document.close()}
function esc(s){const d=document.createElement('div');d.textContent=s??'';return d.innerHTML}
document.addEventListener('click',e=>{if(!empList.contains(e.target)&&e.target!==empSearch)empList.classList.add('hidden')});
</script></body></html>
