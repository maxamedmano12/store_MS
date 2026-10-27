<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('businessHeaderE')) {
    function businessHeaderE($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$headerBusinessName = $_SESSION['business_name'] ?? 'Business';
$headerFullName     = $_SESSION['full_name'] ?? 'User';
$headerRole         = $_SESSION['role'] ?? 'User';
$headerBusinessId   = (int) ($_SESSION['business_id'] ?? 0);
$headerBranchId     = (int) ($_SESSION['branch_id'] ?? 0);
$headerBranchName   = $_SESSION['branch_name'] ?? 'Main Branch';

$headerInitials = '';
foreach (preg_split('/\s+/', trim($headerFullName)) as $part) {
    if ($part !== '') {
        $headerInitials .= strtoupper(substr($part, 0, 1));
    }
    if (strlen($headerInitials) >= 2) {
        break;
    }
}
if ($headerInitials === '') {
    $headerInitials = 'U';
}

/* =========================================================
   BRANCHES
========================================================= */
$headerBranches = [];

if (isset($conn) && $conn instanceof PDO && $headerBusinessId > 0) {
    try {
        $branchStmt = $conn->prepare("
            SELECT id, branch_name, is_main
            FROM branches
            WHERE business_id = ?
              AND status = 'Active'
            ORDER BY is_main DESC, branch_name ASC
        ");
        $branchStmt->execute([$headerBusinessId]);
        $headerBranches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $headerBranches = [];
    }
}

$canSwitchBranch = in_array(
    $headerRole,
    ['Business Admin', 'Manager', 'Accountant'],
    true
) && count($headerBranches) > 1;

/* =========================================================
   SUBSCRIPTION NOTICE
========================================================= */
$subscriptionExpiry = $_SESSION['subscription_expiry'] ?? null;
$subscriptionPlan   = $_SESSION['subscription_plan'] ?? null;
$inGrace            = !empty($_SESSION['subscription_in_grace']);
$graceDaysLeft      = (int) ($_SESSION['subscription_grace_days_left'] ?? 0);

$subscriptionDaysLeft = null;

if ($subscriptionExpiry) {
    try {
        $today = new DateTimeImmutable(date('Y-m-d'));
        $expiry = new DateTimeImmutable($subscriptionExpiry);
        $subscriptionDaysLeft = (int) $today->diff($expiry)->format('%r%a');
    } catch (Throwable $e) {
        $subscriptionDaysLeft = null;
    }
}

$showSubscriptionWarning =
    $inGrace ||
    ($subscriptionDaysLeft !== null && $subscriptionDaysLeft >= 0 && $subscriptionDaysLeft <= 7);

$notificationCount = $showSubscriptionWarning ? 1 : 0;
?>

<header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl dark:border-slate-800 dark:bg-slate-950/90">
    <div class="flex h-[74px] items-center justify-between gap-3 px-4 md:px-6">

        <!-- LEFT -->
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <button id="businessSidebarToggle"
                    type="button"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 lg:hidden dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                <i data-lucide="menu" class="h-5 w-5"></i>
            </button>

            <div class="hidden min-w-0 sm:block">
                <p class="truncate text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                    <?= businessHeaderE($headerBusinessName) ?>
                </p>
                <h2 class="truncate text-[14px] font-extrabold text-slate-900 dark:text-white">
                    Business Dashboard
                </h2>
            </div>

            <!-- SEARCH -->
            <div class="relative ml-0 hidden max-w-md flex-1 xl:block">
                <i data-lucide="search"
                   class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>

                <input id="businessGlobalSearch"
                       type="search"
                       autocomplete="off"
                       placeholder="Search products, sales, customers..."
                       class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-12 text-[11px] text-slate-700 outline-none transition focus:border-blue-400 focus:bg-white focus:ring-4 focus:ring-blue-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200 dark:focus:ring-blue-950">

                <span class="absolute right-3 top-1/2 -translate-y-1/2 rounded-md border border-slate-200 bg-white px-1.5 py-0.5 text-[9px] font-bold text-slate-400 dark:border-slate-700 dark:bg-slate-800">
                    ⌘K
                </span>
            </div>
        </div>

        <!-- RIGHT -->
        <div class="flex shrink-0 items-center gap-2">

            <!-- BRANCH -->
            <div class="relative">
                <button id="businessBranchButton"
                        type="button"
                        class="flex h-10 max-w-[180px] items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-left transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">
                        <i data-lucide="store" class="h-3.5 w-3.5"></i>
                    </span>

                    <span class="hidden min-w-0 sm:block">
                        <span class="block text-[8px] font-bold uppercase tracking-wide text-slate-400">Branch</span>
                        <span class="block truncate text-[10px] font-bold text-slate-700 dark:text-slate-200">
                            <?= businessHeaderE($headerBranchName) ?>
                        </span>
                    </span>

                    <?php if ($canSwitchBranch): ?>
                        <i data-lucide="chevron-down" class="hidden h-3.5 w-3.5 text-slate-400 sm:block"></i>
                    <?php endif; ?>
                </button>

                <?php if ($canSwitchBranch): ?>
                    <div id="businessBranchMenu"
                         class="absolute right-0 mt-2 hidden w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl shadow-slate-900/10 dark:border-slate-800 dark:bg-slate-900">

                        <div class="px-3 py-2">
                            <p class="text-[9px] font-extrabold uppercase tracking-[0.14em] text-slate-400">
                                Switch Branch
                            </p>
                        </div>

                        <?php foreach ($headerBranches as $branch): ?>
                            <a href="switch-branch.php?branch_id=<?= (int) $branch['id'] ?>"
                               class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800">
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg <?= (int) $branch['id'] === $headerBranchId ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300' ?>">
                                    <i data-lucide="<?= !empty($branch['is_main']) ? 'building-2' : 'store' ?>" class="h-4 w-4"></i>
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[10px] font-bold text-slate-700 dark:text-slate-200">
                                        <?= businessHeaderE($branch['branch_name']) ?>
                                    </span>
                                    <span class="text-[9px] text-slate-400">
                                        <?= !empty($branch['is_main']) ? 'Main Branch' : 'Branch' ?>
                                    </span>
                                </span>

                                <?php if ((int) $branch['id'] === $headerBranchId): ?>
                                    <i data-lucide="check" class="h-4 w-4 text-blue-600"></i>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- THEME -->
            <button id="businessThemeToggle"
                    type="button"
                    title="Change theme"
                    class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                <i id="businessThemeIcon" data-lucide="moon" class="h-4 w-4"></i>
            </button>

            <!-- NOTIFICATION -->
            <div class="relative">
                <button id="businessNotificationButton"
                        type="button"
                        class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                    <i data-lucide="bell" class="h-4 w-4"></i>

                    <?php if ($notificationCount > 0): ?>
                        <span class="absolute right-1.5 top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[8px] font-extrabold text-white">
                            <?= $notificationCount ?>
                        </span>
                    <?php endif; ?>
                </button>

                <div id="businessNotificationMenu"
                     class="absolute right-0 mt-2 hidden w-[310px] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/10 dark:border-slate-800 dark:bg-slate-900">

                    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                        <div>
                            <p class="text-[11px] font-extrabold text-slate-800 dark:text-white">Notifications</p>
                            <p class="mt-0.5 text-[9px] text-slate-400">Business alerts and updates</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-[8px] font-bold text-slate-500 dark:bg-slate-800">
                            <?= $notificationCount ?>
                        </span>
                    </div>

                    <div class="max-h-80 overflow-y-auto p-2">
                        <?php if ($showSubscriptionWarning): ?>
                            <a href="subscription.php"
                               class="flex gap-3 rounded-xl p-3 transition hover:bg-slate-50 dark:hover:bg-slate-800">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl <?= $inGrace ? 'bg-red-50 text-red-600 dark:bg-red-950/50' : 'bg-amber-50 text-amber-600 dark:bg-amber-950/50' ?>">
                                    <i data-lucide="<?= $inGrace ? 'triangle-alert' : 'clock-3' ?>" class="h-4 w-4"></i>
                                </span>

                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold text-slate-700 dark:text-slate-200">
                                        <?= $inGrace ? 'Subscription grace period' : 'Subscription expiring soon' ?>
                                    </p>
                                    <p class="mt-1 text-[9px] leading-4 text-slate-500">
                                        <?php if ($inGrace): ?>
                                            Grace period: <?= max(0, $graceDaysLeft) ?> day(s) left.
                                        <?php else: ?>
                                            <?= businessHeaderE($subscriptionPlan ?: 'Current plan') ?>
                                            expires in <?= max(0, (int) $subscriptionDaysLeft) ?> day(s).
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </a>
                        <?php else: ?>
                            <div class="px-4 py-8 text-center">
                                <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40">
                                    <i data-lucide="circle-check" class="h-5 w-5"></i>
                                </div>
                                <p class="mt-3 text-[10px] font-bold text-slate-600 dark:text-slate-300">
                                    Everything looks good
                                </p>
                                <p class="mt-1 text-[9px] text-slate-400">
                                    No important notifications right now.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PROFILE -->
            <div class="relative">
                <button id="businessProfileButton"
                        type="button"
                        class="flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-2 pr-3 transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-[9px] font-extrabold text-white">
                        <?= businessHeaderE($headerInitials) ?>
                    </span>

                    <span class="hidden max-w-[130px] text-left md:block">
                        <span class="block truncate text-[9px] font-bold text-slate-700 dark:text-slate-200">
                            <?= businessHeaderE($headerFullName) ?>
                        </span>
                        <span class="block truncate text-[8px] text-slate-400">
                            <?= businessHeaderE($headerRole) ?>
                        </span>
                    </span>

                    <i data-lucide="chevron-down" class="hidden h-3.5 w-3.5 text-slate-400 md:block"></i>
                </button>

                <div id="businessProfileMenu"
                     class="absolute right-0 mt-2 hidden w-56 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl shadow-slate-900/10 dark:border-slate-800 dark:bg-slate-900">
                    <div class="border-b border-slate-100 px-3 py-3 dark:border-slate-800">
                        <p class="truncate text-[10px] font-extrabold text-slate-800 dark:text-white">
                            <?= businessHeaderE($headerFullName) ?>
                        </p>
                        <p class="mt-0.5 truncate text-[9px] text-slate-400">
                            <?= businessHeaderE($headerBusinessName) ?>
                        </p>
                    </div>

                    <div class="py-1">
                        <a href="profile.php"
                           class="flex items-center gap-2 rounded-xl px-3 py-2.5 text-[10px] font-semibold text-slate-600 transition hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800">
                            <i data-lucide="user-round" class="h-4 w-4"></i>
                            My Profile
                        </a>

                        <a href="settings.php"
                           class="flex items-center gap-2 rounded-xl px-3 py-2.5 text-[10px] font-semibold text-slate-600 transition hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800">
                            <i data-lucide="settings" class="h-4 w-4"></i>
                            Settings
                        </a>
                    </div>

                    <div class="border-t border-slate-100 pt-1 dark:border-slate-800">
                        <a href="../logout.php"
                           class="flex items-center gap-2 rounded-xl px-3 py-2.5 text-[10px] font-semibold text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950/30">
                            <i data-lucide="log-out" class="h-4 w-4"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($showSubscriptionWarning): ?>
        <div class="<?= $inGrace ? 'bg-red-600' : 'bg-amber-500' ?> px-4 py-2 text-center text-[9px] font-semibold text-white">
            <?php if ($inGrace): ?>
                Your subscription has expired. Grace period has <?= max(0, $graceDaysLeft) ?> day(s) remaining.
            <?php else: ?>
                Your <?= businessHeaderE($subscriptionPlan ?: 'subscription') ?> expires on
                <?= businessHeaderE(date('d M Y', strtotime((string) $subscriptionExpiry))) ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</header>

<script>
(function () {
    const root = document.documentElement;
    const sidebar = document.getElementById('businessSidebar');
    const overlay = document.getElementById('businessSidebarOverlay');
    const sidebarToggle = document.getElementById('businessSidebarToggle');

    const branchButton = document.getElementById('businessBranchButton');
    const branchMenu = document.getElementById('businessBranchMenu');

    const notificationButton = document.getElementById('businessNotificationButton');
    const notificationMenu = document.getElementById('businessNotificationMenu');

    const profileButton = document.getElementById('businessProfileButton');
    const profileMenu = document.getElementById('businessProfileMenu');

    const themeToggle = document.getElementById('businessThemeToggle');
    const themeIcon = document.getElementById('businessThemeIcon');

    function closeMenus(except = null) {
        if (branchMenu && except !== branchMenu) branchMenu.classList.add('hidden');
        if (notificationMenu && except !== notificationMenu) notificationMenu.classList.add('hidden');
        if (profileMenu && except !== profileMenu) profileMenu.classList.add('hidden');
    }

    sidebarToggle?.addEventListener('click', function () {
        sidebar?.classList.remove('-translate-x-full');
        overlay?.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    });

    overlay?.addEventListener('click', function () {
        sidebar?.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
    });

    branchButton?.addEventListener('click', function (e) {
        if (!branchMenu) return;
        e.stopPropagation();
        const isHidden = branchMenu.classList.contains('hidden');
        closeMenus(branchMenu);
        branchMenu.classList.toggle('hidden', !isHidden);
    });

    notificationButton?.addEventListener('click', function (e) {
        e.stopPropagation();
        const isHidden = notificationMenu?.classList.contains('hidden');
        closeMenus(notificationMenu);
        notificationMenu?.classList.toggle('hidden', !isHidden);
    });

    profileButton?.addEventListener('click', function (e) {
        e.stopPropagation();
        const isHidden = profileMenu?.classList.contains('hidden');
        closeMenus(profileMenu);
        profileMenu?.classList.toggle('hidden', !isHidden);
    });

    document.addEventListener('click', function () {
        closeMenus();
    });

    function applyTheme(theme) {
        const useDark =
            theme === 'dark' ||
            (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

        root.classList.toggle('dark', useDark);

        if (themeIcon) {
            themeIcon.setAttribute('data-lucide', useDark ? 'sun' : 'moon');
        }

        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    let currentTheme = localStorage.getItem('business_theme') || 'light';
    applyTheme(currentTheme);

    themeToggle?.addEventListener('click', function () {
        currentTheme = root.classList.contains('dark') ? 'light' : 'dark';
        localStorage.setItem('business_theme', currentTheme);
        applyTheme(currentTheme);
    });

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            document.getElementById('businessGlobalSearch')?.focus();
        }

        if (e.key === 'Escape') {
            closeMenus();

            if (window.innerWidth < 1024) {
                sidebar?.classList.add('-translate-x-full');
                overlay?.classList.add('hidden');
                document.body.style.overflow = '';
            }
        }
    });
})();
</script>
