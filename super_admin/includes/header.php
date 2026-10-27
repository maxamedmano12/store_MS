<?php
/*
|--------------------------------------------------------------------------
| DYNAMIC SUPER ADMIN HEADER DATA
|--------------------------------------------------------------------------
| This header expects $conn to already exist (loaded by database.php/auth.php).
| It safely falls back to session/default values if a query fails.
*/

$headerFullName = trim((string)($_SESSION['full_name'] ?? 'Super Admin'));
$headerRole = trim((string)($_SESSION['role'] ?? 'Super Admin'));

if ($headerFullName === '') {
    $headerFullName = 'Super Admin';
}

$headerInitials = '';
foreach (preg_split('/\s+/', $headerFullName) as $part) {
    if ($part !== '') {
        $headerInitials .= strtoupper(substr($part, 0, 1));
    }
}
$headerInitials = substr($headerInitials, 0, 2);

if ($headerInitials === '') {
    $headerInitials = 'SA';
}

if (!function_exists('headerE')) {
    function headerE($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
*/

$notificationStats = [
    'expiring_soon' => 0,
    'expired' => 0,
    'suspended' => 0,
    'failed_logins' => 0,
];

if (isset($conn) && $conn instanceof PDO) {
    try {
        // Latest subscription of each business expiring in the next 7 days.
        $stmt = $conn->query("
            SELECT COUNT(*)
            FROM businesses b
            INNER JOIN subscriptions s
                ON s.id = (
                    SELECT s2.id
                    FROM subscriptions s2
                    WHERE s2.business_id = b.id
                    ORDER BY s2.expiry_date DESC, s2.id DESC
                    LIMIT 1
                )
            WHERE s.status = 'Active'
              AND s.expiry_date >= CURDATE()
              AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $notificationStats['expiring_soon'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $notificationStats['expiring_soon'] = 0;
    }

    try {
        // Businesses whose latest subscription is expired.
        $stmt = $conn->query("
            SELECT COUNT(*)
            FROM businesses b
            INNER JOIN subscriptions s
                ON s.id = (
                    SELECT s2.id
                    FROM subscriptions s2
                    WHERE s2.business_id = b.id
                    ORDER BY s2.expiry_date DESC, s2.id DESC
                    LIMIT 1
                )
            WHERE s.status = 'Expired'
               OR s.expiry_date < CURDATE()
        ");
        $notificationStats['expired'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $notificationStats['expired'] = 0;
    }

    try {
        $stmt = $conn->query("
            SELECT COUNT(*)
            FROM businesses
            WHERE status = 'Suspended'
        ");
        $notificationStats['suspended'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $notificationStats['suspended'] = 0;
    }

    try {
        $stmt = $conn->query("
            SELECT COUNT(*)
            FROM login_history
            WHERE event_type = 'LOGIN_FAILED'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $notificationStats['failed_logins'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $notificationStats['failed_logins'] = 0;
    }
}

$notificationCount =
    $notificationStats['expiring_soon']
    + $notificationStats['expired']
    + $notificationStats['suspended']
    + $notificationStats['failed_logins'];

$notificationBadge = $notificationCount > 99 ? '99+' : (string)$notificationCount;

/*
|--------------------------------------------------------------------------
| GLOBAL SEARCH
|--------------------------------------------------------------------------
| Searches businesses and users. Results are rendered by the header and
| filtered instantly in the browser. This keeps the feature simple and
| avoids requiring a separate API file.
*/

$globalSearchBusinesses = [];
$globalSearchUsers = [];

if (isset($conn) && $conn instanceof PDO) {
    try {
        $stmt = $conn->query("
            SELECT
                b.id,
                b.business_name,
                b.phone,
                b.status
            FROM businesses b
            ORDER BY b.id DESC
            LIMIT 100
        ");
        $globalSearchBusinesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $globalSearchBusinesses = [];
    }

    try {
        $stmt = $conn->query("
            SELECT
                u.id,
                u.full_name,
                u.username,
                u.status,
                r.role_name,
                b.business_name
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            LEFT JOIN businesses b ON b.id = u.business_id
            ORDER BY u.id DESC
            LIMIT 100
        ");
        $globalSearchUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $globalSearchUsers = [];
    }
}

?>
<header
    class="
        sticky top-0 z-30
        flex h-[72px]
        items-center justify-between
        border-b border-slate-200/80
        bg-white/90 px-4
        backdrop-blur-xl
        md:px-6
        dark:border-slate-800
        dark:bg-slate-950/90
    "
>

    <!-- LEFT -->

    <div
        class="
            flex min-w-0
            items-center gap-3
        "
    >

        <button
            id="sidebarToggle"
            type="button"
            class="
                flex h-10 w-10 items-center
                justify-center rounded-xl
                border border-slate-200
                bg-white text-slate-600
                transition hover:bg-slate-50
                lg:hidden
            "
        >

            <i
                data-lucide="menu"
                class="h-5 w-5"
            ></i>

        </button>


        <div class="hidden md:block">

            <h2
                class="
                    text-[16px] font-bold
                    text-slate-900
                    dark:text-white
                "
            >
                <?= headerE($headerFullName) ?>
            </h2>

            <p
                class="
                    text-[11px]
                    text-slate-500
                "
            >
                System Control Center
            </p>

        </div>

    </div>


    <!-- CENTER GLOBAL SEARCH -->

    <div
        id="globalSearchWrap"
        class="
            relative mx-6 hidden
            max-w-xl flex-1
            lg:block
        "
    >

        <div class="relative">

            <i
                data-lucide="search"
                class="
                    absolute left-3.5 top-1/2
                    h-4 w-4 -translate-y-1/2
                    text-slate-400
                "
            ></i>

            <input
                id="globalSearchInput"
                type="search"
                autocomplete="off"
                placeholder="Search businesses, users..."
                class="
                    h-11 w-full rounded-xl
                    border border-slate-200
                    bg-slate-50 pl-10 pr-10
                    text-[13px]
                    text-slate-800
                    outline-none transition
                    placeholder:text-slate-400
                    focus:border-blue-400
                    focus:bg-white
                    focus:ring-4
                    focus:ring-blue-100
                    dark:border-slate-800
                    dark:bg-slate-900
                    dark:text-white
                    dark:focus:ring-blue-950
                "
            >

            <button
                id="globalSearchClear"
                type="button"
                aria-label="Clear search"
                class="
                    absolute right-2.5 top-1/2 hidden
                    h-7 w-7 -translate-y-1/2
                    items-center justify-center
                    rounded-lg text-slate-400
                    hover:bg-slate-200/70
                    hover:text-slate-700
                    dark:hover:bg-slate-800
                    dark:hover:text-white
                "
            >
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>

        </div>


        <div
            id="globalSearchDropdown"
            class="
                dropdown-menu absolute
                left-0 right-0 top-[52px]
                z-50 hidden overflow-hidden
                rounded-2xl border
                border-slate-200 bg-white
                shadow-2xl
                dark:border-slate-800
                dark:bg-slate-900
            "
        >

            <div
                class="
                    flex items-center justify-between
                    border-b border-slate-100
                    px-4 py-3
                    dark:border-slate-800
                "
            >
                <div>
                    <p
                        class="
                            text-xs font-bold
                            text-slate-800
                            dark:text-white
                        "
                    >
                        Global Search
                    </p>

                    <p
                        id="globalSearchHint"
                        class="mt-0.5 text-[10px] text-slate-400"
                    >
                        Type at least 1 character
                    </p>
                </div>

                <span
                    id="globalSearchCount"
                    class="
                        hidden rounded-full
                        bg-blue-50 px-2 py-1
                        text-[10px] font-bold
                        text-blue-600
                    "
                ></span>
            </div>


            <div
                id="globalSearchResults"
                class="max-h-[390px] overflow-y-auto p-2"
            >

                <?php foreach ($globalSearchBusinesses as $searchBusiness): ?>
                    <?php
                    $businessSearchText = strtolower(
                        trim(
                            ($searchBusiness['business_name'] ?? '') . ' ' .
                            ($searchBusiness['phone'] ?? '') . ' ' .
                            ($searchBusiness['status'] ?? '')
                        )
                    );
                    ?>

                    <a
                        href="business-view.php?id=<?= (int)$searchBusiness['id'] ?>"
                        data-global-search-item
                        data-search-type="business"
                        data-search-text="<?= headerE($businessSearchText) ?>"
                        class="
                            hidden items-center gap-3
                            rounded-xl px-3 py-3
                            transition hover:bg-blue-50
                            dark:hover:bg-slate-800
                        "
                    >
                        <div
                            class="
                                flex h-9 w-9 flex-shrink-0
                                items-center justify-center
                                rounded-xl bg-blue-50
                                text-blue-600
                            "
                        >
                            <i data-lucide="building-2" class="h-4 w-4"></i>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p
                                    class="
                                        truncate text-xs
                                        font-bold text-slate-800
                                        dark:text-white
                                    "
                                >
                                    <?= headerE($searchBusiness['business_name'] ?? '') ?>
                                </p>

                                <span
                                    class="
                                        rounded-md bg-blue-50
                                        px-1.5 py-0.5
                                        text-[9px] font-bold
                                        uppercase text-blue-600
                                    "
                                >
                                    Business
                                </span>
                            </div>

                            <p
                                class="
                                    mt-1 truncate
                                    text-[10px] text-slate-400
                                "
                            >
                                <?= headerE($searchBusiness['phone'] ?: 'No phone') ?>
                                ·
                                <?= headerE($searchBusiness['status'] ?? '') ?>
                            </p>
                        </div>

                        <i
                            data-lucide="chevron-right"
                            class="h-4 w-4 flex-shrink-0 text-slate-300"
                        ></i>
                    </a>
                <?php endforeach; ?>


                <?php foreach ($globalSearchUsers as $searchUser): ?>
                    <?php
                    $userSearchText = strtolower(
                        trim(
                            ($searchUser['full_name'] ?? '') . ' ' .
                            ($searchUser['username'] ?? '') . ' ' .
                            ($searchUser['role_name'] ?? '') . ' ' .
                            ($searchUser['business_name'] ?? '') . ' ' .
                            ($searchUser['status'] ?? '')
                        )
                    );

                    $searchUserInitials = '';
                    foreach (
                        preg_split(
                            '/\s+/',
                            trim((string)($searchUser['full_name'] ?? ''))
                        ) as $searchNamePart
                    ) {
                        if ($searchNamePart !== '') {
                            $searchUserInitials .= strtoupper(
                                substr($searchNamePart, 0, 1)
                            );
                        }
                    }
                    $searchUserInitials = substr($searchUserInitials, 0, 2);
                    ?>

                    <a
                        href="users.php?search=<?= urlencode((string)($searchUser['username'] ?? '')) ?>"
                        data-global-search-item
                        data-search-type="user"
                        data-search-text="<?= headerE($userSearchText) ?>"
                        class="
                            hidden items-center gap-3
                            rounded-xl px-3 py-3
                            transition hover:bg-violet-50
                            dark:hover:bg-slate-800
                        "
                    >
                        <div
                            class="
                                flex h-9 w-9 flex-shrink-0
                                items-center justify-center
                                rounded-xl bg-violet-50
                                text-[10px] font-black
                                text-violet-600
                            "
                        >
                            <?= headerE($searchUserInitials ?: 'U') ?>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p
                                    class="
                                        truncate text-xs
                                        font-bold text-slate-800
                                        dark:text-white
                                    "
                                >
                                    <?= headerE($searchUser['full_name'] ?? '') ?>
                                </p>

                                <span
                                    class="
                                        rounded-md bg-violet-50
                                        px-1.5 py-0.5
                                        text-[9px] font-bold
                                        uppercase text-violet-600
                                    "
                                >
                                    User
                                </span>
                            </div>

                            <p
                                class="
                                    mt-1 truncate
                                    text-[10px] text-slate-400
                                "
                            >
                                @<?= headerE($searchUser['username'] ?? '') ?>
                                ·
                                <?= headerE($searchUser['role_name'] ?? '') ?>
                                <?php if (!empty($searchUser['business_name'])): ?>
                                    · <?= headerE($searchUser['business_name']) ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <i
                            data-lucide="chevron-right"
                            class="h-4 w-4 flex-shrink-0 text-slate-300"
                        ></i>
                    </a>
                <?php endforeach; ?>


                <div
                    id="globalSearchEmpty"
                    class="px-4 py-8 text-center"
                >
                    <div
                        class="
                            mx-auto flex h-11 w-11
                            items-center justify-center
                            rounded-2xl bg-slate-100
                            text-slate-400
                            dark:bg-slate-800
                        "
                    >
                        <i data-lucide="search" class="h-5 w-5"></i>
                    </div>

                    <p
                        id="globalSearchEmptyTitle"
                        class="
                            mt-3 text-xs font-bold
                            text-slate-700
                            dark:text-slate-100
                        "
                    >
                        Search the system
                    </p>

                    <p
                        id="globalSearchEmptyText"
                        class="mt-1 text-[11px] text-slate-400"
                    >
                        Find a business or user quickly.
                    </p>
                </div>

            </div>


            <div
                class="
                    flex items-center gap-4
                    border-t border-slate-100
                    px-4 py-2.5
                    text-[9px] text-slate-400
                    dark:border-slate-800
                "
            >
                <span class="flex items-center gap-1">
                    <kbd
                        class="
                            rounded border border-slate-200
                            bg-slate-50 px-1.5 py-0.5
                            dark:border-slate-700
                            dark:bg-slate-800
                        "
                    >ESC</kbd>
                    close
                </span>

                <span>
                    Businesses + Users
                </span>
            </div>

        </div>

    </div>


    <!-- RIGHT -->

    <div
        class="
            flex items-center gap-2
            md:gap-3
        "
    >

        <!-- THEME -->

        <button
            id="themeToggle"
            type="button"
            class="
                flex h-10 w-10 items-center
                justify-center rounded-xl
                text-slate-500 transition
                hover:bg-slate-100
            "
        >

            <i
                data-lucide="sun"
                class="h-[19px] w-[19px]"
            ></i>

        </button>


        <!-- NOTIFICATION -->

        <div class="relative">

            <button
                id="notificationButton"
                type="button"
                class="
                    relative flex h-10 w-10
                    items-center justify-center
                    rounded-xl text-slate-500
                    transition hover:bg-slate-100
                "
            >

                <i
                    data-lucide="bell"
                    class="h-[19px] w-[19px]"
                ></i>

                <?php if ($notificationCount > 0): ?>
                    <span
                        class="
                            absolute right-1 top-1
                            flex h-[16px] min-w-[16px]
                            items-center justify-center
                            rounded-full bg-red-500 px-1
                            text-[9px] font-bold text-white
                        "
                    >
                        <?= headerE($notificationBadge) ?>
                    </span>
                <?php endif; ?>

            </button>


            <div
                id="notificationDropdown"
                class="
                    dropdown-menu absolute
                    right-0 top-12 hidden
                    w-[340px] rounded-2xl
                    border border-slate-200
                    bg-white p-2
                    shadow-2xl
                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <div
                    class="
                        flex items-center justify-between
                        border-b border-slate-100
                        px-3 py-2
                        dark:border-slate-800
                    "
                >
                    <div>
                        <h4
                            class="
                                text-sm font-bold
                                text-slate-800
                                dark:text-white
                            "
                        >
                            Notifications
                        </h4>

                        <p class="mt-0.5 text-[10px] text-slate-400">
                            Live system alerts
                        </p>
                    </div>

                    <?php if ($notificationCount > 0): ?>
                        <span
                            class="
                                rounded-full bg-red-50
                                px-2 py-1 text-[10px]
                                font-bold text-red-600
                            "
                        >
                            <?= headerE($notificationBadge) ?> alert<?= $notificationCount === 1 ? '' : 's' ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="max-h-[360px] overflow-y-auto py-1">

                    <?php if ($notificationStats['expiring_soon'] > 0): ?>
                        <a
                            href="subscriptions.php"
                            class="
                                flex gap-3 rounded-xl
                                px-3 py-3 transition
                                hover:bg-amber-50
                                dark:hover:bg-slate-800
                            "
                        >
                            <div
                                class="
                                    flex h-9 w-9 flex-shrink-0
                                    items-center justify-center
                                    rounded-xl bg-amber-100
                                    text-amber-600
                                "
                            >
                                <i data-lucide="clock-3" class="h-4 w-4"></i>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p
                                    class="
                                        text-xs font-bold
                                        text-slate-700
                                        dark:text-slate-100
                                    "
                                >
                                    Subscription expiring soon
                                </p>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    <?= number_format($notificationStats['expiring_soon']) ?>
                                    business<?= $notificationStats['expiring_soon'] === 1 ? '' : 'es' ?>
                                    expire within 7 days.
                                </p>
                            </div>

                            <span
                                class="
                                    rounded-full bg-amber-100
                                    px-2 py-1 text-[10px]
                                    font-bold text-amber-700
                                "
                            >
                                <?= number_format($notificationStats['expiring_soon']) ?>
                            </span>
                        </a>
                    <?php endif; ?>


                    <?php if ($notificationStats['expired'] > 0): ?>
                        <a
                            href="subscriptions.php"
                            class="
                                flex gap-3 rounded-xl
                                px-3 py-3 transition
                                hover:bg-red-50
                                dark:hover:bg-slate-800
                            "
                        >
                            <div
                                class="
                                    flex h-9 w-9 flex-shrink-0
                                    items-center justify-center
                                    rounded-xl bg-red-100
                                    text-red-600
                                "
                            >
                                <i data-lucide="calendar-x" class="h-4 w-4"></i>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p
                                    class="
                                        text-xs font-bold
                                        text-slate-700
                                        dark:text-slate-100
                                    "
                                >
                                    Expired subscriptions
                                </p>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    <?= number_format($notificationStats['expired']) ?>
                                    business<?= $notificationStats['expired'] === 1 ? '' : 'es' ?>
                                    need renewal.
                                </p>
                            </div>

                            <span
                                class="
                                    rounded-full bg-red-100
                                    px-2 py-1 text-[10px]
                                    font-bold text-red-700
                                "
                            >
                                <?= number_format($notificationStats['expired']) ?>
                            </span>
                        </a>
                    <?php endif; ?>


                    <?php if ($notificationStats['suspended'] > 0): ?>
                        <a
                            href="businesses.php"
                            class="
                                flex gap-3 rounded-xl
                                px-3 py-3 transition
                                hover:bg-violet-50
                                dark:hover:bg-slate-800
                            "
                        >
                            <div
                                class="
                                    flex h-9 w-9 flex-shrink-0
                                    items-center justify-center
                                    rounded-xl bg-violet-100
                                    text-violet-600
                                "
                            >
                                <i data-lucide="ban" class="h-4 w-4"></i>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p
                                    class="
                                        text-xs font-bold
                                        text-slate-700
                                        dark:text-slate-100
                                    "
                                >
                                    Suspended businesses
                                </p>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    <?= number_format($notificationStats['suspended']) ?>
                                    business<?= $notificationStats['suspended'] === 1 ? '' : 'es' ?>
                                    currently suspended.
                                </p>
                            </div>

                            <span
                                class="
                                    rounded-full bg-violet-100
                                    px-2 py-1 text-[10px]
                                    font-bold text-violet-700
                                "
                            >
                                <?= number_format($notificationStats['suspended']) ?>
                            </span>
                        </a>
                    <?php endif; ?>


                    <?php if ($notificationStats['failed_logins'] > 0): ?>
                        <a
                            href="login-history.php"
                            class="
                                flex gap-3 rounded-xl
                                px-3 py-3 transition
                                hover:bg-orange-50
                                dark:hover:bg-slate-800
                            "
                        >
                            <div
                                class="
                                    flex h-9 w-9 flex-shrink-0
                                    items-center justify-center
                                    rounded-xl bg-orange-100
                                    text-orange-600
                                "
                            >
                                <i data-lucide="shield-alert" class="h-4 w-4"></i>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p
                                    class="
                                        text-xs font-bold
                                        text-slate-700
                                        dark:text-slate-100
                                    "
                                >
                                    Failed login attempts
                                </p>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    <?= number_format($notificationStats['failed_logins']) ?>
                                    failed attempt<?= $notificationStats['failed_logins'] === 1 ? '' : 's' ?>
                                    during the last 24 hours.
                                </p>
                            </div>

                            <span
                                class="
                                    rounded-full bg-orange-100
                                    px-2 py-1 text-[10px]
                                    font-bold text-orange-700
                                "
                            >
                                <?= number_format($notificationStats['failed_logins']) ?>
                            </span>
                        </a>
                    <?php endif; ?>


                    <?php if ($notificationCount === 0): ?>
                        <div class="px-4 py-8 text-center">
                            <div
                                class="
                                    mx-auto flex h-11 w-11
                                    items-center justify-center
                                    rounded-2xl bg-emerald-50
                                    text-emerald-600
                                "
                            >
                                <i data-lucide="circle-check-big" class="h-5 w-5"></i>
                            </div>

                            <p
                                class="
                                    mt-3 text-xs font-bold
                                    text-slate-700
                                    dark:text-slate-100
                                "
                            >
                                Everything looks good
                            </p>

                            <p class="mt-1 text-[11px] text-slate-400">
                                There are no system alerts right now.
                            </p>
                        </div>
                    <?php endif; ?>

                </div>

                <div
                    class="
                        border-t border-slate-100
                        px-2 pt-2
                        dark:border-slate-800
                    "
                >
                    <a
                        href="login-history.php"
                        class="
                            flex items-center justify-center
                            gap-2 rounded-xl px-3 py-2.5
                            text-[11px] font-bold text-blue-600
                            transition hover:bg-blue-50
                            dark:hover:bg-slate-800
                        "
                    >
                        <i data-lucide="activity" class="h-4 w-4"></i>
                        View system activity
                    </a>
                </div>

            </div>

        </div>


        <div
            class="
                mx-1 hidden h-8
                w-px bg-slate-200
                md:block
            "
        ></div>


        <!-- PROFILE -->

        <div class="relative">

            <button
                id="profileButton"
                type="button"
                class="
                    flex items-center gap-2
                    rounded-xl p-1.5
                    transition
                    hover:bg-slate-100
                "
            >

                <div
                    class="
                        flex h-9 w-9
                        items-center justify-center
                        rounded-full
                        bg-gradient-to-br
                        from-slate-700 to-slate-950
                        text-xs font-bold text-white
                    "
                >
                    <?= headerE($headerInitials) ?>
                </div>


                <div
                    class="
                        hidden text-left
                        md:block
                    "
                >

                    <p
                        class="
                            text-[12px] font-bold
                            text-slate-800
                        "
                    >
                        <?= headerE($headerFullName) ?>
                    </p>

                    <p
                        class="
                            text-[10px]
                            text-slate-500
                        "
                    >
                        <?= headerE($headerRole) ?>
                    </p>

                </div>


                <i
                    data-lucide="chevron-down"
                    class="
                        hidden h-4 w-4
                        text-slate-400
                        md:block
                    "
                ></i>

            </button>


            <div
                id="profileDropdown"
                class="
                    dropdown-menu absolute
                    right-0 top-12 hidden
                    w-52 rounded-2xl
                    border border-slate-200
                    bg-white p-2
                    shadow-2xl
                "
            >

                <a
                    href="profile.php"
                    class="
                        flex items-center gap-2
                        rounded-xl px-3 py-2.5
                        text-xs text-slate-700
                        hover:bg-slate-50
                    "
                >

                    <i
                        data-lucide="user"
                        class="h-4 w-4"
                    ></i>

                    My Profile

                </a>


                <a
                    href="settings.php"
                    class="
                        flex items-center gap-2
                        rounded-xl px-3 py-2.5
                        text-xs text-slate-700
                        hover:bg-slate-50
                    "
                >

                    <i
                        data-lucide="settings"
                        class="h-4 w-4"
                    ></i>

                    Settings

                </a>


                <div
                    class="
                        my-1 border-t
                        border-slate-100
                    "
                ></div>


                <a
                    href="../logout.php"
                    class="
                        flex items-center gap-2
                        rounded-xl px-3 py-2.5
                        text-xs font-semibold
                        text-red-600
                        hover:bg-red-50
                    "
                >

                    <i
                        data-lucide="log-out"
                        class="h-4 w-4"
                    ></i>

                    Logout

                </a>

            </div>

        </div>

    </div>

</header>