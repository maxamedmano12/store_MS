<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('suppliers.view');

$businessId = (int)($_SESSION['business_id'] ?? 0);
$businessName = (string)($_SESSION['business_name'] ?? 'Business');

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business not available.');
}

$canAdd = hasPermission('suppliers.add');
$canUpdate = hasPermission('suppliers.update');
$canDelete = hasPermission('suppliers.delete');

function supplierE($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function supplierFlash(string $message, string $type='success'): void {
    $_SESSION['supplier_flash'] = $message;
    $_SESSION['supplier_flash_type'] = $type;
}

if (empty($_SESSION['supplier_csrf'])) {
    $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['supplier_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['supplier_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        supplierFlash('Security token is invalid.', 'error');
        header('Location: suppliers.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add') {
            if (!$canAdd) { http_response_code(403); exit('Access denied.'); }

            $name = trim((string)($_POST['supplier_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $opening = max(0, (float)($_POST['opening_balance'] ?? 0));
            $status = (string)($_POST['status'] ?? 'Active');

            if ($name === '') throw new RuntimeException('Supplier name is required.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email address is invalid.');
            if (!in_array($status, ['Active','Inactive'], true)) $status = 'Active';

            $s = $conn->prepare("SELECT id FROM suppliers WHERE business_id=? AND supplier_name=? LIMIT 1");
            $s->execute([$businessId,$name]);
            if ($s->fetch()) throw new RuntimeException('This supplier already exists.');

            $s = $conn->prepare("INSERT INTO suppliers
                (business_id,supplier_name,phone,email,address,opening_balance,status)
                VALUES(?,?,?,?,?,?,?)");
            $s->execute([$businessId,$name,$phone ?: null,$email ?: null,$address ?: null,$opening,$status]);
            $id = (int)$conn->lastInsertId();

            try { if (function_exists('logActivity')) logActivity('CREATE','Suppliers',$id,'Created supplier: '.$name); } catch (Throwable $ignore) {}
            supplierFlash('Supplier added successfully.');
        } elseif ($action === 'update') {
            if (!$canUpdate) { http_response_code(403); exit('Access denied.'); }

            $id = (int)($_POST['supplier_id'] ?? 0);
            $name = trim((string)($_POST['supplier_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $opening = max(0, (float)($_POST['opening_balance'] ?? 0));
            $status = (string)($_POST['status'] ?? 'Active');

            if ($id <= 0 || $name === '') throw new RuntimeException('Invalid supplier information.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email address is invalid.');
            if (!in_array($status, ['Active','Inactive'], true)) $status = 'Active';

            $s = $conn->prepare("SELECT id FROM suppliers WHERE id=? AND business_id=? LIMIT 1");
            $s->execute([$id,$businessId]);
            if (!$s->fetch()) throw new RuntimeException('Supplier not found.');

            $s = $conn->prepare("SELECT id FROM suppliers WHERE business_id=? AND supplier_name=? AND id<>? LIMIT 1");
            $s->execute([$businessId,$name,$id]);
            if ($s->fetch()) throw new RuntimeException('Another supplier already uses this name.');

            $s = $conn->prepare("UPDATE suppliers SET supplier_name=?,phone=?,email=?,address=?,opening_balance=?,status=? WHERE id=? AND business_id=?");
            $s->execute([$name,$phone ?: null,$email ?: null,$address ?: null,$opening,$status,$id,$businessId]);

            try { if (function_exists('logActivity')) logActivity('UPDATE','Suppliers',$id,'Updated supplier: '.$name); } catch (Throwable $ignore) {}
            supplierFlash('Supplier updated successfully.');
        } elseif ($action === 'toggle_status') {
            if (!$canUpdate) { http_response_code(403); exit('Access denied.'); }

            $id = (int)($_POST['supplier_id'] ?? 0);
            $s = $conn->prepare("SELECT supplier_name,status FROM suppliers WHERE id=? AND business_id=? LIMIT 1");
            $s->execute([$id,$businessId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Supplier not found.');

            $newStatus = $row['status'] === 'Active' ? 'Inactive' : 'Active';
            $s = $conn->prepare("UPDATE suppliers SET status=? WHERE id=? AND business_id=?");
            $s->execute([$newStatus,$id,$businessId]);

            try { if (function_exists('logActivity')) logActivity('UPDATE','Suppliers',$id,'Changed supplier status to '.$newStatus.': '.$row['supplier_name']); } catch (Throwable $ignore) {}
            supplierFlash('Supplier status updated successfully.');
        } elseif ($action === 'delete') {
            if (!$canDelete) { http_response_code(403); exit('Access denied.'); }

            $id = (int)($_POST['supplier_id'] ?? 0);
            $s = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id=? AND business_id=? LIMIT 1");
            $s->execute([$id,$businessId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Supplier not found.');

            $s = $conn->prepare("DELETE FROM suppliers WHERE id=? AND business_id=?");
            $s->execute([$id,$businessId]);

            try { if (function_exists('logActivity')) logActivity('DELETE','Suppliers',$id,'Deleted supplier: '.$row['supplier_name']); } catch (Throwable $ignore) {}
            supplierFlash('Supplier deleted successfully.');
        }
    } catch (PDOException $e) {
        supplierFlash((string)$e->getCode() === '23000'
            ? 'This supplier is already used and cannot be deleted, or the supplier name already exists.'
            : 'Unable to process supplier.', 'error');
    } catch (Throwable $e) {
        supplierFlash($e->getMessage(), 'error');
    }

    header('Location: suppliers.php');
    exit;
}

$s = $conn->prepare("
    SELECT id,supplier_name,phone,email,address,opening_balance,status,created_at
    FROM suppliers
    WHERE business_id=?
    ORDER BY id DESC
");
$s->execute([$businessId]);
$suppliers = $s->fetchAll(PDO::FETCH_ASSOC);

$total = count($suppliers);
$active = 0;
$inactive = 0;
$openingTotal = 0.0;
foreach ($suppliers as $row) {
    if ($row['status'] === 'Active') $active++; else $inactive++;
    $openingTotal += (float)$row['opening_balance'];
}

$flashMessage = $_SESSION['supplier_flash'] ?? null;
$flashType = $_SESSION['supplier_flash_type'] ?? 'success';
unset($_SESSION['supplier_flash'], $_SESSION['supplier_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Suppliers - <?= supplierE($businessName) ?></title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>tailwind.config={darkMode:'class'};</script>
<style>
.field{height:38px;width:100%;border:1px solid rgb(226 232 240);border-radius:12px;background:rgb(248 250 252);padding:0 12px;font-size:10px;outline:none;transition:.15s}
textarea.field{height:auto;padding-top:10px;padding-bottom:10px;resize:vertical}
.field:focus{border-color:rgb(59 130 246);background:#fff}
.dark .field{border-color:rgb(51 65 85);background:rgb(2 6 23);color:rgb(226 232 240)}
</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
<?php require_once __DIR__.'/includes/sidebar.php'; ?>
<div class="min-h-screen lg:pl-64">
<?php require_once __DIR__.'/includes/header.php'; ?>
<main class="p-4 sm:p-5 lg:p-6">

<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h1 class="text-lg font-extrabold text-slate-900 dark:text-white">Suppliers</h1>
        <p class="mt-1 text-[10px] text-slate-400">Manage your business suppliers.</p>
    </div>
    <?php if ($canAdd): ?>
    <button type="button" onclick="openAddModal()" class="inline-flex h-9 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white shadow-sm transition hover:bg-blue-700">
        <i data-lucide="plus" class="h-4 w-4"></i> Add Supplier
    </button>
    <?php endif; ?>
</div>

<?php if ($flashMessage): ?>
<div id="supplierFlash" class="mb-5 flex items-center gap-3 rounded-xl border px-4 py-3 text-[10px] font-semibold <?= $flashType==='error' ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300' : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' ?>">
    <i data-lucide="<?= $flashType==='error' ? 'circle-alert' : 'circle-check' ?>" class="h-4 w-4"></i>
    <?= supplierE($flashMessage) ?>
</div>
<?php endif; ?>

<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
<?php
$stats = [
    ['Total Suppliers', number_format($total), 'truck', 'text-blue-600'],
    ['Active', number_format($active), 'circle-check', 'text-emerald-600'],
    ['Inactive', number_format($inactive), 'circle-x', 'text-red-500'],
    ['Opening Balance', '$'.number_format($openingTotal,2), 'wallet', 'text-amber-500']
];
foreach ($stats as $st):
?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-[9px] font-bold uppercase tracking-wider text-slate-400"><?= supplierE($st[0]) ?></p>
            <p class="mt-2 text-xl font-extrabold <?= $st[3] ?>"><?= supplierE($st[1]) ?></p>
        </div>
        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40">
            <i data-lucide="<?= supplierE($st[2]) ?>" class="h-5 w-5"></i>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
        <div>
            <h2 class="text-[12px] font-extrabold text-slate-900 dark:text-white">Supplier List</h2>
            <p class="mt-1 text-[9px] text-slate-400">Search suppliers automatically.</p>
        </div>
        <div class="relative w-full sm:w-72">
            <i data-lucide="search" class="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"></i>
            <input type="text" id="supplierSearch" autocomplete="off" placeholder="Search ID, name, phone, email..." class="h-9 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-[10px] outline-none transition focus:border-blue-500 focus:bg-white dark:border-slate-700 dark:bg-slate-950">
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead class="bg-slate-50 text-[9px] font-bold uppercase tracking-wider text-slate-400 dark:bg-slate-950/60">
                <tr>
                    <th class="px-5 py-3">ID</th>
                    <th class="px-5 py-3">Supplier</th>
                    <th class="px-5 py-3">Contact</th>
                    <th class="px-5 py-3">Address</th>
                    <th class="px-5 py-3">Opening Balance</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$suppliers): ?>
                <tr><td colspan="7" class="px-5 py-14 text-center text-[10px] text-slate-400">No suppliers found.</td></tr>
            <?php endif; ?>

            <?php foreach ($suppliers as $row):
                $searchText = strtolower(
                    $row['id'].' '.$row['supplier_name'].' '.($row['phone'] ?? '').' '.
                    ($row['email'] ?? '').' '.($row['address'] ?? '').' '.$row['status']
                );
            ?>
            <tr class="supplier-row border-t border-slate-100 text-[10px] transition hover:bg-slate-50/70 dark:border-slate-800 dark:hover:bg-slate-800/40" data-search="<?= supplierE($searchText) ?>">
                <td class="px-5 py-3.5 font-bold text-slate-500">#<?= (int)$row['id'] ?></td>
                <td class="px-5 py-3.5">
                    <p class="font-bold text-slate-800 dark:text-white"><?= supplierE($row['supplier_name']) ?></p>
                    <p class="mt-1 text-[9px] text-slate-400"><?= supplierE(date('d M Y', strtotime($row['created_at']))) ?></p>
                </td>
                <td class="px-5 py-3.5">
                    <p><?= supplierE($row['phone'] ?: '—') ?></p>
                    <p class="mt-1 text-[9px] text-slate-400"><?= supplierE($row['email'] ?: '—') ?></p>
                </td>
                <td class="max-w-[220px] px-5 py-3.5 text-slate-500"><?= supplierE($row['address'] ?: '—') ?></td>
                <td class="px-5 py-3.5 font-extrabold">$<?= number_format((float)$row['opening_balance'],2) ?></td>
                <td class="px-5 py-3.5">
                    <span class="inline-flex rounded-full px-2.5 py-1 text-[9px] font-bold <?= $row['status']==='Active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300' ?>">
                        <?= supplierE($row['status']) ?>
                    </span>
                </td>
                <td class="px-5 py-3.5">
                    <div class="flex justify-end gap-1.5">
                        <?php if ($canUpdate): ?>
                        <button type="button" onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG) ?>)' class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-950/40" title="Edit">
                            <i data-lucide="pencil" class="h-3.5 w-3.5"></i>
                        </button>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= supplierE($csrf) ?>">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="supplier_id" value="<?= (int)$row['id'] ?>">
                            <button class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-100 dark:bg-amber-950/40" title="Toggle status">
                                <i data-lucide="power" class="h-3.5 w-3.5"></i>
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($canDelete): ?>
                        <button type="button" onclick='openDeleteModal(<?= (int)$row["id"] ?>,<?= json_encode($row["supplier_name"], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG) ?>)' class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-950/40" title="Delete">
                            <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr id="noResults" class="hidden"><td colspan="7" class="px-5 py-14 text-center text-[10px] text-slate-400">No matching suppliers found.</td></tr>
            </tbody>
        </table>
    </div>
</div>
</main>
</div>

<div id="supplierModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
<div class="w-full max-w-xl rounded-2xl bg-white shadow-2xl dark:bg-slate-900">
<form method="post" id="supplierForm">
<input type="hidden" name="csrf_token" value="<?= supplierE($csrf) ?>">
<input type="hidden" name="action" id="formAction" value="add">
<input type="hidden" name="supplier_id" id="supplierId">

<div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
    <div><h3 id="modalTitle" class="text-sm font-extrabold text-slate-900 dark:text-white">Add Supplier</h3><p class="mt-1 text-[9px] text-slate-400">Enter supplier information.</p></div>
    <button type="button" onclick="closeSupplierModal()" class="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800"><i data-lucide="x" class="h-4 w-4"></i></button>
</div>

<div class="grid gap-4 p-5 sm:grid-cols-2">
    <label class="sm:col-span-2"><span class="mb-1.5 block text-[9px] font-bold text-slate-500">Supplier Name *</span><input required name="supplier_name" id="supplierName" class="field"></label>
    <label><span class="mb-1.5 block text-[9px] font-bold text-slate-500">Phone</span><input name="phone" id="supplierPhone" class="field"></label>
    <label><span class="mb-1.5 block text-[9px] font-bold text-slate-500">Email</span><input type="email" name="email" id="supplierEmail" class="field"></label>
    <label><span class="mb-1.5 block text-[9px] font-bold text-slate-500">Opening Balance</span><input type="number" min="0" step="0.01" name="opening_balance" id="supplierOpening" value="0" class="field"></label>
    <label><span class="mb-1.5 block text-[9px] font-bold text-slate-500">Status</span><select name="status" id="supplierStatus" class="field"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></label>
    <label class="sm:col-span-2"><span class="mb-1.5 block text-[9px] font-bold text-slate-500">Address</span><textarea name="address" id="supplierAddress" rows="3" class="field"></textarea></label>
</div>

<div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-slate-800">
    <button type="button" onclick="closeSupplierModal()" class="h-9 rounded-xl border border-slate-200 px-4 text-[10px] font-bold dark:border-slate-700">Cancel</button>
    <button class="h-9 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white hover:bg-blue-700">Save Supplier</button>
</div>
</form>
</div>
</div>

<div id="deleteModal" class="fixed inset-0 z-[110] hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
<div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900">
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/40"><i data-lucide="trash-2"></i></div>
    <h3 class="mt-4 text-center text-sm font-extrabold text-slate-900 dark:text-white">Delete Supplier?</h3>
    <p class="mt-2 text-center text-[10px] text-slate-500">Are you sure you want to delete <strong id="deleteName"></strong>?</p>
    <form method="post" class="mt-5 flex justify-center gap-2">
        <input type="hidden" name="csrf_token" value="<?= supplierE($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="supplier_id" id="deleteId">
        <button type="button" onclick="closeDeleteModal()" class="h-9 rounded-xl border border-slate-200 px-4 text-[10px] font-bold dark:border-slate-700">Cancel</button>
        <button class="h-9 rounded-xl bg-red-600 px-4 text-[10px] font-bold text-white hover:bg-red-700">Delete</button>
    </form>
</div>
</div>

<script>
lucide.createIcons();

const supplierModal=document.getElementById('supplierModal');
const deleteModal=document.getElementById('deleteModal');

function openAddModal(){
    document.getElementById('supplierForm').reset();
    document.getElementById('formAction').value='add';
    document.getElementById('supplierId').value='';
    document.getElementById('supplierOpening').value='0';
    document.getElementById('supplierStatus').value='Active';
    document.getElementById('modalTitle').textContent='Add Supplier';
    supplierModal.classList.remove('hidden'); supplierModal.classList.add('flex');
    setTimeout(()=>document.getElementById('supplierName').focus(),50);
}
function openEditModal(r){
    document.getElementById('formAction').value='update';
    document.getElementById('supplierId').value=r.id;
    document.getElementById('supplierName').value=r.supplier_name||'';
    document.getElementById('supplierPhone').value=r.phone||'';
    document.getElementById('supplierEmail').value=r.email||'';
    document.getElementById('supplierAddress').value=r.address||'';
    document.getElementById('supplierOpening').value=r.opening_balance||0;
    document.getElementById('supplierStatus').value=r.status||'Active';
    document.getElementById('modalTitle').textContent='Update Supplier';
    supplierModal.classList.remove('hidden'); supplierModal.classList.add('flex');
}
function closeSupplierModal(){ supplierModal.classList.add('hidden'); supplierModal.classList.remove('flex'); }

function openDeleteModal(id,name){
    document.getElementById('deleteId').value=id;
    document.getElementById('deleteName').textContent=name;
    deleteModal.classList.remove('hidden'); deleteModal.classList.add('flex');
}
function closeDeleteModal(){ deleteModal.classList.add('hidden'); deleteModal.classList.remove('flex'); }

document.getElementById('supplierSearch').addEventListener('input',function(){
    const q=this.value.toLowerCase().trim();
    let visible=0;
    const rows=document.querySelectorAll('.supplier-row');
    rows.forEach(row=>{
        const ok=(row.dataset.search||'').includes(q);
        row.classList.toggle('hidden',!ok);
        if(ok) visible++;
    });
    document.getElementById('noResults').classList.toggle('hidden',visible!==0 || rows.length===0);
});

[supplierModal,deleteModal].forEach(m=>m.addEventListener('click',e=>{
    if(e.target===m){m.classList.add('hidden');m.classList.remove('flex');}
}));
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){closeSupplierModal();closeDeleteModal();}
});
setTimeout(()=>{const f=document.getElementById('supplierFlash');if(f)f.remove();},4500);
</script>
</body>
</html>
