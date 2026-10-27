<?php

/*
|--------------------------------------------------------------------------
| PERMISSIONS CONFIG
|--------------------------------------------------------------------------
| Handles:
| - Permission tables
| - Default permissions
| - Role permissions
| - User-specific overrides
| - Permission helper functions
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/database.php';


/*
|--------------------------------------------------------------------------
| CREATE TABLES IF THEY DO NOT EXIST
|--------------------------------------------------------------------------
*/

$conn->exec("
    CREATE TABLE IF NOT EXISTS permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        permission_key VARCHAR(100) NOT NULL UNIQUE,
        permission_name VARCHAR(150) NOT NULL,
        module_name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");


$conn->exec("
    CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INT NOT NULL,
        permission_id INT NOT NULL,

        PRIMARY KEY (role_id, permission_id),

        CONSTRAINT fk_role_permissions_role
            FOREIGN KEY (role_id)
            REFERENCES roles(id)
            ON DELETE CASCADE,

        CONSTRAINT fk_role_permissions_permission
            FOREIGN KEY (permission_id)
            REFERENCES permissions(id)
            ON DELETE CASCADE

    ) ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");


$conn->exec("
    CREATE TABLE IF NOT EXISTS user_permissions (
        user_id INT NOT NULL,
        permission_id INT NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 1,

        PRIMARY KEY (user_id, permission_id),

        CONSTRAINT fk_user_permissions_user
            FOREIGN KEY (user_id)
            REFERENCES users(id)
            ON DELETE CASCADE,

        CONSTRAINT fk_user_permissions_permission
            FOREIGN KEY (permission_id)
            REFERENCES permissions(id)
            ON DELETE CASCADE

    ) ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");


/*
|--------------------------------------------------------------------------
| DEFAULT PERMISSIONS
|--------------------------------------------------------------------------
*/

$defaultPermissions = [

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    ['dashboard.view', 'View Dashboard', 'Dashboard'],


    /*
    |--------------------------------------------------------------------------
    | Inventory
    |--------------------------------------------------------------------------
    */

    ['categories.view', 'View Categories', 'Inventory'],
    ['categories.create', 'Add Categories', 'Inventory'],
    ['categories.edit', 'Edit Categories', 'Inventory'],

    ['units.view', 'View Units', 'Inventory'],
    ['units.create', 'Add Units', 'Inventory'],
    ['units.edit', 'Edit Units', 'Inventory'],

    ['products.view', 'View Products', 'Inventory'],
    ['products.create', 'Add Products', 'Inventory'],
    ['products.edit', 'Edit Products', 'Inventory'],
    ['products.price', 'Update Product Prices', 'Inventory'],

    ['stock.view', 'View Stock', 'Inventory'],
    ['stock.adjust', 'Adjust Stock', 'Inventory'],
    ['stock.transfer', 'Transfer Stock', 'Inventory'],

    ['batches.view', 'View Batch & Expiry', 'Inventory'],


    /*
    |--------------------------------------------------------------------------
    | Trading
    |--------------------------------------------------------------------------
    */

    ['purchases.view', 'View Purchases', 'Trading'],
    ['purchases.create', 'Add Purchases', 'Trading'],
    ['purchases.edit', 'Edit Purchases', 'Trading'],

    ['purchase_returns.view', 'View Purchase Returns', 'Trading'],
    ['purchase_returns.create', 'Create Purchase Return', 'Trading'],

    ['sales.view', 'View Sales', 'Trading'],
    ['sales.create', 'Create Sales', 'Trading'],
    ['sales.edit', 'Edit Sales', 'Trading'],
    ['sales.credit', 'Create Credit Sales', 'Trading'],

    ['sales_returns.view', 'View Sales Returns', 'Trading'],
    ['sales_returns.create', 'Create Sales Return', 'Trading'],


    /*
    |--------------------------------------------------------------------------
    | Parties
    |--------------------------------------------------------------------------
    */

    ['customers.view', 'View Customers', 'Parties'],
    ['customers.manage', 'Manage Customers', 'Parties'],
    ['customers.debt', 'Manage Customer Debts', 'Parties'],

    ['suppliers.view', 'View Suppliers', 'Parties'],
    ['suppliers.manage', 'Manage Suppliers', 'Parties'],
    ['suppliers.debt', 'Manage Supplier Debts', 'Parties'],


    /*
    |--------------------------------------------------------------------------
    | Finance
    |--------------------------------------------------------------------------
    */

    ['expenses.view', 'View Expenses', 'Finance'],
    ['expenses.manage', 'Manage Expenses', 'Finance'],

    ['payroll.view', 'View Payroll', 'Finance'],
    ['payroll.manage', 'Manage Payroll', 'Finance'],

    ['assets.view', 'View Fixed Assets', 'Finance'],
    ['assets.manage', 'Manage Fixed Assets', 'Finance'],

    ['depreciation.view', 'View Depreciation', 'Finance'],
    ['depreciation.manage', 'Manage Depreciation', 'Finance'],

    ['accounting.view', 'View Accounting', 'Finance'],
    ['accounting.manage', 'Manage Accounting', 'Finance'],

    ['cash_accounts.view', 'View Cash / Bank / EVC', 'Finance'],
    ['cash_accounts.manage', 'Manage Cash / Bank / EVC', 'Finance'],

    ['zakat.view', 'View Zakat', 'Finance'],
    ['zakat.manage', 'Manage Zakat', 'Finance'],


    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    ['reports.view', 'View Reports', 'Reports'],
    ['reports.sales', 'View Sales Reports', 'Reports'],
    ['reports.purchases', 'View Purchase Reports', 'Reports'],
    ['reports.stock', 'View Stock Reports', 'Reports'],
    ['reports.financial', 'View Financial Reports', 'Reports'],


    /*
    |--------------------------------------------------------------------------
    | Management
    |--------------------------------------------------------------------------
    */

    ['branches.view', 'View Branches', 'Management'],
    ['branches.manage', 'Manage Branches', 'Management'],

    ['users.view', 'View Users', 'Management'],
    ['users.manage', 'Manage Users', 'Management'],

    ['permissions.manage', 'Manage User Permissions', 'Management'],

    ['audit.view', 'View Audit Trail', 'Management'],

    ['support.view', 'View Support / Chat', 'Management'],

    ['settings.view', 'View Settings', 'Management'],
    ['settings.manage', 'Manage Settings', 'Management'],
];


/*
|--------------------------------------------------------------------------
| INSERT / UPDATE DEFAULT PERMISSIONS
|--------------------------------------------------------------------------
*/

$seedPermission = $conn->prepare("
    INSERT INTO permissions (
        permission_key,
        permission_name,
        module_name
    )
    VALUES (?, ?, ?)

    ON DUPLICATE KEY UPDATE
        permission_name = VALUES(permission_name),
        module_name = VALUES(module_name)
");


foreach ($defaultPermissions as $permission) {
    $seedPermission->execute($permission);
}


/*
|--------------------------------------------------------------------------
| GET ROLE PERMISSIONS
|--------------------------------------------------------------------------
*/

function getRolePermissions(int $roleId): array
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT p.permission_key
        FROM role_permissions rp

        INNER JOIN permissions p
            ON p.id = rp.permission_id

        WHERE rp.role_id = ?
    ");

    $stmt->execute([$roleId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}


/*
|--------------------------------------------------------------------------
| GET USER OVERRIDES
|--------------------------------------------------------------------------
*/

function getUserPermissionOverrides(int $userId): array
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT
            p.permission_key,
            up.allowed

        FROM user_permissions up

        INNER JOIN permissions p
            ON p.id = up.permission_id

        WHERE up.user_id = ?
    ");

    $stmt->execute([$userId]);

    $result = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

        $result[$row['permission_key']] =
            (int)$row['allowed'];
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| CHECK PERMISSION
|--------------------------------------------------------------------------
|
| Priority:
|
| 1. Super Admin = full access
| 2. User-specific override
| 3. Role permission
|
|--------------------------------------------------------------------------
*/

function hasPermission(string $permissionKey): bool
{
    global $conn;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return false;
    }


    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.role_id,
            r.role_name

        FROM users u

        INNER JOIN roles r
            ON r.id = u.role_id

        WHERE u.id = ?

        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$user) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | SUPER ADMIN
    |--------------------------------------------------------------------------
    */

    if ($user['role_name'] === 'Super Admin') {
        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | FIND PERMISSION
    |--------------------------------------------------------------------------
    */

    $permissionStmt = $conn->prepare("
        SELECT id
        FROM permissions
        WHERE permission_key = ?
        LIMIT 1
    ");

    $permissionStmt->execute([$permissionKey]);

    $permissionId =
        (int)$permissionStmt->fetchColumn();


    if ($permissionId <= 0) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | USER OVERRIDE
    |--------------------------------------------------------------------------
    */

    $userPermissionStmt = $conn->prepare("
        SELECT allowed

        FROM user_permissions

        WHERE user_id = ?
        AND permission_id = ?

        LIMIT 1
    ");

    $userPermissionStmt->execute([
        $userId,
        $permissionId
    ]);


    $userPermission =
        $userPermissionStmt->fetchColumn();


    if ($userPermission !== false) {

        return (int)$userPermission === 1;
    }


    /*
    |--------------------------------------------------------------------------
    | ROLE PERMISSION
    |--------------------------------------------------------------------------
    */

    $rolePermissionStmt = $conn->prepare("
        SELECT COUNT(*)

        FROM role_permissions

        WHERE role_id = ?
        AND permission_id = ?
    ");

    $rolePermissionStmt->execute([
        $user['role_id'],
        $permissionId
    ]);


    return (int)$rolePermissionStmt->fetchColumn() > 0;
}


/*
|--------------------------------------------------------------------------
| REQUIRE PERMISSION
|--------------------------------------------------------------------------
*/

function requirePermission(string $permissionKey): void
{
    if (!hasPermission($permissionKey)) {

        http_response_code(403);

        exit('Access denied.');
    }
}


/*
|--------------------------------------------------------------------------
| CHECK ANY PERMISSION
|--------------------------------------------------------------------------
|
| Useful for sidebar groups.
|
|--------------------------------------------------------------------------
*/

function hasAnyPermission(array $permissions): bool
{
    foreach ($permissions as $permission) {

        if (hasPermission($permission)) {
            return true;
        }
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| CHECK ALL PERMISSIONS
|--------------------------------------------------------------------------
*/

function hasAllPermissions(array $permissions): bool
{
    foreach ($permissions as $permission) {

        if (!hasPermission($permission)) {
            return false;
        }
    }

    return true;
}