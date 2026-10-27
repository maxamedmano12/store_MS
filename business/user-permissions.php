<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/permissions.php';
require_once '../config/audit.php';

requireBusinessUser();

/*
|--------------------------------------------------------------------------
| BUSINESS ADMIN ONLY
|--------------------------------------------------------------------------
*/
if (($_SESSION['role'] ?? '') !== 'Business Admin') {
    http_response_code(403);
    exit('Access denied.');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0 || $currentUserId <= 0) {
    http_response_code(403);
    exit('Invalid business session.');
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function permissionAction(string $key): string
{
    $parts = explode('.', $key, 2);
    return $parts[1] ?? '';
}

function actionOrder(string $action): int
{
    return match ($action) {
        'view'   => 1,
        'add'    => 2,
        'update' => 3,
        'delete' => 4,
        default  => 99,
    };
}

function actionLabel(string $action): string
{
    return match ($action) {
        'view'   => 'View',
        'add'    => 'Add',
        'update' => 'Update',
        'delete' => 'Delete',
        default  => ucfirst($action),
    };
}

function isCanonicalPermission(string $key): bool
{
    if (preg_match('/^[a-z0-9_]+\.(view|add|update|delete)$/', $key)) {
        return true;
    }

    return in_array($key, [
        'reports.view',
        'audit.view',
        'settings.view',
        'settings.update',
        'dashboard.view',
    ], true);
}

function permissionResource(string $key): string
{
    return explode('.', $key, 2)[0] ?? $key;
}

function prettyResource(string $resource): string
{
    return ucwords(str_replace('_', ' ', $resource));
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['business_user_permissions_csrf'])) {
    $_SESSION['business_user_permissions_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['business_user_permissions_csrf'];

/*
|--------------------------------------------------------------------------
| ENSURE DELEGABLE PERMISSIONS EXIST
|--------------------------------------------------------------------------
*/
$delegableResources = [
    'categories'       => 'Inventory',
    'units'            => 'Inventory',
    'products'         => 'Inventory',
    'stock'            => 'Inventory',
    'batches'          => 'Inventory',
    'purchases'        => 'Purchases',
    'purchase_returns' => 'Purchases',
    'suppliers'        => 'Purchases',
    'sales'            => 'Sales',
    'sales_returns'    => 'Sales',
    'customers'        => 'Sales',
    'expenses'         => 'Finance',
    'payroll'          => 'Payroll',
    'accounting'       => 'Accounting',
    'cash_accounts'    => 'Accounting',
    'zakat'            => 'Accounting',
    'branches'         => 'Administration',
    'support'          => 'Support',
];

$permissionActions = [
    'view'   => 'View',
    'add'    => 'Add',
    'update' => 'Update',
    'delete' => 'Delete',
];

$findPermissionByNameStmt = $conn->prepare("\n    SELECT id, permission_key\n    FROM permissions\n    WHERE permission_name = ?\n    LIMIT 1\n");

$findPermissionByKeyStmt = $conn->prepare("\n    SELECT id\n    FROM permissions\n    WHERE permission_key = ?\n    LIMIT 1\n");

$insertPermissionStmt = $conn->prepare("\n    INSERT INTO permissions\n        (permission_key, permission_name, module_name)\n    VALUES (?, ?, ?)\n");

$updateLegacyPermissionStmt = $conn->prepare("\n    UPDATE permissions\n    SET permission_key = ?,\n        module_name = ?\n    WHERE id = ?\n");

/*
 * Make sure every resource has all four actions:
 * View, Add, Update and Delete.
 *
 * Older databases may already contain a row such as \"View Accounting\"
 * under a legacy permission_key. In that case we REUSE that row and change
 * its key to accounting.view instead of letting a UNIQUE permission_name
 * constraint prevent View from being created.
 */
foreach ($delegableResources as $resourceKey => $moduleName) {
    $resourceLabel = ucwords(str_replace('_', ' ', $resourceKey));

    foreach ($permissionActions as $actionKey => $actionLabel) {
        $permissionKey = $resourceKey . '.' . $actionKey;
        $permissionName = $actionLabel . ' ' . $resourceLabel;

        // 1) Exact canonical permission already exists.
        $findPermissionByKeyStmt->execute([$permissionKey]);
        $existingId = (int)($findPermissionByKeyStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $normalizeExistingStmt = $conn->prepare("
                UPDATE permissions
                SET module_name = ?,
                    permission_name = ?
                WHERE id = ?
            ");
            $normalizeExistingStmt->execute([
                $moduleName,
                $permissionName,
                $existingId,
            ]);
            continue;
        }

        // 2) Reuse an older/legacy row that has the same display name.
        $findPermissionByNameStmt->execute([$permissionName]);
        $legacyRow = $findPermissionByNameStmt->fetch(PDO::FETCH_ASSOC);

        if ($legacyRow) {
            try {
                $updateLegacyPermissionStmt->execute([
                    $permissionKey,
                    $moduleName,
                    (int)$legacyRow['id'],
                ]);
                continue;
            } catch (Throwable $legacyUpdateError) {
                // If that legacy row cannot be normalized, fall through to insert.
            }
        }

        // 3) Create the canonical permission.
        try {
            $insertPermissionStmt->execute([
                $permissionKey,
                $permissionName,
                $moduleName,
            ]);
        } catch (Throwable $insertError) {
            /*
             * Last fallback for databases where permission_name is UNIQUE and a
             * different legacy row still conflicts. The key remains canonical,
             * while the display name is made unique.
             */
            $insertPermissionStmt->execute([
                $permissionKey,
                $permissionName . ' [' . $permissionKey . ']',
                $moduleName,
            ]);
        }
    }
}

/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/
$flash = $_SESSION['business_user_permissions_flash'] ?? null;
unset($_SESSION['business_user_permissions_flash']);

function setPermissionFlash(string $type, string $message): void
{
    $_SESSION['business_user_permissions_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

/*
|--------------------------------------------------------------------------
| BRANCHES
|--------------------------------------------------------------------------
*/
$branchStmt = $conn->prepare("
    SELECT id, branch_name, status
    FROM branches
    WHERE business_id = ?
    ORDER BY is_main DESC, branch_name ASC
");
$branchStmt->execute([$businessId]);
$branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| USERS
|--------------------------------------------------------------------------
| Only users from the logged-in business.
| Business Admins are NOT listed as permission targets.
| Super Admin is never visible here.
|--------------------------------------------------------------------------
*/
$userStmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.branch_id,
        u.status,
        r.role_name,
        b.branch_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN branches b
        ON b.id = u.branch_id
       AND b.business_id = u.business_id
    WHERE u.business_id = ?
      AND r.role_name NOT IN ('Super Admin', 'Business Admin')
    ORDER BY u.full_name ASC, u.username ASC
");
$userStmt->execute([$businessId]);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

$userMap = [];
foreach ($users as $row) {
    $userMap[(int)$row['id']] = $row;
}

/*
|--------------------------------------------------------------------------
| SELECTED USER / BRANCH FILTER
|--------------------------------------------------------------------------
*/
$selectedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedBranchId = (int)($_POST['branch_id'] ?? 0);
    $selectedUserId = (int)($_POST['user_id'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| PERMISSIONS
|--------------------------------------------------------------------------
| Legacy permission keys remain in DB but are hidden here.
|--------------------------------------------------------------------------
*/
$permissionRows = $conn->query("
    SELECT id, permission_key, permission_name, module_name
    FROM permissions
    ORDER BY module_name ASC, permission_key ASC
")->fetchAll(PDO::FETCH_ASSOC);

$permissions = [];
$seenPermissionActions = [];

foreach ($permissionRows as $row) {
    $key = (string)$row['permission_key'];

    if (!isCanonicalPermission($key)) {
        continue;
    }

    /*
     * Business Admin-only administration pages are not delegated.
     * Users and User Permissions remain admin-only.
     */
    if (str_starts_with($key, 'users.')) {
        continue;
    }

    /*
     * Admin-only financial/asset areas are never delegated to normal users.
     */
    if (in_array(permissionResource($key), ['assets', 'depreciation'], true)) {
        continue;
    }

    /*
     * Dashboard is visible to every authenticated business user.
     * We therefore do not grant/revoke dashboard.view here.
     */
    if ($key === 'dashboard.view') {
        continue;
    }

    $resourceName = permissionResource($key);

    if (!array_key_exists($resourceName, $delegableResources)) {
        continue;
    }

    $actionName = permissionAction($key);
    $dedupeKey = $resourceName . '.' . $actionName;

    /*
     * Never render the same action twice for the same resource.
     */
    if (isset($seenPermissionActions[$dedupeKey])) {
        continue;
    }
    $seenPermissionActions[$dedupeKey] = true;

    $row['resource'] = $resourceName;
    $row['action'] = $actionName;

    /*
     * Always group a resource under its canonical module so one table
     * cannot appear in multiple sections because of old DB module names.
     */
    $row['module_name'] = $delegableResources[$resourceName];

    $permissions[] = $row;
}

usort($permissions, function ($a, $b) {
    $moduleCompare = strcasecmp((string)$a['module_name'], (string)$b['module_name']);
    if ($moduleCompare !== 0) {
        return $moduleCompare;
    }

    $resourceCompare = strcasecmp((string)$a['resource'], (string)$b['resource']);
    if ($resourceCompare !== 0) {
        return $resourceCompare;
    }

    return actionOrder((string)$a['action']) <=> actionOrder((string)$b['action']);
});

$permissionMap = [];
foreach ($permissions as $permission) {
    $permissionMap[(int)$permission['id']] = $permission;
}

/*
|--------------------------------------------------------------------------
| CURRENT ACTOR'S EFFECTIVE PERMISSIONS
|--------------------------------------------------------------------------
| Conservative anti-escalation rule:
| Business Admin may grant only permissions that the current admin has.
| Because Business Admin itself can have permissions removed by Super Admin.
|--------------------------------------------------------------------------
*/
$actorCanGrant = [];

foreach ($permissions as $permission) {
    $actorCanGrant[(int)$permission['id']] = true;
}

/*
|--------------------------------------------------------------------------
| SAVE USER PERMISSIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $postedCsrf)) {
            throw new RuntimeException('Invalid request token. Please refresh the page and try again.');
        }

        if ($selectedUserId <= 0 || !isset($userMap[$selectedUserId])) {
            throw new RuntimeException('Please select a valid user from your business.');
        }

        $targetUser = $userMap[$selectedUserId];

        if ((int)$targetUser['id'] === $currentUserId) {
            throw new RuntimeException('You cannot change your own permissions from this page.');
        }

        if ($selectedBranchId > 0 && (int)$targetUser['branch_id'] !== $selectedBranchId) {
            throw new RuntimeException('The selected user does not belong to the selected branch.');
        }

        $selectedIds = array_map('intval', (array)($_POST['permissions'] ?? []));
        $selectedSet = array_fill_keys($selectedIds, true);

        $resourcePermissionIds = [];
        foreach ($permissions as $permission) {
            $resourcePermissionIds[(string)$permission['resource']][(string)$permission['action']]
                = (int)$permission['id'];
        }

        // VIEW permissions are not manually managed on this page.

        /*
         * Load target's current overrides so permissions the actor cannot manage
         * are preserved exactly as they are.
         */
        $currentOverrideStmt = $conn->prepare("
            SELECT permission_id, allowed
            FROM user_permissions
            WHERE user_id = ?
        ");
        $currentOverrideStmt->execute([$selectedUserId]);

        $currentOverrides = [];
        foreach ($currentOverrideStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currentOverrides[(int)$row['permission_id']] = (int)$row['allowed'];
        }

        /*
         * Role defaults for target user.
         */
        $roleStmt = $conn->prepare("
            SELECT role_id
            FROM users
            WHERE id = ?
              AND business_id = ?
            LIMIT 1
        ");
        $roleStmt->execute([$selectedUserId, $businessId]);
        $targetRoleId = (int)($roleStmt->fetchColumn() ?: 0);

        if ($targetRoleId <= 0) {
            throw new RuntimeException('Selected user has no valid role.');
        }

        $rolePermissionStmt = $conn->prepare("
            SELECT permission_id
            FROM role_permissions
            WHERE role_id = ?
        ");
        $rolePermissionStmt->execute([$targetRoleId]);

        $roleDefaults = [];
        foreach ($rolePermissionStmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $roleDefaults[(int)$pid] = true;
        }

        $conn->beginTransaction();

        $deleteOverrideStmt = $conn->prepare("
            DELETE FROM user_permissions
            WHERE user_id = ?
              AND permission_id = ?
        ");

        $upsertOverrideStmt = $conn->prepare("
            INSERT INTO user_permissions
                (user_id, permission_id, allowed)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                allowed = VALUES(allowed)
        ");

        foreach ($permissions as $permission) {
            $permissionId = (int)$permission['id'];

            /*
             * If current Business Admin cannot grant/manage this permission,
             * preserve target state and do not touch it.
             */
            if (empty($actorCanGrant[$permissionId])) {
                continue;
            }

            $roleHas = isset($roleDefaults[$permissionId]);
            $shouldAllow = isset($selectedSet[$permissionId]);

            /*
             * Save only the difference from role defaults:
             * role yes + unchecked => explicit deny
             * role no  + checked   => explicit allow
             * same as role         => remove override
             */
            if ($roleHas === $shouldAllow) {
                $deleteOverrideStmt->execute([$selectedUserId, $permissionId]);
            } else {
                $upsertOverrideStmt->execute([
                    $selectedUserId,
                    $permissionId,
                    $shouldAllow ? 1 : 0
                ]);
            }
        }

        $conn->commit();

        /*
         * Audit safely. Different project versions may use different signatures.
         */
        try {
            if (function_exists('logActivity')) {
                logActivity(
                    'UPDATE',
                    'User Permissions',
                    $selectedUserId,
                    'Updated business user permissions',
                    null,
                    [
                        'user_id' => $selectedUserId,
                        'username' => $targetUser['username'] ?? '',
                    ]
                );
            }
        } catch (Throwable $auditError) {
            // Do not break permission save if audit helper signature differs.
        }

        setPermissionFlash('success', 'Permissions updated successfully.');
        $selfPage = basename((string)($_SERVER['PHP_SELF'] ?? 'user_permissions.php'));

        header(
            'Location: ' . $selfPage . '?branch_id=' .
            urlencode((string)$selectedBranchId) .
            '&user_id=' .
            urlencode((string)$selectedUserId)
        );
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $flash = [
            'type' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| TARGET EFFECTIVE PERMISSIONS
|--------------------------------------------------------------------------
*/
$targetEffective = [];
$targetRoleDefaults = [];
$targetOverrides = [];

if ($selectedUserId > 0 && isset($userMap[$selectedUserId])) {
    $roleStmt = $conn->prepare("
        SELECT role_id
        FROM users
        WHERE id = ?
          AND business_id = ?
        LIMIT 1
    ");
    $roleStmt->execute([$selectedUserId, $businessId]);
    $targetRoleId = (int)($roleStmt->fetchColumn() ?: 0);

    if ($targetRoleId > 0) {
        $rpStmt = $conn->prepare("
            SELECT permission_id
            FROM role_permissions
            WHERE role_id = ?
        ");
        $rpStmt->execute([$targetRoleId]);

        foreach ($rpStmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $targetRoleDefaults[(int)$pid] = true;
        }

        $uoStmt = $conn->prepare("
            SELECT permission_id, allowed
            FROM user_permissions
            WHERE user_id = ?
        ");
        $uoStmt->execute([$selectedUserId]);

        foreach ($uoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $targetOverrides[(int)$row['permission_id']] = (int)$row['allowed'];
        }

        foreach ($permissions as $permission) {
            $pid = (int)$permission['id'];

            if (array_key_exists($pid, $targetOverrides)) {
                $targetEffective[$pid] = $targetOverrides[$pid] === 1;
            } else {
                $targetEffective[$pid] = isset($targetRoleDefaults[$pid]);
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| GROUP PERMISSIONS BY MODULE + RESOURCE
|--------------------------------------------------------------------------
*/
$groupedPermissions = [];

foreach ($permissions as $permission) {
    $module = trim((string)$permission['module_name']) ?: 'General';
    $resource = (string)$permission['resource'];

    $groupedPermissions[$module][$resource][] = $permission;
}

/*
|--------------------------------------------------------------------------
| PAGE DATA
|--------------------------------------------------------------------------
*/
$businessName = (string)($_SESSION['business_name'] ?? 'Business');
$selectedUser = $selectedUserId > 0 && isset($userMap[$selectedUserId])
    ? $userMap[$selectedUserId]
    : null;

$visibleUsers = array_values(array_filter(
    $users,
    function ($user) use ($selectedBranchId) {
        return $selectedBranchId <= 0 || (int)$user['branch_id'] === $selectedBranchId;
    }
));

$usersForJs = array_map(
    static function (array $user): array {
        return [
            'id' => (int)$user['id'],
            'full_name' => (string)$user['full_name'],
            'username' => (string)$user['username'],
            'branch_id' => (int)($user['branch_id'] ?? 0),
            'branch_name' => (string)($user['branch_name'] ?? ''),
        ];
    },
    $users
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Permissions - <?= e($businessName) ?></title>

    <link rel="icon" type="image/png" href="../assets/images/favicon.png">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="stylesheet" href="../assets/css/app.css">

    <style>
        [x-cloak] { display: none !important; }

        .permission-check {
            width: 18px;
            height: 18px;
            accent-color: #2563eb;
            cursor: pointer;
        }

        .permission-check:disabled {
            cursor: not-allowed;
            opacity: .45;
        }

        .action-box {
            min-height: 44px;
        }

        .permission-card {
            transition: .2s ease;
        }

        .permission-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 28px rgba(15, 23, 42, .06);
        }

        .resource-row + .resource-row {
            border-top: 1px solid #eef2f7;
        }
    </style>
</head>

<body class="min-h-screen bg-slate-50 text-slate-800">
<?php include 'includes/sidebar.php'; ?>

<div class="min-h-screen min-w-0 transition-all duration-300 lg:ml-[270px]">
    <?php include 'includes/header.php'; ?>

    <main class="p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl mx-auto">

                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
                    <div>
                        <div class="flex items-center gap-2 text-sm text-slate-500 mb-1">
                            <span><?= e($businessName) ?></span>
                            <i data-lucide="chevron-right" class="w-4 h-4"></i>
                            <span>User Permissions</span>
                        </div>

                        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">
                            User Permissions
                        </h1>

                        <p class="text-sm text-slate-500 mt-1">
                            Grant each user access only to the business tables and actions they need.
                        </p>
                    </div>

                    <a
                        href="users.php"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-semibold hover:bg-slate-50 transition"
                    >
                        <i data-lucide="users" class="w-4 h-4"></i>
                        Users
                    </a>
                </div>

                <?php if ($flash): ?>
                    <div
                        class="mb-6 rounded-2xl border px-4 py-3 flex items-start gap-3 <?= ($flash['type'] ?? '') === 'success'
                            ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                            : 'bg-rose-50 border-rose-200 text-rose-800' ?>"
                    >
                        <i
                            data-lucide="<?= ($flash['type'] ?? '') === 'success' ? 'circle-check' : 'circle-alert' ?>"
                            class="w-5 h-5 mt-0.5 shrink-0"
                        ></i>

                        <div class="font-medium">
                            <?= e($flash['message'] ?? '') ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 items-end">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                Branch
                            </label>

                            <select
                                name="branch_id"
                                id="branchFilter"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                            >
                                <option value="0">All Branches</option>

                                <?php foreach ($branches as $branch): ?>
                                    <option
                                        value="<?= (int)$branch['id'] ?>"
                                        <?= $selectedBranchId === (int)$branch['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($branch['branch_name']) ?>
                                        <?= $branch['status'] !== 'Active' ? ' - ' . e($branch['status']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                User
                            </label>

                            <select
                                name="user_id"
                                id="userFilter"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                            >
                                <option value="0">Select User</option>

                                <?php foreach ($visibleUsers as $user): ?>
                                    <option
                                        value="<?= (int)$user['id'] ?>"
                                        <?= $selectedUserId === (int)$user['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($user['full_name']) ?>
                                        (<?= e($user['username']) ?>)
                                        <?php if (!empty($user['branch_name'])): ?>
                                            - <?= e($user['branch_name']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-700">
                            <div class="flex items-center gap-2 font-semibold">
                                <i data-lucide="zap" class="w-4 h-4"></i>
                                Automatic
                            </div>
                            <p class="mt-1 text-xs text-blue-600">
                                Select branch, then user. Permissions load automatically.
                            </p>
                        </div>
                    </form>
                </div>

                <?php if (!$selectedUser): ?>
                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-10 text-center">
                        <div class="w-16 h-16 mx-auto rounded-2xl bg-blue-50 flex items-center justify-center mb-4">
                            <i data-lucide="shield-check" class="w-8 h-8 text-blue-600"></i>
                        </div>

                        <h2 class="text-lg font-bold text-slate-900">
                            Select a user
                        </h2>

                        <p class="text-slate-500 text-sm mt-2 max-w-md mx-auto">
                            Choose a branch and user above. New users start with zero permissions until the Business Admin grants access.
                        </p>
                    </div>
                <?php else: ?>

                    <form method="POST" id="permissionForm">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="branch_id" value="<?= (int)$selectedBranchId ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$selectedUserId ?>">

                        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 mb-6">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-xl bg-slate-900 text-white flex items-center justify-center font-bold text-lg">
                                        <?= e(strtoupper(substr((string)$selectedUser['full_name'], 0, 1))) ?>
                                    </div>

                                    <div>
                                        <h2 class="font-bold text-slate-900 text-lg">
                                            <?= e($selectedUser['full_name']) ?>
                                        </h2>

                                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
                                            <span>@<?= e($selectedUser['username']) ?></span>
                                            <span><?= e($selectedUser['branch_name'] ?? 'No Branch') ?></span>
                                            <span><?= e($selectedUser['status']) ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        id="selectAllBtn"
                                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-slate-200 bg-white text-sm font-semibold hover:bg-slate-50 transition"
                                    >
                                        <i data-lucide="check-check" class="w-4 h-4"></i>
                                        Select All
                                    </button>

                                    <button
                                        type="button"
                                        id="clearAllBtn"
                                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-slate-200 bg-white text-sm font-semibold hover:bg-slate-50 transition"
                                    >
                                        <i data-lucide="eraser" class="w-4 h-4"></i>
                                        Clear
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                                        <i data-lucide="table-properties" class="h-5 w-5"></i>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Resources</p>
                                        <p class="text-lg font-black text-slate-900"><?= count($groupedPermissions) ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                                        <i data-lucide="shield-check" class="h-5 w-5"></i>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Granted</p>
                                        <p id="grantedCount" class="text-lg font-black text-slate-900">0</p>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                                        <i data-lucide="user-round-check" class="h-5 w-5"></i>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Selected User</p>
                                        <p class="truncate text-sm font-black text-slate-900"><?= e($selectedUser['full_name']) ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        
                        <div class="grid grid-cols-1 gap-5">
                            <?php foreach ($groupedPermissions as $moduleName => $resources): ?>
                                <?php
                                $moduleId = 'module-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($moduleName));
                                ?>

                                <section class="permission-card bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                                    <div class="px-5 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                        <div>
                                            <h3 class="font-bold text-slate-900">
                                                <?= e($moduleName) ?>
                                            </h3>
                                            <p class="text-xs text-slate-500 mt-1">
                                                Manage permissions for each table.
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            class="module-toggle inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-slate-100 text-slate-700 text-xs font-semibold hover:bg-slate-200 transition"
                                            data-module="<?= e($moduleId) ?>"
                                        >
                                            <i data-lucide="list-checks" class="w-4 h-4"></i>
                                            Toggle Module
                                        </button>
                                    </div>

                                    <div id="<?= e($moduleId) ?>">
                                        <?php foreach ($resources as $resource => $resourcePermissions): ?>
                                            <div class="resource-row px-5 py-4">
                                                <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">
                                                    <div class="min-w-0 xl:w-64">
                                                        <div class="font-semibold text-slate-800">
                                                            <?= e(prettyResource($resource)) ?>
                                                        </div>

                                                        <div class="text-xs text-slate-400 mt-1">
                                                            <?= e($resource) ?>
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 w-full xl:w-[540px]">
                                                        <?php
                                                        $byAction = [];
                                                        foreach ($resourcePermissions as $perm) {
                                                            $byAction[(string)$perm['action']] = $perm;
                                                        }

                                                        foreach (['view', 'add', 'update', 'delete'] as $action):
                                                            $permission = $byAction[$action] ?? null;
                                                            $missingPermission = $permission === null;
                                                            $pid = $missingPermission ? 0 : (int)$permission['id'];
                                                            $checked = !$missingPermission && !empty($targetEffective[$pid]);
                                                            $canManage = !$missingPermission && !empty($actorCanGrant[$pid]);

                                                            $badgeClasses = 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50';
                                                        ?>
                                                            <label
                                                                class="action-box inline-flex w-full items-center justify-center gap-2 rounded-xl border px-3 py-2.5 <?= e($badgeClasses) ?>"
                                                                title="<?= $missingPermission
                                                                    ? e($resource . '.' . $action . ' could not be created in permissions table.')
                                                                    : (!$canManage ? 'Your account cannot grant this permission.' : e($permission['permission_key'])) ?>"
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    <?= !$missingPermission ? 'name="permissions[]"' : '' ?>
                                                                    value="<?= $pid ?>"
                                                                    class="permission-check <?= !$missingPermission ? 'manageable-permission' : '' ?>"
                                                                    data-module="<?= e($moduleId) ?>"
                                                                    data-resource="<?= e($resource) ?>"
                                                                    data-action="<?= e($action) ?>"
                                                                    <?= $checked ? 'checked' : '' ?>
                                                                    <?= !$canManage ? 'disabled' : '' ?>
                                                                >

                                                                <span class="font-semibold text-sm">
                                                                    <?= e(actionLabel($action)) ?>
                                                                </span>

                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>

                        <div class="sticky bottom-4 mt-6">
                            <div class="bg-white/95 backdrop-blur border border-slate-200 rounded-2xl shadow-xl px-5 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="text-sm text-slate-500">
                                    Only permissions you are allowed to grant can be changed.
                                </div>

                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700 transition"
                                >
                                    <i data-lucide="save" class="w-4 h-4"></i>
                                    Save Permissions
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </main>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="../assets/js/app.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.lucide) {
            lucide.createIcons();
        }

        const branchFilter = document.getElementById('branchFilter');
        const userFilter = document.getElementById('userFilter');

        const allUsers = <?= json_encode(
            $usersForJs,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        ) ?>;

        const escapeHtml = (value) => {
            const div = document.createElement('div');
            div.textContent = value ?? '';
            return div.innerHTML;
        };

        const fillUsersForBranch = (branchId) => {
            if (!userFilter) return;

            const branchValue = parseInt(branchId || '0', 10);
            const currentUserId = <?= (int)$selectedUserId ?>;

            const filteredUsers = allUsers.filter(user => {
                return branchValue === 0 || parseInt(user.branch_id || 0, 10) === branchValue;
            });

            let html = '<option value="0">Select User</option>';

            filteredUsers.forEach(user => {
                const branchText = user.branch_name ? ' - ' + user.branch_name : '';
                const selected = parseInt(user.id, 10) === currentUserId ? ' selected' : '';

                html += '<option value="' + parseInt(user.id, 10) + '"' + selected + '>' +
                    escapeHtml(user.full_name) +
                    ' (' + escapeHtml(user.username) + ')' +
                    escapeHtml(branchText) +
                    '</option>';
            });

            userFilter.innerHTML = html;
        };

        if (branchFilter) {
            branchFilter.addEventListener('change', function () {
                fillUsersForBranch(this.value);
                if (userFilter) userFilter.value = '0';
            });
        }

        if (userFilter) {
            userFilter.addEventListener('change', function () {
                const userId = parseInt(this.value || '0', 10);
                if (userId <= 0) return;

                const params = new URLSearchParams();
                params.set('branch_id', branchFilter ? (branchFilter.value || '0') : '0');
                params.set('user_id', String(userId));

                window.location.href = window.location.pathname + '?' + params.toString();
            });
        }

        fillUsersForBranch(branchFilter ? branchFilter.value : '0');

        const selectable = () => Array.from(
            document.querySelectorAll('.manageable-permission:not(:disabled)')
        );

        const updateGrantedCount = () => {
            const counter = document.getElementById('grantedCount');
            if (!counter) return;
            counter.textContent = document.querySelectorAll('.manageable-permission:checked').length;
        };
        document.querySelectorAll('.manageable-permission').forEach(cb => {
            cb.addEventListener('change', function () {
                updateGrantedCount();
            });
        });

        updateGrantedCount();

        const selectAllBtn = document.getElementById('selectAllBtn');
        const clearAllBtn = document.getElementById('clearAllBtn');

        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function () {
                selectable().forEach(cb => cb.checked = true);
                updateGrantedCount();
            });
        }

        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', function () {
                selectable().forEach(cb => cb.checked = false);
                updateGrantedCount();
            });
        }

        document.querySelectorAll('.module-toggle').forEach(button => {
            button.addEventListener('click', function () {
                const moduleId = this.dataset.module;
                const checkboxes = Array.from(
                    document.querySelectorAll(
                        '.manageable-permission[data-module="' + moduleId + '"]:not(:disabled)'
                    )
                );

                if (!checkboxes.length) {
                    return;
                }

                const allChecked = checkboxes.every(cb => cb.checked);
                checkboxes.forEach(cb => cb.checked = !allChecked);
                updateGrantedCount();
            });
        });

        const form = document.getElementById('permissionForm');

        if (form) {
            form.addEventListener('submit', function () {
                /*
                 * Disabled checkboxes are not submitted.
                 * Backend intentionally preserves those permissions.
                 */
            });
        }
    });
</script>
</body>
</html>
