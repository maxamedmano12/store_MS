<?php

require_once '../config/auth.php';
require_once '../config/permissions.php';

requireBusinessUser();
// Dashboard-ka business user kasta wuu geli karaa.

/*
|--------------------------------------------------------------------------
| BUSINESS DASHBOARD
|--------------------------------------------------------------------------
| Multi-Tenant:
| Dhammaan xogta business-ka waxaa lagu xaddidayaa business_id.
| Branch-specific xogtana branch_id.
|--------------------------------------------------------------------------
*/

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId   = (int) ($_SESSION['branch_id'] ?? 0);

$businessName = $_SESSION['business_name'] ?? 'Business';
$branchName   = $_SESSION['branch_name'] ?? 'Main Branch';
$fullName     = $_SESSION['full_name'] ?? 'User';
$role         = $_SESSION['role'] ?? 'User';

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business account is not available.');
}
/*
|--------------------------------------------------------------------------
| LINK / ACTION PERMISSIONS ONLY
|--------------------------------------------------------------------------
| Dashboard-ka iyo xogtiisa business user kasta wuu arki karaa.
| Permission-ku wuxuu qarinayaa oo keliya links/buttons-ka uu user-ku
| permission uusan u lahayn.
|--------------------------------------------------------------------------
*/

$canViewSales         = hasPermission('sales.view');
$canAddSales          = hasPermission('sales.add');

$canViewPurchases     = hasPermission('purchases.view');
$canViewExpenses      = hasPermission('expenses.view');
$canViewProducts      = hasPermission('products.view');
$canViewCustomers     = hasPermission('customers.view');
$canViewSuppliers     = hasPermission('suppliers.view');
$canViewStock         = hasPermission('stock.view');
$canViewBatches       = hasPermission('batches.view');
$canViewCashAccounts  = hasPermission('cash_accounts.view');
$canViewAssets        = hasPermission('assets.view');
$canViewDepreciation  = hasPermission('depreciation.view');
$canViewAccounting    = hasPermission('accounting.view');
$canViewZakat         = hasPermission('zakat.view');
$canViewReports       = hasPermission('reports.view');
$canViewAudit         = hasPermission('audit.view');

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function dashboardE($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function dashboardMoney($value): string
{
    return number_format(
        (float) $value,
        2
    );
}


/*
|--------------------------------------------------------------------------
| CHECK TABLE
|--------------------------------------------------------------------------
| Qaybaha system-ka wali aanan dhisin haddii table-koodu jirin,
| dashboard-ku error ma bixinayo.
|--------------------------------------------------------------------------
*/

function dashboardTableExists(PDO $conn, string $table): bool
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = ?
        ");

        $stmt->execute([
            $table
        ]);

        $cache[$table] =
            (int) $stmt->fetchColumn() > 0;

    } catch (Throwable $e) {

        $cache[$table] = false;

    }

    return $cache[$table];
}


/*
|--------------------------------------------------------------------------
| CHECK COLUMN
|--------------------------------------------------------------------------
*/

function dashboardColumnExists(
    PDO $conn,
    string $table,
    string $column
): bool {

    static $cache = [];

    $key =
        $table
        . '.'
        . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = ?
            AND column_name = ?
        ");

        $stmt->execute([
            $table,
            $column
        ]);

        $cache[$key] =
            (int) $stmt->fetchColumn() > 0;

    } catch (Throwable $e) {

        $cache[$key] = false;

    }

    return $cache[$key];
}


/*
|--------------------------------------------------------------------------
| SAFE SINGLE VALUE QUERY
|--------------------------------------------------------------------------
*/

function dashboardValue(
    PDO $conn,
    string $sql,
    array $params = [],
    $default = 0
) {

    try {

        $stmt =
            $conn->prepare($sql);

        $stmt->execute($params);

        $result =
            $stmt->fetchColumn();

        return
            $result !== false
                ? $result
                : $default;

    } catch (Throwable $e) {

        return $default;

    }
}


/*
|--------------------------------------------------------------------------
| SAFE ROWS QUERY
|--------------------------------------------------------------------------
*/

function dashboardRows(
    PDO $conn,
    string $sql,
    array $params = []
): array {

    try {

        $stmt =
            $conn->prepare($sql);

        $stmt->execute($params);

        return
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

    } catch (Throwable $e) {

        return [];

    }
}


/*
|--------------------------------------------------------------------------
| DEFAULT DASHBOARD VALUES
|--------------------------------------------------------------------------
*/

$totalSales = 0;

$totalPurchases = 0;

$grossProfit = 0;

$stockValue = 0;

$lowStock = 0;

$outOfStock = 0;

$expiringSoon = 0;

$expiredStock = 0;

$customerDebt = 0;

$supplierDebt = 0;

$totalExpenses = 0;

$cashBalance = 0;

$fixedAssets = 0;

$monthlyDepreciation = 0;

$zakatEstimate = 0;

$totalProducts = 0;

$totalCustomers = 0;

$totalSuppliers = 0;

$recentSales = [];

$recentActivities = [];


/*
|--------------------------------------------------------------------------
| SALES
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'sales'
    )
) {

    $salesDateColumn =
        dashboardColumnExists(
            $conn,
            'sales',
            'sale_date'
        )
            ? 'sale_date'
            : (
                dashboardColumnExists(
                    $conn,
                    'sales',
                    'created_at'
                )
                    ? 'created_at'
                    : null
            );

    if (
        dashboardColumnExists(
            $conn,
            'sales',
            'grand_total'
        )
    ) {

        $sql = "
            SELECT
                COALESCE(
                    SUM(grand_total),
                    0
                )
            FROM sales
            WHERE business_id = ?
        ";

        $params = [
            $businessId
        ];

        if (
            $branchId > 0
            &&
            dashboardColumnExists(
                $conn,
                'sales',
                'branch_id'
            )
        ) {

            $sql .= "
                AND branch_id = ?
            ";

            $params[] =
                $branchId;
        }

        if ($salesDateColumn) {

            $sql .= "
                AND YEAR($salesDateColumn)
                    = YEAR(CURDATE())

                AND MONTH($salesDateColumn)
                    = MONTH(CURDATE())
            ";
        }

        $totalSales =
            dashboardValue(
                $conn,
                $sql,
                $params,
                0
            );
    }


    /*
    |--------------------------------------------------------------------------
    | RECENT SALES
    |--------------------------------------------------------------------------
    */

    $invoiceColumn =
        dashboardColumnExists(
            $conn,
            'sales',
            'invoice_no'
        )
            ? 's.invoice_no'
            : 's.id';

    $amountColumn =
        dashboardColumnExists(
            $conn,
            'sales',
            'grand_total'
        )
            ? 's.grand_total'
            : (
                dashboardColumnExists(
                    $conn,
                    'sales',
                    'total_amount'
                )
                    ? 's.total_amount'
                    : '0'
            );

    $statusColumn =
        dashboardColumnExists(
            $conn,
            'sales',
            'payment_status'
        )
            ? 's.payment_status'
            : (
                dashboardColumnExists(
                    $conn,
                    'sales',
                    'status'
                )
                    ? 's.status'
                    : "'Completed'"
            );

    if (
        dashboardColumnExists(
            $conn,
            'sales',
            'customer_id'
        )
        &&
        dashboardTableExists(
            $conn,
            'customers'
        )
    ) {

        $customerNameColumn =
            dashboardColumnExists(
                $conn,
                'customers',
                'customer_name'
            )
                ? 'c.customer_name'
                : (
                    dashboardColumnExists(
                        $conn,
                        'customers',
                        'full_name'
                    )
                        ? 'c.full_name'
                        : "'Walk-in Customer'"
                );

        $recentSql = "
            SELECT
                {$invoiceColumn}
                    AS invoice_no,

                {$customerNameColumn}
                    AS customer_name,

                {$amountColumn}
                    AS amount,

                {$statusColumn}
                    AS payment_status

            FROM sales s

            LEFT JOIN customers c
                ON c.id = s.customer_id

            WHERE s.business_id = ?
        ";

    } else {

        $recentSql = "
            SELECT
                {$invoiceColumn}
                    AS invoice_no,

                'Walk-in Customer'
                    AS customer_name,

                {$amountColumn}
                    AS amount,

                {$statusColumn}
                    AS payment_status

            FROM sales s

            WHERE s.business_id = ?
        ";
    }

    $recentParams = [
        $businessId
    ];

    if (
        $branchId > 0
        &&
        dashboardColumnExists(
            $conn,
            'sales',
            'branch_id'
        )
    ) {

        $recentSql .= "
            AND s.branch_id = ?
        ";

        $recentParams[] =
            $branchId;
    }

    $recentSql .= "
        ORDER BY s.id DESC
        LIMIT 5
    ";

    $recentSales =
        dashboardRows(
            $conn,
            $recentSql,
            $recentParams
        );
}


/*
|--------------------------------------------------------------------------
| PURCHASES
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'purchases'
    )
) {

    $purchaseAmountColumn = null;

    if (
        dashboardColumnExists(
            $conn,
            'purchases',
            'grand_total'
        )
    ) {

        $purchaseAmountColumn =
            'grand_total';

    } elseif (
        dashboardColumnExists(
            $conn,
            'purchases',
            'total_amount'
        )
    ) {

        $purchaseAmountColumn =
            'total_amount';

    }

    if ($purchaseAmountColumn) {

        $purchaseDateColumn =
            dashboardColumnExists(
                $conn,
                'purchases',
                'purchase_date'
            )
                ? 'purchase_date'
                : (
                    dashboardColumnExists(
                        $conn,
                        'purchases',
                        'created_at'
                    )
                        ? 'created_at'
                        : null
                );

        $sql = "
            SELECT
                COALESCE(
                    SUM($purchaseAmountColumn),
                    0
                )

            FROM purchases

            WHERE business_id = ?
        ";

        $params = [
            $businessId
        ];

        if (
            $branchId > 0
            &&
            dashboardColumnExists(
                $conn,
                'purchases',
                'branch_id'
            )
        ) {

            $sql .= "
                AND branch_id = ?
            ";

            $params[] =
                $branchId;
        }

        if ($purchaseDateColumn) {

            $sql .= "
                AND YEAR($purchaseDateColumn)
                    = YEAR(CURDATE())

                AND MONTH($purchaseDateColumn)
                    = MONTH(CURDATE())
            ";
        }

        $totalPurchases =
            dashboardValue(
                $conn,
                $sql,
                $params,
                0
            );
    }
}


/*
|--------------------------------------------------------------------------
| EXPENSES
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'expenses'
    )
) {

    $expenseAmountColumn =
        dashboardColumnExists(
            $conn,
            'expenses',
            'amount'
        )
            ? 'amount'
            : null;

    if ($expenseAmountColumn) {

        $sql = "
            SELECT
                COALESCE(
                    SUM(amount),
                    0
                )

            FROM expenses

            WHERE business_id = ?
        ";

        $params = [
            $businessId
        ];

        if (
            $branchId > 0
            &&
            dashboardColumnExists(
                $conn,
                'expenses',
                'branch_id'
            )
        ) {

            $sql .= "
                AND branch_id = ?
            ";

            $params[] =
                $branchId;
        }

        if (
            dashboardColumnExists(
                $conn,
                'expenses',
                'expense_date'
            )
        ) {

            $sql .= "
                AND YEAR(expense_date)
                    = YEAR(CURDATE())

                AND MONTH(expense_date)
                    = MONTH(CURDATE())
            ";

        } elseif (
            dashboardColumnExists(
                $conn,
                'expenses',
                'created_at'
            )
        ) {

            $sql .= "
                AND YEAR(created_at)
                    = YEAR(CURDATE())

                AND MONTH(created_at)
                    = MONTH(CURDATE())
            ";
        }

        $totalExpenses =
            dashboardValue(
                $conn,
                $sql,
                $params,
                0
            );
    }
}


/*
|--------------------------------------------------------------------------
| PRODUCTS
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'products'
    )
) {

    $sql = "
        SELECT COUNT(*)

        FROM products

        WHERE business_id = ?
    ";

    $params = [
        $businessId
    ];

    if (
        dashboardColumnExists(
            $conn,
            'products',
            'status'
        )
    ) {

        $sql .= "
            AND status = 'Active'
        ";
    }

    $totalProducts =
        dashboardValue(
            $conn,
            $sql,
            $params,
            0
        );
}


/*
|--------------------------------------------------------------------------
| CUSTOMERS
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'customers'
    )
) {

    $totalCustomers =
        dashboardValue(
            $conn,
            "
                SELECT COUNT(*)

                FROM customers

                WHERE business_id = ?
            ",
            [
                $businessId
            ],
            0
        );
}


/*
|--------------------------------------------------------------------------
| SUPPLIERS
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'suppliers'
    )
) {

    $totalSuppliers =
        dashboardValue(
            $conn,
            "
                SELECT COUNT(*)

                FROM suppliers

                WHERE business_id = ?
            ",
            [
                $businessId
            ],
            0
        );
}


/*
|--------------------------------------------------------------------------
| STOCK
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'stock'
    )
) {

    $qtyColumn = null;

    if (
        dashboardColumnExists(
            $conn,
            'stock',
            'quantity'
        )
    ) {

        $qtyColumn =
            'quantity';

    } elseif (
        dashboardColumnExists(
            $conn,
            'stock',
            'qty'
        )
    ) {

        $qtyColumn =
            'qty';
    }

    if ($qtyColumn) {

        $sql = "
            SELECT COUNT(*)

            FROM stock

            WHERE business_id = ?
            AND $qtyColumn <= 0
        ";

        $params = [
            $businessId
        ];

        if (
            $branchId > 0
            &&
            dashboardColumnExists(
                $conn,
                'stock',
                'branch_id'
            )
        ) {

            $sql .= "
                AND branch_id = ?
            ";

            $params[] =
                $branchId;
        }

        $outOfStock =
            dashboardValue(
                $conn,
                $sql,
                $params,
                0
            );
    }
}


/*
|--------------------------------------------------------------------------
| PRODUCT BATCHES / EXPIRY
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'product_batches'
    )
) {

    $batchBusinessWhere = "";

    $batchParams = [];

    if (
        dashboardColumnExists(
            $conn,
            'product_batches',
            'business_id'
        )
    ) {

        $batchBusinessWhere = "
            business_id = ?
        ";

        $batchParams[] =
            $businessId;

    } else {

        $batchBusinessWhere =
            "1 = 1";
    }


    if (
        $branchId > 0
        &&
        dashboardColumnExists(
            $conn,
            'product_batches',
            'branch_id'
        )
    ) {

        $batchBusinessWhere .= "
            AND branch_id = ?
        ";

        $batchParams[] =
            $branchId;
    }


    if (
        dashboardColumnExists(
            $conn,
            'product_batches',
            'expiry_date'
        )
    ) {

        $expiredStock =
            dashboardValue(
                $conn,
                "
                    SELECT COUNT(*)

                    FROM product_batches

                    WHERE $batchBusinessWhere

                    AND expiry_date IS NOT NULL

                    AND expiry_date < CURDATE()
                ",
                $batchParams,
                0
            );


        $expiringSoon =
            dashboardValue(
                $conn,
                "
                    SELECT COUNT(*)

                    FROM product_batches

                    WHERE $batchBusinessWhere

                    AND expiry_date IS NOT NULL

                    AND expiry_date
                        BETWEEN CURDATE()
                        AND DATE_ADD(
                            CURDATE(),
                            INTERVAL 30 DAY
                        )
                ",
                $batchParams,
                0
            );
    }
}


/*
|--------------------------------------------------------------------------
| CUSTOMER DEBT / ACCOUNTS RECEIVABLE
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'sales'
    )
    &&
    dashboardColumnExists(
        $conn,
        'sales',
        'due_amount'
    )
) {

    $sql = "
        SELECT
            COALESCE(
                SUM(due_amount),
                0
            )

        FROM sales

        WHERE business_id = ?
        AND due_amount > 0
    ";

    $params = [
        $businessId
    ];

    if (
        $branchId > 0
        &&
        dashboardColumnExists(
            $conn,
            'sales',
            'branch_id'
        )
    ) {

        $sql .= "
            AND branch_id = ?
        ";

        $params[] =
            $branchId;
    }

    $customerDebt =
        dashboardValue(
            $conn,
            $sql,
            $params,
            0
        );
}


/*
|--------------------------------------------------------------------------
| SUPPLIER PAYABLE
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'purchases'
    )
    &&
    dashboardColumnExists(
        $conn,
        'purchases',
        'due_amount'
    )
) {

    $sql = "
        SELECT
            COALESCE(
                SUM(due_amount),
                0
            )

        FROM purchases

        WHERE business_id = ?
        AND due_amount > 0
    ";

    $params = [
        $businessId
    ];

    if (
        $branchId > 0
        &&
        dashboardColumnExists(
            $conn,
            'purchases',
            'branch_id'
        )
    ) {

        $sql .= "
            AND branch_id = ?
        ";

        $params[] =
            $branchId;
    }

    $supplierDebt =
        dashboardValue(
            $conn,
            $sql,
            $params,
            0
        );
}


/*
|--------------------------------------------------------------------------
| CASH / BANK / EVC
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'accounts'
    )
    &&
    dashboardColumnExists(
        $conn,
        'accounts',
        'balance'
    )
) {

    $sql = "
        SELECT
            COALESCE(
                SUM(balance),
                0
            )

        FROM accounts

        WHERE business_id = ?
    ";

    $params = [
        $businessId
    ];

    if (
        $branchId > 0
        &&
        dashboardColumnExists(
            $conn,
            'accounts',
            'branch_id'
        )
    ) {

        $sql .= "
            AND (
                branch_id = ?
                OR branch_id IS NULL
            )
        ";

        $params[] =
            $branchId;
    }

    $cashBalance =
        dashboardValue(
            $conn,
            $sql,
            $params,
            0
        );
}


/*
|--------------------------------------------------------------------------
| FIXED ASSETS
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'fixed_assets'
    )
) {

    $assetValueColumn = null;

    if (
        dashboardColumnExists(
            $conn,
            'fixed_assets',
            'book_value'
        )
    ) {

        $assetValueColumn =
            'book_value';

    } elseif (
        dashboardColumnExists(
            $conn,
            'fixed_assets',
            'purchase_cost'
        )
    ) {

        $assetValueColumn =
            'purchase_cost';

    } elseif (
        dashboardColumnExists(
            $conn,
            'fixed_assets',
            'cost'
        )
    ) {

        $assetValueColumn =
            'cost';
    }


    if ($assetValueColumn) {

        $sql = "
            SELECT
                COALESCE(
                    SUM($assetValueColumn),
                    0
                )

            FROM fixed_assets

            WHERE business_id = ?
        ";

        $params = [
            $businessId
        ];

        if (
            $branchId > 0
            &&
            dashboardColumnExists(
                $conn,
                'fixed_assets',
                'branch_id'
            )
        ) {

            $sql .= "
                AND (
                    branch_id = ?
                    OR branch_id IS NULL
                )
            ";

            $params[] =
                $branchId;
        }

        $fixedAssets =
            dashboardValue(
                $conn,
                $sql,
                $params,
                0
            );
    }
}


/*
|--------------------------------------------------------------------------
| DEPRECIATION
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'asset_depreciation'
    )
    &&
    dashboardColumnExists(
        $conn,
        'asset_depreciation',
        'amount'
    )
) {

    $sql = "
        SELECT
            COALESCE(
                SUM(amount),
                0
            )

        FROM asset_depreciation

        WHERE business_id = ?
    ";

    $params = [
        $businessId
    ];

    if (
        dashboardColumnExists(
            $conn,
            'asset_depreciation',
            'depreciation_date'
        )
    ) {

        $sql .= "
            AND YEAR(depreciation_date)
                = YEAR(CURDATE())

            AND MONTH(depreciation_date)
                = MONTH(CURDATE())
        ";
    }

    $monthlyDepreciation =
        dashboardValue(
            $conn,
            $sql,
            $params,
            0
        );
}


/*
|--------------------------------------------------------------------------
| GROSS PROFIT
|--------------------------------------------------------------------------
| Hadda fallback:
| Sales - Purchases - Expenses.
|
| Markaan accounting + COGS dhisno waxaa lagu beddelayaa:
| Sales Revenue - Cost of Goods Sold.
|--------------------------------------------------------------------------
*/

$grossProfit =
    (float) $totalSales
    -
    (float) $totalPurchases
    -
    (float) $totalExpenses;


/*
|--------------------------------------------------------------------------
| ZAKAT ESTIMATE
|--------------------------------------------------------------------------
| Dashboard estimate only.
| Final Zakat module-ka wuxuu yeelan doonaa calculation configurable ah.
|--------------------------------------------------------------------------
*/

$zakatBase =
    max(
        0,
        (float) $cashBalance
        +
        (float) $stockValue
        +
        (float) $customerDebt
        -
        (float) $supplierDebt
    );

$zakatEstimate =
    $zakatBase * 0.025;
/*
|--------------------------------------------------------------------------
| DASHBOARD OVERVIEW CHART
|--------------------------------------------------------------------------
| Dashboard data is visible normally; permissions only control navigation links.
|--------------------------------------------------------------------------
*/

$overviewLabels = [
    'Sales',
    'Purchases',
    'Expenses',
    'Profit'
];

$overviewValues = [
    (float) $totalSales,
    (float) $totalPurchases,
    (float) $totalExpenses,
    (float) $grossProfit
];


/*
|--------------------------------------------------------------------------
| PERMISSION-SAFE QUICK ACTIONS
|--------------------------------------------------------------------------
*/

$quickActions = [
    ['Sales', 'sales.php', 'shopping-bag', 'sales.view'],
    ['Purchases', 'purchases.php', 'shopping-cart', 'purchases.view'],
    ['Products', 'products.php', 'package', 'products.view'],
    ['Customers', 'customers.php', 'users', 'customers.view'],
    ['Suppliers', 'suppliers.php', 'truck', 'suppliers.view'],
    ['Expenses', 'expenses.php', 'wallet', 'expenses.view'],
    ['Assets', 'fixed-assets.php', 'building-2', 'assets.view'],
    ['Reports', 'reports.php', 'bar-chart-3', 'reports.view'],
];

$quickActions = array_values(
    array_filter(
        $quickActions,
        static function (array $action): bool {
            return hasPermission($action[3]);
        }
    )
);


/*
|--------------------------------------------------------------------------
| AUDIT TRAIL
|--------------------------------------------------------------------------
*/

if (
    dashboardTableExists(
        $conn,
        'audit_logs'
    )
) {

    $recentActivities =
        dashboardRows(
            $conn,
            "
                SELECT
                    action,
                    module,
                    description,
                    created_at

                FROM audit_logs

                WHERE business_id = ?

                ORDER BY id DESC

                LIMIT 5
            ",
            [
                $businessId
            ]
        );
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

$flashMessage =
    $_SESSION['flash_message']
    ?? null;

$flashType =
    $_SESSION['flash_type']
    ?? 'success';

unset(
    $_SESSION['flash_message'],
    $_SESSION['flash_type']
);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= dashboardE($businessName) ?>
        Management System
    </title>


    <!-- FAVICON -->

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
    >


    <!-- TAILWIND -->

    <script src="https://cdn.tailwindcss.com"></script>


    <script>
        tailwind.config = {

            darkMode: 'class',

            theme: {

                extend: {

                    boxShadow: {

                        soft:
                            '0 10px 35px rgba(15,23,42,.06)'

                    }

                }

            }

        }
    </script>


    <!-- LUCIDE -->

    <script src="https://unpkg.com/lucide@latest"></script>


    <!-- CHART JS -->

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


    <!-- SHARED CSS -->

    <link
        rel="stylesheet"
        href="../assets/css/app.css"
    >


    <style>

        body {

            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

        }


        .dashboard-card {

            transition:
                transform .2s ease,
                box-shadow .2s ease;

        }


        .dashboard-card:hover {

            transform:
                translateY(-2px);

            box-shadow:
                0 15px 40px
                rgba(15,23,42,.08);

        }


        .metric-icon {

            width:
                42px;

            height:
                42px;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            border-radius:
                14px;

            flex-shrink:
                0;

        }

    </style>

</head>


<body
    class="
        min-h-screen
        bg-slate-50
        text-slate-700
        transition-colors
        duration-300

        dark:bg-slate-950
        dark:text-slate-200
    "
>


<!-- =====================================================
     SIDEBAR
===================================================== -->

<?php

require_once
    __DIR__
    . '/includes/sidebar.php';

?>


<!-- =====================================================
     MAIN WRAPPER
===================================================== -->

<div
    class="
        min-h-screen
        transition-all
        duration-300
        lg:ml-[270px]
    "
>


    <!-- =================================================
         HEADER
    ================================================= -->

    <?php

    require_once
        __DIR__
        . '/includes/header.php';

    ?>


    <!-- =================================================
         PAGE
    ================================================= -->

    <main
        class="
            px-4
            py-5
            md:px-6
            md:py-6
        "
    >


        <!-- =============================================
             FLASH
        ============================================== -->

        <?php if ($flashMessage): ?>

            <div
                data-business-flash
                class="
                    mb-5
                    flex
                    items-center
                    justify-between
                    gap-3
                    rounded-2xl
                    border
                    px-4
                    py-3
                    text-[11px]
                    shadow-sm
                    transition-all
                    duration-300

                    <?= $flashType === 'error'

                        ? '
                            border-red-200
                            bg-red-50
                            text-red-700

                            dark:border-red-900
                            dark:bg-red-950/40
                            dark:text-red-300
                        '

                        : '
                            border-emerald-200
                            bg-emerald-50
                            text-emerald-700

                            dark:border-emerald-900
                            dark:bg-emerald-950/40
                            dark:text-emerald-300
                        '

                    ?>
                "
            >

                <span
                    class="
                        flex
                        items-center
                        gap-2
                    "
                >

                    <i
                        data-lucide="
                            <?= $flashType === 'error'
                                ? 'circle-alert'
                                : 'circle-check'
                            ?>
                        "
                        class="h-4 w-4"
                    ></i>

                    <?= dashboardE($flashMessage) ?>

                </span>

            </div>

        <?php endif; ?>


        <!-- =============================================
             PAGE HEADING
        ============================================== -->

        <section
            class="
                mb-6
                flex
                flex-col
                gap-4

                lg:flex-row
                lg:items-center
                lg:justify-between
            "
        >

            <div>

                <div
                    class="
                        mb-1
                        flex
                        items-center
                        gap-2
                        text-[10px]
                        font-semibold
                        text-slate-400
                    "
                >

                    <span>
                        <?= dashboardE($branchName) ?>
                    </span>

                    <span>•</span>

                    <span>
                        <?= date('l, d M Y') ?>
                    </span>

                </div>


                <h1
                    class="
                        text-xl
                        font-black
                        tracking-tight
                        text-slate-900

                        dark:text-white

                        md:text-2xl
                    "
                >

                    Welcome back,
                    <?= dashboardE($fullName) ?> 👋

                </h1>


                <p
                    class="
                        mt-1
                        text-[11px]
                        text-slate-500

                        dark:text-slate-400
                    "
                >

                    Here's what's happening with
                    <?= dashboardE($businessName) ?>
                    today.

                </p>

            </div>


            <div
                class="
                    flex
                    flex-wrap
                    items-center
                    gap-2
                "
            >

                <button
                    type="button"
                    data-refresh-page
                    class="
                        inline-flex
                        h-10
                        items-center
                        gap-2
                        rounded-xl
                        border
                        border-slate-200
                        bg-white
                        px-4
                        text-[10px]
                        font-bold
                        text-slate-600
                        shadow-sm
                        transition
                        hover:bg-slate-50

                        dark:border-slate-800
                        dark:bg-slate-900
                        dark:text-slate-300
                        dark:hover:bg-slate-800
                    "
                >

                    <i
                        data-lucide="refresh-cw"
                        class="h-4 w-4"
                    ></i>

                    Refresh

                </button>


                <?php if ($canAddSales): ?>

<a
                    href="sales.php"
                    class="
                        inline-flex
                        h-10
                        items-center
                        gap-2
                        rounded-xl
                        bg-blue-600
                        px-4
                        text-[10px]
                        font-extrabold
                        text-white
                        shadow-lg
                        shadow-blue-600/20
                        transition
                        hover:bg-blue-700
                    "
                >

                    <i
                        data-lucide="plus"
                        class="h-4 w-4"
                    ></i>

                    New Sale

                </a>

<?php endif; ?>

            </div>

        </section>



        <!-- =============================================
             PRIMARY KPI
        ============================================== -->

        <section
            class="
                grid
                grid-cols-1
                gap-4

                sm:grid-cols-2

                xl:grid-cols-4
            "
        >


            <!-- SALES -->

            <div
                class="
                    dashboard-card
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4
                    shadow-soft

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <div
                    class="
                        flex
                        items-start
                        justify-between
                        gap-3
                    "
                >

                    <div>

                        <p
                            class="
                                text-[10px]
                                font-bold
                                text-slate-400
                            "
                        >
                            Sales This Month
                        </p>

                        <h3
                            class="
                                mt-2
                                text-xl
                                font-black
                                text-slate-900

                                dark:text-white
                            "
                        >
                            $
                            <?= dashboardMoney($totalSales) ?>
                        </h3>

                        <p
                            class="
                                mt-2
                                text-[9px]
                                text-slate-400
                            "
                        >
                            Monthly sales revenue
                        </p>

                    </div>


                    <div
                        class="
                            metric-icon
                            bg-emerald-50
                            text-emerald-600

                            dark:bg-emerald-950/50
                            dark:text-emerald-300
                        "
                    >

                        <i
                            data-lucide="shopping-bag"
                            class="h-5 w-5"
                        ></i>

                    </div>

                </div>

            </div>



            <!-- PURCHASES -->

            <div
                class="
                    dashboard-card
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4
                    shadow-soft

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <div
                    class="
                        flex
                        items-start
                        justify-between
                        gap-3
                    "
                >

                    <div>

                        <p
                            class="
                                text-[10px]
                                font-bold
                                text-slate-400
                            "
                        >
                            Purchases This Month
                        </p>

                        <h3
                            class="
                                mt-2
                                text-xl
                                font-black
                                text-slate-900

                                dark:text-white
                            "
                        >
                            $
                            <?= dashboardMoney($totalPurchases) ?>
                        </h3>

                        <p
                            class="
                                mt-2
                                text-[9px]
                                text-slate-400
                            "
                        >
                            Supplier purchases
                        </p>

                    </div>


                    <div
                        class="
                            metric-icon
                            bg-blue-50
                            text-blue-600

                            dark:bg-blue-950/50
                            dark:text-blue-300
                        "
                    >

                        <i
                            data-lucide="shopping-cart"
                            class="h-5 w-5"
                        ></i>

                    </div>

                </div>

            </div>



            <!-- PROFIT -->

            <div
                class="
                    dashboard-card
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4
                    shadow-soft

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <div
                    class="
                        flex
                        items-start
                        justify-between
                        gap-3
                    "
                >

                    <div>

                        <p
                            class="
                                text-[10px]
                                font-bold
                                text-slate-400
                            "
                        >
                            Estimated Profit
                        </p>

                        <h3
                            class="
                                mt-2
                                text-xl
                                font-black

                                <?= $grossProfit >= 0

                                    ? '
                                        text-emerald-600
                                        dark:text-emerald-400
                                    '

                                    : '
                                        text-red-600
                                        dark:text-red-400
                                    '

                                ?>
                            "
                        >
                            $
                            <?= dashboardMoney($grossProfit) ?>
                        </h3>

                        <p
                            class="
                                mt-2
                                text-[9px]
                                text-slate-400
                            "
                        >
                            Current dashboard estimate
                        </p>

                    </div>


                    <div
                        class="
                            metric-icon
                            bg-violet-50
                            text-violet-600

                            dark:bg-violet-950/50
                            dark:text-violet-300
                        "
                    >

                        <i
                            data-lucide="chart-no-axes-combined"
                            class="h-5 w-5"
                        ></i>

                    </div>

                </div>

            </div>



            <!-- STOCK -->

            <div
                class="
                    dashboard-card
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4
                    shadow-soft

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <div
                    class="
                        flex
                        items-start
                        justify-between
                        gap-3
                    "
                >

                    <div>

                        <p
                            class="
                                text-[10px]
                                font-bold
                                text-slate-400
                            "
                        >
                            Stock Value
                        </p>

                        <h3
                            class="
                                mt-2
                                text-xl
                                font-black
                                text-slate-900

                                dark:text-white
                            "
                        >
                            $
                            <?= dashboardMoney($stockValue) ?>
                        </h3>

                        <p
                            class="
                                mt-2
                                text-[9px]
                                text-slate-400
                            "
                        >
                            Current inventory value
                        </p>

                    </div>


                    <div
                        class="
                            metric-icon
                            bg-amber-50
                            text-amber-600

                            dark:bg-amber-950/50
                            dark:text-amber-300
                        "
                    >

                        <i
                            data-lucide="boxes"
                            class="h-5 w-5"
                        ></i>

                    </div>

                </div>

            </div>

        </section>



        <!-- =============================================
             SECONDARY KPIS
        ============================================== -->

        <section
            class="
                mt-4
                grid
                grid-cols-2
                gap-3

                md:grid-cols-3

                xl:grid-cols-6
            "
        >


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <p
                    class="
                        text-[9px]
                        font-bold
                        text-slate-400
                    "
                >
                    Customer Debt
                </p>

                <p
                    class="
                        mt-2
                        text-base
                        font-black
                        text-red-600
                    "
                >
                    $
                    <?= dashboardMoney($customerDebt) ?>
                </p>

            </div>


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <p
                    class="
                        text-[9px]
                        font-bold
                        text-slate-400
                    "
                >
                    Supplier Payable
                </p>

                <p
                    class="
                        mt-2
                        text-base
                        font-black
                        text-orange-600
                    "
                >
                    $
                    <?= dashboardMoney($supplierDebt) ?>
                </p>

            </div>


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <p
                    class="
                        text-[9px]
                        font-bold
                        text-slate-400
                    "
                >
                    Expenses
                </p>

                <p
                    class="
                        mt-2
                        text-base
                        font-black
                        text-slate-800

                        dark:text-white
                    "
                >
                    $
                    <?= dashboardMoney($totalExpenses) ?>
                </p>

            </div>


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <p
                    class="
                        text-[9px]
                        font-bold
                        text-slate-400
                    "
                >
                    Cash / Bank / EVC
                </p>

                <p
                    class="
                        mt-2
                        text-base
                        font-black
                        text-blue-600
                    "
                >
                    $
                    <?= dashboardMoney($cashBalance) ?>
                </p>

            </div>


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <p
                    class="
                        text-[9px]
                        font-bold
                        text-slate-400
                    "
                >
                    Fixed Assets
                </p>

                <p
                    class="
                        mt-2
                        text-base
                        font-black
                        text-violet-600
                    "
                >
                    $
                    <?= dashboardMoney($fixedAssets) ?>
                </p>

            </div>


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <p
                    class="
                        text-[9px]
                        font-bold
                        text-slate-400
                    "
                >
                    Zakat Estimate
                </p>

                <p
                    class="
                        mt-2
                        text-base
                        font-black
                        text-emerald-600
                    "
                >
                    $
                    <?= dashboardMoney($zakatEstimate) ?>
                </p>

            </div>

        </section>



        <!-- =============================================
             CHART + STOCK ALERT
        ============================================== -->

        <section
            class="
                mt-5
                grid
                grid-cols-1
                gap-4

                xl:grid-cols-3
            "
        >


            <!-- SALES CHART -->

            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-5
                    shadow-soft

                    dark:border-slate-800
                    dark:bg-slate-900

                    xl:col-span-2
                "
            >

                <div
                    class="
                        mb-5
                        flex
                        items-center
                        justify-between
                        gap-3
                    "
                >

                    <div>

                        <h3
                            class="
                                text-[12px]
                                font-extrabold
                                text-slate-800

                                dark:text-white
                            "
                        >
                            Business Overview
                        </h3>

                        <p
                            class="
                                mt-1
                                text-[9px]
                                text-slate-400
                            "
                        >
                            Only information you are allowed to view
                        </p>

                    </div>


                    <span
                        class="
                            rounded-lg
                            bg-slate-100
                            px-2.5
                            py-1.5
                            text-[9px]
                            font-bold
                            text-slate-500

                            dark:bg-slate-800
                            dark:text-slate-300
                        "
                    >
                        This Month
                    </span>

                </div>


                <div
                    class="
                        relative
                        h-[280px]
                    "
                >

                    <canvas
                        id="businessOverviewChart"
                    ></canvas>

                </div>

            </div>



            <!-- STOCK ALERTS -->

            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-5
                    shadow-soft

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <div
                    class="
                        mb-5
                        flex
                        items-center
                        justify-between
                    "
                >

                    <div>

                        <h3
                            class="
                                text-[12px]
                                font-extrabold
                                text-slate-800

                                dark:text-white
                            "
                        >
                            Stock Alerts
                        </h3>

                        <p
                            class="
                                mt-1
                                text-[9px]
                                text-slate-400
                            "
                        >
                            Inventory warnings
                        </p>

                    </div>


                    <?php if ($canViewStock): ?>

<a
                        href="stock.php"
                        class="
                            text-[9px]
                            font-bold
                            text-blue-600
                            hover:text-blue-700
                        "
                    >
                        View Stock
                    </a>

<?php endif; ?>

                </div>


                <div
                    class="
                        space-y-3
                    "
                >


                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            rounded-xl
                            bg-emerald-50
                            px-3
                            py-3

                            dark:bg-emerald-950/30
                        "
                    >

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <span
                                class="
                                    h-2.5
                                    w-2.5
                                    rounded-full
                                    bg-emerald-500
                                "
                            ></span>

                            <span
                                class="
                                    text-[10px]
                                    font-semibold
                                "
                            >
                                Products
                            </span>

                        </div>


                        <strong
                            class="
                                text-[11px]
                                text-emerald-700

                                dark:text-emerald-300
                            "
                        >
                            <?= (int) $totalProducts ?>
                        </strong>

                    </div>



                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            rounded-xl
                            bg-amber-50
                            px-3
                            py-3

                            dark:bg-amber-950/30
                        "
                    >

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <span
                                class="
                                    h-2.5
                                    w-2.5
                                    rounded-full
                                    bg-amber-500
                                "
                            ></span>

                            <span
                                class="
                                    text-[10px]
                                    font-semibold
                                "
                            >
                                Low Stock
                            </span>

                        </div>


                        <strong
                            class="
                                text-[11px]
                                text-amber-700

                                dark:text-amber-300
                            "
                        >
                            <?= (int) $lowStock ?>
                        </strong>

                    </div>



                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            rounded-xl
                            bg-red-50
                            px-3
                            py-3

                            dark:bg-red-950/30
                        "
                    >

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <span
                                class="
                                    h-2.5
                                    w-2.5
                                    rounded-full
                                    bg-red-500
                                "
                            ></span>

                            <span
                                class="
                                    text-[10px]
                                    font-semibold
                                "
                            >
                                Out of Stock
                            </span>

                        </div>


                        <strong
                            class="
                                text-[11px]
                                text-red-700

                                dark:text-red-300
                            "
                        >
                            <?= (int) $outOfStock ?>
                        </strong>

                    </div>



                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            rounded-xl
                            bg-violet-50
                            px-3
                            py-3

                            dark:bg-violet-950/30
                        "
                    >

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <span
                                class="
                                    h-2.5
                                    w-2.5
                                    rounded-full
                                    bg-violet-500
                                "
                            ></span>

                            <span
                                class="
                                    text-[10px]
                                    font-semibold
                                "
                            >
                                Expiring ≤ 30 Days
                            </span>

                        </div>


                        <strong
                            class="
                                text-[11px]
                                text-violet-700

                                dark:text-violet-300
                            "
                        >
                            <?= (int) $expiringSoon ?>
                        </strong>

                    </div>



                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            rounded-xl
                            bg-rose-50
                            px-3
                            py-3

                            dark:bg-rose-950/30
                        "
                    >

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <span
                                class="
                                    h-2.5
                                    w-2.5
                                    rounded-full
                                    bg-rose-600
                                "
                            ></span>

                            <span
                                class="
                                    text-[10px]
                                    font-semibold
                                "
                            >
                                Expired
                            </span>

                        </div>


                        <strong
                            class="
                                text-[11px]
                                text-rose-700

                                dark:text-rose-300
                            "
                        >
                            <?= (int) $expiredStock ?>
                        </strong>

                    </div>


                </div>

            </div>

        </section>



        <!-- =============================================
             BUSINESS SUMMARY
        ============================================== -->

        <section
            class="
                mt-5
                grid
                grid-cols-1
                gap-4

                md:grid-cols-2

                xl:grid-cols-4
            "
        >


            <?php if ($canViewProducts): ?>

<a
                href="products.php"
                class="
                    dashboard-card
                    flex
                    items-center
                    gap-4
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <span
                    class="
                        metric-icon
                        bg-blue-50
                        text-blue-600

                        dark:bg-blue-950/40
                    "
                >
                    <i
                        data-lucide="package"
                        class="h-5 w-5"
                    ></i>
                </span>

                <div>

                    <p
                        class="
                            text-lg
                            font-black
                            text-slate-900

                            dark:text-white
                        "
                    >
                        <?= (int) $totalProducts ?>
                    </p>

                    <p
                        class="
                            text-[9px]
                            font-semibold
                            text-slate-400
                        "
                    >
                        Products
                    </p>

                </div>

            </a>

<?php endif; ?>



            <?php if ($canViewCustomers): ?>

<a
                href="customers.php"
                class="
                    dashboard-card
                    flex
                    items-center
                    gap-4
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <span
                    class="
                        metric-icon
                        bg-emerald-50
                        text-emerald-600

                        dark:bg-emerald-950/40
                    "
                >
                    <i
                        data-lucide="users"
                        class="h-5 w-5"
                    ></i>
                </span>

                <div>

                    <p
                        class="
                            text-lg
                            font-black
                            text-slate-900

                            dark:text-white
                        "
                    >
                        <?= (int) $totalCustomers ?>
                    </p>

                    <p
                        class="
                            text-[9px]
                            font-semibold
                            text-slate-400
                        "
                    >
                        Customers
                    </p>

                </div>

            </a>

<?php endif; ?>



            <?php if ($canViewSuppliers): ?>

<a
                href="suppliers.php"
                class="
                    dashboard-card
                    flex
                    items-center
                    gap-4
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <span
                    class="
                        metric-icon
                        bg-orange-50
                        text-orange-600

                        dark:bg-orange-950/40
                    "
                >
                    <i
                        data-lucide="truck"
                        class="h-5 w-5"
                    ></i>
                </span>

                <div>

                    <p
                        class="
                            text-lg
                            font-black
                            text-slate-900

                            dark:text-white
                        "
                    >
                        <?= (int) $totalSuppliers ?>
                    </p>

                    <p
                        class="
                            text-[9px]
                            font-semibold
                            text-slate-400
                        "
                    >
                        Suppliers
                    </p>

                </div>

            </a>

<?php endif; ?>



            <?php if ($canViewDepreciation): ?>

<a
                href="depreciation.php"
                class="
                    dashboard-card
                    flex
                    items-center
                    gap-4
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <span
                    class="
                        metric-icon
                        bg-violet-50
                        text-violet-600

                        dark:bg-violet-950/40
                    "
                >
                    <i
                        data-lucide="trending-down"
                        class="h-5 w-5"
                    ></i>
                </span>

                <div>

                    <p
                        class="
                            text-base
                            font-black
                            text-slate-900

                            dark:text-white
                        "
                    >
                        $
                        <?= dashboardMoney($monthlyDepreciation) ?>
                    </p>

                    <p
                        class="
                            text-[9px]
                            font-semibold
                            text-slate-400
                        "
                    >
                        Monthly Depreciation
                    </p>

                </div>

            </a>

<?php endif; ?>


        </section>



        <!-- =============================================
             RECENT SALES / ACTIVITY
        ============================================== -->

        <section
            class="
                mt-5
                grid
                grid-cols-1
                gap-4

                xl:grid-cols-5
            "
        >


            <!-- RECENT SALES -->

            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    shadow-soft

                    dark:border-slate-800
                    dark:bg-slate-900

                    xl:col-span-3
                "
            >

                <div
                    class="
                        flex
                        items-center
                        justify-between
                        border-b
                        border-slate-100
                        px-5
                        py-4

                        dark:border-slate-800
                    "
                >

                    <div>

                        <h3
                            class="
                                text-[12px]
                                font-extrabold
                                text-slate-800

                                dark:text-white
                            "
                        >
                            Recent Sales
                        </h3>

                        <p
                            class="
                                mt-1
                                text-[9px]
                                text-slate-400
                            "
                        >
                            Latest customer transactions
                        </p>

                    </div>


                    <?php if ($canViewSales): ?>

<a
                        href="sales.php"
                        class="
                            text-[9px]
                            font-bold
                            text-blue-600
                        "
                    >
                        View All
                    </a>

<?php endif; ?>

                </div>


                <div
                    class="
                        overflow-x-auto
                    "
                >

                    <table
                        class="
                            w-full
                            text-left
                        "
                    >

                        <thead
                            class="
                                bg-slate-50/80

                                dark:bg-slate-950/50
                            "
                        >

                            <tr
                                class="
                                    text-[9px]
                                    uppercase
                                    tracking-wide
                                    text-slate-400
                                "
                            >

                                <th
                                    class="
                                        px-5
                                        py-3
                                    "
                                >
                                    Invoice
                                </th>

                                <th
                                    class="
                                        px-5
                                        py-3
                                    "
                                >
                                    Customer
                                </th>

                                <th
                                    class="
                                        px-5
                                        py-3
                                    "
                                >
                                    Amount
                                </th>

                                <th
                                    class="
                                        px-5
                                        py-3
                                    "
                                >
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php if (!$recentSales): ?>

                                <tr>

                                    <td
                                        colspan="4"
                                        class="
                                            px-5
                                            py-12
                                            text-center
                                        "
                                    >

                                        <div
                                            class="
                                                mx-auto
                                                flex
                                                h-12
                                                w-12
                                                items-center
                                                justify-center
                                                rounded-2xl
                                                bg-slate-100
                                                text-slate-400

                                                dark:bg-slate-800
                                            "
                                        >

                                            <i
                                                data-lucide="receipt-text"
                                                class="h-5 w-5"
                                            ></i>

                                        </div>


                                        <p
                                            class="
                                                mt-3
                                                text-[10px]
                                                font-bold
                                                text-slate-500
                                            "
                                        >
                                            No sales yet
                                        </p>


                                        <p
                                            class="
                                                mt-1
                                                text-[9px]
                                                text-slate-400
                                            "
                                        >
                                            New sales will appear here.
                                        </p>

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($recentSales as $sale): ?>

                                    <tr
                                        class="
                                            border-t
                                            border-slate-100
                                            text-[10px]

                                            dark:border-slate-800
                                        "
                                    >

                                        <td
                                            class="
                                                px-5
                                                py-3.5
                                                font-bold
                                                text-blue-600
                                            "
                                        >
                                            <?= dashboardE(
                                                $sale['invoice_no']
                                                ?? '-'
                                            ) ?>
                                        </td>

                                        <td
                                            class="
                                                px-5
                                                py-3.5
                                                font-semibold
                                                text-slate-700

                                                dark:text-slate-200
                                            "
                                        >
                                            <?= dashboardE(
                                                $sale['customer_name']
                                                ?? 'Walk-in Customer'
                                            ) ?>
                                        </td>

                                        <td
                                            class="
                                                px-5
                                                py-3.5
                                                font-extrabold
                                                text-slate-800

                                                dark:text-white
                                            "
                                        >
                                            $
                                            <?= dashboardMoney(
                                                $sale['amount']
                                                ?? 0
                                            ) ?>
                                        </td>

                                        <td
                                            class="
                                                px-5
                                                py-3.5
                                            "
                                        >

                                            <span
                                                class="
                                                    rounded-full
                                                    bg-emerald-50
                                                    px-2.5
                                                    py-1
                                                    text-[8px]
                                                    font-bold
                                                    text-emerald-600

                                                    dark:bg-emerald-950/40
                                                "
                                            >
                                                <?= dashboardE(
                                                    $sale['payment_status']
                                                    ?? 'Completed'
                                                ) ?>
                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>



            <!-- ACTIVITY -->

            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    shadow-soft

                    dark:border-slate-800
                    dark:bg-slate-900

                    xl:col-span-2
                "
            >

                <div
                    class="
                        flex
                        items-center
                        justify-between
                        border-b
                        border-slate-100
                        px-5
                        py-4

                        dark:border-slate-800
                    "
                >

                    <div>

                        <h3
                            class="
                                text-[12px]
                                font-extrabold
                                text-slate-800

                                dark:text-white
                            "
                        >
                            Recent Activity
                        </h3>

                        <p
                            class="
                                mt-1
                                text-[9px]
                                text-slate-400
                            "
                        >
                            Latest system actions
                        </p>

                    </div>


                    <?php if ($canViewAudit): ?>

<a
                        href="audit-trail.php"
                        class="
                            text-[9px]
                            font-bold
                            text-blue-600
                        "
                    >
                        View All
                    </a>

<?php endif; ?>

                </div>


                <div
                    class="
                        p-4
                    "
                >

                    <?php if (!$recentActivities): ?>

                        <div
                            class="
                                py-10
                                text-center
                            "
                        >

                            <div
                                class="
                                    mx-auto
                                    flex
                                    h-11
                                    w-11
                                    items-center
                                    justify-center
                                    rounded-2xl
                                    bg-slate-100
                                    text-slate-400

                                    dark:bg-slate-800
                                "
                            >

                                <i
                                    data-lucide="history"
                                    class="h-5 w-5"
                                ></i>

                            </div>


                            <p
                                class="
                                    mt-3
                                    text-[10px]
                                    font-bold
                                    text-slate-500
                                "
                            >
                                No recent activity
                            </p>

                        </div>

                    <?php else: ?>

                        <div
                            class="
                                space-y-2
                            "
                        >

                            <?php foreach ($recentActivities as $activity): ?>

                                <div
                                    class="
                                        flex
                                        gap-3
                                        rounded-xl
                                        p-2.5
                                        transition
                                        hover:bg-slate-50

                                        dark:hover:bg-slate-800
                                    "
                                >

                                    <span
                                        class="
                                            flex
                                            h-8
                                            w-8
                                            shrink-0
                                            items-center
                                            justify-center
                                            rounded-lg
                                            bg-blue-50
                                            text-blue-600

                                            dark:bg-blue-950/40
                                        "
                                    >

                                        <i
                                            data-lucide="activity"
                                            class="h-3.5 w-3.5"
                                        ></i>

                                    </span>


                                    <div
                                        class="
                                            min-w-0
                                            flex-1
                                        "
                                    >

                                        <p
                                            class="
                                                truncate
                                                text-[10px]
                                                font-bold
                                                text-slate-700

                                                dark:text-slate-200
                                            "
                                        >
                                            <?= dashboardE(
                                                $activity['action']
                                                ?? 'Activity'
                                            ) ?>

                                            <?php if (!empty($activity['module'])): ?>

                                                ·
                                                <?= dashboardE(
                                                    $activity['module']
                                                ) ?>

                                            <?php endif; ?>
                                        </p>


                                        <p
                                            class="
                                                mt-0.5
                                                line-clamp-2
                                                text-[9px]
                                                text-slate-400
                                            "
                                        >
                                            <?= dashboardE(
                                                $activity['description']
                                                ?? ''
                                            ) ?>
                                        </p>


                                        <?php if (!empty($activity['created_at'])): ?>

                                            <p
                                                class="
                                                    mt-1
                                                    text-[8px]
                                                    text-slate-400
                                                "
                                            >
                                                <?= dashboardE(
                                                    date(
                                                        'd M Y, h:i A',
                                                        strtotime(
                                                            $activity['created_at']
                                                        )
                                                    )
                                                ) ?>
                                            </p>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </section>



<?php if (!empty($quickActions)): ?>

        <!-- =============================================
             QUICK ACTIONS
        ============================================== -->

        <section
            class="
                mt-5
                rounded-2xl
                border
                border-slate-200
                bg-white
                p-5
                shadow-soft

                dark:border-slate-800
                dark:bg-slate-900
            "
        >

            <div
                class="
                    mb-4
                "
            >

                <h3
                    class="
                        text-[12px]
                        font-extrabold
                        text-slate-800

                        dark:text-white
                    "
                >
                    Quick Actions
                </h3>

                <p
                    class="
                        mt-1
                        text-[9px]
                        text-slate-400
                    "
                >
                    Frequently used operations
                </p>

            </div>


            <div
                class="
                    grid
                    grid-cols-2
                    gap-3

                    md:grid-cols-4

                    xl:grid-cols-8
                "
            >


                <?php
                // $quickActions waxaa lagu diyaariyey PHP-ga kore iyadoo permission la filter-gareeyey.
                ?>


                <?php foreach ($quickActions as $quickAction): ?>

                    <a
                        href="<?= dashboardE(
                            $quickAction[1]
                        ) ?>"
                        class="
                            flex
                            flex-col
                            items-center
                            justify-center
                            gap-2
                            rounded-xl
                            border
                            border-slate-100
                            bg-slate-50
                            px-3
                            py-4
                            text-center
                            transition
                            hover:border-blue-200
                            hover:bg-blue-50
                            hover:text-blue-600

                            dark:border-slate-800
                            dark:bg-slate-950
                            dark:hover:border-blue-900
                            dark:hover:bg-blue-950/30
                        "
                    >

                        <i
                            data-lucide="<?= dashboardE(
                                $quickAction[2]
                            ) ?>"
                            class="h-5 w-5"
                        ></i>

                        <span
                            class="
                                text-[9px]
                                font-bold
                            "
                        >
                            <?= dashboardE(
                                $quickAction[0]
                            ) ?>
                        </span>

                    </a>

                <?php endforeach; ?>


            </div>

        </section>



<?php endif; ?>

    </main>


    <!-- =================================================
         FOOTER
    ================================================= -->

    <?php

    require_once
        __DIR__
        . '/includes/footer.php';

    ?>


</div>


<!-- =====================================================
     CHART
===================================================== -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        if (
            typeof lucide !== 'undefined'
        ) {

            lucide.createIcons();

        }


        const canvas =
            document.getElementById(
                'businessOverviewChart'
            );


        if (
            canvas
            &&
            typeof Chart !== 'undefined'
        ) {

            new Chart(
                canvas,
                {

                    type:
                        'bar',

                    data: {

                        labels:
                            <?= json_encode(
                                $overviewLabels,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ) ?>,

                        datasets: [

                            {

                                label:
                                    'Amount',

                                data:
                                    <?= json_encode(
                                        $overviewValues,
                                        JSON_NUMERIC_CHECK
                                    ) ?>,

                                borderWidth:
                                    0,

                                borderRadius:
                                    8,

                                backgroundColor: [

                                    'rgba(16,185,129,.75)',

                                    'rgba(37,99,235,.75)',

                                    'rgba(245,158,11,.75)',

                                    'rgba(124,58,237,.75)'

                                ]

                            }

                        ]

                    },

                    options: {

                        responsive:
                            true,

                        maintainAspectRatio:
                            false,

                        plugins: {

                            legend: {

                                display:
                                    false

                            }

                        },

                        scales: {

                            x: {

                                grid: {

                                    display:
                                        false

                                },

                                ticks: {

                                    font: {

                                        size:
                                            10

                                    },

                                    color:
                                        '#94a3b8'

                                }

                            },

                            y: {

                                beginAtZero:
                                    true,

                                grid: {

                                    color:
                                        'rgba(148,163,184,.12)'

                                },

                                ticks: {

                                    font: {

                                        size:
                                            9

                                    },

                                    color:
                                        '#94a3b8',

                                    callback:
                                        function (
                                            value
                                        ) {

                                            return (
                                                '$'
                                                +
                                                value
                                            );

                                        }

                                }

                            }

                        }

                    }

                }
            );

        }

    }
);

</script>


</body>

</html>
