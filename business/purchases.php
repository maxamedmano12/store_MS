<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('purchases.view');

$businessId   = (int)($_SESSION['business_id'] ?? 0);
$businessName = (string)($_SESSION['business_name'] ?? 'Business');
$branchId     = (int)($_SESSION['branch_id'] ?? 0);
$branchName   = (string)($_SESSION['branch_name'] ?? 'Branch');
$userId       = (int)($_SESSION['user_id'] ?? 0);
$canAdd       = hasPermission('purchases.add');

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    exit('Business or branch not available.');
}

if (!function_exists('pe')) {
    function pe($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('pflash')) {
    function pflash(string $m, string $t='success'): void {
        $_SESSION['pflash'] = $m;
        $_SESSION['pflash_type'] = $t;
    }
}

if (empty($_SESSION['purchase_csrf'])) {
    $_SESSION['purchase_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['purchase_csrf'];

/* =========================================================
   SAVE PURCHASE
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_purchase') {
    if (!$canAdd) {
        http_response_code(403);
        exit('Access denied.');
    }

    if (!hash_equals($_SESSION['purchase_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        pflash('Security token is invalid.', 'error');
        header('Location: purchases.php');
        exit;
    }

    try {
        $supplierId      = (int)($_POST['supplier_id'] ?? 0);
        $supplierInvoice = trim((string)($_POST['supplier_invoice_no'] ?? ''));
        $purchaseDateRaw = trim((string)($_POST['purchase_date'] ?? ''));
        $discountAmount  = max(0, (float)($_POST['discount_amount'] ?? 0));
        $taxAmount       = max(0, (float)($_POST['tax_amount'] ?? 0));
        $paidAmount      = max(0, (float)($_POST['paid_amount'] ?? 0));
        $paymentMethod   = trim((string)($_POST['payment_method'] ?? 'Cash'));
        $note            = trim((string)($_POST['note'] ?? ''));

        if (!in_array($paymentMethod, ['Zaad','Edahab','Cash'], true)) {
            throw new RuntimeException('Invalid Payment Method.');
        }
        $items           = json_decode((string)($_POST['items_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($items) || !$items) {
            throw new RuntimeException('Add at least one purchase item.');
        }

        if ($supplierId > 0) {
            $s = $conn->prepare("SELECT id FROM suppliers WHERE id=? AND business_id=? AND status='Active' LIMIT 1");
            $s->execute([$supplierId, $businessId]);
            if (!$s->fetch()) throw new RuntimeException('Supplier not found or inactive.');
        }

        if ($purchaseDateRaw === '') {
            $purchaseDate = date('Y-m-d H:i:s');
        } else {
            $ts = strtotime($purchaseDateRaw);
            if ($ts === false) throw new RuntimeException('Purchase Date is invalid.');
            $purchaseDate = date('Y-m-d H:i:s', $ts);
        }

        $validated = [];
        $subtotal = 0.0;

        foreach ($items as $i => $item) {
            $productId     = (int)($item['product_id'] ?? 0);
            $productUnitId = (int)($item['product_unit_id'] ?? 0);
            $qty           = (float)($item['qty'] ?? 0);
            $unitCost      = (float)($item['unit_cost'] ?? 0);
            $batchNo       = trim((string)($item['batch_number'] ?? ''));
            $expiryDate    = trim((string)($item['expiry_date'] ?? ''));

            if ($productId <= 0 || $productUnitId <= 0 || $qty <= 0) {
                throw new RuntimeException('Invalid item on row '.($i+1).'.');
            }
            if ($unitCost < 0) {
                throw new RuntimeException('Unit Cost cannot be negative on row '.($i+1).'.');
            }

            $s = $conn->prepare("
                SELECT p.id,p.product_name,p.track_expiry,p.status,
                       pu.id product_unit_id,pu.conversion_qty,pu.can_purchase,pu.status unit_status,
                       u.unit_name,u.short_name
                FROM products p
                INNER JOIN product_units pu
                    ON pu.product_id=p.id AND pu.business_id=p.business_id
                INNER JOIN units u
                    ON u.id=pu.unit_id AND u.business_id=pu.business_id
                WHERE p.id=? AND pu.id=? AND p.business_id=?
                LIMIT 1
            ");
            $s->execute([$productId, $productUnitId, $businessId]);
            $pr = $s->fetch(PDO::FETCH_ASSOC);

            if (!$pr) throw new RuntimeException('Product or Product Unit not found on row '.($i+1).'.');
            if ($pr['status'] !== 'Active') throw new RuntimeException($pr['product_name'].' is inactive.');
            if ($pr['unit_status'] !== 'Active' || (int)$pr['can_purchase'] !== 1) {
                throw new RuntimeException('Selected unit cannot be used for purchases: '.$pr['product_name'].'.');
            }

            $conversion = (float)$pr['conversion_qty'];
            if ($conversion <= 0) throw new RuntimeException('Invalid conversion for '.$pr['product_name'].'.');

            $baseQty      = round($qty * $conversion, 3);
            $baseUnitCost = round($unitCost / $conversion, 4);
            $lineTotal    = round($qty * $unitCost, 4);

            if ((int)$pr['track_expiry'] === 1) {
                if ($expiryDate === '') throw new RuntimeException('Expiry Date is required for '.$pr['product_name'].'.');
                if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expiryDate)) {
                    throw new RuntimeException('Expiry Date is invalid for '.$pr['product_name'].'.');
                }
                if ($expiryDate < substr($purchaseDate, 0, 10)) {
                    throw new RuntimeException('Expiry Date cannot be earlier than Purchase Date for '.$pr['product_name'].'.');
                }
            } else {
                $batchNo = '';
                $expiryDate = '';
            }

            $validated[] = [
                'product_id'      => $productId,
                'product_name'    => $pr['product_name'],
                'product_unit_id' => $productUnitId,
                'qty'             => $qty,
                'conversion'      => $conversion,
                'base_qty'        => $baseQty,
                'unit_cost'       => $unitCost,
                'base_unit_cost'  => $baseUnitCost,
                'line_total'      => $lineTotal,
                'track_expiry'    => (int)$pr['track_expiry'],
                'batch_number'    => $batchNo !== '' ? $batchNo : null,
                'expiry_date'     => $expiryDate !== '' ? $expiryDate : null
            ];
            $subtotal += $lineTotal;
        }

        $subtotal    = round($subtotal, 2);
        $totalAmount = round(max(0, $subtotal - $discountAmount + $taxAmount), 2);
        if ($paidAmount > $totalAmount) throw new RuntimeException('Paid Amount cannot be greater than Total Amount.');

        $balanceDue = round($totalAmount - $paidAmount, 2);
        $paymentStatus = $balanceDue <= 0.00001 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid');

        $conn->beginTransaction();

        /* Generate purchase number */
        $datePrefix = date('Ymd', strtotime($purchaseDate));
        $s = $conn->prepare("SELECT purchase_no FROM purchases WHERE business_id=? AND purchase_no LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $s->execute([$businessId, 'PUR-'.$datePrefix.'-%']);
        $lastNo = $s->fetchColumn();
        $seq = 1;
        if ($lastNo && preg_match('/-(\\d+)$/', $lastNo, $m)) $seq = ((int)$m[1]) + 1;
        $purchaseNo = 'PUR-'.$datePrefix.'-'.str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        $s = $conn->prepare("
            INSERT INTO purchases
            (business_id,branch_id,purchase_no,supplier_id,supplier_invoice_no,purchase_date,
             subtotal,discount_amount,tax_amount,total_amount,paid_amount,balance_due,
             payment_status,status,note,created_by)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'Completed',?,?)
        ");
        $s->execute([
            $businessId,$branchId,$purchaseNo,$supplierId>0?$supplierId:null,
            $supplierInvoice!==''?$supplierInvoice:null,$purchaseDate,
            $subtotal,$discountAmount,$taxAmount,$totalAmount,$paidAmount,$balanceDue,
            $paymentStatus,$paymentMethod,$note!==''?$note:null,$userId
        ]);
        $purchaseId = (int)$conn->lastInsertId();

        foreach ($validated as $it) {
            /* Purchase item */
            $s = $conn->prepare("
                INSERT INTO purchase_items
                (business_id,purchase_id,product_id,product_unit_id,
                 transaction_qty,conversion_qty,base_qty,unit_cost,base_unit_cost,total_cost,
                 batch_number,expiry_date)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $s->execute([
                $businessId,$purchaseId,$it['product_id'],$it['product_unit_id'],
                $it['qty'],$it['conversion'],$it['base_qty'],$it['unit_cost'],
                $it['base_unit_cost'],$it['line_total'],$it['batch_number'],$it['expiry_date']
            ]);
            $purchaseItemId = (int)$conn->lastInsertId();

            /* Lock aggregate stock */
            $s = $conn->prepare("
                SELECT id,quantity,average_cost
                FROM product_stock
                WHERE business_id=? AND branch_id=? AND product_id=?
                LIMIT 1 FOR UPDATE
            ");
            $s->execute([$businessId,$branchId,$it['product_id']]);
            $stock = $s->fetch(PDO::FETCH_ASSOC);

            $oldQty = $stock ? (float)$stock['quantity'] : 0.0;
            $oldAvg = $stock ? (float)$stock['average_cost'] : 0.0;
            $newQty = round($oldQty + $it['base_qty'], 3);
            $newAvg = $newQty > 0
                ? round((($oldQty*$oldAvg)+($it['base_qty']*$it['base_unit_cost']))/$newQty, 4)
                : 0.0;

            if ($stock) {
                $s = $conn->prepare("UPDATE product_stock SET quantity=?,average_cost=? WHERE id=? AND business_id=? AND branch_id=?");
                $s->execute([$newQty,$newAvg,(int)$stock['id'],$businessId,$branchId]);
            } else {
                $s = $conn->prepare("INSERT INTO product_stock (business_id,branch_id,product_id,quantity,average_cost) VALUES(?,?,?,?,?)");
                $s->execute([$businessId,$branchId,$it['product_id'],$newQty,$newAvg]);
            }

            /* Stock movement */
            $s = $conn->prepare("
                INSERT INTO stock_movements
                (business_id,branch_id,product_id,product_unit_id,movement_type,
                 reference_type,reference_id,transaction_qty,conversion_qty,base_qty,
                 qty_in,qty_out,unit_cost,total_cost,balance_after,note,created_by)
                VALUES(?,?,?,?,'PURCHASE','PURCHASE',?,?,?,?,?,0,?,?,?,?,?)
            ");
            $s->execute([
                $businessId,$branchId,$it['product_id'],$it['product_unit_id'],
                $purchaseId,$it['qty'],$it['conversion'],$it['base_qty'],$it['base_qty'],
                $it['base_unit_cost'],$it['line_total'],$newQty,'Purchase '.$purchaseNo,$userId
            ]);

            /* Expiry batch */
            if ($it['track_expiry'] === 1) {
                $existingBatch = false;

                if ($it['batch_number'] !== null) {
                    $s = $conn->prepare("
                        SELECT id,quantity
                        FROM product_batches
                        WHERE business_id=? AND branch_id=? AND product_id=? AND batch_number=?
                        ORDER BY id ASC LIMIT 1 FOR UPDATE
                    ");
                    $s->execute([$businessId,$branchId,$it['product_id'],$it['batch_number']]);
                    $existingBatch = $s->fetch(PDO::FETCH_ASSOC);
                }

                if ($existingBatch) {
                    $batchId = (int)$existingBatch['id'];
                    $batchBalance = round((float)$existingBatch['quantity'] + $it['base_qty'], 3);

                    $s = $conn->prepare("
                        UPDATE product_batches
                        SET quantity=?,purchase_item_id=?,purchase_date=?,expiry_date=?,
                            status=CASE
                                WHEN ?<=0 THEN 'Depleted'
                                WHEN ?<CURDATE() THEN 'Expired'
                                ELSE 'Active'
                            END
                        WHERE id=? AND business_id=? AND branch_id=?
                    ");
                    $s->execute([
                        $batchBalance,$purchaseItemId,substr($purchaseDate,0,10),$it['expiry_date'],
                        $batchBalance,$it['expiry_date'],$batchId,$businessId,$branchId
                    ]);
                } else {
                    $batchStatus = $it['expiry_date'] < date('Y-m-d') ? 'Expired' : 'Active';
                    $s = $conn->prepare("
                        INSERT INTO product_batches
                        (business_id,branch_id,product_id,batch_number,purchase_item_id,quantity,
                         purchase_date,expiry_date,status,created_by)
                        VALUES(?,?,?,?,?,?,?,?,?,?)
                    ");
                    $s->execute([
                        $businessId,$branchId,$it['product_id'],$it['batch_number'],$purchaseItemId,
                        $it['base_qty'],substr($purchaseDate,0,10),$it['expiry_date'],$batchStatus,$userId
                    ]);
                    $batchId = (int)$conn->lastInsertId();
                    $batchBalance = $it['base_qty'];
                }

                $s = $conn->prepare("
                    INSERT INTO batch_movements
                    (business_id,branch_id,batch_id,product_id,movement_type,reference_type,reference_id,
                     qty_in,qty_out,balance_after,note,created_by)
                    VALUES(?,?,?,?,'PURCHASE','PURCHASE',?,?,0,?,?,?)
                ");
                $s->execute([
                    $businessId,$branchId,$batchId,$it['product_id'],$purchaseId,
                    $it['base_qty'],$batchBalance,'Purchase '.$purchaseNo,$userId
                ]);
            }
        }

        try {
            if (function_exists('logActivity')) {
                logActivity('CREATE_PURCHASE','purchases',$purchaseId,'Created purchase '.$purchaseNo);
            }
        } catch (Throwable $ignore) {}

        $conn->commit();
        pflash('Purchase '.$purchaseNo.' saved successfully.');
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        pflash($e->getMessage(), 'error');
    }

    header('Location: purchases.php');
    exit;
}

/* =========================================================
   PAGE DATA
========================================================= */
try {
    $s = $conn->prepare("SELECT id,supplier_name,phone,email FROM suppliers WHERE business_id=? AND status='Active' ORDER BY supplier_name");
    $s->execute([$businessId]);
    $suppliers = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $suppliers = []; }

try {
    $s = $conn->prepare("
        SELECT p.id product_id,p.product_name,p.product_code,p.barcode,p.track_expiry,
               pu.id product_unit_id,pu.conversion_qty,pu.purchase_price,
               u.id unit_id,u.unit_name,u.short_name
        FROM products p
        INNER JOIN product_units pu ON pu.product_id=p.id AND pu.business_id=p.business_id
        INNER JOIN units u ON u.id=pu.unit_id AND u.business_id=pu.business_id
        WHERE p.business_id=? AND p.status='Active'
          AND pu.status='Active' AND pu.can_purchase=1
        ORDER BY p.product_name,u.unit_name
    ");
    $s->execute([$businessId]);
    $unitRows = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $unitRows = []; }

$productMap = [];
foreach ($unitRows as $r) {
    $pid = (int)$r['product_id'];
    if (!isset($productMap[$pid])) {
        $productMap[$pid] = [
            'id'=>$pid,
            'product_name'=>$r['product_name'],
            'product_code'=>$r['product_code'],
            'barcode'=>$r['barcode'],
            'track_expiry'=>(int)$r['track_expiry'],
            'units'=>[]
        ];
    }
    $productMap[$pid]['units'][] = [
        'product_unit_id'=>(int)$r['product_unit_id'],
        'unit_id'=>(int)$r['unit_id'],
        'unit_name'=>$r['unit_name'],
        'short_name'=>$r['short_name'],
        'conversion_qty'=>(float)$r['conversion_qty'],
        'purchase_price'=>(float)$r['purchase_price']
    ];
}

try {
    $s = $conn->prepare("
        SELECT p.id,p.purchase_no,p.supplier_invoice_no,p.purchase_date,
               p.total_amount,p.paid_amount,p.balance_due,p.payment_status,p.payment_method,p.status,
               COALESCE(s.supplier_name,'No Supplier') supplier_name,
               u.full_name created_by_name,
               (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id=p.id) item_count
        FROM purchases p
        LEFT JOIN suppliers s ON s.id=p.supplier_id AND s.business_id=p.business_id
        INNER JOIN users u ON u.id=p.created_by
        WHERE p.business_id=? AND p.branch_id=?
        ORDER BY p.id DESC
        LIMIT 300
    ");
    $s->execute([$businessId,$branchId]);
    $purchases = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $purchases = []; }

$stats = ['count'=>count($purchases),'total'=>0,'paid'=>0,'balance'=>0];
foreach ($purchases as $p) {
    $stats['total'] += (float)$p['total_amount'];
    $stats['paid'] += (float)$p['paid_amount'];
    $stats['balance'] += (float)$p['balance_due'];
}

$flash = $_SESSION['pflash'] ?? null;
$flashType = $_SESSION['pflash_type'] ?? 'success';
unset($_SESSION['pflash'],$_SESSION['pflash_type']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Purchases - <?=pe($businessName)?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={darkMode:'class'}</script>
<script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="../assets/css/app.css">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%232563eb'/%3E%3Cpath d='M12 28 32 14l20 14v22H12z' fill='white'/%3E%3Cpath d='M24 34h16v16H24z' fill='%232563eb'/%3E%3C/svg%3E">
<style>
.fi{height:44px;width:100%;border-radius:.75rem;border:1px solid #e2e8f0;background:#f8fafc;padding:.7rem .85rem;font-size:.75rem;outline:none}
.fi:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgb(59 130 246/.08)}
.dark .fi{border-color:#334155;background:#0f172a;color:#e2e8f0}
.dd-menu{position:absolute;z-index:180;margin-top:.25rem;width:100%;overflow:hidden;border:1px solid #e2e8f0;border-radius:.75rem;background:#fff;box-shadow:0 18px 45px rgba(15,23,42,.16)}
.dark .dd-menu{background:#0f172a;border-color:#334155}
</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
<?php require_once __DIR__.'/includes/sidebar.php'; ?>
<div class="min-h-screen transition-all duration-300 lg:ml-[270px]">
<?php require_once __DIR__.'/includes/header.php'; ?>
<main class="p-4 md:p-6">

<div class="mb-5 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
    <div>
        <div class="mb-1 text-[9px] font-bold uppercase tracking-wider text-slate-400">Purchases / Purchases</div>
        <h1 class="text-xl font-black text-slate-900 dark:text-white">Purchases</h1>
        <p class="mt-1 text-[10px] text-slate-500">Supplier purchases and automatic stock receiving in <?=pe($branchName)?>.</p>
    </div>
    <?php if($canAdd): ?>
    <button type="button" onclick="openPurchaseModal()" class="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white">
        <i data-lucide="shopping-cart" class="h-4 w-4"></i> Add Purchase
    </button>
    <?php endif; ?>
</div>

<?php if($flash): ?>
<div class="mb-5 rounded-2xl border px-4 py-3 text-[11px] font-semibold <?=$flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700'?>"><?=pe($flash)?></div>
<?php endif; ?>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
<?php foreach([
    ['Purchases',$stats['count'],'shopping-bag'],
    ['Total Purchases',number_format($stats['total'],2),'badge-dollar-sign'],
    ['Paid',number_format($stats['paid'],2),'circle-check-big'],
    ['Balance Due',number_format($stats['balance'],2),'wallet-cards']
] as $c): ?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800"><i data-lucide="<?=pe($c[2])?>" class="h-5 w-5"></i></div>
        <div><p class="text-[8px] font-bold uppercase text-slate-400"><?=pe($c[0])?></p><p class="text-lg font-black"><?=pe($c[1])?></p></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-col gap-3 border-b p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 class="text-sm font-black">Purchase List</h2><p class="mt-1 text-[9px] text-slate-500">Search updates automatically.</p></div>
        <div class="relative w-full sm:w-80">
            <i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-slate-400"></i>
            <input id="purchaseSearch" class="h-10 w-full rounded-xl border bg-slate-50 pl-9 pr-3 text-[10px] outline-none dark:border-slate-700 dark:bg-slate-800" placeholder="Search ID, purchase, supplier...">
        </div>
    </div>

    <div class="overflow-x-auto"><table class="min-w-full">
        <thead class="bg-slate-50 dark:bg-slate-800"><tr>
        <?php foreach(['ID','Purchase No','Date','Supplier','Invoice','Items','Total','Paid','Balance','Payment','Method','Created By'] as $h): ?>
        <th class="whitespace-nowrap px-4 py-3 text-left text-[9px] font-bold uppercase text-slate-500"><?=pe($h)?></th>
        <?php endforeach; ?>
        </tr></thead>
        <tbody class="divide-y dark:divide-slate-800">
        <?php if(!$purchases): ?><tr><td colspan="12" class="px-5 py-14 text-center text-xs text-slate-500">No purchases found.</td></tr><?php endif; ?>
        <?php foreach($purchases as $p):
            $search=implode(' ',[$p['id'],$p['purchase_no'],$p['purchase_date'],$p['supplier_name'],$p['supplier_invoice_no'],$p['payment_status'],$p['payment_method'],$p['created_by_name']]);
            $payCls=$p['payment_status']==='Paid'?'bg-emerald-50 text-emerald-700':($p['payment_status']==='Partial'?'bg-amber-50 text-amber-700':'bg-red-50 text-red-700');
        ?>
        <tr class="purchase-row hover:bg-slate-50 dark:hover:bg-slate-800/50" data-search="<?=pe($search)?>">
            <td class="px-4 py-4 text-[10px] font-bold">#<?=$p['id']?></td>
            <td class="px-4 py-4 text-[10px] font-black"><?=pe($p['purchase_no'])?></td>
            <td class="px-4 py-4 text-[10px]"><?=pe(date('Y-m-d H:i',strtotime($p['purchase_date'])))?></td>
            <td class="px-4 py-4 text-[10px] font-bold"><?=pe($p['supplier_name'])?></td>
            <td class="px-4 py-4 text-[10px]"><?=pe($p['supplier_invoice_no']?:'—')?></td>
            <td class="px-4 py-4 text-[10px] font-bold"><?=pe($p['item_count'])?></td>
            <td class="px-4 py-4 text-[10px] font-black"><?=number_format((float)$p['total_amount'],2)?></td>
            <td class="px-4 py-4 text-[10px]"><?=number_format((float)$p['paid_amount'],2)?></td>
            <td class="px-4 py-4 text-[10px] font-bold"><?=number_format((float)$p['balance_due'],2)?></td>
            <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-[9px] font-bold <?=$payCls?>"><?=pe($p['payment_status'])?></span></td>
            <td class="px-4 py-4 text-[10px] font-bold"><?=pe($p['payment_method'])?></td>
            <td class="px-4 py-4 text-[10px]"><?=pe($p['created_by_name'])?></td>
        </tr>
        <?php endforeach; ?>
        <tr id="searchEmpty" class="hidden"><td colspan="12" class="px-5 py-14 text-center text-xs">No purchase matches your search.</td></tr>
        </tbody>
    </table></div>
</section>
</main>
<?php require_once __DIR__.'/includes/footer.php'; ?>
</div>

<?php if($canAdd): ?>
<div id="purchaseModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm">
<div class="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900">
    <div class="flex items-center justify-between border-b p-5 dark:border-slate-800">
        <div><h3 class="font-extrabold">Add Purchase</h3><p class="mt-1 text-[10px] text-slate-500">Stock and expiry batch update automatically.</p></div>
        <button type="button" onclick="closePurchaseModal()" class="rounded-xl p-2"><i data-lucide="x"></i></button>
    </div>

    <form id="purchaseForm" method="post" class="overflow-y-auto p-5" onsubmit="return submitPurchase()">
        <input type="hidden" name="csrf_token" value="<?=pe($csrf)?>">
        <input type="hidden" name="action" value="add_purchase">
        <input type="hidden" name="supplier_id" id="supplierId" value="0">
        <input type="hidden" name="items_json" id="itemsJson">

        <div id="purchaseError" class="mb-4 hidden rounded-xl border border-red-200 bg-red-50 p-3 text-[10px] font-bold text-red-700"></div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="mb-1 block text-[10px] font-bold">Supplier</label>
                <div class="relative" id="supplierSel">
                    <button type="button" class="fi flex items-center justify-between text-left" onclick="supplierMenu.classList.toggle('hidden')"><span id="supplierLabel">No Supplier</span><i data-lucide="chevron-down" class="h-4 w-4"></i></button>
                    <div id="supplierMenu" class="dd-menu hidden">
                        <div class="p-2"><input id="supplierSearch" class="fi !h-9" placeholder="Search ID, supplier, phone..."></div>
                        <div class="max-h-52 overflow-y-auto p-1">
                            <button type="button" class="supplier-opt w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" data-search="0 no supplier" onclick="pickSupplier(0,'No Supplier')"><span class="block text-[10px] font-bold">No Supplier</span></button>
                            <?php foreach($suppliers as $s): ?>
                            <button type="button" class="supplier-opt w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800"
                                data-search="<?=pe($s['id'].' '.$s['supplier_name'].' '.($s['phone']??'').' '.($s['email']??''))?>"
                                onclick='pickSupplier(<?=$s["id"]?>,<?=json_encode("#".$s["id"]." · ".$s["supplier_name"],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>
                                <span class="block text-[10px] font-bold"><?=pe($s['supplier_name'])?></span><span class="text-[8px] text-slate-400">#<?=$s['id']?> · <?=pe($s['phone']?:'No phone')?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div><label class="mb-1 block text-[10px] font-bold">Supplier Invoice No</label><input name="supplier_invoice_no" class="fi" placeholder="Optional invoice number"></div>
            <div><label class="mb-1 block text-[10px] font-bold">Purchase Date *</label><input name="purchase_date" type="datetime-local" class="fi" value="<?=date('Y-m-d\\TH:i')?>" required></div>
            <div><label class="mb-1 block text-[10px] font-bold">Branch</label><input class="fi" value="<?=pe($branchName)?>" readonly></div>
        </div>

        <div class="mt-5 rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
            <div class="mb-4 flex items-center justify-between">
                <div><h4 class="text-sm font-black">Purchase Items</h4><p class="mt-1 text-[9px] text-slate-500">Product and Unit lists are searchable.</p></div>
                <button type="button" onclick="addItemRow()" class="inline-flex h-9 items-center gap-2 rounded-xl bg-slate-900 px-3 text-[9px] font-bold text-white dark:bg-white dark:text-slate-900"><i data-lucide="plus" class="h-4 w-4"></i>Add Item</button>
            </div>
            <div id="itemsWrap" class="space-y-3"></div>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-[1fr_360px]">
            <div><label class="mb-1 block text-[10px] font-bold">Note</label><textarea name="note" rows="5" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-[11px] outline-none dark:border-slate-700 dark:bg-slate-800" placeholder="Optional note"></textarea></div>
            <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                <div class="space-y-3">
                    <div><label class="mb-1 block text-[10px] font-bold">Subtotal</label><input id="subtotalDisplay" class="fi" value="0.00" readonly></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-[10px] font-bold">Discount</label><input id="discountAmount" name="discount_amount" type="number" min="0" step=".01" value="0.00" class="fi"></div>
                        <div><label class="mb-1 block text-[10px] font-bold">Tax</label><input id="taxAmount" name="tax_amount" type="number" min="0" step=".01" value="0.00" class="fi"></div>
                    </div>
                    <div><label class="mb-1 block text-[10px] font-bold">Total Amount</label><input id="totalDisplay" class="fi font-black" value="0.00" readonly></div>
                    <div><label class="mb-1 block text-[10px] font-bold">Paid Amount</label><input id="paidAmount" name="paid_amount" type="number" min="0" step=".01" value="0.00" class="fi"></div>

                    <div>
                        <label class="mb-1 block text-[10px] font-bold">Payment Method *</label>
                        <input type="hidden" name="payment_method" id="paymentMethod" value="Cash">
                        <div class="relative" id="paymentMethodSel">
                            <button type="button" class="fi flex items-center justify-between text-left" onclick="paymentMethodMenu.classList.toggle('hidden')">
                                <span id="paymentMethodLabel">Cash</span>
                                <i data-lucide="chevron-down" class="h-4 w-4"></i>
                            </button>
                            <div id="paymentMethodMenu" class="dd-menu hidden">
                                <div class="p-2">
                                    <input id="paymentMethodSearch" class="fi !h-9" placeholder="Search payment method...">
                                </div>
                                <div class="max-h-52 overflow-y-auto p-1">
                                    <?php foreach(['Zaad','Edahab','Cash'] as $method): ?>
                                    <button type="button"
                                        class="payment-method-opt w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800"
                                        data-search="<?=pe($method)?>"
                                        onclick='pickPaymentMethod(<?=json_encode($method,JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>
                                        <span class="block text-[10px] font-bold"><?=pe($method)?></span>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div><label class="mb-1 block text-[10px] font-bold">Balance Due</label><input id="balanceDisplay" class="fi font-black" value="0.00" readonly></div>
                </div>
            </div>
        </div>

        <div class="mt-5 flex justify-end gap-2 border-t pt-4 dark:border-slate-800">
            <button type="button" onclick="closePurchaseModal()" class="h-10 rounded-xl border px-4 text-[10px] font-bold">Cancel</button>
            <button class="h-10 rounded-xl bg-blue-600 px-5 text-[10px] font-bold text-white">Save Purchase</button>
        </div>
    </form>
</div>
</div>
<?php endif; ?>

<script>
const productData = <?=json_encode(array_values($productMap),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
let itemRows=[];
let itemSeq=0;

const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
const money=v=>Number(v||0).toFixed(2);
const qfmt=v=>Number(v||0).toFixed(3).replace(/\\.?0+$/,'');
const productById=id=>productData.find(p=>Number(p.id)===Number(id))||null;
const unitById=(pid,uid)=>{const p=productById(pid);return p?p.units.find(u=>Number(u.product_unit_id)===Number(uid))||null:null};

function openPurchaseModal(){purchaseModal.classList.remove('hidden');purchaseModal.classList.add('flex');if(!itemRows.length)addItemRow()}
function closePurchaseModal(){purchaseModal.classList.add('hidden');purchaseModal.classList.remove('flex')}
function pickSupplier(id,label){supplierId.value=id;supplierLabel.textContent=label;supplierMenu.classList.add('hidden')}
function pickPaymentMethod(method){
    paymentMethod.value=method;
    paymentMethodLabel.textContent=method;
    paymentMethodMenu.classList.add('hidden');
}
function addItemRow(){itemRows.push({row_id:++itemSeq,product_id:0,product_unit_id:0,qty:1,unit_cost:0,batch_number:'',expiry_date:''});renderItems()}
function removeItemRow(id){itemRows=itemRows.filter(x=>x.row_id!==id);renderItems()}
function toggleMenu(id){document.getElementById(id)?.classList.toggle('hidden')}
function filterOptions(input,selector){const q=input.value.toLowerCase();document.querySelectorAll(selector).forEach(x=>x.style.display=(x.dataset.search||'').toLowerCase().includes(q)?'':'none')}

function selectProduct(rowId,productId){
    const r=itemRows.find(x=>x.row_id===rowId);if(!r)return;
    r.product_id=Number(productId);r.product_unit_id=0;r.unit_cost=0;r.batch_number='';r.expiry_date='';
    const p=productById(productId);
    if(p&&p.units.length===1){r.product_unit_id=Number(p.units[0].product_unit_id);r.unit_cost=Number(p.units[0].purchase_price||0)}
    renderItems();
}
function selectUnit(rowId,uid){
    const r=itemRows.find(x=>x.row_id===rowId);if(!r)return;
    const u=unitById(r.product_id,uid);r.product_unit_id=Number(uid);r.unit_cost=u?Number(u.purchase_price||0):0;renderItems();
}
function updateRow(rowId,field,value,rerender=false){
    const r=itemRows.find(x=>x.row_id===rowId);if(!r)return;
    r[field]=(field==='qty'||field==='unit_cost')?Number(value||0):value;
    if(rerender)renderItems();else recalcTotals();
}

function renderItems(){
    itemsWrap.innerHTML='';
    itemRows.forEach((r,idx)=>{
        const p=productById(r.product_id),u=unitById(r.product_id,r.product_unit_id);
        const productLabel=p?`#${p.id} · ${p.product_name}`:'Select Product';
        const unitLabel=u?`#${u.product_unit_id} · ${u.unit_name}`:'Select Unit';
        const productOptions=productData.map(x=>`<button type="button" class="prod-opt-${r.row_id} w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" data-search="${esc(`${x.id} ${x.product_name} ${x.product_code||''} ${x.barcode||''}`)}" onclick="selectProduct(${r.row_id},${x.id})"><span class="block text-[10px] font-bold">${esc(x.product_name)}</span><span class="text-[8px] text-slate-400">#${x.id} · ${esc(x.product_code||'No code')}</span></button>`).join('');
        const unitOptions=p?p.units.map(x=>`<button type="button" class="unit-opt-${r.row_id} w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" data-search="${esc(`${x.product_unit_id} ${x.unit_id} ${x.unit_name} ${x.short_name||''}`)}" onclick="selectUnit(${r.row_id},${x.product_unit_id})"><span class="block text-[10px] font-bold">${esc(x.unit_name)}</span><span class="text-[8px] text-slate-400">#${x.product_unit_id} · Conversion ${qfmt(x.conversion_qty)} · Default ${money(x.purchase_price)}</span></button>`).join(''):'';
        const expiryHtml=p&&Number(p.track_expiry)===1?`<div class="grid gap-3 sm:grid-cols-2"><div><label class="mb-1 block text-[9px] font-bold text-orange-700">Batch Number</label><input class="fi !h-10" value="${esc(r.batch_number)}" placeholder="e.g. LOT-001" oninput="updateRow(${r.row_id},'batch_number',this.value)"></div><div><label class="mb-1 block text-[9px] font-bold text-orange-700">Expiry Date *</label><input type="date" class="fi !h-10" value="${esc(r.expiry_date)}" oninput="updateRow(${r.row_id},'expiry_date',this.value)"></div></div>`:`<div class="rounded-xl bg-slate-50 px-3 py-2 text-[9px] font-semibold text-slate-400 dark:bg-slate-800">No expiry tracking for this product.</div>`;

        itemsWrap.insertAdjacentHTML('beforeend',`<div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/50">
            <div class="mb-3 flex items-center justify-between"><div class="text-[10px] font-black">Item ${idx+1}</div><button type="button" onclick="removeItemRow(${r.row_id})" class="flex h-8 w-8 items-center justify-center rounded-lg text-red-600 hover:bg-red-50"><i data-lucide="trash-2" class="h-4 w-4"></i></button></div>
            <div class="grid gap-3 lg:grid-cols-[1.6fr_1.2fr_.7fr_.9fr_.8fr]">
                <div class="relative" id="prodSel${r.row_id}"><label class="mb-1 block text-[9px] font-bold">Product *</label><button type="button" class="fi !h-10 flex items-center justify-between text-left" onclick="toggleMenu('prodMenu${r.row_id}')"><span>${esc(productLabel)}</span><i data-lucide="chevron-down" class="h-4 w-4"></i></button><div id="prodMenu${r.row_id}" class="dd-menu hidden"><div class="p-2"><input class="fi !h-9" placeholder="Search ID, product, code, barcode..." oninput="filterOptions(this,'.prod-opt-${r.row_id}')"></div><div class="max-h-52 overflow-y-auto p-1">${productOptions}</div></div></div>
                <div class="relative" id="unitSel${r.row_id}"><label class="mb-1 block text-[9px] font-bold">Unit *</label><button type="button" class="fi !h-10 flex items-center justify-between text-left" ${p?'':'disabled'} onclick="toggleMenu('unitMenu${r.row_id}')"><span>${esc(unitLabel)}</span><i data-lucide="chevron-down" class="h-4 w-4"></i></button><div id="unitMenu${r.row_id}" class="dd-menu hidden"><div class="p-2"><input class="fi !h-9" placeholder="Search ID, unit..." oninput="filterOptions(this,'.unit-opt-${r.row_id}')"></div><div class="max-h-52 overflow-y-auto p-1">${unitOptions}</div></div></div>
                <div><label class="mb-1 block text-[9px] font-bold">Quantity *</label><input type="number" min=".001" step=".001" class="fi !h-10" value="${esc(r.qty)}" onchange="updateRow(${r.row_id},'qty',this.value,true)"></div>
                <div><label class="mb-1 block text-[9px] font-bold">Unit Cost *</label><input type="number" min="0" step=".0001" class="fi !h-10" value="${esc(r.unit_cost)}" onchange="updateRow(${r.row_id},'unit_cost',this.value,true)"></div>
                <div><label class="mb-1 block text-[9px] font-bold">Line Total</label><div class="fi !h-10 flex items-center font-black">${money(Number(r.qty||0)*Number(r.unit_cost||0))}</div></div>
            </div>
            <div class="mt-3 grid gap-3 lg:grid-cols-[1fr_2fr]"><div class="rounded-xl bg-blue-50 px-3 py-2 text-[9px] font-semibold text-blue-700 dark:bg-blue-950/30 dark:text-blue-300">${u?`Conversion: 1 ${esc(u.unit_name)} = ${qfmt(u.conversion_qty)} Base Unit(s)<br>Base Qty: ${qfmt(Number(r.qty||0)*Number(u.conversion_qty||0))}`:'Select a unit to see conversion.'}</div>${expiryHtml}</div>
        </div>`);
    });
    lucide?.createIcons();recalcTotals();
}

function recalcTotals(){
    const subtotal=itemRows.reduce((s,r)=>s+Number(r.qty||0)*Number(r.unit_cost||0),0);
    const discount=Number(discountAmount?.value||0),tax=Number(taxAmount?.value||0),paid=Number(paidAmount?.value||0);
    const total=Math.max(0,subtotal-discount+tax),balance=Math.max(0,total-paid);
    if(subtotalDisplay)subtotalDisplay.value=money(subtotal);if(totalDisplay)totalDisplay.value=money(total);if(balanceDisplay)balanceDisplay.value=money(balance);
}
function failPurchase(m){purchaseError.textContent=m;purchaseError.classList.remove('hidden');purchaseError.scrollIntoView({behavior:'smooth',block:'center'});return false}
function submitPurchase(){
    purchaseError.classList.add('hidden');
    if(!itemRows.length)return failPurchase('Add at least one purchase item.');
    for(let i=0;i<itemRows.length;i++){
        const r=itemRows[i],p=productById(r.product_id);
        if(!p)return failPurchase(`Select Product on item ${i+1}.`);
        if(!r.product_unit_id)return failPurchase(`Select Unit on item ${i+1}.`);
        if(Number(r.qty)<=0)return failPurchase(`Quantity must be greater than zero on item ${i+1}.`);
        if(Number(r.unit_cost)<0)return failPurchase(`Unit Cost cannot be negative on item ${i+1}.`);
        if(Number(p.track_expiry)===1&&!r.expiry_date)return failPurchase(`Expiry Date is required for ${p.product_name}.`);
    }
    const subtotal=itemRows.reduce((s,r)=>s+Number(r.qty||0)*Number(r.unit_cost||0),0);
    const total=Math.max(0,subtotal-Number(discountAmount.value||0)+Number(taxAmount.value||0));
    if(Number(paidAmount.value||0)>total)return failPurchase('Paid Amount cannot be greater than Total Amount.');
    itemsJson.value=JSON.stringify(itemRows.map(r=>({product_id:r.product_id,product_unit_id:r.product_unit_id,qty:r.qty,unit_cost:r.unit_cost,batch_number:r.batch_number,expiry_date:r.expiry_date})));
    return true;
}

supplierSearch?.addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('.supplier-opt').forEach(x=>x.style.display=(x.dataset.search||'').toLowerCase().includes(q)?'':'none')});
paymentMethodSearch?.addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('.payment-method-opt').forEach(x=>x.style.display=(x.dataset.search||'').toLowerCase().includes(q)?'':'none')});
purchaseSearch?.addEventListener('input',function(){const q=this.value.toLowerCase();let n=0;document.querySelectorAll('.purchase-row').forEach(x=>{const ok=(x.dataset.search||'').toLowerCase().includes(q);x.style.display=ok?'':'none';if(ok)n++});searchEmpty.classList.toggle('hidden',n!==0)});
discountAmount?.addEventListener('input',recalcTotals);taxAmount?.addEventListener('input',recalcTotals);paidAmount?.addEventListener('input',recalcTotals);
document.addEventListener('click',e=>{if(!e.target.closest('#supplierSel'))supplierMenu?.classList.add('hidden');if(!e.target.closest('#paymentMethodSel'))paymentMethodMenu?.classList.add('hidden');itemRows.forEach(r=>{const ps=document.getElementById('prodSel'+r.row_id),pm=document.getElementById('prodMenu'+r.row_id),us=document.getElementById('unitSel'+r.row_id),um=document.getElementById('unitMenu'+r.row_id);if(ps&&!ps.contains(e.target))pm?.classList.add('hidden');if(us&&!us.contains(e.target))um?.classList.add('hidden')})});
document.addEventListener('DOMContentLoaded',()=>lucide?.createIcons());
</script>
</body>
</html>
