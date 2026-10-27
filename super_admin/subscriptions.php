<?php

require_once '../config/auth.php';
requireSuperAdmin();

$message = '';
$messageType = '';

/* =========================================================
   HELPERS
========================================================= */

function subscriptionSetting(PDO $conn, string $key, $default = null)
{
    try {
        $stmt = $conn->prepare("\n            SELECT setting_value\n            FROM system_settings\n            WHERE setting_key = ?\n            LIMIT 1\n        ");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function addMonthsClamped(string $date, int $months): string
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    if (!$start || $start->format('Y-m-d') !== $date) {
        throw new Exception('Start Date ma saxna.');
    }

    $day = (int)$start->format('d');
    $target = $start->modify('first day of this month')->modify('+' . $months . ' months');
    $lastDay = (int)$target->format('t');

    return $target
        ->setDate(
            (int)$target->format('Y'),
            (int)$target->format('m'),
            min($day, $lastDay)
        )
        ->format('Y-m-d');
}

function validYmd(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

$graceDays = max(
    0,
    (int)subscriptionSetting(
        $conn,
        'subscription_grace_days',
        3
    )
);

/* =========================================================
   CSRF
========================================================= */

if (empty($_SESSION['subscriptions_csrf'])) {
    $_SESSION['subscriptions_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['subscriptions_csrf'];

function verifySubscriptionCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['subscriptions_csrf'] ?? '');

    if (
        $token === ''
        || $sessionToken === ''
        || !hash_equals($sessionToken, $token)
    ) {
        throw new Exception('Request-ka ma saxna. Fadlan page-ka refresh garee.');
    }
}

/* =========================================================
   AUTO EXPIRE AFTER GRACE PERIOD
========================================================= */

try {
    $stmt = $conn->prepare("\n        UPDATE subscriptions\n        SET status = 'Expired'\n        WHERE status = 'Active'\n          AND DATE_ADD(expiry_date, INTERVAL ? DAY) < CURDATE()\n    ");
    $stmt->execute([$graceDays]);

    /*
       Only sync businesses from their LATEST subscription.
       Never overwrite a manually Suspended business here.
    */
    $stmt = $conn->prepare("\n        UPDATE businesses b\n        INNER JOIN subscriptions s\n            ON s.id = (\n                SELECT s2.id\n                FROM subscriptions s2\n                WHERE s2.business_id = b.id\n                ORDER BY s2.expiry_date DESC, s2.id DESC\n                LIMIT 1\n            )\n        SET b.status = 'Expired'\n        WHERE b.status <> 'Suspended'\n          AND s.status = 'Expired'\n          AND DATE_ADD(s.expiry_date, INTERVAL ? DAY) < CURDATE()\n    ");
    $stmt->execute([$graceDays]);
} catch (Throwable $e) {
    // Do not break the page if automatic synchronization fails.
}

/* =========================================================
   SAVE / RENEW SUBSCRIPTION
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['save_subscription'])
) {
    try {
        verifySubscriptionCsrf();

        $businessId = (int)($_POST['business_id'] ?? 0);
        $planId = (int)($_POST['plan_id'] ?? 0);
        $startDate = trim((string)($_POST['start_date'] ?? ''));

        if ($businessId <= 0 || $planId <= 0 || $startDate === '') {
            throw new Exception('Fadlan buuxi Business, Plan iyo Start Date.');
        }

        if (!validYmd($startDate)) {
            throw new Exception('Start Date ma saxna.');
        }

        $stmt = $conn->prepare("\n            SELECT id, business_name, status\n            FROM businesses\n            WHERE id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$businessId]);
        $business = $stmt->fetch();

        if (!$business) {
            throw new Exception('Business lama helin.');
        }

        $stmt = $conn->prepare("\n            SELECT\n                id,\n                plan_name,\n                duration_months,\n                price,\n                max_branches\n            FROM subscription_plans\n            WHERE id = ?\n              AND status = 'Active'\n            LIMIT 1\n        ");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();

        if (!$plan) {
            throw new Exception('Subscription plan lama helin ama ma Active aha.');
        }

        $durationMonths = (int)$plan['duration_months'];

        if ($durationMonths <= 0) {
            throw new Exception('Plan duration-ka ma saxna.');
        }

        $expiryDate = addMonthsClamped($startDate, $durationMonths);

        /*
           Branch limit validation.
           NULL max_branches = unlimited.
        */
        if ($plan['max_branches'] !== null) {
            $stmt = $conn->prepare("\n                SELECT COUNT(*)\n                FROM branches\n                WHERE business_id = ?\n                  AND status = 'Active'\n            ");
            $stmt->execute([$businessId]);
            $activeBranchCount = (int)$stmt->fetchColumn();

            if ($activeBranchCount > (int)$plan['max_branches']) {
                throw new Exception(
                    'Plan-kan wuxuu oggol yahay ugu badnaan '
                    . (int)$plan['max_branches']
                    . ' branch, laakiin business-kan wuxuu leeyahay '
                    . $activeBranchCount
                    . ' active branches.'
                );
            }
        }

        $conn->beginTransaction();

        /*
           Keep history, but ensure there is only one current Active
           subscription for this business.
           Suspended history is NOT silently changed.
        */
        $stmt = $conn->prepare("\n            UPDATE subscriptions\n            SET status = 'Expired'\n            WHERE business_id = ?\n              AND status = 'Active'\n        ");
        $stmt->execute([$businessId]);

        $stmt = $conn->prepare("\n            INSERT INTO subscriptions (\n                business_id,\n                plan_id,\n                start_date,\n                expiry_date,\n                amount,\n                status\n            )\n            VALUES (?, ?, ?, ?, ?, 'Active')\n        ");
        $stmt->execute([
            $businessId,
            $planId,
            $startDate,
            $expiryDate,
            $plan['price'],
        ]);

        /*
           Renewal can reactivate Expired business.
           It must NEVER silently override manual Suspended status.
        */
        if ($business['status'] === 'Expired') {
            $stmt = $conn->prepare("\n                UPDATE businesses\n                SET status = 'Active'\n                WHERE id = ?\n                  AND status = 'Expired'\n            ");
            $stmt->execute([$businessId]);
        }

        $conn->commit();

        $message = $business['status'] === 'Suspended'
            ? 'Subscription-ka waa la renew-gareeyey, laakiin business-ku wali waa Suspended ilaa si gaar ah loo activate-gareeyo.'
            : 'Subscription-ka si guul leh ayaa loo kaydiyey / renew-gareeyey.';
        $messageType = 'success';

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $message = $e->getMessage();
        $messageType = 'error';
    }
}

/* =========================================================
   CHANGE STATUS
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['change_status'])
) {
    try {
        verifySubscriptionCsrf();

        $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
        $newStatus = trim((string)($_POST['new_status'] ?? ''));

        $allowedStatuses = ['Active', 'Suspended', 'Expired'];

        if (
            $subscriptionId <= 0
            || !in_array($newStatus, $allowedStatuses, true)
        ) {
            throw new Exception('Status request ma saxna.');
        }

        $stmt = $conn->prepare("\n            SELECT\n                s.id,\n                s.business_id,\n                s.start_date,\n                s.expiry_date,\n                s.status,\n                b.status AS business_status\n            FROM subscriptions s\n            INNER JOIN businesses b\n                ON b.id = s.business_id\n            WHERE s.id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$subscriptionId]);
        $subscription = $stmt->fetch();

        if (!$subscription) {
            throw new Exception('Subscription lama helin.');
        }

        $businessId = (int)$subscription['business_id'];

        /* Only the latest subscription may control business access. */
        $stmt = $conn->prepare("\n            SELECT id\n            FROM subscriptions\n            WHERE business_id = ?\n            ORDER BY expiry_date DESC, id DESC\n            LIMIT 1\n        ");
        $stmt->execute([$businessId]);
        $latestSubscriptionId = (int)$stmt->fetchColumn();

        if ($latestSubscriptionId !== $subscriptionId) {
            throw new Exception(
                'Historical subscription status lama beddeli karo. Latest subscription-ka isticmaal.'
            );
        }

        if ($newStatus === 'Active') {
            $today = new DateTimeImmutable('today');
            $start = new DateTimeImmutable($subscription['start_date']);
            $expiry = new DateTimeImmutable($subscription['expiry_date']);

            if ($today < $start) {
                throw new Exception('Subscription-kan wali ma bilaaban. Start Date-ka hubi.');
            }

            if ($today > $expiry) {
                throw new Exception('Subscription-kan date-kiisu wuu dhacay. Renew samee halkii aad Active ka dhigi lahayd.');
            }
        }

        $conn->beginTransaction();

        $stmt = $conn->prepare("\n            UPDATE subscriptions\n            SET status = ?\n            WHERE id = ?\n        ");
        $stmt->execute([$newStatus, $subscriptionId]);

        if ($newStatus === 'Suspended') {
            $stmt = $conn->prepare("\n                UPDATE businesses\n                SET status = 'Suspended'\n                WHERE id = ?\n            ");
            $stmt->execute([$businessId]);
        } elseif ($newStatus === 'Expired') {
            $stmt = $conn->prepare("\n                UPDATE businesses\n                SET status = 'Expired'\n                WHERE id = ?\n                  AND status <> 'Suspended'\n            ");
            $stmt->execute([$businessId]);
        } elseif ($newStatus === 'Active') {
            $stmt = $conn->prepare("\n                UPDATE businesses\n                SET status = 'Active'\n                WHERE id = ?\n            ");
            $stmt->execute([$businessId]);
        }

        $conn->commit();

        $message = 'Latest subscription status si sax ah ayaa loo beddelay.';
        $messageType = 'success';

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $message = $e->getMessage();
        $messageType = 'error';
    }
}

/* =========================================================
   BUSINESSES
========================================================= */

$businessStmt = $conn->query("\n    SELECT id, business_name\n    FROM businesses\n    ORDER BY business_name ASC\n");
$businesses = $businessStmt->fetchAll();

/* =========================================================
   PLANS
========================================================= */

$planStmt = $conn->query("\n    SELECT\n        id,\n        plan_name,\n        duration_months,\n        price,\n        max_branches\n    FROM subscription_plans\n    WHERE status = 'Active'\n    ORDER BY duration_months ASC\n");
$plans = $planStmt->fetchAll();

/* =========================================================
   FILTERS
========================================================= */

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$sql = "\n    SELECT\n        s.id,\n        s.business_id,\n        s.plan_id,\n        s.start_date,\n        s.expiry_date,\n        s.amount,\n        s.status,\n        s.created_at,\n        b.business_name,\n        sp.plan_name,\n        sp.duration_months,\n        sp.max_branches\n    FROM subscriptions s\n    INNER JOIN businesses b\n        ON b.id = s.business_id\n    INNER JOIN subscription_plans sp\n        ON sp.id = s.plan_id\n    WHERE 1 = 1\n";

$params = [];

if ($search !== '') {
    $sql .= "\n        AND (\n            b.business_name LIKE ?\n            OR sp.plan_name LIKE ?\n        )\n    ";

    $searchValue = '%' . $search . '%';
    $params[] = $searchValue;
    $params[] = $searchValue;
}

if (
    in_array(
        $statusFilter,
        ['Active', 'Suspended', 'Expired'],
        true
    )
) {
    $sql .= " AND s.status = ? ";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY s.id DESC ";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$subscriptions = $stmt->fetchAll();

/* =========================================================
   COUNTS
========================================================= */

$totalSubscriptions = (int)$conn->query("\n    SELECT COUNT(*) FROM subscriptions\n")->fetchColumn();

$stmt = $conn->prepare("\n    SELECT COUNT(*)\n    FROM subscriptions\n    WHERE status = 'Active'\n      AND DATE_ADD(expiry_date, INTERVAL ? DAY) >= CURDATE()\n");
$stmt->execute([$graceDays]);
$activeSubscriptions = (int)$stmt->fetchColumn();

$suspendedSubscriptions = (int)$conn->query("\n    SELECT COUNT(*)\n    FROM subscriptions\n    WHERE status = 'Suspended'\n")->fetchColumn();

$stmt = $conn->prepare("\n    SELECT COUNT(*)\n    FROM subscriptions\n    WHERE status = 'Expired'\n       OR DATE_ADD(expiry_date, INTERVAL ? DAY) < CURDATE()\n");
$stmt->execute([$graceDays]);
$expiredSubscriptions = (int)$stmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Subscriptions</title>

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


    <!-- PAGE HEADER -->

    <div
        class="
            mb-6
            flex flex-col gap-4
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
                    Subscriptions
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
                Subscription Management
            </h1>

            <p
                class="
                    mt-1
                    text-[12px]
                    text-slate-500
                "
            >
                Manage business subscriptions,
                expiry dates and account access.
            </p>

        </div>


        <button
            id="openSubscriptionModal"
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

            New Subscription

        </button>

    </div>


    <!-- MESSAGE -->

    <?php if ($message !== ''): ?>

        <div
            id="pageAlert"
            class="
                mb-5
                flex items-start
                justify-between
                gap-3
                rounded-xl
                border p-4

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


    <!-- KPI -->

    <div
        class="
            mb-6
            grid
            grid-cols-2
            gap-3
            xl:grid-cols-4
        "
    >


        <div
            class="
                dashboard-card
                rounded-2xl
                border border-slate-200
                bg-white
                p-4
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
                            text-[10px]
                            font-bold
                            uppercase
                            text-slate-400
                        "
                    >
                        Total
                    </p>

                    <h3
                        class="
                            mt-1
                            text-2xl
                            font-extrabold
                        "
                    >
                        <?= $totalSubscriptions ?>
                    </h3>

                </div>


                <div
                    class="
                        flex h-10 w-10
                        items-center
                        justify-center
                        rounded-xl
                        bg-blue-50
                        text-blue-600
                    "
                >

                    <i
                        data-lucide="credit-card"
                        class="h-5 w-5"
                    ></i>

                </div>

            </div>

        </div>


        <div
            class="
                dashboard-card
                rounded-2xl
                border border-slate-200
                bg-white
                p-4
                shadow-sm
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
                Active
            </p>

            <h3
                class="
                    mt-1
                    text-2xl
                    font-extrabold
                    text-emerald-600
                "
            >
                <?= $activeSubscriptions ?>
            </h3>

        </div>


        <div
            class="
                dashboard-card
                rounded-2xl
                border border-slate-200
                bg-white
                p-4
                shadow-sm
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
                Suspended
            </p>

            <h3
                class="
                    mt-1
                    text-2xl
                    font-extrabold
                    text-amber-600
                "
            >
                <?= $suspendedSubscriptions ?>
            </h3>

        </div>


        <div
            class="
                dashboard-card
                rounded-2xl
                border border-slate-200
                bg-white
                p-4
                shadow-sm
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
                Expired
            </p>

            <h3
                class="
                    mt-1
                    text-2xl
                    font-extrabold
                    text-red-600
                "
            >
                <?= $expiredSubscriptions ?>
            </h3>

        </div>

    </div>


    <!-- FILTER -->

    <section
        class="
            overflow-hidden
            rounded-2xl
            border
            border-slate-200
            bg-white
            shadow-sm
        "
    >

        <div
            class="
                flex
                flex-col gap-3
                border-b
                border-slate-100
                p-4
                md:flex-row
                md:items-center
                md:justify-between
            "
        >

            <div>

                <h3
                    class="
                        text-sm
                        font-bold
                    "
                >
                    Subscription List
                </h3>

                <p
                    class="
                        mt-1
                        text-[10px]
                        text-slate-500
                    "
                >
                    <?= count($subscriptions) ?>
                    records found
                </p>

            </div>


            <form
                method="GET"
                class="
                    grid
                    w-full
                    gap-2
                    md:w-auto
                    md:grid-cols-[250px_150px]
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
                        placeholder="Search business..."
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
                        value="Suspended"
                        <?= $statusFilter === 'Suspended'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        Suspended
                    </option>

                    <option
                        value="Expired"
                        <?= $statusFilter === 'Expired'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        Expired
                    </option>

                </select>

            </form>

        </div>


        <!-- MOBILE -->

        <div class="divide-y divide-slate-100 md:hidden">

            <?php if (!$subscriptions): ?>

                <div class="p-10 text-center">

                    <i
                        data-lucide="credit-card"
                        class="
                            mx-auto
                            h-10 w-10
                            text-slate-300
                        "
                    ></i>

                    <p class="mt-3 text-xs text-slate-500">
                        No subscriptions found.
                    </p>

                </div>

            <?php endif; ?>


            <?php foreach ($subscriptions as $row): ?>

                <?php

                $today = new DateTime(date('Y-m-d'));

                $expiry = new DateTime(
                    $row['expiry_date']
                );

                $daysLeft =
                    (int) $today
                    ->diff($expiry)
                    ->format('%r%a');

                ?>

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
                                    $row['business_name']
                                ) ?>
                            </h4>

                            <p
                                class="
                                    mt-1
                                    text-[11px]
                                    text-slate-500
                                "
                            >
                                <?= htmlspecialchars(
                                    $row['plan_name']
                                ) ?>
                            </p>

                        </div>


                        <?php if ($row['status'] === 'Active'): ?>

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

                        <?php elseif (
                            $row['status'] === 'Suspended'
                        ): ?>

                            <span
                                class="
                                    rounded-full
                                    bg-amber-50
                                    px-2.5
                                    py-1
                                    text-[10px]
                                    font-semibold
                                    text-amber-700
                                "
                            >
                                Suspended
                            </span>

                        <?php else: ?>

                            <span
                                class="
                                    rounded-full
                                    bg-red-50
                                    px-2.5
                                    py-1
                                    text-[10px]
                                    font-semibold
                                    text-red-700
                                "
                            >
                                Expired
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
                        "
                    >

                        <div>

                            <p class="text-[9px] text-slate-400">
                                START DATE
                            </p>

                            <p class="mt-1 text-[11px] font-semibold">

                                <?= date(
                                    'd M Y',
                                    strtotime($row['start_date'])
                                ) ?>

                            </p>

                        </div>


                        <div>

                            <p class="text-[9px] text-slate-400">
                                EXPIRY DATE
                            </p>

                            <p class="mt-1 text-[11px] font-semibold">

                                <?= date(
                                    'd M Y',
                                    strtotime($row['expiry_date'])
                                ) ?>

                            </p>

                        </div>


                        <div>

                            <p class="text-[9px] text-slate-400">
                                PRICE
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-[11px]
                                    font-bold
                                "
                            >
                                $<?= number_format(
                                    $row['amount'],
                                    2
                                ) ?>
                            </p>

                        </div>


                        <div>

                            <p class="text-[9px] text-slate-400">
                                DAYS LEFT
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-[11px]
                                    font-bold
                                    <?=
                                        $daysLeft <= 7
                                        ? 'text-red-600'
                                        : 'text-slate-700'
                                    ?>
                                "
                            >

                                <?=
                                    $daysLeft >= 0
                                    ? $daysLeft . ' days'
                                    : 'Expired'
                                ?>

                            </p>

                        </div>

                    </div>


                    <div class="mt-3 flex gap-2">

                        <button
                            type="button"
                            class="
                                renewButton
                                flex h-9
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
                            data-business="<?= $row['business_id'] ?>"
                        >

                            <i
                                data-lucide="refresh-cw"
                                class="h-3.5 w-3.5"
                            ></i>

                            Renew

                        </button>


                        <button
                            type="button"
                            class="
                                statusButton
                                flex h-9
                                flex-1
                                items-center
                                justify-center
                                gap-2
                                rounded-xl
                                bg-slate-100
                                text-[10px]
                                font-semibold
                                text-slate-600
                            "
                            data-id="<?= $row['id'] ?>"
                            data-business="<?=
                                htmlspecialchars(
                                    $row['business_name'],
                                    ENT_QUOTES
                                )
                            ?>"
                            data-status="<?= $row['status'] ?>"
                        >

                            <i
                                data-lucide="settings-2"
                                class="h-3.5 w-3.5"
                            ></i>

                            Status

                        </button>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>


        <!-- DESKTOP TABLE -->

        <div class="hidden overflow-x-auto md:block">

            <table class="min-w-full">

                <thead class="bg-slate-50">

                    <tr>

                        <th class="px-5 py-3 text-left text-[10px] uppercase text-slate-500">
                            Business
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] uppercase text-slate-500">
                            Plan
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] uppercase text-slate-500">
                            Start
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] uppercase text-slate-500">
                            Expiry
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] uppercase text-slate-500">
                            Amount
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] uppercase text-slate-500">
                            Remaining
                        </th>

                        <th class="px-5 py-3 text-left text-[10px] uppercase text-slate-500">
                            Status
                        </th>

                        <th class="px-5 py-3 text-right text-[10px] uppercase text-slate-500">
                            Actions
                        </th>

                    </tr>

                </thead>


                <tbody class="divide-y divide-slate-100">

                <?php if (!$subscriptions): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="
                                px-5
                                py-12
                                text-center
                                text-xs
                                text-slate-500
                            "
                        >
                            No subscriptions found.
                        </td>

                    </tr>

                <?php endif; ?>


                <?php foreach ($subscriptions as $row): ?>

                    <?php

                    $today = new DateTime(date('Y-m-d'));

                    $expiry = new DateTime(
                        $row['expiry_date']
                    );

                    $daysLeft =
                        (int) $today
                        ->diff($expiry)
                        ->format('%r%a');

                    ?>

                    <tr class="hover:bg-slate-50/70">

                        <td class="px-5 py-4">

                            <div class="flex items-center gap-3">

                                <div
                                    class="
                                        flex h-9 w-9
                                        items-center
                                        justify-center
                                        rounded-xl
                                        bg-blue-50
                                        text-blue-600
                                    "
                                >

                                    <i
                                        data-lucide="building-2"
                                        class="h-4 w-4"
                                    ></i>

                                </div>

                                <p class="text-xs font-bold">

                                    <?= htmlspecialchars(
                                        $row['business_name']
                                    ) ?>

                                </p>

                            </div>

                        </td>


                        <td
                            class="
                                px-5
                                py-4
                                text-xs
                                font-semibold
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
                                text-[10px]
                                text-slate-500
                            "
                        >

                            <?= date(
                                'd M Y',
                                strtotime($row['start_date'])
                            ) ?>

                        </td>


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
                                strtotime($row['expiry_date'])
                            ) ?>

                        </td>


                        <td
                            class="
                                px-5
                                py-4
                                text-xs
                                font-bold
                            "
                        >
                            $<?= number_format(
                                $row['amount'],
                                2
                            ) ?>
                        </td>


                        <td class="px-5 py-4">

                            <?php if ($daysLeft >= 0): ?>

                                <span
                                    class="
                                        text-[10px]
                                        font-semibold
                                        <?=
                                            $daysLeft <= 7
                                            ? 'text-red-600'
                                            : (
                                                $daysLeft <= 30
                                                ? 'text-amber-600'
                                                : 'text-slate-600'
                                            )
                                        ?>
                                    "
                                >
                                    <?= $daysLeft ?> days
                                </span>

                            <?php else: ?>

                                <span
                                    class="
                                        text-[10px]
                                        font-semibold
                                        text-red-600
                                    "
                                >
                                    Expired
                                </span>

                            <?php endif; ?>

                        </td>


                        <td class="px-5 py-4">

                            <?php if ($row['status'] === 'Active'): ?>

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

                            <?php elseif (
                                $row['status'] === 'Suspended'
                            ): ?>

                                <span
                                    class="
                                        inline-flex
                                        items-center
                                        gap-1.5
                                        rounded-full
                                        bg-amber-50
                                        px-2.5
                                        py-1
                                        text-[10px]
                                        font-semibold
                                        text-amber-700
                                    "
                                >
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
                                        px-2.5
                                        py-1
                                        text-[10px]
                                        font-semibold
                                        text-red-700
                                    "
                                >
                                    Expired
                                </span>

                            <?php endif; ?>

                        </td>


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
                                    title="Renew"
                                    class="
                                        renewButton
                                        flex h-8 w-8
                                        items-center
                                        justify-center
                                        rounded-lg
                                        text-slate-400
                                        hover:bg-blue-50
                                        hover:text-blue-600
                                    "
                                    data-business="<?= $row['business_id'] ?>"
                                >

                                    <i
                                        data-lucide="refresh-cw"
                                        class="h-4 w-4"
                                    ></i>

                                </button>


                                <button
                                    type="button"
                                    title="Status"
                                    class="
                                        statusButton
                                        flex h-8 w-8
                                        items-center
                                        justify-center
                                        rounded-lg
                                        text-slate-400
                                        hover:bg-amber-50
                                        hover:text-amber-600
                                    "
                                    data-id="<?= $row['id'] ?>"
                                    data-business="<?=
                                        htmlspecialchars(
                                            $row['business_name'],
                                            ENT_QUOTES
                                        )
                                    ?>"
                                    data-status="<?= $row['status'] ?>"
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

                </tbody>

            </table>

        </div>

    </section>

</main>


<?php require_once 'includes/footer.php'; ?>


</div>


<!-- =========================================================
     SUBSCRIPTION MODAL
========================================================= -->

<div
    id="subscriptionModal"
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
        id="subscriptionPanel"
        class="
            max-h-[94vh]
            w-full
            max-w-xl
            translate-y-5
            overflow-y-auto
            rounded-2xl
            bg-white
            opacity-0
            shadow-2xl
            transition-all
            duration-300
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
                border-slate-100
                bg-white/95
                px-5
                py-4
                backdrop-blur-xl
            "
        >

            <div>

                <h3 class="text-sm font-bold">
                    New / Renew Subscription
                </h3>

                <p class="mt-1 text-[10px] text-slate-500">
                    Select business and subscription plan.
                </p>

            </div>


            <button
                id="closeSubscriptionModal"
                type="button"
                class="
                    flex h-9 w-9
                    items-center
                    justify-center
                    rounded-xl
                    text-slate-400
                    hover:bg-slate-100
                "
            >

                <i
                    data-lucide="x"
                    class="h-5 w-5"
                ></i>

            </button>

        </div>


        <form method="POST">

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <input
                type="hidden"
                name="save_subscription"
                value="1"
            >


            <div class="space-y-4 p-5">


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
                        Business
                    </label>

                    <select
                        id="businessId"
                        name="business_id"
                        required
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

                        <option value="">
                            Select Business
                        </option>

                        <?php foreach ($businesses as $business): ?>

                            <option value="<?= $business['id'] ?>">

                                <?= htmlspecialchars(
                                    $business['business_name']
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

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
                        Subscription Plan
                    </label>

                    <select
                        id="planId"
                        name="plan_id"
                        required
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

                        <option value="">
                            Select Plan
                        </option>

                        <?php foreach ($plans as $plan): ?>

                            <option
                                value="<?= $plan['id'] ?>"
                                data-months="<?= $plan['duration_months'] ?>"
                                data-price="<?= $plan['price'] ?>"
                            >

                                <?= htmlspecialchars(
                                    $plan['plan_name']
                                ) ?>

                                -
                                $<?= number_format(
                                    $plan['price'],
                                    2
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

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
                        Start Date
                    </label>

                    <input
                        id="startDate"
                        type="date"
                        name="start_date"
                        value="<?= date('Y-m-d') ?>"
                        required
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


                <div
                    class="
                        grid
                        grid-cols-2
                        gap-3
                        rounded-2xl
                        bg-slate-50
                        p-4
                    "
                >

                    <div>

                        <p
                            class="
                                text-[9px]
                                font-bold
                                uppercase
                                text-slate-400
                            "
                        >
                            Price
                        </p>

                        <p
                            id="previewPrice"
                            class="
                                mt-1
                                text-xl
                                font-extrabold
                            "
                        >
                            $0.00
                        </p>

                    </div>


                    <div>

                        <p
                            class="
                                text-[9px]
                                font-bold
                                uppercase
                                text-slate-400
                            "
                        >
                            Expiry Date
                        </p>

                        <p
                            id="previewExpiry"
                            class="
                                mt-1
                                text-sm
                                font-bold
                                text-blue-600
                            "
                        >
                            -
                        </p>

                    </div>

                </div>

            </div>


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
                "
            >

                <button
                    id="cancelSubscriptionModal"
                    type="button"
                    class="
                        h-11
                        rounded-xl
                        border
                        border-slate-200
                        px-5
                        text-xs
                        font-semibold
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
                        hover:bg-blue-700
                    "
                >

                    <i
                        data-lucide="check"
                        class="h-4 w-4"
                    ></i>

                    Save Subscription

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     STATUS MODAL
========================================================= -->

<div
    id="statusModal"
    class="
        fixed
        inset-0
        z-[110]
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
            w-full
            max-w-md
            rounded-2xl
            bg-white
            p-5
            shadow-2xl
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

                <h3 class="text-sm font-bold">
                    Change Status
                </h3>

                <p
                    id="statusBusiness"
                    class="
                        mt-1
                        text-[11px]
                        text-slate-500
                    "
                ></p>

            </div>


            <button
                id="closeStatusModal"
                type="button"
            >

                <i
                    data-lucide="x"
                    class="h-5 w-5"
                ></i>

            </button>

        </div>


        <form
            method="POST"
            class="mt-5"
        >

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <input
                type="hidden"
                name="change_status"
                value="1"
            >

            <input
                id="statusSubscriptionId"
                type="hidden"
                name="subscription_id"
            >


            <select
                id="statusSelect"
                name="new_status"
                class="
                    h-11
                    w-full
                    rounded-xl
                    border
                    border-slate-200
                    px-3
                    text-xs
                    outline-none
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
                    mt-5
                    flex
                    justify-end
                    gap-2
                "
            >

                <button
                    id="cancelStatusModal"
                    type="button"
                    class="
                        h-10
                        rounded-xl
                        border
                        border-slate-200
                        px-4
                        text-xs
                        font-semibold
                    "
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="
                        h-10
                        rounded-xl
                        bg-blue-600
                        px-4
                        text-xs
                        font-semibold
                        text-white
                    "
                >
                    Save
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


/* =============================================
   SUBSCRIPTION MODAL
============================================= */

const subscriptionModal =
    document.getElementById('subscriptionModal');

const subscriptionPanel =
    document.getElementById('subscriptionPanel');

const openSubscriptionModal =
    document.getElementById('openSubscriptionModal');

const closeSubscriptionModal =
    document.getElementById('closeSubscriptionModal');

const cancelSubscriptionModal =
    document.getElementById('cancelSubscriptionModal');


function showSubscriptionModal() {

    subscriptionModal.classList.remove('hidden');
    subscriptionModal.classList.add('flex');

    document.body.style.overflow = 'hidden';

    requestAnimationFrame(function () {

        subscriptionPanel.classList.remove(
            'translate-y-5',
            'opacity-0'
        );

    });
}


function hideSubscriptionModal() {

    subscriptionPanel.classList.add(
        'translate-y-5',
        'opacity-0'
    );

    setTimeout(function () {

        subscriptionModal.classList.add('hidden');
        subscriptionModal.classList.remove('flex');

        document.body.style.overflow = '';

    }, 250);
}


openSubscriptionModal?.addEventListener(
    'click',
    showSubscriptionModal
);

closeSubscriptionModal?.addEventListener(
    'click',
    hideSubscriptionModal
);

cancelSubscriptionModal?.addEventListener(
    'click',
    hideSubscriptionModal
);


subscriptionModal?.addEventListener(
    'click',
    function (e) {

        if (e.target === subscriptionModal) {
            hideSubscriptionModal();
        }

    }
);


/* =============================================
   PLAN PREVIEW
============================================= */

const planId =
    document.getElementById('planId');

const startDate =
    document.getElementById('startDate');

const previewPrice =
    document.getElementById('previewPrice');

const previewExpiry =
    document.getElementById('previewExpiry');


function updatePreview() {

    const option =
        planId.options[
            planId.selectedIndex
        ];

    const months =
        parseInt(
            option?.dataset?.months || 0
        );

    const price =
        parseFloat(
            option?.dataset?.price || 0
        );

    previewPrice.textContent =
        '$' + price.toFixed(2);


    if (
        months > 0
        && startDate.value
    ) {

        const date =
            new Date(
                startDate.value + 'T00:00:00'
            );

        const originalDay = date.getDate();

        date.setDate(1);
        date.setMonth(date.getMonth() + months);

        const lastDay = new Date(
            date.getFullYear(),
            date.getMonth() + 1,
            0
        ).getDate();

        date.setDate(
            Math.min(originalDay, lastDay)
        );

        previewExpiry.textContent =
            date.toLocaleDateString(
                'en-GB',
                {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                }
            );

    } else {

        previewExpiry.textContent = '-';

    }
}


planId?.addEventListener(
    'change',
    updatePreview
);

startDate?.addEventListener(
    'change',
    updatePreview
);


/* =============================================
   RENEW BUTTON
============================================= */

document
.querySelectorAll('.renewButton')
.forEach(function (button) {

    button.addEventListener(
        'click',
        function () {

            const businessSelect =
                document.getElementById(
                    'businessId'
                );

            if (businessSelect) {

                businessSelect.value =
                    this.dataset.business;

            }

            showSubscriptionModal();

        }
    );

});


/* =============================================
   STATUS MODAL
============================================= */

const statusModal =
    document.getElementById('statusModal');

const statusSubscriptionId =
    document.getElementById('statusSubscriptionId');

const statusBusiness =
    document.getElementById('statusBusiness');

const statusSelect =
    document.getElementById('statusSelect');


function hideStatusModal() {

    statusModal.classList.add('hidden');
    statusModal.classList.remove('flex');

    document.body.style.overflow = '';
}


document
.querySelectorAll('.statusButton')
.forEach(function (button) {

    button.addEventListener(
        'click',
        function () {

            statusSubscriptionId.value =
                this.dataset.id;

            statusBusiness.textContent =
                this.dataset.business;

            statusSelect.value =
                this.dataset.status;

            statusModal.classList.remove('hidden');
            statusModal.classList.add('flex');

            document.body.style.overflow =
                'hidden';

        }
    );

});


document
.getElementById('closeStatusModal')
?.addEventListener(
    'click',
    hideStatusModal
);


document
.getElementById('cancelStatusModal')
?.addEventListener(
    'click',
    hideStatusModal
);


statusModal?.addEventListener(
    'click',
    function (e) {

        if (e.target === statusModal) {
            hideStatusModal();
        }

    }
);


/* =============================================
   ESC
============================================= */

document.addEventListener(
    'keydown',
    function (e) {

        if (e.key !== 'Escape') return;

        if (
            !subscriptionModal
            .classList
            .contains('hidden')
        ) {

            hideSubscriptionModal();

        }

        if (
            !statusModal
            .classList
            .contains('hidden')
        ) {

            hideStatusModal();

        }

    }
);

</script>

</body>
</html>