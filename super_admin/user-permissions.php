<?php

session_start();

require_once '../config/database.php';
require_once '../config/auth.php';

requireSuperAdmin();
/*
|--------------------------------------------------------------------------
| AUTH CHECK
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
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


/*
|--------------------------------------------------------------------------
| GET USER ID
|--------------------------------------------------------------------------
*/

$userId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($userId <= 0) {
    header('Location: users.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| GET USER INFORMATION
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.status,
        u.business_id,
        u.branch_id,
        u.role_id,

        r.role_name,

        b.business_name,
        br.branch_name

    FROM users u

    INNER JOIN roles r
        ON r.id = u.role_id

    LEFT JOIN businesses b
        ON b.id = u.business_id

    LEFT JOIN branches br
        ON br.id = u.branch_id

    WHERE u.id = ?

    LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch();

if (!$user) {
    header('Location: users.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| SUPER ADMIN USER PROTECTION
|--------------------------------------------------------------------------
*/

$isSuperAdminUser = $user['role_name'] === 'Super Admin';


/*
|--------------------------------------------------------------------------
| SAVE PERMISSIONS
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die('Invalid CSRF token.');
    }

    if ($isSuperAdminUser) {

        $message = 'Super Admin permissions lama beddeli karo.';
        $messageType = 'error';

    } else {

        $submitted = $_POST['permissions'] ?? [];

        try {

            $conn->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | DELETE OLD USER OVERRIDES
            |--------------------------------------------------------------------------
            */

            $deleteStmt = $conn->prepare("
                DELETE FROM user_permissions
                WHERE user_id = ?
            ");

            $deleteStmt->execute([$userId]);


            /*
            |--------------------------------------------------------------------------
            | GET ALL PERMISSIONS
            |--------------------------------------------------------------------------
            */

            $permissionsStmt = $conn->query("
                SELECT id
                FROM permissions
                ORDER BY id ASC
            ");

            $allPermissionIds = $permissionsStmt->fetchAll(
                PDO::FETCH_COLUMN
            );


            /*
            |--------------------------------------------------------------------------
            | GET ROLE DEFAULT PERMISSIONS
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

            $rolePermissionIds = array_map(
                'intval',
                $roleStmt->fetchAll(PDO::FETCH_COLUMN)
            );


            /*
            |--------------------------------------------------------------------------
            | CREATE USER OVERRIDES ONLY WHEN DIFFERENT FROM ROLE
            |--------------------------------------------------------------------------
            */

            $insertStmt = $conn->prepare("
                INSERT INTO user_permissions (
                    user_id,
                    permission_id,
                    allowed
                )
                VALUES (?, ?, ?)
            ");

            foreach ($allPermissionIds as $permissionId) {

                $permissionId = (int) $permissionId;

                $roleHasPermission = in_array(
                    $permissionId,
                    $rolePermissionIds,
                    true
                );

                $userSelectedPermission = isset(
                    $submitted[$permissionId]
                );


                /*
                |--------------------------------------------------------------------------
                | ROLE HAS IT, USER REMOVED IT
                |--------------------------------------------------------------------------
                */

                if (
                    $roleHasPermission &&
                    !$userSelectedPermission
                ) {

                    $insertStmt->execute([
                        $userId,
                        $permissionId,
                        0
                    ]);

                }


                /*
                |--------------------------------------------------------------------------
                | ROLE DOES NOT HAVE IT, USER ADDED IT
                |--------------------------------------------------------------------------
                */

                elseif (
                    !$roleHasPermission &&
                    $userSelectedPermission
                ) {

                    $insertStmt->execute([
                        $userId,
                        $permissionId,
                        1
                    ]);

                }
            }

            $conn->commit();

            $message = 'User permissions successfully updated.';
            $messageType = 'success';

        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = 'Permissions lama kaydin karin: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}


/*
|--------------------------------------------------------------------------
| GET ROLE PERMISSIONS
|--------------------------------------------------------------------------
*/

$rolePermissionStmt = $conn->prepare("
    SELECT permission_id
    FROM role_permissions
    WHERE role_id = ?
");

$rolePermissionStmt->execute([
    $user['role_id']
]);

$rolePermissionIds = array_map(
    'intval',
    $rolePermissionStmt->fetchAll(PDO::FETCH_COLUMN)
);


/*
|--------------------------------------------------------------------------
| GET USER OVERRIDES
|--------------------------------------------------------------------------
*/

$userPermissionStmt = $conn->prepare("
    SELECT
        permission_id,
        allowed
    FROM user_permissions
    WHERE user_id = ?
");

$userPermissionStmt->execute([$userId]);

$userOverrides = [];

foreach ($userPermissionStmt->fetchAll() as $override) {

    $userOverrides[
        (int) $override['permission_id']
    ] = (int) $override['allowed'];
}


/*
|--------------------------------------------------------------------------
| GET ALL PERMISSIONS
|--------------------------------------------------------------------------
*/

$permissionsStmt = $conn->query("
    SELECT
        id,
        permission_key,
        permission_name,
        module_name
    FROM permissions
    ORDER BY
        module_name ASC,
        permission_name ASC
");

$permissions = $permissionsStmt->fetchAll();


/*
|--------------------------------------------------------------------------
| GROUP PERMISSIONS BY MODULE
|--------------------------------------------------------------------------
*/

$permissionGroups = [];

foreach ($permissions as $permission) {

    $module = $permission['module_name'] ?: 'General';

    if (!isset($permissionGroups[$module])) {
        $permissionGroups[$module] = [];
    }

    $permissionGroups[$module][] = $permission;
}


/*
|--------------------------------------------------------------------------
| CALCULATE EFFECTIVE PERMISSIONS
|--------------------------------------------------------------------------
*/

function effectivePermission(
    int $permissionId,
    array $rolePermissionIds,
    array $userOverrides,
    bool $isSuperAdminUser
): bool {

    if ($isSuperAdminUser) {
        return true;
    }

    if (array_key_exists(
        $permissionId,
        $userOverrides
    )) {

        return $userOverrides[$permissionId] === 1;
    }

    return in_array(
        $permissionId,
        $rolePermissionIds,
        true
    );
}


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$totalPermissions = count($permissions);

$effectiveCount = 0;
$addedCount = 0;
$removedCount = 0;

foreach ($permissions as $permission) {

    $permissionId = (int) $permission['id'];

    if (
        effectivePermission(
            $permissionId,
            $rolePermissionIds,
            $userOverrides,
            $isSuperAdminUser
        )
    ) {
        $effectiveCount++;
    }

    if (
        isset($userOverrides[$permissionId]) &&
        $userOverrides[$permissionId] === 1
    ) {
        $addedCount++;
    }

    if (
        isset($userOverrides[$permissionId]) &&
        $userOverrides[$permissionId] === 0
    ) {
        $removedCount++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>User Permissions</title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
    >

    <script src="https://cdn.tailwindcss.com"></script>

    <script src="https://unpkg.com/lucide@latest"></script>

    <link
        rel="stylesheet"
        href="../assets/css/app.css"
    >

</head>

<body class="bg-slate-100 text-slate-800">

<div class="min-h-screen">

    <?php require 'includes/sidebar.php'; ?>

    <div class="lg:pl-72">

        <?php require 'includes/header.php'; ?>


        <main class="page-enter p-4 pb-28 sm:p-6 sm:pb-32 lg:p-8 lg:pb-32">

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
                            text-sm
                            text-slate-500
                        "
                    >

                        <a
                            href="users.php"
                            class="
                                hover:text-blue-600
                                transition
                            "
                        >
                            Users
                        </a>

                        <i
                            data-lucide="chevron-right"
                            class="h-4 w-4"
                        ></i>

                        <span>
                            Permissions
                        </span>

                    </div>

                    <h1
                        class="
                            text-2xl
                            font-bold
                            tracking-tight
                            text-slate-900
                            sm:text-3xl
                        "
                    >
                        User Permissions
                    </h1>

                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        Manage individual permissions for
                        <?= htmlspecialchars($user['full_name']) ?>.
                    </p>

                </div>


                <div class="flex flex-wrap gap-3">

                    <a
                        href="roles.php"
                        class="
                            inline-flex
                            items-center
                            gap-2
                            rounded-xl
                            border
                            border-slate-200
                            bg-white
                            px-4
                            py-2.5
                            text-sm
                            font-semibold
                            text-slate-700
                            shadow-sm
                            transition
                            hover:bg-slate-50
                        "
                    >
                        <i
                            data-lucide="shield"
                            class="h-4 w-4"
                        ></i>

                        Roles
                    </a>

                    <a
                        href="users.php"
                        class="
                            inline-flex
                            items-center
                            gap-2
                            rounded-xl
                            bg-slate-900
                            px-4
                            py-2.5
                            text-sm
                            font-semibold
                            text-white
                            shadow-sm
                            transition
                            hover:bg-slate-800
                        "
                    >
                        <i
                            data-lucide="arrow-left"
                            class="h-4 w-4"
                        ></i>

                        Back to Users
                    </a>

                </div>

            </div>


            <?php if ($message): ?>

                <div
                    class="
                        mb-6
                        rounded-2xl
                        border
                        px-4
                        py-4
                        text-sm
                        font-medium

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

                        <span>
                            <?= htmlspecialchars($message) ?>
                        </span>

                    </div>

                </div>

            <?php endif; ?>


            <!-- USER PROFILE -->

            <div
                class="
                    mb-6
                    overflow-hidden
                    rounded-3xl
                    border
                    border-slate-200
                    bg-white
                    shadow-sm
                "
            >

                <div
                    class="
                        bg-gradient-to-r
                        from-slate-900
                        via-slate-800
                        to-blue-900
                        p-6
                        text-white
                    "
                >

                    <div
                        class="
                            flex
                            flex-col
                            gap-5
                            md:flex-row
                            md:items-center
                            md:justify-between
                        "
                    >

                        <div
                            class="
                                flex
                                items-center
                                gap-4
                            "
                        >

                            <div
                                class="
                                    flex
                                    h-16
                                    w-16
                                    shrink-0
                                    items-center
                                    justify-center
                                    rounded-2xl
                                    bg-white/10
                                    text-xl
                                    font-bold
                                    ring-1
                                    ring-white/20
                                "
                            >

                                <?php
                                $initials = '';

                                foreach (
                                    explode(
                                        ' ',
                                        $user['full_name']
                                    ) as $namePart
                                ) {
                                    if ($namePart !== '') {
                                        $initials .= strtoupper(
                                            substr($namePart, 0, 1)
                                        );
                                    }
                                }

                                echo htmlspecialchars(
                                    substr($initials, 0, 2)
                                );
                                ?>

                            </div>


                            <div>

                                <h2
                                    class="
                                        text-xl
                                        font-bold
                                    "
                                >
                                    <?= htmlspecialchars($user['full_name']) ?>
                                </h2>

                                <p
                                    class="
                                        mt-1
                                        text-sm
                                        text-slate-300
                                    "
                                >
                                    @<?= htmlspecialchars($user['username']) ?>
                                </p>


                                <div
                                    class="
                                        mt-3
                                        flex
                                        flex-wrap
                                        gap-2
                                    "
                                >

                                    <span
                                        class="
                                            rounded-full
                                            bg-blue-500/20
                                            px-3
                                            py-1
                                            text-xs
                                            font-semibold
                                            text-blue-100
                                            ring-1
                                            ring-blue-300/20
                                        "
                                    >
                                        <?= htmlspecialchars($user['role_name']) ?>
                                    </span>


                                    <span
                                        class="
                                            rounded-full
                                            bg-white/10
                                            px-3
                                            py-1
                                            text-xs
                                            font-medium
                                            text-slate-200
                                        "
                                    >
                                        <?= htmlspecialchars(
                                            $user['business_name']
                                            ?? 'System Wide'
                                        ) ?>
                                    </span>


                                    <span
                                        class="
                                            rounded-full
                                            bg-white/10
                                            px-3
                                            py-1
                                            text-xs
                                            font-medium
                                            text-slate-200
                                        "
                                    >
                                        <?= htmlspecialchars(
                                            $user['branch_name']
                                            ?? 'All Branches'
                                        ) ?>
                                    </span>

                                </div>

                            </div>

                        </div>


                        <div>

                            <?php if ($user['status'] === 'Active'): ?>

                                <span
                                    class="
                                        inline-flex
                                        items-center
                                        gap-2
                                        rounded-full
                                        bg-emerald-400/10
                                        px-3
                                        py-1.5
                                        text-sm
                                        font-semibold
                                        text-emerald-200
                                        ring-1
                                        ring-emerald-300/20
                                    "
                                >
                                    <span
                                        class="
                                            h-2
                                            w-2
                                            rounded-full
                                            bg-emerald-400
                                        "
                                    ></span>

                                    Active
                                </span>

                            <?php else: ?>

                                <span
                                    class="
                                        inline-flex
                                        items-center
                                        gap-2
                                        rounded-full
                                        bg-red-400/10
                                        px-3
                                        py-1.5
                                        text-sm
                                        font-semibold
                                        text-red-200
                                        ring-1
                                        ring-red-300/20
                                    "
                                >
                                    <span
                                        class="
                                            h-2
                                            w-2
                                            rounded-full
                                            bg-red-400
                                        "
                                    ></span>

                                    <?= htmlspecialchars($user['status']) ?>
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>


            <!-- STATISTICS -->

            <div
                class="
                    mb-6
                    grid
                    grid-cols-1
                    gap-4
                    sm:grid-cols-2
                    xl:grid-cols-4
                "
            >

                <div
                    class="
                        dashboard-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
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
                                    text-sm
                                    font-medium
                                    text-slate-500
                                "
                            >
                                Total Permissions
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-slate-900
                                "
                            >
                                <?= $totalPermissions ?>
                            </p>

                        </div>

                        <div
                            class="
                                flex
                                h-12
                                w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-slate-100
                                text-slate-700
                            "
                        >
                            <i
                                data-lucide="key-round"
                                class="h-6 w-6"
                            ></i>
                        </div>

                    </div>

                </div>


                <div
                    class="
                        dashboard-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
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
                                    text-sm
                                    font-medium
                                    text-slate-500
                                "
                            >
                                Effective Access
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-emerald-600
                                "
                            >
                                <?= $effectiveCount ?>
                            </p>

                        </div>

                        <div
                            class="
                                flex
                                h-12
                                w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-emerald-50
                                text-emerald-600
                            "
                        >
                            <i
                                data-lucide="shield-check"
                                class="h-6 w-6"
                            ></i>
                        </div>

                    </div>

                </div>


                <div
                    class="
                        dashboard-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
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
                                    text-sm
                                    font-medium
                                    text-slate-500
                                "
                            >
                                Extra Access
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-blue-600
                                "
                            >
                                <?= $addedCount ?>
                            </p>

                        </div>

                        <div
                            class="
                                flex
                                h-12
                                w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-blue-50
                                text-blue-600
                            "
                        >
                            <i
                                data-lucide="plus-circle"
                                class="h-6 w-6"
                            ></i>
                        </div>

                    </div>

                </div>


                <div
                    class="
                        dashboard-card
                        rounded-2xl
                        border
                        border-slate-200
                        bg-white
                        p-5
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
                                    text-sm
                                    font-medium
                                    text-slate-500
                                "
                            >
                                Removed Access
                            </p>

                            <p
                                class="
                                    mt-2
                                    text-3xl
                                    font-bold
                                    text-red-600
                                "
                            >
                                <?= $removedCount ?>
                            </p>

                        </div>

                        <div
                            class="
                                flex
                                h-12
                                w-12
                                items-center
                                justify-center
                                rounded-2xl
                                bg-red-50
                                text-red-600
                            "
                        >
                            <i
                                data-lucide="minus-circle"
                                class="h-6 w-6"
                            ></i>
                        </div>

                    </div>

                </div>

            </div>


            <?php if ($isSuperAdminUser): ?>

                <div
                    class="
                        mb-6
                        rounded-2xl
                        border
                        border-blue-200
                        bg-blue-50
                        p-5
                        text-blue-800
                    "
                >

                    <div
                        class="
                            flex
                            items-start
                            gap-3
                        "
                    >

                        <i
                            data-lucide="shield-check"
                            class="
                                mt-0.5
                                h-6
                                w-6
                                shrink-0
                            "
                        ></i>

                        <div>

                            <h3 class="font-bold">
                                Super Admin Full Access
                            </h3>

                            <p
                                class="
                                    mt-1
                                    text-sm
                                    text-blue-700
                                "
                            >
                                Super Admin wuxuu leeyahay dhammaan
                                permissions-ka system-ka. Individual
                                overrides lama beddeli karo.
                            </p>

                        </div>

                    </div>

                </div>

            <?php endif; ?>


            <!-- PERMISSIONS -->

            <form method="POST" id="permissionsForm">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                >


                <div
                    class="
                        overflow-hidden
                        rounded-3xl
                        border
                        border-slate-200
                        bg-white
                        shadow-sm
                    "
                >

                    <div
                        class="
                            border-b
                            border-slate-200
                            p-5
                            sm:p-6
                        "
                    >

                        <div
                            class="
                                flex
                                flex-col
                                gap-4
                                lg:flex-row
                                lg:items-center
                                lg:justify-between
                            "
                        >

                            <div>

                                <h2
                                    class="
                                        text-lg
                                        font-bold
                                        text-slate-900
                                    "
                                >
                                    Permission Access
                                </h2>

                                <p
                                    class="
                                        mt-1
                                        text-sm
                                        text-slate-500
                                    "
                                >
                                    Checkbox-ka checked yahay =
                                    permission-ku user-ka wuu shaqaynayaa.
                                </p>

                            </div>


                            <div
                                class="
                                    flex
                                    flex-col
                                    gap-3
                                    sm:flex-row
                                "
                            >

                                <div
                                    class="relative"
                                >

                                    <i
                                        data-lucide="search"
                                        class="
                                            absolute
                                            left-3
                                            top-1/2
                                            h-4
                                            w-4
                                            -translate-y-1/2
                                            text-slate-400
                                        "
                                    ></i>

                                    <input
                                        type="text"
                                        id="permissionSearch"
                                        placeholder="Search permissions..."
                                        class="
                                            w-full
                                            rounded-xl
                                            border
                                            border-slate-200
                                            bg-white
                                            py-2.5
                                            pl-10
                                            pr-4
                                            text-sm
                                            outline-none
                                            transition
                                            focus:border-blue-500
                                            focus:ring-4
                                            focus:ring-blue-500/10
                                            sm:w-64
                                        "
                                    >

                                </div>


                                <?php if (!$isSuperAdminUser): ?>

                                    <button
                                        type="button"
                                        id="selectAllButton"
                                        class="
                                            inline-flex
                                            items-center
                                            justify-center
                                            gap-2
                                            rounded-xl
                                            border
                                            border-slate-200
                                            bg-white
                                            px-4
                                            py-2.5
                                            text-sm
                                            font-semibold
                                            text-slate-700
                                            transition
                                            hover:bg-slate-50
                                        "
                                    >
                                        <i
                                            data-lucide="check-check"
                                            class="h-4 w-4"
                                        ></i>

                                        Select All
                                    </button>


                                    <button
                                        type="button"
                                        id="clearAllButton"
                                        class="
                                            inline-flex
                                            items-center
                                            justify-center
                                            gap-2
                                            rounded-xl
                                            border
                                            border-slate-200
                                            bg-white
                                            px-4
                                            py-2.5
                                            text-sm
                                            font-semibold
                                            text-slate-700
                                            transition
                                            hover:bg-slate-50
                                        "
                                    >
                                        <i
                                            data-lucide="square"
                                            class="h-4 w-4"
                                        ></i>

                                        Clear
                                    </button>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>


                    <div class="p-4 sm:p-6">

                        <div
                            id="permissionModules"
                            class="space-y-5"
                        >

                            <?php foreach (
                                $permissionGroups
                                as $moduleName => $modulePermissions
                            ): ?>

                                <section
                                    class="
                                        permission-module
                                        overflow-hidden
                                        rounded-2xl
                                        border
                                        border-slate-200
                                        bg-slate-50/50
                                    "
                                    data-module="<?=
                                        htmlspecialchars(
                                            strtolower($moduleName)
                                        )
                                    ?>"
                                >

                                    <div
                                        class="
                                            flex
                                            flex-col
                                            gap-3
                                            border-b
                                            border-slate-200
                                            bg-slate-50
                                            px-5
                                            py-4
                                            sm:flex-row
                                            sm:items-center
                                            sm:justify-between
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
                                                    h-10
                                                    w-10
                                                    items-center
                                                    justify-center
                                                    rounded-xl
                                                    bg-white
                                                    text-blue-600
                                                    shadow-sm
                                                    ring-1
                                                    ring-slate-200
                                                "
                                            >
                                                <i
                                                    data-lucide="layers-3"
                                                    class="h-5 w-5"
                                                ></i>
                                            </div>

                                            <div>

                                                <h3
                                                    class="
                                                        font-bold
                                                        text-slate-900
                                                    "
                                                >
                                                    <?= htmlspecialchars($moduleName) ?>
                                                </h3>

                                                <p
                                                    class="
                                                        text-xs
                                                        text-slate-500
                                                    "
                                                >
                                                    <?= count($modulePermissions) ?>
                                                    permissions
                                                </p>

                                            </div>

                                        </div>


                                        <?php if (!$isSuperAdminUser): ?>

                                            <button
                                                type="button"
                                                class="
                                                    module-toggle
                                                    inline-flex
                                                    items-center
                                                    gap-2
                                                    self-start
                                                    rounded-lg
                                                    border
                                                    border-slate-200
                                                    bg-white
                                                    px-3
                                                    py-2
                                                    text-xs
                                                    font-semibold
                                                    text-slate-600
                                                    transition
                                                    hover:bg-slate-100
                                                    sm:self-auto
                                                "
                                            >
                                                <i
                                                    data-lucide="check-square"
                                                    class="h-4 w-4"
                                                ></i>

                                                Toggle Module
                                            </button>

                                        <?php endif; ?>

                                    </div>


                                    <div
                                        class="
                                            grid
                                            grid-cols-1
                                            gap-3
                                            p-4
                                            md:grid-cols-2
                                            xl:grid-cols-3
                                        "
                                    >

                                        <?php foreach (
                                            $modulePermissions
                                            as $permission
                                        ):

                                            $permissionId =
                                                (int) $permission['id'];

                                            $roleDefault =
                                                in_array(
                                                    $permissionId,
                                                    $rolePermissionIds,
                                                    true
                                                );

                                            $hasOverride =
                                                array_key_exists(
                                                    $permissionId,
                                                    $userOverrides
                                                );

                                            $effective =
                                                effectivePermission(
                                                    $permissionId,
                                                    $rolePermissionIds,
                                                    $userOverrides,
                                                    $isSuperAdminUser
                                                );

                                            $overrideLabel = '';

                                            if ($isSuperAdminUser) {

                                                $overrideLabel =
                                                    'Full Access';

                                            } elseif ($hasOverride) {

                                                if (
                                                    $userOverrides[
                                                        $permissionId
                                                    ] === 1
                                                ) {
                                                    $overrideLabel =
                                                        'Extra Access';
                                                } else {
                                                    $overrideLabel =
                                                        'Removed';
                                                }

                                            } elseif ($roleDefault) {

                                                $overrideLabel =
                                                    'From Role';

                                            } else {

                                                $overrideLabel =
                                                    'No Access';
                                            }

                                        ?>

                                            <label
                                                class="
                                                    permission-item
                                                    group
                                                    flex
                                                    cursor-pointer
                                                    items-start
                                                    gap-3
                                                    rounded-2xl
                                                    border
                                                    border-slate-200
                                                    bg-white
                                                    p-4
                                                    transition
                                                    hover:border-blue-200
                                                    hover:shadow-sm
                                                "
                                                data-search="<?=
                                                    htmlspecialchars(
                                                        strtolower(
                                                            $permission['permission_name']
                                                            . ' '
                                                            . $permission['permission_key']
                                                            . ' '
                                                            . $moduleName
                                                        )
                                                    )
                                                ?>"
                                            >

                                                <div
                                                    class="
                                                        pt-0.5
                                                    "
                                                >

                                                    <input
                                                        type="checkbox"
                                                        name="permissions[<?= $permissionId ?>]"
                                                        value="1"
                                                        class="
                                                            permission-checkbox
                                                            h-5
                                                            w-5
                                                            rounded
                                                            border-slate-300
                                                            text-blue-600
                                                            focus:ring-blue-500
                                                        "
                                                        <?= $effective ? 'checked' : '' ?>
                                                        <?= $isSuperAdminUser ? 'disabled' : '' ?>
                                                    >

                                                </div>


                                                <div
                                                    class="
                                                        min-w-0
                                                        flex-1
                                                    "
                                                >

                                                    <div
                                                        class="
                                                            flex
                                                            items-start
                                                            justify-between
                                                            gap-3
                                                        "
                                                    >

                                                        <h4
                                                            class="
                                                                text-sm
                                                                font-semibold
                                                                text-slate-800
                                                            "
                                                        >
                                                            <?= htmlspecialchars(
                                                                $permission['permission_name']
                                                            ) ?>
                                                        </h4>


                                                        <?php if (
                                                            $overrideLabel
                                                            === 'Extra Access'
                                                        ): ?>

                                                            <span
                                                                class="
                                                                    shrink-0
                                                                    rounded-full
                                                                    bg-blue-50
                                                                    px-2
                                                                    py-1
                                                                    text-[10px]
                                                                    font-bold
                                                                    uppercase
                                                                    tracking-wide
                                                                    text-blue-600
                                                                "
                                                            >
                                                                Extra
                                                            </span>

                                                        <?php elseif (
                                                            $overrideLabel
                                                            === 'Removed'
                                                        ): ?>

                                                            <span
                                                                class="
                                                                    shrink-0
                                                                    rounded-full
                                                                    bg-red-50
                                                                    px-2
                                                                    py-1
                                                                    text-[10px]
                                                                    font-bold
                                                                    uppercase
                                                                    tracking-wide
                                                                    text-red-600
                                                                "
                                                            >
                                                                Removed
                                                            </span>

                                                        <?php elseif (
                                                            $overrideLabel
                                                            === 'From Role'
                                                        ): ?>

                                                            <span
                                                                class="
                                                                    shrink-0
                                                                    rounded-full
                                                                    bg-emerald-50
                                                                    px-2
                                                                    py-1
                                                                    text-[10px]
                                                                    font-bold
                                                                    uppercase
                                                                    tracking-wide
                                                                    text-emerald-600
                                                                "
                                                            >
                                                                Role
                                                            </span>

                                                        <?php elseif (
                                                            $isSuperAdminUser
                                                        ): ?>

                                                            <span
                                                                class="
                                                                    shrink-0
                                                                    rounded-full
                                                                    bg-violet-50
                                                                    px-2
                                                                    py-1
                                                                    text-[10px]
                                                                    font-bold
                                                                    uppercase
                                                                    tracking-wide
                                                                    text-violet-600
                                                                "
                                                            >
                                                                Full
                                                            </span>

                                                        <?php endif; ?>

                                                    </div>


                                                    <p
                                                        class="
                                                            mt-1
                                                            break-all
                                                            font-mono
                                                            text-xs
                                                            text-slate-400
                                                        "
                                                    >
                                                        <?= htmlspecialchars(
                                                            $permission['permission_key']
                                                        ) ?>
                                                    </p>


                                                    <div
                                                        class="
                                                            mt-3
                                                            flex
                                                            items-center
                                                            gap-2
                                                            text-xs
                                                        "
                                                    >

                                                        <?php if ($roleDefault): ?>

                                                            <span
                                                                class="
                                                                    inline-flex
                                                                    items-center
                                                                    gap-1
                                                                    text-emerald-600
                                                                "
                                                            >
                                                                <i
                                                                    data-lucide="shield"
                                                                    class="h-3.5 w-3.5"
                                                                ></i>

                                                                Role default
                                                            </span>

                                                        <?php else: ?>

                                                            <span
                                                                class="
                                                                    inline-flex
                                                                    items-center
                                                                    gap-1
                                                                    text-slate-400
                                                                "
                                                            >
                                                                <i
                                                                    data-lucide="shield-off"
                                                                    class="h-3.5 w-3.5"
                                                                ></i>

                                                                Not in role
                                                            </span>

                                                        <?php endif; ?>

                                                    </div>

                                                </div>

                                            </label>

                                        <?php endforeach; ?>

                                    </div>

                                </section>

                            <?php endforeach; ?>

                        </div>


                        <div
                            id="noPermissionResults"
                            class="
                                hidden
                                py-16
                                text-center
                            "
                        >

                            <div
                                class="
                                    mx-auto
                                    flex
                                    h-14
                                    w-14
                                    items-center
                                    justify-center
                                    rounded-2xl
                                    bg-slate-100
                                    text-slate-400
                                "
                            >
                                <i
                                    data-lucide="search-x"
                                    class="h-7 w-7"
                                ></i>
                            </div>

                            <h3
                                class="
                                    mt-4
                                    font-bold
                                    text-slate-800
                                "
                            >
                                No permissions found
                            </h3>

                            <p
                                class="
                                    mt-1
                                    text-sm
                                    text-slate-500
                                "
                            >
                                Search-kaaga wax natiijo ah lagama helin.
                            </p>

                        </div>

                    </div>


                    

                </div>

            </form>

            <?php if (!$isSuperAdminUser): ?>
                <button
                    type="submit"
                    form="permissionsForm"
                    id="floatingSavePermissions"
                    class="
                        fixed
                        right-5
                        bottom-5
                        z-[9999]
                        inline-flex
                        items-center
                        justify-center
                        gap-2
                        rounded-2xl
                        bg-blue-600
                        px-6
                        py-3.5
                        text-sm
                        font-bold
                        text-white
                        shadow-2xl
                        ring-4
                        ring-white
                        transition
                        hover:bg-blue-700
                        focus:outline-none
                        focus:ring-blue-200
                        sm:right-7
                        sm:bottom-7
                    "
                >
                    <i data-lucide="save" class="h-5 w-5"></i>
                    Save Permissions
                </button>
            <?php endif; ?>


        </main>


        <?php require 'includes/footer.php'; ?>

    </div>

</div>


<script src="../assets/js/app.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const searchInput =
        document.getElementById('permissionSearch');

    const modules =
        document.querySelectorAll('.permission-module');

    const noResults =
        document.getElementById('noPermissionResults');


    /*
    |--------------------------------------------------------------------------
    | SEARCH PERMISSIONS
    |--------------------------------------------------------------------------
    */

    searchInput?.addEventListener('input', function () {

        const searchValue =
            this.value
                .trim()
                .toLowerCase();

        let totalVisible = 0;

        modules.forEach(function (module) {

            const permissionItems =
                module.querySelectorAll(
                    '.permission-item'
                );

            let moduleVisible = 0;

            permissionItems.forEach(function (item) {

                const searchText =
                    item.dataset.search || '';

                const matched =
                    searchText.includes(
                        searchValue
                    );

                item.classList.toggle(
                    'hidden',
                    !matched
                );

                if (matched) {
                    moduleVisible++;
                    totalVisible++;
                }

            });


            module.classList.toggle(
                'hidden',
                moduleVisible === 0
            );

        });


        noResults?.classList.toggle(
            'hidden',
            totalVisible !== 0
        );

    });


    /*
    |--------------------------------------------------------------------------
    | SELECT ALL
    |--------------------------------------------------------------------------
    */

    document
        .getElementById('selectAllButton')
        ?.addEventListener(
            'click',
            function () {

                document
                    .querySelectorAll(
                        '.permission-checkbox:not(:disabled)'
                    )
                    .forEach(
                        function (checkbox) {
                            checkbox.checked = true;
                        }
                    );

            }
        );


    /*
    |--------------------------------------------------------------------------
    | CLEAR ALL
    |--------------------------------------------------------------------------
    */

    document
        .getElementById('clearAllButton')
        ?.addEventListener(
            'click',
            function () {

                document
                    .querySelectorAll(
                        '.permission-checkbox:not(:disabled)'
                    )
                    .forEach(
                        function (checkbox) {
                            checkbox.checked = false;
                        }
                    );

            }
        );


    /*
    |--------------------------------------------------------------------------
    | TOGGLE MODULE
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('.module-toggle')
        .forEach(function (button) {

            button.addEventListener(
                'click',
                function () {

                    const module =
                        this.closest(
                            '.permission-module'
                        );

                    const checkboxes =
                        module.querySelectorAll(
                            '.permission-checkbox:not(:disabled)'
                        );

                    const allChecked =
                        Array
                            .from(checkboxes)
                            .every(
                                function (checkbox) {
                                    return checkbox.checked;
                                }
                            );


                    checkboxes.forEach(
                        function (checkbox) {
                            checkbox.checked =
                                !allChecked;
                        }
                    );

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | LUCIDE ICONS
    |--------------------------------------------------------------------------
    */

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

});

</script>

</body>
</html>