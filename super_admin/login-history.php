<?php

require_once '../config/database.php';
require_once '../config/auth.php';

requireSuperAdmin();

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function eventBadge(string $event): string
{
    return match ($event) {
        'LOGIN_SUCCESS'
            => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',

        'LOGIN_FAILED'
            => 'bg-red-50 text-red-700 ring-red-600/20',

        'LOGOUT'
            => 'bg-blue-50 text-blue-700 ring-blue-600/20',

        'SESSION_TIMEOUT'
            => 'bg-orange-50 text-orange-700 ring-orange-600/20',

        default
            => 'bg-slate-100 text-slate-700 ring-slate-600/20'
    };
}

function eventLabel(string $event): string
{
    return match ($event) {
        'LOGIN_SUCCESS' => 'Login Success',
        'LOGIN_FAILED' => 'Login Failed',
        'LOGOUT' => 'Logout',
        'SESSION_TIMEOUT' => 'Session Timeout',
        default => $event
    };
}

function eventIcon(string $event): string
{
    return match ($event) {
        'LOGIN_SUCCESS' => 'log-in',
        'LOGIN_FAILED' => 'shield-alert',
        'LOGOUT' => 'log-out',
        'SESSION_TIMEOUT' => 'clock',
        default => 'activity'
    };
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

$eventFilter = trim(
    $_GET['event_type'] ?? ''
);

$businessFilter = isset(
    $_GET['business_id']
)
    ? (int) $_GET['business_id']
    : 0;

$fromDate = trim(
    $_GET['from_date'] ?? ''
);

$toDate = trim(
    $_GET['to_date'] ?? ''
);


$allowedEvents = [
    'LOGIN_SUCCESS',
    'LOGIN_FAILED',
    'LOGOUT',
    'SESSION_TIMEOUT'
];

if (
    $eventFilter !== ''
    &&
    !in_array(
        $eventFilter,
        $allowedEvents,
        true
    )
) {
    $eventFilter = '';
}


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
| BUILD QUERY
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($search !== '') {

    $where[] = "
        (
            lh.username LIKE ?
            OR u.full_name LIKE ?
            OR b.business_name LIKE ?
            OR br.branch_name LIKE ?
            OR lh.ip_address LIKE ?
            OR lh.failure_reason LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $keyword;
    }
}

if ($eventFilter !== '') {

    $where[] = "
        lh.event_type = ?
    ";

    $params[] = $eventFilter;
}

if ($businessFilter > 0) {

    $where[] = "
        lh.business_id = ?
    ";

    $params[] = $businessFilter;
}

if ($fromDate !== '') {

    $where[] = "
        DATE(lh.created_at) >= ?
    ";

    $params[] = $fromDate;
}

if ($toDate !== '') {

    $where[] = "
        DATE(lh.created_at) <= ?
    ";

    $params[] = $toDate;
}

$whereSql = '';

if (!empty($where)) {

    $whereSql =
        'WHERE '
        . implode(
            ' AND ',
            $where
        );
}


/*
|--------------------------------------------------------------------------
| LOGIN HISTORY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT

        lh.id,
        lh.user_id,
        lh.business_id,
        lh.branch_id,
        lh.username,

        lh.event_type,
        lh.ip_address,
        lh.user_agent,
        lh.failure_reason,
        lh.created_at,

        u.full_name,

        r.role_name,

        b.business_name,

        br.branch_name

    FROM login_history lh

    LEFT JOIN users u
        ON u.id = lh.user_id

    LEFT JOIN roles r
        ON r.id = u.role_id

    LEFT JOIN businesses b
        ON b.id = lh.business_id

    LEFT JOIN branches br
        ON br.id = lh.branch_id

    $whereSql

    ORDER BY lh.id DESC

    LIMIT 500
");

$stmt->execute($params);

$history = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| KPIs
|--------------------------------------------------------------------------
*/

$totalRecords = (int) $conn->query("
    SELECT COUNT(*)
    FROM login_history
")->fetchColumn();


$successfulToday = (int) $conn->query("
    SELECT COUNT(*)
    FROM login_history
    WHERE event_type = 'LOGIN_SUCCESS'
    AND DATE(created_at) = CURDATE()
")->fetchColumn();


$failedToday = (int) $conn->query("
    SELECT COUNT(*)
    FROM login_history
    WHERE event_type = 'LOGIN_FAILED'
    AND DATE(created_at) = CURDATE()
")->fetchColumn();


$usersToday = (int) $conn->query("
    SELECT COUNT(
        DISTINCT user_id
    )
    FROM login_history
    WHERE event_type = 'LOGIN_SUCCESS'
    AND DATE(created_at) = CURDATE()
    AND user_id IS NOT NULL
")->fetchColumn();


$failed24Hours = (int) $conn->query("
    SELECT COUNT(*)
    FROM login_history
    WHERE event_type = 'LOGIN_FAILED'
    AND created_at >=
        DATE_SUB(
            NOW(),
            INTERVAL 24 HOUR
        )
")->fetchColumn();

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
        Login History
    </title>

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

    <?php
    require 'includes/sidebar.php';
    ?>


    <div class="lg:pl-72">

        <?php
        require 'includes/header.php';
        ?>


        <main
            class="
                page-enter
                p-4
                sm:p-6
                lg:p-8
            "
        >


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
                        Security Monitoring
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
                        Login History
                    </h1>

                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        Track login success,
                        failed attempts,
                        logout and session timeout.
                    </p>

                </div>


                <a
                    href="audit-trail.php"
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
                        data-lucide="activity"
                        class="h-4 w-4"
                    ></i>

                    Audit Trail

                </a>

            </div>


            <!-- KPI CARDS -->

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

                            <p
                                class="
                                    text-sm
                                    text-slate-500
                                "
                            >
                                Total Records
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-slate-900
                                "
                            >
                                <?= number_format(
                                    $totalRecords
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
                                bg-blue-50
                                text-blue-600
                            "
                        >

                            <i
                                data-lucide="history"
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

                    <p
                        class="
                            text-sm
                            text-slate-500
                        "
                    >
                        Successful Today
                    </p>

                    <p
                        class="
                            mt-2
                            text-3xl
                            font-bold
                            text-emerald-600
                        "
                    >
                        <?= number_format(
                            $successfulToday
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

                    <p
                        class="
                            text-sm
                            text-slate-500
                        "
                    >
                        Failed Today
                    </p>

                    <p
                        class="
                            mt-2
                            text-3xl
                            font-bold
                            text-red-600
                        "
                    >
                        <?= number_format(
                            $failedToday
                        ) ?>
                    </p>

                    <p
                        class="
                            mt-1
                            text-xs
                            text-slate-400
                        "
                    >
                        <?= number_format(
                            $failed24Hours
                        ) ?>
                        failed in last 24 hours
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

                    <p
                        class="
                            text-sm
                            text-slate-500
                        "
                    >
                        Users Today
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
                            $usersToday
                        ) ?>
                    </p>

                </div>

            </div>


            <!-- SECURITY ALERT -->

            <?php if ($failed24Hours >= 5): ?>

                <div
                    class="
                        mb-6
                        flex
                        items-start
                        gap-3
                        rounded-2xl
                        border
                        border-red-200
                        bg-red-50
                        p-4
                        text-red-700
                    "
                >

                    <i
                        data-lucide="shield-alert"
                        class="
                            mt-0.5
                            h-5
                            w-5
                            shrink-0
                        "
                    ></i>


                    <div>

                        <p
                            class="
                                font-bold
                            "
                        >
                            Security Alert
                        </p>

                        <p
                            class="
                                mt-1
                                text-sm
                            "
                        >
                            <?= number_format(
                                $failed24Hours
                            ) ?>

                            failed login attempts
                            were detected during
                            the last 24 hours.
                        </p>

                    </div>

                </div>

            <?php endif; ?>


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
                        xl:grid-cols-6
                    "
                >

                    <input
                        type="text"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="User, business, branch, IP..."
                        class="
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
                            outline-none
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
                                value="<?= (int)
                                    $business['id']
                                ?>"
                                <?= $businessFilter
                                    ===
                                    (int)
                                    $business['id']
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= e(
                                    $business[
                                        'business_name'
                                    ]
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>


                    <select
                        name="event_type"
                        class="
                            rounded-xl
                            border
                            border-slate-200
                            bg-white
                            px-3
                            py-2.5
                            text-sm
                            outline-none
                        "
                    >

                        <option value="">
                            All Events
                        </option>


                        <?php foreach (
                            $allowedEvents
                            as $event
                        ): ?>

                            <option
                                value="<?= e(
                                    $event
                                ) ?>"
                                <?= $eventFilter
                                    === $event
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= e(
                                    eventLabel(
                                        $event
                                    )
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>


                    <input
                        type="date"
                        name="from_date"
                        value="<?= e(
                            $fromDate
                        ) ?>"
                        class="
                            rounded-xl
                            border
                            border-slate-200
                            px-3
                            py-2.5
                            text-sm
                            outline-none
                        "
                    >


                    <input
                        type="date"
                        name="to_date"
                        value="<?= e(
                            $toDate
                        ) ?>"
                        class="
                            rounded-xl
                            border
                            border-slate-200
                            px-3
                            py-2.5
                            text-sm
                            outline-none
                        "
                    >


                    <div
                        class="
                            flex
                            gap-2
                        "
                    >

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
                            href="login-history.php"
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
                        >

                            <i
                                data-lucide="rotate-ccw"
                                class="h-4 w-4"
                            ></i>

                        </a>

                    </div>

                </form>

            </div>


            <!-- LOGIN HISTORY TABLE -->

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
                            Authentication Activity
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                            "
                        >
                            Latest 500 login
                            and security records.
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
                        <?= number_format(
                            count($history)
                        ) ?>
                    </span>

                </div>


                <div class="overflow-x-auto">

                    <table
                        class="
                            min-w-full
                        "
                    >

                        <thead
                            class="
                                border-b
                                border-slate-200
                                bg-slate-50
                            "
                        >

                        <tr>

                            <th
                                class="
                                    px-5
                                    py-3
                                    text-left
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
                                    text-slate-500
                                "
                            >
                                Date
                            </th>

                            <th
                                class="
                                    px-5
                                    py-3
                                    text-left
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
                                    text-slate-500
                                "
                            >
                                User
                            </th>

                            <th
                                class="
                                    px-5
                                    py-3
                                    text-left
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
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
                                    tracking-wide
                                    text-slate-500
                                "
                            >
                                Branch
                            </th>

                            <th
                                class="
                                    px-5
                                    py-3
                                    text-left
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
                                    text-slate-500
                                "
                            >
                                Event
                            </th>

                            <th
                                class="
                                    px-5
                                    py-3
                                    text-left
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
                                    text-slate-500
                                "
                            >
                                IP
                            </th>

                            <th
                                class="
                                    px-5
                                    py-3
                                    text-left
                                    text-xs
                                    font-semibold
                                    uppercase
                                    tracking-wide
                                    text-slate-500
                                "
                            >
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

                        <?php if (
                            empty($history)
                        ): ?>

                            <tr>

                                <td
                                    colspan="7"
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
                                            data-lucide="shield-check"
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
                                        No login history found.
                                    </p>

                                </td>

                            </tr>

                        <?php else: ?>


                            <?php foreach (
                                $history
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
                                            text-sm
                                            text-slate-500
                                        "
                                    >
                                        <?= e(
                                            $row[
                                                'created_at'
                                            ]
                                        ) ?>
                                    </td>


                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-5
                                            py-4
                                        "
                                    >

                                        <div
                                            class="
                                                flex
                                                items-center
                                                gap-3
                                            "
                                        >

                                            <div
                                                class="
                                                    flex
                                                    h-9
                                                    w-9
                                                    items-center
                                                    justify-center
                                                    rounded-xl
                                                    bg-slate-100
                                                    text-slate-600
                                                "
                                            >

                                                <i
                                                    data-lucide="user"
                                                    class="h-4 w-4"
                                                ></i>

                                            </div>


                                            <div>

                                                <p
                                                    class="
                                                        text-sm
                                                        font-semibold
                                                        text-slate-800
                                                    "
                                                >
                                                    <?= e(
                                                        $row[
                                                            'full_name'
                                                        ]
                                                        ??
                                                        $row[
                                                            'username'
                                                        ]
                                                        ??
                                                        'Unknown'
                                                    ) ?>
                                                </p>


                                                <p
                                                    class="
                                                        mt-0.5
                                                        text-xs
                                                        text-slate-400
                                                    "
                                                >
                                                    <?= e(
                                                        $row[
                                                            'username'
                                                        ]
                                                        ?? '-'
                                                    ) ?>

                                                    <?php if (
                                                        !empty(
                                                            $row[
                                                                'role_name'
                                                            ]
                                                        )
                                                    ): ?>

                                                        •
                                                        <?= e(
                                                            $row[
                                                                'role_name'
                                                            ]
                                                        ) ?>

                                                    <?php endif; ?>

                                                </p>

                                            </div>

                                        </div>

                                    </td>


                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-5
                                            py-4
                                            text-sm
                                            font-medium
                                            text-slate-700
                                        "
                                    >
                                        <?= e(
                                            $row[
                                                'business_name'
                                            ]
                                            ?? 'System'
                                        ) ?>
                                    </td>


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
                                            $row[
                                                'branch_name'
                                            ]
                                            ?? '-'
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
                                                items-center
                                                gap-1.5
                                                rounded-full
                                                px-2.5
                                                py-1
                                                text-xs
                                                font-bold
                                                ring-1
                                                ring-inset
                                                <?= eventBadge(
                                                    $row[
                                                        'event_type'
                                                    ]
                                                ) ?>
                                            "
                                        >

                                            <i
                                                data-lucide="<?= e(
                                                    eventIcon(
                                                        $row[
                                                            'event_type'
                                                        ]
                                                    )
                                                ) ?>"
                                                class="h-3.5 w-3.5"
                                            ></i>

                                            <?= e(
                                                eventLabel(
                                                    $row[
                                                        'event_type'
                                                    ]
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-5
                                            py-4
                                            font-mono
                                            text-xs
                                            text-slate-500
                                        "
                                    >
                                        <?= e(
                                            $row[
                                                'ip_address'
                                            ]
                                            ?? '-'
                                        ) ?>
                                    </td>


                                    <td
                                        class="
                                            min-w-[220px]
                                            px-5
                                            py-4
                                            text-sm
                                        "
                                    >

                                        <?php if (
                                            $row[
                                                'event_type'
                                            ]
                                            ===
                                            'LOGIN_FAILED'
                                        ): ?>

                                            <span
                                                class="
                                                    text-red-600
                                                "
                                            >
                                                <?= e(
                                                    $row[
                                                        'failure_reason'
                                                    ]
                                                    ??
                                                    'Authentication failed'
                                                ) ?>
                                            </span>

                                        <?php elseif (
                                            $row[
                                                'event_type'
                                            ]
                                            ===
                                            'LOGIN_SUCCESS'
                                        ): ?>

                                            <span
                                                class="
                                                    text-emerald-600
                                                "
                                            >
                                                Authentication successful
                                            </span>

                                        <?php elseif (
                                            $row[
                                                'event_type'
                                            ]
                                            ===
                                            'LOGOUT'
                                        ): ?>

                                            <span
                                                class="
                                                    text-blue-600
                                                "
                                            >
                                                User logged out
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="
                                                    text-orange-600
                                                "
                                            >
                                                Session expired
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </main>


        <?php
        require 'includes/footer.php';
        ?>

    </div>

</div>


<script src="../assets/js/app.js"></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        if (
            typeof lucide
            !== 'undefined'
        ) {
            lucide.createIcons();
        }

    }
);

</script>

</body>

</html>