<?php

$currentPage = basename($_SERVER['PHP_SELF']);

if (!function_exists('menuActive')) {
    function menuActive($pages)
    {
        global $currentPage;

        return in_array($currentPage, $pages, true)
            ? 'active bg-blue-600/15 text-white'
            : 'text-slate-300 hover:bg-white/5 hover:text-white';
    }
}

/*
|--------------------------------------------------------------------------
| SIDEBAR COLOR FROM SYSTEM SETTINGS
|--------------------------------------------------------------------------
*/

$sidebarColor = '#071527';

if (isset($conn) && $conn instanceof PDO) {
    try {
        $sidebarColorStmt = $conn->prepare("
            SELECT setting_value
            FROM system_settings
            WHERE setting_key = 'sidebar_color'
            LIMIT 1
        ");

        $sidebarColorStmt->execute();
        $savedSidebarColor = $sidebarColorStmt->fetchColumn();

        if (
            is_string($savedSidebarColor)
            && preg_match('/^#[0-9A-Fa-f]{6}$/', $savedSidebarColor)
        ) {
            $sidebarColor = $savedSidebarColor;
        }
    } catch (Throwable $e) {
        // Keep default sidebar color if setting/table is unavailable.
    }
}

$sidebarAdminName = $_SESSION['full_name'] ?? 'Super Admin';
$sidebarInitial = strtoupper(substr(trim($sidebarAdminName), 0, 1));

if ($sidebarInitial === '') {
    $sidebarInitial = 'S';
}

?>



<!-- MOBILE MENU BUTTON -->
<button
    id="mobileSidebarToggle"
    type="button"
    aria-label="Open menu"
    class="
        fixed left-4 top-4 z-[60]
        flex h-11 w-11 items-center justify-center
        rounded-xl border border-slate-200
        bg-white/95 text-slate-700 shadow-lg
        backdrop-blur transition
        hover:bg-slate-50 hover:text-blue-600
        focus:outline-none focus:ring-4 focus:ring-blue-100
        lg:hidden
    "
>
    <i data-lucide="menu" class="h-5 w-5"></i>
</button>

<aside
    id="sidebar"
    style="background-color: <?= htmlspecialchars($sidebarColor, ENT_QUOTES, 'UTF-8') ?>;"
    class="
        fixed inset-y-0 left-0 z-50
        flex w-[270px] -translate-x-full flex-col
        text-white shadow-2xl
        transition-transform duration-300
        lg:translate-x-0
    "
>

    <!-- LOGO -->

    <div
        class="
            flex h-[76px] items-center justify-between
            border-b border-white/10 px-5
        "
    >

        <a
            href="dashboard.php"
            class="flex min-w-0 items-center gap-3"
        >

            <div
                class="
                    float-animation flex h-11 w-11
                    flex-shrink-0 items-center justify-center
                    rounded-xl bg-gradient-to-br
                    from-blue-500 to-indigo-600
                    shadow-lg shadow-blue-950/40
                "
            >

                <i
                    data-lucide="boxes"
                    class="h-6 w-6"
                ></i>

            </div>


            <div class="min-w-0">

                <h1
                    class="
                        truncate text-[17px]
                        font-extrabold tracking-wide
                    "
                >
                    SYSTEM ADMIN
                </h1>

                <p
                    class="
                        truncate text-[11px]
                        text-slate-400
                    "
                >
                    Business Management
                </p>

            </div>

        </a>


        <button
            id="sidebarClose"
            type="button"
            class="
                flex h-9 w-9 items-center
                justify-center rounded-lg
                text-slate-400
                hover:bg-white/10 hover:text-white
                lg:hidden
            "
        >

            <i
                data-lucide="x"
                class="h-5 w-5"
            ></i>

        </button>

    </div>


    <!-- MENU -->

    <nav
        class="
            sidebar-scroll flex-1
            overflow-y-auto px-3 py-4
        "
    >


        <!-- MAIN -->

        <p
            class="
                px-3 pb-2 pt-2 text-[10px]
                font-bold uppercase
                tracking-[.15em]
                text-slate-500
            "
        >
            Main
        </p>


        <a
            href="dashboard.php"
            class="
                nav-item <?= menuActive(['dashboard.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="layout-dashboard"
                class="h-[18px] w-[18px]"
            ></i>

            Dashboard

        </a>
<a
    href="chat.php"
    class="
        nav-item <?= menuActive(['chat.php']) ?>
        mb-1 flex items-center gap-3
        rounded-xl px-3 py-2.5 text-[13px]
        font-medium
    "
>
    <i
        data-lucide="message-circle"
        class="h-[18px] w-[18px]"
    ></i>

    Chat
</a>

        <!-- MANAGEMENT -->

        <p
            class="
                px-3 pb-2 pt-6 text-[10px]
                font-bold uppercase tracking-[.15em]
                text-slate-500
            "
        >
            Management
        </p>


        <a
            href="businesses.php"
            class="
                nav-item <?= menuActive(['businesses.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="building-2"
                class="h-[18px] w-[18px]"
            ></i>

            Businesses

        </a>


        <a
            href="branches.php"
            class="
                nav-item <?= menuActive(['branches.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="git-branch"
                class="h-[18px] w-[18px]"
            ></i>

            Branches

        </a>


        <a
            href="users.php"
            class="
                nav-item <?= menuActive(['users.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="users"
                class="h-[18px] w-[18px]"
            ></i>

            Users

        </a>


        <a
            href="roles.php"
            class="
                nav-item <?= menuActive(['roles.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="shield-check"
                class="h-[18px] w-[18px]"
            ></i>

            User Permissions

        </a>


        <!-- SUBSCRIPTIONS -->

        <p
            class="
                px-3 pb-2 pt-6 text-[10px]
                font-bold uppercase tracking-[.15em]
                text-slate-500
            "
        >
            Billing
        </p>


        <a
            href="subscriptions.php"
            class="
                nav-item <?= menuActive(['subscriptions.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="credit-card"
                class="h-[18px] w-[18px]"
            ></i>

            Subscriptions

        </a>


        <a
            href="plans.php"
            class="
                nav-item <?= menuActive(['plans.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="badge-dollar-sign"
                class="h-[18px] w-[18px]"
            ></i>

            Subscription Plans

        </a>


        <!-- REPORTS -->

        <p
            class="
                px-3 pb-2 pt-6 text-[10px]
                font-bold uppercase tracking-[.15em]
                text-slate-500
            "
        >
            Reports
        </p>


        <a
            href="reports.php"
            class="
                nav-item <?= menuActive(['reports.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="chart-no-axes-combined"
                class="h-[18px] w-[18px]"
            ></i>

            System Reports

        </a>


        <!-- SYSTEM -->

        <p
            class="
                px-3 pb-2 pt-6 text-[10px]
                font-bold uppercase tracking-[.15em]
                text-slate-500
            "
        >
            System
        </p>


        <a
            href="audit-trail.php"
            class="
                nav-item <?= menuActive(['audit-trail.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="history"
                class="h-[18px] w-[18px]"
            ></i>

            Audit Trail

        </a>


        <a
            href="login-history.php"
            class="
                nav-item <?= menuActive(['login-history.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="log-in"
                class="h-[18px] w-[18px]"
            ></i>

            Login History

        </a>


        <a
            href="settings.php"
            class="
                nav-item <?= menuActive(['settings.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="settings"
                class="h-[18px] w-[18px]"
            ></i>

            Settings

        </a>


        <a
            href="profile.php"
            class="
                nav-item <?= menuActive(['profile.php']) ?>
                mb-1 flex items-center gap-3
                rounded-xl px-3 py-2.5 text-[13px]
                font-medium
            "
        >

            <i
                data-lucide="user-round"
                class="h-[18px] w-[18px]"
            ></i>

            My Profile

        </a>

    </nav>


    <!-- ADMIN PROFILE -->

    <div
        class="
            border-t border-white/10
            p-3
        "
    >

        <a
            href="profile.php"
            class="
                group flex items-center gap-3
                rounded-xl bg-white/5
                p-3 transition
                hover:bg-white/10
            "
        >

            <div
                class="
                    flex h-10 w-10
                    flex-shrink-0 items-center
                    justify-center rounded-full
                    bg-blue-600 font-bold
                    transition group-hover:scale-105
                "
            >
                <?= htmlspecialchars($sidebarInitial, ENT_QUOTES, 'UTF-8') ?>
            </div>


            <div class="min-w-0 flex-1">

                <p
                    class="
                        truncate text-[12px]
                        font-semibold
                    "
                >
                    <?= htmlspecialchars($sidebarAdminName, ENT_QUOTES, 'UTF-8') ?>
                </p>

                <div
                    class="
                        mt-1 flex items-center
                        gap-1.5
                    "
                >

                    <span
                        class="
                            h-2 w-2 rounded-full
                            bg-emerald-400
                            soft-pulse
                        "
                    ></span>

                    <span
                        class="text-[10px] text-slate-400"
                    >
                        Online · My Profile
                    </span>

                </div>

            </div>

            <i
                data-lucide="chevron-right"
                class="h-4 w-4 flex-shrink-0 text-slate-500 transition group-hover:translate-x-0.5 group-hover:text-white"
            ></i>

        </a>

    </div>

</aside>


<!-- MOBILE OVERLAY -->

<div
    id="sidebarOverlay"
    class="
        fixed inset-0 z-40 hidden
        bg-slate-950/60 opacity-0
        backdrop-blur-sm lg:hidden
    "
></div>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const openBtn = document.getElementById('mobileSidebarToggle');
    const closeBtn = document.getElementById('sidebarClose');

    function openSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        requestAnimationFrame(() => overlay.classList.remove('opacity-0'));
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('opacity-0');
        setTimeout(() => overlay.classList.add('hidden'), 300);
        document.body.style.overflow = '';
    }

    openBtn?.addEventListener('click', openSidebar);
    closeBtn?.addEventListener('click', closeSidebar);
    overlay?.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeSidebar();
    });
})();
</script>
