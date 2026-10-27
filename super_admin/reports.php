<?php

session_start();

require_once '../config/database.php';

/*
|--------------------------------------------------------------------------
| AUTH PROTECTION
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'Super Admin') {
    header('Location: ../dashboard.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$fromDate = trim($_GET['from_date'] ?? '');
$toDate   = trim($_GET['to_date'] ?? '');
$status   = trim($_GET['status'] ?? '');
$search   = trim($_GET['search'] ?? '');


/*
|--------------------------------------------------------------------------
| AUTO UPDATE EXPIRED SUBSCRIPTIONS
|--------------------------------------------------------------------------
*/

$conn->exec("
    UPDATE subscriptions
    SET status = 'Expired'
    WHERE expiry_date < CURDATE()
      AND status = 'Active'
");


/*
|--------------------------------------------------------------------------
| SYSTEM STATISTICS
|--------------------------------------------------------------------------
*/

$totalBusinesses = (int) $conn->query("
    SELECT COUNT(*)
    FROM businesses
")->fetchColumn();


$activeBusinesses = (int) $conn->query("
    SELECT COUNT(*)
    FROM businesses
    WHERE status = 'Active'
")->fetchColumn();


$suspendedBusinesses = (int) $conn->query("
    SELECT COUNT(*)
    FROM businesses
    WHERE status = 'Suspended'
")->fetchColumn();


$expiredBusinesses = (int) $conn->query("
    SELECT COUNT(*)
    FROM businesses
    WHERE status = 'Expired'
")->fetchColumn();


$totalBranches = (int) $conn->query("
    SELECT COUNT(*)
    FROM branches
")->fetchColumn();


$totalUsers = (int) $conn->query("
    SELECT COUNT(*)
    FROM users
")->fetchColumn();


$totalSubscriptions = (int) $conn->query("
    SELECT COUNT(*)
    FROM subscriptions
")->fetchColumn();


$activeSubscriptions = (int) $conn->query("
    SELECT COUNT(*)
    FROM subscriptions
    WHERE status = 'Active'
")->fetchColumn();


$expiredSubscriptions = (int) $conn->query("
    SELECT COUNT(*)
    FROM subscriptions
    WHERE status = 'Expired'
")->fetchColumn();


$totalRevenue = (float) $conn->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM subscriptions
")->fetchColumn();


$currentMonthRevenue = (float) $conn->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM subscriptions
    WHERE YEAR(created_at) = YEAR(CURDATE())
      AND MONTH(created_at) = MONTH(CURDATE())
")->fetchColumn();


/*
|--------------------------------------------------------------------------
| SUBSCRIPTIONS EXPIRING SOON
|--------------------------------------------------------------------------
*/

$expiringSoonStmt = $conn->query("
    SELECT
        s.id,
        s.business_id,
        s.expiry_date,
        s.amount,
        b.business_name,
        sp.plan_name,
        DATEDIFF(s.expiry_date, CURDATE()) AS days_left

    FROM subscriptions s

    INNER JOIN businesses b
        ON b.id = s.business_id

    INNER JOIN subscription_plans sp
        ON sp.id = s.plan_id

    WHERE s.status = 'Active'
      AND s.expiry_date BETWEEN CURDATE()
      AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)

    ORDER BY s.expiry_date ASC

    LIMIT 10
");

$expiringSoon = $expiringSoonStmt->fetchAll();


/*
|--------------------------------------------------------------------------
| BUILD REPORT QUERY
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($search !== '') {

    $where[] = "
        (
            b.business_name LIKE ?
            OR b.phone LIKE ?
            OR b.email LIKE ?
            OR sp.plan_name LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}


if (
    in_array(
        $status,
        ['Active', 'Expired', 'Suspended'],
        true
    )
) {

    $where[] = "s.status = ?";
    $params[] = $status;
}


if ($fromDate !== '') {

    $where[] = "s.start_date >= ?";
    $params[] = $fromDate;
}


if ($toDate !== '') {

    $where[] = "s.start_date <= ?";
    $params[] = $toDate;
}


$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}


/*
|--------------------------------------------------------------------------
| SUBSCRIPTION REPORT
|--------------------------------------------------------------------------
*/

$reportStmt = $conn->prepare("
    SELECT
        s.id,
        s.business_id,
        s.start_date,
        s.expiry_date,
        s.amount,
        s.status,

        b.business_name,
        b.phone,

        sp.plan_name,
        sp.duration_months,
        sp.max_branches,

        (
            SELECT COUNT(*)
            FROM branches br
            WHERE br.business_id = b.id
        ) AS branch_count,

        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.business_id = b.id
        ) AS user_count

    FROM subscriptions s

    INNER JOIN businesses b
        ON b.id = s.business_id

    INNER JOIN subscription_plans sp
        ON sp.id = s.plan_id

    $whereSql

    ORDER BY s.id DESC
");

$reportStmt->execute($params);

$reportRows = $reportStmt->fetchAll();


/*
|--------------------------------------------------------------------------
| FILTERED TOTAL
|--------------------------------------------------------------------------
*/

$filteredRevenue = 0;

foreach ($reportRows as $row) {
    $filteredRevenue += (float) $row['amount'];
}


/*
|--------------------------------------------------------------------------
| BUSINESS STATUS SUMMARY
|--------------------------------------------------------------------------
*/

$businessStatusStmt = $conn->query("
    SELECT
        status,
        COUNT(*) AS total
    FROM businesses
    GROUP BY status
");

$businessStatusSummary = [
    'Active' => 0,
    'Suspended' => 0,
    'Expired' => 0
];

foreach ($businessStatusStmt->fetchAll() as $row) {

    $businessStatusSummary[
        $row['status']
    ] = (int) $row['total'];
}


/*
|--------------------------------------------------------------------------
| PLAN SUMMARY
|--------------------------------------------------------------------------
*/

$planSummaryStmt = $conn->query("
    SELECT
        sp.plan_name,
        COUNT(s.id) AS total_subscriptions,
        COALESCE(SUM(s.amount), 0) AS revenue

    FROM subscription_plans sp

    LEFT JOIN subscriptions s
        ON s.plan_id = sp.id

    GROUP BY
        sp.id,
        sp.plan_name

    ORDER BY total_subscriptions DESC
");

$planSummary = $planSummaryStmt->fetchAll();


/*
|--------------------------------------------------------------------------
| MONTHLY REVENUE - LAST 6 MONTHS
|--------------------------------------------------------------------------
*/

$monthlyStmt = $conn->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month_key,
        DATE_FORMAT(created_at, '%b %Y') AS month_name,
        COALESCE(SUM(amount), 0) AS revenue

    FROM subscriptions

    WHERE created_at >= DATE_SUB(
        CURDATE(),
        INTERVAL 5 MONTH
    )

    GROUP BY
        YEAR(created_at),
        MONTH(created_at)

    ORDER BY
        YEAR(created_at),
        MONTH(created_at)
");

$monthlyRevenue = $monthlyStmt->fetchAll();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>System Reports</title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
    >

    <script src="https://cdn.tailwindcss.com"></script>

    <script src="https://unpkg.com/lucide@latest"></script>

    <link
        rel="stylesheet"
        href="../assets/css/app.css"
    >

    <style>

        @media print {

            #sidebar,
            #sidebarOverlay,
            header,
            .no-print,
            footer {
                display: none !important;
            }

            .lg\:pl-72 {
                padding-left: 0 !important;
            }

            body {
                background: white !important;
            }

            main {
                padding: 0 !important;
            }

            .print-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
        }

    </style>

</head>

<body class="bg-slate-100 text-slate-800">

<div class="min-h-screen">

    <?php require 'includes/sidebar.php'; ?>


    <div class="lg:pl-72">

        <?php require 'includes/header.php'; ?>


        <main class="page-enter p-4 sm:p-6 lg:p-8">


            <!-- PAGE HEADER -->

            <div
                class="
                    no-print
                    mb-6
                    flex
                    flex-col
                    gap-4
                    xl:flex-row
                    xl:items-center
                    xl:justify-between
                "
            >

                <div>

                    <p
                        class="
                            text-sm
                            font-semibold
                            uppercase
                            tracking-wider
                            text-blue-600
                        "
                    >
                        Analytics
                    </p>

                    <h1
                        class="
                            mt-1
                            text-2xl
                            font-bold
                            tracking-tight
                            text-slate-900
                            sm:text-3xl
                        "
                    >
                        System Reports
                    </h1>

                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        Businesses, subscriptions, revenue,
                        branches and users overview.
                    </p>

                </div>


                <div class="flex flex-wrap gap-3">

                    <button
                        type="button"
                        onclick="window.print()"
                        class="
                            inline-flex
                            items-center
                            gap-2
                            rounded-xl
                            border
                            border-slate-200
                            bg-white
                            px-4
                            py-2.5
                            text-sm
                            font-semibold
                            text-slate-700
                            shadow-sm
                            transition
                            hover:bg-slate-50
                        "
                    >
                        <i
                            data-lucide="printer"
                            class="h-4 w-4"
                        ></i>

                        Print Report
                    </button>


                    <a
                        href="subscriptions.php"
                        class="
                            inline-flex
                            items-center
                            gap-2
                            rounded-xl
                            bg-blue-600
                            px-4
                            py-2.5
                            text-sm
                            font-semibold
                            text-white
                            shadow-sm
                            transition
                            hover:bg-blue-700
                        "
                    >
                        <i
                            data-lucide="credit-card"
                            class="h-4 w-4"
                        ></i>

                        Subscriptions
                    </a>

                </div>

            </div>


            <!-- MAIN KPIs -->

            <div
                class="
                    mb-6
                    grid
                    grid-cols-1
                    gap-4
                    sm:grid-cols-2
                    xl:grid-cols-4
                "
            >

                <!-- Businesses -->

                <div
                    class="
                        dashboard-card
                        print-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
                        shadow-sm
                    "
                >

                    <div
                        class="
                            flex
                            items-center
                            justify-between
                        "
                    >

                        <div>

                            <p
                                class="
                                    text-sm
                                    font-medium
                                    text-slate-500
                                "
                            >
                                Total Businesses
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-slate-900
                                "
                            >
                                <?= number_format($totalBusinesses) ?>
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-xs
                                    text-emerald-600
                                "
                            >
                                <?= number_format($activeBusinesses) ?>
                                active
                            </p>

                        </div>


                        <div
                            class="
                                flex
                                h-12
                                w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-blue-50
                                text-blue-600
                            "
                        >
                            <i
                                data-lucide="building-2"
                                class="h-6 w-6"
                            ></i>
                        </div>

                    </div>

                </div>


                <!-- Revenue -->

                <div
                    class="
                        dashboard-card
                        print-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
                        shadow-sm
                    "
                >

                    <div
                        class="
                            flex
                            items-center
                            justify-between
                        "
                    >

                        <div>

                            <p
                                class="
                                    text-sm
                                    font-medium
                                    text-slate-500
                                "
                            >
                                Total Revenue
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-emerald-600
                                "
                            >
                                $<?= number_format(
                                    $totalRevenue,
                                    2
                                ) ?>
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-xs
                                    text-slate-500
                                "
                            >
                                This month:
                                $<?= number_format(
                                    $currentMonthRevenue,
                                    2
                                ) ?>
                            </p>

                        </div>


                        <div
                            class="
                                flex
                                h-12
                                w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-emerald-50
                                text-emerald-600
                            "
                        >
                            <i
                                data-lucide="dollar-sign"
                                class="h-6 w-6"
                            ></i>
                        </div>

                    </div>

                </div>


                <!-- Branches -->

                <div
                    class="
                        dashboard-card
                        print-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
                        shadow-sm
                    "
                >

                    <div
                        class="
                            flex
                            items-center
                            justify-between
                        "
                    >

                        <div>

                            <p
                                class="
                                    text-sm
                                    font-medium
                                    text-slate-500
                                "
                            >
                                Total Branches
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-slate-900
                                "
                            >
                                <?= number_format($totalBranches) ?>
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-xs
                                    text-slate-500
                                "
                            >
                                Across all businesses
                            </p>

                        </div>


                        <div
                            class="
                                flex
                                h-12
                                w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-violet-50
                                text-violet-600
                            "
                        >
                            <i
                                data-lucide="git-branch"
                                class="h-6 w-6"
                            ></i>
                        </div>

                    </div>

                </div>


                <!-- Users -->

                <div
                    class="
                        dashboard-card
                        print-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
                        shadow-sm
                    "
                >

                    <div
                        class="
                            flex
                            items-center
                            justify-between
                        "
                    >

                        <div>

                            <p
                                class="
                                    text-sm
                                    font-medium
                                    text-slate-500
                                "
                            >
                                Total Users
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-slate-900
                                "
                            >
                                <?= number_format($totalUsers) ?>
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-xs
                                    text-slate-500
                                "
                            >
                                System-wide accounts
                            </p>

                        </div>


                        <div
                            class="
                                flex
                                h-12
                                w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-amber-50
                                text-amber-600
                            "
                        >
                            <i
                                data-lucide="users"
                                class="h-6 w-6"
                            ></i>
                        </div>

                    </div>

                </div>

            </div>


            <!-- STATUS / SUBSCRIPTION KPI -->

            <div
                class="
                    mb-6
                    grid
                    grid-cols-1
                    gap-4
                    md:grid-cols-2
                    xl:grid-cols-4
                "
            >

                <div
                    class="
                        rounded-2xl
                        border
                        border-emerald-200
                        bg-emerald-50
                        p-5
                    "
                >

                    <p
                        class="
                            text-sm
                            font-semibold
                            text-emerald-700
                        "
                    >
                        Active Businesses
                    </p>

                    <p
                        class="
                            mt-2
                            text-2xl
                            font-bold
                            text-emerald-800
                        "
                    >
                        <?= $activeBusinesses ?>
                    </p>

                </div>


                <div
                    class="
                        rounded-2xl
                        border
                        border-red-200
                        bg-red-50
                        p-5
                    "
                >

                    <p
                        class="
                            text-sm
                            font-semibold
                            text-red-700
                        "
                    >
                        Suspended Businesses
                    </p>

                    <p
                        class="
                            mt-2
                            text-2xl
                            font-bold
                            text-red-800
                        "
                    >
                        <?= $suspendedBusinesses ?>
                    </p>

                </div>


                <div
                    class="
                        rounded-2xl
                        border
                        border-orange-200
                        bg-orange-50
                        p-5
                    "
                >

                    <p
                        class="
                            text-sm
                            font-semibold
                            text-orange-700
                        "
                    >
                        Expired Businesses
                    </p>

                    <p
                        class="
                            mt-2
                            text-2xl
                            font-bold
                            text-orange-800
                        "
                    >
                        <?= $expiredBusinesses ?>
                    </p>

                </div>


                <div
                    class="
                        rounded-2xl
                        border
                        border-blue-200
                        bg-blue-50
                        p-5
                    "
                >

                    <p
                        class="
                            text-sm
                            font-semibold
                            text-blue-700
                        "
                    >
                        Active Subscriptions
                    </p>

                    <p
                        class="
                            mt-2
                            text-2xl
                            font-bold
                            text-blue-800
                        "
                    >
                        <?= $activeSubscriptions ?>
                    </p>

                    <p
                        class="
                            mt-1
                            text-xs
                            text-blue-600
                        "
                    >
                        <?= $expiredSubscriptions ?>
                        expired
                    </p>

                </div>

            </div>


            <!-- PLAN SUMMARY + MONTHLY REVENUE -->

            <div
                class="
                    mb-6
                    grid
                    grid-cols-1
                    gap-6
                    xl:grid-cols-2
                "
            >

                <!-- Plan Summary -->

                <div
                    class="
                        print-card
                        rounded-3xl
                        border
                        border-slate-200
                        bg-white
                        shadow-sm
                    "
                >

                    <div
                        class="
                            border-b
                            border-slate-200
                            px-5
                            py-5
                        "
                    >

                        <h2
                            class="
                                text-lg
                                font-bold
                                text-slate-900
                            "
                        >
                            Subscription Plan Performance
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                            "
                        >
                            Revenue and subscriptions by plan.
                        </p>

                    </div>


                    <div class="divide-y divide-slate-100">

                        <?php if (!$planSummary): ?>

                            <div
                                class="
                                    p-8
                                    text-center
                                    text-sm
                                    text-slate-500
                                "
                            >
                                No plan data available.
                            </div>

                        <?php else: ?>

                            <?php foreach ($planSummary as $plan): ?>

                                <div
                                    class="
                                        flex
                                        items-center
                                        justify-between
                                        gap-4
                                        px-5
                                        py-4
                                    "
                                >

                                    <div>

                                        <p
                                            class="
                                                font-semibold
                                                text-slate-800
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $plan['plan_name']
                                            ) ?>
                                        </p>

                                        <p
                                            class="
                                                mt-1
                                                text-xs
                                                text-slate-500
                                            "
                                        >
                                            <?= number_format(
                                                $plan['total_subscriptions']
                                            ) ?>
                                            subscriptions
                                        </p>

                                    </div>


                                    <div class="text-right">

                                        <p
                                            class="
                                                font-bold
                                                text-emerald-600
                                            "
                                        >
                                            $<?= number_format(
                                                $plan['revenue'],
                                                2
                                            ) ?>
                                        </p>

                                        <p
                                            class="
                                                text-xs
                                                text-slate-400
                                            "
                                        >
                                            Revenue
                                        </p>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- Monthly Revenue -->

                <div
                    class="
                        print-card
                        rounded-3xl
                        border
                        border-slate-200
                        bg-white
                        p-5
                        shadow-sm
                    "
                >

                    <div class="mb-6">

                        <h2
                            class="
                                text-lg
                                font-bold
                                text-slate-900
                            "
                        >
                            Monthly Revenue
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                            "
                        >
                            Subscription income overview.
                        </p>

                    </div>


                    <?php

                    $maxRevenue = 0;

                    foreach ($monthlyRevenue as $month) {

                        if (
                            (float) $month['revenue']
                            > $maxRevenue
                        ) {
                            $maxRevenue =
                                (float) $month['revenue'];
                        }
                    }

                    ?>


                    <div
                        class="
                            flex
                            h-64
                            items-end
                            gap-3
                            overflow-x-auto
                            pb-2
                        "
                    >

                        <?php if (!$monthlyRevenue): ?>

                            <div
                                class="
                                    flex
                                    h-full
                                    w-full
                                    items-center
                                    justify-center
                                    text-sm
                                    text-slate-500
                                "
                            >
                                Revenue data not available.
                            </div>

                        <?php else: ?>

                            <?php foreach (
                                $monthlyRevenue
                                as $month
                            ): ?>

                                <?php

                                $height = 10;

                                if ($maxRevenue > 0) {

                                    $height =
                                        (
                                            (float) $month['revenue']
                                            / $maxRevenue
                                        ) * 180;

                                    $height = max(
                                        10,
                                        $height
                                    );
                                }

                                ?>

                                <div
                                    class="
                                        flex
                                        min-w-[70px]
                                        flex-1
                                        flex-col
                                        items-center
                                        justify-end
                                    "
                                >

                                    <span
                                        class="
                                            mb-2
                                            text-xs
                                            font-semibold
                                            text-slate-600
                                        "
                                    >
                                        $<?= number_format(
                                            $month['revenue'],
                                            0
                                        ) ?>
                                    </span>


                                    <div
                                        class="
                                            w-full
                                            max-w-[46px]
                                            rounded-t-xl
                                            bg-blue-600
                                            transition-all
                                            duration-500
                                            hover:bg-blue-700
                                        "
                                        style="
                                            height:
                                            <?= (int) $height ?>px;
                                        "
                                    ></div>


                                    <span
                                        class="
                                            mt-3
                                            text-[11px]
                                            font-medium
                                            text-slate-500
                                        "
                                    >
                                        <?= htmlspecialchars(
                                            $month['month_name']
                                        ) ?>
                                    </span>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- EXPIRING SOON -->

            <div
                class="
                    mb-6
                    print-card
                    overflow-hidden
                    rounded-3xl
                    border
                    border-slate-200
                    bg-white
                    shadow-sm
                "
            >

                <div
                    class="
                        flex
                        flex-col
                        gap-3
                        border-b
                        border-slate-200
                        px-5
                        py-5
                        sm:flex-row
                        sm:items-center
                        sm:justify-between
                    "
                >

                    <div>

                        <h2
                            class="
                                text-lg
                                font-bold
                                text-slate-900
                            "
                        >
                            Expiring Soon
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                            "
                        >
                            Active subscriptions expiring
                            within 30 days.
                        </p>

                    </div>


                    <a
                        href="subscriptions.php"
                        class="
                            no-print
                            text-sm
                            font-semibold
                            text-blue-600
                            hover:text-blue-700
                        "
                    >
                        Manage subscriptions
                    </a>

                </div>


                <div class="overflow-x-auto">

                    <table
                        class="
                            min-w-full
                            divide-y
                            divide-slate-200
                        "
                    >

                        <thead class="bg-slate-50">

                            <tr>

                                <th
                                    class="
                                        px-5
                                        py-3
                                        text-left
                                        text-xs
                                        font-semibold
                                        uppercase
                                        tracking-wider
                                        text-slate-500
                                    "
                                >
                                    Business
                                </th>

                                <th
                                    class="
                                        px-5
                                        py-3
                                        text-left
                                        text-xs
                                        font-semibold
                                        uppercase
                                        tracking-wider
                                        text-slate-500
                                    "
                                >
                                    Plan
                                </th>

                                <th
                                    class="
                                        px-5
                                        py-3
                                        text-left
                                        text-xs
                                        font-semibold
                                        uppercase
                                        tracking-wider
                                        text-slate-500
                                    "
                                >
                                    Expiry
                                </th>

                                <th
                                    class="
                                        px-5
                                        py-3
                                        text-left
                                        text-xs
                                        font-semibold
                                        uppercase
                                        tracking-wider
                                        text-slate-500
                                    "
                                >
                                    Remaining
                                </th>

                            </tr>

                        </thead>


                        <tbody
                            class="
                                divide-y
                                divide-slate-100
                                bg-white
                            "
                        >

                            <?php if (!$expiringSoon): ?>

                                <tr>

                                    <td
                                        colspan="4"
                                        class="
                                            px-5
                                            py-10
                                            text-center
                                            text-sm
                                            text-slate-500
                                        "
                                    >
                                        No subscriptions are expiring soon.
                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach (
                                    $expiringSoon
                                    as $item
                                ): ?>

                                    <tr>

                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                            "
                                        >

                                            <a
                                                href="business-view.php?id=<?= (int) $item['business_id'] ?>"
                                                class="
                                                    font-semibold
                                                    text-slate-800
                                                    hover:text-blue-600
                                                "
                                            >
                                                <?= htmlspecialchars(
                                                    $item['business_name']
                                                ) ?>
                                            </a>

                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-sm
                                                text-slate-600
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $item['plan_name']
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-sm
                                                text-slate-600
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $item['expiry_date']
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                            "
                                        >

                                            <?php

                                            $daysLeft =
                                                (int) $item['days_left'];

                                            if ($daysLeft <= 7) {

                                                $badge =
                                                    'bg-red-50 text-red-700';

                                            } elseif (
                                                $daysLeft <= 15
                                            ) {

                                                $badge =
                                                    'bg-orange-50 text-orange-700';

                                            } else {

                                                $badge =
                                                    'bg-amber-50 text-amber-700';
                                            }

                                            ?>

                                            <span
                                                class="
                                                    rounded-full
                                                    px-2.5
                                                    py-1
                                                    text-xs
                                                    font-semibold
                                                    <?= $badge ?>
                                                "
                                            >
                                                <?= $daysLeft ?>
                                                days
                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>


            <!-- FILTERED REPORT -->

            <div
                class="
                    print-card
                    overflow-hidden
                    rounded-3xl
                    border
                    border-slate-200
                    bg-white
                    shadow-sm
                "
            >

                <div
                    class="
                        no-print
                        border-b
                        border-slate-200
                        p-5
                    "
                >

                    <div class="mb-5">

                        <h2
                            class="
                                text-lg
                                font-bold
                                text-slate-900
                            "
                        >
                            Subscription Report
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                            "
                        >
                            Search and filter subscription records.
                        </p>

                    </div>


                    <form
                        method="GET"
                        class="
                            grid
                            grid-cols-1
                            gap-3
                            md:grid-cols-2
                            xl:grid-cols-5
                        "
                    >

                        <div class="xl:col-span-1">

                            <input
                                type="text"
                                name="search"
                                value="<?= htmlspecialchars($search) ?>"
                                placeholder="Search business..."
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    px-4
                                    py-2.5
                                    text-sm
                                    outline-none
                                    transition
                                    focus:border-blue-500
                                    focus:ring-4
                                    focus:ring-blue-500/10
                                "
                            >

                        </div>


                        <div>

                            <select
                                name="status"
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    bg-white
                                    px-4
                                    py-2.5
                                    text-sm
                                    outline-none
                                    focus:border-blue-500
                                    focus:ring-4
                                    focus:ring-blue-500/10
                                "
                            >

                                <option value="">
                                    All Status
                                </option>

                                <option
                                    value="Active"
                                    <?= $status === 'Active'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Active
                                </option>

                                <option
                                    value="Expired"
                                    <?= $status === 'Expired'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Expired
                                </option>

                                <option
                                    value="Suspended"
                                    <?= $status === 'Suspended'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Suspended
                                </option>

                            </select>

                        </div>


                        <div>

                            <input
                                type="date"
                                name="from_date"
                                value="<?= htmlspecialchars($fromDate) ?>"
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    px-4
                                    py-2.5
                                    text-sm
                                    outline-none
                                    focus:border-blue-500
                                    focus:ring-4
                                    focus:ring-blue-500/10
                                "
                            >

                        </div>


                        <div>

                            <input
                                type="date"
                                name="to_date"
                                value="<?= htmlspecialchars($toDate) ?>"
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    px-4
                                    py-2.5
                                    text-sm
                                    outline-none
                                    focus:border-blue-500
                                    focus:ring-4
                                    focus:ring-blue-500/10
                                "
                            >

                        </div>


                        <div class="flex gap-2">

                            <button
                                type="submit"
                                class="
                                    flex-1
                                    rounded-xl
                                    bg-blue-600
                                    px-4
                                    py-2.5
                                    text-sm
                                    font-semibold
                                    text-white
                                    transition
                                    hover:bg-blue-700
                                "
                            >
                                Filter
                            </button>


                            <a
                                href="reports.php"
                                class="
                                    inline-flex
                                    items-center
                                    justify-center
                                    rounded-xl
                                    border
                                    border-slate-200
                                    bg-white
                                    px-3
                                    text-slate-500
                                    transition
                                    hover:bg-slate-50
                                "
                                title="Clear filters"
                            >
                                <i
                                    data-lucide="rotate-ccw"
                                    class="h-4 w-4"
                                ></i>
                            </a>

                        </div>

                    </form>

                </div>


                <!-- PRINT TITLE -->

                <div class="hidden print:block p-5">

                    <h2 class="text-xl font-bold">
                        System Subscription Report
                    </h2>

                    <p class="mt-1 text-sm">
                        Generated:
                        <?= date('Y-m-d H:i') ?>
                    </p>

                </div>


                <!-- REPORT SUMMARY -->

                <div
                    class="
                        grid
                        grid-cols-1
                        gap-3
                        border-b
                        border-slate-200
                        bg-slate-50
                        px-5
                        py-4
                        sm:grid-cols-3
                    "
                >

                    <div>

                        <p class="text-xs text-slate-500">
                            Records
                        </p>

                        <p
                            class="
                                mt-1
                                font-bold
                                text-slate-800
                            "
                        >
                            <?= number_format(
                                count($reportRows)
                            ) ?>
                        </p>

                    </div>


                    <div>

                        <p class="text-xs text-slate-500">
                            Filtered Revenue
                        </p>

                        <p
                            class="
                                mt-1
                                font-bold
                                text-emerald-600
                            "
                        >
                            $<?= number_format(
                                $filteredRevenue,
                                2
                            ) ?>
                        </p>

                    </div>


                    <div>

                        <p class="text-xs text-slate-500">
                            Total Subscriptions
                        </p>

                        <p
                            class="
                                mt-1
                                font-bold
                                text-slate-800
                            "
                        >
                            <?= $totalSubscriptions ?>
                        </p>

                    </div>

                </div>


                <div class="overflow-x-auto">

                    <table
                        class="
                            min-w-full
                            divide-y
                            divide-slate-200
                        "
                    >

                        <thead class="bg-slate-50">

                            <tr>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Business
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Plan
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Branches
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Users
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Start
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Expiry
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Amount
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody
                            class="
                                divide-y
                                divide-slate-100
                                bg-white
                            "
                        >

                            <?php if (!$reportRows): ?>

                                <tr>

                                    <td
                                        colspan="8"
                                        class="
                                            px-5
                                            py-12
                                            text-center
                                            text-sm
                                            text-slate-500
                                        "
                                    >
                                        No report records found.
                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach (
                                    $reportRows
                                    as $row
                                ): ?>

                                    <tr
                                        class="
                                            transition
                                            hover:bg-slate-50
                                        "
                                    >

                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                            "
                                        >

                                            <a
                                                href="business-view.php?id=<?= (int) $row['business_id'] ?>"
                                                class="
                                                    font-semibold
                                                    text-slate-800
                                                    hover:text-blue-600
                                                "
                                            >
                                                <?= htmlspecialchars(
                                                    $row['business_name']
                                                ) ?>
                                            </a>

                                            <p
                                                class="
                                                    mt-1
                                                    text-xs
                                                    text-slate-400
                                                "
                                            >
                                                <?= htmlspecialchars(
                                                    $row['phone'] ?? '-'
                                                ) ?>
                                            </p>

                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-sm
                                                text-slate-600
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $row['plan_name']
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-sm
                                                text-slate-600
                                            "
                                        >
                                            <?= (int) $row['branch_count'] ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-sm
                                                text-slate-600
                                            "
                                        >
                                            <?= (int) $row['user_count'] ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-sm
                                                text-slate-600
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $row['start_date']
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-sm
                                                text-slate-600
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $row['expiry_date']
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-sm
                                                font-semibold
                                                text-slate-800
                                            "
                                        >
                                            $<?= number_format(
                                                $row['amount'],
                                                2
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                            "
                                        >

                                            <?php

                                            if (
                                                $row['status']
                                                === 'Active'
                                            ) {

                                                $statusClass =
                                                    'bg-emerald-50 text-emerald-700';

                                            } elseif (
                                                $row['status']
                                                === 'Expired'
                                            ) {

                                                $statusClass =
                                                    'bg-orange-50 text-orange-700';

                                            } else {

                                                $statusClass =
                                                    'bg-red-50 text-red-700';
                                            }

                                            ?>

                                            <span
                                                class="
                                                    rounded-full
                                                    px-2.5
                                                    py-1
                                                    text-xs
                                                    font-semibold
                                                    <?= $statusClass ?>
                                                "
                                            >
                                                <?= htmlspecialchars(
                                                    $row['status']
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

        </main>


        <?php require 'includes/footer.php'; ?>

    </div>

</div>


<script src="../assets/js/app.js"></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

    }
);

</script>

</body>

</html>