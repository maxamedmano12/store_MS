<?php
require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('stock.view');

$businessId   = (int)($_SESSION['business_id'] ?? 0);
$businessName = (string)($_SESSION['business_name'] ?? 'Business');
$branchId     = (int)($_SESSION['branch_id'] ?? 0);
$branchName   = (string)($_SESSION['branch_name'] ?? 'Branch');
$userId       = (int)($_SESSION['user_id'] ?? 0);
$canAddStock  = hasPermission('stock.add');

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    exit('Business or branch not available.');
}

function stockE($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function stockFlash(string $message, string $type = 'success'): void {
    $_SESSION['stock_flash'] = $message;
    $_SESSION['stock_flash_type'] = $type;
}

if (empty($_SESSION['stock_csrf'])) {
    $_SESSION['stock_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['stock_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['stock_csrf'], $token)) {
        stockFlash('Security token is invalid.', 'error');
        header('Location: stock.php'); exit;
    }

    if (($_POST['action'] ?? '') === 'add_opening_stock') {
        if (!$canAddStock) {
            http_response_code(403); exit('Access denied.');
        }

        $productId      = (int)($_POST['product_id'] ?? 0);
        $productUnitId  = (int)($_POST['product_unit_id'] ?? 0);
        $qty            = (float)($_POST['quantity'] ?? 0);
        $selectedCost   = max(0, (float)($_POST['unit_cost'] ?? 0));
        $note           = trim((string)($_POST['note'] ?? ''));

        try {
            if ($productId <= 0 || $productUnitId <= 0 || $qty <= 0) {
                throw new RuntimeException('Product, Unit and Quantity are required.');
            }

            $stmt = $conn->prepare("\n                SELECT p.product_name, p.status AS product_status,\n                       pu.id AS product_unit_id, pu.conversion_qty, pu.status AS unit_status,\n                       u.unit_name, u.short_name\n                FROM products p\n                INNER JOIN product_units pu\n                    ON pu.product_id = p.id AND pu.business_id = p.business_id\n                INNER JOIN units u\n                    ON u.id = pu.unit_id AND u.business_id = p.business_id\n                WHERE p.id = ? AND p.business_id = ? AND pu.id = ?\n                LIMIT 1\n            ");
            $stmt->execute([$productId, $businessId, $productUnitId]);
            $selected = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$selected) throw new RuntimeException('Selected Product Unit is invalid.');
            if ($selected['product_status'] !== 'Active') throw new RuntimeException('Product is inactive.');
            if ($selected['unit_status'] !== 'Active') throw new RuntimeException('Product Unit is inactive.');

            $conversion = (float)$selected['conversion_qty'];
            if ($conversion <= 0) throw new RuntimeException('Invalid conversion quantity.');

            $check = $conn->prepare("\n                SELECT id FROM stock_movements\n                WHERE business_id = ? AND branch_id = ? AND product_id = ?\n                LIMIT 1\n            ");
            $check->execute([$businessId, $branchId, $productId]);
            if ($check->fetch()) {
                throw new RuntimeException('This product already has stock activity. Use Stock Adjustment later instead of Opening Stock.');
            }

            $baseQty      = round($qty * $conversion, 3);
            $baseUnitCost = $conversion > 0 ? round($selectedCost / $conversion, 4) : 0;
            $totalCost    = round($qty * $selectedCost, 4);

            $conn->beginTransaction();

            $stmt = $conn->prepare("\n                INSERT INTO product_stock\n                    (business_id, branch_id, product_id, quantity, average_cost)\n                VALUES (?, ?, ?, ?, ?)\n                ON DUPLICATE KEY UPDATE\n                    quantity = VALUES(quantity),\n                    average_cost = VALUES(average_cost),\n                    updated_at = CURRENT_TIMESTAMP\n            ");
            $stmt->execute([$businessId, $branchId, $productId, $baseQty, $baseUnitCost]);

            $stmt = $conn->prepare("\n                INSERT INTO stock_movements\n                (business_id, branch_id, product_id, product_unit_id, movement_type,\n                 reference_type, reference_id, transaction_qty, conversion_qty, base_qty,\n                 qty_in, qty_out, unit_cost, total_cost, balance_after, note, created_by)\n                VALUES (?, ?, ?, ?, 'OPENING', 'OPENING_STOCK', NULL, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)\n            ");
            $stmt->execute([
                $businessId, $branchId, $productId, $productUnitId,
                $qty, $conversion, $baseQty, $baseQty,
                $baseUnitCost, $totalCost, $baseQty,
                $note !== '' ? $note : null, $userId
            ]);
            $movementId = (int)$conn->lastInsertId();
            $conn->commit();

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        $conn, 'CREATE', 'Stock', $movementId,
                        'Added opening stock for ' . $selected['product_name'],
                        null,
                        [
                            'product_id' => $productId,
                            'product_unit_id' => $productUnitId,
                            'transaction_qty' => $qty,
                            'conversion_qty' => $conversion,
                            'base_qty' => $baseQty
                        ],
                        $businessId, $branchId
                    );
                } catch (Throwable $e) {}
            }

            stockFlash(
                'Opening stock saved: ' . $qty . ' ' . $selected['unit_name'] .
                ' × ' . $conversion . ' = ' . $baseQty . ' Base Units.'
            );

        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            stockFlash($e->getMessage(), 'error');
        }

        header('Location: stock.php'); exit;
    }
}

try {
    $stmt = $conn->prepare("\n        SELECT p.id, p.product_name, p.product_code, p.barcode, p.minimum_stock, p.cost_price,\n               c.category_name,\n               u.unit_name AS base_unit_name, u.short_name AS base_unit_short_name\n        FROM products p\n        LEFT JOIN categories c ON c.id = p.category_id AND c.business_id = p.business_id\n        INNER JOIN units u ON u.id = p.base_unit_id AND u.business_id = p.business_id\n        WHERE p.business_id = ? AND p.status = 'Active'\n        ORDER BY p.product_name ASC\n    ");
    $stmt->execute([$businessId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $products = []; }

try {
    $stmt = $conn->prepare("\n        SELECT pu.id, pu.product_id, pu.conversion_qty, pu.purchase_price, pu.is_base_unit,\n               u.unit_name, u.short_name\n        FROM product_units pu\n        INNER JOIN products p ON p.id = pu.product_id AND p.business_id = pu.business_id\n        INNER JOIN units u ON u.id = pu.unit_id AND u.business_id = pu.business_id\n        WHERE pu.business_id = ? AND p.status = 'Active' AND pu.status = 'Active'\n        ORDER BY pu.product_id, pu.is_base_unit DESC, pu.conversion_qty, pu.id\n    ");
    $stmt->execute([$businessId]);
    $productUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $productUnits = []; }

$productUnitsMap = [];
foreach ($productUnits as $u) {
    $productUnitsMap[(int)$u['product_id']][] = [
        'id' => (int)$u['id'],
        'unit_name' => (string)$u['unit_name'],
        'short_name' => (string)$u['short_name'],
        'conversion_qty' => (float)$u['conversion_qty'],
        'purchase_price' => (float)$u['purchase_price'],
        'is_base_unit' => (int)$u['is_base_unit']
    ];
}

try {
    $stmt = $conn->prepare("\n        SELECT p.id AS product_id, p.product_name, p.product_code, p.barcode, p.minimum_stock,\n               c.category_name,\n               u.unit_name AS base_unit_name, u.short_name AS base_unit_short_name,\n               COALESCE(ps.quantity,0) AS quantity,\n               COALESCE(ps.average_cost,p.cost_price,0) AS average_cost,\n               COALESCE(ps.quantity,0) * COALESCE(ps.average_cost,p.cost_price,0) AS stock_value\n        FROM products p\n        LEFT JOIN categories c ON c.id = p.category_id AND c.business_id = p.business_id\n        INNER JOIN units u ON u.id = p.base_unit_id AND u.business_id = p.business_id\n        LEFT JOIN product_stock ps\n            ON ps.product_id = p.id AND ps.business_id = p.business_id AND ps.branch_id = ?\n        WHERE p.business_id = ? AND p.status = 'Active'\n        ORDER BY p.id DESC\n    ");
    $stmt->execute([$branchId, $businessId]);
    $stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $stocks = []; }

try {
    $stmt = $conn->prepare("\n        SELECT sm.id, sm.movement_type, sm.transaction_qty, sm.conversion_qty, sm.base_qty,\n               sm.balance_after, sm.created_at, p.product_name, u.short_name, usr.full_name\n        FROM stock_movements sm\n        INNER JOIN products p ON p.id = sm.product_id AND p.business_id = sm.business_id\n        LEFT JOIN product_units pu ON pu.id = sm.product_unit_id\n        LEFT JOIN units u ON u.id = pu.unit_id\n        INNER JOIN users usr ON usr.id = sm.created_by\n        WHERE sm.business_id = ? AND sm.branch_id = ?\n        ORDER BY sm.id DESC LIMIT 20\n    ");
    $stmt->execute([$businessId, $branchId]);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $movements = []; }

$totalProducts = count($stocks);
$inStock = $lowStock = $outStock = 0;
$totalValue = 0;
foreach ($stocks as $s) {
    $q = (float)$s['quantity']; $m = (float)$s['minimum_stock'];
    $totalValue += (float)$s['stock_value'];
    if ($q <= 0) $outStock++;
    elseif ($m > 0 && $q <= $m) $lowStock++;
    else $inStock++;
}

$flashMessage = $_SESSION['stock_flash'] ?? null;
$flashType = $_SESSION['stock_flash_type'] ?? 'success';
unset($_SESSION['stock_flash'], $_SESSION['stock_flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock - <?= stockE($businessName) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%232563eb'/><path d='M10 28L32 14l22 14v24H10V28z' fill='white'/><path d='M20 34h24v18H20V34z' fill='%23dbeafe'/><path d='M25 38h14v14H25V38z' fill='%232563eb'/></svg>">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={darkMode:'class'};</script>
<script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="../assets/css/app.css">
<style>
.stock-input{height:44px;width:100%;border-radius:.75rem;border:1px solid rgb(226 232 240);background:rgb(248 250 252);padding:.7rem .85rem;font-size:.75rem;outline:none}.stock-input:focus{border-color:rgb(59 130 246);box-shadow:0 0 0 3px rgb(59 130 246/.08);background:white}.dark .stock-input{border-color:rgb(51 65 85);background:rgb(15 23 42);color:rgb(226 232 240)}.searchable-option.selected{background:rgb(239 246 255);color:rgb(37 99 235)}.dark .searchable-option.selected{background:rgb(30 58 138/.28);color:rgb(147 197 253)}
</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<div class="min-h-screen transition-all duration-300 lg:ml-[270px]">
<?php require_once __DIR__ . '/includes/header.php'; ?>
<main class="p-4 md:p-6">

<div class="mb-5 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
    <div>
        <div class="mb-1 flex items-center gap-2 text-[9px] font-bold uppercase tracking-wider text-slate-400">Inventory <span>/</span> Stock</div>
        <h1 class="text-xl font-black text-slate-900 dark:text-white">Stock</h1>
        <p class="mt-1 text-[10px] text-slate-500"><?= stockE($branchName) ?> · Stock is stored in Base Unit.</p>
    </div>
    <?php if ($canAddStock): ?>
    <button type="button" onclick="openStockModal()" class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white shadow-lg shadow-blue-600/20 hover:bg-blue-700">
        <i data-lucide="package-plus" class="h-4 w-4"></i> Opening Stock
    </button>
    <?php endif; ?>
</div>

<?php if ($flashMessage): ?>
<div class="mb-5 rounded-2xl border px-4 py-3 text-[11px] font-semibold <?= $flashType==='error'?'border-red-200 bg-red-50 text-red-700':'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
    <?= stockE($flashMessage) ?>
</div>
<?php endif; ?>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
<?php
$cards = [
    ['Products',$totalProducts,'boxes','blue'],
    ['In Stock',$inStock,'circle-check-big','emerald'],
    ['Low Stock',$lowStock,'triangle-alert','amber'],
    ['Out',$outStock,'package-x','red'],
    ['Stock Value','$'.number_format($totalValue,2),'badge-dollar-sign','violet']
];
foreach ($cards as $c): ?>
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"><i data-lucide="<?= $c[2] ?>" class="h-5 w-5"></i></div>
        <div><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400"><?= stockE($c[0]) ?></p><p class="text-lg font-black text-slate-900 dark:text-white"><?= stockE($c[1]) ?></p></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
<div class="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
    <div><h2 class="text-sm font-black text-slate-900 dark:text-white">Current Stock</h2><p class="mt-1 text-[9px] text-slate-500">All quantities are displayed in Base Unit.</p></div>
    <div class="relative w-full sm:w-80"><i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i><input id="stockSearch" type="text" placeholder="Search ID, product, code, category, unit..." class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-800"></div>
</div>
<div class="overflow-x-auto">
<table class="min-w-full">
<thead class="bg-slate-50/80 dark:bg-slate-800/60"><tr>
<?php foreach(['ID','Product','Category','Base Unit','Current Stock','Min Stock','Avg Cost','Stock Value','Status'] as $h): ?><th class="whitespace-nowrap px-4 py-3 text-left text-[9px] font-bold uppercase tracking-wider text-slate-500"><?= stockE($h) ?></th><?php endforeach; ?>
</tr></thead>
<tbody class="divide-y divide-slate-100 dark:divide-slate-800">
<?php foreach($stocks as $s):
$q=(float)$s['quantity']; $m=(float)$s['minimum_stock'];
if($q<=0){$status='Out of Stock';$cls='bg-red-50 text-red-700';}
elseif($m>0&&$q<=$m){$status='Low Stock';$cls='bg-amber-50 text-amber-700';}
else{$status='In Stock';$cls='bg-emerald-50 text-emerald-700';}
$search=implode(' ',[$s['product_id'],$s['product_name'],$s['product_code']??'',$s['barcode']??'',$s['category_name']??'',$s['base_unit_name'],$s['base_unit_short_name'],$status]);
?>
<tr class="stock-row hover:bg-slate-50/70 dark:hover:bg-slate-800/40" data-search="<?= stockE($search) ?>">
<td class="px-4 py-4 text-[10px] font-bold text-slate-500">#<?= (int)$s['product_id'] ?></td>
<td class="min-w-[200px] px-4 py-4"><p class="text-[11px] font-extrabold text-slate-900 dark:text-white"><?= stockE($s['product_name']) ?></p><p class="mt-1 text-[9px] text-slate-400"><?= $s['product_code']?'Code: '.stockE($s['product_code']):'No product code' ?></p></td>
<td class="px-4 py-4 text-[10px]"><?= stockE($s['category_name']?:'Uncategorized') ?></td>
<td class="px-4 py-4"><span class="rounded-lg bg-blue-50 px-2.5 py-1.5 text-[9px] font-bold text-blue-700"><?= stockE($s['base_unit_name']) ?> (<?= stockE($s['base_unit_short_name']) ?>)</span></td>
<td class="px-4 py-4 text-[12px] font-black"><?= number_format($q,3) ?></td>
<td class="px-4 py-4 text-[10px]"><?= number_format($m,3) ?></td>
<td class="px-4 py-4 text-[10px] font-bold">$<?= number_format((float)$s['average_cost'],4) ?></td>
<td class="px-4 py-4 text-[10px] font-black">$<?= number_format((float)$s['stock_value'],2) ?></td>
<td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-[9px] font-bold <?= $cls ?>"><?= stockE($status) ?></span></td>
</tr>
<?php endforeach; ?>
<tr id="searchEmpty" class="hidden"><td colspan="9" class="px-5 py-14 text-center text-xs text-slate-500">No stock matches your search.</td></tr>
</tbody>
</table>
</div>
</section>

<section class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
<div class="border-b border-slate-100 p-4 dark:border-slate-800"><h2 class="text-sm font-black text-slate-900 dark:text-white">Recent Stock Movements</h2><p class="mt-1 text-[9px] text-slate-500">Latest movements for <?= stockE($branchName) ?>.</p></div>
<div class="overflow-x-auto"><table class="min-w-full"><thead class="bg-slate-50/80 dark:bg-slate-800/60"><tr><?php foreach(['ID','Product','Type','Entered Qty','Conversion','Base Qty','Balance','By','Date'] as $h): ?><th class="px-4 py-3 text-left text-[9px] font-bold uppercase tracking-wider text-slate-500"><?= stockE($h) ?></th><?php endforeach; ?></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-slate-800">
<?php if(!$movements): ?><tr><td colspan="9" class="px-5 py-12 text-center text-xs text-slate-500">No stock movements yet.</td></tr><?php endif; ?>
<?php foreach($movements as $m): ?><tr>
<td class="px-4 py-4 text-[10px] font-bold text-slate-500">#<?= (int)$m['id'] ?></td>
<td class="px-4 py-4 text-[10px] font-bold"><?= stockE($m['product_name']) ?></td>
<td class="px-4 py-4"><span class="rounded-full bg-blue-50 px-2.5 py-1 text-[9px] font-bold text-blue-700"><?= stockE($m['movement_type']) ?></span></td>
<td class="px-4 py-4 text-[10px]"><?= number_format((float)$m['transaction_qty'],3) ?> <?= stockE($m['short_name']??'') ?></td>
<td class="px-4 py-4 text-[10px]">× <?= number_format((float)$m['conversion_qty'],3) ?></td>
<td class="px-4 py-4 text-[10px] font-bold"><?= number_format((float)$m['base_qty'],3) ?></td>
<td class="px-4 py-4 text-[10px] font-black"><?= number_format((float)$m['balance_after'],3) ?></td>
<td class="px-4 py-4 text-[10px]"><?= stockE($m['full_name']) ?></td>
<td class="px-4 py-4 text-[9px] text-slate-500"><?= date('d M Y h:i A',strtotime($m['created_at'])) ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
</section>

</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
</div>

<?php if($canAddStock): ?>
<div id="stockModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
<div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl dark:bg-slate-900">
<div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800"><div><h3 class="text-base font-extrabold">Opening Stock</h3><p class="mt-1 text-[10px] text-slate-500">Conversion is calculated automatically.</p></div><button type="button" onclick="closeStockModal()" class="flex h-9 w-9 items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800"><i data-lucide="x" class="h-4 w-4"></i></button></div>
<form method="POST" class="p-5" onsubmit="return validateStockForm()">
<input type="hidden" name="csrf_token" value="<?= stockE($csrfToken) ?>"><input type="hidden" name="action" value="add_opening_stock"><input type="hidden" name="product_id" id="productId"><input type="hidden" name="product_unit_id" id="productUnitId">

<div class="mb-4"><label class="mb-1.5 block text-[10px] font-bold">Product *</label><div class="relative" id="productSelect"><button type="button" class="stock-input flex items-center justify-between text-left" onclick="toggleMenu('productMenu')"><span id="productLabel">Select Product</span><i data-lucide="chevron-down" class="h-4 w-4"></i></button><div id="productMenu" class="absolute left-0 right-0 top-full z-[140] mt-1 hidden max-h-72 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"><div class="border-b p-2 dark:border-slate-800"><input id="productSearch" type="text" placeholder="Search ID, product, code..." class="h-9 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 text-[10px] outline-none dark:border-slate-700 dark:bg-slate-800"></div><div class="max-h-52 overflow-y-auto p-1.5" id="productOptions"><?php foreach($products as $p): ?><button type="button" class="product-option flex w-full items-center justify-between rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" data-search="<?= stockE($p['id'].' '.$p['product_name'].' '.($p['product_code']??'').' '.($p['barcode']??'')) ?>" onclick='selectProduct(<?= (int)$p['id'] ?>, <?= json_encode('#'.$p['id'].' · '.$p['product_name'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><span><span class="block text-[10px] font-bold"><?= stockE($p['product_name']) ?></span><span class="text-[8px] text-slate-400">#<?= (int)$p['id'] ?> · Base: <?= stockE($p['base_unit_name']) ?></span></span></button><?php endforeach; ?></div></div></div></div>

<div class="mb-4"><label class="mb-1.5 block text-[10px] font-bold">Unit *</label><div class="relative"><button type="button" class="stock-input flex items-center justify-between text-left" onclick="toggleMenu('unitMenu')"><span id="unitLabel">Select Product First</span><i data-lucide="chevron-down" class="h-4 w-4"></i></button><div id="unitMenu" class="absolute left-0 right-0 top-full z-[140] mt-1 hidden max-h-72 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"><div class="border-b p-2 dark:border-slate-800"><input id="unitSearch" type="text" placeholder="Search unit or ID..." class="h-9 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 text-[10px] outline-none dark:border-slate-700 dark:bg-slate-800"></div><div id="unitOptions" class="max-h-52 overflow-y-auto p-1.5"><div class="px-3 py-4 text-center text-[9px] text-slate-400">Select a product first.</div></div></div></div><div id="conversionInfo" class="mt-2 hidden rounded-xl bg-blue-50 px-3 py-2 text-[9px] font-semibold text-blue-700"></div></div>

<div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-1.5 block text-[10px] font-bold">Quantity *</label><input id="quantity" type="number" name="quantity" min="0.001" step="0.001" class="stock-input" placeholder="e.g. 3" required></div><div><label class="mb-1.5 block text-[10px] font-bold">Unit Cost</label><input id="unitCost" type="number" name="unit_cost" min="0" step="0.0001" class="stock-input" value="0"><p class="mt-1 text-[8px] text-slate-400">Cost of one selected unit.</p></div></div>

<div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800"><p class="text-[9px] font-bold uppercase text-slate-400">Automatic Conversion</p><p id="calcText" class="mt-1 text-[11px] font-black">Select Product + Unit and enter Quantity.</p></div>
<div class="mt-4"><label class="mb-1.5 block text-[10px] font-bold">Note</label><textarea name="note" rows="3" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-[11px] outline-none dark:border-slate-700 dark:bg-slate-800" placeholder="Optional note..."></textarea></div>
<div class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800"><button type="button" onclick="closeStockModal()" class="h-10 rounded-xl border border-slate-200 px-4 text-[10px] font-bold dark:border-slate-700">Cancel</button><button type="submit" class="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-bold text-white"><i data-lucide="save" class="h-4 w-4"></i> Save Opening Stock</button></div>
</form></div></div>
<?php endif; ?>

<script>
const unitsMap = <?= json_encode($productUnitsMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
let selectedProduct=''; let selectedUnit=null;
function toggleMenu(id){document.querySelectorAll('#productMenu,#unitMenu').forEach(x=>{if(x.id!==id)x.classList.add('hidden')});document.getElementById(id)?.classList.toggle('hidden')}
function openStockModal(){const m=document.getElementById('stockModal');if(m){m.classList.remove('hidden');m.classList.add('flex');document.body.style.overflow='hidden'}}
function closeStockModal(){const m=document.getElementById('stockModal');if(m){m.classList.add('hidden');m.classList.remove('flex');document.body.style.overflow=''}}
function selectProduct(id,label){selectedProduct=String(id);document.getElementById('productId').value=id;document.getElementById('productLabel').textContent=label;document.getElementById('productMenu').classList.add('hidden');buildUnits(id);calculate()}
function buildUnits(id){const box=document.getElementById('unitOptions'), arr=unitsMap[id]||[];selectedUnit=null;document.getElementById('productUnitId').value='';document.getElementById('unitLabel').textContent='Select Unit';if(!arr.length){box.innerHTML='<div class="px-3 py-4 text-center text-[9px] text-slate-400">No active units found.</div>';return}box.innerHTML='';arr.forEach(u=>{const b=document.createElement('button');b.type='button';b.className='unit-option flex w-full items-center justify-between rounded-lg px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800';b.dataset.search=(u.id+' '+u.unit_name+' '+u.short_name+' '+u.conversion_qty).toLowerCase();b.innerHTML='<span><span class="block text-[10px] font-bold">'+esc(u.unit_name)+'</span><span class="text-[8px] text-slate-400">#'+u.id+' · Conversion '+fmt(u.conversion_qty)+(Number(u.is_base_unit)===1?' · BASE':'')+'</span></span><span class="text-[8px] font-bold text-slate-400">'+esc(u.short_name)+'</span>';b.onclick=()=>selectUnit(u);box.appendChild(b)});if(arr.length===1)selectUnit(arr[0])}
function selectUnit(u){selectedUnit=u;document.getElementById('productUnitId').value=u.id;document.getElementById('unitLabel').textContent=u.unit_name+' ('+u.short_name+')';document.getElementById('unitMenu').classList.add('hidden');document.getElementById('unitCost').value=Number(u.purchase_price||0).toFixed(4);const info=document.getElementById('conversionInfo');info.classList.remove('hidden');info.textContent='1 '+u.unit_name+' = '+fmt(u.conversion_qty)+' Base Units';calculate()}
function calculate(){const q=Number(document.getElementById('quantity')?.value||0), t=document.getElementById('calcText');if(!selectedUnit||q<=0){t.textContent='Select Product + Unit and enter Quantity.';return}t.textContent=fmt(q)+' '+selectedUnit.unit_name+' × '+fmt(selectedUnit.conversion_qty)+' = '+fmt(q*Number(selectedUnit.conversion_qty))+' Base Units'}
function validateStockForm(){if(!document.getElementById('productId').value){alert('Please select a product.');return false}if(!document.getElementById('productUnitId').value){alert('Please select a unit.');return false}if(Number(document.getElementById('quantity').value||0)<=0){alert('Quantity must be greater than zero.');return false}return true}
function fmt(v){return Number(v||0).toFixed(3).replace(/\.?0+$/,'')}
function esc(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
document.getElementById('quantity')?.addEventListener('input',calculate);
document.getElementById('productSearch')?.addEventListener('input',function(){const q=this.value.trim().toLowerCase();document.querySelectorAll('.product-option').forEach(x=>x.style.display=(x.dataset.search||'').toLowerCase().includes(q)?'':'none')});
document.getElementById('unitSearch')?.addEventListener('input',function(){const q=this.value.trim().toLowerCase();document.querySelectorAll('.unit-option').forEach(x=>x.style.display=(x.dataset.search||'').includes(q)?'':'none')});
document.getElementById('stockSearch')?.addEventListener('input',function(){const q=this.value.trim().toLowerCase();let n=0;document.querySelectorAll('.stock-row').forEach(x=>{const m=(x.dataset.search||'').toLowerCase().includes(q);x.style.display=m?'':'none';if(m)n++});document.getElementById('searchEmpty')?.classList.toggle('hidden',n!==0)});
document.addEventListener('click',function(e){if(!e.target.closest('#productSelect'))document.getElementById('productMenu')?.classList.add('hidden');if(!e.target.closest('#unitMenu')&&!e.target.closest('[onclick="toggleMenu(\'unitMenu\')"]'))document.getElementById('unitMenu')?.classList.add('hidden')});
document.addEventListener('DOMContentLoaded',()=>{if(typeof lucide!=='undefined')lucide.createIcons()});
</script>
</body>
</html>
