<?php

require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();

if (($_SESSION['role'] ?? '') !== 'Business Admin') {
    http_response_code(403);
    exit('Access denied.');
}

$businessId   = (int)($_SESSION['business_id'] ?? 0);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Business';

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business not available.');
}

$canAddUser    = true;
$canUpdateUser = true;
$canDeleteUser = true;

function userE($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function userFlash(string $message, string $type = 'success'): void
{
    $_SESSION['business_users_flash'] = $message;
    $_SESSION['business_users_flash_type'] = $type;
}

if (empty($_SESSION['business_users_csrf'])) {
    $_SESSION['business_users_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['business_users_csrf'];

/*
|--------------------------------------------------------------------------
| BUSINESS BRANCHES
|--------------------------------------------------------------------------
*/

$branchStmt = $conn->prepare("
    SELECT id, branch_name, is_main, status
    FROM branches
    WHERE business_id = ?
    ORDER BY is_main DESC, branch_name ASC
");
$branchStmt->execute([$businessId]);
$branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

$branchMap = [];
foreach ($branches as $branch) {
    $branchMap[(int)$branch['id']] = $branch;
}

/*
|--------------------------------------------------------------------------
| DEFAULT BUSINESS USER ROLE
|--------------------------------------------------------------------------
| Role is not selected during registration.
| Every newly-created user gets the fixed "User" role.
| Actual access starts at zero and is granted later from User Permissions.
|--------------------------------------------------------------------------
*/

$defaultRoleName = 'User';

$roleLookup = $conn->prepare("
    SELECT id
    FROM roles
    WHERE role_name = ?
    LIMIT 1
");
$roleLookup->execute([$defaultRoleName]);

$defaultRoleId = (int)($roleLookup->fetchColumn() ?: 0);

if ($defaultRoleId <= 0) {
    $insertRole = $conn->prepare("
        INSERT INTO roles (role_name)
        VALUES (?)
    ");
    $insertRole->execute([$defaultRoleName]);
    $defaultRoleId = (int)$conn->lastInsertId();
}

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $postedToken === ''
        || !hash_equals($_SESSION['business_users_csrf'], $postedToken)
    ) {
        userFlash('Security token is invalid. Please try again.', 'error');
        header('Location: users.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | ADD
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        if (!$canAddUser) {
            http_response_code(403);
            exit('Access denied.');
        }

        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $branchId = (int)($_POST['branch_id'] ?? 0);
        $status   = (string)($_POST['status'] ?? 'Active');

        try {

            if ($fullName === '') {
                throw new Exception('Full name is required.');
            }

            if ($username === '' || strlen($username) < 3) {
                throw new Exception('Username must be at least 3 characters.');
            }

            if (strlen($password) < 6) {
                throw new Exception('Password must be at least 6 characters.');
            }
            if (!in_array($status, ['Active', 'Inactive', 'Suspended'], true)) {
                throw new Exception('Invalid user status.');
            }

            if ($branchId > 0) {
                if (!isset($branchMap[$branchId])) {
                    throw new Exception('Selected branch does not belong to this business.');
                }

                if (($branchMap[$branchId]['status'] ?? '') !== 'Active') {
                    throw new Exception('Selected branch is not Active.');
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
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $businessId,
                $branchId > 0 ? $branchId : null,
                $defaultRoleId,
                $fullName,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $status
            ]);

            $newUserId = (int)$conn->lastInsertId();

            /*
             * New users start with ZERO permissions.
             * We create explicit deny overrides for every current permission.
             */
            $permissionIds = $conn->query("
                SELECT id
                FROM permissions
            ")->fetchAll(PDO::FETCH_COLUMN);

            if ($permissionIds) {
                $denyStmt = $conn->prepare("
                    INSERT INTO user_permissions
                        (user_id, permission_id, allowed)
                    VALUES (?, ?, 0)
                    ON DUPLICATE KEY UPDATE allowed = 0
                ");

                foreach ($permissionIds as $permissionId) {
                    $denyStmt->execute([
                        $newUserId,
                        (int)$permissionId
                    ]);
                }
            }

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'CREATE',
                        'Users',
                        $newUserId,
                        'Created business user: ' . $username
                    );
                } catch (Throwable $e) {
                }
            }

            userFlash('User added successfully.');

        } catch (Throwable $e) {
            userFlash($e->getMessage(), 'error');
        }

        header('Location: users.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        if (!$canUpdateUser) {
            http_response_code(403);
            exit('Access denied.');
        }

        $userId   = (int)($_POST['user_id'] ?? 0);
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $branchId = (int)($_POST['branch_id'] ?? 0);
        $status   = (string)($_POST['status'] ?? 'Active');

        try {

            if ($userId <= 0) {
                throw new Exception('Invalid user.');
            }

            $currentStmt = $conn->prepare("
                SELECT
                    u.id,
                    u.full_name,
                    u.username,
                    u.status,
                    u.branch_id,
                    r.role_name
                FROM users u
                INNER JOIN roles r
                    ON r.id = u.role_id
                WHERE u.id = ?
                  AND u.business_id = ?
                LIMIT 1
            ");
            $currentStmt->execute([$userId, $businessId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                throw new Exception('User not found in this business.');
            }

            if ($current['role_name'] === 'Super Admin') {
                throw new Exception('Super Admin cannot be managed here.');
            }

            if ($fullName === '') {
                throw new Exception('Full name is required.');
            }

            if ($username === '' || strlen($username) < 3) {
                throw new Exception('Username must be at least 3 characters.');
            }

            if ($password !== '' && strlen($password) < 6) {
                throw new Exception('New password must be at least 6 characters.');
            }
            if (!in_array($status, ['Active', 'Inactive', 'Suspended'], true)) {
                throw new Exception('Invalid user status.');
            }

            if ($branchId > 0) {
                if (!isset($branchMap[$branchId])) {
                    throw new Exception('Selected branch does not belong to this business.');
                }

                if (($branchMap[$branchId]['status'] ?? '') !== 'Active') {
                    throw new Exception('Selected branch is not Active.');
                }
            }

            if ($userId === $currentUserId && $status !== 'Active') {
                throw new Exception('You cannot disable your own account.');
            }

            $duplicate = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                AND id <> ?
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
                        branch_id = ?,
                        full_name = ?,
                        username = ?,
                        password = ?,
                        status = ?
                    WHERE id = ?
                    AND business_id = ?
                ");

                $stmt->execute([
                    $branchId > 0 ? $branchId : null,
                    $fullName,
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $status,
                    $userId,
                    $businessId
                ]);
            } else {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        branch_id = ?,
                        full_name = ?,
                        username = ?,
                        status = ?
                    WHERE id = ?
                    AND business_id = ?
                ");

                $stmt->execute([
                    $branchId > 0 ? $branchId : null,
                    $fullName,
                    $username,
                    $status,
                    $userId,
                    $businessId
                ]);
            }

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'UPDATE',
                        'Users',
                        $userId,
                        'Updated business user: ' . $username
                    );
                } catch (Throwable $e) {
                }
            }

            userFlash('User updated successfully.');

        } catch (Throwable $e) {
            userFlash($e->getMessage(), 'error');
        }

        header('Location: users.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        if (!$canDeleteUser) {
            http_response_code(403);
            exit('Access denied.');
        }

        $userId = (int)($_POST['user_id'] ?? 0);

        try {

            if ($userId <= 0) {
                throw new Exception('Invalid user.');
            }

            if ($userId === $currentUserId) {
                throw new Exception('You cannot delete your own account.');
            }

            $stmt = $conn->prepare("
                SELECT
                    u.id,
                    u.username,
                    r.role_name
                FROM users u
                INNER JOIN roles r
                    ON r.id = u.role_id
                WHERE u.id = ?
                  AND u.business_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $businessId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new Exception('User not found in this business.');
            }

            if ($target['role_name'] === 'Super Admin') {
                throw new Exception('Super Admin cannot be deleted here.');
            }

            $delete = $conn->prepare("
                DELETE FROM users
                WHERE id = ?
                AND business_id = ?
            ");
            $delete->execute([$userId, $businessId]);

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'DELETE',
                        'Users',
                        $userId,
                        'Deleted business user: ' . $target['username']
                    );
                } catch (Throwable $e) {
                }
            }

            userFlash('User deleted successfully.');

        } catch (PDOException $e) {

            if ((string)$e->getCode() === '23000') {
                userFlash(
                    'This user is connected to existing records and cannot be deleted.',
                    'error'
                );
            } else {
                userFlash('Unable to delete user.', 'error');
            }

        } catch (Throwable $e) {
            userFlash($e->getMessage(), 'error');
        }

        header('Location: users.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| USERS - CURRENT BUSINESS ONLY
|--------------------------------------------------------------------------
*/

$userStmt = $conn->prepare("
    SELECT
        u.id,
        u.branch_id,
        u.full_name,
        u.username,
        u.status,
        u.created_at,
        u.last_login_at,
        br.branch_name
    FROM users u
    LEFT JOIN branches br
        ON br.id = u.branch_id
        AND br.business_id = u.business_id
    WHERE u.business_id = ?
    ORDER BY u.id DESC
");
$userStmt->execute([$businessId]);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = count($users);
$activeUsers = 0;
$inactiveUsers = 0;

foreach ($users as $row) {
    if ($row['status'] === 'Active') {
        $activeUsers++;
    } else {
        $inactiveUsers++;
    }
}

$flashMessage = $_SESSION['business_users_flash'] ?? null;
$flashType = $_SESSION['business_users_flash_type'] ?? 'success';

unset(
    $_SESSION['business_users_flash'],
    $_SESSION['business_users_flash_type']
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Users - <?= userE($businessName) ?></title>

    <link rel="icon" type="image/png" href="../assets/images/favicon.png">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>

    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body class="min-h-screen bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">

<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="min-h-screen transition-all duration-300 lg:ml-[270px]">

    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <main class="p-4 md:p-6">

        <div class="mb-5 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">

            <div>
                <div class="mb-1 flex items-center gap-2 text-[9px] font-bold uppercase tracking-wider text-slate-400">
                    Management <span>/</span> Users
                </div>

                <h1 class="text-xl font-black text-slate-900 dark:text-white">
                    Users
                </h1>

                <p class="mt-1 text-[10px] text-slate-500">
                    Manage users belonging to <?= userE($businessName) ?>.
                </p>
            </div>

            <?php if ($canAddUser): ?>
                <button
                    type="button"
                    onclick="openUserModal('addUserModal')"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-[10px] font-extrabold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700"
                >
                    <i data-lucide="user-plus" class="h-4 w-4"></i>
                    Add User
                </button>
            <?php endif; ?>

        </div>


        <?php if ($flashMessage): ?>
            <div
                id="userFlash"
                class="mb-5 flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-[10px] font-semibold
                <?= $flashType === 'error'
                    ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
                ?>"
            >
                <span class="flex items-center gap-2">
                    <i data-lucide="<?= $flashType === 'error' ? 'circle-alert' : 'circle-check' ?>" class="h-4 w-4"></i>
                    <?= userE($flashMessage) ?>
                </span>

                <button type="button" onclick="document.getElementById('userFlash').remove()">
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>
            </div>
        <?php endif; ?>


        <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">

            <div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[9px] font-bold uppercase text-slate-400">Total Users</p>
                <p class="mt-2 text-xl font-black text-slate-900 dark:text-white"><?= $totalUsers ?></p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[9px] font-bold uppercase text-slate-400">Active</p>
                <p class="mt-2 text-xl font-black text-emerald-600"><?= $activeUsers ?></p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[9px] font-bold uppercase text-slate-400">Inactive / Suspended</p>
                <p class="mt-2 text-xl font-black text-slate-500"><?= $inactiveUsers ?></p>
            </div>

        </div>


        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">

            <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                <div>
                    <h2 class="text-[12px] font-extrabold text-slate-800 dark:text-white">Business Users</h2>
                    <p class="mt-1 text-[9px] text-slate-400"><?= $totalUsers ?> users</p>
                </div>

                <div class="relative w-full sm:w-[300px]">
                    <i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
                    <input
                        type="text"
                        id="userSearch"
                        placeholder="Search user, role, branch..."
                        autocomplete="off"
                        class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-[10px] outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10 dark:border-slate-700 dark:bg-slate-950"
                    >
                </div>
            </div>


            <div class="overflow-x-auto">
                <table class="w-full text-left">

                    <thead class="bg-slate-50 text-[9px] font-bold uppercase tracking-wider text-slate-400 dark:bg-slate-950/60">
                        <tr>
                            <th class="px-5 py-3">User</th>
                            <th class="px-5 py-3">Branch</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Last Login</th>

                            <?php if ($canUpdateUser || $canDeleteUser): ?>
                                <th class="px-5 py-3 text-right">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>

                    <tbody id="userTableBody">

                    <?php if (!$users): ?>
                        <tr>
                            <td
                                colspan="<?= ($canUpdateUser || $canDeleteUser) ? 5 : 4 ?>"
                                class="px-5 py-14 text-center text-[10px] text-slate-400"
                            >
                                No business users found.
                            </td>
                        </tr>
                    <?php endif; ?>


                    <?php foreach ($users as $user): ?>

                        <?php
                        $searchText = strtolower(
                            $user['full_name'] . ' '
                            . $user['username'] . ' '
                            . ($user['branch_name'] ?? '') . ' '
                            . $user['status']
                        );
                        ?>

                        <tr
                            class="user-row border-t border-slate-100 text-[10px] transition hover:bg-slate-50/70 dark:border-slate-800 dark:hover:bg-slate-800/40"
                            data-search="<?= userE($searchText) ?>"
                        >

                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 font-extrabold text-blue-600 dark:bg-blue-950/40">
                                        <?= userE(strtoupper(substr($user['full_name'], 0, 1))) ?>
                                    </div>

                                    <div>
                                        <p class="font-bold text-slate-800 dark:text-white">
                                            <?= userE($user['full_name']) ?>
                                            <?php if ((int)$user['id'] === $currentUserId): ?>
                                                <span class="ml-1 text-[8px] text-blue-500">(You)</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="mt-1 text-[9px] text-slate-400">
                                            @<?= userE($user['username']) ?>
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="px-5 py-3.5 text-slate-500">
                                <?= userE($user['branch_name'] ?: 'All / Not assigned') ?>
                            </td>

                            <td class="px-5 py-3.5">
                                <?php
                                $statusClass = match ($user['status']) {
                                    'Active' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
                                    'Suspended' => 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
                                    default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                                };
                                ?>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-[8px] font-bold <?= $statusClass ?>">
                                    <?= userE($user['status']) ?>
                                </span>
                            </td>

                            <td class="px-5 py-3.5 text-slate-500">
                                <?= !empty($user['last_login_at'])
                                    ? userE(date('d M Y H:i', strtotime($user['last_login_at'])))
                                    : 'Never'
                                ?>
                            </td>


                            <?php if ($canUpdateUser || $canDeleteUser): ?>

                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-1">

                                        <?php if ($canUpdateUser): ?>
                                            <button
                                                type="button"
                                                title="Edit"
                                                onclick='openEditUserModal(<?= json_encode([
                                                    'id' => (int)$user['id'],
                                                    'full_name' => $user['full_name'],
                                                    'username' => $user['username'],
                                                    'branch_id' => $user['branch_id'] !== null ? (int)$user['branch_id'] : 0,
                                                    'status' => $user['status']
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                                class="flex h-8 w-8 items-center justify-center rounded-lg text-blue-600 transition hover:bg-blue-50 dark:hover:bg-blue-950/40"
                                            >
                                                <i data-lucide="pencil" class="h-3.5 w-3.5"></i>
                                            </button>
                                        <?php endif; ?>


                                        <?php if (
                                            $canDeleteUser
                                            && (int)$user['id'] !== $currentUserId
                                        ): ?>
                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Delete this user permanently?');"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= userE($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                                                <button
                                                    type="submit"
                                                    title="Delete"
                                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950/40"
                                                >
                                                    <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                    </div>
                                </td>

                            <?php endif; ?>

                        </tr>

                    <?php endforeach; ?>


                        <tr id="userSearchEmpty" class="hidden">
                            <td
                                colspan="<?= ($canUpdateUser || $canDeleteUser) ? 5 : 4 ?>"
                                class="px-5 py-12 text-center text-[10px] text-slate-400"
                            >
                                No user matches your search.
                            </td>
                        </tr>

                    </tbody>
                </table>
            </div>

        </section>

    </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

</div>


<?php if ($canAddUser): ?>

<div id="addUserModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="modal-box w-full max-w-lg scale-95 rounded-2xl border border-slate-200 bg-white shadow-2xl transition dark:border-slate-800 dark:bg-slate-900">

        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <div>
                <h3 class="text-[13px] font-extrabold text-slate-900 dark:text-white">Add User</h3>
                <p class="mt-1 text-[9px] text-slate-400">Create a user for this business.</p>
            </div>

            <button type="button" onclick="closeUserModal('addUserModal')" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= userE($csrfToken) ?>">
            <input type="hidden" name="action" value="add">

            <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">

                <div class="sm:col-span-2">
                    <label class="user-label">Full Name *</label>
                    <input type="text" name="full_name" maxlength="150" required autocomplete="off" class="user-input">
                </div>

                <div>
                    <label class="user-label">Username *</label>
                    <input type="text" name="username" minlength="3" maxlength="100" required autocomplete="off" class="user-input">
                </div>

                <div>
                    <label class="user-label">Password *</label>
                    <input type="password" name="password" minlength="6" required autocomplete="new-password" class="user-input">
                </div>

                <div>
                    <label class="user-label">Branch</label>
                    <select name="branch_id" class="user-input">
                        <option value="0">All / Not assigned</option>
                        <?php foreach ($branches as $branchItem): ?>
                            <?php if ($branchItem['status'] === 'Active'): ?>
                                <option value="<?= (int)$branchItem['id'] ?>">
                                    <?= userE($branchItem['branch_name']) ?>
                                    <?= (int)$branchItem['is_main'] === 1 ? ' (Main)' : '' ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="user-label">Status</label>
                    <select name="status" class="user-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Suspended">Suspended</option>
                    </select>
                </div>

            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-slate-800">
                <button type="button" onclick="closeUserModal('addUserModal')" class="h-9 rounded-xl border border-slate-200 px-4 text-[9px] font-bold dark:border-slate-700">
                    Cancel
                </button>

                <button type="submit" class="inline-flex h-9 items-center gap-2 rounded-xl bg-blue-600 px-4 text-[9px] font-extrabold text-white hover:bg-blue-700">
                    <i data-lucide="user-plus" class="h-3.5 w-3.5"></i>
                    Save User
                </button>
            </div>
        </form>

    </div>
</div>

<?php endif; ?>


<?php if ($canUpdateUser): ?>

<div id="editUserModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="modal-box w-full max-w-lg scale-95 rounded-2xl border border-slate-200 bg-white shadow-2xl transition dark:border-slate-800 dark:bg-slate-900">

        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <div>
                <h3 class="text-[13px] font-extrabold text-slate-900 dark:text-white">Edit User</h3>
                <p class="mt-1 text-[9px] text-slate-400">Update user account information.</p>
            </div>

            <button type="button" onclick="closeUserModal('editUserModal')" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= userE($csrfToken) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="editUserId">

            <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">

                <div class="sm:col-span-2">
                    <label class="user-label">Full Name *</label>
                    <input type="text" name="full_name" id="editUserFullName" maxlength="150" required class="user-input">
                </div>

                <div>
                    <label class="user-label">Username *</label>
                    <input type="text" name="username" id="editUserUsername" minlength="3" maxlength="100" required class="user-input">
                </div>

                <div>
                    <label class="user-label">New Password</label>
                    <input type="password" name="password" minlength="6" autocomplete="new-password" placeholder="Leave blank to keep current" class="user-input">
                </div>

                <div>
                    <label class="user-label">Branch</label>
                    <select name="branch_id" id="editUserBranch" class="user-input">
                        <option value="0">All / Not assigned</option>
                        <?php foreach ($branches as $branchItem): ?>
                            <option value="<?= (int)$branchItem['id'] ?>">
                                <?= userE($branchItem['branch_name']) ?>
                                <?= (int)$branchItem['is_main'] === 1 ? ' (Main)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="user-label">Status</label>
                    <select name="status" id="editUserStatus" class="user-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Suspended">Suspended</option>
                    </select>
                </div>

            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-slate-800">
                <button type="button" onclick="closeUserModal('editUserModal')" class="h-9 rounded-xl border border-slate-200 px-4 text-[9px] font-bold dark:border-slate-700">
                    Cancel
                </button>

                <button type="submit" class="inline-flex h-9 items-center gap-2 rounded-xl bg-blue-600 px-4 text-[9px] font-extrabold text-white hover:bg-blue-700">
                    <i data-lucide="save" class="h-3.5 w-3.5"></i>
                    Update User
                </button>
            </div>
        </form>

    </div>
</div>

<?php endif; ?>


<style>
.user-label {
    display: block;
    margin-bottom: .375rem;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    color: rgb(100 116 139);
}

.user-input {
    width: 100%;
    min-height: 42px;
    border: 1px solid rgb(226 232 240);
    border-radius: .75rem;
    background: rgb(248 250 252);
    padding: .65rem .8rem;
    font-size: 10px;
    outline: none;
    transition: all .2s ease;
}

.user-input:focus {
    border-color: rgb(59 130 246);
    box-shadow: 0 0 0 3px rgba(59,130,246,.08);
}

.dark .user-input {
    border-color: rgb(51 65 85);
    background: rgb(2 6 23);
    color: rgb(226 232 240);
}
</style>


<script>
document.addEventListener('DOMContentLoaded', function () {

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    const search = document.getElementById('userSearch');
    const rows = document.querySelectorAll('.user-row');
    const empty = document.getElementById('userSearchEmpty');

    if (search) {
        search.addEventListener('input', function () {

            const query = this.value.trim().toLowerCase();
            let visible = 0;

            rows.forEach(function (row) {
                const match = (row.dataset.search || '').toLowerCase().includes(query);
                row.style.display = match ? '' : 'none';

                if (match) {
                    visible++;
                }
            });

            if (empty) {
                empty.classList.toggle(
                    'hidden',
                    !(rows.length > 0 && visible === 0)
                );
            }
        });
    }

    ['addUserModal', 'editUserModal'].forEach(function (id) {

        const modal = document.getElementById(id);

        if (!modal) {
            return;
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeUserModal(id);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeUserModal('addUserModal');
            closeUserModal('editUserModal');
        }
    });

    const flash = document.getElementById('userFlash');

    if (flash) {
        setTimeout(function () {
            flash.style.opacity = '0';
            setTimeout(function () {
                flash.remove();
            }, 300);
        }, 3500);
    }
});


function openUserModal(id) {

    const modal = document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    setTimeout(function () {
        const box = modal.querySelector('.modal-box');

        if (box) {
            box.classList.remove('scale-95');
            box.classList.add('scale-100');
        }
    }, 20);
}


function closeUserModal(id) {

    const modal = document.getElementById(id);

    if (!modal || modal.classList.contains('hidden')) {
        return;
    }

    const box = modal.querySelector('.modal-box');

    if (box) {
        box.classList.remove('scale-100');
        box.classList.add('scale-95');
    }

    setTimeout(function () {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
    }, 120);
}


function openEditUserModal(user) {

    const modal = document.getElementById('editUserModal');

    if (!modal) {
        return;
    }

    document.getElementById('editUserId').value = user.id || '';
    document.getElementById('editUserFullName').value = user.full_name || '';
    document.getElementById('editUserUsername').value = user.username || '';
    document.getElementById('editUserBranch').value = user.branch_id || '0';
    document.getElementById('editUserStatus').value = user.status || 'Active';

    openUserModal('editUserModal');
}
</script>

<script src="../assets/js/app.js"></script>

</body>
</html>
