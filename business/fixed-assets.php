<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('assets.view');

$businessId   = (int)($_SESSION['business_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Business';
$userId       = (int)($_SESSION['user_id'] ?? 0);
$sessionBranchId = (int)($_SESSION['branch_id'] ?? 0);

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business not available.');
}

$canAdd    = hasPermission('assets.add');
$canUpdate = hasPermission('assets.update');
$canDelete = hasPermission('assets.delete');

function assetE($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function assetFlash(string $message, string $type='success'): void {
    $_SESSION['asset_flash'] = $message;
    $_SESSION['asset_flash_type'] = $type;
}
if (empty($_SESSION['assets_csrf'])) {
    $_SESSION['assets_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['assets_csrf'];

$allowedCategories = ['Land','Building','Vehicle','Computer','Furniture','Equipment','Other'];
$allowedStatuses   = ['Active','Damaged','Sold','Disposed'];
$allowedMethods    = ['Straight Line','None'];


function assetDepTableExists(PDO $conn): bool {
    try { return (bool)$conn->query("SHOW TABLES LIKE 'asset_depreciations'")->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/* Rebuilds this asset's depreciation after depreciation-defining fields change.
   This project has not posted depreciation into Accounting yet, so rebuilding is safe here. */
function rebuildAssetDepreciation(PDO $conn, int $businessId, int $assetId, int $userId): int {
    if (!assetDepTableExists($conn)) return 0;

    $q=$conn->prepare("SELECT * FROM fixed_assets WHERE id=? AND business_id=? LIMIT 1");
    $q->execute([$assetId,$businessId]);
    $a=$q->fetch(PDO::FETCH_ASSOC);
    if(!$a) return 0;

    // Remove the old calculation chain first, including cancelled rows.
    $d=$conn->prepare("DELETE FROM asset_depreciations WHERE business_id=? AND asset_id=?");
    $d->execute([$businessId,$assetId]);

    if($a['depreciation_method']!=='Straight Line' || $a['asset_category']==='Land') return 0;
    $cost=(float)$a['purchase_cost']; $salvage=(float)$a['salvage_value']; $life=(int)$a['useful_life_months'];
    if($life<=0 || $cost<=$salvage) return 0;

    $start=date('Y-m-01',strtotime($a['purchase_date']));
    $end=date('Y-m-01');
    // Sold/Disposed assets depreciate only through their disposal/sold month.
    if(in_array($a['status'],['Sold','Disposed'],true) && !empty($a['disposal_date'])) {
        $disposalMonth=date('Y-m-01',strtotime($a['disposal_date']));
        if($disposalMonth<$end) $end=$disposalMonth;
    }
    if($start>$end) return 0;

    $monthly=round(($cost-$salvage)/$life,2);
    $opening=$cost; $acc=0.0; $month=$start; $count=0;
    while($month<=$end && $count<$life && $opening>$salvage+0.004){
        $amount=min($monthly,max(0,$opening-$salvage));
        $closing=max($salvage,$opening-$amount);
        $acc=min($cost-$salvage,$acc+$amount);
        $ins=$conn->prepare("INSERT INTO asset_depreciations
            (business_id,branch_id,asset_id,depreciation_month,opening_book_value,depreciation_amount,accumulated_depreciation,closing_book_value,status,note,created_by)
            VALUES (?,?,?,?,?,?,?,?, 'Posted', ?, ?)");
        $ins->execute([$businessId,(int)$a['branch_id'],$assetId,$month,$opening,$amount,$acc,$closing,'Recalculated after fixed asset update',$userId]);
        $opening=$closing; $count++; $month=date('Y-m-01',strtotime($month.' +1 month'));
    }
    return $count;
}

function verifyAssetBranch(PDO $conn, int $businessId, int $branchId, int $sessionBranchId): bool {
    if ($branchId <= 0) return false;
    $sql = "SELECT id FROM branches WHERE id=? AND business_id=?";
    $params = [$branchId, $businessId];
    if ($sessionBranchId > 0) {
        $sql .= " AND id=?";
        $params[] = $sessionBranchId;
    }
    $sql .= " LIMIT 1";
    $s=$conn->prepare($sql); $s->execute($params);
    return (bool)$s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!$postedToken || !hash_equals($_SESSION['assets_csrf'], $postedToken)) {
        assetFlash('Security token is invalid. Please try again.', 'error');
        header('Location: fixed-assets.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add' || $action === 'update') {
            if ($action === 'add' && !$canAdd) throw new RuntimeException('Access denied.');
            if ($action === 'update' && !$canUpdate) throw new RuntimeException('Access denied.');

            $assetId = (int)($_POST['asset_id'] ?? 0);
            $branchId = (int)($_POST['branch_id'] ?? 0);
            $assetName = trim($_POST['asset_name'] ?? '');
            $category = $_POST['asset_category'] ?? 'Equipment';
            $purchaseDate = $_POST['purchase_date'] ?? '';
            $purchaseCost = max(0, (float)($_POST['purchase_cost'] ?? 0));
            $salvageValue = max(0, (float)($_POST['salvage_value'] ?? 0));
            $lifeMonthsRaw = trim($_POST['useful_life_months'] ?? '');
            $lifeMonths = $lifeMonthsRaw === '' ? null : max(1, (int)$lifeMonthsRaw);
            $method = $_POST['depreciation_method'] ?? 'Straight Line';
            $location = trim($_POST['location'] ?? '');
            $serial = trim($_POST['serial_number'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'] ?? 'Active';
            $disposalDate = trim($_POST['disposal_date'] ?? '');
            $disposalAmount = max(0, (float)($_POST['disposal_amount'] ?? 0));
            $disposalNote = trim($_POST['disposal_note'] ?? '');

            if ($assetName === '') throw new RuntimeException('Asset name is required.');
            if (!verifyAssetBranch($conn,$businessId,$branchId,$sessionBranchId)) throw new RuntimeException('Selected branch is invalid.');
            if (!in_array($category,$allowedCategories,true)) throw new RuntimeException('Invalid asset category.');
            if (!in_array($status,$allowedStatuses,true)) throw new RuntimeException('Invalid asset status.');
            if (!in_array($method,$allowedMethods,true)) $method='Straight Line';
            if (!$purchaseDate) throw new RuntimeException('Purchase date is required.');
            if ($salvageValue > $purchaseCost) throw new RuntimeException('Salvage value cannot be greater than purchase cost.');

            if ($category === 'Land') {
                $method='None';
                $lifeMonths=null;
            } elseif ($method === 'Straight Line' && (!$lifeMonths || $lifeMonths < 1)) {
                throw new RuntimeException('Useful life in months is required for depreciable assets.');
            }

            if (in_array($status,['Sold','Disposed'],true) && $disposalDate === '') {
                throw new RuntimeException('Disposal/Sold date is required.');
            }
            if (!in_array($status,['Sold','Disposed'],true)) {
                $disposalDate = '';
                $disposalAmount = 0;
                $disposalNote = '';
            }

            if ($action === 'add') {
                $n=$conn->prepare("SELECT COALESCE(MAX(id),0)+1 FROM fixed_assets WHERE business_id=?");
                $n->execute([$businessId]);
                $assetNo='AST-'.str_pad((string)((int)$n->fetchColumn()),4,'0',STR_PAD_LEFT);

                $stmt=$conn->prepare("INSERT INTO fixed_assets
                    (business_id,branch_id,asset_no,asset_name,asset_category,purchase_date,purchase_cost,salvage_value,useful_life_months,depreciation_method,location,serial_number,description,status,disposal_date,disposal_amount,disposal_note,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $businessId,$branchId,$assetNo,$assetName,$category,$purchaseDate,$purchaseCost,$salvageValue,$lifeMonths,$method,
                    $location?:null,$serial?:null,$description?:null,$status,$disposalDate?:null,$disposalAmount,$disposalNote?:null,$userId
                ]);
                $id=(int)$conn->lastInsertId();
                if(function_exists('logActivity')){ try{logActivity('CREATE','Fixed Assets',$id,'Created asset: '.$assetName);}catch(Throwable $e){} }
                assetFlash('Asset added successfully.');
            } else {
                if ($assetId<=0) throw new RuntimeException('Invalid asset.');
                $check=$conn->prepare("SELECT id,asset_no,branch_id,purchase_date,purchase_cost,salvage_value,useful_life_months,depreciation_method,asset_category,status,disposal_date FROM fixed_assets WHERE id=? AND business_id=?".($sessionBranchId>0?" AND branch_id=?":"")." LIMIT 1");
                $params=[$assetId,$businessId]; if($sessionBranchId>0)$params[]=$sessionBranchId;
                $check->execute($params); $old=$check->fetch(PDO::FETCH_ASSOC);
                if(!$old) throw new RuntimeException('Asset not found.');

                $stmt=$conn->prepare("UPDATE fixed_assets SET branch_id=?,asset_name=?,asset_category=?,purchase_date=?,purchase_cost=?,salvage_value=?,useful_life_months=?,depreciation_method=?,location=?,serial_number=?,description=?,status=?,disposal_date=?,disposal_amount=?,disposal_note=? WHERE id=? AND business_id=?");
                $stmt->execute([
                    $branchId,$assetName,$category,$purchaseDate,$purchaseCost,$salvageValue,$lifeMonths,$method,
                    $location?:null,$serial?:null,$description?:null,$status,$disposalDate?:null,$disposalAmount,$disposalNote?:null,$assetId,$businessId
                ]);
                $depChanged =
                    (int)$old['branch_id'] !== $branchId ||
                    $old['purchase_date'] !== $purchaseDate ||
                    abs((float)$old['purchase_cost'] - $purchaseCost) > 0.004 ||
                    abs((float)$old['salvage_value'] - $salvageValue) > 0.004 ||
                    (int)($old['useful_life_months'] ?? 0) !== (int)($lifeMonths ?? 0) ||
                    $old['depreciation_method'] !== $method ||
                    $old['asset_category'] !== $category ||
                    $old['status'] !== $status ||
                    ($old['disposal_date'] ?? '') !== ($disposalDate ?: '');

                if ($depChanged && assetDepTableExists($conn)) {
                    rebuildAssetDepreciation($conn,$businessId,$assetId,$userId);
                }
                if(function_exists('logActivity')){ try{logActivity('UPDATE','Fixed Assets',$assetId,'Updated asset: '.$assetName);}catch(Throwable $e){} }
                assetFlash('Asset updated successfully.');
            }
        }

        elseif ($action === 'delete') {
            if(!$canDelete) throw new RuntimeException('Access denied.');
            $assetId=(int)($_POST['asset_id']??0);
            $sql="SELECT asset_name FROM fixed_assets WHERE id=? AND business_id=?";
            $params=[$assetId,$businessId];
            if($sessionBranchId>0){$sql.=" AND branch_id=?";$params[]=$sessionBranchId;}
            $s=$conn->prepare($sql);$s->execute($params);$name=$s->fetchColumn();
            if(!$name) throw new RuntimeException('Asset not found.');

            // Once depreciation history exists, preserve accounting history.
            $depTable=$conn->query("SHOW TABLES LIKE 'asset_depreciations'")->fetchColumn();
            if($depTable){
                $c=$conn->prepare("SELECT COUNT(*) FROM asset_depreciations WHERE business_id=? AND asset_id=?");
                $c->execute([$businessId,$assetId]);
                if((int)$c->fetchColumn()>0) throw new RuntimeException('This asset has depreciation history and cannot be deleted. Mark it Disposed instead.');
            }

            $d=$conn->prepare("DELETE FROM fixed_assets WHERE id=? AND business_id=?");
            $d->execute([$assetId,$businessId]);
            if(function_exists('logActivity')){ try{logActivity('DELETE','Fixed Assets',$assetId,'Deleted asset: '.$name);}catch(Throwable $e){} }
            assetFlash('Asset deleted successfully.');
        }
    } catch (Throwable $e) {
        assetFlash($e->getMessage() ?: 'Unable to complete action.', 'error');
    }

    header('Location: fixed-assets.php'); exit;
}

/* Branches */
$sql="SELECT id,branch_name FROM branches WHERE business_id=?";
$params=[$businessId];
if($sessionBranchId>0){$sql.=" AND id=?";$params[]=$sessionBranchId;}
$sql.=" ORDER BY branch_name";
$b=$conn->prepare($sql);$b->execute($params);$branches=$b->fetchAll(PDO::FETCH_ASSOC);

/* Assets */
$sql="SELECT fa.*,b.branch_name FROM fixed_assets fa INNER JOIN branches b ON b.id=fa.branch_id AND b.business_id=fa.business_id WHERE fa.business_id=?";
$params=[$businessId];
if($sessionBranchId>0){$sql.=" AND fa.branch_id=?";$params[]=$sessionBranchId;}
$sql.=" ORDER BY fa.id DESC";
$a=$conn->prepare($sql);$a->execute($params);$assets=$a->fetchAll(PDO::FETCH_ASSOC);

$totalAssets=count($assets); $activeAssets=0; $damagedAssets=0; $totalCost=0;
foreach($assets as $x){
    $totalCost+=(float)$x['purchase_cost'];
    if($x['status']==='Active')$activeAssets++;
    if($x['status']==='Damaged')$damagedAssets++;
}
$flashMessage=$_SESSION['asset_flash']??null;
$flashType=$_SESSION['asset_flash_type']??'success';
unset($_SESSION['asset_flash'],$_SESSION['asset_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fixed Assets - <?=assetE($businessName)?></title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>tailwind.config={darkMode:'class'}</script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
<?php require_once __DIR__.'/includes/sidebar.php'; ?>
<div class="min-h-screen lg:pl-64">
<?php require_once __DIR__.'/includes/header.php'; ?>
<main class="p-4 sm:p-5 lg:p-6">
<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
<div><h1 class="text-lg font-extrabold text-slate-900 dark:text-white">Fixed Assets</h1><p class="mt-1 text-[10px] text-slate-400">Manage long-term business assets.</p></div>
<?php if($canAdd):?><button onclick="openAssetModal()" class="inline-flex h-9 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white shadow-sm hover:bg-blue-700"><i data-lucide="plus" class="h-4 w-4"></i>Add Asset</button><?php endif;?>
</div>

<?php if($flashMessage):?><div id="assetFlash" class="mb-5 flex items-center gap-3 rounded-xl border px-4 py-3 text-[10px] font-semibold <?=$flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><i data-lucide="<?=$flashType==='error'?'circle-alert':'circle-check'?>" class="h-4 w-4"></i><?=assetE($flashMessage)?></div><?php endif;?>

<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
<?php
$cards=[
 ['Total Assets',number_format($totalAssets),'package','text-blue-600'],
 ['Active',number_format($activeAssets),'circle-check','text-emerald-600'],
 ['Damaged',number_format($damagedAssets),'triangle-alert','text-amber-500'],
 ['Purchase Value','$'.number_format($totalCost,2),'circle-dollar-sign','text-violet-600']
];
foreach($cards as $c):?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div class="flex items-center justify-between"><div><p class="text-[9px] font-bold uppercase text-slate-400"><?=assetE($c[0])?></p><p class="mt-2 text-xl font-extrabold <?=assetE($c[3])?>"><?=assetE($c[1])?></p></div><i data-lucide="<?=assetE($c[2])?>" class="h-5 w-5 <?=assetE($c[3])?>"></i></div></div>
<?php endforeach;?>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
<div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
<div><h2 class="text-[12px] font-extrabold text-slate-900 dark:text-white">Asset List</h2><p class="mt-1 text-[9px] text-slate-400">Search assets automatically.</p></div>
<div class="relative w-full sm:w-80"><i data-lucide="search" class="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"></i><input id="assetSearch" oninput="filterAssets()" autocomplete="off" placeholder="Search ID, asset, serial, branch..." class="h-9 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"></div>
</div>
<div class="overflow-x-auto"><table class="w-full text-left text-[10px]"><thead class="bg-slate-50 uppercase text-slate-400 dark:bg-slate-950/50"><tr><th class="px-4 py-3">ID</th><th class="px-4 py-3">Asset</th><th class="px-4 py-3">Category</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Purchase Cost</th><th class="px-4 py-3">Purchase Date</th><th class="px-4 py-3">Life</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead><tbody id="assetRows">
<?php if(!$assets):?><tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">No assets found.</td></tr><?php endif;?>
<?php foreach($assets as $x):
$search=strtolower(implode(' ',[$x['id'],$x['asset_no'],$x['asset_name'],$x['asset_category'],$x['branch_name'],$x['serial_number'],$x['location'],$x['status']]));
?>
<tr class="asset-row border-t border-slate-100 dark:border-slate-800" data-search="<?=assetE($search)?>">
<td class="px-4 py-3 font-bold">#<?=$x['id']?></td>
<td class="px-4 py-3"><div class="font-extrabold text-slate-800 dark:text-white"><?=assetE($x['asset_name'])?></div><div class="text-[9px] text-slate-400"><?=assetE($x['asset_no'])?><?= $x['serial_number']?' · '.assetE($x['serial_number']):'' ?></div></td>
<td class="px-4 py-3"><?=assetE($x['asset_category'])?></td><td class="px-4 py-3"><?=assetE($x['branch_name'])?></td>
<td class="px-4 py-3 font-bold">$<?=number_format((float)$x['purchase_cost'],2)?></td><td class="px-4 py-3"><?=assetE($x['purchase_date'])?></td>
<td class="px-4 py-3"><?=$x['depreciation_method']==='None'?'No depreciation':number_format((int)$x['useful_life_months']).' months'?></td>
<td class="px-4 py-3"><span class="rounded-full px-2 py-1 font-bold <?= $x['status']==='Active'?'bg-emerald-50 text-emerald-700':($x['status']==='Damaged'?'bg-amber-50 text-amber-700':'bg-slate-100 text-slate-600') ?>"><?=assetE($x['status'])?></span></td>
<td class="px-4 py-3 text-right"><div class="inline-flex gap-1">
<?php if($canUpdate):?><button type="button" onclick='editAsset(<?=json_encode($x,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-700" title="Update"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button><?php endif;?>
<?php if($canDelete):?><button type="button" onclick="askDelete(<?=(int)$x['id']?>,'<?=assetE($x['asset_no'])?>','<?=assetE($x['asset_name'])?>')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-700" title="Delete"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i></button><?php endif;?>
</div></td></tr>
<?php endforeach;?>
</tbody></table></div></div>
</main>
<?php if(file_exists(__DIR__.'/includes/footer.php')) require_once __DIR__.'/includes/footer.php'; ?>
</div>

<!-- ADD / UPDATE MODAL -->
<div id="assetModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/50 p-4">
<div class="max-h-[94vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-slate-900">
<div class="sticky top-0 z-10 flex items-center justify-between border-b bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><div><h3 id="assetModalTitle" class="text-sm font-black">Add Asset</h3><p class="text-[9px] text-slate-400">Enter fixed asset information.</p></div><button onclick="closeAssetModal()"><i data-lucide="x" class="h-5 w-5"></i></button></div>
<form method="post" class="p-5" id="assetForm"><input type="hidden" name="csrf_token" value="<?=assetE($csrfToken)?>"><input type="hidden" name="action" id="assetAction" value="add"><input type="hidden" name="asset_id" id="assetId">
<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">

<div class="relative"><label class="text-[9px] font-bold uppercase">Branch *</label><input type="hidden" name="branch_id" id="branchId" value="<?=count($branches)===1?(int)$branches[0]['id']:''?>"><button type="button" onclick="toggleDrop('branchDrop')" id="branchButton" class="mt-1 flex h-10 w-full items-center justify-between rounded-xl border px-3 text-left text-xs"><span><?=count($branches)===1?assetE($branches[0]['branch_name']):'Select Branch'?></span><i data-lucide="chevron-down" class="h-3.5 w-3.5"></i></button><div id="branchDrop" class="absolute z-30 mt-1 hidden w-full rounded-xl border bg-white p-2 shadow-xl dark:bg-slate-900"><input oninput="filterDrop(this,'branchOptions')" placeholder="Search branch..." class="mb-2 h-8 w-full rounded-lg border px-2 text-[10px]"><div id="branchOptions" class="max-h-40 overflow-auto"><?php foreach($branches as $br):?><button type="button" data-search="<?=assetE(strtolower($br['id'].' '.$br['branch_name']))?>" onclick="pickBranch(<?=(int)$br['id']?>,'<?=assetE($br['branch_name'])?>')" class="block w-full rounded-lg px-2 py-2 text-left text-[10px] hover:bg-blue-50">#<?=$br['id']?> · <?=assetE($br['branch_name'])?></button><?php endforeach;?></div></div></div>

<div><label class="text-[9px] font-bold uppercase">Asset Name *</label><input name="asset_name" id="assetName" required class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div>

<div class="relative"><label class="text-[9px] font-bold uppercase">Category *</label><input type="hidden" name="asset_category" id="assetCategory" value="Equipment"><button type="button" onclick="toggleDrop('categoryDrop')" id="categoryButton" class="mt-1 flex h-10 w-full items-center justify-between rounded-xl border px-3 text-left text-xs"><span>Equipment</span><i data-lucide="chevron-down" class="h-3.5 w-3.5"></i></button><div id="categoryDrop" class="absolute z-30 mt-1 hidden w-full rounded-xl border bg-white p-2 shadow-xl dark:bg-slate-900"><input oninput="filterDrop(this,'categoryOptions')" placeholder="Search category..." class="mb-2 h-8 w-full rounded-lg border px-2 text-[10px]"><div id="categoryOptions" class="max-h-40 overflow-auto"><?php foreach($allowedCategories as $v):?><button type="button" data-search="<?=strtolower($v)?>" onclick="pickSimple('assetCategory','categoryButton','categoryDrop','<?=assetE($v)?>')" class="block w-full rounded-lg px-2 py-2 text-left text-[10px] hover:bg-blue-50"><?=assetE($v)?></button><?php endforeach;?></div></div></div>

<div><label class="text-[9px] font-bold uppercase">Purchase Date *</label><input type="date" name="purchase_date" id="purchaseDate" required class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div>
<div><label class="text-[9px] font-bold uppercase">Purchase Cost *</label><input type="number" min="0" step=".01" name="purchase_cost" id="purchaseCost" required class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div>
<div><label class="text-[9px] font-bold uppercase">Salvage Value</label><input type="number" min="0" step=".01" name="salvage_value" id="salvageValue" value="0" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div>

<div><label class="text-[9px] font-bold uppercase">Useful Life (Months)</label><input type="number" min="1" name="useful_life_months" id="lifeMonths" placeholder="e.g. 48" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div>

<div class="relative"><label class="text-[9px] font-bold uppercase">Depreciation Method</label><input type="hidden" name="depreciation_method" id="depMethod" value="Straight Line"><button type="button" onclick="toggleDrop('methodDrop')" id="methodButton" class="mt-1 flex h-10 w-full items-center justify-between rounded-xl border px-3 text-left text-xs"><span>Straight Line</span><i data-lucide="chevron-down" class="h-3.5 w-3.5"></i></button><div id="methodDrop" class="absolute z-30 mt-1 hidden w-full rounded-xl border bg-white p-2 shadow-xl dark:bg-slate-900"><input oninput="filterDrop(this,'methodOptions')" placeholder="Search method..." class="mb-2 h-8 w-full rounded-lg border px-2 text-[10px]"><div id="methodOptions"><?php foreach($allowedMethods as $v):?><button type="button" data-search="<?=strtolower($v)?>" onclick="pickSimple('depMethod','methodButton','methodDrop','<?=assetE($v)?>')" class="block w-full rounded-lg px-2 py-2 text-left text-[10px] hover:bg-blue-50"><?=assetE($v)?></button><?php endforeach;?></div></div></div>

<div><label class="text-[9px] font-bold uppercase">Location</label><input name="location" id="assetLocation" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div>
<div><label class="text-[9px] font-bold uppercase">Serial Number</label><input name="serial_number" id="serialNumber" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div>

<div class="relative"><label class="text-[9px] font-bold uppercase">Status</label><input type="hidden" name="status" id="assetStatus" value="Active"><button type="button" onclick="toggleDrop('statusDrop')" id="statusButton" class="mt-1 flex h-10 w-full items-center justify-between rounded-xl border px-3 text-left text-xs"><span>Active</span><i data-lucide="chevron-down" class="h-3.5 w-3.5"></i></button><div id="statusDrop" class="absolute z-30 mt-1 hidden w-full rounded-xl border bg-white p-2 shadow-xl dark:bg-slate-900"><input oninput="filterDrop(this,'statusOptions')" placeholder="Search status..." class="mb-2 h-8 w-full rounded-lg border px-2 text-[10px]"><div id="statusOptions"><?php foreach($allowedStatuses as $v):?><button type="button" data-search="<?=strtolower($v)?>" onclick="pickStatus('<?=assetE($v)?>')" class="block w-full rounded-lg px-2 py-2 text-left text-[10px] hover:bg-blue-50"><?=assetE($v)?></button><?php endforeach;?></div></div></div>

<div class="md:col-span-2 lg:col-span-3"><label class="text-[9px] font-bold uppercase">Description</label><textarea name="description" id="assetDescription" rows="2" class="mt-1 w-full rounded-xl border px-3 py-2 text-xs"></textarea></div>

<div id="disposalFields" class="hidden md:col-span-2 lg:col-span-3"><div class="grid gap-4 rounded-xl border border-amber-200 bg-amber-50/50 p-4 md:grid-cols-3"><div><label class="text-[9px] font-bold uppercase">Sold / Disposal Date *</label><input type="date" name="disposal_date" id="disposalDate" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div><div><label class="text-[9px] font-bold uppercase">Amount Received</label><input type="number" min="0" step=".01" name="disposal_amount" id="disposalAmount" value="0" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div><div><label class="text-[9px] font-bold uppercase">Note</label><input name="disposal_note" id="disposalNote" class="mt-1 h-10 w-full rounded-xl border px-3 text-xs"></div></div></div>
</div>
<div class="mt-5 flex justify-end gap-2"><button type="button" onclick="closeAssetModal()" class="rounded-xl border px-4 py-2.5 text-[10px] font-bold">Cancel</button><button class="rounded-xl bg-blue-600 px-5 py-2.5 text-[10px] font-bold text-white">Save Asset</button></div>
</form></div></div>

<!-- DELETE MODAL -->
<div id="deleteModal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"><div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-red-50 text-red-600"><i data-lucide="trash-2" class="h-5 w-5"></i></div><h3 class="font-black">Delete Asset?</h3><p id="deleteText" class="mt-2 text-xs text-slate-500"></p><form method="post" class="mt-5 flex justify-end gap-2"><input type="hidden" name="csrf_token" value="<?=assetE($csrfToken)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="asset_id" id="deleteAssetId"><button type="button" onclick="closeDelete()" class="rounded-xl border px-4 py-2 text-xs">No</button><button class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white">Yes, Delete</button></form></div></div>

<script>
lucide.createIcons();
const $=id=>document.getElementById(id);
function filterAssets(){let q=$('assetSearch').value.toLowerCase().trim();document.querySelectorAll('.asset-row').forEach(r=>r.style.display=r.dataset.search.includes(q)?'':'none')}
function toggleDrop(id){document.querySelectorAll('[id$="Drop"]').forEach(x=>{if(x.id!==id)x.classList.add('hidden')});$(id).classList.toggle('hidden')}
function filterDrop(inp,box){let q=inp.value.toLowerCase();$(box).querySelectorAll('[data-search]').forEach(x=>x.style.display=x.dataset.search.includes(q)?'':'none')}
function pickSimple(hidden,button,drop,val){$(hidden).value=val;$(button).querySelector('span').textContent=val;$(drop).classList.add('hidden');if(hidden==='assetCategory')categoryRules()}
function pickBranch(id,name){$('branchId').value=id;$('branchButton').querySelector('span').textContent=name;$('branchDrop').classList.add('hidden')}
function pickStatus(v){pickSimple('assetStatus','statusButton','statusDrop',v);statusRules()}
function categoryRules(){if($('assetCategory').value==='Land'){pickSimple('depMethod','methodButton','methodDrop','None');$('lifeMonths').value='';$('lifeMonths').disabled=true}else{$('lifeMonths').disabled=false}}
function statusRules(){let show=['Sold','Disposed'].includes($('assetStatus').value);$('disposalFields').classList.toggle('hidden',!show)}
function resetAssetForm(){$('assetForm').reset();$('assetAction').value='add';$('assetId').value='';$('assetModalTitle').textContent='Add Asset';$('assetCategory').value='Equipment';$('categoryButton').querySelector('span').textContent='Equipment';$('depMethod').value='Straight Line';$('methodButton').querySelector('span').textContent='Straight Line';$('assetStatus').value='Active';$('statusButton').querySelector('span').textContent='Active';$('lifeMonths').disabled=false;$('disposalFields').classList.add('hidden');<?php if(count($branches)===1):?>pickBranch(<?=(int)$branches[0]['id']?>,<?=json_encode($branches[0]['branch_name'])?>);<?php else:?>$('branchId').value='';$('branchButton').querySelector('span').textContent='Select Branch';<?php endif;?>}
function openAssetModal(){resetAssetForm();$('assetModal').classList.remove('hidden');$('assetModal').classList.add('flex');lucide.createIcons()}
function closeAssetModal(){$('assetModal').classList.add('hidden');$('assetModal').classList.remove('flex')}
function editAsset(x){resetAssetForm();$('assetAction').value='update';$('assetId').value=x.id;$('assetModalTitle').textContent='Update Asset - '+x.asset_no;pickBranch(x.branch_id,x.branch_name);$('assetName').value=x.asset_name||'';pickSimple('assetCategory','categoryButton','categoryDrop',x.asset_category);$('purchaseDate').value=x.purchase_date||'';$('purchaseCost').value=x.purchase_cost||0;$('salvageValue').value=x.salvage_value||0;$('lifeMonths').value=x.useful_life_months||'';pickSimple('depMethod','methodButton','methodDrop',x.depreciation_method);$('assetLocation').value=x.location||'';$('serialNumber').value=x.serial_number||'';$('assetDescription').value=x.description||'';pickStatus(x.status);$('disposalDate').value=x.disposal_date||'';$('disposalAmount').value=x.disposal_amount||0;$('disposalNote').value=x.disposal_note||'';categoryRules();$('assetModal').classList.remove('hidden');$('assetModal').classList.add('flex');lucide.createIcons()}
function askDelete(id,no,name){$('deleteAssetId').value=id;$('deleteText').textContent=no+' - '+name+' will be permanently deleted if it has no depreciation history.';$('deleteModal').classList.remove('hidden');$('deleteModal').classList.add('flex')}
function closeDelete(){$('deleteModal').classList.add('hidden');$('deleteModal').classList.remove('flex')}
document.addEventListener('click',e=>{if(!e.target.closest('.relative'))document.querySelectorAll('[id$="Drop"]').forEach(x=>x.classList.add('hidden'))});
setTimeout(()=>{let f=$('assetFlash');if(f){f.style.opacity='0';setTimeout(()=>f.remove(),300)}},4000);
</script>
</body></html>
