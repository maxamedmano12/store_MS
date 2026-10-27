<?php

session_start();
require_once '../config/database.php';

require_once '../config/auth.php';

requireSuperAdmin();
$businessId = (int) ($_GET['id'] ?? 0);

if ($businessId <= 0) {
    header('Location: businesses.php');
    exit;
}


/* =========================================================
   BUSINESS INFO
========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        business_name,
        phone,
        email,
        address,
        status,
        created_at
    FROM businesses
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$businessId]);
$business = $stmt->fetch();

if (!$business) {
    header('Location: businesses.php');
    exit;
}


/* =========================================================
   BUSINESS ADMIN
========================================================= */

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.status,
        u.created_at
    FROM users u
    INNER JOIN roles r
        ON r.id = u.role_id
    WHERE
        u.business_id = ?
        AND r.role_name = 'Business Admin'
    ORDER BY u.id ASC
    LIMIT 1
");

$stmt->execute([$businessId]);
$businessAdmin = $stmt->fetch();


/* =========================================================
   LATEST SUBSCRIPTION
========================================================= */

$stmt = $conn->prepare("
    SELECT
        s.id,
        s.start_date,
        s.expiry_date,
        s.amount,
        s.status,
        s.created_at,
        sp.plan_name,
        sp.duration_months,
        sp.max_branches
    FROM subscriptions s
    INNER JOIN subscription_plans sp
        ON sp.id = s.plan_id
    WHERE s.business_id = ?
    ORDER BY s.id DESC
    LIMIT 1
");

$stmt->execute([$businessId]);
$subscription = $stmt->fetch();


/* =========================================================
   BRANCHES
========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        branch_name,
        is_main,
        status,
        created_at
    FROM branches
    WHERE business_id = ?
    ORDER BY is_main DESC, id ASC
");

$stmt->execute([$businessId]);
$branches = $stmt->fetchAll();


/* =========================================================
   USERS
========================================================= */

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.status,
        u.created_at,
        r.role_name,
        br.branch_name
    FROM users u
    INNER JOIN roles r
        ON r.id = u.role_id
    LEFT JOIN branches br
        ON br.id = u.branch_id
    WHERE u.business_id = ?
    ORDER BY u.id DESC
");

$stmt->execute([$businessId]);
$users = $stmt->fetchAll();


/* =========================================================
   COUNTS
========================================================= */

$totalBranches = count($branches);
$totalUsers = count($users);

$activeBranches = 0;
$activeUsers = 0;

foreach ($branches as $branch) {
    if ($branch['status'] === 'Active') {
        $activeBranches++;
    }
}

foreach ($users as $user) {
    if ($user['status'] === 'Active') {
        $activeUsers++;
    }
}


/* =========================================================
   SUBSCRIPTION DAYS
========================================================= */

$daysRemaining = null;
$isExpiredByDate = false;

if ($subscription) {

    $today = new DateTime(date('Y-m-d'));
    $expiry = new DateTime($subscription['expiry_date']);

    if ($expiry < $today) {
        $isExpiredByDate = true;
        $daysRemaining = 0;
    } else {
        $daysRemaining = (int) $today->diff($expiry)->format('%a');
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

    <title>
        <?= htmlspecialchars($business['business_name']) ?> - Business View
    </title>

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
                        soft: '0 10px 35px rgba(15,23,42,.06)'
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


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div
        class="
            mb-6
            flex flex-col
            gap-4
            xl:flex-row
            xl:items-center
            xl:justify-between
        "
    >

        <div>

            <div
                class="
                    mb-2
                    flex items-center
                    gap-2
                    text-[11px]
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

                <a
                    href="businesses.php"
                    class="hover:text-blue-600"
                >
                    Businesses
                </a>

                <i
                    data-lucide="chevron-right"
                    class="h-3 w-3"
                ></i>

                <span>
                    View
                </span>

            </div>


            <div class="flex items-center gap-3">

                <div
                    class="
                        flex
                        h-12 w-12
                        items-center
                        justify-center
                        rounded-2xl
                        bg-gradient-to-br
                        from-blue-500
                        to-indigo-600
                        text-base
                        font-extrabold
                        text-white
                        shadow-lg
                        shadow-blue-600/20
                    "
                >
                    <?= htmlspecialchars(strtoupper(substr($business['business_name'], 0, 1))) ?>
                </div>


                <div>

                    <h1
                        class="
                            text-2xl
                            font-extrabold
                            tracking-tight
                            text-slate-900
                            dark:text-white
                        "
                    >
                        <?= htmlspecialchars($business['business_name']) ?>
                    </h1>

                    <p class="mt-1 text-[11px] text-slate-500">
                        Business ID: #<?= $business['id'] ?>
                    </p>

                </div>

            </div>

        </div>


        <div
            class="
                flex
                flex-col
                gap-2
                sm:flex-row
            "
        >

            <a
                href="businesses.php"
                class="
                    flex h-11
                    items-center
                    justify-center
                    gap-2
                    rounded-xl
                    border
                    border-slate-200
                    bg-white
                    px-4
                    text-xs
                    font-semibold
                    text-slate-600
                    shadow-sm
                    transition
                    hover:bg-slate-50
                    dark:border-slate-800
                    dark:bg-slate-900
                    dark:text-slate-300
                "
            >

                <i
                    data-lucide="arrow-left"
                    class="h-4 w-4"
                ></i>

                Back

            </a>


            <a
                href="subscriptions.php"
                class="
                    flex h-11
                    items-center
                    justify-center
                    gap-2
                    rounded-xl
                    bg-blue-600
                    px-4
                    text-xs
                    font-semibold
                    text-white
                    shadow-lg
                    shadow-blue-600/20
                    transition
                    hover:bg-blue-700
                "
            >

                <i
                    data-lucide="credit-card"
                    class="h-4 w-4"
                ></i>

                Manage Subscription

            </a>

        </div>

    </div>


    <!-- =====================================================
         STATUS / KPI
    ====================================================== -->

    <div
        class="
            mb-6
            grid
            grid-cols-2
            gap-3
            xl:grid-cols-4
        "
    >


        <!-- BUSINESS STATUS -->

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

            <p
                class="
                    text-[10px]
                    font-bold
                    uppercase
                    text-slate-400
                "
            >
                Business Status
            </p>


            <div class="mt-3">

                <?php if ($business['status'] === 'Active'): ?>

                    <span
                        class="
                            inline-flex
                            items-center
                            gap-1.5
                            rounded-full
                            bg-emerald-50
                            px-3
                            py-1.5
                            text-[10px]
                            font-bold
                            text-emerald-700
                        "
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        Active
                    </span>

                <?php elseif ($business['status'] === 'Suspended'): ?>

                    <span
                        class="
                            inline-flex
                            items-center
                            gap-1.5
                            rounded-full
                            bg-amber-50
                            px-3
                            py-1.5
                            text-[10px]
                            font-bold
                            text-amber-700
                        "
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                        Suspended
                    </span>

                <?php else: ?>

                    <span
                        class="
                            inline-flex
                            items-center
                            gap-1.5
                            rounded-full
                            bg-red-50
                            px-3
                            py-1.5
                            text-[10px]
                            font-bold
                            text-red-700
                        "
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                        Expired
                    </span>

                <?php endif; ?>

            </div>

        </div>


        <!-- BRANCHES -->

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

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-[10px] font-bold uppercase text-slate-400">
                        Branches
                    </p>

                    <h3 class="mt-1 text-2xl font-extrabold">
                        <?= $totalBranches ?>
                    </h3>

                    <p class="mt-1 text-[10px] text-emerald-600">
                        <?= $activeBranches ?> Active
                    </p>

                </div>

                <div
                    class="
                        flex
                        h-10 w-10
                        items-center
                        justify-center
                        rounded-xl
                        bg-blue-50
                        text-blue-600
                    "
                >
                    <i data-lucide="git-branch" class="h-5 w-5"></i>
                </div>

            </div>

        </div>


        <!-- USERS -->

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

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-[10px] font-bold uppercase text-slate-400">
                        Users
                    </p>

                    <h3 class="mt-1 text-2xl font-extrabold">
                        <?= $totalUsers ?>
                    </h3>

                    <p class="mt-1 text-[10px] text-emerald-600">
                        <?= $activeUsers ?> Active
                    </p>

                </div>

                <div
                    class="
                        flex
                        h-10 w-10
                        items-center
                        justify-center
                        rounded-xl
                        bg-violet-50
                        text-violet-600
                    "
                >
                    <i data-lucide="users" class="h-5 w-5"></i>
                </div>

            </div>

        </div>


        <!-- SUBSCRIPTION DAYS -->

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

            <p class="text-[10px] font-bold uppercase text-slate-400">
                Days Remaining
            </p>

            <?php if ($subscription): ?>

                <h3
                    class="
                        mt-1
                        text-2xl
                        font-extrabold
                        <?=
                            $daysRemaining <= 7
                            ? 'text-red-600'
                            : (
                                $daysRemaining <= 30
                                ? 'text-amber-600'
                                : 'text-emerald-600'
                            )
                        ?>
                    "
                >
                    <?= $daysRemaining ?>
                </h3>

                <p class="mt-1 text-[10px] text-slate-500">
                    Until <?= date('d M Y', strtotime($subscription['expiry_date'])) ?>
                </p>

            <?php else: ?>

                <h3 class="mt-1 text-xl font-extrabold text-red-600">
                    No Plan
                </h3>

                <p class="mt-1 text-[10px] text-slate-500">
                    Subscription not found
                </p>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         TOP DETAILS
    ====================================================== -->

    <div
        class="
            mb-6
            grid
            gap-5
            xl:grid-cols-[1.2fr_.8fr]
        "
    >


        <!-- BUSINESS INFORMATION -->

        <section
            class="
                rounded-2xl
                border
                border-slate-200
                bg-white
                shadow-soft
                dark:border-slate-800
                dark:bg-slate-900
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

                    <h3 class="text-sm font-bold">
                        Business Information
                    </h3>

                    <p class="mt-1 text-[10px] text-slate-500">
                        General information about this business.
                    </p>

                </div>

                <i
                    data-lucide="building-2"
                    class="h-5 w-5 text-blue-600"
                ></i>

            </div>


            <div
                class="
                    grid
                    gap-5
                    p-5
                    sm:grid-cols-2
                "
            >

                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Business Name
                    </p>

                    <p class="mt-1 text-xs font-bold">
                        <?= htmlspecialchars($business['business_name']) ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Phone
                    </p>

                    <p class="mt-1 text-xs font-semibold">
                        <?= htmlspecialchars($business['phone'] ?: 'Not provided') ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Email
                    </p>

                    <p class="mt-1 break-all text-xs font-semibold">
                        <?= htmlspecialchars($business['email'] ?: 'Not provided') ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Address
                    </p>

                    <p class="mt-1 text-xs font-semibold">
                        <?= htmlspecialchars($business['address'] ?: 'Not provided') ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Registered
                    </p>

                    <p class="mt-1 text-xs font-semibold">
                        <?= date('d M Y, h:i A', strtotime($business['created_at'])) ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Business ID
                    </p>

                    <p class="mt-1 text-xs font-semibold">
                        #<?= $business['id'] ?>
                    </p>

                </div>

            </div>

        </section>


        <!-- BUSINESS ADMIN -->

        <section
            class="
                rounded-2xl
                border
                border-slate-200
                bg-white
                shadow-soft
                dark:border-slate-800
                dark:bg-slate-900
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

                    <h3 class="text-sm font-bold">
                        Business Admin
                    </h3>

                    <p class="mt-1 text-[10px] text-slate-500">
                        Main administrator account.
                    </p>

                </div>

                <i
                    data-lucide="user-cog"
                    class="h-5 w-5 text-violet-600"
                ></i>

            </div>


            <div class="p-5">

                <?php if ($businessAdmin): ?>

                    <div class="flex items-center gap-3">

                        <div
                            class="
                                flex
                                h-12 w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-violet-50
                                text-sm
                                font-extrabold
                                text-violet-700
                            "
                        >
                            <?= htmlspecialchars(strtoupper(substr($businessAdmin['full_name'], 0, 1))) ?>
                        </div>


                        <div>

                            <p class="text-sm font-bold">
                                <?= htmlspecialchars($businessAdmin['full_name']) ?>
                            </p>

                            <p class="mt-1 text-[10px] text-slate-500">
                                @<?= htmlspecialchars($businessAdmin['username']) ?>
                            </p>

                        </div>

                    </div>


                    <div
                        class="
                            mt-5
                            grid
                            grid-cols-2
                            gap-3
                            rounded-xl
                            bg-slate-50
                            p-3
                            dark:bg-slate-800
                        "
                    >

                        <div>

                            <p class="text-[9px] font-bold uppercase text-slate-400">
                                Status
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-[11px]
                                    font-bold
                                    <?= $businessAdmin['status'] === 'Active'
                                        ? 'text-emerald-600'
                                        : 'text-red-600'
                                    ?>
                                "
                            >
                                <?= htmlspecialchars($businessAdmin['status']) ?>
                            </p>

                        </div>


                        <div>

                            <p class="text-[9px] font-bold uppercase text-slate-400">
                                Created
                            </p>

                            <p class="mt-1 text-[11px] font-bold">
                                <?= date('d M Y', strtotime($businessAdmin['created_at'])) ?>
                            </p>

                        </div>

                    </div>

                <?php else: ?>

                    <div class="py-8 text-center">

                        <i
                            data-lucide="user-x"
                            class="mx-auto h-8 w-8 text-slate-300"
                        ></i>

                        <p class="mt-3 text-xs text-slate-500">
                            Business Admin not found.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>


    <!-- =====================================================
         SUBSCRIPTION
    ====================================================== -->

    <section
        class="
            mb-6
            overflow-hidden
            rounded-2xl
            border
            border-slate-200
            bg-white
            shadow-soft
            dark:border-slate-800
            dark:bg-slate-900
        "
    >

        <div
            class="
                flex
                flex-col
                gap-3
                border-b
                border-slate-100
                px-5
                py-4
                sm:flex-row
                sm:items-center
                sm:justify-between
                dark:border-slate-800
            "
        >

            <div>

                <h3 class="text-sm font-bold">
                    Subscription
                </h3>

                <p class="mt-1 text-[10px] text-slate-500">
                    Current/latest subscription information.
                </p>

            </div>


            <a
                href="subscriptions.php"
                class="
                    inline-flex
                    h-9
                    items-center
                    justify-center
                    gap-2
                    rounded-xl
                    bg-blue-50
                    px-3
                    text-[10px]
                    font-semibold
                    text-blue-700
                    transition
                    hover:bg-blue-100
                "
            >

                <i
                    data-lucide="refresh-cw"
                    class="h-3.5 w-3.5"
                ></i>

                Renew / Manage

            </a>

        </div>


        <?php if ($subscription): ?>

            <div
                class="
                    grid
                    gap-4
                    p-5
                    sm:grid-cols-2
                    xl:grid-cols-6
                "
            >

                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Plan
                    </p>

                    <p class="mt-1 text-xs font-bold">
                        <?= htmlspecialchars($subscription['plan_name']) ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Price
                    </p>

                    <p class="mt-1 text-xs font-extrabold text-blue-600">
                        $<?= number_format($subscription['amount'], 2) ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Start
                    </p>

                    <p class="mt-1 text-xs font-semibold">
                        <?= date('d M Y', strtotime($subscription['start_date'])) ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Expiry
                    </p>

                    <p class="mt-1 text-xs font-semibold">
                        <?= date('d M Y', strtotime($subscription['expiry_date'])) ?>
                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Branch Limit
                    </p>

                    <p class="mt-1 text-xs font-semibold">

                        <?php if ($subscription['max_branches'] === null): ?>

                            Unlimited

                        <?php else: ?>

                            <?= (int) $subscription['max_branches'] ?> Branches

                        <?php endif; ?>

                    </p>

                </div>


                <div>

                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Status
                    </p>

                    <?php
                        $subStatus = $isExpiredByDate
                            ? 'Expired'
                            : $subscription['status'];
                    ?>

                    <span
                        class="
                            mt-1
                            inline-flex
                            rounded-full
                            px-2.5
                            py-1
                            text-[10px]
                            font-bold

                            <?=
                                $subStatus === 'Active'
                                ? 'bg-emerald-50 text-emerald-700'
                                : (
                                    $subStatus === 'Suspended'
                                    ? 'bg-amber-50 text-amber-700'
                                    : 'bg-red-50 text-red-700'
                                )
                            ?>
                        "
                    >
                        <?= htmlspecialchars($subStatus) ?>
                    </span>

                </div>

            </div>

        <?php else: ?>

            <div class="px-5 py-10 text-center">

                <i
                    data-lucide="calendar-x"
                    class="mx-auto h-9 w-9 text-slate-300"
                ></i>

                <p class="mt-3 text-xs font-semibold">
                    No subscription found.
                </p>

                <p class="mt-1 text-[10px] text-slate-500">
                    Create a subscription for this business.
                </p>

            </div>

        <?php endif; ?>

    </section>


    <!-- =====================================================
         BRANCHES
    ====================================================== -->

    <section
        class="
            mb-6
            overflow-hidden
            rounded-2xl
            border
            border-slate-200
            bg-white
            shadow-soft
            dark:border-slate-800
            dark:bg-slate-900
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

                <h3 class="text-sm font-bold">
                    Branches
                </h3>

                <p class="mt-1 text-[10px] text-slate-500">
                    <?= $totalBranches ?> branch(es) registered.
                </p>

            </div>

            <i
                data-lucide="git-branch"
                class="h-5 w-5 text-blue-600"
            ></i>

        </div>


        <div class="overflow-x-auto">

            <table class="min-w-full">

                <thead class="bg-slate-50 dark:bg-slate-800/60">

                    <tr>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            Branch
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            Type
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            Status
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            Created
                        </th>

                    </tr>

                </thead>


                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">

                    <?php if (!$branches): ?>

                        <tr>

                            <td
                                colspan="4"
                                class="px-5 py-10 text-center text-xs text-slate-500"
                            >
                                No branches found.
                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php foreach ($branches as $branch): ?>

                        <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">

                            <td class="px-5 py-4">

                                <p class="text-xs font-bold">
                                    <?= htmlspecialchars($branch['branch_name']) ?>
                                </p>

                                <p class="mt-1 text-[9px] text-slate-400">
                                    Branch #<?= $branch['id'] ?>
                                </p>

                            </td>


                            <td class="px-5 py-4">

                                <?php if ((int) $branch['is_main'] === 1): ?>

                                    <span
                                        class="
                                            rounded-full
                                            bg-blue-50
                                            px-2.5
                                            py-1
                                            text-[10px]
                                            font-semibold
                                            text-blue-700
                                        "
                                    >
                                        Main Branch
                                    </span>

                                <?php else: ?>

                                    <span class="text-[10px] font-semibold text-slate-500">
                                        Branch
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td class="px-5 py-4">

                                <span
                                    class="
                                        rounded-full
                                        px-2.5
                                        py-1
                                        text-[10px]
                                        font-semibold

                                        <?=
                                            $branch['status'] === 'Active'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-slate-100 text-slate-600'
                                        ?>
                                    "
                                >
                                    <?= htmlspecialchars($branch['status']) ?>
                                </span>

                            </td>


                            <td class="px-5 py-4 text-[10px] text-slate-500">
                                <?= date('d M Y', strtotime($branch['created_at'])) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- =====================================================
         USERS
    ====================================================== -->

    <section
        class="
            overflow-hidden
            rounded-2xl
            border
            border-slate-200
            bg-white
            shadow-soft
            dark:border-slate-800
            dark:bg-slate-900
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

                <h3 class="text-sm font-bold">
                    Business Users
                </h3>

                <p class="mt-1 text-[10px] text-slate-500">
                    <?= $totalUsers ?> user(s) attached to this business.
                </p>

            </div>

            <i
                data-lucide="users"
                class="h-5 w-5 text-violet-600"
            ></i>

        </div>


        <div class="overflow-x-auto">

            <table class="min-w-full">

                <thead class="bg-slate-50 dark:bg-slate-800/60">

                    <tr>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            User
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            Role
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            Branch
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            Status
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] font-bold uppercase text-slate-500">
                            Created
                        </th>

                    </tr>

                </thead>


                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">

                    <?php if (!$users): ?>

                        <tr>

                            <td
                                colspan="5"
                                class="px-5 py-10 text-center text-xs text-slate-500"
                            >
                                No users found.
                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php foreach ($users as $user): ?>

                        <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">

                            <td class="px-5 py-4">

                                <p class="text-xs font-bold">
                                    <?= htmlspecialchars($user['full_name']) ?>
                                </p>

                                <p class="mt-1 text-[9px] text-slate-400">
                                    @<?= htmlspecialchars($user['username']) ?>
                                </p>

                            </td>


                            <td class="px-5 py-4">

                                <span
                                    class="
                                        rounded-lg
                                        bg-violet-50
                                        px-2.5
                                        py-1.5
                                        text-[10px]
                                        font-semibold
                                        text-violet-700
                                    "
                                >
                                    <?= htmlspecialchars($user['role_name']) ?>
                                </span>

                            </td>


                            <td class="px-5 py-4 text-[10px] font-semibold text-slate-600">
                                <?= htmlspecialchars($user['branch_name'] ?: 'All / Not assigned') ?>
                            </td>


                            <td class="px-5 py-4">

                                <span
                                    class="
                                        rounded-full
                                        px-2.5
                                        py-1
                                        text-[10px]
                                        font-semibold

                                        <?=
                                            $user['status'] === 'Active'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-red-50 text-red-700'
                                        ?>
                                    "
                                >
                                    <?= htmlspecialchars($user['status']) ?>
                                </span>

                            </td>


                            <td class="px-5 py-4 text-[10px] text-slate-500">
                                <?= date('d M Y', strtotime($user['created_at'])) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </section>


</main>


<?php require_once 'includes/footer.php'; ?>


</div>


<script src="../assets/js/app.js"></script>

<script>

if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}

</script>

</body>
</html>
