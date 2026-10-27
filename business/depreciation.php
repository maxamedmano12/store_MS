<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('depreciation.view');

$businessId = (int)($_SESSION['business_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Business';
$userId = (int)($_SESSION['user_id'] ?? 0);
$sessionBranchId = (int)($_SESSION['branch_id'] ?? 0);

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business not available.');
}

$canAdd = hasPermission('depreciation.add');
$canDelete = hasPermission('depreciation.delete');

function depE($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function depFlash(string $message, string $type='success'): void {
    $_SESSION['dep_flash'] = $message;
    $_SESSION['dep_flash_type'] = $type;
}
if (empty($_SESSION['dep_csrf'])) {
    $_SESSION['dep_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['dep_csrf'];

function monthStart(string $date): string {
    return date('Y-m-01', strtotime($date));
}
function nextMonth(string $date): string {
    return date('Y-m-01', strtotime($date.' +1 month'));
}

/*
 * Automatic catch-up:
 * Creates every missing monthly depreciation entry up to the current month.
 * Straight Line only. Land/None, Sold/Disposed and non-depreciable assets are skipped.
 */
function catchUpDepreciation(PDO $conn, int $businessId, int $userId, int $sessionBranchId=0): int {
    $sql = "SELECT * FROM fixed_assets
            WHERE business_id=?
              AND depreciation_method='Straight Line'
              AND status IN ('Active','Damaged','Sold','Disposed')
              AND useful_life_months IS NOT NULL
              AND useful_life_months > 0
              AND purchase_cost > salvage_value";
    $params = [$businessId];
    if ($sessionBranchId > 0) {
        $sql .= " AND branch_id=?";
        $params[] = $sessionBranchId;
    }
    $sql .= " ORDER BY id";
    $s=$conn->prepare($sql); $s->execute($params);
    $assets=$s->fetchAll(PDO::FETCH_ASSOC);

    $currentMonth = date('Y-m-01');
    $created = 0;

    foreach ($assets as $asset) {
        $assetId=(int)$asset['id'];
        $purchaseCost=(float)$asset['purchase_cost'];
        $salvage=(float)$asset['salvage_value'];
        $life=(int)$asset['useful_life_months'];
        if ($life <= 0 || $purchaseCost <= $salvage) continue;

        $monthly = round(($purchaseCost-$salvage)/$life, 2);
        if ($monthly <= 0) continue;

        // Depreciation begins in the purchase month.
        $startMonth = monthStart($asset['purchase_date']);
        $assetEndMonth = $currentMonth;
        if (in_array($asset['status'], ['Sold','Disposed'], true) && !empty($asset['disposal_date'])) {
            $disposalMonth = monthStart($asset['disposal_date']);
            if ($disposalMonth < $assetEndMonth) $assetEndMonth = $disposalMonth;
        }
        if ($startMonth > $assetEndMonth) continue;

        $last=$conn->prepare("SELECT depreciation_month, closing_book_value, accumulated_depreciation
                             FROM asset_depreciations
                             WHERE business_id=? AND asset_id=? AND status='Posted'
                             ORDER BY depreciation_month DESC LIMIT 1");
        $last->execute([$businessId,$assetId]);
        $lastRow=$last->fetch(PDO::FETCH_ASSOC);

        $month = $lastRow ? nextMonth($lastRow['depreciation_month']) : $startMonth;
        $opening = $lastRow ? (float)$lastRow['closing_book_value'] : $purchaseCost;
        $accumulated = $lastRow ? (float)$lastRow['accumulated_depreciation'] : 0.0;

        $postedCount=$conn->prepare("SELECT COUNT(*) FROM asset_depreciations WHERE business_id=? AND asset_id=? AND status='Posted'");
        $postedCount->execute([$businessId,$assetId]);
        $monthsAlready=(int)$postedCount->fetchColumn();

        while ($month <= $assetEndMonth && $monthsAlready < $life && $opening > $salvage + 0.004) {
            $amount = min($monthly, max(0, $opening-$salvage));
            $closing = max($salvage, $opening-$amount);
            $accumulated = min($purchaseCost-$salvage, $accumulated+$amount);

            $ins=$conn->prepare("INSERT IGNORE INTO asset_depreciations
                (business_id,branch_id,asset_id,depreciation_month,opening_book_value,depreciation_amount,accumulated_depreciation,closing_book_value,status,note,created_by)
                VALUES (?,?,?,?,?,?,?,?, 'Posted', ?, ?)");
            $ins->execute([
                $businessId,(int)$asset['branch_id'],$assetId,$month,
                $opening,$amount,$accumulated,$closing,
                'Automatic monthly depreciation',$userId
            ]);
            if ($ins->rowCount() > 0) {
                $created++;
                $monthsAlready++;
                $opening=$closing;
            } else {
                $existing=$conn->prepare("SELECT closing_book_value, accumulated_depreciation
                                          FROM asset_depreciations
                                          WHERE business_id=? AND asset_id=? AND depreciation_month=? AND status='Posted'
                                          LIMIT 1");
                $existing->execute([$businessId,$assetId,$month]);
                if ($r=$existing->fetch(PDO::FETCH_ASSOC)) {
                    $opening=(float)$r['closing_book_value'];
                    $accumulated=(float)$r['accumulated_depreciation'];
                    $monthsAlready++;
                }
            }
            $month=nextMonth($month);
        }
    }
    return $created;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $token=$_POST['csrf_token']??'';
    if (!$token || !hash_equals($_SESSION['dep_csrf'],$token)) {
        depFlash('Security token is invalid.','error');
        header('Location: depreciation.php'); exit;
    }

    $action=$_POST['action']??'';
    try {
        if ($action==='run') {
            if (!$canAdd) throw new RuntimeException('Access denied.');
            $conn->beginTransaction();
            $created=catchUpDepreciation($conn,$businessId,$userId,$sessionBranchId);
            $conn->commit();
            if(function_exists('logActivity')){try{logActivity('CREATE','Depreciation',0,'Automatic depreciation catch-up: '.$created.' entries');}catch(Throwable $e){}}
            depFlash($created>0 ? $created.' depreciation record(s) posted successfully.' : 'Depreciation is already up to date.');
        } elseif ($action==='cancel') {
            if (!$canDelete) throw new RuntimeException('Access denied.');
            $id=(int)($_POST['depreciation_id']??0);
            $sql="SELECT ad.id,ad.asset_id,fa.asset_name
                  FROM asset_depreciations ad
                  INNER JOIN fixed_assets fa ON fa.id=ad.asset_id AND fa.business_id=ad.business_id
                  WHERE ad.id=? AND ad.business_id=? AND ad.status='Posted'";
            $params=[$id,$businessId];
            if($sessionBranchId>0){$sql.=" AND ad.branch_id=?";$params[]=$sessionBranchId;}
            $sql.=" LIMIT 1";
            $s=$conn->prepare($sql);$s->execute($params);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row) throw new RuntimeException('Depreciation record not found.');

            // Only latest posted record for an asset can be cancelled to preserve the chain.
            $latest=$conn->prepare("SELECT id FROM asset_depreciations WHERE business_id=? AND asset_id=? AND status='Posted' ORDER BY depreciation_month DESC,id DESC LIMIT 1");
            $latest->execute([$businessId,(int)$row['asset_id']]);
            if((int)$latest->fetchColumn()!==$id) throw new RuntimeException('Only the latest depreciation record for this asset can be cancelled.');

            $u=$conn->prepare("UPDATE asset_depreciations SET status='Cancelled' WHERE id=? AND business_id=?");
            $u->execute([$id,$businessId]);
            if(function_exists('logActivity')){try{logActivity('UPDATE','Depreciation',$id,'Cancelled depreciation for '.$row['asset_name']);}catch(Throwable $e){}}
            depFlash('Depreciation record cancelled.');
        }
    } catch(Throwable $e) {
        if($conn->inTransaction()) $conn->rollBack();
        depFlash($e->getMessage() ?: 'Unable to complete action.','error');
    }
    header('Location: depreciation.php'); exit;
}

/* Auto catch-up when page opens, if user has add permission. */
$autoCreated=0;
if($canAdd){
    try{
        $conn->beginTransaction();
        $autoCreated=catchUpDepreciation($conn,$businessId,$userId,$sessionBranchId);
        $conn->commit();
    }catch(Throwable $e){
        if($conn->inTransaction())$conn->rollBack();
    }
}

$sql="SELECT ad.*,fa.asset_no,fa.asset_name,fa.asset_category,fa.purchase_cost,fa.salvage_value,b.branch_name
      FROM asset_depreciations ad
      INNER JOIN fixed_assets fa ON fa.id=ad.asset_id AND fa.business_id=ad.business_id
      INNER JOIN branches b ON b.id=ad.branch_id AND b.business_id=ad.business_id
      WHERE ad.business_id=?";
$params=[$businessId];
if($sessionBranchId>0){$sql.=" AND ad.branch_id=?";$params[]=$sessionBranchId;}
$sql.=" ORDER BY ad.depreciation_month DESC,ad.id DESC";
$q=$conn->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);

$totalMonthly=0;$totalAccumulated=0;$currentBook=0;$posted=0;
$latestByAsset=[];
foreach($rows as $r){
    if($r['status']==='Posted'){
        $totalMonthly+=(float)$r['depreciation_amount'];
        $posted++;
        if(!isset($latestByAsset[$r['asset_id']])){
            $latestByAsset[$r['asset_id']]=$r;
        }
    }
}
foreach($latestByAsset as $r){
    $totalAccumulated+=(float)$r['accumulated_depreciation'];
    $currentBook+=(float)$r['closing_book_value'];
}

$flashMessage=$_SESSION['dep_flash']??null;
$flashType=$_SESSION['dep_flash_type']??'success';
unset($_SESSION['dep_flash'],$_SESSION['dep_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Depreciation - <?=depE($businessName)?></title>
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
<div><h1 class="text-lg font-extrabold text-slate-900 dark:text-white">Depreciation</h1><p class="mt-1 text-[10px] text-slate-400">Automatic monthly fixed-asset depreciation.</p></div>
<?php if($canAdd):?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=depE($csrfToken)?>"><input type="hidden" name="action" value="run"><button class="inline-flex h-9 items-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white hover:bg-blue-700"><i data-lucide="refresh-cw" class="h-4 w-4"></i>Run Depreciation</button></form>
<?php endif;?>
</div>

<?php if($autoCreated>0):?><div class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-[10px] font-semibold text-blue-700"><b>Automatic:</b> <?=$autoCreated?> missing monthly depreciation record(s) were posted when this page opened.</div><?php endif;?>
<?php if($flashMessage):?><div id="depFlash" class="mb-5 rounded-xl border px-4 py-3 text-[10px] font-semibold <?=$flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><?=depE($flashMessage)?></div><?php endif;?>

<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
<?php $cards=[
['Posted Records',number_format($posted),'calendar-check','text-blue-600'],
['Total Depreciation','$'.number_format($totalMonthly,2),'trending-down','text-amber-600'],
['Accumulated','$'.number_format($totalAccumulated,2),'layers','text-violet-600'],
['Current Book Value','$'.number_format($currentBook,2),'book-open','text-emerald-600']
]; foreach($cards as $c):?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div class="flex items-center justify-between"><div><p class="text-[9px] font-bold uppercase text-slate-400"><?=depE($c[0])?></p><p class="mt-2 text-xl font-extrabold <?=depE($c[3])?>"><?=depE($c[1])?></p></div><i data-lucide="<?=depE($c[2])?>" class="h-5 w-5 <?=depE($c[3])?>"></i></div></div>
<?php endforeach;?>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
<div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
<div><h2 class="text-[12px] font-extrabold text-slate-900 dark:text-white">Depreciation History</h2><p class="mt-1 text-[9px] text-slate-400">Missing months are automatically caught up.</p></div>
<div class="relative w-full sm:w-80"><i data-lucide="search" class="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"></i><input id="depSearch" oninput="filterDep()" placeholder="Search ID, asset, month, branch..." class="h-9 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"></div>
</div>
<div class="overflow-x-auto"><table class="w-full text-left text-[10px]"><thead class="bg-slate-50 uppercase text-slate-400 dark:bg-slate-950/50"><tr><th class="px-4 py-3">ID</th><th class="px-4 py-3">Asset</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Month</th><th class="px-4 py-3">Opening</th><th class="px-4 py-3">Depreciation</th><th class="px-4 py-3">Accumulated</th><th class="px-4 py-3">Closing</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Action</th></tr></thead>
<tbody>
<?php if(!$rows):?><tr><td colspan="10" class="px-4 py-10 text-center text-slate-400">No depreciation records yet. Add depreciable assets in Fixed Assets.</td></tr><?php endif;?>
<?php foreach($rows as $r): $search=strtolower(implode(' ',[$r['id'],$r['asset_no'],$r['asset_name'],$r['asset_category'],$r['branch_name'],$r['depreciation_month'],$r['status']]));?>
<tr class="dep-row border-t border-slate-100 dark:border-slate-800" data-search="<?=depE($search)?>">
<td class="px-4 py-3 font-bold">#<?=$r['id']?></td>
<td class="px-4 py-3"><div class="font-extrabold text-slate-800 dark:text-white"><?=depE($r['asset_name'])?></div><div class="text-[9px] text-slate-400"><?=depE($r['asset_no'])?> · <?=depE($r['asset_category'])?></div></td>
<td class="px-4 py-3"><?=depE($r['branch_name'])?></td>
<td class="px-4 py-3 font-bold"><?=date('M Y',strtotime($r['depreciation_month']))?></td>
<td class="px-4 py-3">$<?=number_format((float)$r['opening_book_value'],2)?></td>
<td class="px-4 py-3 font-bold text-amber-600">$<?=number_format((float)$r['depreciation_amount'],2)?></td>
<td class="px-4 py-3">$<?=number_format((float)$r['accumulated_depreciation'],2)?></td>
<td class="px-4 py-3 font-bold text-emerald-600">$<?=number_format((float)$r['closing_book_value'],2)?></td>
<td class="px-4 py-3"><span class="rounded-full px-2 py-1 font-bold <?=$r['status']==='Posted'?'bg-emerald-50 text-emerald-700':'bg-slate-100 text-slate-500'?>"><?=depE($r['status'])?></span></td>
<td class="px-4 py-3 text-right"><?php if($canDelete && $r['status']==='Posted'):?><button onclick="askCancel(<?=(int)$r['id']?>,'<?=depE($r['asset_name'])?>','<?=date('M Y',strtotime($r['depreciation_month']))?>')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-700" title="Cancel"><i data-lucide="x-circle" class="h-3.5 w-3.5"></i></button><?php endif;?></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>
</main>
<?php if(file_exists(__DIR__.'/includes/footer.php')) require_once __DIR__.'/includes/footer.php'; ?>
</div>

<div id="cancelModal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"><div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-red-50 text-red-600"><i data-lucide="x-circle" class="h-5 w-5"></i></div><h3 class="font-black">Cancel Depreciation?</h3><p id="cancelText" class="mt-2 text-xs text-slate-500"></p><p class="mt-2 text-[9px] text-slate-400">Only the latest posted month for an asset can be cancelled.</p><form method="post" class="mt-5 flex justify-end gap-2"><input type="hidden" name="csrf_token" value="<?=depE($csrfToken)?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="depreciation_id" id="cancelId"><button type="button" onclick="closeCancel()" class="rounded-xl border px-4 py-2 text-xs">No</button><button class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white">Yes, Cancel</button></form></div></div>

<script>
lucide.createIcons();
function filterDep(){let q=document.getElementById('depSearch').value.toLowerCase().trim();document.querySelectorAll('.dep-row').forEach(r=>r.style.display=r.dataset.search.includes(q)?'':'none')}
function askCancel(id,asset,month){document.getElementById('cancelId').value=id;document.getElementById('cancelText').textContent=asset+' - '+month;let m=document.getElementById('cancelModal');m.classList.remove('hidden');m.classList.add('flex')}
function closeCancel(){let m=document.getElementById('cancelModal');m.classList.add('hidden');m.classList.remove('flex')}
setTimeout(()=>{let f=document.getElementById('depFlash');if(f){f.style.opacity='0';setTimeout(()=>f.remove(),300)}},4000);
</script>
</body></html>
