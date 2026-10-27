<?php

session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

requireSuperAdmin();
$message = '';
$messageType = '';


/* =========================================================
   ADD PLAN
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['add_plan'])
) {

    $planName = trim($_POST['plan_name'] ?? '');
    $durationMonths = (int) ($_POST['duration_months'] ?? 0);
    $price = (float) ($_POST['price'] ?? 0);

    $maxBranchesRaw = trim($_POST['max_branches'] ?? '');

    $maxBranches =
        $maxBranchesRaw === ''
        ? null
        : (int) $maxBranchesRaw;

    $status = $_POST['status'] ?? 'Active';

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $status = 'Active';
    }


    if ($planName === '') {

        $message = 'Plan name waa required.';
        $messageType = 'error';

    } elseif ($durationMonths <= 0) {

        $message = 'Duration months waa inuu ka weyn yahay 0.';
        $messageType = 'error';

    } elseif ($price < 0) {

        $message = 'Price sax ah geli.';
        $messageType = 'error';

    } elseif ($maxBranches !== null && $maxBranches <= 0) {

        $message = 'Max branches waa inuu ka weyn yahay 0 ama ka tag empty.';
        $messageType = 'error';

    } else {

        try {

            /* CHECK DUPLICATE */

            $stmt = $conn->prepare("
                SELECT id
                FROM subscription_plans
                WHERE plan_name = ?
                LIMIT 1
            ");

            $stmt->execute([$planName]);

            if ($stmt->fetch()) {

                throw new Exception(
                    'Plan-kan hore ayuu u jiraa.'
                );
            }


            /* INSERT */

            $stmt = $conn->prepare("
                INSERT INTO subscription_plans
                (
                    plan_name,
                    duration_months,
                    price,
                    max_branches,
                    status
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $planName,
                $durationMonths,
                $price,
                $maxBranches,
                $status
            ]);


            $message = 'Subscription plan-ka waa la sameeyey.';
            $messageType = 'success';


        } catch (Exception $e) {

            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}


/* =========================================================
   UPDATE PLAN
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_plan'])
) {

    $planId = (int) ($_POST['plan_id'] ?? 0);

    $planName = trim($_POST['plan_name'] ?? '');

    $durationMonths =
        (int) ($_POST['duration_months'] ?? 0);

    $price =
        (float) ($_POST['price'] ?? 0);

    $maxBranchesRaw =
        trim($_POST['max_branches'] ?? '');

    $maxBranches =
        $maxBranchesRaw === ''
        ? null
        : (int) $maxBranchesRaw;

    $status =
        $_POST['status'] ?? 'Active';


    if (!in_array($status, ['Active', 'Inactive'], true)) {

        $status = 'Active';
    }


    if ($planId <= 0) {

        $message = 'Plan ID ma saxna.';
        $messageType = 'error';

    } elseif ($planName === '') {

        $message = 'Plan name waa required.';
        $messageType = 'error';

    } elseif ($durationMonths <= 0) {

        $message = 'Duration months sax ah geli.';
        $messageType = 'error';

    } elseif ($price < 0) {

        $message = 'Price sax ah geli.';
        $messageType = 'error';

    } elseif (
        $maxBranches !== null
        && $maxBranches <= 0
    ) {

        $message = 'Max branches sax ah geli ama empty ka dhig.';
        $messageType = 'error';

    } else {

        try {

            /* DUPLICATE CHECK */

            $stmt = $conn->prepare("
                SELECT id
                FROM subscription_plans
                WHERE plan_name = ?
                AND id != ?
                LIMIT 1
            ");

            $stmt->execute([
                $planName,
                $planId
            ]);


            if ($stmt->fetch()) {

                throw new Exception(
                    'Plan kale ayaa magacaas isticmaala.'
                );
            }


            /* UPDATE */

            $stmt = $conn->prepare("
                UPDATE subscription_plans
                SET
                    plan_name = ?,
                    duration_months = ?,
                    price = ?,
                    max_branches = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $planName,
                $durationMonths,
                $price,
                $maxBranches,
                $status,
                $planId
            ]);


            $message = 'Plan-ka waa la update gareeyey.';
            $messageType = 'success';


        } catch (Exception $e) {

            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}


/* =========================================================
   QUICK STATUS
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['toggle_status'])
) {

    $planId =
        (int) ($_POST['plan_id'] ?? 0);

    $newStatus =
        $_POST['new_status'] ?? '';


    if (
        $planId > 0
        && in_array(
            $newStatus,
            ['Active', 'Inactive'],
            true
        )
    ) {

        $stmt = $conn->prepare("
            UPDATE subscription_plans
            SET status = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $newStatus,
            $planId
        ]);


        $message = 'Plan status waa la beddelay.';
        $messageType = 'success';
    }
}


/* =========================================================
   SEARCH
========================================================= */

$search =
    trim($_GET['search'] ?? '');

$statusFilter =
    trim($_GET['status'] ?? '');


$sql = "
    SELECT
        id,
        plan_name,
        duration_months,
        price,
        max_branches,
        status,
        created_at
    FROM subscription_plans
    WHERE 1 = 1
";

$params = [];


if ($search !== '') {

    $sql .= "
        AND plan_name LIKE ?
    ";

    $params[] =
        '%' . $search . '%';
}


if (
    in_array(
        $statusFilter,
        ['Active', 'Inactive'],
        true
    )
) {

    $sql .= "
        AND status = ?
    ";

    $params[] = $statusFilter;
}


$sql .= "
    ORDER BY duration_months ASC, id ASC
";


$stmt = $conn->prepare($sql);
$stmt->execute($params);

$plans = $stmt->fetchAll();


/* =========================================================
   COUNTS
========================================================= */

$totalPlans =
    (int) $conn->query("
        SELECT COUNT(*)
        FROM subscription_plans
    ")->fetchColumn();


$activePlans =
    (int) $conn->query("
        SELECT COUNT(*)
        FROM subscription_plans
        WHERE status = 'Active'
    ")->fetchColumn();


$inactivePlans =
    (int) $conn->query("
        SELECT COUNT(*)
        FROM subscription_plans
        WHERE status = 'Inactive'
    ")->fetchColumn();


$yearlyPrice =
    $conn->query("
        SELECT price
        FROM subscription_plans
        WHERE duration_months = 12
        ORDER BY id ASC
        LIMIT 1
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

    <title>Subscription Plans</title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
    >

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            darkMode: 'class'
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


<main
    class="
        page-enter
        px-4 py-6
        md:px-7 md:py-7
    "
>


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div
        class="
            mb-6
            flex flex-col
            gap-4
            lg:flex-row
            lg:items-center
            lg:justify-between
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

                <span>
                    Subscription Plans
                </span>

            </div>


            <h1
                class="
                    text-2xl
                    font-extrabold
                    tracking-tight
                    text-slate-900
                    dark:text-white
                "
            >
                Subscription Plans
            </h1>


            <p
                class="
                    mt-1
                    text-[12px]
                    text-slate-500
                "
            >
                Manage prices, duration and branch limits.
            </p>

        </div>


        <button
            id="openAddModal"
            type="button"
            class="
                flex h-11
                items-center
                justify-center
                gap-2
                rounded-xl
                bg-blue-600
                px-5
                text-xs
                font-semibold
                text-white
                shadow-lg
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

            Add Plan

        </button>

    </div>


    <!-- =====================================================
         MESSAGE
    ====================================================== -->

    <?php if ($message !== ''): ?>

        <div
            id="pageAlert"
            class="
                mb-5
                flex items-start
                justify-between
                gap-3
                rounded-xl
                border
                p-4

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
                    .getElementById('pageAlert')
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


    <!-- =====================================================
         KPI CARDS
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


        <!-- TOTAL -->

        <div
            class="
                dashboard-card
                rounded-2xl
                border
                border-slate-200
                bg-white
                p-4
                shadow-sm
                dark:border-slate-800
                dark:bg-slate-900
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
                            text-[10px]
                            font-bold
                            uppercase
                            text-slate-400
                        "
                    >
                        Total Plans
                    </p>

                    <h3
                        class="
                            mt-1
                            text-2xl
                            font-extrabold
                        "
                    >
                        <?= $totalPlans ?>
                    </h3>

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

                    <i
                        data-lucide="layers-3"
                        class="h-5 w-5"
                    ></i>

                </div>

            </div>

        </div>


        <!-- ACTIVE -->

        <div
            class="
                dashboard-card
                rounded-2xl
                border
                border-slate-200
                bg-white
                p-4
                shadow-sm
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
                Active Plans
            </p>

            <h3
                class="
                    mt-1
                    text-2xl
                    font-extrabold
                    text-emerald-600
                "
            >
                <?= $activePlans ?>
            </h3>

        </div>


        <!-- INACTIVE -->

        <div
            class="
                dashboard-card
                rounded-2xl
                border
                border-slate-200
                bg-white
                p-4
                shadow-sm
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
                Inactive
            </p>

            <h3
                class="
                    mt-1
                    text-2xl
                    font-extrabold
                    text-amber-600
                "
            >
                <?= $inactivePlans ?>
            </h3>

        </div>


        <!-- YEARLY -->

        <div
            class="
                dashboard-card
                rounded-2xl
                border
                border-slate-200
                bg-white
                p-4
                shadow-sm
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
                Yearly Price
            </p>

            <h3
                class="
                    mt-1
                    text-2xl
                    font-extrabold
                    text-blue-600
                "
            >
                $<?= number_format(
                    (float) $yearlyPrice,
                    2
                ) ?>
            </h3>

        </div>

    </div>


    <!-- =====================================================
         TABLE CARD
    ====================================================== -->

    <section
        class="
            overflow-hidden
            rounded-2xl
            border
            border-slate-200
            bg-white
            shadow-sm
            dark:border-slate-800
            dark:bg-slate-900
        "
    >


        <!-- FILTER -->

        <div
            class="
                flex
                flex-col
                gap-3
                border-b
                border-slate-100
                p-4
                md:flex-row
                md:items-center
                md:justify-between
                dark:border-slate-800
            "
        >

            <div>

                <h3 class="text-sm font-bold">
                    Plan List
                </h3>

                <p
                    class="
                        mt-1
                        text-[10px]
                        text-slate-500
                    "
                >
                    <?= count($plans) ?>
                    plans found
                </p>

            </div>


            <form
                method="GET"
                class="
                    grid
                    w-full
                    gap-2
                    md:w-auto
                    md:grid-cols-[230px_150px]
                "
            >

                <div class="relative">

                    <i
                        data-lucide="search"
                        class="
                            absolute
                            left-3
                            top-1/2
                            h-4 w-4
                            -translate-y-1/2
                            text-slate-400
                        "
                    ></i>


                    <input
                        type="text"
                        name="search"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search plan..."
                        autocomplete="off"
                        class="
                            h-10
                            w-full
                            rounded-xl
                            border
                            border-slate-200
                            bg-slate-50
                            pl-9
                            pr-3
                            text-xs
                            outline-none
                            transition
                            focus:border-blue-400
                            focus:bg-white
                            focus:ring-4
                            focus:ring-blue-50
                        "
                    >

                </div>


                <select
                    name="status"
                    onchange="this.form.submit()"
                    class="
                        h-10
                        rounded-xl
                        border
                        border-slate-200
                        bg-slate-50
                        px-3
                        text-xs
                        outline-none
                    "
                >

                    <option value="">
                        All Status
                    </option>

                    <option
                        value="Active"
                        <?= $statusFilter === 'Active'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        Active
                    </option>

                    <option
                        value="Inactive"
                        <?= $statusFilter === 'Inactive'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        Inactive
                    </option>

                </select>

            </form>

        </div>


        <!-- =================================================
             MOBILE CARDS
        ================================================== -->

        <div
            class="
                divide-y
                divide-slate-100
                md:hidden
                dark:divide-slate-800
            "
        >

            <?php if (!$plans): ?>

                <div
                    class="
                        px-5
                        py-12
                        text-center
                    "
                >

                    <i
                        data-lucide="layers-3"
                        class="
                            mx-auto
                            h-10 w-10
                            text-slate-300
                        "
                    ></i>

                    <p
                        class="
                            mt-3
                            text-xs
                            text-slate-500
                        "
                    >
                        No plans found.
                    </p>

                </div>

            <?php endif; ?>


            <?php foreach ($plans as $plan): ?>

                <div class="p-4">

                    <div
                        class="
                            flex
                            items-start
                            justify-between
                            gap-3
                        "
                    >

                        <div>

                            <h4
                                class="
                                    text-sm
                                    font-bold
                                "
                            >
                                <?= htmlspecialchars(
                                    $plan['plan_name']
                                ) ?>
                            </h4>

                            <p
                                class="
                                    mt-1
                                    text-xl
                                    font-extrabold
                                    text-blue-600
                                "
                            >
                                $<?= number_format(
                                    $plan['price'],
                                    2
                                ) ?>
                            </p>

                        </div>


                        <?php if (
                            $plan['status'] === 'Active'
                        ): ?>

                            <span
                                class="
                                    rounded-full
                                    bg-emerald-50
                                    px-2.5
                                    py-1
                                    text-[10px]
                                    font-semibold
                                    text-emerald-700
                                "
                            >
                                Active
                            </span>

                        <?php else: ?>

                            <span
                                class="
                                    rounded-full
                                    bg-slate-100
                                    px-2.5
                                    py-1
                                    text-[10px]
                                    font-semibold
                                    text-slate-600
                                "
                            >
                                Inactive
                            </span>

                        <?php endif; ?>

                    </div>


                    <div
                        class="
                            mt-4
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

                            <p
                                class="
                                    text-[9px]
                                    font-semibold
                                    uppercase
                                    text-slate-400
                                "
                            >
                                Duration
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-[11px]
                                    font-bold
                                "
                            >
                                <?= $plan['duration_months'] ?>
                                Month<?= $plan['duration_months'] > 1 ? 's' : '' ?>
                            </p>

                        </div>


                        <div>

                            <p
                                class="
                                    text-[9px]
                                    font-semibold
                                    uppercase
                                    text-slate-400
                                "
                            >
                                Branch Limit
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-[11px]
                                    font-bold
                                "
                            >

                                <?php if (
                                    $plan['max_branches'] === null
                                ): ?>

                                    Unlimited

                                <?php else: ?>

                                    <?= (int) $plan['max_branches'] ?>
                                    Branches

                                <?php endif; ?>

                            </p>

                        </div>

                    </div>


                    <div
                        class="
                            mt-3
                            flex
                            gap-2
                        "
                    >

                        <button
                            type="button"
                            class="
                                editPlanButton
                                flex
                                h-9
                                flex-1
                                items-center
                                justify-center
                                gap-2
                                rounded-xl
                                bg-blue-50
                                text-[10px]
                                font-semibold
                                text-blue-700
                            "

                            data-id="<?= $plan['id'] ?>"

                            data-name="<?=
                                htmlspecialchars(
                                    $plan['plan_name'],
                                    ENT_QUOTES
                                )
                            ?>"

                            data-duration="<?= $plan['duration_months'] ?>"

                            data-price="<?= $plan['price'] ?>"

                            data-branches="<?=
                                $plan['max_branches'] ?? ''
                            ?>"

                            data-status="<?= $plan['status'] ?>"
                        >

                            <i
                                data-lucide="pencil"
                                class="h-3.5 w-3.5"
                            ></i>

                            Edit

                        </button>


                        <form
                            method="POST"
                            class="flex-1"
                        >

                            <input
                                type="hidden"
                                name="toggle_status"
                                value="1"
                            >

                            <input
                                type="hidden"
                                name="plan_id"
                                value="<?= $plan['id'] ?>"
                            >

                            <input
                                type="hidden"
                                name="new_status"
                                value="<?=
                                    $plan['status'] === 'Active'
                                    ? 'Inactive'
                                    : 'Active'
                                ?>"
                            >


                            <button
                                type="submit"
                                class="
                                    flex
                                    h-9
                                    w-full
                                    items-center
                                    justify-center
                                    gap-2
                                    rounded-xl
                                    bg-slate-100
                                    text-[10px]
                                    font-semibold
                                    text-slate-600
                                "
                            >

                                <i
                                    data-lucide="<?=
                                        $plan['status'] === 'Active'
                                        ? 'pause'
                                        : 'play'
                                    ?>"
                                    class="h-3.5 w-3.5"
                                ></i>

                                <?=
                                    $plan['status'] === 'Active'
                                    ? 'Disable'
                                    : 'Activate'
                                ?>

                            </button>

                        </form>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>


        <!-- =================================================
             DESKTOP TABLE
        ================================================== -->

        <div
            class="
                hidden
                overflow-x-auto
                md:block
            "
        >

            <table class="min-w-full">

                <thead
                    class="
                        bg-slate-50
                        dark:bg-slate-800/60
                    "
                >

                    <tr>

                        <th
                            class="
                                px-5 py-3
                                text-left
                                text-[10px]
                                font-bold
                                uppercase
                                text-slate-500
                            "
                        >
                            Plan
                        </th>

                        <th
                            class="
                                px-5 py-3
                                text-left
                                text-[10px]
                                font-bold
                                uppercase
                                text-slate-500
                            "
                        >
                            Duration
                        </th>

                        <th
                            class="
                                px-5 py-3
                                text-left
                                text-[10px]
                                font-bold
                                uppercase
                                text-slate-500
                            "
                        >
                            Price
                        </th>

                        <th
                            class="
                                px-5 py-3
                                text-left
                                text-[10px]
                                font-bold
                                uppercase
                                text-slate-500
                            "
                        >
                            Branch Limit
                        </th>

                        <th
                            class="
                                px-5 py-3
                                text-left
                                text-[10px]
                                font-bold
                                uppercase
                                text-slate-500
                            "
                        >
                            Status
                        </th>

                        <th
                            class="
                                px-5 py-3
                                text-left
                                text-[10px]
                                font-bold
                                uppercase
                                text-slate-500
                            "
                        >
                            Created
                        </th>

                        <th
                            class="
                                px-5 py-3
                                text-right
                                text-[10px]
                                font-bold
                                uppercase
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
                        dark:divide-slate-800
                    "
                >

                    <?php if (!$plans): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="
                                    px-5
                                    py-14
                                    text-center
                                    text-xs
                                    text-slate-500
                                "
                            >
                                No subscription plans found.
                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php foreach ($plans as $plan): ?>

                        <tr
                            class="
                                transition
                                hover:bg-slate-50/70
                                dark:hover:bg-slate-800/40
                            "
                        >

                            <!-- PLAN -->

                            <td class="px-5 py-4">

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
                                            h-9 w-9
                                            items-center
                                            justify-center
                                            rounded-xl
                                            bg-blue-50
                                            text-blue-600
                                        "
                                    >

                                        <i
                                            data-lucide="package-check"
                                            class="h-4 w-4"
                                        ></i>

                                    </div>


                                    <div>

                                        <p
                                            class="
                                                text-xs
                                                font-bold
                                            "
                                        >
                                            <?= htmlspecialchars(
                                                $plan['plan_name']
                                            ) ?>
                                        </p>

                                        <p
                                            class="
                                                mt-1
                                                text-[9px]
                                                text-slate-400
                                            "
                                        >
                                            Plan #<?= $plan['id'] ?>
                                        </p>

                                    </div>

                                </div>

                            </td>


                            <!-- DURATION -->

                            <td
                                class="
                                    px-5
                                    py-4
                                    text-xs
                                    font-semibold
                                "
                            >

                                <?= $plan['duration_months'] ?>

                                Month<?= $plan['duration_months'] > 1 ? 's' : '' ?>

                            </td>


                            <!-- PRICE -->

                            <td class="px-5 py-4">

                                <span
                                    class="
                                        text-sm
                                        font-extrabold
                                        text-blue-600
                                    "
                                >
                                    $<?= number_format(
                                        $plan['price'],
                                        2
                                    ) ?>
                                </span>

                            </td>


                            <!-- BRANCH -->

                            <td class="px-5 py-4">

                                <?php if (
                                    $plan['max_branches'] === null
                                ): ?>

                                    <span
                                        class="
                                            inline-flex
                                            items-center
                                            gap-1
                                            rounded-lg
                                            bg-violet-50
                                            px-2.5
                                            py-1
                                            text-[10px]
                                            font-semibold
                                            text-violet-700
                                        "
                                    >

                                        <i
                                            data-lucide="infinity"
                                            class="h-3 w-3"
                                        ></i>

                                        Unlimited

                                    </span>

                                <?php else: ?>

                                    <span
                                        class="
                                            text-xs
                                            font-semibold
                                        "
                                    >

                                        <?= (int) $plan['max_branches'] ?>

                                        Branch<?= $plan['max_branches'] > 1 ? 'es' : '' ?>

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- STATUS -->

                            <td class="px-5 py-4">

                                <?php if (
                                    $plan['status'] === 'Active'
                                ): ?>

                                    <span
                                        class="
                                            inline-flex
                                            items-center
                                            gap-1.5
                                            rounded-full
                                            bg-emerald-50
                                            px-2.5
                                            py-1
                                            text-[10px]
                                            font-semibold
                                            text-emerald-700
                                        "
                                    >

                                        <span
                                            class="
                                                h-1.5
                                                w-1.5
                                                rounded-full
                                                bg-emerald-500
                                            "
                                        ></span>

                                        Active

                                    </span>

                                <?php else: ?>

                                    <span
                                        class="
                                            inline-flex
                                            items-center
                                            gap-1.5
                                            rounded-full
                                            bg-slate-100
                                            px-2.5
                                            py-1
                                            text-[10px]
                                            font-semibold
                                            text-slate-600
                                        "
                                    >
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- CREATED -->

                            <td
                                class="
                                    whitespace-nowrap
                                    px-5
                                    py-4
                                    text-[10px]
                                    text-slate-500
                                "
                            >

                                <?= date(
                                    'd M Y',
                                    strtotime(
                                        $plan['created_at']
                                    )
                                ) ?>

                            </td>


                            <!-- ACTIONS -->

                            <td class="px-5 py-4">

                                <div
                                    class="
                                        flex
                                        justify-end
                                        gap-1
                                    "
                                >

                                    <button
                                        type="button"
                                        title="Edit Plan"
                                        class="
                                            editPlanButton
                                            flex
                                            h-8 w-8
                                            items-center
                                            justify-center
                                            rounded-lg
                                            text-slate-400
                                            transition
                                            hover:bg-blue-50
                                            hover:text-blue-600
                                        "

                                        data-id="<?= $plan['id'] ?>"

                                        data-name="<?=
                                            htmlspecialchars(
                                                $plan['plan_name'],
                                                ENT_QUOTES
                                            )
                                        ?>"

                                        data-duration="<?= $plan['duration_months'] ?>"

                                        data-price="<?= $plan['price'] ?>"

                                        data-branches="<?=
                                            $plan['max_branches'] ?? ''
                                        ?>"

                                        data-status="<?= $plan['status'] ?>"
                                    >

                                        <i
                                            data-lucide="pencil"
                                            class="h-4 w-4"
                                        ></i>

                                    </button>


                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="toggle_status"
                                            value="1"
                                        >

                                        <input
                                            type="hidden"
                                            name="plan_id"
                                            value="<?= $plan['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="new_status"
                                            value="<?=
                                                $plan['status'] === 'Active'
                                                ? 'Inactive'
                                                : 'Active'
                                            ?>"
                                        >


                                        <button
                                            type="submit"
                                            title="<?=
                                                $plan['status'] === 'Active'
                                                ? 'Disable'
                                                : 'Activate'
                                            ?>"
                                            class="
                                                flex
                                                h-8 w-8
                                                items-center
                                                justify-center
                                                rounded-lg
                                                text-slate-400
                                                transition
                                                hover:bg-amber-50
                                                hover:text-amber-600
                                            "
                                        >

                                            <i
                                                data-lucide="<?=
                                                    $plan['status'] === 'Active'
                                                    ? 'pause-circle'
                                                    : 'play-circle'
                                                ?>"
                                                class="h-4 w-4"
                                            ></i>

                                        </button>

                                    </form>

                                </div>

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


<!-- =========================================================
     ADD / UPDATE PLAN MODAL
========================================================= -->

<div
    id="planModal"
    class="
        fixed
        inset-0
        z-[100]
        hidden
        items-center
        justify-center
        bg-slate-950/60
        p-3
        backdrop-blur-sm
    "
>

    <div
        id="planModalPanel"
        class="
            max-h-[94vh]
            w-full
            max-w-lg
            translate-y-5
            overflow-y-auto
            rounded-2xl
            bg-white
            opacity-0
            shadow-2xl
            transition-all
            duration-300
            dark:bg-slate-900
        "
    >


        <!-- MODAL HEADER -->

        <div
            class="
                sticky
                top-0
                z-10
                flex
                items-center
                justify-between
                border-b
                border-slate-100
                bg-white/95
                px-5
                py-4
                backdrop-blur-xl
                dark:border-slate-800
                dark:bg-slate-900/95
            "
        >

            <div>

                <h3
                    id="modalTitle"
                    class="text-sm font-bold"
                >
                    Add Subscription Plan
                </h3>

                <p
                    id="modalDescription"
                    class="
                        mt-1
                        text-[10px]
                        text-slate-500
                    "
                >
                    Create a new subscription plan.
                </p>

            </div>


            <button
                id="closePlanModal"
                type="button"
                class="
                    flex
                    h-9 w-9
                    items-center
                    justify-center
                    rounded-xl
                    text-slate-400
                    transition
                    hover:bg-slate-100
                "
            >

                <i
                    data-lucide="x"
                    class="h-5 w-5"
                ></i>

            </button>

        </div>


        <!-- FORM -->

        <form
            method="POST"
            id="planForm"
        >

            <input
                id="formAction"
                type="hidden"
                name="add_plan"
                value="1"
            >

            <input
                id="planId"
                type="hidden"
                name="plan_id"
                value=""
            >


            <div class="space-y-4 p-5">


                <!-- PLAN NAME -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[11px]
                            font-semibold
                            text-slate-600
                        "
                    >
                        Plan Name
                        <span class="text-red-500">*</span>
                    </label>


                    <input
                        id="planName"
                        type="text"
                        name="plan_name"
                        required
                        placeholder="Example: Monthly"
                        autocomplete="off"
                        class="
                            h-11
                            w-full
                            rounded-xl
                            border
                            border-slate-200
                            bg-white
                            px-3
                            text-xs
                            outline-none
                            transition
                            focus:border-blue-400
                            focus:ring-4
                            focus:ring-blue-50
                        "
                    >

                </div>


                <!-- DURATION + PRICE -->

                <div
                    class="
                        grid
                        gap-4
                        sm:grid-cols-2
                    "
                >

                    <div>

                        <label
                            class="
                                mb-1.5
                                block
                                text-[11px]
                                font-semibold
                                text-slate-600
                            "
                        >
                            Duration (Months)
                            <span class="text-red-500">*</span>
                        </label>


                        <input
                            id="durationMonths"
                            type="number"
                            name="duration_months"
                            min="1"
                            required
                            placeholder="1"
                            class="
                                h-11
                                w-full
                                rounded-xl
                                border
                                border-slate-200
                                px-3
                                text-xs
                                outline-none
                                focus:border-blue-400
                                focus:ring-4
                                focus:ring-blue-50
                            "
                        >

                    </div>


                    <div>

                        <label
                            class="
                                mb-1.5
                                block
                                text-[11px]
                                font-semibold
                                text-slate-600
                            "
                        >
                            Price ($)
                            <span class="text-red-500">*</span>
                        </label>


                        <div class="relative">

                            <span
                                class="
                                    absolute
                                    left-3
                                    top-1/2
                                    -translate-y-1/2
                                    text-xs
                                    font-bold
                                    text-slate-400
                                "
                            >
                                $
                            </span>

                            <input
                                id="planPrice"
                                type="number"
                                name="price"
                                min="0"
                                step="0.01"
                                required
                                placeholder="20.00"
                                class="
                                    h-11
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    pl-7
                                    pr-3
                                    text-xs
                                    outline-none
                                    focus:border-blue-400
                                    focus:ring-4
                                    focus:ring-blue-50
                                "
                            >

                        </div>

                    </div>

                </div>


                <!-- BRANCH LIMIT -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[11px]
                            font-semibold
                            text-slate-600
                        "
                    >
                        Maximum Branches
                    </label>


                    <input
                        id="maxBranches"
                        type="number"
                        name="max_branches"
                        min="1"
                        placeholder="Leave empty for unlimited"
                        class="
                            h-11
                            w-full
                            rounded-xl
                            border
                            border-slate-200
                            px-3
                            text-xs
                            outline-none
                            focus:border-blue-400
                            focus:ring-4
                            focus:ring-blue-50
                        "
                    >


                    <div
                        class="
                            mt-2
                            flex
                            items-start
                            gap-2
                            rounded-xl
                            bg-blue-50
                            p-3
                            text-blue-700
                        "
                    >

                        <i
                            data-lucide="info"
                            class="
                                mt-0.5
                                h-4 w-4
                                shrink-0
                            "
                        ></i>

                        <p class="text-[10px] leading-5">

                            Empty ka tag haddii plan-ku leeyahay
                            branches aan xad lahayn.

                        </p>

                    </div>

                </div>


                <!-- STATUS -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[11px]
                            font-semibold
                            text-slate-600
                        "
                    >
                        Status
                    </label>


                    <select
                        id="planStatus"
                        name="status"
                        class="
                            h-11
                            w-full
                            rounded-xl
                            border
                            border-slate-200
                            bg-white
                            px-3
                            text-xs
                            outline-none
                            focus:border-blue-400
                            focus:ring-4
                            focus:ring-blue-50
                        "
                    >

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                </div>


                <!-- PREVIEW -->

                <div
                    class="
                        rounded-2xl
                        border
                        border-slate-100
                        bg-slate-50
                        p-4
                        dark:border-slate-800
                        dark:bg-slate-800
                    "
                >

                    <p
                        class="
                            text-[9px]
                            font-bold
                            uppercase
                            tracking-wider
                            text-slate-400
                        "
                    >
                        Plan Preview
                    </p>


                    <div
                        class="
                            mt-3
                            flex
                            items-center
                            justify-between
                            gap-4
                        "
                    >

                        <div>

                            <p
                                id="previewName"
                                class="
                                    text-sm
                                    font-bold
                                "
                            >
                                New Plan
                            </p>

                            <p
                                id="previewDuration"
                                class="
                                    mt-1
                                    text-[10px]
                                    text-slate-500
                                "
                            >
                                0 Months
                            </p>

                        </div>


                        <p
                            id="previewPrice"
                            class="
                                text-xl
                                font-extrabold
                                text-blue-600
                            "
                        >
                            $0.00
                        </p>

                    </div>

                </div>

            </div>


            <!-- MODAL FOOTER -->

            <div
                class="
                    sticky
                    bottom-0
                    flex
                    flex-col-reverse
                    gap-2
                    border-t
                    border-slate-100
                    bg-white/95
                    px-5
                    py-4
                    backdrop-blur-xl
                    sm:flex-row
                    sm:justify-end
                    dark:border-slate-800
                    dark:bg-slate-900/95
                "
            >

                <button
                    id="cancelPlanModal"
                    type="button"
                    class="
                        h-11
                        rounded-xl
                        border
                        border-slate-200
                        px-5
                        text-xs
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
                        flex
                        h-11
                        items-center
                        justify-center
                        gap-2
                        rounded-xl
                        bg-blue-600
                        px-5
                        text-xs
                        font-semibold
                        text-white
                        transition
                        hover:bg-blue-700
                    "
                >

                    <i
                        data-lucide="check"
                        class="h-4 w-4"
                    ></i>

                    <span id="submitText">
                        Save Plan
                    </span>

                </button>

            </div>

        </form>

    </div>

</div>


<script src="../assets/js/app.js"></script>


<script>

if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}


/* =========================================================
   ELEMENTS
========================================================= */

const planModal =
    document.getElementById('planModal');

const planModalPanel =
    document.getElementById('planModalPanel');

const openAddModal =
    document.getElementById('openAddModal');

const closePlanModal =
    document.getElementById('closePlanModal');

const cancelPlanModal =
    document.getElementById('cancelPlanModal');

const planForm =
    document.getElementById('planForm');

const formAction =
    document.getElementById('formAction');

const planId =
    document.getElementById('planId');

const planName =
    document.getElementById('planName');

const durationMonths =
    document.getElementById('durationMonths');

const planPrice =
    document.getElementById('planPrice');

const maxBranches =
    document.getElementById('maxBranches');

const planStatus =
    document.getElementById('planStatus');

const modalTitle =
    document.getElementById('modalTitle');

const modalDescription =
    document.getElementById('modalDescription');

const submitText =
    document.getElementById('submitText');

const previewName =
    document.getElementById('previewName');

const previewDuration =
    document.getElementById('previewDuration');

const previewPrice =
    document.getElementById('previewPrice');


/* =========================================================
   SHOW MODAL
========================================================= */

function showPlanModal() {

    planModal.classList.remove('hidden');

    planModal.classList.add('flex');

    document.body.style.overflow =
        'hidden';


    requestAnimationFrame(function () {

        planModalPanel.classList.remove(
            'translate-y-5',
            'opacity-0'
        );

    });
}


/* =========================================================
   CLOSE MODAL
========================================================= */

function hidePlanModal() {

    planModalPanel.classList.add(
        'translate-y-5',
        'opacity-0'
    );


    setTimeout(function () {

        planModal.classList.add('hidden');

        planModal.classList.remove('flex');

        document.body.style.overflow = '';

    }, 250);
}


/* =========================================================
   ADD MODE
========================================================= */

function openAddPlan() {

    planForm.reset();

    planId.value = '';

    formAction.name = 'add_plan';
    formAction.value = '1';

    modalTitle.textContent =
        'Add Subscription Plan';

    modalDescription.textContent =
        'Create a new subscription plan.';

    submitText.textContent =
        'Save Plan';

    planStatus.value =
        'Active';

    updatePreview();

    showPlanModal();

    setTimeout(function () {
        planName.focus();
    }, 300);
}


openAddModal?.addEventListener(
    'click',
    openAddPlan
);


/* =========================================================
   EDIT MODE
========================================================= */

document
.querySelectorAll('.editPlanButton')
.forEach(function (button) {

    button.addEventListener(
        'click',
        function () {

            planForm.reset();

            formAction.name =
                'update_plan';

            formAction.value =
                '1';


            planId.value =
                this.dataset.id;

            planName.value =
                this.dataset.name;

            durationMonths.value =
                this.dataset.duration;

            planPrice.value =
                this.dataset.price;

            maxBranches.value =
                this.dataset.branches;

            planStatus.value =
                this.dataset.status;


            modalTitle.textContent =
                'Update Subscription Plan';

            modalDescription.textContent =
                'Change plan price, duration or branch limit.';

            submitText.textContent =
                'Update Plan';


            updatePreview();

            showPlanModal();

        }
    );

});


/* =========================================================
   PREVIEW
========================================================= */

function updatePreview() {

    const name =
        planName.value.trim();

    const months =
        parseInt(
            durationMonths.value || 0
        );

    const price =
        parseFloat(
            planPrice.value || 0
        );


    previewName.textContent =
        name !== ''
        ? name
        : 'New Plan';


    previewDuration.textContent =
        months
        + (
            months === 1
            ? ' Month'
            : ' Months'
        );


    previewPrice.textContent =
        '$' + price.toFixed(2);
}


planName?.addEventListener(
    'input',
    updatePreview
);

durationMonths?.addEventListener(
    'input',
    updatePreview
);

planPrice?.addEventListener(
    'input',
    updatePreview
);


/* =========================================================
   CLOSE EVENTS
========================================================= */

closePlanModal?.addEventListener(
    'click',
    hidePlanModal
);

cancelPlanModal?.addEventListener(
    'click',
    hidePlanModal
);


planModal?.addEventListener(
    'click',
    function (event) {

        if (event.target === planModal) {

            hidePlanModal();

        }

    }
);


/* =========================================================
   ESC
========================================================= */

document.addEventListener(
    'keydown',
    function (event) {

        if (
            event.key === 'Escape'
            &&
            !planModal
            .classList
            .contains('hidden')
        ) {

            hidePlanModal();

        }

    }
);

</script>

</body>
</html>