<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

requireSuperAdmin();
/*
|--------------------------------------------------------------------------
| SUPER ADMIN GUARD
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'Super Admin') {
    header('Location: ../dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        throw new Exception('Invalid request token. Refresh the page and try again.');
    }
}

function userInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }

    return $initials ?: 'U';
}

$message = '';
$messageType = '';

/*
|--------------------------------------------------------------------------
| LOAD ROLES
|--------------------------------------------------------------------------
*/
$roles = $conn->query("
    SELECT id, role_name
    FROM roles
    ORDER BY
        CASE role_name
            WHEN 'Super Admin' THEN 1
            WHEN 'Business Admin' THEN 2
            WHEN 'Manager' THEN 3
            WHEN 'Accountant' THEN 4
            WHEN 'Salesman' THEN 5
            WHEN 'Store Keeper' THEN 6
            ELSE 99
        END,
        role_name
")->fetchAll();

$roleMap = [];
foreach ($roles as $role) {
    $roleMap[(int)$role['id']] = $role['role_name'];
}

/*
|--------------------------------------------------------------------------
| ADD USER
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        verifyCsrf();

        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $businessId = (int)($_POST['business_id'] ?? 0);
        $branchId = (int)($_POST['branch_id'] ?? 0);
        $status = $_POST['status'] ?? 'Active';

        if ($fullName === '') {
            throw new Exception('Full Name is required.');
        }

        if ($username === '') {
            throw new Exception('Username is required.');
        }

        if (strlen($username) < 3) {
            throw new Exception('Username must be at least 3 characters.');
        }

        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters.');
        }

        if (!isset($roleMap[$roleId])) {
            throw new Exception('Please select a valid role.');
        }

        if (!in_array($status, ['Active', 'Inactive', 'Suspended'], true)) {
            throw new Exception('Invalid user status.');
        }

        $roleName = $roleMap[$roleId];

        if ($roleName === 'Super Admin') {
            $businessId = 0;
            $branchId = 0;
        } else {
            if ($businessId <= 0) {
                throw new Exception('Business is required for this role.');
            }

            $businessCheck = $conn->prepare("
                SELECT id, business_name, status
                FROM businesses
                WHERE id = ?
                LIMIT 1
            ");
            $businessCheck->execute([$businessId]);
            $business = $businessCheck->fetch();

            if (!$business) {
                throw new Exception('Selected business was not found.');
            }

            if ($business['status'] !== 'Active') {
                throw new Exception('The selected business is not Active.');
            }

            if ($branchId > 0) {
                $branchCheck = $conn->prepare("
                    SELECT id, branch_name, status
                    FROM branches
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");
                $branchCheck->execute([$branchId, $businessId]);
                $branch = $branchCheck->fetch();

                if (!$branch) {
                    throw new Exception('Selected branch does not belong to this business.');
                }

                if ($branch['status'] !== 'Active') {
                    throw new Exception('The selected branch is not Active.');
                }
            }
        }

        $duplicate = $conn->prepare("
            SELECT id
            FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $duplicate->execute([$username]);

        if ($duplicate->fetch()) {
            throw new Exception('Username already exists.');
        }

        $stmt = $conn->prepare("
            INSERT INTO users (
                business_id,
                branch_id,
                role_id,
                full_name,
                username,
                password,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $businessId > 0 ? $businessId : null,
            $branchId > 0 ? $branchId : null,
            $roleId,
            $fullName,
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $status
        ]);

        $message = 'User created successfully.';
        $messageType = 'success';

    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| UPDATE USER
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    try {
        verifyCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $businessId = (int)($_POST['business_id'] ?? 0);
        $branchId = (int)($_POST['branch_id'] ?? 0);
        $status = $_POST['status'] ?? 'Active';

        if ($userId <= 0) {
            throw new Exception('Invalid user.');
        }

        $currentStmt = $conn->prepare("
            SELECT
                u.id,
                u.role_id,
                r.role_name
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $currentStmt->execute([$userId]);
        $current = $currentStmt->fetch();

        if (!$current) {
            throw new Exception('User not found.');
        }

        if ($fullName === '' || $username === '') {
            throw new Exception('Full Name and Username are required.');
        }

        if (strlen($username) < 3) {
            throw new Exception('Username must be at least 3 characters.');
        }

        if ($password !== '' && strlen($password) < 6) {
            throw new Exception('New password must be at least 6 characters.');
        }

        if (!isset($roleMap[$roleId])) {
            throw new Exception('Please select a valid role.');
        }

        if (!in_array($status, ['Active', 'Inactive', 'Suspended'], true)) {
            throw new Exception('Invalid user status.');
        }

        $roleName = $roleMap[$roleId];

        /*
         * Protect the currently logged-in Super Admin from accidentally
         * removing their own access.
         */
        if ($userId === (int)$_SESSION['user_id']) {
            if ($roleName !== 'Super Admin') {
                throw new Exception('You cannot remove your own Super Admin role.');
            }

            if ($status !== 'Active') {
                throw new Exception('You cannot disable your own account.');
            }
        }

        if ($roleName === 'Super Admin') {
            $businessId = 0;
            $branchId = 0;
        } else {
            if ($businessId <= 0) {
                throw new Exception('Business is required for this role.');
            }

            $businessCheck = $conn->prepare("
                SELECT id, status
                FROM businesses
                WHERE id = ?
                LIMIT 1
            ");
            $businessCheck->execute([$businessId]);
            $business = $businessCheck->fetch();

            if (!$business) {
                throw new Exception('Selected business was not found.');
            }

            if ($branchId > 0) {
                $branchCheck = $conn->prepare("
                    SELECT id
                    FROM branches
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");
                $branchCheck->execute([$branchId, $businessId]);

                if (!$branchCheck->fetch()) {
                    throw new Exception('Selected branch does not belong to this business.');
                }
            }
        }

        $duplicate = $conn->prepare("
            SELECT id
            FROM users
            WHERE username = ? AND id <> ?
            LIMIT 1
        ");
        $duplicate->execute([$username, $userId]);

        if ($duplicate->fetch()) {
            throw new Exception('Username already exists.');
        }

        if ($password !== '') {
            $stmt = $conn->prepare("
                UPDATE users
                SET
                    business_id = ?,
                    branch_id = ?,
                    role_id = ?,
                    full_name = ?,
                    username = ?,
                    password = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $businessId > 0 ? $businessId : null,
                $branchId > 0 ? $branchId : null,
                $roleId,
                $fullName,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $status,
                $userId
            ]);
        } else {
            $stmt = $conn->prepare("
                UPDATE users
                SET
                    business_id = ?,
                    branch_id = ?,
                    role_id = ?,
                    full_name = ?,
                    username = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $businessId > 0 ? $businessId : null,
                $branchId > 0 ? $branchId : null,
                $roleId,
                $fullName,
                $username,
                $status,
                $userId
            ]);
        }

        if ($userId === (int)$_SESSION['user_id']) {
            $_SESSION['full_name'] = $fullName;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $roleName;
        }

        $message = 'User updated successfully.';
        $messageType = 'success';

    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| CHANGE STATUS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    try {
        verifyCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        $status = $_POST['new_status'] ?? '';

        if ($userId <= 0) {
            throw new Exception('Invalid user.');
        }

        if (!in_array($status, ['Active', 'Inactive', 'Suspended'], true)) {
            throw new Exception('Invalid status.');
        }

        if ($userId === (int)$_SESSION['user_id']) {
            throw new Exception('You cannot change the status of your own account.');
        }

        $stmt = $conn->prepare("
            UPDATE users
            SET status = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $userId]);

        $message = 'User status updated.';
        $messageType = 'success';

    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| BUSINESSES + BRANCHES
|--------------------------------------------------------------------------
*/
$businesses = $conn->query("
    SELECT id, business_name, status
    FROM businesses
    ORDER BY business_name
")->fetchAll();

$branches = $conn->query("
    SELECT id, business_id, branch_name, status
    FROM branches
    ORDER BY branch_name
")->fetchAll();

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$businessFilter = (int)($_GET['business_id'] ?? 0);
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$roleFilter = (int)($_GET['role_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        u.full_name LIKE ?
        OR u.username LIKE ?
        OR COALESCE(b.business_name, '') LIKE ?
        OR COALESCE(br.branch_name, '') LIKE ?
        OR r.role_name LIKE ?
    )";

    $term = '%' . $search . '%';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $term;
    }
}

if ($businessFilter > 0) {
    $where[] = "u.business_id = ?";
    $params[] = $businessFilter;
}

if ($branchFilter > 0) {
    $where[] = "u.branch_id = ?";
    $params[] = $branchFilter;
}

if ($roleFilter > 0) {
    $where[] = "u.role_id = ?";
    $params[] = $roleFilter;
}

if (in_array($statusFilter, ['Active', 'Inactive', 'Suspended'], true)) {
    $where[] = "u.status = ?";
    $params[] = $statusFilter;
}

$sql = "
    SELECT
        u.id,
        u.business_id,
        u.branch_id,
        u.role_id,
        u.full_name,
        u.username,
        u.status,
        u.created_at,
        r.role_name,
        b.business_name,
        b.status AS business_status,
        br.branch_name,
        br.status AS branch_status
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN businesses b ON b.id = u.business_id
    LEFT JOIN branches br ON br.id = u.branch_id
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
    ORDER BY
        CASE r.role_name
            WHEN 'Super Admin' THEN 1
            WHEN 'Business Admin' THEN 2
            ELSE 3
        END,
        u.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| KPI
|--------------------------------------------------------------------------
*/
$totalUsers = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = (int)$conn->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();
$inactiveUsers = (int)$conn->query("
    SELECT COUNT(*)
    FROM users
    WHERE status IN ('Inactive', 'Suspended')
")->fetchColumn();

$businessAdminCount = (int)$conn->query("
    SELECT COUNT(*)
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'Business Admin'
")->fetchColumn();

$currentUserId = (int)$_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Users Management</title>

<link rel="icon" type="image/png" href="../assets/images/favicon.png">

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
<link rel="stylesheet" href="../assets/css/app.css">

<style>
.picker-menu {
    max-height: 260px;
    overflow-y: auto;
}
</style>
</head>

<body class="bg-[#f7f9fc] text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-100">

<?php require_once 'includes/sidebar.php'; ?>

<div class="min-h-screen lg:ml-[270px]">

<?php require_once 'includes/header.php'; ?>

<main class="page-enter px-4 py-6 md:px-7 md:py-7">

    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">

        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] text-slate-400">
                <a href="dashboard.php" class="hover:text-blue-600">Dashboard</a>
                <i data-lucide="chevron-right" class="h-3 w-3"></i>
                <span>Users</span>
            </div>

            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                Users Management
            </h1>

            <p class="mt-1 text-[12px] text-slate-500">
                Create users and connect each account to its Business, Branch and Role.
            </p>
        </div>


        <button
            type="button"
            onclick="openAddUserModal()"
            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-xs font-bold text-white shadow-lg shadow-blue-600/20 transition hover:-translate-y-0.5 hover:bg-blue-700"
        >
            <i data-lucide="user-plus" class="h-4 w-4"></i>
            Add User
        </button>

    </div>


    <?php if ($message !== ''): ?>

        <div
            id="alertMessage"
            class="mb-5 flex items-start justify-between gap-3 rounded-xl border p-4
            <?= $messageType === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-red-200 bg-red-50 text-red-700' ?>"
        >
            <div class="flex items-start gap-3">
                <i
                    data-lucide="<?= $messageType === 'success' ? 'circle-check' : 'circle-alert' ?>"
                    class="mt-0.5 h-5 w-5"
                ></i>

                <div>
                    <p class="text-xs font-extrabold">
                        <?= $messageType === 'success' ? 'Success' : 'Error' ?>
                    </p>

                    <p class="mt-1 text-[11px]">
                        <?= e($message) ?>
                    </p>
                </div>
            </div>

            <button
                type="button"
                onclick="document.getElementById('alertMessage').remove()"
            >
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>

    <?php endif; ?>


    <!-- KPI -->
    <div class="mb-6 grid grid-cols-2 gap-3 xl:grid-cols-4">

        <div class="dashboard-card rounded-2xl border border-slate-200 bg-white p-4 shadow-soft dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-semibold uppercase text-slate-400">Total Users</p>
                    <h3 class="mt-1 text-2xl font-extrabold"><?= $totalUsers ?></h3>
                </div>

                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                    <i data-lucide="users" class="h-5 w-5"></i>
                </div>
            </div>
        </div>


        <div class="dashboard-card rounded-2xl border border-slate-200 bg-white p-4 shadow-soft dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-semibold uppercase text-slate-400">Active</p>
                    <h3 class="mt-1 text-2xl font-extrabold text-emerald-600"><?= $activeUsers ?></h3>
                </div>

                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <i data-lucide="user-check" class="h-5 w-5"></i>
                </div>
            </div>
        </div>


        <div class="dashboard-card rounded-2xl border border-slate-200 bg-white p-4 shadow-soft dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-semibold uppercase text-slate-400">Inactive / Suspended</p>
                    <h3 class="mt-1 text-2xl font-extrabold text-red-600"><?= $inactiveUsers ?></h3>
                </div>

                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-50 text-red-600">
                    <i data-lucide="user-x" class="h-5 w-5"></i>
                </div>
            </div>
        </div>


        <div class="dashboard-card rounded-2xl border border-slate-200 bg-white p-4 shadow-soft dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-semibold uppercase text-slate-400">Business Admins</p>
                    <h3 class="mt-1 text-2xl font-extrabold text-violet-600"><?= $businessAdminCount ?></h3>
                </div>

                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                    <i data-lucide="shield-check" class="h-5 w-5"></i>
                </div>
            </div>
        </div>

    </div>


    <!-- FILTERS -->
    <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-soft dark:border-slate-800 dark:bg-slate-900">

        <form method="GET" class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">

            <div class="relative xl:col-span-2">

                <i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>

                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search name, username, business..."
                    class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-xs outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-50 dark:border-slate-700 dark:bg-slate-800"
                >

            </div>


            <select
                name="business_id"
                id="filterBusiness"
                class="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800"
            >
                <option value="">All Businesses</option>

                <?php foreach ($businesses as $business): ?>
                    <option
                        value="<?= $business['id'] ?>"
                        <?= $businessFilter === (int)$business['id'] ? 'selected' : '' ?>
                    >
                        <?= e($business['business_name']) ?>
                    </option>
                <?php endforeach; ?>

            </select>


            <select
                name="branch_id"
                id="filterBranch"
                class="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800"
            >
                <option value="">All Branches</option>

                <?php foreach ($branches as $branch): ?>
                    <option
                        value="<?= $branch['id'] ?>"
                        data-business-id="<?= $branch['business_id'] ?>"
                        <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>
                    >
                        <?= e($branch['branch_name']) ?>
                    </option>
                <?php endforeach; ?>

            </select>


            <select
                name="role_id"
                class="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800"
            >
                <option value="">All Roles</option>

                <?php foreach ($roles as $role): ?>
                    <option
                        value="<?= $role['id'] ?>"
                        <?= $roleFilter === (int)$role['id'] ? 'selected' : '' ?>
                    >
                        <?= e($role['role_name']) ?>
                    </option>
                <?php endforeach; ?>

            </select>


            <div class="flex gap-2">

                <select
                    name="status"
                    class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800"
                >
                    <option value="">All Status</option>
                    <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="Suspended" <?= $statusFilter === 'Suspended' ? 'selected' : '' ?>>Suspended</option>
                </select>

                <button
                    class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white hover:bg-blue-600 dark:bg-blue-600"
                    title="Apply Filters"
                >
                    <i data-lucide="sliders-horizontal" class="h-4 w-4"></i>
                </button>

            </div>

        </form>


        <?php if ($search !== '' || $businessFilter || $branchFilter || $roleFilter || $statusFilter !== ''): ?>

            <div class="mt-3 flex justify-end">
                <a
                    href="users.php"
                    class="inline-flex items-center gap-1 text-[10px] font-bold text-red-500 hover:text-red-600"
                >
                    <i data-lucide="x" class="h-3 w-3"></i>
                    Clear filters
                </a>
            </div>

        <?php endif; ?>

    </section>


    <!-- USERS TABLE -->
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft dark:border-slate-800 dark:bg-slate-900">

        <div class="flex items-center justify-between border-b border-slate-100 p-4 dark:border-slate-800">

            <div>
                <h2 class="text-sm font-extrabold">Users</h2>
                <p class="mt-1 text-[10px] text-slate-400">
                    <?= count($users) ?> records shown
                </p>
            </div>

            <div class="rounded-lg bg-slate-100 px-3 py-2 text-[9px] font-bold text-slate-500 dark:bg-slate-800">
                Business → Branch → User → Role
            </div>

        </div>


        <?php if (!$users): ?>

            <div class="p-12 text-center">

                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800">
                    <i data-lucide="users" class="h-6 w-6"></i>
                </div>

                <h3 class="mt-4 text-sm font-extrabold">
                    No users found
                </h3>

                <p class="mt-2 text-[11px] text-slate-500">
                    Add a user or change your search filters.
                </p>

            </div>

        <?php else: ?>

            <div class="overflow-x-auto">

                <table class="min-w-[1050px] w-full">

                    <thead class="bg-slate-50 text-left dark:bg-slate-800/50">

                        <tr class="text-[9px] font-bold uppercase tracking-wider text-slate-400">
                            <th class="px-4 py-3">User</th>
                            <th class="px-4 py-3">Business</th>
                            <th class="px-4 py-3">Branch</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>

                    </thead>


                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">

                        <?php foreach ($users as $user): ?>

                            <?php
                            $isSuperAdmin = $user['role_name'] === 'Super Admin';
                            $isCurrent = (int)$user['id'] === $currentUserId;
                            ?>

                            <tr class="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30">

                                <td class="px-4 py-4">

                                    <div class="flex items-center gap-3">

                                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl
                                            <?= $isSuperAdmin
                                                ? 'bg-amber-50 text-amber-600'
                                                : 'bg-blue-50 text-blue-600' ?>
                                            text-[10px] font-extrabold"
                                        >
                                            <?= e(userInitials($user['full_name'])) ?>
                                        </div>


                                        <div class="min-w-0">

                                            <div class="flex items-center gap-2">

                                                <p class="max-w-[180px] truncate text-xs font-extrabold text-slate-800 dark:text-slate-100">
                                                    <?= e($user['full_name']) ?>
                                                </p>

                                                <?php if ($isCurrent): ?>
                                                    <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[7px] font-extrabold uppercase text-blue-600">
                                                        You
                                                    </span>
                                                <?php endif; ?>

                                            </div>

                                            <p class="mt-1 text-[9px] text-slate-400">
                                                @<?= e($user['username']) ?>
                                            </p>

                                        </div>

                                    </div>

                                </td>


                                <td class="px-4 py-4">

                                    <?php if ($isSuperAdmin): ?>

                                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[9px] font-bold text-amber-700">
                                            <i data-lucide="building-2" class="h-3 w-3"></i>
                                            All Businesses
                                        </span>

                                    <?php else: ?>

                                        <p class="text-[10px] font-bold">
                                            <?= e($user['business_name'] ?? '—') ?>
                                        </p>

                                        <?php if (($user['business_status'] ?? '') !== 'Active'): ?>
                                            <p class="mt-1 text-[8px] font-bold text-red-500">
                                                <?= e($user['business_status'] ?? '') ?>
                                            </p>
                                        <?php endif; ?>

                                    <?php endif; ?>

                                </td>


                                <td class="px-4 py-4">

                                    <?php if ($isSuperAdmin): ?>

                                        <span class="text-[10px] text-slate-400">System Level</span>

                                    <?php elseif ($user['branch_id']): ?>

                                        <p class="text-[10px] font-semibold">
                                            <?= e($user['branch_name'] ?? '—') ?>
                                        </p>

                                    <?php else: ?>

                                        <span class="rounded-lg bg-violet-50 px-2 py-1 text-[8px] font-bold text-violet-600">
                                            All Branches
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td class="px-4 py-4">

                                    <span class="inline-flex rounded-lg px-2.5 py-1.5 text-[9px] font-extrabold
                                        <?= $isSuperAdmin
                                            ? 'bg-amber-50 text-amber-700'
                                            : ($user['role_name'] === 'Business Admin'
                                                ? 'bg-violet-50 text-violet-700'
                                                : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300') ?>"
                                    >
                                        <?= e($user['role_name']) ?>
                                    </span>

                                </td>


                                <td class="px-4 py-4">

                                    <?php
                                    $statusClass = match ($user['status']) {
                                        'Active' => 'bg-emerald-50 text-emerald-700',
                                        'Suspended' => 'bg-red-50 text-red-700',
                                        default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                                    };
                                    ?>

                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[8px] font-extrabold <?= $statusClass ?>">
                                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                        <?= e($user['status']) ?>
                                    </span>

                                </td>


                                <td class="px-4 py-4 text-[9px] text-slate-500">
                                    <?= date('d M Y', strtotime($user['created_at'])) ?>
                                </td>


                                <td class="px-4 py-4">

                                    <div class="flex items-center justify-end gap-1">

                                        <?php if (!$isSuperAdmin): ?>

                                            <a
                                                href="user-permissions.php?id=<?= $user['id'] ?>"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-violet-600 transition hover:border-violet-300 hover:bg-violet-50 dark:border-slate-700"
                                                title="Manage Permissions"
                                            >
                                                <i data-lucide="key-round" class="h-3.5 w-3.5"></i>
                                            </a>

                                        <?php endif; ?>


                                        <button
                                            type="button"
                                            onclick='openEditUserModal(<?= json_encode([
                                                "id" => (int)$user["id"],
                                                "business_id" => $user["business_id"] ? (int)$user["business_id"] : 0,
                                                "branch_id" => $user["branch_id"] ? (int)$user["branch_id"] : 0,
                                                "role_id" => (int)$user["role_id"],
                                                "full_name" => $user["full_name"],
                                                "username" => $user["username"],
                                                "status" => $user["status"],
                                                "role_name" => $user["role_name"]
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-blue-600 transition hover:border-blue-300 hover:bg-blue-50 dark:border-slate-700"
                                            title="Edit User"
                                        >
                                            <i data-lucide="pencil" class="h-3.5 w-3.5"></i>
                                        </button>


                                        <?php if (!$isCurrent): ?>

                                            <button
                                                type="button"
                                                onclick="openStatusModal(<?= (int)$user['id'] ?>, '<?= e($user['full_name']) ?>', '<?= e($user['status']) ?>')"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700"
                                                title="Change Status"
                                            >
                                                <i data-lucide="power" class="h-3.5 w-3.5"></i>
                                            </button>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php require_once 'includes/footer.php'; ?>

</div>


<!-- ADD USER MODAL -->
<div
    id="addUserModal"
    class="fixed inset-0 z-[100] hidden items-center justify-center p-4"
>

    <div
        class="absolute inset-0 bg-slate-950/55 backdrop-blur-[2px]"
        onclick="closeAddUserModal()"
    ></div>


    <div class="relative z-10 max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-3xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">

        <form method="POST">

            <input type="hidden" name="add_user" value="1">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">


            <div class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-100 bg-white/95 p-5 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">

                <div>
                    <p class="text-[9px] font-extrabold uppercase tracking-wider text-blue-600">
                        New Account
                    </p>

                    <h2 class="mt-1 text-lg font-extrabold">
                        Add User
                    </h2>
                </div>


                <button
                    type="button"
                    onclick="closeAddUserModal()"
                    class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-slate-500 hover:bg-red-50 hover:text-red-500 dark:bg-slate-800"
                >
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>

            </div>


            <div class="space-y-5 p-5">

                <div class="grid gap-4 sm:grid-cols-2">

                    <div>
                        <label class="mb-2 block text-[10px] font-bold text-slate-600 dark:text-slate-300">
                            Full Name *
                        </label>

                        <input
                            name="full_name"
                            required
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-50 dark:border-slate-700 dark:bg-slate-800"
                            placeholder="e.g. Mohamed Ali"
                        >
                    </div>


                    <div>
                        <label class="mb-2 block text-[10px] font-bold text-slate-600 dark:text-slate-300">
                            Username *
                        </label>

                        <input
                            name="username"
                            required
                            autocomplete="off"
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-50 dark:border-slate-700 dark:bg-slate-800"
                            placeholder="e.g. mohamed"
                        >
                    </div>


                    <div>
                        <label class="mb-2 block text-[10px] font-bold text-slate-600 dark:text-slate-300">
                            Password *
                        </label>

                        <input
                            type="password"
                            name="password"
                            minlength="6"
                            required
                            autocomplete="new-password"
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-50 dark:border-slate-700 dark:bg-slate-800"
                            placeholder="Minimum 6 characters"
                        >
                    </div>


                    <div>
                        <label class="mb-2 block text-[10px] font-bold text-slate-600 dark:text-slate-300">
                            Role *
                        </label>

                        <select
                            id="addRole"
                            name="role_id"
                            required
                            onchange="handleAddRoleChange()"
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800"
                        >
                            <option value="">Select Role</option>

                            <?php foreach ($roles as $role): ?>

                                <option
                                    value="<?= $role['id'] ?>"
                                    data-role-name="<?= e($role['role_name']) ?>"
                                >
                                    <?= e($role['role_name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                </div>


                <div
                    id="addTenantSection"
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/40"
                >

                    <div class="mb-4">
                        <h3 class="text-xs font-extrabold">
                            Business Access
                        </h3>

                        <p class="mt-1 text-[9px] text-slate-500">
                            Business is required. Branch is optional; empty branch means all branches within that business.
                        </p>
                    </div>


                    <div class="grid gap-4 sm:grid-cols-2">

                        <div>
                            <label class="mb-2 block text-[10px] font-bold">
                                Business *
                            </label>

                            <div class="relative">

                                <input
                                    type="hidden"
                                    id="addBusinessId"
                                    name="business_id"
                                >

                                <button
                                    id="addBusinessButton"
                                    type="button"
                                    onclick="togglePicker('addBusinessPicker')"
                                    class="flex h-11 w-full items-center justify-between rounded-xl border border-slate-200 bg-white px-3 text-left text-xs dark:border-slate-700 dark:bg-slate-900"
                                >
                                    <span id="addBusinessLabel" class="truncate text-slate-400">
                                        Select Business
                                    </span>

                                    <i data-lucide="chevrons-up-down" class="h-4 w-4 text-slate-400"></i>
                                </button>


                                <div
                                    id="addBusinessPicker"
                                    class="absolute left-0 right-0 top-[48px] z-[999] hidden rounded-xl border border-slate-200 bg-white p-2 shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                                >

                                    <div class="relative mb-2">
                                        <i data-lucide="search" class="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"></i>

                                        <input
                                            type="text"
                                            id="addBusinessSearch"
                                            oninput="filterPickerOptions('addBusinessPicker', this.value)"
                                            placeholder="Search business..."
                                            class="h-9 w-full rounded-lg border border-slate-200 bg-slate-50 pl-8 pr-3 text-[10px] outline-none dark:border-slate-700 dark:bg-slate-800"
                                        >
                                    </div>


                                    <div class="picker-menu space-y-1">

                                        <?php foreach ($businesses as $business): ?>

                                            <button
                                                type="button"
                                                class="picker-option flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-left text-[10px] hover:bg-blue-50 dark:hover:bg-slate-800"
                                                data-search="<?= e(strtolower($business['business_name'] . ' ' . $business['status'])) ?>"
                                                onclick='selectAddBusiness(
                                                    <?= (int)$business["id"] ?>,
                                                    <?= json_encode($business["business_name"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                                                )'
                                            >
                                                <span class="font-bold"><?= e($business['business_name']) ?></span>

                                                <span class="text-[8px] <?= $business['status'] === 'Active' ? 'text-emerald-600' : 'text-red-500' ?>">
                                                    <?= e($business['status']) ?>
                                                </span>
                                            </button>

                                        <?php endforeach; ?>

                                    </div>

                                </div>

                            </div>

                        </div>


                        <div>
                            <label class="mb-2 block text-[10px] font-bold">
                                Branch
                            </label>

                            <div class="relative">

                                <input
                                    type="hidden"
                                    id="addBranchId"
                                    name="branch_id"
                                >

                                <button
                                    id="addBranchButton"
                                    type="button"
                                    onclick="togglePicker('addBranchPicker')"
                                    class="flex h-11 w-full items-center justify-between rounded-xl border border-slate-200 bg-white px-3 text-left text-xs dark:border-slate-700 dark:bg-slate-900"
                                >
                                    <span id="addBranchLabel" class="truncate text-slate-400">
                                        All Branches / Optional
                                    </span>

                                    <i data-lucide="chevrons-up-down" class="h-4 w-4 text-slate-400"></i>
                                </button>


                                <div
                                    id="addBranchPicker"
                                    class="absolute left-0 right-0 top-[48px] z-[999] hidden rounded-xl border border-slate-200 bg-white p-2 shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                                >

                                    <div class="relative mb-2">
                                        <i data-lucide="search" class="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"></i>

                                        <input
                                            type="text"
                                            oninput="filterPickerOptions('addBranchPicker', this.value)"
                                            placeholder="Search branch..."
                                            class="h-9 w-full rounded-lg border border-slate-200 bg-slate-50 pl-8 pr-3 text-[10px] outline-none dark:border-slate-700 dark:bg-slate-800"
                                        >
                                    </div>


                                    <div class="picker-menu space-y-1">

                                        <button
                                            type="button"
                                            class="picker-option add-branch-option w-full rounded-lg px-3 py-2.5 text-left text-[10px] font-bold text-violet-600 hover:bg-violet-50"
                                            data-search="all branches"
                                            data-business-id="0"
                                            onclick="selectAddBranch(0, 'All Branches / Optional')"
                                        >
                                            All Branches / Optional
                                        </button>


                                        <?php foreach ($branches as $branch): ?>

                                            <button
                                                type="button"
                                                class="picker-option add-branch-option hidden w-full rounded-lg px-3 py-2.5 text-left text-[10px] hover:bg-blue-50 dark:hover:bg-slate-800"
                                                data-search="<?= e(strtolower($branch['branch_name'] . ' ' . $branch['status'])) ?>"
                                                data-business-id="<?= $branch['business_id'] ?>"
                                                onclick='selectAddBranch(
                                                    <?= (int)$branch["id"] ?>,
                                                    <?= json_encode($branch["branch_name"], JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                                                )'
                                            >
                                                <div class="flex items-center justify-between">
                                                    <span class="font-bold"><?= e($branch['branch_name']) ?></span>

                                                    <span class="text-[8px] <?= $branch['status'] === 'Active' ? 'text-emerald-600' : 'text-red-500' ?>">
                                                        <?= e($branch['status']) ?>
                                                    </span>
                                                </div>
                                            </button>

                                        <?php endforeach; ?>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <div>
                    <label class="mb-2 block text-[10px] font-bold">
                        Account Status
                    </label>

                    <select
                        name="status"
                        class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800"
                    >
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Suspended">Suspended</option>
                    </select>
                </div>

            </div>


            <div class="sticky bottom-0 flex justify-end gap-2 border-t border-slate-100 bg-white/95 p-5 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">

                <button
                    type="button"
                    onclick="closeAddUserModal()"
                    class="h-10 rounded-xl border border-slate-200 px-4 text-[10px] font-bold dark:border-slate-700"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    onclick="return validateAddUserForm()"
                    class="flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-5 text-[10px] font-bold text-white hover:bg-blue-700"
                >
                    <i data-lucide="user-plus" class="h-3.5 w-3.5"></i>
                    Create User
                </button>

            </div>

        </form>

    </div>

</div>


<!-- EDIT USER MODAL -->
<div
    id="editUserModal"
    class="fixed inset-0 z-[100] hidden items-center justify-center p-4"
>

    <div
        class="absolute inset-0 bg-slate-950/55 backdrop-blur-[2px]"
        onclick="closeEditUserModal()"
    ></div>


    <div class="relative z-10 max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-3xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">

        <form method="POST">

            <input type="hidden" name="update_user" value="1">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="user_id" id="editUserId">


            <div class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-100 bg-white/95 p-5 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">

                <div>
                    <p class="text-[9px] font-extrabold uppercase tracking-wider text-blue-600">
                        Account Settings
                    </p>

                    <h2 class="mt-1 text-lg font-extrabold">
                        Edit User
                    </h2>
                </div>


                <button
                    type="button"
                    onclick="closeEditUserModal()"
                    class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-slate-500 hover:bg-red-50 hover:text-red-500 dark:bg-slate-800"
                >
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>

            </div>


            <div class="space-y-5 p-5">

                <div class="grid gap-4 sm:grid-cols-2">

                    <div>
                        <label class="mb-2 block text-[10px] font-bold">Full Name *</label>

                        <input
                            id="editFullName"
                            name="full_name"
                            required
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-50 dark:border-slate-700 dark:bg-slate-800"
                        >
                    </div>


                    <div>
                        <label class="mb-2 block text-[10px] font-bold">Username *</label>

                        <input
                            id="editUsername"
                            name="username"
                            required
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-50 dark:border-slate-700 dark:bg-slate-800"
                        >
                    </div>


                    <div>
                        <label class="mb-2 block text-[10px] font-bold">
                            New Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            minlength="6"
                            autocomplete="new-password"
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-50 dark:border-slate-700 dark:bg-slate-800"
                            placeholder="Leave empty to keep current password"
                        >
                    </div>


                    <div>
                        <label class="mb-2 block text-[10px] font-bold">Role *</label>

                        <select
                            id="editRole"
                            name="role_id"
                            required
                            onchange="handleEditRoleChange()"
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800"
                        >
                            <?php foreach ($roles as $role): ?>

                                <option
                                    value="<?= $role['id'] ?>"
                                    data-role-name="<?= e($role['role_name']) ?>"
                                >
                                    <?= e($role['role_name']) ?>
                                </option>

                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>


                <div
                    id="editTenantSection"
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/40"
                >

                    <div class="mb-4">
                        <h3 class="text-xs font-extrabold">Business Access</h3>

                        <p class="mt-1 text-[9px] text-slate-500">
                            Branch empty = access can apply across the selected business.
                        </p>
                    </div>


                    <div class="grid gap-4 sm:grid-cols-2">

                        <div>
                            <label class="mb-2 block text-[10px] font-bold">Business *</label>

                            <select
                                id="editBusiness"
                                name="business_id"
                                onchange="refreshEditBranches()"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-900"
                            >
                                <option value="">Select Business</option>

                                <?php foreach ($businesses as $business): ?>
                                    <option value="<?= $business['id'] ?>">
                                        <?= e($business['business_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>


                        <div>
                            <label class="mb-2 block text-[10px] font-bold">Branch</label>

                            <select
                                id="editBranch"
                                name="branch_id"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-900"
                            >
                                <option value="">All Branches / Optional</option>

                                <?php foreach ($branches as $branch): ?>
                                    <option
                                        value="<?= $branch['id'] ?>"
                                        data-business-id="<?= $branch['business_id'] ?>"
                                    >
                                        <?= e($branch['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                </div>


                <div>
                    <label class="mb-2 block text-[10px] font-bold">Account Status</label>

                    <select
                        id="editStatus"
                        name="status"
                        class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800"
                    >
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Suspended">Suspended</option>
                    </select>
                </div>

            </div>


            <div class="sticky bottom-0 flex justify-end gap-2 border-t border-slate-100 bg-white/95 p-5 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">

                <button
                    type="button"
                    onclick="closeEditUserModal()"
                    class="h-10 rounded-xl border border-slate-200 px-4 text-[10px] font-bold dark:border-slate-700"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-5 text-[10px] font-bold text-white hover:bg-blue-700"
                >
                    <i data-lucide="save" class="h-3.5 w-3.5"></i>
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>


<!-- STATUS MODAL -->
<div
    id="statusModal"
    class="fixed inset-0 z-[110] hidden items-center justify-center p-4"
>

    <div
        class="absolute inset-0 bg-slate-950/55 backdrop-blur-[2px]"
        onclick="closeStatusModal()"
    ></div>


    <div class="relative z-10 w-full max-w-sm rounded-3xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-slate-700 dark:bg-slate-900">

        <form method="POST">

            <input type="hidden" name="change_status" value="1">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="user_id" id="statusUserId">


            <div class="flex items-center justify-between">

                <div>
                    <p class="text-[9px] font-bold uppercase text-slate-400">
                        Account Control
                    </p>

                    <h3 class="mt-1 text-base font-extrabold">
                        Change User Status
                    </h3>
                </div>


                <button
                    type="button"
                    onclick="closeStatusModal()"
                    class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800"
                >
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>

            </div>


            <p class="mt-4 text-[10px] leading-5 text-slate-500">
                User:
                <strong id="statusUserName" class="text-slate-800 dark:text-white"></strong>
            </p>


            <select
                id="statusNewValue"
                name="new_status"
                class="mt-4 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs outline-none dark:border-slate-700 dark:bg-slate-800"
            >
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
                <option value="Suspended">Suspended</option>
            </select>


            <div class="mt-5 flex justify-end gap-2">

                <button
                    type="button"
                    onclick="closeStatusModal()"
                    class="h-10 rounded-xl border border-slate-200 px-4 text-[10px] font-bold dark:border-slate-700"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="h-10 rounded-xl bg-slate-900 px-4 text-[10px] font-bold text-white hover:bg-blue-600"
                >
                    Update Status
                </button>

            </div>

        </form>

    </div>

</div>


<script src="../assets/js/app.js"></script>

<script>
const branchesData = <?= json_encode(array_map(function ($branch) {
    return [
        'id' => (int)$branch['id'],
        'business_id' => (int)$branch['business_id'],
        'branch_name' => $branch['branch_name'],
        'status' => $branch['status']
    ];
}, $branches), JSON_UNESCAPED_UNICODE) ?>;

function showModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function hideModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;

    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}

function openAddUserModal() {
    showModal('addUserModal');

    setTimeout(function () {
        const firstInput = document.querySelector('#addUserModal input[name="full_name"]');
        if (firstInput) firstInput.focus();
    }, 100);
}

function closeAddUserModal() {
    hideModal('addUserModal');
    closeAllPickers();
}

function closeEditUserModal() {
    hideModal('editUserModal');
}

function openStatusModal(userId, userName, status) {
    document.getElementById('statusUserId').value = userId;
    document.getElementById('statusUserName').textContent = userName;
    document.getElementById('statusNewValue').value = status;

    showModal('statusModal');
}

function closeStatusModal() {
    hideModal('statusModal');
}

function getSelectedRoleName(selectId) {
    const select = document.getElementById(selectId);
    const option = select.options[select.selectedIndex];

    return option ? option.dataset.roleName || '' : '';
}

function handleAddRoleChange() {
    const roleName = getSelectedRoleName('addRole');
    const tenantSection = document.getElementById('addTenantSection');
    const businessId = document.getElementById('addBusinessId');
    const branchId = document.getElementById('addBranchId');

    if (roleName === 'Super Admin') {
        tenantSection.classList.add('hidden');
        businessId.value = '';
        branchId.value = '';
    } else {
        tenantSection.classList.remove('hidden');
    }
}

function handleEditRoleChange() {
    const roleName = getSelectedRoleName('editRole');
    const tenantSection = document.getElementById('editTenantSection');

    if (roleName === 'Super Admin') {
        tenantSection.classList.add('hidden');
    } else {
        tenantSection.classList.remove('hidden');
    }
}

function togglePicker(id) {
    const picker = document.getElementById(id);
    if (!picker) return;

    const isHidden = picker.classList.contains('hidden');

    closeAllPickers();

    if (isHidden) {
        picker.classList.remove('hidden');

        const search = picker.querySelector('input[type="text"]');
        if (search) {
            setTimeout(function () {
                search.focus();
            }, 30);
        }
    }
}

function closeAllPickers() {
    document
        .querySelectorAll('[id$="Picker"]')
        .forEach(function (picker) {
            picker.classList.add('hidden');
        });
}

function filterPickerOptions(pickerId, value) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;

    const term = value.trim().toLowerCase();

    picker
        .querySelectorAll('.picker-option')
        .forEach(function (option) {
            /*
             * Preserve business filtering for Add Branch options.
             */
            if (pickerId === 'addBranchPicker') {
                const selectedBusinessId =
                    Number(document.getElementById('addBusinessId').value || 0);

                const optionBusinessId =
                    Number(option.dataset.businessId || 0);

                if (
                    optionBusinessId !== 0 &&
                    optionBusinessId !== selectedBusinessId
                ) {
                    option.classList.add('hidden');
                    return;
                }
            }

            const text = (option.dataset.search || '').toLowerCase();

            option.classList.toggle(
                'hidden',
                term !== '' && !text.includes(term)
            );
        });
}

function selectAddBusiness(id, name) {
    document.getElementById('addBusinessId').value = id;

    const label = document.getElementById('addBusinessLabel');
    label.textContent = name;
    label.classList.remove('text-slate-400');

    selectAddBranch(0, 'All Branches / Optional');

    document
        .querySelectorAll('.add-branch-option')
        .forEach(function (option) {
            const optionBusinessId =
                Number(option.dataset.businessId || 0);

            option.classList.toggle(
                'hidden',
                optionBusinessId !== 0 &&
                optionBusinessId !== Number(id)
            );
        });

    document.getElementById('addBusinessPicker').classList.add('hidden');
}

function selectAddBranch(id, name) {
    document.getElementById('addBranchId').value = id || '';

    const label = document.getElementById('addBranchLabel');
    label.textContent = name;

    if (id) {
        label.classList.remove('text-slate-400');
    } else {
        label.classList.add('text-slate-400');
    }

    document.getElementById('addBranchPicker').classList.add('hidden');
}

function validateAddUserForm() {
    const roleName = getSelectedRoleName('addRole');

    if (
        roleName !== 'Super Admin' &&
        !document.getElementById('addBusinessId').value
    ) {
        alert('Please select a Business.');
        togglePicker('addBusinessPicker');
        return false;
    }

    return true;
}

function openEditUserModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editFullName').value = user.full_name;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editRole').value = user.role_id;
    document.getElementById('editBusiness').value = user.business_id || '';
    document.getElementById('editStatus').value = user.status;

    refreshEditBranches(user.branch_id || '');

    handleEditRoleChange();

    showModal('editUserModal');
}

function refreshEditBranches(selectedBranchId = '') {
    const businessId =
        Number(document.getElementById('editBusiness').value || 0);

    const branchSelect =
        document.getElementById('editBranch');

    branchSelect.innerHTML =
        '<option value="">All Branches / Optional</option>';

    branchesData
        .filter(function (branch) {
            return branch.business_id === businessId;
        })
        .forEach(function (branch) {
            const option = document.createElement('option');
            option.value = branch.id;
            option.textContent = branch.branch_name;

            if (Number(selectedBranchId) === branch.id) {
                option.selected = true;
            }

            branchSelect.appendChild(option);
        });
}

/*
|--------------------------------------------------------------------------
| FILTER BRANCHES BY BUSINESS
|--------------------------------------------------------------------------
*/
const filterBusiness = document.getElementById('filterBusiness');
const filterBranch = document.getElementById('filterBranch');

function refreshFilterBranches() {
    const businessId =
        Number(filterBusiness.value || 0);

    Array
        .from(filterBranch.options)
        .forEach(function (option, index) {
            if (index === 0) return;

            const optionBusinessId =
                Number(option.dataset.businessId || 0);

            option.hidden =
                businessId > 0 &&
                optionBusinessId !== businessId;
        });

    const selectedOption =
        filterBranch.options[filterBranch.selectedIndex];

    if (
        selectedOption &&
        selectedOption.hidden
    ) {
        filterBranch.value = '';
    }
}

filterBusiness?.addEventListener('change', refreshFilterBranches);
refreshFilterBranches();

/*
|--------------------------------------------------------------------------
| OUTSIDE CLICK FOR PICKERS
|--------------------------------------------------------------------------
*/
document.addEventListener('click', function (event) {
    const insidePicker = event.target.closest('[id$="Picker"]');
    const pickerButton =
        event.target.closest('#addBusinessButton, #addBranchButton');

    if (!insidePicker && !pickerButton) {
        closeAllPickers();
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeAddUserModal();
        closeEditUserModal();
        closeStatusModal();
        closeAllPickers();
    }
});

if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

</body>
</html>
