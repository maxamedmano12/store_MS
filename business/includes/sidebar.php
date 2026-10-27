<?php

require_once __DIR__ . '/../../config/permissions.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$currentRole = (string)($_SESSION['role'] ?? ($role ?? 'User'));
$isBusinessAdmin = ($currentRole === 'Business Admin');

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('menuActive')) {
    function menuActive(array $pages): string
    {
        $currentPage = basename($_SERVER['PHP_SELF']);
        return in_array($currentPage, $pages, true) ? 'active' : '';
    }
}

?>

<aside
    id="businessSidebar"
    class="
        fixed inset-y-0 left-0 z-50
        flex w-[270px] -translate-x-full flex-col
        border-r border-slate-800
        bg-[#071527] text-slate-200
        transition-transform duration-300
        lg:translate-x-0
    "
>

    <div class="flex h-[74px] items-center gap-3 border-b border-white/10 px-5">
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-600/20">
            <i data-lucide="warehouse" class="h-5 w-5"></i>
        </div>

        <div class="min-w-0">
            <p class="truncate text-[13px] font-extrabold tracking-wide text-white">
                <?= e($businessName ?? 'Business') ?>
            </p>
            <p class="truncate text-[10px] text-slate-400">
                Management System
            </p>
        </div>
    </div>


    <div class="px-4 pt-4">
        <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-3">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-blue-300">
                    <i data-lucide="store" class="h-4 w-4"></i>
                </div>

                <div class="min-w-0">
                    <p class="truncate text-[11px] font-bold text-white">
                        <?= e($branchName ?? 'Main Branch') ?>
                    </p>
                    <p class="truncate text-[9px] text-slate-400">
                        <?= e($currentRole) ?>
                    </p>
                </div>
            </div>
        </div>
    </div>


    <nav class="business-sidebar-scroll mt-4 flex-1 overflow-y-auto px-3 pb-5">

        <!-- OVERVIEW -->
        <div class="sidebar-section">
            <p class="sidebar-section-title">
                <span>Overview</span>
            </p>

            <a href="dashboard.php" class="business-nav-link <?= menuActive(['dashboard.php']) ?>">
                <i data-lucide="layout-dashboard"></i>
                <span>Dashboard</span>
            </a>
        </div>


        <!-- INVENTORY -->
        <?php if (
            hasPermission('categories.view') ||
            hasPermission('units.view') ||
            hasPermission('products.view') ||
            hasPermission('stock.view') ||
            hasPermission('batches.view')
        ): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    <span>Inventory</span>
                </p>

                <?php if (hasPermission('categories.view')): ?>
                    <a href="categories.php" class="business-nav-link <?= menuActive(['categories.php']) ?>">
                        <i data-lucide="tags"></i>
                        <span>Categories</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('units.view')): ?>
                    <a href="units.php" class="business-nav-link <?= menuActive(['units.php']) ?>">
                        <i data-lucide="ruler"></i>
                        <span>Units</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('products.view')): ?>
                    <a href="products.php" class="business-nav-link <?= menuActive(['products.php']) ?>">
                        <i data-lucide="package"></i>
                        <span>Products</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('stock.view')): ?>
                    <a href="stock.php" class="business-nav-link <?= menuActive(['stock.php']) ?>">
                        <i data-lucide="boxes"></i>
                        <span>Stock</span>
                    </a>

                    <a href="stock-transfers.php" class="business-nav-link <?= menuActive(['stock-transfers.php']) ?>">
                        <i data-lucide="arrow-left-right"></i>
                        <span>Stock Transfers</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('batches.view')): ?>
                    <a href="batches.php" class="business-nav-link <?= menuActive(['batches.php']) ?>">
                        <i data-lucide="layers-3"></i>
                        <span>Batch & Expiry</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <!-- PURCHASES -->
        <?php if (
            hasPermission('purchases.view') ||
            hasPermission('purchase_returns.view') ||
            hasPermission('suppliers.view')
        ): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    <span>Purchases</span>
                </p>

                <?php if (hasPermission('purchases.view')): ?>
                    <a href="purchases.php" class="business-nav-link <?= menuActive(['purchases.php']) ?>">
                        <i data-lucide="shopping-cart"></i>
                        <span>Purchases</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('purchase_returns.view')): ?>
                    <a href="purchase-returns.php" class="business-nav-link <?= menuActive(['purchase-returns.php']) ?>">
                        <i data-lucide="undo-2"></i>
                        <span>Purchase Returns</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('suppliers.view')): ?>
                    <a href="suppliers.php" class="business-nav-link <?= menuActive(['suppliers.php']) ?>">
                        <i data-lucide="truck"></i>
                        <span>Suppliers</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <!-- SALES -->
        <?php if (
            hasPermission('sales.view') ||
            hasPermission('sales_returns.view') ||
            hasPermission('customers.view')
        ): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    <span>Sales</span>
                </p>

                <?php if (hasPermission('sales.view')): ?>
                    <a href="sales.php" class="business-nav-link <?= menuActive(['sales.php']) ?>">
                        <i data-lucide="badge-dollar-sign"></i>
                        <span>Sales</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('sales_returns.view')): ?>
                    <a href="sales-returns.php" class="business-nav-link <?= menuActive(['sales-returns.php']) ?>">
                        <i data-lucide="rotate-ccw"></i>
                        <span>Sales Returns</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('customers.view')): ?>
                    <a href="customers.php" class="business-nav-link <?= menuActive(['customers.php']) ?>">
                        <i data-lucide="users"></i>
                        <span>Customers</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <!-- FINANCE -->
        <?php if (
            hasPermission('expenses.view') ||
            hasPermission('payroll.view') ||
            ($isBusinessAdmin && hasPermission('assets.view')) ||
            ($isBusinessAdmin && hasPermission('depreciation.view')) ||
            hasPermission('accounting.view') ||
            hasPermission('cash_accounts.view') ||
            hasPermission('zakat.view')
        ): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    <span>Finance & Accounting</span>
                </p>

                <?php if (hasPermission('expenses.view')): ?>
                    <a href="expenses.php" class="business-nav-link <?= menuActive(['expenses.php']) ?>">
                        <i data-lucide="wallet-cards"></i>
                        <span>Expenses</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('payroll.view')): ?>
                    <a href="payroll.php" class="business-nav-link <?= menuActive(['payroll.php']) ?>">
                        <i data-lucide="banknote"></i>
                        <span>Payroll</span>
                    </a>
                <?php endif; ?>

                <?php if ($isBusinessAdmin && hasPermission('assets.view')): ?>
                    <a href="fixed-assets.php" class="business-nav-link <?= menuActive(['fixed-assets.php']) ?>">
                        <i data-lucide="building-2"></i>
                        <span>Fixed Assets</span>
                    </a>
                <?php endif; ?>

                <?php if ($isBusinessAdmin && hasPermission('depreciation.view')): ?>
                    <a href="depreciation.php" class="business-nav-link <?= menuActive(['depreciation.php']) ?>">
                        <i data-lucide="trending-down"></i>
                        <span>Depreciation</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('accounting.view')): ?>
                    <a href="accounting.php" class="business-nav-link <?= menuActive(['accounting.php']) ?>">
                        <i data-lucide="landmark"></i>
                        <span>Accounting</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('cash_accounts.view')): ?>
                    <a href="cash-accounts.php" class="business-nav-link <?= menuActive(['cash-accounts.php']) ?>">
                        <i data-lucide="credit-card"></i>
                        <span>zaad / edahap / cash</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('zakat.view')): ?>
                    <a href="zakat.php" class="business-nav-link <?= menuActive(['zakat.php']) ?>">
                        <i data-lucide="hand-coins"></i>
                        <span>Zakat</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <!-- REPORTS -->
        <?php if (
            hasPermission('reports.view') ||
            hasPermission('audit.view')
        ): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    <span>Reports & Monitoring</span>
                </p>

                <?php if (hasPermission('reports.view')): ?>
                    <a href="reports.php" class="business-nav-link <?= menuActive(['reports.php']) ?>">
                        <i data-lucide="bar-chart-3"></i>
                        <span>Reports</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('audit.view')): ?>
                    <a href="audit-trail.php" class="business-nav-link <?= menuActive(['audit-trail.php']) ?>">
                        <i data-lucide="history"></i>
                        <span>Audit Trail</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <!-- ADMINISTRATION -->
        <?php if (
            hasPermission('branches.view') ||
            $isBusinessAdmin
        ): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    <span>Administration</span>
                </p>

                <?php if (hasPermission('branches.view')): ?>
                    <a href="branches.php" class="business-nav-link <?= menuActive(['branches.php']) ?>">
                        <i data-lucide="git-branch"></i>
                        <span>Branches</span>
                    </a>
                <?php endif; ?>

                <?php if ($isBusinessAdmin): ?>
                    <a href="users.php" class="business-nav-link <?= menuActive(['users.php']) ?>">
                        <i data-lucide="users-round"></i>
                        <span>Users</span>
                    </a>

                    <a href="user-permissions.php" class="business-nav-link <?= menuActive(['user_permissions.php']) ?>">
                        <i data-lucide="shield-check"></i>
                        <span>User Permissions</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <!-- SUPPORT & SYSTEM -->
        <?php if (
            hasPermission('support.view') ||
            hasPermission('settings.view')
        ): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    <span>Support & System</span>
                </p>

                <?php if (hasPermission('support.view')): ?>
                    <a href="chat.php" class="business-nav-link <?= menuActive(['support.php', 'chat.php']) ?>">
                        <i data-lucide="messages-square"></i>
                        <span>Support / Chat</span>
                    </a>
                <?php endif; ?>

                <?php if (hasPermission('settings.view')): ?>
                    <a href="settings.php" class="business-nav-link <?= menuActive(['settings.php']) ?>">
                        <i data-lucide="settings"></i>
                        <span>Settings</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </nav>


    <div class="border-t border-white/10 p-3">
        <a
            href="profile.php"
            class="flex items-center gap-3 rounded-2xl p-3 transition hover:bg-white/[0.06]"
        >
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-[11px] font-extrabold text-white">
                <?= e($initials ?? 'U') ?>
            </div>

            <div class="min-w-0 flex-1">
                <p class="truncate text-[11px] font-bold text-white">
                    <?= e($fullName ?? 'User') ?>
                </p>
                <p class="truncate text-[9px] text-slate-400">
                    <?= e($currentRole) ?>
                </p>
            </div>

            <i data-lucide="chevron-right" class="h-4 w-4 text-slate-500"></i>
        </a>
    </div>

</aside>


<div
    id="businessSidebarOverlay"
    class="
        fixed inset-0 z-40 hidden
        bg-slate-950/60 backdrop-blur-sm
        lg:hidden
    "
></div>


<style>

.business-nav-link {
    display: flex;
    align-items: center;
    gap: .75rem;
    min-height: 42px;
    padding: .65rem .75rem;
    margin-bottom: .25rem;
    border-radius: .8rem;
    color: rgb(148 163 184);
    font-size: 11px;
    font-weight: 600;
    transition: all .2s ease;
}

.business-nav-link svg {
    width: 16px;
    height: 16px;
    flex: 0 0 auto;
}

.business-nav-link:hover {
    color: white;
    background: rgba(255,255,255,.06);
    transform: translateX(2px);
}

.business-nav-link.active {
    color: white;
    background: rgb(37 99 235);
    box-shadow: 0 10px 24px rgba(37,99,235,.22);
}


.sidebar-section {
    margin-bottom: 1.15rem;
}

.sidebar-section:last-child {
    margin-bottom: 0;
}

.sidebar-section-title {
    display: flex;
    align-items: center;
    gap: .55rem;
    padding: 0 .75rem .45rem;
    color: rgb(100 116 139);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .16em;
    text-transform: uppercase;
}

.sidebar-section-title::after {
    content: "";
    height: 1px;
    flex: 1;
    background: rgba(148, 163, 184, .14);
}

.business-sidebar-scroll {
    scrollbar-width: thin;
    scrollbar-color: rgba(148,163,184,.25) transparent;
}

.business-sidebar-scroll::-webkit-scrollbar {
    width: 5px;
}

.business-sidebar-scroll::-webkit-scrollbar-thumb {
    background: rgba(148,163,184,.25);
    border-radius: 999px;
}

</style>
