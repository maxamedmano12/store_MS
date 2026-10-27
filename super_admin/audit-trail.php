<?php

require_once '../config/database.php';
require_once '../config/auth.php';

requireSuperAdmin();


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

$businessFilter =
    isset($_GET['business_id'])
    ? (int) $_GET['business_id']
    : 0;

$actionFilter =
    trim($_GET['action'] ?? '');

$moduleFilter =
    trim($_GET['module'] ?? '');

$fromDate =
    trim($_GET['from_date'] ?? '');

$toDate =
    trim($_GET['to_date'] ?? '');


/*
|--------------------------------------------------------------------------
| BUSINESSES
|--------------------------------------------------------------------------
*/

$businesses = $conn->query("
    SELECT
        id,
        business_name
    FROM businesses
    ORDER BY business_name ASC
")->fetchAll();


/*
|--------------------------------------------------------------------------
| ACTIONS
|--------------------------------------------------------------------------
*/

$actions = $conn->query("
    SELECT DISTINCT action
    FROM audit_logs
    WHERE action IS NOT NULL
    AND action <> ''
    ORDER BY action ASC
")->fetchAll(PDO::FETCH_COLUMN);


/*
|--------------------------------------------------------------------------
| MODULES
|--------------------------------------------------------------------------
*/

$modules = $conn->query("
    SELECT DISTINCT module
    FROM audit_logs
    WHERE module IS NOT NULL
    AND module <> ''
    ORDER BY module ASC
")->fetchAll(PDO::FETCH_COLUMN);


/*
|--------------------------------------------------------------------------
| BUILD WHERE
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];


if ($search !== '') {

    $where[] = "
        (
            u.full_name LIKE ?
            OR u.username LIKE ?
            OR actor_business.business_name LIKE ?
            OR target_business.business_name LIKE ?
            OR al.description LIKE ?
            OR al.module LIKE ?
            OR al.action LIKE ?
            OR al.ip_address LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    for ($i = 0; $i < 8; $i++) {
        $params[] = $keyword;
    }
}


if ($businessFilter > 0) {

    $where[] = "
        al.target_business_id = ?
    ";

    $params[] = $businessFilter;
}


if ($actionFilter !== '') {

    $where[] = "
        al.action = ?
    ";

    $params[] = $actionFilter;
}


if ($moduleFilter !== '') {

    $where[] = "
        al.module = ?
    ";

    $params[] = $moduleFilter;
}


if ($fromDate !== '') {

    $where[] = "
        DATE(al.created_at) >= ?
    ";

    $params[] = $fromDate;
}


if ($toDate !== '') {

    $where[] = "
        DATE(al.created_at) <= ?
    ";

    $params[] = $toDate;
}


$whereSql = '';

if (!empty($where)) {

    $whereSql =
        'WHERE '
        . implode(' AND ', $where);
}


/*
|--------------------------------------------------------------------------
| AUDIT LOGS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT

        al.id,
        al.user_id,
        al.business_id,
        al.target_business_id,
        al.branch_id,

        al.action,
        al.module,
        al.record_id,
        al.description,

        al.old_values,
        al.new_values,

        al.ip_address,
        al.user_agent,
        al.created_at,

        u.full_name,
        u.username,

        r.role_name,

        actor_business.business_name
            AS actor_business_name,

        target_business.business_name
            AS target_business_name,

        br.branch_name

    FROM audit_logs al

    LEFT JOIN users u
        ON u.id = al.user_id

    LEFT JOIN roles r
        ON r.id = u.role_id

    LEFT JOIN businesses actor_business
        ON actor_business.id = al.business_id

    LEFT JOIN businesses target_business
        ON target_business.id =
           al.target_business_id

    LEFT JOIN branches br
        ON br.id = al.branch_id

    $whereSql

    ORDER BY al.id DESC

    LIMIT 500
");

$stmt->execute($params);

$logs = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| KPI DATA
|--------------------------------------------------------------------------
*/

$totalLogs = (int) $conn->query("
    SELECT COUNT(*)
    FROM audit_logs
")->fetchColumn();


$todayLogs = (int) $conn->query("
    SELECT COUNT(*)
    FROM audit_logs
    WHERE DATE(created_at) = CURDATE()
")->fetchColumn();


$activeBusinessesToday =
    (int) $conn->query("
        SELECT COUNT(
            DISTINCT target_business_id
        )
        FROM audit_logs
        WHERE DATE(created_at) = CURDATE()
        AND target_business_id IS NOT NULL
    ")->fetchColumn();


$activeUsersToday =
    (int) $conn->query("
        SELECT COUNT(
            DISTINCT user_id
        )
        FROM audit_logs
        WHERE DATE(created_at) = CURDATE()
        AND user_id IS NOT NULL
    ")->fetchColumn();


/*
|--------------------------------------------------------------------------
| MOST ACTIVE BUSINESSES
|--------------------------------------------------------------------------
*/

$activeBusinessStmt =
    $conn->query("
        SELECT
            b.id,
            b.business_name,
            COUNT(al.id) AS activity_count,
            MAX(al.created_at) AS last_activity

        FROM businesses b

        INNER JOIN audit_logs al
            ON al.target_business_id = b.id

        GROUP BY
            b.id,
            b.business_name

        ORDER BY
            activity_count DESC

        LIMIT 8
    ");

$activeBusinessList =
    $activeBusinessStmt->fetchAll();


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function actionBadge(
    string $action
): string {

    return match (
        strtoupper($action)
    ) {

        'CREATE',
        'ACTIVATE',
        'APPROVE',
        'PAYMENT'
            => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',

        'UPDATE',
        'RENEW'
            => 'bg-blue-50 text-blue-700 ring-blue-600/20',

        'DELETE',
        'SUSPEND',
        'REJECT'
            => 'bg-red-50 text-red-700 ring-red-600/20',

        'LOGIN',
        'LOGOUT'
            => 'bg-violet-50 text-violet-700 ring-violet-600/20',

        'RETURN',
        'TRANSFER'
            => 'bg-orange-50 text-orange-700 ring-orange-600/20',

        default
            => 'bg-slate-100 text-slate-700 ring-slate-600/20'
    };
}


/*
|--------------------------------------------------------------------------
| FORMAT JSON
|--------------------------------------------------------------------------
*/

function formatAuditValues(
    ?string $value
): string {

    if (
        $value === null
        || trim($value) === ''
    ) {
        return '-';
    }

    $decoded =
        json_decode(
            $value,
            true
        );

    if (
        json_last_error()
        === JSON_ERROR_NONE
    ) {

        return json_encode(
            $decoded,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    return $value;
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Audit Trail</title>

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
                        Security & Monitoring
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
                        Business Activity & Audit Trail
                    </h1>


                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        Monitor users, businesses,
                        branches and system activities.
                    </p>

                </div>


                <a
                    href="reports.php"
                    class="
                        inline-flex
                        items-center
                        justify-center
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
                        data-lucide="bar-chart-3"
                        class="h-4 w-4"
                    ></i>

                    Reports

                </a>

            </div>


            <!-- KPIs -->

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

                <div
                    class="
                        dashboard-card
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

                            <p class="text-sm text-slate-500">
                                Total Activities
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-slate-900
                                "
                            >
                                <?= number_format($totalLogs) ?>
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
                                data-lucide="activity"
                                class="h-6 w-6"
                            ></i>

                        </div>

                    </div>

                </div>


                <div
                    class="
                        dashboard-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
                        shadow-sm
                    "
                >

                    <p class="text-sm text-slate-500">
                        Today's Activities
                    </p>

                    <p
                        class="
                            mt-2
                            text-3xl
                            font-bold
                            text-emerald-600
                        "
                    >
                        <?= number_format($todayLogs) ?>
                    </p>

                </div>


                <div
                    class="
                        dashboard-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
                        shadow-sm
                    "
                >

                    <p class="text-sm text-slate-500">
                        Active Businesses Today
                    </p>

                    <p
                        class="
                            mt-2
                            text-3xl
                            font-bold
                            text-violet-600
                        "
                    >
                        <?= number_format(
                            $activeBusinessesToday
                        ) ?>
                    </p>

                </div>


                <div
                    class="
                        dashboard-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
                        shadow-sm
                    "
                >

                    <p class="text-sm text-slate-500">
                        Active Users Today
                    </p>

                    <p
                        class="
                            mt-2
                            text-3xl
                            font-bold
                            text-orange-600
                        "
                    >
                        <?= number_format(
                            $activeUsersToday
                        ) ?>
                    </p>

                </div>

            </div>


            <!-- MOST ACTIVE BUSINESSES -->

            <div
                class="
                    mb-6
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
                        Most Active Businesses
                    </h2>


                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        Businesses with the highest
                        recorded system activity.
                    </p>

                </div>


                <div
                    class="
                        grid
                        grid-cols-1
                        gap-4
                        p-5
                        sm:grid-cols-2
                        xl:grid-cols-4
                    "
                >

                    <?php if (!$activeBusinessList): ?>

                        <div
                            class="
                                col-span-full
                                py-8
                                text-center
                                text-sm
                                text-slate-500
                            "
                        >
                            No business activity yet.
                        </div>

                    <?php else: ?>


                        <?php foreach (
                            $activeBusinessList
                            as $business
                        ): ?>

                            <a
                                href="audit-trail.php?business_id=<?= (int) $business['id'] ?>"
                                class="
                                    rounded-2xl
                                    border
                                    border-slate-200
                                    p-4
                                    transition
                                    duration-200
                                    hover:-translate-y-1
                                    hover:border-blue-200
                                    hover:shadow-md
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
                                                font-bold
                                                text-slate-800
                                            "
                                        >
                                            <?= e(
                                                $business[
                                                    'business_name'
                                                ]
                                            ) ?>
                                        </p>


                                        <p
                                            class="
                                                mt-1
                                                text-xs
                                                text-slate-500
                                            "
                                        >
                                            Last activity:
                                            <?= e(
                                                $business[
                                                    'last_activity'
                                                ]
                                            ) ?>
                                        </p>

                                    </div>


                                    <span
                                        class="
                                            rounded-xl
                                            bg-blue-50
                                            px-2.5
                                            py-1
                                            text-xs
                                            font-bold
                                            text-blue-700
                                        "
                                    >
                                        <?= number_format(
                                            $business[
                                                'activity_count'
                                            ]
                                        ) ?>
                                    </span>

                                </div>

                            </a>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>


            <!-- FILTERS -->

            <div
                class="
                    mb-6
                    rounded-3xl
                    border
                    border-slate-200
                    bg-white
                    p-5
                    shadow-sm
                "
            >

                <form
                    method="GET"
                    class="
                        grid
                        grid-cols-1
                        gap-3
                        md:grid-cols-2
                        xl:grid-cols-7
                    "
                >

                    <div class="xl:col-span-2">

                        <input
                            type="text"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Search activity..."
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


                    <select
                        name="business_id"
                        class="
                            rounded-xl
                            border
                            border-slate-200
                            bg-white
                            px-3
                            py-2.5
                            text-sm
                        "
                    >

                        <option value="0">
                            All Businesses
                        </option>


                        <?php foreach (
                            $businesses
                            as $business
                        ): ?>

                            <option
                                value="<?= (int) $business['id'] ?>"
                                <?= $businessFilter
                                    === (int) $business['id']
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= e(
                                    $business['business_name']
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>


                    <select
                        name="action"
                        class="
                            rounded-xl
                            border
                            border-slate-200
                            bg-white
                            px-3
                            py-2.5
                            text-sm
                        "
                    >

                        <option value="">
                            All Actions
                        </option>


                        <?php foreach (
                            $actions
                            as $action
                        ): ?>

                            <option
                                value="<?= e($action) ?>"
                                <?= $actionFilter === $action
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= e($action) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>


                    <select
                        name="module"
                        class="
                            rounded-xl
                            border
                            border-slate-200
                            bg-white
                            px-3
                            py-2.5
                            text-sm
                        "
                    >

                        <option value="">
                            All Modules
                        </option>


                        <?php foreach (
                            $modules
                            as $module
                        ): ?>

                            <option
                                value="<?= e($module) ?>"
                                <?= $moduleFilter === $module
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= e($module) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>


                    <input
                        type="date"
                        name="from_date"
                        value="<?= e($fromDate) ?>"
                        class="
                            rounded-xl
                            border
                            border-slate-200
                            px-3
                            py-2.5
                            text-sm
                        "
                    >


                    <input
                        type="date"
                        name="to_date"
                        value="<?= e($toDate) ?>"
                        class="
                            rounded-xl
                            border
                            border-slate-200
                            px-3
                            py-2.5
                            text-sm
                        "
                    >


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
                            href="audit-trail.php"
                            class="
                                inline-flex
                                items-center
                                justify-center
                                rounded-xl
                                border
                                border-slate-200
                                px-3
                                text-slate-500
                            "
                        >

                            <i
                                data-lucide="rotate-ccw"
                                class="h-4 w-4"
                            ></i>

                        </a>

                    </div>

                </form>

            </div>


            <!-- AUDIT TABLE -->

            <div
                class="
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
                        items-center
                        justify-between
                        border-b
                        border-slate-200
                        px-5
                        py-5
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
                            Activity History
                        </h2>


                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                            "
                        >
                            Showing up to 500 latest records.
                        </p>

                    </div>


                    <span
                        class="
                            rounded-xl
                            bg-slate-100
                            px-3
                            py-1.5
                            text-sm
                            font-semibold
                            text-slate-600
                        "
                    >
                        <?= number_format(count($logs)) ?>
                    </span>

                </div>


                <div class="overflow-x-auto">

                    <table class="min-w-full">

                        <thead
                            class="
                                border-b
                                border-slate-200
                                bg-slate-50
                            "
                        >

                            <tr>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Date
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    User
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Business
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Module
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Action
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    Activity
                                </th>

                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase text-slate-500">
                                    IP
                                </th>

                                <th class="px-5 py-3 text-center text-xs font-semibold uppercase text-slate-500">
                                    Details
                                </th>

                            </tr>

                        </thead>


                        <tbody
                            class="
                                divide-y
                                divide-slate-100
                            "
                        >

                            <?php if (!$logs): ?>

                                <tr>

                                    <td
                                        colspan="8"
                                        class="
                                            px-5
                                            py-16
                                            text-center
                                        "
                                    >

                                        <div
                                            class="
                                                mx-auto
                                                flex
                                                h-14
                                                w-14
                                                items-center
                                                justify-center
                                                rounded-2xl
                                                bg-slate-100
                                                text-slate-400
                                            "
                                        >

                                            <i
                                                data-lucide="activity"
                                                class="h-6 w-6"
                                            ></i>

                                        </div>


                                        <p
                                            class="
                                                mt-3
                                                font-semibold
                                                text-slate-700
                                            "
                                        >
                                            No activity found.
                                        </p>

                                    </td>

                                </tr>

                            <?php else: ?>


                                <?php foreach (
                                    $logs
                                    as $log
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
                                                text-sm
                                                text-slate-500
                                            "
                                        >
                                            <?= e(
                                                $log['created_at']
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                            "
                                        >

                                            <p
                                                class="
                                                    text-sm
                                                    font-semibold
                                                    text-slate-800
                                                "
                                            >
                                                <?= e(
                                                    $log['full_name']
                                                    ?? 'System'
                                                ) ?>
                                            </p>


                                            <p
                                                class="
                                                    mt-1
                                                    text-xs
                                                    text-slate-400
                                                "
                                            >
                                                <?= e(
                                                    $log['role_name']
                                                    ?? '-'
                                                ) ?>
                                            </p>

                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                            "
                                        >

                                            <p
                                                class="
                                                    text-sm
                                                    font-semibold
                                                    text-slate-700
                                                "
                                            >
                                                <?= e(
                                                    $log[
                                                        'target_business_name'
                                                    ]
                                                    ?? 'System'
                                                ) ?>
                                            </p>


                                            <?php if (
                                                !empty(
                                                    $log[
                                                        'branch_name'
                                                    ]
                                                )
                                            ): ?>

                                                <p
                                                    class="
                                                        mt-1
                                                        text-xs
                                                        text-slate-400
                                                    "
                                                >
                                                    <?= e(
                                                        $log[
                                                            'branch_name'
                                                        ]
                                                    ) ?>
                                                </p>

                                            <?php endif; ?>

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
                                            <?= e(
                                                $log['module']
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                            "
                                        >

                                            <span
                                                class="
                                                    inline-flex
                                                    rounded-full
                                                    px-2.5
                                                    py-1
                                                    text-xs
                                                    font-bold
                                                    ring-1
                                                    ring-inset
                                                    <?= actionBadge(
                                                        $log[
                                                            'action'
                                                        ]
                                                    ) ?>
                                                "
                                            >
                                                <?= e(
                                                    $log['action']
                                                ) ?>
                                            </span>

                                        </td>


                                        <td
                                            class="
                                                min-w-[250px]
                                                px-5
                                                py-4
                                                text-sm
                                                text-slate-600
                                            "
                                        >
                                            <?= e(
                                                $log[
                                                    'description'
                                                ]
                                                ?? '-'
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-xs
                                                text-slate-500
                                            "
                                        >
                                            <?= e(
                                                $log[
                                                    'ip_address'
                                                ]
                                                ?? '-'
                                            ) ?>
                                        </td>


                                        <td
                                            class="
                                                whitespace-nowrap
                                                px-5
                                                py-4
                                                text-center
                                            "
                                        >

                                            <button
                                                type="button"
                                                onclick='openAuditDetails(
                                                    <?= json_encode(
                                                        [
                                                            'id' =>
                                                                $log['id'],

                                                            'date' =>
                                                                $log['created_at'],

                                                            'user' =>
                                                                $log['full_name']
                                                                ?? 'System',

                                                            'username' =>
                                                                $log['username']
                                                                ?? '-',

                                                            'role' =>
                                                                $log['role_name']
                                                                ?? '-',

                                                            'business' =>
                                                                $log[
                                                                    'target_business_name'
                                                                ]
                                                                ?? 'System',

                                                            'branch' =>
                                                                $log['branch_name']
                                                                ?? '-',

                                                            'module' =>
                                                                $log['module'],

                                                            'action' =>
                                                                $log['action'],

                                                            'record_id' =>
                                                                $log['record_id'],

                                                            'description' =>
                                                                $log['description'],

                                                            'old_values' =>
                                                                formatAuditValues(
                                                                    $log[
                                                                        'old_values'
                                                                    ]
                                                                ),

                                                            'new_values' =>
                                                                formatAuditValues(
                                                                    $log[
                                                                        'new_values'
                                                                    ]
                                                                ),

                                                            'ip' =>
                                                                $log['ip_address'],

                                                            'user_agent' =>
                                                                $log['user_agent']
                                                        ],
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_QUOT |
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_AMP
                                                    ) ?>
                                                )'
                                                class="
                                                    inline-flex
                                                    h-9
                                                    w-9
                                                    items-center
                                                    justify-center
                                                    rounded-xl
                                                    bg-blue-50
                                                    text-blue-600
                                                    transition
                                                    hover:bg-blue-600
                                                    hover:text-white
                                                "
                                            >

                                                <i
                                                    data-lucide="eye"
                                                    class="h-4 w-4"
                                                ></i>

                                            </button>

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


<!-- DETAILS MODAL -->

<div
    id="auditModal"
    class="
        fixed
        inset-0
        z-[100]
        hidden
        items-center
        justify-center
        bg-slate-950/60
        p-4
        backdrop-blur-sm
    "
>

    <div
        class="
            max-h-[90vh]
            w-full
            max-w-3xl
            overflow-y-auto
            rounded-3xl
            bg-white
            shadow-2xl
        "
    >

        <div
            class="
                sticky
                top-0
                z-10
                flex
                items-center
                justify-between
                border-b
                border-slate-200
                bg-white
                px-6
                py-5
            "
        >

            <div>

                <h3
                    class="
                        text-xl
                        font-bold
                        text-slate-900
                    "
                >
                    Activity Details
                </h3>

                <p
                    id="modalAuditId"
                    class="
                        mt-1
                        text-xs
                        text-slate-500
                    "
                ></p>

            </div>


            <button
                type="button"
                onclick="closeAuditDetails()"
                class="
                    flex
                    h-10
                    w-10
                    items-center
                    justify-center
                    rounded-xl
                    bg-slate-100
                    text-slate-500
                    transition
                    hover:bg-slate-200
                "
            >

                <i
                    data-lucide="x"
                    class="h-5 w-5"
                ></i>

            </button>

        </div>


        <div class="space-y-6 p-6">

            <div
                class="
                    grid
                    grid-cols-1
                    gap-4
                    sm:grid-cols-2
                "
            >

                <div
                    class="
                        rounded-2xl
                        bg-slate-50
                        p-4
                    "
                >

                    <p
                        class="
                            text-xs
                            font-semibold
                            uppercase
                            text-slate-400
                        "
                    >
                        User
                    </p>

                    <p
                        id="modalUser"
                        class="
                            mt-1
                            font-semibold
                            text-slate-800
                        "
                    ></p>

                </div>


                <div
                    class="
                        rounded-2xl
                        bg-slate-50
                        p-4
                    "
                >

                    <p
                        class="
                            text-xs
                            font-semibold
                            uppercase
                            text-slate-400
                        "
                    >
                        Business
                    </p>

                    <p
                        id="modalBusiness"
                        class="
                            mt-1
                            font-semibold
                            text-slate-800
                        "
                    ></p>

                </div>


                <div
                    class="
                        rounded-2xl
                        bg-slate-50
                        p-4
                    "
                >

                    <p
                        class="
                            text-xs
                            font-semibold
                            uppercase
                            text-slate-400
                        "
                    >
                        Module / Action
                    </p>

                    <p
                        id="modalAction"
                        class="
                            mt-1
                            font-semibold
                            text-slate-800
                        "
                    ></p>

                </div>


                <div
                    class="
                        rounded-2xl
                        bg-slate-50
                        p-4
                    "
                >

                    <p
                        class="
                            text-xs
                            font-semibold
                            uppercase
                            text-slate-400
                        "
                    >
                        Date / IP
                    </p>

                    <p
                        id="modalDate"
                        class="
                            mt-1
                            font-semibold
                            text-slate-800
                        "
                    ></p>

                </div>

            </div>


            <div>

                <p
                    class="
                        mb-2
                        text-sm
                        font-bold
                        text-slate-700
                    "
                >
                    Description
                </p>

                <div
                    id="modalDescription"
                    class="
                        rounded-2xl
                        border
                        border-slate-200
                        bg-slate-50
                        p-4
                        text-sm
                        text-slate-600
                    "
                ></div>

            </div>


            <div
                class="
                    grid
                    grid-cols-1
                    gap-5
                    lg:grid-cols-2
                "
            >

                <div>

                    <p
                        class="
                            mb-2
                            text-sm
                            font-bold
                            text-slate-700
                        "
                    >
                        Old Values
                    </p>

                    <pre
                        id="modalOldValues"
                        class="
                            max-h-80
                            overflow-auto
                            whitespace-pre-wrap
                            rounded-2xl
                            bg-slate-950
                            p-4
                            text-xs
                            text-slate-200
                        "
                    ></pre>

                </div>


                <div>

                    <p
                        class="
                            mb-2
                            text-sm
                            font-bold
                            text-slate-700
                        "
                    >
                        New Values
                    </p>

                    <pre
                        id="modalNewValues"
                        class="
                            max-h-80
                            overflow-auto
                            whitespace-pre-wrap
                            rounded-2xl
                            bg-slate-950
                            p-4
                            text-xs
                            text-slate-200
                        "
                    ></pre>

                </div>

            </div>


            <div>

                <p
                    class="
                        mb-2
                        text-sm
                        font-bold
                        text-slate-700
                    "
                >
                    Device / Browser
                </p>

                <div
                    id="modalUserAgent"
                    class="
                        break-words
                        rounded-2xl
                        border
                        border-slate-200
                        bg-slate-50
                        p-4
                        text-xs
                        text-slate-500
                    "
                ></div>

            </div>

        </div>

    </div>

</div>


<script src="../assets/js/app.js"></script>

<script>

function openAuditDetails(data) {

    const modal =
        document.getElementById(
            'auditModal'
        );

    document.getElementById(
        'modalAuditId'
    ).textContent =
        'Audit ID: #' + data.id;


    document.getElementById(
        'modalUser'
    ).textContent =
        data.user
        + ' • '
        + data.role;


    document.getElementById(
        'modalBusiness'
    ).textContent =
        data.business
        + (
            data.branch !== '-'
            ? ' • ' + data.branch
            : ''
        );


    document.getElementById(
        'modalAction'
    ).textContent =
        data.module
        + ' • '
        + data.action;


    document.getElementById(
        'modalDate'
    ).textContent =
        data.date
        + ' • '
        + (
            data.ip || '-'
        );


    document.getElementById(
        'modalDescription'
    ).textContent =
        data.description
        || '-';


    document.getElementById(
        'modalOldValues'
    ).textContent =
        data.old_values
        || '-';


    document.getElementById(
        'modalNewValues'
    ).textContent =
        data.new_values
        || '-';


    document.getElementById(
        'modalUserAgent'
    ).textContent =
        data.user_agent
        || '-';


    modal.classList.remove(
        'hidden'
    );

    modal.classList.add(
        'flex'
    );

    document.body.classList.add(
        'overflow-hidden'
    );
}


function closeAuditDetails() {

    const modal =
        document.getElementById(
            'auditModal'
        );

    modal.classList.add(
        'hidden'
    );

    modal.classList.remove(
        'flex'
    );

    document.body.classList.remove(
        'overflow-hidden'
    );
}


document.getElementById(
    'auditModal'
)?.addEventListener(
    'click',
    function (event) {

        if (
            event.target === this
        ) {
            closeAuditDetails();
        }
    }
);


document.addEventListener(
    'keydown',
    function (event) {

        if (
            event.key === 'Escape'
        ) {
            closeAuditDetails();
        }
    }
);


if (
    typeof lucide !== 'undefined'
) {
    lucide.createIcons();
}

</script>

</body>

</html>