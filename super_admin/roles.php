<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/permissions.php';

requireSuperAdmin();


$message = '';
$messageType = '';


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['roles_csrf'])) {
    $_SESSION['roles_csrf'] = bin2hex(random_bytes(32));
}


$csrfToken = $_SESSION['roles_csrf'];


/*
|--------------------------------------------------------------------------
| CANONICAL TABLE / MODULE PERMISSIONS
|--------------------------------------------------------------------------
| Qaabka cusub:
|   table.view
|   table.add
|   table.update
|   table.delete
|
| Dashboard / Reports / Audit waa view-only.
| Settings waa view + update.
|--------------------------------------------------------------------------
*/

$permissionDefinitions = [

    'dashboard' => [
        'label' => 'Dashboard',
        'actions' => ['view']
    ],

    'categories' => [
        'label' => 'Categories',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'units' => [
        'label' => 'Units',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'products' => [
        'label' => 'Products',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'stock' => [
        'label' => 'Stock',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'batches' => [
        'label' => 'Product Batches',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'purchases' => [
        'label' => 'Purchases',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'purchase_returns' => [
        'label' => 'Purchase Returns',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'sales' => [
        'label' => 'Sales',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'sales_returns' => [
        'label' => 'Sales Returns',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'customers' => [
        'label' => 'Customers',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'suppliers' => [
        'label' => 'Suppliers',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'expenses' => [
        'label' => 'Expenses',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'payroll' => [
        'label' => 'Payroll',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'assets' => [
        'label' => 'Fixed Assets',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'depreciation' => [
        'label' => 'Depreciation',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'accounting' => [
        'label' => 'Accounting',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'cash_accounts' => [
        'label' => 'Cash / Bank / EVC',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'zakat' => [
        'label' => 'Zakat',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'branches' => [
        'label' => 'Branches',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'users' => [
        'label' => 'Users',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'support' => [
        'label' => 'Support / Chat',
        'actions' => ['view', 'add', 'update', 'delete']
    ],

    'reports' => [
        'label' => 'Reports',
        'actions' => ['view']
    ],

    'audit' => [
        'label' => 'Audit Trail',
        'actions' => ['view']
    ],

    'settings' => [
        'label' => 'Settings',
        'actions' => ['view', 'update']
    ],
];


$permissionActionNames = [
    'view'   => 'View',
    'add'    => 'Add',
    'update' => 'Update',
    'delete' => 'Delete'
];


$insertPermission = $conn->prepare("
    INSERT INTO permissions
    (
        permission_key,
        permission_name,
        module_name
    )
    VALUES (?, ?, ?)

    ON DUPLICATE KEY UPDATE
        permission_name = VALUES(permission_name),
        module_name = VALUES(module_name)
");


foreach ($permissionDefinitions as $permissionBase => $definition) {

    foreach ($definition['actions'] as $permissionAction) {

        $permissionKey =
            $permissionBase
            . '.'
            . $permissionAction;

        $permissionName =
            ($permissionActionNames[$permissionAction] ?? ucfirst($permissionAction))
            . ' '
            . $definition['label'];

        $insertPermission->execute([
            $permissionKey,
            $permissionName,
            $definition['label']
        ]);
    }
}


function isCanonicalCrudPermissionKey(string $key): bool
{
    return (bool) preg_match(
        '/^[a-z0-9_]+\.(view|add|update|delete)$/',
        $key
    );
}



/*
|--------------------------------------------------------------------------
| AJAX
|--------------------------------------------------------------------------
*/

if (isset($_GET['ajax'])) {

    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['ajax'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | LOAD BRANCHES
    |--------------------------------------------------------------------------
    */

    if ($action === 'branches') {

        $businessId = (int)($_GET['business_id'] ?? 0);

        if ($businessId <= 0) {

            echo json_encode([
                'success' => false,
                'message' => 'Invalid business.'
            ]);

            exit;
        }


        $stmt = $conn->prepare("
            SELECT
                id,
                branch_name,
                is_main,
                status

            FROM branches

            WHERE business_id = ?

            ORDER BY
                is_main DESC,
                branch_name ASC
        ");

        $stmt->execute([$businessId]);


        echo json_encode([
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | LOAD USERS
    |--------------------------------------------------------------------------
    */

    if ($action === 'users') {

        $businessId = (int)($_GET['business_id'] ?? 0);
        $branchId = (int)($_GET['branch_id'] ?? 0);


        if ($businessId <= 0 || $branchId <= 0) {

            echo json_encode([
                'success' => false,
                'message' => 'Invalid branch.'
            ]);

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE BRANCH
        |--------------------------------------------------------------------------
        */

        $branchCheck = $conn->prepare("
            SELECT id

            FROM branches

            WHERE id = ?
            AND business_id = ?

            LIMIT 1
        ");

        $branchCheck->execute([
            $branchId,
            $businessId
        ]);


        if (!$branchCheck->fetch()) {

            echo json_encode([
                'success' => false,
                'message' => 'Branch does not belong to this business.'
            ]);

            exit;
        }


        $stmt = $conn->prepare("
            SELECT
                u.id,
                u.full_name,
                u.username,
                u.status,
                r.role_name

            FROM users u

            INNER JOIN roles r
                ON r.id = u.role_id

            WHERE u.business_id = ?
            AND u.branch_id = ?
            AND r.role_name <> 'Super Admin'

            ORDER BY
                u.full_name ASC
        ");

        $stmt->execute([
            $businessId,
            $branchId
        ]);


        echo json_encode([
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | LOAD USER PERMISSIONS
    |--------------------------------------------------------------------------
    */

    if ($action === 'permissions') {

        $businessId = (int)($_GET['business_id'] ?? 0);
        $branchId = (int)($_GET['branch_id'] ?? 0);
        $userId = (int)($_GET['user_id'] ?? 0);


        if (
            $businessId <= 0 ||
            $branchId <= 0 ||
            $userId <= 0
        ) {

            echo json_encode([
                'success' => false,
                'message' => 'Invalid request.'
            ]);

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE USER
        |--------------------------------------------------------------------------
        */

        $userStmt = $conn->prepare("
            SELECT
                u.id,
                u.full_name,
                u.username,
                u.status,
                u.role_id,
                r.role_name

            FROM users u

            INNER JOIN roles r
                ON r.id = u.role_id

            WHERE u.id = ?
            AND u.business_id = ?
            AND u.branch_id = ?
            AND r.role_name <> 'Super Admin'

            LIMIT 1
        ");

        $userStmt->execute([
            $userId,
            $businessId,
            $branchId
        ]);

        $user = $userStmt->fetch(PDO::FETCH_ASSOC);


        if (!$user) {

            echo json_encode([
                'success' => false,
                'message' => 'User not found in this branch.'
            ]);

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | ROLE DEFAULT PERMISSIONS
        |--------------------------------------------------------------------------
        */

        $roleStmt = $conn->prepare("
            SELECT permission_id

            FROM role_permissions

            WHERE role_id = ?
        ");

        $roleStmt->execute([
            $user['role_id']
        ]);

        $rolePermissions = array_map(
            'intval',
            $roleStmt->fetchAll(PDO::FETCH_COLUMN)
        );


        /*
        |--------------------------------------------------------------------------
        | USER OVERRIDES
        |--------------------------------------------------------------------------
        */

        $userPermissionStmt = $conn->prepare("
            SELECT
                permission_id,
                allowed

            FROM user_permissions

            WHERE user_id = ?
        ");

        $userPermissionStmt->execute([
            $userId
        ]);


        $userOverrides = [];


        foreach (
            $userPermissionStmt->fetchAll(PDO::FETCH_ASSOC)
            as $row
        ) {

            $userOverrides[
                (int)$row['permission_id']
            ] = (int)$row['allowed'];
        }


        /*
        |--------------------------------------------------------------------------
        | FINAL PERMISSIONS
        |--------------------------------------------------------------------------
        */

        $finalPermissions = $rolePermissions;


        foreach ($userOverrides as $permissionId => $allowed) {

            if ($allowed === 1) {

                if (
                    !in_array(
                        $permissionId,
                        $finalPermissions,
                        true
                    )
                ) {

                    $finalPermissions[] =
                        $permissionId;
                }

            } else {

                $finalPermissions =
                    array_values(
                        array_filter(
                            $finalPermissions,
                            fn($id) =>
                                $id !== $permissionId
                        )
                    );
            }
        }


        echo json_encode([
            'success' => true,

            'data' => [
                'user' => $user,
                'permissions' => $finalPermissions
            ]
        ]);

        exit;
    }


    echo json_encode([
        'success' => false,
        'message' => 'Unknown request.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| SAVE USER PERMISSIONS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['save_permissions'])
) {

    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        $postedToken =
            $_POST['csrf_token'] ?? '';


        if (
            !$postedToken ||
            !hash_equals(
                $_SESSION['roles_csrf'],
                $postedToken
            )
        ) {

            throw new Exception(
                'Security token expired. Refresh page.'
            );
        }


        $businessId =
            (int)($_POST['business_id'] ?? 0);

        $branchId =
            (int)($_POST['branch_id'] ?? 0);

        $userId =
            (int)($_POST['user_id'] ?? 0);

        $selectedPermissions =
            $_POST['permissions'] ?? [];


        /*
        |--------------------------------------------------------------------------
        | VALIDATE SELECTION
        |--------------------------------------------------------------------------
        */

        if (
            $businessId <= 0 ||
            $branchId <= 0 ||
            $userId <= 0
        ) {

            throw new Exception(
                'Select Business, Branch and User.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE USER TENANT
        |--------------------------------------------------------------------------
        */

        $userCheck = $conn->prepare("
            SELECT
                u.id,
                u.full_name,
                u.role_id,
                r.role_name

            FROM users u

            INNER JOIN roles r
                ON r.id = u.role_id

            WHERE u.id = ?
            AND u.business_id = ?
            AND u.branch_id = ?
            AND r.role_name <> 'Super Admin'

            LIMIT 1
        ");

        $userCheck->execute([
            $userId,
            $businessId,
            $branchId
        ]);

        $selectedUser =
            $userCheck->fetch(PDO::FETCH_ASSOC);


        if (!$selectedUser) {

            throw new Exception(
                'Selected user does not belong to this business branch.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CLEAN SELECTED PERMISSIONS
        |--------------------------------------------------------------------------
        */

        if (!is_array($selectedPermissions)) {
            $selectedPermissions = [];
        }


        $selectedPermissions =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            $selectedPermissions
                        ),
                        fn($id) => $id > 0
                    )
                )
            );


        /*
        |--------------------------------------------------------------------------
        | VALID PERMISSION IDS
        |--------------------------------------------------------------------------
        */

        $validPermissions = [];


        if (!empty($selectedPermissions)) {

            $placeholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count($selectedPermissions),
                        '?'
                    )
                );


            $checkPermissions =
                $conn->prepare("
                    SELECT id

                    FROM permissions

                    WHERE id IN ($placeholders)
                    AND permission_key REGEXP '\\.(view|add|update|delete)$'
                ");


            $checkPermissions
                ->execute($selectedPermissions);


            $validPermissions =
                array_map(
                    'intval',
                    $checkPermissions
                        ->fetchAll(PDO::FETCH_COLUMN)
                );
        }


        /*
        |--------------------------------------------------------------------------
        | ROLE DEFAULTS
        |--------------------------------------------------------------------------
        */

        $rolePermissionStmt =
            $conn->prepare("
                SELECT permission_id

                FROM role_permissions

                WHERE role_id = ?
            ");

        $rolePermissionStmt->execute([
            $selectedUser['role_id']
        ]);


        $rolePermissionIds =
            array_map(
                'intval',
                $rolePermissionStmt
                    ->fetchAll(PDO::FETCH_COLUMN)
            );


        /*
        |--------------------------------------------------------------------------
        | SAVE OVERRIDES
        |--------------------------------------------------------------------------
        |
        | If role already has permission:
        | checked   = no override needed
        | unchecked = allowed 0
        |
        | If role does not have permission:
        | checked   = allowed 1
        | unchecked = no override needed
        |--------------------------------------------------------------------------
        */

        $conn->beginTransaction();


        $deleteOverrides =
            $conn->prepare("
                DELETE FROM user_permissions

                WHERE user_id = ?
            ");

        $deleteOverrides->execute([
            $userId
        ]);


        $insertOverride =
            $conn->prepare("
                INSERT INTO user_permissions
                (
                    user_id,
                    permission_id,
                    allowed
                )

                VALUES (?, ?, ?)
            ");


        /*
        |--------------------------------------------------------------------------
        | ALL PERMISSION IDS
        |--------------------------------------------------------------------------
        */

        $allPermissionIds =
            array_map(
                'intval',
                $conn->query("
                    SELECT id
                    FROM permissions
                    WHERE permission_key REGEXP '\\.(view|add|update|delete)$'
                ")->fetchAll(PDO::FETCH_COLUMN)
            );


        foreach ($allPermissionIds as $permissionId) {

            $roleHas =
                in_array(
                    $permissionId,
                    $rolePermissionIds,
                    true
                );


            $userSelected =
                in_array(
                    $permissionId,
                    $validPermissions,
                    true
                );


            /*
            |--------------------------------------------------------------------------
            | ROLE HAS BUT USER UNCHECKED
            |--------------------------------------------------------------------------
            */

            if (
                $roleHas &&
                !$userSelected
            ) {

                $insertOverride->execute([
                    $userId,
                    $permissionId,
                    0
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | ROLE DOES NOT HAVE BUT USER CHECKED
            |--------------------------------------------------------------------------
            */

            if (
                !$roleHas &&
                $userSelected
            ) {

                $insertOverride->execute([
                    $userId,
                    $permissionId,
                    1
                ]);
            }
        }


        $conn->commit();


        $message =
            $selectedUser['full_name'] .
            ' permissions waa la kaydiyey.';

        $messageType =
            'success';


    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }


        $message =
            $e->getMessage();

        $messageType =
            'error';
    }
}


/*
|--------------------------------------------------------------------------
| BUSINESSES
|--------------------------------------------------------------------------
*/

$businesses =
    $conn->query("
        SELECT
            id,
            business_name,
            status

        FROM businesses

        ORDER BY business_name ASC
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| PERMISSIONS
|--------------------------------------------------------------------------
*/

$permissions =
    $conn->query("
        SELECT
            id,
            permission_key,
            permission_name,
            module_name

        FROM permissions

        WHERE permission_key
            REGEXP '\\.(view|add|update|delete)$'

        ORDER BY
            module_name ASC,

            FIELD(
                SUBSTRING_INDEX(
                    permission_key,
                    '.',
                    -1
                ),
                'view',
                'add',
                'update',
                'delete'
            ),

            permission_name ASC
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GROUP PERMISSIONS
|--------------------------------------------------------------------------
*/

$permissionsByModule = [];


foreach ($permissions as $permission) {

    $module =
        trim(
            $permission['module_name']
            ?? ''
        );


    if ($module === '') {
        $module = 'General';
    }


    $permissionsByModule[$module][] =
        $permission;
}


$totalBusinesses =
    count($businesses);

$totalBranches =
    (int)$conn->query("
        SELECT COUNT(*)
        FROM branches
    ")->fetchColumn();

$totalUsers =
    (int)$conn->query("
        SELECT COUNT(*)
        FROM users
        WHERE business_id IS NOT NULL
    ")->fetchColumn();

$totalPermissions =
    count($permissions);

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>User Roles & Permissions</title>

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


<style>

.permission-checkbox:checked
+ .permission-card {

    border-color: rgb(37 99 235);
    background: rgb(239 246 255);
}


.dark
.permission-checkbox:checked
+ .permission-card {

    border-color: rgb(59 130 246);
    background: rgba(30, 64, 175, .18);
}

</style>

</head>


<body
class="
bg-slate-50
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


<main
class="
page-enter
px-4
py-6
md:px-7
md:py-7
"
>


<!-- PAGE HEADER -->

<div
class="
mb-6
flex
flex-col
gap-4
xl:flex-row
xl:items-center
xl:justify-between
"
>


<div>

<div
class="
mb-2
flex
items-center
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
User Permissions
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
User Roles & Permissions
</h1>


<p
class="
mt-1
text-xs
text-slate-500
dark:text-slate-400
"
>
Choose Business → Branch → User, then give each table View, Add, Update and Delete access.
</p>

</div>


<div
class="
inline-flex
items-center
gap-2
rounded-xl
border
border-blue-100
bg-blue-50
px-4
py-2.5
text-xs
font-semibold
text-blue-700

dark:border-blue-900
dark:bg-blue-950/30
dark:text-blue-300
"
>

<i
data-lucide="shield-check"
class="h-4 w-4"
></i>

Super Admin Access Control

</div>

</div>


<!-- FLASH -->

<?php if ($message !== ''): ?>

<div
id="alertMessage"
class="
mb-6
flex
items-center
justify-between
gap-3
rounded-xl
border
p-4

<?= $messageType === 'success'
?
'border-emerald-200 bg-emerald-50 text-emerald-700'
:
'border-red-200 bg-red-50 text-red-700'
?>
"
>

<div class="flex items-center gap-3">

<i
data-lucide="
<?= $messageType === 'success'
? 'circle-check'
: 'circle-alert'
?>
"
class="h-5 w-5"
></i>


<p class="text-xs font-semibold">

<?= htmlspecialchars($message) ?>

</p>

</div>


<button
type="button"
onclick="
document
.getElementById('alertMessage')
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


<!-- STATS -->

<div
class="
mb-6
grid
grid-cols-2
gap-3
lg:grid-cols-4
"
>


<div
class="
rounded-2xl
border
border-slate-200
bg-white
p-4
dark:border-slate-800
dark:bg-slate-900
"
>

<p class="text-[10px] font-bold uppercase text-slate-400">
Businesses
</p>

<h3
class="
mt-1
text-2xl
font-black
text-blue-600
"
>
<?= $totalBusinesses ?>
</h3>

</div>


<div
class="
rounded-2xl
border
border-slate-200
bg-white
p-4
dark:border-slate-800
dark:bg-slate-900
"
>

<p class="text-[10px] font-bold uppercase text-slate-400">
Branches
</p>

<h3
class="
mt-1
text-2xl
font-black
text-violet-600
"
>
<?= $totalBranches ?>
</h3>

</div>


<div
class="
rounded-2xl
border
border-slate-200
bg-white
p-4
dark:border-slate-800
dark:bg-slate-900
"
>

<p class="text-[10px] font-bold uppercase text-slate-400">
Users
</p>

<h3
class="
mt-1
text-2xl
font-black
text-emerald-600
"
>
<?= $totalUsers ?>
</h3>

</div>


<div
class="
rounded-2xl
border
border-slate-200
bg-white
p-4
dark:border-slate-800
dark:bg-slate-900
"
>

<p class="text-[10px] font-bold uppercase text-slate-400">
Permissions
</p>

<h3
class="
mt-1
text-2xl
font-black
text-amber-600
"
>
<?= $totalPermissions ?>
</h3>

</div>

</div>


<form
method="POST"
id="permissionForm"
>


<input
type="hidden"
name="save_permissions"
value="1"
>


<input
type="hidden"
name="csrf_token"
value="<?= htmlspecialchars($csrfToken) ?>"
>


<!-- SELECTOR -->

<section
class="
mb-6
rounded-2xl
border
border-slate-200
bg-white
p-5

dark:border-slate-800
dark:bg-slate-900
"
>


<div
class="
mb-5
flex
items-center
gap-3
"
>

<div
class="
flex
h-10
w-10
items-center
justify-center
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


<div>

<h2 class="text-sm font-extrabold">
Select User
</h2>

<p class="mt-1 text-[10px] text-slate-500">
Business → Branch → User
</p>

</div>

</div>


<div
class="
grid
grid-cols-1
gap-5
md:grid-cols-3
"
>


<!-- BUSINESS -->

<div>

<label
class="
mb-2
block
text-xs
font-bold
"
>
Business
</label>


<select
name="business_id"
id="business_id"
required

class="
h-11
w-full
rounded-xl
border
border-slate-200
bg-slate-50
px-3
text-xs
outline-none

focus:border-blue-500

dark:border-slate-700
dark:bg-slate-800
"
>

<option value="">
Select Business
</option>


<?php foreach ($businesses as $business): ?>

<option
value="<?= (int)$business['id'] ?>"
>

<?= htmlspecialchars(
    $business['business_name']
) ?>

<?= $business['status'] !== 'Active'
    ? ' - ' . htmlspecialchars($business['status'])
    : ''
?>

</option>

<?php endforeach; ?>

</select>

</div>


<!-- BRANCH -->

<div>

<label
class="
mb-2
block
text-xs
font-bold
"
>
Branch
</label>


<select
name="branch_id"
id="branch_id"
required
disabled

class="
h-11
w-full
rounded-xl
border
border-slate-200
bg-slate-50
px-3
text-xs
outline-none

disabled:cursor-not-allowed
disabled:opacity-50

focus:border-blue-500

dark:border-slate-700
dark:bg-slate-800
"
>

<option value="">
Select Business First
</option>

</select>

</div>


<!-- USER -->

<div>

<label
class="
mb-2
block
text-xs
font-bold
"
>
User
</label>


<select
name="user_id"
id="user_id"
required
disabled

class="
h-11
w-full
rounded-xl
border
border-slate-200
bg-slate-50
px-3
text-xs
outline-none

disabled:cursor-not-allowed
disabled:opacity-50

focus:border-blue-500

dark:border-slate-700
dark:bg-slate-800
"
>

<option value="">
Select Branch First
</option>

</select>

</div>

</div>


<!-- USER INFO -->

<div
id="userInfo"
class="
mt-5
hidden
rounded-xl
border
border-slate-200
bg-slate-50
p-4

dark:border-slate-700
dark:bg-slate-800/50
"
>


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
h-11
w-11
items-center
justify-center
rounded-full
bg-blue-100
text-blue-600

dark:bg-blue-950
"
>

<i
data-lucide="user"
class="h-5 w-5"
></i>

</div>


<div>

<p
id="userInfoName"
class="text-xs font-extrabold"
></p>


<p
id="userInfoMeta"
class="
mt-1
text-[10px]
text-slate-500
"
></p>

</div>

</div>

</div>

</section>


<!-- PERMISSIONS -->

<section
class="
overflow-hidden
rounded-2xl
border
border-slate-200
bg-white

dark:border-slate-800
dark:bg-slate-900
"
>


<div
class="
flex
flex-col
gap-4
border-b
border-slate-100
p-5

sm:flex-row
sm:items-center
sm:justify-between

dark:border-slate-800
"
>


<div>

<h2 class="text-sm font-extrabold">
User Permissions
</h2>

<p
id="permissionStatus"
class="
mt-1
text-[10px]
text-slate-500
"
>
Select a user first.
</p>

</div>


<div class="flex gap-2">


<button
id="selectAllPermissions"
type="button"
disabled

class="
h-9
rounded-xl
border
border-slate-200
px-3
text-[10px]
font-semibold

disabled:opacity-40

dark:border-slate-700
"
>
Select All
</button>


<button
id="clearPermissions"
type="button"
disabled

class="
h-9
rounded-xl
border
border-slate-200
px-3
text-[10px]
font-semibold

disabled:opacity-40

dark:border-slate-700
"
>
Clear
</button>

</div>

</div>


<div
id="permissionContainer"
class="
pointer-events-none
p-5
opacity-40
"
>


<div class="space-y-5">


<?php foreach (
    $permissionsByModule
    as $module => $modulePermissions
): ?>


<div
class="
permissionModule
rounded-2xl
border
border-slate-200
p-4

dark:border-slate-800
"
>


<div
class="
mb-4
flex
items-center
justify-between
"
>


<div class="flex items-center gap-3">


<div
class="
flex
h-9
w-9
items-center
justify-center
rounded-xl
bg-slate-100
text-slate-600

dark:bg-slate-800
dark:text-slate-300
"
>

<i
data-lucide="folder-key"
class="h-4 w-4"
></i>

</div>


<div>

<h3 class="text-xs font-extrabold">

<?= htmlspecialchars($module) ?>

</h3>

<p
class="
mt-0.5
text-[9px]
text-slate-400
"
>

<?= count($modulePermissions) ?>
permissions

</p>

</div>

</div>


<button
type="button"

class="
moduleToggle
text-[9px]
font-bold
text-blue-600
"
>

Select module

</button>

</div>


<div
class="
grid
gap-2
sm:grid-cols-2
lg:grid-cols-3
"
>


<?php foreach (
    $modulePermissions
    as $permission
): ?>


<label
class="
relative
cursor-pointer
"
>


<input
type="checkbox"

name="permissions[]"

value="<?= (int)$permission['id'] ?>"

class="
permission-checkbox
peer
sr-only
"
>


<div
class="
permission-card
h-full
rounded-xl
border
border-slate-200
p-3
transition

hover:border-blue-300

dark:border-slate-700
"
>


<div
class="
flex
items-start
gap-3
"
>


<div
class="
mt-0.5
flex
h-5
w-5
shrink-0
items-center
justify-center
rounded-md
border
border-slate-300
text-transparent

peer-checked:border-blue-600
peer-checked:bg-blue-600
peer-checked:text-white
"
>

<i
data-lucide="check"
class="h-3 w-3"
></i>

</div>


<div>

<p class="text-[10px] font-bold">

<?= htmlspecialchars(
    $permission['permission_name']
) ?>

</p>

<?php

$permissionParts =
    explode(
        '.',
        $permission['permission_key']
    );

$permissionAction =
    $permissionParts[
        count($permissionParts) - 1
    ] ?? 'view';

$actionBadgeClass =
    match ($permissionAction) {
        'view' =>
            'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',

        'add' =>
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',

        'update' =>
            'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',

        'delete' =>
            'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',

        default =>
            'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
    };

?>

<span
class="
mt-2
inline-flex
rounded-md
px-2
py-1
text-[8px]
font-extrabold
uppercase
<?= $actionBadgeClass ?>
"
>
<?= htmlspecialchars($permissionAction) ?>
</span>


<p
class="
mt-1
text-[8px]
text-slate-400
"
>

<?= htmlspecialchars(
    $permission['permission_key']
) ?>

</p>

</div>

</div>

</div>

</label>


<?php endforeach; ?>


</div>

</div>


<?php endforeach; ?>


</div>

</div>


<!-- SAVE -->

<div
class="
flex
items-center
justify-between
border-t
border-slate-100
bg-slate-50
p-5

dark:border-slate-800
dark:bg-slate-950
"
>


<p
id="selectedPermissionCount"
class="
text-[10px]
text-slate-500
"
>
0 permissions selected
</p>


<button
id="saveButton"
type="submit"
disabled

class="
flex
h-10
items-center
gap-2
rounded-xl
bg-blue-600
px-5
text-xs
font-bold
text-white

hover:bg-blue-700

disabled:cursor-not-allowed
disabled:opacity-40
"
>

<i
data-lucide="save"
class="h-4 w-4"
></i>

Save Permissions

</button>

</div>

</section>

</form>

</main>


<?php
require_once 'includes/footer.php';
?>


</div>


<script src="../assets/js/app.js"></script>


<script>

const businessSelect =
document.getElementById(
    'business_id'
);


const branchSelect =
document.getElementById(
    'branch_id'
);


const userSelect =
document.getElementById(
    'user_id'
);


const permissionContainer =
document.getElementById(
    'permissionContainer'
);


const permissionCheckboxes =
document.querySelectorAll(
    '.permission-checkbox'
);


const saveButton =
document.getElementById(
    'saveButton'
);


const selectAllButton =
document.getElementById(
    'selectAllPermissions'
);


const clearButton =
document.getElementById(
    'clearPermissions'
);


const permissionStatus =
document.getElementById(
    'permissionStatus'
);


const userInfo =
document.getElementById(
    'userInfo'
);


const userInfoName =
document.getElementById(
    'userInfoName'
);


const userInfoMeta =
document.getElementById(
    'userInfoMeta'
);


const selectedCount =
document.getElementById(
    'selectedPermissionCount'
);



function resetPermissions() {

    permissionCheckboxes
    .forEach(
        checkbox => {
            checkbox.checked = false;
        }
    );


    permissionContainer
    .classList.add(
        'pointer-events-none',
        'opacity-40'
    );


    saveButton.disabled = true;

    selectAllButton.disabled = true;

    clearButton.disabled = true;


    userInfo.classList.add(
        'hidden'
    );


    permissionStatus.textContent =
        'Select a user first.';


    updateCount();
}



function enablePermissions() {

    permissionContainer
    .classList.remove(
        'pointer-events-none',
        'opacity-40'
    );


    saveButton.disabled = false;

    selectAllButton.disabled = false;

    clearButton.disabled = false;
}



function updateCount() {

    const total =
        document.querySelectorAll(
            '.permission-checkbox:checked'
        ).length;


    selectedCount.textContent =
        `${total} permissions selected`;
}



/*
|--------------------------------------------------------------------------
| BUSINESS
|--------------------------------------------------------------------------
*/

businessSelect
.addEventListener(
    'change',
    async function () {

        const businessId =
            this.value;


        branchSelect.disabled =
            true;


        branchSelect.innerHTML =
            '<option value="">Loading branches...</option>';


        userSelect.disabled =
            true;


        userSelect.innerHTML =
            '<option value="">Select Branch First</option>';


        resetPermissions();


        if (!businessId) {

            branchSelect.innerHTML =
                '<option value="">Select Business First</option>';

            return;
        }


        try {

            const response =
                await fetch(
                    `roles.php?ajax=branches&business_id=${encodeURIComponent(businessId)}`
                );


            const result =
                await response.json();


            if (!result.success) {

                throw new Error(
                    result.message
                );
            }


            branchSelect.innerHTML =
                '<option value="">Select Branch</option>';


            result.data.forEach(
                branch => {

                    const option =
                        document.createElement(
                            'option'
                        );


                    option.value =
                        branch.id;


                    option.textContent =
                        branch.branch_name +
                        (
                            Number(
                                branch.is_main
                            ) === 1
                            ? ' (Main Branch)'
                            : ''
                        );


                    branchSelect
                        .appendChild(
                            option
                        );
                }
            );


            branchSelect.disabled =
                false;


            if (
                result.data.length === 1
            ) {

                branchSelect.value =
                    result.data[0].id;


                branchSelect.dispatchEvent(
                    new Event(
                        'change'
                    )
                );
            }


        } catch (error) {

            branchSelect.innerHTML =
                '<option value="">Unable to load branches</option>';

            alert(error.message);
        }
    }
);



/*
|--------------------------------------------------------------------------
| BRANCH
|--------------------------------------------------------------------------
*/

branchSelect
.addEventListener(
    'change',
    async function () {

        const businessId =
            businessSelect.value;

        const branchId =
            this.value;


        userSelect.disabled =
            true;


        userSelect.innerHTML =
            '<option value="">Loading users...</option>';


        resetPermissions();


        if (
            !businessId ||
            !branchId
        ) {

            userSelect.innerHTML =
                '<option value="">Select Branch First</option>';

            return;
        }


        try {

            const response =
                await fetch(
                    `roles.php?ajax=users&business_id=${encodeURIComponent(businessId)}&branch_id=${encodeURIComponent(branchId)}`
                );


            const result =
                await response.json();


            if (!result.success) {

                throw new Error(
                    result.message
                );
            }


            userSelect.innerHTML =
                '<option value="">Select User</option>';


            if (
                result.data.length === 0
            ) {

                userSelect.innerHTML =
                    '<option value="">No users found</option>';

                return;
            }


            result.data.forEach(
                user => {

                    const option =
                        document.createElement(
                            'option'
                        );


                    option.value =
                        user.id;


                    option.textContent =
                        `${user.full_name} - ${user.role_name}`;


                    userSelect
                        .appendChild(
                            option
                        );
                }
            );


            userSelect.disabled =
                false;


        } catch (error) {

            userSelect.innerHTML =
                '<option value="">Unable to load users</option>';

            alert(error.message);
        }
    }
);



/*
|--------------------------------------------------------------------------
| USER
|--------------------------------------------------------------------------
*/

userSelect
.addEventListener(
    'change',
    async function () {

        resetPermissions();


        const businessId =
            businessSelect.value;

        const branchId =
            branchSelect.value;

        const userId =
            this.value;


        if (
            !businessId ||
            !branchId ||
            !userId
        ) {
            return;
        }


        permissionStatus.textContent =
            'Loading permissions...';


        try {

            const response =
                await fetch(
                    `roles.php?ajax=permissions&business_id=${encodeURIComponent(businessId)}&branch_id=${encodeURIComponent(branchId)}&user_id=${encodeURIComponent(userId)}`
                );


            const result =
                await response.json();


            if (!result.success) {

                throw new Error(
                    result.message
                );
            }


            const assigned =
                result.data.permissions
                    .map(Number);


            permissionCheckboxes
            .forEach(
                checkbox => {

                    checkbox.checked =
                        assigned.includes(
                            Number(
                                checkbox.value
                            )
                        );
                }
            );


            userInfoName.textContent =
                result.data.user.full_name;


            userInfoMeta.textContent =
                `${result.data.user.role_name} • ${result.data.user.username} • ${result.data.user.status}`;


            userInfo.classList.remove(
                'hidden'
            );


            permissionStatus.textContent =
                'Choose View, Add, Update and Delete for each table.';


            enablePermissions();

            updateCount();


        } catch (error) {

            resetPermissions();

            alert(error.message);
        }
    }
);



/*
|--------------------------------------------------------------------------
| INDIVIDUAL CHECKBOX
|--------------------------------------------------------------------------
*/

permissionCheckboxes
.forEach(
    checkbox => {

        checkbox.addEventListener(
            'change',
            updateCount
        );
    }
);



/*
|--------------------------------------------------------------------------
| MODULE TOGGLE
|--------------------------------------------------------------------------
*/

document
.querySelectorAll(
    '.moduleToggle'
)
.forEach(
    button => {

        button.addEventListener(
            'click',
            function () {

                const module =
                    this.closest(
                        '.permissionModule'
                    );


                const boxes =
                    module.querySelectorAll(
                        '.permission-checkbox'
                    );


                const allChecked =
                    Array.from(
                        boxes
                    ).every(
                        checkbox =>
                            checkbox.checked
                    );


                boxes.forEach(
                    checkbox => {

                        checkbox.checked =
                            !allChecked;
                    }
                );


                this.textContent =
                    allChecked
                    ? 'Select module'
                    : 'Clear module';


                updateCount();
            }
        );
    }
);



/*
|--------------------------------------------------------------------------
| SELECT ALL
|--------------------------------------------------------------------------
*/

selectAllButton
.addEventListener(
    'click',
    function () {

        permissionCheckboxes
        .forEach(
            checkbox => {
                checkbox.checked = true;
            }
        );


        updateCount();
    }
);



/*
|--------------------------------------------------------------------------
| CLEAR
|--------------------------------------------------------------------------
*/

clearButton
.addEventListener(
    'click',
    function () {

        permissionCheckboxes
        .forEach(
            checkbox => {
                checkbox.checked = false;
            }
        );


        updateCount();
    }
);



if (
    typeof lucide !==
    'undefined'
) {

    lucide.createIcons();
}

</script>

</body>

</html>