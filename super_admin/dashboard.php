<?php

session_start();

require_once '../config/database.php';
require_once '../config/auth.php';

requireSuperAdmin();
/*
|--------------------------------------------------------------------------
| TEMP SUPER ADMIN TEST
|--------------------------------------------------------------------------
| Marka Super Admin login-ka dhamaystirno,
| qaybtaan test-ka waan ka saaraynaa.
*/

if (
    !isset($_SESSION['user_id'])
    ||
    ($_SESSION['role'] ?? '') !== 'Super Admin'
) {

    /*
    header('Location: ../login.php');
    exit;
    */
}


/*
|--------------------------------------------------------------------------
| DASHBOARD DATA
|--------------------------------------------------------------------------
*/

$totalBusinesses = 0;
$totalBranches = 0;
$totalUsers = 0;
$activeSubscriptions = 0;
$expiredSubscriptions = 0;


/*
|--------------------------------------------------------------------------
| Count Businesses
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->query("
        SELECT COUNT(*)
        FROM businesses
    ");

    $totalBusinesses =
        (int) $stmt->fetchColumn();

} catch (Exception $e) {

    $totalBusinesses = 0;
}


/*
|--------------------------------------------------------------------------
| Count Branches
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->query("
        SELECT COUNT(*)
        FROM branches
    ");

    $totalBranches =
        (int) $stmt->fetchColumn();

} catch (Exception $e) {

    $totalBranches = 0;
}


/*
|--------------------------------------------------------------------------
| Count Users
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->query("
        SELECT COUNT(*)
        FROM users
        WHERE business_id IS NOT NULL
    ");

    $totalUsers =
        (int) $stmt->fetchColumn();

} catch (Exception $e) {

    $totalUsers = 0;
}

?>

<!DOCTYPE html>

<html
    lang="en"
    class="bg-slate-50"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Super Admin Dashboard
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
                            '0 10px 35px rgba(15, 23, 42, 0.06)'

                    }

                }

            }

        }

    </script>


    <!-- ICONS -->

    <script
        src="https://unpkg.com/lucide@latest"
    ></script>


    <!-- CUSTOM CSS -->

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


<?php
require_once 'includes/sidebar.php';
?>


<div class="min-h-screen lg:ml-[270px]">


    <?php
    require_once 'includes/header.php';
    ?>


    <!-- PAGE CONTENT -->

    <main
        class="
            page-enter
            px-4 py-6
            md:px-7 md:py-7
        "
    >


        <!-- PAGE TITLE -->

        <div
            class="
                mb-7 flex flex-col
                gap-4 xl:flex-row
                xl:items-center
                xl:justify-between
            "
        >

            <div>

                <div
                    class="
                        mb-1 flex items-center
                        gap-2
                    "
                >

                    <h1
                        class="
                            text-2xl font-extrabold
                            tracking-tight
                            text-slate-900
                            dark:text-white
                        "
                    >
                        Dashboard
                    </h1>

                    <span class="text-xl">
                        👋
                    </span>

                </div>

                <p
                    class="
                        text-[13px]
                        text-slate-500
                    "
                >
                    Monitor and manage your entire system from one place.
                </p>

            </div>


            <div
                class="
                    flex flex-wrap
                    items-center gap-2
                "
            >

                <div
                    class="
                        flex h-11 items-center
                        gap-2 rounded-xl
                        border border-slate-200
                        bg-white px-4
                        text-xs text-slate-600
                        shadow-sm
                    "
                >

                    <i
                        data-lucide="calendar-days"
                        class="h-4 w-4"
                    ></i>

                    <?= date('d M Y') ?>

                </div>


                <a
                    href="businesses.php"
                    class="
                        flex h-11 items-center
                        gap-2 rounded-xl
                        bg-blue-600 px-4
                        text-xs font-semibold
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

                </a>

            </div>

        </div>


        <!-- STATISTICS -->

        <div
            class="
                grid grid-cols-1
                gap-4 sm:grid-cols-2
                xl:grid-cols-4
            "
        >


            <!-- BUSINESSES -->

            <a
                href="businesses.php"
                class="
                    dashboard-card group
                    rounded-2xl border
                    border-slate-200
                    bg-white p-5
                    shadow-soft
                "
            >

                <div
                    class="
                        flex items-start
                        justify-between
                    "
                >

                    <div>

                        <p
                            class="
                                text-[11px]
                                font-semibold uppercase
                                tracking-wide
                                text-slate-500
                            "
                        >
                            Total Businesses
                        </p>

                        <h2
                            class="
                                mt-2 text-3xl
                                font-extrabold
                                text-slate-900
                            "
                        >
                            <?= number_format($totalBusinesses) ?>
                        </h2>

                    </div>


                    <div
                        class="
                            flex h-12 w-12
                            items-center justify-center
                            rounded-2xl
                            bg-blue-50 text-blue-600
                            transition
                            group-hover:scale-110
                        "
                    >

                        <i
                            data-lucide="building-2"
                            class="h-6 w-6"
                        ></i>

                    </div>

                </div>


                <div
                    class="
                        mt-5 flex items-center
                        justify-between
                    "
                >

                    <span
                        class="
                            text-[11px]
                            text-slate-500
                        "
                    >
                        Registered businesses
                    </span>

                    <i
                        data-lucide="arrow-up-right"
                        class="
                            h-4 w-4
                            text-blue-500
                        "
                    ></i>

                </div>

            </a>


            <!-- BRANCHES -->

            <a
                href="branches.php"
                class="
                    dashboard-card group
                    rounded-2xl border
                    border-slate-200
                    bg-white p-5
                    shadow-soft
                "
            >

                <div
                    class="
                        flex items-start
                        justify-between
                    "
                >

                    <div>

                        <p
                            class="
                                text-[11px]
                                font-semibold uppercase
                                tracking-wide
                                text-slate-500
                            "
                        >
                            Total Branches
                        </p>

                        <h2
                            class="
                                mt-2 text-3xl
                                font-extrabold
                            "
                        >
                            <?= number_format($totalBranches) ?>
                        </h2>

                    </div>


                    <div
                        class="
                            flex h-12 w-12
                            items-center justify-center
                            rounded-2xl
                            bg-emerald-50
                            text-emerald-600
                            transition
                            group-hover:scale-110
                        "
                    >

                        <i
                            data-lucide="git-branch"
                            class="h-6 w-6"
                        ></i>

                    </div>

                </div>


                <div
                    class="
                        mt-5 flex items-center
                        justify-between
                    "
                >

                    <span
                        class="
                            text-[11px]
                            text-slate-500
                        "
                    >
                        Across all businesses
                    </span>

                    <i
                        data-lucide="arrow-up-right"
                        class="
                            h-4 w-4
                            text-emerald-500
                        "
                    ></i>

                </div>

            </a>


            <!-- USERS -->

            <a
                href="users.php"
                class="
                    dashboard-card group
                    rounded-2xl border
                    border-slate-200
                    bg-white p-5
                    shadow-soft
                "
            >

                <div
                    class="
                        flex items-start
                        justify-between
                    "
                >

                    <div>

                        <p
                            class="
                                text-[11px]
                                font-semibold uppercase
                                tracking-wide
                                text-slate-500
                            "
                        >
                            System Users
                        </p>

                        <h2
                            class="
                                mt-2 text-3xl
                                font-extrabold
                            "
                        >
                            <?= number_format($totalUsers) ?>
                        </h2>

                    </div>


                    <div
                        class="
                            flex h-12 w-12
                            items-center justify-center
                            rounded-2xl
                            bg-violet-50
                            text-violet-600
                            transition
                            group-hover:scale-110
                        "
                    >

                        <i
                            data-lucide="users"
                            class="h-6 w-6"
                        ></i>

                    </div>

                </div>


                <div
                    class="
                        mt-5 flex items-center
                        justify-between
                    "
                >

                    <span
                        class="
                            text-[11px]
                            text-slate-500
                        "
                    >
                        Business accounts
                    </span>

                    <i
                        data-lucide="arrow-up-right"
                        class="
                            h-4 w-4
                            text-violet-500
                        "
                    ></i>

                </div>

            </a>


            <!-- SUBSCRIPTIONS -->

            <a
                href="subscriptions.php"
                class="
                    dashboard-card group
                    rounded-2xl border
                    border-slate-200
                    bg-white p-5
                    shadow-soft
                "
            >

                <div
                    class="
                        flex items-start
                        justify-between
                    "
                >

                    <div>

                        <p
                            class="
                                text-[11px]
                                font-semibold uppercase
                                tracking-wide
                                text-slate-500
                            "
                        >
                            Active Subscriptions
                        </p>

                        <h2
                            class="
                                mt-2 text-3xl
                                font-extrabold
                            "
                        >
                            <?= number_format($activeSubscriptions) ?>
                        </h2>

                    </div>


                    <div
                        class="
                            flex h-12 w-12
                            items-center justify-center
                            rounded-2xl
                            bg-amber-50
                            text-amber-600
                            transition
                            group-hover:scale-110
                        "
                    >

                        <i
                            data-lucide="crown"
                            class="h-6 w-6"
                        ></i>

                    </div>

                </div>


                <div
                    class="
                        mt-5 flex items-center
                        justify-between
                    "
                >

                    <span
                        class="
                            text-[11px]
                            text-slate-500
                        "
                    >
                        Currently active
                    </span>

                    <i
                        data-lucide="arrow-up-right"
                        class="
                            h-4 w-4
                            text-amber-500
                        "
                    ></i>

                </div>

            </a>

        </div>


        <!-- MAIN GRID -->

        <div
            class="
                mt-6 grid grid-cols-1
                gap-5 xl:grid-cols-3
            "
        >


            <!-- SYSTEM OVERVIEW -->

            <section
                class="
                    dashboard-card
                    rounded-2xl border
                    border-slate-200
                    bg-white p-5
                    shadow-soft
                    xl:col-span-2
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
                                text-[15px]
                                font-bold
                                text-slate-900
                            "
                        >
                            System Overview
                        </h3>

                        <p
                            class="
                                mt-1 text-[11px]
                                text-slate-500
                            "
                        >
                            Businesses registered this year
                        </p>

                    </div>


                    <button
                        type="button"
                        class="
                            rounded-lg border
                            border-slate-200
                            px-3 py-2
                            text-[11px]
                            text-slate-600
                        "
                    >
                        This Year
                    </button>

                </div>


                <!-- CHART -->

                <div
                    class="
                        mt-8 flex h-[270px]
                        items-end gap-4
                        border-b border-l
                        border-slate-200
                        px-4 pb-0
                        sm:gap-7
                    "
                >

                    <?php

                    $chart = [
                        35,
                        48,
                        42,
                        65,
                        58,
                        76,
                        68,
                        82,
                        74,
                        92,
                        78,
                        88
                    ];

                    foreach ($chart as $index => $height):

                    ?>

                        <div
                            class="
                                flex h-full flex-1
                                items-end
                            "
                        >

                            <div
                                class="
                                    chart-bar w-full
                                    rounded-t-md
                                    bg-gradient-to-t
                                    from-blue-600
                                    to-blue-400
                                    opacity-90
                                    transition
                                    hover:opacity-100
                                "
                                style="
                                    height:
                                    <?= $height ?>%;
                                    animation-delay:
                                    <?= $index * 60 ?>ms;
                                "
                            ></div>

                        </div>

                    <?php endforeach; ?>

                </div>


                <div
                    class="
                        mt-3 grid
                        grid-cols-6
                        text-center
                        text-[10px]
                        text-slate-400
                        sm:grid-cols-12
                    "
                >

                    <?php

                    $months = [
                        'Jan','Feb','Mar','Apr',
                        'May','Jun','Jul','Aug',
                        'Sep','Oct','Nov','Dec'
                    ];

                    foreach ($months as $month):

                    ?>

                        <span>
                            <?= $month ?>
                        </span>

                    <?php endforeach; ?>

                </div>

            </section>


            <!-- QUICK ACTIONS -->

            <section
                class="
                    dashboard-card
                    rounded-2xl border
                    border-slate-200
                    bg-white p-5
                    shadow-soft
                "
            >

                <div>

                    <h3
                        class="
                            text-[15px]
                            font-bold
                        "
                    >
                        Quick Actions
                    </h3>

                    <p
                        class="
                            mt-1 text-[11px]
                            text-slate-500
                        "
                    >
                        Frequently used actions
                    </p>

                </div>


                <div class="mt-5 space-y-2">


                    <a
                        href="businesses.php?action=add"
                        class="
                            group flex items-center
                            gap-3 rounded-xl
                            border border-slate-100
                            p-3 transition
                            hover:border-blue-200
                            hover:bg-blue-50/50
                        "
                    >

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


                        <div class="flex-1">

                            <p
                                class="
                                    text-xs font-semibold
                                "
                            >
                                Add Business
                            </p>

                            <p
                                class="
                                    mt-0.5 text-[10px]
                                    text-slate-500
                                "
                            >
                                Register new customer business
                            </p>

                        </div>


                        <i
                            data-lucide="chevron-right"
                            class="
                                h-4 w-4
                                text-slate-300
                                transition
                                group-hover:translate-x-1
                            "
                        ></i>

                    </a>


                    <a
                        href="subscriptions.php"
                        class="
                            group flex items-center
                            gap-3 rounded-xl
                            border border-slate-100
                            p-3 transition
                            hover:border-amber-200
                            hover:bg-amber-50/50
                        "
                    >

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
                                data-lucide="credit-card"
                                class="h-5 w-5"
                            ></i>

                        </div>


                        <div class="flex-1">

                            <p
                                class="
                                    text-xs font-semibold
                                "
                            >
                                Renew Subscription
                            </p>

                            <p
                                class="
                                    mt-0.5 text-[10px]
                                    text-slate-500
                                "
                            >
                                Extend business access
                            </p>

                        </div>


                        <i
                            data-lucide="chevron-right"
                            class="
                                h-4 w-4
                                text-slate-300
                                transition
                                group-hover:translate-x-1
                            "
                        ></i>

                    </a>


                    <a
                        href="users.php"
                        class="
                            group flex items-center
                            gap-3 rounded-xl
                            border border-slate-100
                            p-3 transition
                            hover:border-violet-200
                            hover:bg-violet-50/50
                        "
                    >

                        <div
                            class="
                                flex h-10 w-10
                                items-center justify-center
                                rounded-xl
                                bg-violet-50
                                text-violet-600
                            "
                        >

                            <i
                                data-lucide="user-plus"
                                class="h-5 w-5"
                            ></i>

                        </div>


                        <div class="flex-1">

                            <p
                                class="
                                    text-xs font-semibold
                                "
                            >
                                Manage Users
                            </p>

                            <p
                                class="
                                    mt-0.5 text-[10px]
                                    text-slate-500
                                "
                            >
                                View business users
                            </p>

                        </div>


                        <i
                            data-lucide="chevron-right"
                            class="
                                h-4 w-4
                                text-slate-300
                                transition
                                group-hover:translate-x-1
                            "
                        ></i>

                    </a>


                    <a
                        href="audit-trail.php"
                        class="
                            group flex items-center
                            gap-3 rounded-xl
                            border border-slate-100
                            p-3 transition
                            hover:border-emerald-200
                            hover:bg-emerald-50/50
                        "
                    >

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
                                data-lucide="history"
                                class="h-5 w-5"
                            ></i>

                        </div>


                        <div class="flex-1">

                            <p
                                class="
                                    text-xs font-semibold
                                "
                            >
                                Audit Trail
                            </p>

                            <p
                                class="
                                    mt-0.5 text-[10px]
                                    text-slate-500
                                "
                            >
                                Check system activities
                            </p>

                        </div>


                        <i
                            data-lucide="chevron-right"
                            class="
                                h-4 w-4
                                text-slate-300
                                transition
                                group-hover:translate-x-1
                            "
                        ></i>

                    </a>

                </div>

            </section>

        </div>


        <!-- BOTTOM -->

        <div
            class="
                mt-5 grid grid-cols-1
                gap-5 lg:grid-cols-2
            "
        >


            <!-- RECENT BUSINESSES -->

            <section
                class="
                    dashboard-card
                    rounded-2xl border
                    border-slate-200
                    bg-white p-5
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

                        <h3
                            class="
                                text-[15px]
                                font-bold
                            "
                        >
                            Recent Businesses
                        </h3>

                        <p
                            class="
                                mt-1 text-[11px]
                                text-slate-500
                            "
                        >
                            Newly registered businesses
                        </p>

                    </div>


                    <a
                        href="businesses.php"
                        class="
                            text-[11px]
                            font-semibold
                            text-blue-600
                        "
                    >
                        View All
                    </a>

                </div>


                <div
                    class="
                        mt-8 flex min-h-[180px]
                        flex-col items-center
                        justify-center
                        text-center
                    "
                >

                    <div
                        class="
                            flex h-14 w-14
                            items-center justify-center
                            rounded-2xl
                            bg-slate-100
                            text-slate-400
                        "
                    >

                        <i
                            data-lucide="building"
                            class="h-6 w-6"
                        ></i>

                    </div>

                    <h4
                        class="
                            mt-3 text-xs
                            font-semibold
                        "
                    >
                        No businesses yet
                    </h4>

                    <p
                        class="
                            mt-1 text-[11px]
                            text-slate-500
                        "
                    >
                        New businesses will appear here.
                    </p>

                </div>

            </section>


            <!-- SUBSCRIPTION -->

            <section
                class="
                    dashboard-card
                    rounded-2xl border
                    border-slate-200
                    bg-white p-5
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

                        <h3
                            class="
                                text-[15px]
                                font-bold
                            "
                        >
                            Subscription Status
                        </h3>

                        <p
                            class="
                                mt-1 text-[11px]
                                text-slate-500
                            "
                        >
                            Current customer subscriptions
                        </p>

                    </div>


                    <a
                        href="subscriptions.php"
                        class="
                            text-[11px]
                            font-semibold
                            text-blue-600
                        "
                    >
                        View All
                    </a>

                </div>


                <div
                    class="
                        mt-6 space-y-4
                    "
                >


                    <div>

                        <div
                            class="
                                mb-2 flex
                                justify-between
                                text-[11px]
                            "
                        >

                            <span
                                class="text-slate-500"
                            >
                                Active
                            </span>

                            <strong
                                class="text-emerald-600"
                            >
                                <?= $activeSubscriptions ?>
                            </strong>

                        </div>

                        <div
                            class="
                                h-2 overflow-hidden
                                rounded-full
                                bg-slate-100
                            "
                        >

                            <div
                                class="
                                    h-full w-[75%]
                                    rounded-full
                                    bg-emerald-500
                                "
                            ></div>

                        </div>

                    </div>


                    <div>

                        <div
                            class="
                                mb-2 flex
                                justify-between
                                text-[11px]
                            "
                        >

                            <span
                                class="text-slate-500"
                            >
                                Expired
                            </span>

                            <strong
                                class="text-red-500"
                            >
                                <?= $expiredSubscriptions ?>
                            </strong>

                        </div>

                        <div
                            class="
                                h-2 overflow-hidden
                                rounded-full
                                bg-slate-100
                            "
                        >

                            <div
                                class="
                                    h-full w-[20%]
                                    rounded-full
                                    bg-red-500
                                "
                            ></div>

                        </div>

                    </div>


                    <div
                        class="
                            mt-5 rounded-xl
                            bg-blue-50 p-4
                        "
                    >

                        <div
                            class="
                                flex items-start gap-3
                            "
                        >

                            <i
                                data-lucide="info"
                                class="
                                    mt-0.5 h-5 w-5
                                    text-blue-600
                                "
                            ></i>

                            <div>

                                <p
                                    class="
                                        text-xs font-semibold
                                        text-blue-900
                                    "
                                >
                                    Subscription Management
                                </p>

                                <p
                                    class="
                                        mt-1 text-[10px]
                                        leading-5
                                        text-blue-700
                                    "
                                >
                                    You can activate, suspend or renew
                                    business subscriptions from the
                                    subscriptions page.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </section>

        </div>

    </main>


    <?php
    require_once 'includes/footer.php';
    ?>

</div>


<script src="../assets/js/app.js"></script>


<script>

    lucide.createIcons();

</script>

</body>

</html>