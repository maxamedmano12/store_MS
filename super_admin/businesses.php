<?php

session_start();
require_once '../config/database.php';

require_once '../config/auth.php';

requireSuperAdmin();
$message = '';
$messageType = '';

/* =========================================================
   ADD BUSINESS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_business'])) {

    $businessName = trim($_POST['business_name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $address      = trim($_POST['address'] ?? '');

    $adminName     = trim($_POST['admin_name'] ?? '');
    $adminUsername = trim($_POST['admin_username'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';

    $planId        = (int) ($_POST['plan_id'] ?? 0);
    $startDate     = trim($_POST['start_date'] ?? date('Y-m-d'));

    if (
        $businessName === '' ||
        $adminName === '' ||
        $adminUsername === '' ||
        $adminPassword === '' ||
        $planId <= 0 ||
        $startDate === ''
    ) {

        $message = 'Fadlan buuxi meelaha muhiimka ah.';
        $messageType = 'error';

    } elseif (strlen($adminPassword) < 6) {

        $message = 'Password-ku waa inuu ugu yaraan 6 xaraf yahay.';
        $messageType = 'error';

    } else {

        try {

            /* GET SELECTED SUBSCRIPTION PLAN */

            $planStmt = $conn->prepare("
                SELECT
                    id,
                    plan_name,
                    duration_months,
                    price,
                    max_branches
                FROM subscription_plans
                WHERE id = ?
                AND status = 'Active'
                LIMIT 1
            ");

            $planStmt->execute([$planId]);
            $selectedPlan = $planStmt->fetch();

            if (!$selectedPlan) {
                throw new Exception(
                    'Subscription plan-ka la doortay lama helin ama ma Active aha.'
                );
            }

            $startDateObject = DateTime::createFromFormat('Y-m-d', $startDate);

            if (
                !$startDateObject ||
                $startDateObject->format('Y-m-d') !== $startDate
            ) {
                throw new Exception('Start Date sax ah geli.');
            }

            /*
             * Calculate expiry using the selected plan duration.
             * Example: 2026-08-28 + 12 months = 2027-08-28
             */
            $expiryDateObject = clone $startDateObject;
            $expiryDateObject->modify(
                '+' . (int) $selectedPlan['duration_months'] . ' months'
            );

            $expiryDate = $expiryDateObject->format('Y-m-d');

            $conn->beginTransaction();

            /* CHECK USERNAME */

            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $check->execute([$adminUsername]);

            if ($check->fetch()) {
                throw new Exception(
                    'Username-kan hore ayaa loo isticmaalay.'
                );
            }


            /* CREATE BUSINESS */

            $stmt = $conn->prepare("
                INSERT INTO businesses
                (
                    business_name,
                    phone,
                    email,
                    address,
                    status
                )
                VALUES (?, ?, ?, ?, 'Active')
            ");

            $stmt->execute([
                $businessName,
                $phone ?: null,
                $email ?: null,
                $address ?: null
            ]);

            $businessId = $conn->lastInsertId();


            /* CREATE MAIN BRANCH */

            $stmt = $conn->prepare("
                INSERT INTO branches
                (
                    business_id,
                    branch_name,
                    is_main,
                    status
                )
                VALUES (?, 'Main Branch', 1, 'Active')
            ");

            $stmt->execute([$businessId]);

            $branchId = $conn->lastInsertId();


            /* GET BUSINESS ADMIN ROLE */

            $stmt = $conn->prepare("
                SELECT id
                FROM roles
                WHERE role_name = 'Business Admin'
                LIMIT 1
            ");

            $stmt->execute();

            $roleId = $stmt->fetchColumn();

            if (!$roleId) {
                throw new Exception(
                    'Business Admin role lama helin.'
                );
            }


            /* CREATE BUSINESS ADMIN */

            $passwordHash = password_hash(
                $adminPassword,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare("
                INSERT INTO users
                (
                    business_id,
                    branch_id,
                    role_id,
                    full_name,
                    username,
                    password,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, 'Active')
            ");

            $stmt->execute([
                $businessId,
                $branchId,
                $roleId,
                $adminName,
                $adminUsername,
                $passwordHash
            ]);


            /* CREATE SUBSCRIPTION */

            $stmt = $conn->prepare("
                INSERT INTO subscriptions
                (
                    business_id,
                    plan_id,
                    start_date,
                    expiry_date,
                    amount,
                    status
                )
                VALUES (?, ?, ?, ?, ?, 'Active')
            ");

            $stmt->execute([
                $businessId,
                $selectedPlan['id'],
                $startDate,
                $expiryDate,
                $selectedPlan['price']
            ]);

            /* CREATE DEFAULT CHART OF ACCOUNTS FOR NEW BUSINESS */
            $stmt = $conn->prepare("
                INSERT IGNORE INTO accounts
                (business_id, account_code, account_name, account_type, account_group, normal_balance, is_system, status, description)
                SELECT ?, account_code, account_name, account_type, account_group, normal_balance, 1, status, description
                FROM default_accounts
                WHERE status = 'Active'
            ");
            $stmt->execute([$businessId]);

            $conn->commit();

            $message =
                $businessName .
                ' si guul leh ayaa loo diiwaangeliyey. Plan: ' .
                $selectedPlan['plan_name'] .
                ' | Expiry: ' .
                date('d M Y', strtotime($expiryDate));

            $messageType = 'success';

        } catch (Exception $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}


/* =========================================================
   CHANGE BUSINESS STATUS
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['change_status'])
) {

    $businessId = (int) ($_POST['business_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';

    $allowed = [
        'Active',
        'Suspended',
        'Expired'
    ];

    if ($businessId > 0 && in_array($newStatus, $allowed, true)) {

        $stmt = $conn->prepare("
            UPDATE businesses
            SET status = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $newStatus,
            $businessId
        ]);

        $message = 'Business status waa la beddelay.';
        $messageType = 'success';
    }
}


/* =========================================================
   LOAD ACTIVE SUBSCRIPTION PLANS
========================================================= */

$planStmt = $conn->query("
    SELECT
        id,
        plan_name,
        duration_months,
        price,
        max_branches
    FROM subscription_plans
    WHERE status = 'Active'
    ORDER BY duration_months ASC, id ASC
");

$subscriptionPlans = $planStmt->fetchAll();


/* =========================================================
   SEARCH
========================================================= */

$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        b.id,
        b.business_name,
        b.phone,
        b.email,
        b.address,
        b.status,
        b.created_at,

        (
            SELECT COUNT(*)
            FROM branches br
            WHERE br.business_id = b.id
        ) AS total_branches,

        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.business_id = b.id
        ) AS total_users,

        (
            SELECT u.full_name
            FROM users u
            INNER JOIN roles r
                ON r.id = u.role_id
            WHERE
                u.business_id = b.id
                AND r.role_name = 'Business Admin'
            ORDER BY u.id ASC
            LIMIT 1
        ) AS admin_name,

        (
            SELECT u.username
            FROM users u
            INNER JOIN roles r
                ON r.id = u.role_id
            WHERE
                u.business_id = b.id
                AND r.role_name = 'Business Admin'
            ORDER BY u.id ASC
            LIMIT 1
        ) AS admin_username

    FROM businesses b
";

$params = [];

if ($search !== '') {

    $sql .= "
        WHERE
            b.business_name LIKE ?
            OR b.phone LIKE ?
            OR b.email LIKE ?
            OR b.address LIKE ?
    ";

    $searchValue = '%' . $search . '%';

    $params = [
        $searchValue,
        $searchValue,
        $searchValue,
        $searchValue
    ];
}

$sql .= "
    ORDER BY b.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$businesses = $stmt->fetchAll();


/* =========================================================
   COUNTS
========================================================= */

$totalBusinesses = count($businesses);

$activeBusinesses = 0;
$suspendedBusinesses = 0;
$expiredBusinesses = 0;

foreach ($businesses as $business) {

    if ($business['status'] === 'Active') {
        $activeBusinesses++;
    }

    if ($business['status'] === 'Suspended') {
        $suspendedBusinesses++;
    }

    if ($business['status'] === 'Expired') {
        $expiredBusinesses++;
    }
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

    <title>Businesses</title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
    >

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

    <script src="https://unpkg.com/lucide@latest"></script>

    <link
        rel="stylesheet"
        href="../assets/css/app.css"
    >

</head>


<body
    class="
        bg-[#f7f9fc]
        text-slate-800
        antialiased
        dark:bg-slate-950
        dark:text-slate-100
    "
>


<?php require_once 'includes/sidebar.php'; ?>


<div class="min-h-screen lg:ml-[270px]">

<?php require_once 'includes/header.php'; ?>


<main class="page-enter px-4 py-6 md:px-7 md:py-7">


    <!-- PAGE HEADER -->

    <div
        class="
            mb-6 flex flex-col gap-4
            xl:flex-row xl:items-center
            xl:justify-between
        "
    >

        <div>

            <div
                class="
                    mb-2 flex items-center
                    gap-2 text-[11px]
                    text-slate-400
                "
            >

                <a
                    href="dashboard.php"
                    class="hover:text-blue-600"
                >
                    Dashboard
                </a>

                <i
                    data-lucide="chevron-right"
                    class="h-3 w-3"
                ></i>

                <span>
                    Businesses
                </span>

            </div>


            <h1
                class="
                    text-2xl font-extrabold
                    tracking-tight text-slate-900
                    dark:text-white
                "
            >
                Businesses
            </h1>

            <p
                class="
                    mt-1 text-[12px]
                    text-slate-500
                "
            >
                Register and manage businesses using your system.
            </p>

        </div>


        <button
            id="openAddBusiness"
            type="button"
            class="
                flex h-11 items-center
                justify-center gap-2
                rounded-xl bg-blue-600
                px-5 text-xs font-semibold
                text-white shadow-lg
                shadow-blue-600/20
                transition
                hover:-translate-y-0.5
                hover:bg-blue-700
            "
        >

            <i
                data-lucide="plus"
                class="h-4 w-4"
            ></i>

            Add Business

        </button>

    </div>


    <!-- MESSAGE -->

    <?php if ($message !== ''): ?>

        <div
            id="alertMessage"
            class="
                mb-5 flex items-start
                justify-between gap-3
                rounded-xl border p-4

                <?=
                    $messageType === 'success'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : 'border-red-200 bg-red-50 text-red-700'
                ?>
            "
        >

            <div class="flex items-start gap-3">

                <i
                    data-lucide="<?=
                        $messageType === 'success'
                        ? 'circle-check'
                        : 'circle-alert'
                    ?>"
                    class="mt-0.5 h-5 w-5"
                ></i>

                <div>

                    <p class="text-xs font-bold">

                        <?=
                            $messageType === 'success'
                            ? 'Success'
                            : 'Error'
                        ?>

                    </p>

                    <p class="mt-1 text-[11px]">
                        <?= htmlspecialchars($message) ?>
                    </p>

                </div>

            </div>


            <button
                type="button"
                onclick="
                    document
                    .getElementById('alertMessage')
                    .remove()
                "
            >

                <i
                    data-lucide="x"
                    class="h-4 w-4"
                ></i>

            </button>

        </div>

    <?php endif; ?>


    <!-- STAT CARDS -->

    <div
        class="
            mb-6 grid grid-cols-2
            gap-3 lg:grid-cols-4
        "
    >

        <div
            class="
                dashboard-card
                rounded-2xl border
                border-slate-200
                bg-white p-4
                shadow-soft
            "
        >

            <div
                class="
                    flex items-center
                    justify-between
                "
            >

                <div>

                    <p
                        class="
                            text-[10px]
                            font-semibold uppercase
                            text-slate-400
                        "
                    >
                        Total
                    </p>

                    <h3
                        class="
                            mt-1 text-2xl
                            font-extrabold
                        "
                    >
                        <?= $totalBusinesses ?>
                    </h3>

                </div>


                <div
                    class="
                        flex h-10 w-10
                        items-center justify-center
                        rounded-xl
                        bg-blue-50 text-blue-600
                    "
                >

                    <i
                        data-lucide="building-2"
                        class="h-5 w-5"
                    ></i>

                </div>

            </div>

        </div>


        <div
            class="
                dashboard-card
                rounded-2xl border
                border-slate-200
                bg-white p-4
                shadow-soft
            "
        >

            <div
                class="
                    flex items-center
                    justify-between
                "
            >

                <div>

                    <p
                        class="
                            text-[10px]
                            font-semibold uppercase
                            text-slate-400
                        "
                    >
                        Active
                    </p>

                    <h3
                        class="
                            mt-1 text-2xl
                            font-extrabold
                            text-emerald-600
                        "
                    >
                        <?= $activeBusinesses ?>
                    </h3>

                </div>


                <div
                    class="
                        flex h-10 w-10
                        items-center justify-center
                        rounded-xl
                        bg-emerald-50
                        text-emerald-600
                    "
                >

                    <i
                        data-lucide="circle-check"
                        class="h-5 w-5"
                    ></i>

                </div>

            </div>

        </div>


        <div
            class="
                dashboard-card
                rounded-2xl border
                border-slate-200
                bg-white p-4
                shadow-soft
            "
        >

            <div
                class="
                    flex items-center
                    justify-between
                "
            >

                <div>

                    <p
                        class="
                            text-[10px]
                            font-semibold uppercase
                            text-slate-400
                        "
                    >
                        Suspended
                    </p>

                    <h3
                        class="
                            mt-1 text-2xl
                            font-extrabold
                            text-amber-600
                        "
                    >
                        <?= $suspendedBusinesses ?>
                    </h3>

                </div>


                <div
                    class="
                        flex h-10 w-10
                        items-center justify-center
                        rounded-xl
                        bg-amber-50
                        text-amber-600
                    "
                >

                    <i
                        data-lucide="pause-circle"
                        class="h-5 w-5"
                    ></i>

                </div>

            </div>

        </div>


        <div
            class="
                dashboard-card
                rounded-2xl border
                border-slate-200
                bg-white p-4
                shadow-soft
            "
        >

            <div
                class="
                    flex items-center
                    justify-between
                "
            >

                <div>

                    <p
                        class="
                            text-[10px]
                            font-semibold uppercase
                            text-slate-400
                        "
                    >
                        Expired
                    </p>

                    <h3
                        class="
                            mt-1 text-2xl
                            font-extrabold
                            text-red-600
                        "
                    >
                        <?= $expiredBusinesses ?>
                    </h3>

                </div>


                <div
                    class="
                        flex h-10 w-10
                        items-center justify-center
                        rounded-xl
                        bg-red-50 text-red-600
                    "
                >

                    <i
                        data-lucide="calendar-x"
                        class="h-5 w-5"
                    ></i>

                </div>

            </div>

        </div>

    </div>


    <!-- MAIN CARD -->

    <section
        class="
            overflow-hidden rounded-2xl
            border border-slate-200
            bg-white shadow-soft
        "
    >


        <!-- TOOLBAR -->

        <div
            class="
                flex flex-col gap-3
                border-b border-slate-100
                p-4 md:flex-row
                md:items-center
                md:justify-between
            "
        >

            <div>

                <h3
                    class="
                        text-sm font-bold
                        text-slate-900
                    "
                >
                    All Businesses
                </h3>

                <p
                    class="
                        mt-1 text-[10px]
                        text-slate-500
                    "
                >
                    <?= count($businesses) ?>
                    businesses found
                </p>

            </div>


            <form
                method="GET"
                class="relative w-full md:w-72"
            >

                <i
                    data-lucide="search"
                    class="
                        absolute left-3 top-1/2
                        h-4 w-4
                        -translate-y-1/2
                        text-slate-400
                    "
                ></i>

                <input
                    id="businessSearch"
                    type="text"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search business..."
                    autocomplete="off"
                    class="
                        h-10 w-full
                        rounded-xl border
                        border-slate-200
                        bg-slate-50
                        pl-9 pr-9
                        text-xs outline-none
                        transition
                        focus:border-blue-400
                        focus:bg-white
                        focus:ring-4
                        focus:ring-blue-50
                    "
                >

                <?php if ($search !== ''): ?>

                    <a
                        href="businesses.php"
                        class="
                            absolute right-3 top-1/2
                            -translate-y-1/2
                            text-slate-400
                            hover:text-red-500
                        "
                    >

                        <i
                            data-lucide="x"
                            class="h-4 w-4"
                        ></i>

                    </a>

                <?php endif; ?>

            </form>

        </div>


        <!-- TABLE -->

        <div class="overflow-x-auto">

            <table
                class="
                    min-w-full
                    divide-y divide-slate-100
                "
            >

                <thead class="bg-slate-50/80">

                <tr>

                    <th
                        class="
                            px-5 py-3 text-left
                            text-[10px] font-bold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Business
                    </th>

                    <th
                        class="
                            px-5 py-3 text-left
                            text-[10px] font-bold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Business Admin
                    </th>

                    <th
                        class="
                            px-5 py-3 text-left
                            text-[10px] font-bold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Branches
                    </th>

                    <th
                        class="
                            px-5 py-3 text-left
                            text-[10px] font-bold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Users
                    </th>

                    <th
                        class="
                            px-5 py-3 text-left
                            text-[10px] font-bold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Status
                    </th>

                    <th
                        class="
                            px-5 py-3 text-left
                            text-[10px] font-bold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Created
                    </th>

                    <th
                        class="
                            px-5 py-3 text-right
                            text-[10px] font-bold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Actions
                    </th>

                </tr>

                </thead>


                <tbody
                    class="
                        divide-y
                        divide-slate-100
                    "
                >

                <?php if (!$businesses): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="px-5 py-16"
                        >

                            <div
                                class="
                                    flex flex-col
                                    items-center
                                    text-center
                                "
                            >

                                <div
                                    class="
                                        flex h-16 w-16
                                        items-center justify-center
                                        rounded-2xl
                                        bg-slate-100
                                        text-slate-400
                                    "
                                >

                                    <i
                                        data-lucide="building-2"
                                        class="h-7 w-7"
                                    ></i>

                                </div>

                                <h4
                                    class="
                                        mt-4 text-sm
                                        font-bold
                                    "
                                >
                                    No businesses found
                                </h4>

                                <p
                                    class="
                                        mt-1 max-w-sm
                                        text-[11px]
                                        text-slate-500
                                    "
                                >
                                    Add your first business to
                                    start using the system.
                                </p>

                                <button
                                    type="button"
                                    class="
                                        openBusinessModal
                                        mt-4 flex items-center
                                        gap-2 rounded-xl
                                        bg-blue-600 px-4
                                        py-2.5 text-xs
                                        font-semibold text-white
                                    "
                                >

                                    <i
                                        data-lucide="plus"
                                        class="h-4 w-4"
                                    ></i>

                                    Add Business

                                </button>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach ($businesses as $business): ?>

                        <?php

                        $initial =
                            strtoupper(
                                substr(
                                    $business['business_name'],
                                    0,
                                    1
                                )
                            );

                        ?>

                        <tr
                            class="
                                transition
                                hover:bg-slate-50/70
                            "
                        >

                            <!-- BUSINESS -->

                            <td class="px-5 py-4">

                                <div
                                    class="
                                        flex items-center
                                        gap-3
                                    "
                                >

                                    <div
                                        class="
                                            flex h-10 w-10
                                            flex-shrink-0
                                            items-center
                                            justify-center
                                            rounded-xl
                                            bg-gradient-to-br
                                            from-blue-500
                                            to-indigo-600
                                            text-xs font-bold
                                            text-white
                                        "
                                    >
                                        <?= htmlspecialchars($initial) ?>
                                    </div>


                                    <div>

                                        <p
                                            class="
                                                text-xs
                                                font-bold
                                                text-slate-800
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $business['business_name']
                                            ) ?>
                                        </p>

                                        <p
                                            class="
                                                mt-1 text-[10px]
                                                text-slate-500
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $business['phone']
                                                ?: 'No phone'
                                            ) ?>
                                        </p>

                                    </div>

                                </div>

                            </td>


                            <!-- ADMIN -->

                            <td class="px-5 py-4">

                                <p
                                    class="
                                        text-xs
                                        font-semibold
                                        text-slate-700
                                    "
                                >
                                    <?= htmlspecialchars(
                                        $business['admin_name']
                                        ?: 'Not assigned'
                                    ) ?>
                                </p>

                                <p
                                    class="
                                        mt-1 text-[10px]
                                        text-slate-400
                                    "
                                >
                                    <?= htmlspecialchars(
                                        $business['admin_username']
                                        ?: '-'
                                    ) ?>
                                </p>

                            </td>


                            <!-- BRANCHES -->

                            <td class="px-5 py-4">

                                <span
                                    class="
                                        inline-flex items-center
                                        gap-1.5 rounded-lg
                                        bg-blue-50
                                        px-2.5 py-1.5
                                        text-[10px]
                                        font-semibold
                                        text-blue-700
                                    "
                                >

                                    <i
                                        data-lucide="git-branch"
                                        class="h-3 w-3"
                                    ></i>

                                    <?= $business['total_branches'] ?>

                                </span>

                            </td>


                            <!-- USERS -->

                            <td class="px-5 py-4">

                                <span
                                    class="
                                        inline-flex items-center
                                        gap-1.5 text-[11px]
                                        text-slate-600
                                    "
                                >

                                    <i
                                        data-lucide="users"
                                        class="h-3.5 w-3.5"
                                    ></i>

                                    <?= $business['total_users'] ?>

                                </span>

                            </td>


                            <!-- STATUS -->

                            <td class="px-5 py-4">

                                <?php if ($business['status'] === 'Active'): ?>

                                    <span
                                        class="
                                            inline-flex
                                            items-center gap-1.5
                                            rounded-full
                                            bg-emerald-50
                                            px-2.5 py-1
                                            text-[10px]
                                            font-semibold
                                            text-emerald-700
                                        "
                                    >

                                        <span
                                            class="
                                                h-1.5 w-1.5
                                                rounded-full
                                                bg-emerald-500
                                            "
                                        ></span>

                                        Active

                                    </span>

                                <?php elseif (
                                    $business['status'] === 'Suspended'
                                ): ?>

                                    <span
                                        class="
                                            inline-flex
                                            items-center gap-1.5
                                            rounded-full
                                            bg-amber-50
                                            px-2.5 py-1
                                            text-[10px]
                                            font-semibold
                                            text-amber-700
                                        "
                                    >

                                        <span
                                            class="
                                                h-1.5 w-1.5
                                                rounded-full
                                                bg-amber-500
                                            "
                                        ></span>

                                        Suspended

                                    </span>

                                <?php else: ?>

                                    <span
                                        class="
                                            inline-flex
                                            items-center gap-1.5
                                            rounded-full
                                            bg-red-50
                                            px-2.5 py-1
                                            text-[10px]
                                            font-semibold
                                            text-red-700
                                        "
                                    >

                                        <span
                                            class="
                                                h-1.5 w-1.5
                                                rounded-full
                                                bg-red-500
                                            "
                                        ></span>

                                        Expired

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- CREATED -->

                            <td
                                class="
                                    whitespace-nowrap
                                    px-5 py-4
                                    text-[10px]
                                    text-slate-500
                                "
                            >
                                <?= date(
                                    'd M Y',
                                    strtotime(
                                        $business['created_at']
                                    )
                                ) ?>
                            </td>


                            <!-- ACTION -->

                            <td class="px-5 py-4">

                                <div
                                    class="
                                        flex items-center
                                        justify-end gap-1
                                    "
                                >

                                    <a
                                        href="
                                            business-view.php?id=
                                            <?= $business['id'] ?>
                                        "
                                        title="View"
                                        class="
                                            flex h-8 w-8
                                            items-center
                                            justify-center
                                            rounded-lg
                                            text-slate-400
                                            transition
                                            hover:bg-blue-50
                                            hover:text-blue-600
                                        "
                                    >

                                        <i
                                            data-lucide="eye"
                                            class="h-4 w-4"
                                        ></i>

                                    </a>


                                    <button
                                        type="button"
                                        title="Change status"
                                        class="
                                            statusButton
                                            flex h-8 w-8
                                            items-center
                                            justify-center
                                            rounded-lg
                                            text-slate-400
                                            transition
                                            hover:bg-amber-50
                                            hover:text-amber-600
                                        "

                                        data-id="<?= $business['id'] ?>"

                                        data-name="<?=
                                            htmlspecialchars(
                                                $business['business_name'],
                                                ENT_QUOTES
                                            )
                                        ?>"

                                        data-status="<?=
                                            htmlspecialchars(
                                                $business['status'],
                                                ENT_QUOTES
                                            )
                                        ?>"
                                    >

                                        <i
                                            data-lucide="power"
                                            class="h-4 w-4"
                                        ></i>

                                    </button>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>


<?php require_once 'includes/footer.php'; ?>

</div>


<!-- ======================================================
     ADD BUSINESS MODAL
======================================================= -->

<div
    id="businessModal"
    class="
        fixed inset-0 z-[100]
        hidden items-center
        justify-center
        bg-slate-950/60
        p-3 backdrop-blur-sm
    "
>

    <div
        id="businessModalPanel"
        class="
            max-h-[94vh] w-full
            max-w-3xl
            translate-y-5
            overflow-y-auto
            rounded-2xl
            bg-white opacity-0
            shadow-2xl
            transition-all
            duration-300
        "
    >


        <!-- MODAL HEADER -->

        <div
            class="
                sticky top-0 z-10
                flex items-center
                justify-between
                border-b
                border-slate-100
                bg-white/95
                px-5 py-4
                backdrop-blur-xl
            "
        >

            <div class="flex items-center gap-3">

                <div
                    class="
                        flex h-10 w-10
                        items-center justify-center
                        rounded-xl
                        bg-blue-50
                        text-blue-600
                    "
                >

                    <i
                        data-lucide="building-2"
                        class="h-5 w-5"
                    ></i>

                </div>

                <div>

                    <h3
                        class="
                            text-sm font-bold
                            text-slate-900
                        "
                    >
                        Register New Business
                    </h3>

                    <p
                        class="
                            mt-0.5 text-[10px]
                            text-slate-500
                        "
                    >
                        Business + Main Branch + Business Admin + Subscription
                    </p>

                </div>

            </div>


            <button
                id="closeBusinessModal"
                type="button"
                class="
                    flex h-9 w-9
                    items-center justify-center
                    rounded-xl
                    text-slate-400
                    transition
                    hover:bg-slate-100
                    hover:text-slate-700
                "
            >

                <i
                    data-lucide="x"
                    class="h-5 w-5"
                ></i>

            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="add_business"
                value="1"
            >


            <div class="p-5">


                <!-- STEP INDICATOR -->

                <div
                    class="
                        mb-6 grid
                        grid-cols-2 gap-2 sm:grid-cols-4
                    "
                >

                    <div
                        class="
                            rounded-xl
                            bg-blue-50 p-3
                        "
                    >

                        <span
                            class="
                                flex h-6 w-6
                                items-center
                                justify-center
                                rounded-full
                                bg-blue-600
                                text-[10px]
                                font-bold
                                text-white
                            "
                        >
                            1
                        </span>

                        <p
                            class="
                                mt-2 text-[10px]
                                font-bold
                                text-blue-800
                            "
                        >
                            Business
                        </p>

                    </div>


                    <div
                        class="
                            rounded-xl
                            bg-emerald-50
                            p-3
                        "
                    >

                        <span
                            class="
                                flex h-6 w-6
                                items-center
                                justify-center
                                rounded-full
                                bg-emerald-600
                                text-[10px]
                                font-bold
                                text-white
                            "
                        >
                            2
                        </span>

                        <p
                            class="
                                mt-2 text-[10px]
                                font-bold
                                text-emerald-800
                            "
                        >
                            Main Branch
                        </p>

                    </div>


                    <div
                        class="
                            rounded-xl
                            bg-violet-50
                            p-3
                        "
                    >

                        <span
                            class="
                                flex h-6 w-6
                                items-center
                                justify-center
                                rounded-full
                                bg-violet-600
                                text-[10px]
                                font-bold
                                text-white
                            "
                        >
                            3
                        </span>

                        <p
                            class="
                                mt-2 text-[10px]
                                font-bold
                                text-violet-800
                            "
                        >
                            Admin Account
                        </p>

                    </div>


                    <div
                        class="
                            rounded-xl
                            bg-amber-50
                            p-3
                        "
                    >

                        <span
                            class="
                                flex h-6 w-6
                                items-center
                                justify-center
                                rounded-full
                                bg-amber-500
                                text-[10px]
                                font-bold
                                text-white
                            "
                        >
                            4
                        </span>

                        <p
                            class="
                                mt-2 text-[10px]
                                font-bold
                                text-amber-800
                            "
                        >
                            Subscription
                        </p>

                    </div>

                </div>


                <!-- BUSINESS -->

                <div
                    class="
                        rounded-2xl
                        border border-slate-200
                        p-4
                    "
                >

                    <div
                        class="
                            mb-4 flex
                            items-center gap-2
                        "
                    >

                        <i
                            data-lucide="store"
                            class="
                                h-4 w-4
                                text-blue-600
                            "
                        ></i>

                        <h4
                            class="
                                text-xs font-bold
                            "
                        >
                            Business Information
                        </h4>

                    </div>


                    <div
                        class="
                            grid grid-cols-1
                            gap-4 md:grid-cols-2
                        "
                    >

                        <div>

                            <label
                                class="
                                    mb-1.5 block
                                    text-[11px]
                                    font-semibold
                                    text-slate-600
                                "
                            >
                                Business Name
                                <span class="text-red-500">*</span>
                            </label>

                            <input
                                type="text"
                                name="business_name"
                                required
                                placeholder="Example: Kulmiye Trading"
                                class="
                                    h-11 w-full
                                    rounded-xl border
                                    border-slate-200
                                    px-3 text-xs
                                    outline-none
                                    transition
                                    focus:border-blue-400
                                    focus:ring-4
                                    focus:ring-blue-50
                                "
                            >

                        </div>


                        <div>

                            <label
                                class="
                                    mb-1.5 block
                                    text-[11px]
                                    font-semibold
                                    text-slate-600
                                "
                            >
                                Phone
                            </label>

                            <input
                                type="text"
                                name="phone"
                                placeholder="063xxxxxxx"
                                class="
                                    h-11 w-full
                                    rounded-xl border
                                    border-slate-200
                                    px-3 text-xs
                                    outline-none
                                    transition
                                    focus:border-blue-400
                                    focus:ring-4
                                    focus:ring-blue-50
                                "
                            >

                        </div>


                        <div>

                            <label
                                class="
                                    mb-1.5 block
                                    text-[11px]
                                    font-semibold
                                    text-slate-600
                                "
                            >
                                Email
                            </label>

                            <input
                                type="email"
                                name="email"
                                placeholder="business@example.com"
                                class="
                                    h-11 w-full
                                    rounded-xl border
                                    border-slate-200
                                    px-3 text-xs
                                    outline-none
                                    transition
                                    focus:border-blue-400
                                    focus:ring-4
                                    focus:ring-blue-50
                                "
                            >

                        </div>


                        <div>

                            <label
                                class="
                                    mb-1.5 block
                                    text-[11px]
                                    font-semibold
                                    text-slate-600
                                "
                            >
                                Address
                            </label>

                            <input
                                type="text"
                                name="address"
                                placeholder="Business address"
                                class="
                                    h-11 w-full
                                    rounded-xl border
                                    border-slate-200
                                    px-3 text-xs
                                    outline-none
                                    transition
                                    focus:border-blue-400
                                    focus:ring-4
                                    focus:ring-blue-50
                                "
                            >

                        </div>

                    </div>

                </div>


                <!-- MAIN BRANCH -->

                <div
                    class="
                        mt-4 flex items-start
                        gap-3 rounded-2xl
                        border border-emerald-100
                        bg-emerald-50/60
                        p-4
                    "
                >

                    <div
                        class="
                            flex h-9 w-9
                            flex-shrink-0
                            items-center
                            justify-center
                            rounded-xl
                            bg-emerald-100
                            text-emerald-600
                        "
                    >

                        <i
                            data-lucide="git-branch"
                            class="h-4 w-4"
                        ></i>

                    </div>


                    <div>

                        <h4
                            class="
                                text-xs font-bold
                                text-emerald-900
                            "
                        >
                            Main Branch
                        </h4>

                        <p
                            class="
                                mt-1 text-[10px]
                                leading-5
                                text-emerald-700
                            "
                        >
                            System-ku automatic ayuu business-kan
                            ugu samaynayaa Main Branch.
                            Branches kale Business Admin-ku
                            dambe ayuu ku dari karaa.
                        </p>

                    </div>

                </div>


                <!-- ADMIN -->

                <div
                    class="
                        mt-4 rounded-2xl
                        border border-slate-200
                        p-4
                    "
                >

                    <div
                        class="
                            mb-4 flex
                            items-center gap-2
                        "
                    >

                        <i
                            data-lucide="user-cog"
                            class="
                                h-4 w-4
                                text-violet-600
                            "
                        ></i>

                        <h4
                            class="
                                text-xs font-bold
                            "
                        >
                            Business Admin Account
                        </h4>

                    </div>


                    <div
                        class="
                            grid grid-cols-1
                            gap-4 md:grid-cols-2
                        "
                    >

                        <div>

                            <label
                                class="
                                    mb-1.5 block
                                    text-[11px]
                                    font-semibold
                                    text-slate-600
                                "
                            >
                                Admin Full Name
                                <span class="text-red-500">*</span>
                            </label>

                            <input
                                type="text"
                                name="admin_name"
                                required
                                placeholder="Full name"
                                class="
                                    h-11 w-full
                                    rounded-xl border
                                    border-slate-200
                                    px-3 text-xs
                                    outline-none
                                    transition
                                    focus:border-violet-400
                                    focus:ring-4
                                    focus:ring-violet-50
                                "
                            >

                        </div>


                        <div>

                            <label
                                class="
                                    mb-1.5 block
                                    text-[11px]
                                    font-semibold
                                    text-slate-600
                                "
                            >
                                Username
                                <span class="text-red-500">*</span>
                            </label>

                            <input
                                type="text"
                                name="admin_username"
                                required
                                autocomplete="off"
                                placeholder="kulmiye_admin"
                                class="
                                    h-11 w-full
                                    rounded-xl border
                                    border-slate-200
                                    px-3 text-xs
                                    outline-none
                                    transition
                                    focus:border-violet-400
                                    focus:ring-4
                                    focus:ring-violet-50
                                "
                            >

                        </div>


                        <div class="md:col-span-2">

                            <label
                                class="
                                    mb-1.5 block
                                    text-[11px]
                                    font-semibold
                                    text-slate-600
                                "
                            >
                                Password
                                <span class="text-red-500">*</span>
                            </label>


                            <div class="relative">

                                <input
                                    id="adminPassword"
                                    type="password"
                                    name="admin_password"
                                    required
                                    minlength="6"
                                    autocomplete="new-password"
                                    placeholder="Minimum 6 characters"
                                    class="
                                        h-11 w-full
                                        rounded-xl border
                                        border-slate-200
                                        px-3 pr-11
                                        text-xs outline-none
                                        transition
                                        focus:border-violet-400
                                        focus:ring-4
                                        focus:ring-violet-50
                                    "
                                >

                                <button
                                    id="togglePassword"
                                    type="button"
                                    class="
                                        absolute right-1
                                        top-1/2
                                        flex h-9 w-9
                                        -translate-y-1/2
                                        items-center
                                        justify-center
                                        rounded-lg
                                        text-slate-400
                                        hover:bg-slate-100
                                    "
                                >

                                    <i
                                        data-lucide="eye"
                                        class="h-4 w-4"
                                    ></i>

                                </button>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- SUBSCRIPTION -->

                <div
                    class="
                        mt-4 rounded-2xl
                        border border-slate-200
                        p-4
                    "
                >

                    <div
                        class="
                            mb-4 flex
                            items-center gap-2
                        "
                    >

                        <i
                            data-lucide="credit-card"
                            class="
                                h-4 w-4
                                text-amber-600
                            "
                        ></i>

                        <div>
                            <h4 class="text-xs font-bold">
                                Subscription Plan
                            </h4>

                            <p class="mt-0.5 text-[10px] text-slate-500">
                                Business-ku isla marka la sameeyo plan-kan ayuu helayaa.
                            </p>
                        </div>

                    </div>


                    <?php if (!$subscriptionPlans): ?>

                        <div
                            class="
                                rounded-xl
                                border border-red-200
                                bg-red-50
                                p-3
                                text-[10px]
                                font-semibold
                                text-red-700
                            "
                        >
                            Active subscription plan ma jiro.
                            Marka hore ka samee
                            <a
                                href="plans.php"
                                class="underline"
                            >
                                Subscription Plans
                            </a>.
                        </div>

                    <?php else: ?>

                        <div
                            class="
                                grid grid-cols-1
                                gap-4 md:grid-cols-2
                            "
                        >

                            <div>

                                <label
                                    class="
                                        mb-1.5 block
                                        text-[11px]
                                        font-semibold
                                        text-slate-600
                                    "
                                >
                                    Select Plan
                                    <span class="text-red-500">*</span>
                                </label>

                                <select
                                    id="businessPlan"
                                    name="plan_id"
                                    required
                                    class="
                                        h-11 w-full
                                        rounded-xl border
                                        border-slate-200
                                        bg-white px-3
                                        text-xs outline-none
                                        transition
                                        focus:border-amber-400
                                        focus:ring-4
                                        focus:ring-amber-50
                                    "
                                >

                                    <option value="">
                                        Select subscription plan
                                    </option>

                                    <?php foreach ($subscriptionPlans as $plan): ?>

                                        <option
                                            value="<?= $plan['id'] ?>"
                                            data-name="<?=
                                                htmlspecialchars(
                                                    $plan['plan_name'],
                                                    ENT_QUOTES
                                                )
                                            ?>"
                                            data-months="<?= (int) $plan['duration_months'] ?>"
                                            data-price="<?= (float) $plan['price'] ?>"
                                            data-branches="<?=
                                                $plan['max_branches'] === null
                                                ? 'Unlimited'
                                                : (int) $plan['max_branches']
                                            ?>"
                                        >
                                            <?= htmlspecialchars($plan['plan_name']) ?>
                                            -
                                            $<?= number_format($plan['price'], 2) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <div>

                                <label
                                    class="
                                        mb-1.5 block
                                        text-[11px]
                                        font-semibold
                                        text-slate-600
                                    "
                                >
                                    Start Date
                                    <span class="text-red-500">*</span>
                                </label>

                                <input
                                    id="subscriptionStartDate"
                                    type="date"
                                    name="start_date"
                                    value="<?= date('Y-m-d') ?>"
                                    required
                                    class="
                                        h-11 w-full
                                        rounded-xl border
                                        border-slate-200
                                        bg-white px-3
                                        text-xs outline-none
                                        transition
                                        focus:border-amber-400
                                        focus:ring-4
                                        focus:ring-amber-50
                                    "
                                >

                            </div>

                        </div>


                        <!-- SUBSCRIPTION PREVIEW -->

                        <div
                            class="
                                mt-4 grid
                                grid-cols-2 gap-3
                                rounded-xl
                                bg-amber-50/70
                                p-3
                                sm:grid-cols-4
                            "
                        >

                            <div>
                                <p class="text-[9px] font-bold uppercase text-amber-700/60">
                                    Plan
                                </p>
                                <p
                                    id="businessPlanPreviewName"
                                    class="mt-1 text-[11px] font-bold text-slate-800"
                                >
                                    -
                                </p>
                            </div>

                            <div>
                                <p class="text-[9px] font-bold uppercase text-amber-700/60">
                                    Price
                                </p>
                                <p
                                    id="businessPlanPreviewPrice"
                                    class="mt-1 text-[11px] font-extrabold text-blue-600"
                                >
                                    $0.00
                                </p>
                            </div>

                            <div>
                                <p class="text-[9px] font-bold uppercase text-amber-700/60">
                                    Expiry
                                </p>
                                <p
                                    id="businessPlanPreviewExpiry"
                                    class="mt-1 text-[11px] font-bold text-slate-800"
                                >
                                    -
                                </p>
                            </div>

                            <div>
                                <p class="text-[9px] font-bold uppercase text-amber-700/60">
                                    Max Branches
                                </p>
                                <p
                                    id="businessPlanPreviewBranches"
                                    class="mt-1 text-[11px] font-bold text-slate-800"
                                >
                                    -
                                </p>
                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- MODAL FOOTER -->

            <div
                class="
                    sticky bottom-0
                    flex flex-col-reverse
                    gap-2 border-t
                    border-slate-100
                    bg-white/95
                    px-5 py-4
                    backdrop-blur-xl
                    sm:flex-row
                    sm:justify-end
                "
            >

                <button
                    id="cancelBusinessModal"
                    type="button"
                    class="
                        h-11 rounded-xl
                        border border-slate-200
                        px-5 text-xs
                        font-semibold
                        text-slate-600
                        transition
                        hover:bg-slate-50
                    "
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="
                        flex h-11
                        items-center
                        justify-center gap-2
                        rounded-xl
                        bg-blue-600 px-5
                        text-xs font-semibold
                        text-white
                        shadow-lg
                        shadow-blue-600/20
                        transition
                        hover:bg-blue-700
                    "
                >

                    <i
                        data-lucide="circle-check"
                        class="h-4 w-4"
                    ></i>

                    Create Business

                </button>

            </div>

        </form>

    </div>

</div>


<!-- ======================================================
     STATUS MODAL
======================================================= -->

<div
    id="statusModal"
    class="
        fixed inset-0 z-[110]
        hidden items-center
        justify-center
        bg-slate-950/60
        p-4 backdrop-blur-sm
    "
>

    <div
        class="
            w-full max-w-md
            rounded-2xl bg-white
            p-5 shadow-2xl
        "
    >

        <div
            class="
                flex items-center
                justify-between
            "
        >

            <div>

                <h3
                    class="
                        text-sm font-bold
                    "
                >
                    Business Status
                </h3>

                <p
                    id="statusBusinessName"
                    class="
                        mt-1 text-[11px]
                        text-slate-500
                    "
                ></p>

            </div>


            <button
                id="closeStatusModal"
                type="button"
                class="
                    flex h-9 w-9
                    items-center
                    justify-center
                    rounded-xl
                    hover:bg-slate-100
                "
            >

                <i
                    data-lucide="x"
                    class="h-4 w-4"
                ></i>

            </button>

        </div>


        <form
            method="POST"
            class="mt-5"
        >

            <input
                type="hidden"
                name="change_status"
                value="1"
            >

            <input
                id="statusBusinessId"
                type="hidden"
                name="business_id"
            >


            <label
                class="
                    mb-2 block
                    text-[11px]
                    font-semibold
                    text-slate-600
                "
            >
                Select Status
            </label>


            <select
                id="newStatus"
                name="new_status"
                class="
                    h-11 w-full
                    rounded-xl border
                    border-slate-200
                    px-3 text-xs
                    outline-none
                    focus:border-blue-400
                    focus:ring-4
                    focus:ring-blue-50
                "
            >

                <option value="Active">
                    Active
                </option>

                <option value="Suspended">
                    Suspended
                </option>

                <option value="Expired">
                    Expired
                </option>

            </select>


            <div
                class="
                    mt-5 flex
                    justify-end gap-2
                "
            >

                <button
                    id="cancelStatusModal"
                    type="button"
                    class="
                        h-10 rounded-xl
                        border border-slate-200
                        px-4 text-xs
                        font-semibold
                    "
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="
                        h-10 rounded-xl
                        bg-blue-600
                        px-4 text-xs
                        font-semibold
                        text-white
                        hover:bg-blue-700
                    "
                >
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>


<script src="../assets/js/app.js"></script>


<script>

lucide.createIcons();


/* =========================================================
   BUSINESS MODAL
========================================================= */

const businessModal =
    document.getElementById('businessModal');

const businessModalPanel =
    document.getElementById('businessModalPanel');

const openAddBusiness =
    document.getElementById('openAddBusiness');

const openBusinessButtons =
    document.querySelectorAll('.openBusinessModal');

const closeBusinessModal =
    document.getElementById('closeBusinessModal');

const cancelBusinessModal =
    document.getElementById('cancelBusinessModal');


function showBusinessModal() {

    businessModal.classList.remove('hidden');

    businessModal.classList.add('flex');

    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {

        businessModalPanel.classList.remove(
            'translate-y-5',
            'opacity-0'
        );
    });
}


function hideBusinessModal() {

    businessModalPanel.classList.add(
        'translate-y-5',
        'opacity-0'
    );

    setTimeout(() => {

        businessModal.classList.add('hidden');

        businessModal.classList.remove('flex');

        document.body.style.overflow = '';

    }, 250);
}


openAddBusiness?.addEventListener(
    'click',
    showBusinessModal
);


openBusinessButtons.forEach(button => {

    button.addEventListener(
        'click',
        showBusinessModal
    );
});


closeBusinessModal?.addEventListener(
    'click',
    hideBusinessModal
);


cancelBusinessModal?.addEventListener(
    'click',
    hideBusinessModal
);


businessModal?.addEventListener(
    'click',
    function(event) {

        if (event.target === businessModal) {
            hideBusinessModal();
        }
    }
);


/* =========================================================
   AUTO OPEN FROM DASHBOARD
   businesses.php?action=add
========================================================= */

const params =
    new URLSearchParams(
        window.location.search
    );

if (params.get('action') === 'add') {

    showBusinessModal();
}


/* =========================================================
   PASSWORD
========================================================= */

const adminPassword =
    document.getElementById('adminPassword');

const togglePassword =
    document.getElementById('togglePassword');


togglePassword?.addEventListener(
    'click',
    function() {

        adminPassword.type =
            adminPassword.type === 'password'
            ? 'text'
            : 'password';

    }
);


/* =========================================================
   SUBSCRIPTION PREVIEW
========================================================= */

const businessPlan =
    document.getElementById('businessPlan');

const subscriptionStartDate =
    document.getElementById('subscriptionStartDate');

const businessPlanPreviewName =
    document.getElementById('businessPlanPreviewName');

const businessPlanPreviewPrice =
    document.getElementById('businessPlanPreviewPrice');

const businessPlanPreviewExpiry =
    document.getElementById('businessPlanPreviewExpiry');

const businessPlanPreviewBranches =
    document.getElementById('businessPlanPreviewBranches');


function updateBusinessPlanPreview() {

    if (!businessPlan) {
        return;
    }

    const selectedOption =
        businessPlan.options[
            businessPlan.selectedIndex
        ];

    const planName =
        selectedOption?.dataset?.name || '-';

    const months =
        parseInt(
            selectedOption?.dataset?.months || 0
        );

    const price =
        parseFloat(
            selectedOption?.dataset?.price || 0
        );

    const branches =
        selectedOption?.dataset?.branches || '-';


    if (businessPlanPreviewName) {
        businessPlanPreviewName.textContent =
            planName;
    }

    if (businessPlanPreviewPrice) {
        businessPlanPreviewPrice.textContent =
            '$' + price.toFixed(2);
    }

    if (businessPlanPreviewBranches) {

        businessPlanPreviewBranches.textContent =
            branches === 'Unlimited'
            ? 'Unlimited'
            : (
                branches === '-'
                ? '-'
                : branches + ' Branch' + (
                    parseInt(branches) === 1
                    ? ''
                    : 'es'
                )
            );
    }


    if (
        businessPlanPreviewExpiry
        && subscriptionStartDate?.value
        && months > 0
    ) {

        const start =
            new Date(
                subscriptionStartDate.value
                + 'T00:00:00'
            );

        const originalDay =
            start.getDate();

        const target =
            new Date(start);

        target.setDate(1);

        target.setMonth(
            target.getMonth() + months
        );

        const lastDay =
            new Date(
                target.getFullYear(),
                target.getMonth() + 1,
                0
            ).getDate();

        target.setDate(
            Math.min(
                originalDay,
                lastDay
            )
        );

        businessPlanPreviewExpiry.textContent =
            target.toLocaleDateString(
                'en-GB',
                {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                }
            );

    } else if (businessPlanPreviewExpiry) {

        businessPlanPreviewExpiry.textContent =
            '-';
    }
}


businessPlan?.addEventListener(
    'change',
    updateBusinessPlanPreview
);

subscriptionStartDate?.addEventListener(
    'change',
    updateBusinessPlanPreview
);

updateBusinessPlanPreview();


/* =========================================================
   STATUS MODAL
========================================================= */

const statusModal =
    document.getElementById('statusModal');

const statusButtons =
    document.querySelectorAll('.statusButton');

const statusBusinessId =
    document.getElementById('statusBusinessId');

const statusBusinessName =
    document.getElementById('statusBusinessName');

const newStatus =
    document.getElementById('newStatus');

const closeStatusModal =
    document.getElementById('closeStatusModal');

const cancelStatusModal =
    document.getElementById('cancelStatusModal');


function hideStatusModal() {

    statusModal.classList.add('hidden');

    statusModal.classList.remove('flex');

    document.body.style.overflow = '';
}


statusButtons.forEach(button => {

    button.addEventListener(
        'click',
        function() {

            statusBusinessId.value =
                this.dataset.id;

            statusBusinessName.textContent =
                this.dataset.name;

            newStatus.value =
                this.dataset.status;

            statusModal.classList.remove('hidden');

            statusModal.classList.add('flex');

            document.body.style.overflow = 'hidden';
        }
    );

});


closeStatusModal?.addEventListener(
    'click',
    hideStatusModal
);


cancelStatusModal?.addEventListener(
    'click',
    hideStatusModal
);


statusModal?.addEventListener(
    'click',
    function(event) {

        if (event.target === statusModal) {
            hideStatusModal();
        }
    }
);


/* =========================================================
   ESC KEY
========================================================= */

document.addEventListener(
    'keydown',
    function(event) {

        if (event.key === 'Escape') {

            if (
                !businessModal.classList.contains('hidden')
            ) {
                hideBusinessModal();
            }

            if (
                !statusModal.classList.contains('hidden')
            ) {
                hideStatusModal();
            }
        }
    }
);

</script>

</body>
</html>