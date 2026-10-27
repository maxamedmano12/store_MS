<?php

require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('units.view');

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Business';

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business not available.');
}


/*
|--------------------------------------------------------------------------
| UNIT PERMISSIONS
|--------------------------------------------------------------------------
*/

$canAddUnit    = hasPermission('units.add');
$canUpdateUnit = hasPermission('units.update');
$canDeleteUnit = hasPermission('units.delete');


function unitE($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['units_csrf'])) {
    $_SESSION['units_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['units_csrf'];


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

function unitFlash(string $message, string $type = 'success'): void
{
    $_SESSION['unit_flash'] = $message;
    $_SESSION['unit_flash_type'] = $type;
}


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !$postedToken ||
        !hash_equals($_SESSION['units_csrf'], $postedToken)
    ) {
        unitFlash(
            'Security token is invalid. Please try again.',
            'error'
        );

        header('Location: units.php');
        exit;
    }


    $action = $_POST['action'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | ADD UNIT
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        if (!$canAddUnit) {
            http_response_code(403);
            exit('Access denied.');
        }


        $unitName = trim($_POST['unit_name'] ?? '');
        $shortName = trim($_POST['short_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'Active';


        if ($unitName === '') {

            unitFlash(
                'Unit name is required.',
                'error'
            );

            header('Location: units.php');
            exit;
        }


        if ($shortName === '') {

            unitFlash(
                'Short name is required.',
                'error'
            );

            header('Location: units.php');
            exit;
        }


        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }


        try {

            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE UNIT NAME INSIDE CURRENT BUSINESS
            |--------------------------------------------------------------------------
            */

            $checkName = $conn->prepare("
                SELECT id
                FROM units
                WHERE business_id = ?
                AND unit_name = ?
                LIMIT 1
            ");

            $checkName->execute([
                $businessId,
                $unitName
            ]);


            if ($checkName->fetch()) {

                unitFlash(
                    'This unit name already exists.',
                    'error'
                );

                header('Location: units.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE SHORT NAME INSIDE CURRENT BUSINESS
            |--------------------------------------------------------------------------
            */

            $checkShort = $conn->prepare("
                SELECT id
                FROM units
                WHERE business_id = ?
                AND short_name = ?
                LIMIT 1
            ");

            $checkShort->execute([
                $businessId,
                $shortName
            ]);


            if ($checkShort->fetch()) {

                unitFlash(
                    'This short name already exists.',
                    'error'
                );

                header('Location: units.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | INSERT UNIT
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                INSERT INTO units
                (
                    business_id,
                    unit_name,
                    short_name,
                    description,
                    status
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $businessId,
                $unitName,
                $shortName,
                $description !== '' ? $description : null,
                $status
            ]);


            $unitId = (int) $conn->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | AUDIT
            |--------------------------------------------------------------------------
            */

            if (function_exists('logActivity')) {

                try {

                    logActivity(
                        'CREATE',
                        'Units',
                        $unitId,
                        'Created unit: ' . $unitName
                    );

                } catch (Throwable $e) {
                    // Unit creation must not fail because of audit.
                }
            }


            unitFlash(
                'Unit added successfully.'
            );


        } catch (PDOException $e) {

            if ((string) $e->getCode() === '23000') {

                unitFlash(
                    'This unit name or short name already exists.',
                    'error'
                );

            } else {

                unitFlash(
                    'Unable to add unit.',
                    'error'
                );
            }
        }


        header('Location: units.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE UNIT
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        if (!$canUpdateUnit) {
            http_response_code(403);
            exit('Access denied.');
        }


        $unitId = (int) ($_POST['unit_id'] ?? 0);
        $unitName = trim($_POST['unit_name'] ?? '');
        $shortName = trim($_POST['short_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'Active';


        if (
            $unitId <= 0 ||
            $unitName === '' ||
            $shortName === ''
        ) {

            unitFlash(
                'Please enter valid unit information.',
                'error'
            );

            header('Location: units.php');
            exit;
        }


        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }


        try {

            /*
            |--------------------------------------------------------------------------
            | FETCH OLD RECORD
            |--------------------------------------------------------------------------
            */

            $oldStmt = $conn->prepare("
                SELECT
                    id,
                    unit_name,
                    short_name,
                    description,
                    status

                FROM units

                WHERE id = ?
                AND business_id = ?

                LIMIT 1
            ");

            $oldStmt->execute([
                $unitId,
                $businessId
            ]);


            $oldUnit = $oldStmt->fetch(PDO::FETCH_ASSOC);


            if (!$oldUnit) {

                unitFlash(
                    'Unit not found.',
                    'error'
                );

                header('Location: units.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE UNIT NAME
            |--------------------------------------------------------------------------
            */

            $duplicateName = $conn->prepare("
                SELECT id
                FROM units
                WHERE business_id = ?
                AND unit_name = ?
                AND id <> ?
                LIMIT 1
            ");

            $duplicateName->execute([
                $businessId,
                $unitName,
                $unitId
            ]);


            if ($duplicateName->fetch()) {

                unitFlash(
                    'Another unit already uses this name.',
                    'error'
                );

                header('Location: units.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE SHORT NAME
            |--------------------------------------------------------------------------
            */

            $duplicateShort = $conn->prepare("
                SELECT id
                FROM units
                WHERE business_id = ?
                AND short_name = ?
                AND id <> ?
                LIMIT 1
            ");

            $duplicateShort->execute([
                $businessId,
                $shortName,
                $unitId
            ]);


            if ($duplicateShort->fetch()) {

                unitFlash(
                    'Another unit already uses this short name.',
                    'error'
                );

                header('Location: units.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE units

                SET
                    unit_name = ?,
                    short_name = ?,
                    description = ?,
                    status = ?

                WHERE id = ?
                AND business_id = ?
            ");

            $stmt->execute([
                $unitName,
                $shortName,
                $description !== '' ? $description : null,
                $status,
                $unitId,
                $businessId
            ]);


            /*
            |--------------------------------------------------------------------------
            | AUDIT
            |--------------------------------------------------------------------------
            */

            if (function_exists('logActivity')) {

                try {

                    logActivity(
                        'UPDATE',
                        'Units',
                        $unitId,
                        'Updated unit: ' . $unitName
                    );

                } catch (Throwable $e) {
                    // Do not break main operation.
                }
            }


            unitFlash(
                'Unit updated successfully.'
            );


        } catch (PDOException $e) {

            if ((string) $e->getCode() === '23000') {

                unitFlash(
                    'Another unit already uses this name or short name.',
                    'error'
                );

            } else {

                unitFlash(
                    'Unable to update unit.',
                    'error'
                );
            }
        }


        header('Location: units.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TOGGLE STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_status') {

        if (!$canUpdateUnit) {
            http_response_code(403);
            exit('Access denied.');
        }


        $unitId = (int) ($_POST['unit_id'] ?? 0);


        if ($unitId <= 0) {

            unitFlash(
                'Invalid unit.',
                'error'
            );

            header('Location: units.php');
            exit;
        }


        try {

            $stmt = $conn->prepare("
                SELECT
                    id,
                    unit_name,
                    status

                FROM units

                WHERE id = ?
                AND business_id = ?

                LIMIT 1
            ");

            $stmt->execute([
                $unitId,
                $businessId
            ]);


            $unit = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$unit) {

                unitFlash(
                    'Unit not found.',
                    'error'
                );

                header('Location: units.php');
                exit;
            }


            $newStatus =
                $unit['status'] === 'Active'
                    ? 'Inactive'
                    : 'Active';


            $update = $conn->prepare("
                UPDATE units
                SET status = ?
                WHERE id = ?
                AND business_id = ?
            ");

            $update->execute([
                $newStatus,
                $unitId,
                $businessId
            ]);


            if (function_exists('logActivity')) {

                try {

                    logActivity(
                        'UPDATE',
                        'Units',
                        $unitId,
                        'Changed unit status to ' . $newStatus
                    );

                } catch (Throwable $e) {
                    // Ignore audit failure.
                }
            }


            unitFlash(
                'Unit is now ' . $newStatus . '.'
            );


        } catch (PDOException $e) {

            unitFlash(
                'Unable to change unit status.',
                'error'
            );
        }


        header('Location: units.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE UNIT
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        if (!$canDeleteUnit) {
            http_response_code(403);
            exit('Access denied.');
        }


        $unitId = (int) ($_POST['unit_id'] ?? 0);


        if ($unitId <= 0) {

            unitFlash(
                'Invalid unit.',
                'error'
            );

            header('Location: units.php');
            exit;
        }


        try {

            $unitStmt = $conn->prepare("
                SELECT
                    id,
                    unit_name

                FROM units

                WHERE id = ?
                AND business_id = ?

                LIMIT 1
            ");

            $unitStmt->execute([
                $unitId,
                $businessId
            ]);


            $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);


            if (!$unit) {

                unitFlash(
                    'Unit not found.',
                    'error'
                );

                header('Location: units.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | DELETE
            |--------------------------------------------------------------------------
            */

            $deleteStmt = $conn->prepare("
                DELETE FROM units
                WHERE id = ?
                AND business_id = ?
            ");

            $deleteStmt->execute([
                $unitId,
                $businessId
            ]);


            if (function_exists('logActivity')) {

                try {

                    logActivity(
                        'DELETE',
                        'Units',
                        $unitId,
                        'Deleted unit: ' . $unit['unit_name']
                    );

                } catch (Throwable $e) {
                    // Delete must not fail because of audit.
                }
            }


            unitFlash(
                'Unit deleted successfully.'
            );


        } catch (PDOException $e) {

            if ((string) $e->getCode() === '23000') {

                unitFlash(
                    'This unit is already being used and cannot be deleted.',
                    'error'
                );

            } else {

                unitFlash(
                    'Unable to delete unit.',
                    'error'
                );
            }
        }


        header('Location: units.php');
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| GET UNITS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            id,
            unit_name,
            short_name,
            description,
            status,
            created_at

        FROM units

        WHERE business_id = ?

        ORDER BY id DESC
    ");

    $stmt->execute([
        $businessId
    ]);

    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);


} catch (PDOException $e) {

    $units = [];
}


/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$totalUnits = count($units);

$activeUnits = 0;
$inactiveUnits = 0;


foreach ($units as $unit) {

    if ($unit['status'] === 'Active') {
        $activeUnits++;
    } else {
        $inactiveUnits++;
    }
}


/*
|--------------------------------------------------------------------------
| FLASH READ
|--------------------------------------------------------------------------
*/

$flashMessage = $_SESSION['unit_flash'] ?? null;
$flashType = $_SESSION['unit_flash_type'] ?? 'success';

unset(
    $_SESSION['unit_flash'],
    $_SESSION['unit_flash_type']
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Units - <?= unitE($businessName) ?>
    </title>


    <link
        rel="icon"
        type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%232563eb'/%3E%3Cpath d='M12 28 32 15l20 13v23H12V28Z' fill='white'/%3E%3Cpath d='M20 34h24v17H20z' fill='%23dbeafe'/%3E%3Cpath d='M26 39h12v12H26z' fill='%232563eb'/%3E%3C/svg%3E"
    >


    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            darkMode: 'class'
        };
    </script>


    <script src="https://unpkg.com/lucide@latest"></script>


    <link
        rel="stylesheet"
        href="../assets/css/app.css"
    >

</head>


<body
    class="
        min-h-screen
        bg-slate-50
        text-slate-700
        dark:bg-slate-950
        dark:text-slate-200
    "
>


<?php require_once __DIR__ . '/includes/sidebar.php'; ?>


<div class="min-h-screen transition-all duration-300 lg:ml-[270px]">


    <?php require_once __DIR__ . '/includes/header.php'; ?>


    <main class="p-4 md:p-6">


        <!-- PAGE HEADER -->

        <div
            class="
                mb-5
                flex
                flex-col
                gap-4
                md:flex-row
                md:items-center
                md:justify-between
            "
        >

            <div>

                <div
                    class="
                        mb-1
                        flex
                        items-center
                        gap-2
                        text-[9px]
                        font-bold
                        uppercase
                        tracking-wider
                        text-slate-400
                    "
                >
                    Inventory
                    <span>/</span>
                    Units
                </div>


                <h1
                    class="
                        text-xl
                        font-black
                        text-slate-900
                        dark:text-white
                    "
                >
                    Units
                </h1>


                <p
                    class="
                        mt-1
                        text-[10px]
                        text-slate-500
                    "
                >
                    Manage product measurement and selling units.
                </p>

            </div>


            <?php if ($canAddUnit): ?>

                <button
                    type="button"
                    onclick="openAddUnitModal()"
                    class="
                        inline-flex
                        h-10
                        items-center
                        justify-center
                        gap-2
                        rounded-xl
                        bg-blue-600
                        px-4
                        text-[10px]
                        font-extrabold
                        text-white
                        shadow-lg
                        shadow-blue-600/20
                        transition
                        hover:bg-blue-700
                    "
                >

                    <i
                        data-lucide="plus"
                        class="h-4 w-4"
                    ></i>

                    Add Unit

                </button>

            <?php endif; ?>

        </div>


        <!-- FLASH -->

        <?php if ($flashMessage): ?>

            <div
                id="unitFlash"
                class="
                    mb-5
                    flex
                    items-center
                    justify-between
                    gap-3
                    rounded-xl
                    border
                    px-4
                    py-3
                    text-[10px]
                    font-semibold

                    <?= $flashType === 'error'
                        ? '
                            border-red-200
                            bg-red-50
                            text-red-700
                            dark:border-red-900
                            dark:bg-red-950/40
                            dark:text-red-300
                        '
                        : '
                            border-emerald-200
                            bg-emerald-50
                            text-emerald-700
                            dark:border-emerald-900
                            dark:bg-emerald-950/40
                            dark:text-emerald-300
                        '
                    ?>
                "
            >

                <span class="flex items-center gap-2">

                    <i
                        data-lucide="<?= $flashType === 'error'
                            ? 'circle-alert'
                            : 'circle-check'
                        ?>"
                        class="h-4 w-4"
                    ></i>

                    <?= unitE($flashMessage) ?>

                </span>


                <button
                    type="button"
                    onclick="document.getElementById('unitFlash').remove()"
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
                mb-5
                grid
                grid-cols-1
                gap-3
                sm:grid-cols-3
            "
        >


            <!-- TOTAL -->

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

                <div class="flex items-center justify-between">

                    <div>

                        <p
                            class="
                                text-[9px]
                                font-bold
                                uppercase
                                text-slate-400
                            "
                        >
                            Total Units
                        </p>


                        <p
                            class="
                                mt-2
                                text-xl
                                font-black
                                text-slate-900
                                dark:text-white
                            "
                        >
                            <?= $totalUnits ?>
                        </p>

                    </div>


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
                            dark:bg-blue-950/40
                        "
                    >
                        <i
                            data-lucide="ruler"
                            class="h-5 w-5"
                        ></i>
                    </div>

                </div>

            </div>


            <!-- ACTIVE -->

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

                <div class="flex items-center justify-between">

                    <div>

                        <p
                            class="
                                text-[9px]
                                font-bold
                                uppercase
                                text-slate-400
                            "
                        >
                            Active
                        </p>


                        <p
                            class="
                                mt-2
                                text-xl
                                font-black
                                text-emerald-600
                            "
                        >
                            <?= $activeUnits ?>
                        </p>

                    </div>


                    <div
                        class="
                            flex
                            h-10
                            w-10
                            items-center
                            justify-center
                            rounded-xl
                            bg-emerald-50
                            text-emerald-600
                            dark:bg-emerald-950/40
                        "
                    >
                        <i
                            data-lucide="circle-check"
                            class="h-5 w-5"
                        ></i>
                    </div>

                </div>

            </div>


            <!-- INACTIVE -->

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

                <div class="flex items-center justify-between">

                    <div>

                        <p
                            class="
                                text-[9px]
                                font-bold
                                uppercase
                                text-slate-400
                            "
                        >
                            Inactive
                        </p>


                        <p
                            class="
                                mt-2
                                text-xl
                                font-black
                                text-slate-500
                            "
                        >
                            <?= $inactiveUnits ?>
                        </p>

                    </div>


                    <div
                        class="
                            flex
                            h-10
                            w-10
                            items-center
                            justify-center
                            rounded-xl
                            bg-slate-100
                            text-slate-500
                            dark:bg-slate-800
                        "
                    >
                        <i
                            data-lucide="circle-off"
                            class="h-5 w-5"
                        ></i>
                    </div>

                </div>

            </div>

        </div>


        <!-- TABLE CARD -->

        <div
            class="
                overflow-hidden
                rounded-2xl
                border
                border-slate-200
                bg-white
                shadow-sm
                dark:border-slate-800
                dark:bg-slate-900
            "
        >


            <!-- SEARCH -->

            <div
                class="
                    flex
                    flex-col
                    gap-3
                    border-b
                    border-slate-100
                    p-4
                    sm:flex-row
                    sm:items-center
                    sm:justify-between
                    dark:border-slate-800
                "
            >

                <div>

                    <h2
                        class="
                            text-[12px]
                            font-extrabold
                            text-slate-800
                            dark:text-white
                        "
                    >
                        Unit List
                    </h2>


                    <p
                        class="
                            mt-1
                            text-[9px]
                            text-slate-400
                        "
                    >
                        <?= $totalUnits ?> units
                    </p>

                </div>


                <div class="relative w-full sm:w-[280px]">

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
                        id="unitSearch"
                        placeholder="Search unit..."
                        autocomplete="off"
                        class="
                            h-10
                            w-full
                            rounded-xl
                            border
                            border-slate-200
                            bg-slate-50
                            pl-9
                            pr-3
                            text-[10px]
                            outline-none
                            transition
                            focus:border-blue-500
                            focus:ring-2
                            focus:ring-blue-500/10
                            dark:border-slate-700
                            dark:bg-slate-950
                        "
                    >

                </div>

            </div>


            <!-- TABLE -->

            <div class="overflow-x-auto">

                <table class="w-full text-left">

                    <thead
                        class="
                            bg-slate-50
                            text-[9px]
                            font-bold
                            uppercase
                            tracking-wider
                            text-slate-400
                            dark:bg-slate-950/60
                        "
                    >

                        <tr>

                            <th class="px-5 py-3">
                                #
                            </th>

                            <th class="px-5 py-3">
                                Unit
                            </th>

                            <th class="px-5 py-3">
                                Short Name
                            </th>

                            <th class="px-5 py-3">
                                Description
                            </th>

                            <th class="px-5 py-3">
                                Status
                            </th>

                            <th class="px-5 py-3">
                                Created
                            </th>


                            <?php if ($canUpdateUnit || $canDeleteUnit): ?>

                                <th class="px-5 py-3 text-right">
                                    Actions
                                </th>

                            <?php endif; ?>

                        </tr>

                    </thead>


                    <tbody id="unitTableBody">


                    <?php if (!$units): ?>

                        <tr id="emptyUnitRow">

                            <td
                                colspan="<?= ($canUpdateUnit || $canDeleteUnit) ? 7 : 6 ?>"
                                class="px-5 py-14 text-center"
                            >

                                <div
                                    class="
                                        mx-auto
                                        flex
                                        h-12
                                        w-12
                                        items-center
                                        justify-center
                                        rounded-2xl
                                        bg-slate-100
                                        text-slate-400
                                        dark:bg-slate-800
                                    "
                                >
                                    <i
                                        data-lucide="ruler"
                                        class="h-5 w-5"
                                    ></i>
                                </div>


                                <p
                                    class="
                                        mt-3
                                        text-[11px]
                                        font-bold
                                        text-slate-600
                                        dark:text-slate-300
                                    "
                                >
                                    No units found
                                </p>


                                <p
                                    class="
                                        mt-1
                                        text-[9px]
                                        text-slate-400
                                    "
                                >
                                    Add your first product unit.
                                </p>

                            </td>

                        </tr>


                    <?php else: ?>


                        <?php foreach ($units as $index => $unit): ?>

                            <tr
                                class="
                                    unit-row
                                    border-t
                                    border-slate-100
                                    text-[10px]
                                    transition
                                    hover:bg-slate-50/70
                                    dark:border-slate-800
                                    dark:hover:bg-slate-800/40
                                "

                                data-search="<?= unitE(
                                    strtolower(
                                        $unit['unit_name']
                                        . ' '
                                        . $unit['short_name']
                                        . ' '
                                        . ($unit['description'] ?? '')
                                        . ' '
                                        . $unit['status']
                                    )
                                ) ?>"
                            >


                                <td
                                    class="
                                        px-5
                                        py-3.5
                                        text-slate-400
                                    "
                                >
                                    <?= $index + 1 ?>
                                </td>


                                <td class="px-5 py-3.5">

                                    <div class="flex items-center gap-3">

                                        <div
                                            class="
                                                flex
                                                h-9
                                                w-9
                                                shrink-0
                                                items-center
                                                justify-center
                                                rounded-xl
                                                bg-blue-50
                                                text-blue-600
                                                dark:bg-blue-950/40
                                            "
                                        >
                                            <i
                                                data-lucide="ruler"
                                                class="h-4 w-4"
                                            ></i>
                                        </div>


                                        <span
                                            class="
                                                font-bold
                                                text-slate-800
                                                dark:text-white
                                            "
                                        >
                                            <?= unitE(
                                                $unit['unit_name']
                                            ) ?>
                                        </span>

                                    </div>

                                </td>


                                <td class="px-5 py-3.5">

                                    <span
                                        class="
                                            inline-flex
                                            rounded-lg
                                            bg-blue-50
                                            px-2.5
                                            py-1
                                            text-[9px]
                                            font-extrabold
                                            text-blue-600
                                            dark:bg-blue-950/40
                                            dark:text-blue-300
                                        "
                                    >
                                        <?= unitE(
                                            $unit['short_name']
                                        ) ?>
                                    </span>

                                </td>


                                <td
                                    class="
                                        max-w-[280px]
                                        px-5
                                        py-3.5
                                        text-slate-500
                                    "
                                >

                                    <span class="block truncate">

                                        <?= unitE(
                                            $unit['description']
                                            ?: '—'
                                        ) ?>

                                    </span>

                                </td>


                                <td class="px-5 py-3.5">


                                    <?php if ($unit['status'] === 'Active'): ?>

                                        <span
                                            class="
                                                inline-flex
                                                items-center
                                                gap-1.5
                                                rounded-full
                                                bg-emerald-50
                                                px-2.5
                                                py-1
                                                text-[8px]
                                                font-bold
                                                text-emerald-600
                                                dark:bg-emerald-950/40
                                                dark:text-emerald-300
                                            "
                                        >

                                            <span
                                                class="
                                                    h-1.5
                                                    w-1.5
                                                    rounded-full
                                                    bg-emerald-500
                                                "
                                            ></span>

                                            Active

                                        </span>


                                    <?php else: ?>


                                        <span
                                            class="
                                                inline-flex
                                                items-center
                                                gap-1.5
                                                rounded-full
                                                bg-slate-100
                                                px-2.5
                                                py-1
                                                text-[8px]
                                                font-bold
                                                text-slate-500
                                                dark:bg-slate-800
                                                dark:text-slate-300
                                            "
                                        >

                                            <span
                                                class="
                                                    h-1.5
                                                    w-1.5
                                                    rounded-full
                                                    bg-slate-400
                                                "
                                            ></span>

                                            Inactive

                                        </span>


                                    <?php endif; ?>


                                </td>


                                <td
                                    class="
                                        px-5
                                        py-3.5
                                        text-slate-500
                                    "
                                >

                                    <?= unitE(
                                        date(
                                            'd M Y',
                                            strtotime($unit['created_at'])
                                        )
                                    ) ?>

                                </td>


                                <?php if ($canUpdateUnit || $canDeleteUnit): ?>

                                    <td class="px-5 py-3.5">

                                        <div class="flex items-center justify-end gap-1">


                                            <?php if ($canUpdateUnit): ?>


                                                <!-- EDIT -->

                                                <button
                                                    type="button"
                                                    title="Edit"

                                                    onclick='openEditUnitModal(<?= json_encode([
                                                        'id' => (int) $unit['id'],
                                                        'unit_name' => $unit['unit_name'],
                                                        'short_name' => $unit['short_name'],
                                                        'description' => $unit['description'] ?? '',
                                                        'status' => $unit['status']
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'

                                                    class="
                                                        flex
                                                        h-8
                                                        w-8
                                                        items-center
                                                        justify-center
                                                        rounded-lg
                                                        text-blue-600
                                                        transition
                                                        hover:bg-blue-50
                                                        dark:hover:bg-blue-950/40
                                                    "
                                                >

                                                    <i
                                                        data-lucide="pencil"
                                                        class="h-3.5 w-3.5"
                                                    ></i>

                                                </button>


                                                <!-- STATUS -->

                                                <form
                                                    method="POST"
                                                    onsubmit="return confirmUnitStatus('<?= unitE(
                                                        $unit['status']
                                                    ) ?>');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= unitE($csrfToken) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="toggle_status"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="unit_id"
                                                        value="<?= (int) $unit['id'] ?>"
                                                    >


                                                    <button
                                                        type="submit"

                                                        title="<?= $unit['status'] === 'Active'
                                                            ? 'Deactivate'
                                                            : 'Activate'
                                                        ?>"

                                                        class="
                                                            flex
                                                            h-8
                                                            w-8
                                                            items-center
                                                            justify-center
                                                            rounded-lg
                                                            transition

                                                            <?= $unit['status'] === 'Active'
                                                                ? '
                                                                    text-red-500
                                                                    hover:bg-red-50
                                                                    dark:hover:bg-red-950/40
                                                                '
                                                                : '
                                                                    text-emerald-500
                                                                    hover:bg-emerald-50
                                                                    dark:hover:bg-emerald-950/40
                                                                '
                                                            ?>
                                                        "
                                                    >

                                                        <i
                                                            data-lucide="<?= $unit['status'] === 'Active'
                                                                ? 'circle-off'
                                                                : 'circle-check'
                                                            ?>"
                                                            class="h-3.5 w-3.5"
                                                        ></i>

                                                    </button>

                                                </form>


                                            <?php endif; ?>


                                            <?php if ($canDeleteUnit): ?>


                                                <!-- DELETE -->

                                                <button
                                                    type="button"
                                                    title="Delete"
                                                    onclick='openDeleteUnitModal(<?= json_encode([
                                                        "id" => (int) $unit["id"],
                                                        "unit_name" => $unit["unit_name"]
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                                    class="
                                                        flex
                                                        h-8
                                                        w-8
                                                        items-center
                                                        justify-center
                                                        rounded-lg
                                                        text-red-600
                                                        transition
                                                        hover:bg-red-50
                                                        dark:hover:bg-red-950/40
                                                    "
                                                >

                                                    <i
                                                        data-lucide="trash-2"
                                                        class="h-3.5 w-3.5"
                                                    ></i>

                                                </button>


                                            <?php endif; ?>


                                        </div>

                                    </td>

                                <?php endif; ?>


                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>


                        <!-- SEARCH EMPTY -->

                        <tr
                            id="searchEmptyRow"
                            class="hidden"
                        >

                            <td
                                colspan="<?= ($canUpdateUnit || $canDeleteUnit) ? 7 : 6 ?>"
                                class="
                                    px-5
                                    py-12
                                    text-center
                                    text-[10px]
                                    text-slate-400
                                "
                            >
                                No unit matches your search.
                            </td>

                        </tr>


                    </tbody>

                </table>

            </div>

        </div>


    </main>


    <?php require_once __DIR__ . '/includes/footer.php'; ?>


</div>



<?php if ($canAddUnit): ?>


<!-- =========================================================
     ADD UNIT MODAL
========================================================= -->

<div
    id="addUnitModal"
    class="
        fixed
        inset-0
        z-[100]
        hidden
        items-center
        justify-center
        bg-slate-950/60
        p-4
        backdrop-blur-sm
    "
>

    <div
        class="
            modal-box
            w-full
            max-w-md
            scale-95
            rounded-2xl
            border
            border-slate-200
            bg-white
            shadow-2xl
            transition
            dark:border-slate-800
            dark:bg-slate-900
        "
    >


        <div
            class="
                flex
                items-center
                justify-between
                border-b
                border-slate-100
                px-5
                py-4
                dark:border-slate-800
            "
        >

            <div>

                <h3
                    class="
                        text-[13px]
                        font-extrabold
                        text-slate-900
                        dark:text-white
                    "
                >
                    Add Unit
                </h3>


                <p
                    class="
                        mt-1
                        text-[9px]
                        text-slate-400
                    "
                >
                    Create a new product unit.
                </p>

            </div>


            <button
                type="button"
                onclick="closeUnitModal('addUnitModal')"
                class="
                    flex
                    h-8
                    w-8
                    items-center
                    justify-center
                    rounded-lg
                    text-slate-400
                    hover:bg-slate-100
                    dark:hover:bg-slate-800
                "
            >

                <i
                    data-lucide="x"
                    class="h-4 w-4"
                ></i>

            </button>

        </div>


        <form method="POST">


            <input
                type="hidden"
                name="csrf_token"
                value="<?= unitE($csrfToken) ?>"
            >


            <input
                type="hidden"
                name="action"
                value="add"
            >


            <div class="space-y-4 p-5">


                <!-- UNIT NAME -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[9px]
                            font-bold
                            uppercase
                            text-slate-500
                        "
                    >
                        Unit Name
                        <span class="text-red-500">*</span>
                    </label>


                    <input
                        type="text"
                        name="unit_name"
                        maxlength="120"
                        required
                        autocomplete="off"
                        placeholder="e.g. Carton"
                        class="unit-input"
                    >

                </div>


                <!-- SHORT NAME -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[9px]
                            font-bold
                            uppercase
                            text-slate-500
                        "
                    >
                        Short Name
                        <span class="text-red-500">*</span>
                    </label>


                    <input
                        type="text"
                        name="short_name"
                        maxlength="30"
                        required
                        autocomplete="off"
                        placeholder="e.g. CTN"
                        class="unit-input uppercase"
                    >

                </div>


                <!-- DESCRIPTION -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[9px]
                            font-bold
                            uppercase
                            text-slate-500
                        "
                    >
                        Description
                    </label>


                    <textarea
                        name="description"
                        maxlength="255"
                        rows="3"
                        placeholder="Optional description..."
                        class="unit-input resize-none"
                    ></textarea>

                </div>


                <!-- STATUS -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[9px]
                            font-bold
                            uppercase
                            text-slate-500
                        "
                    >
                        Status
                    </label>


                    <select
                        name="status"
                        class="unit-input"
                    >

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                </div>


            </div>


            <div
                class="
                    flex
                    justify-end
                    gap-2
                    border-t
                    border-slate-100
                    px-5
                    py-4
                    dark:border-slate-800
                "
            >


                <button
                    type="button"
                    onclick="closeUnitModal('addUnitModal')"
                    class="
                        h-9
                        rounded-xl
                        border
                        border-slate-200
                        px-4
                        text-[9px]
                        font-bold
                        text-slate-600
                        hover:bg-slate-50
                        dark:border-slate-700
                        dark:text-slate-300
                        dark:hover:bg-slate-800
                    "
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="
                        inline-flex
                        h-9
                        items-center
                        gap-2
                        rounded-xl
                        bg-blue-600
                        px-4
                        text-[9px]
                        font-extrabold
                        text-white
                        hover:bg-blue-700
                    "
                >

                    <i
                        data-lucide="plus"
                        class="h-3.5 w-3.5"
                    ></i>

                    Save Unit

                </button>


            </div>


        </form>


    </div>

</div>


<?php endif; ?>



<?php if ($canUpdateUnit): ?>


<!-- =========================================================
     EDIT UNIT MODAL
========================================================= -->

<div
    id="editUnitModal"
    class="
        fixed
        inset-0
        z-[100]
        hidden
        items-center
        justify-center
        bg-slate-950/60
        p-4
        backdrop-blur-sm
    "
>

    <div
        class="
            modal-box
            w-full
            max-w-md
            scale-95
            rounded-2xl
            border
            border-slate-200
            bg-white
            shadow-2xl
            transition
            dark:border-slate-800
            dark:bg-slate-900
        "
    >


        <div
            class="
                flex
                items-center
                justify-between
                border-b
                border-slate-100
                px-5
                py-4
                dark:border-slate-800
            "
        >

            <div>

                <h3
                    class="
                        text-[13px]
                        font-extrabold
                        text-slate-900
                        dark:text-white
                    "
                >
                    Edit Unit
                </h3>


                <p
                    class="
                        mt-1
                        text-[9px]
                        text-slate-400
                    "
                >
                    Update unit information.
                </p>

            </div>


            <button
                type="button"
                onclick="closeUnitModal('editUnitModal')"
                class="
                    flex
                    h-8
                    w-8
                    items-center
                    justify-center
                    rounded-lg
                    text-slate-400
                    hover:bg-slate-100
                    dark:hover:bg-slate-800
                "
            >

                <i
                    data-lucide="x"
                    class="h-4 w-4"
                ></i>

            </button>


        </div>


        <form method="POST">


            <input
                type="hidden"
                name="csrf_token"
                value="<?= unitE($csrfToken) ?>"
            >


            <input
                type="hidden"
                name="action"
                value="update"
            >


            <input
                type="hidden"
                name="unit_id"
                id="editUnitId"
            >


            <div class="space-y-4 p-5">


                <!-- UNIT NAME -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[9px]
                            font-bold
                            uppercase
                            text-slate-500
                        "
                    >
                        Unit Name
                        <span class="text-red-500">*</span>
                    </label>


                    <input
                        type="text"
                        name="unit_name"
                        id="editUnitName"
                        maxlength="120"
                        required
                        autocomplete="off"
                        class="unit-input"
                    >

                </div>


                <!-- SHORT NAME -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[9px]
                            font-bold
                            uppercase
                            text-slate-500
                        "
                    >
                        Short Name
                        <span class="text-red-500">*</span>
                    </label>


                    <input
                        type="text"
                        name="short_name"
                        id="editUnitShortName"
                        maxlength="30"
                        required
                        autocomplete="off"
                        class="unit-input uppercase"
                    >

                </div>


                <!-- DESCRIPTION -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[9px]
                            font-bold
                            uppercase
                            text-slate-500
                        "
                    >
                        Description
                    </label>


                    <textarea
                        name="description"
                        id="editUnitDescription"
                        maxlength="255"
                        rows="3"
                        class="unit-input resize-none"
                    ></textarea>

                </div>


                <!-- STATUS -->

                <div>

                    <label
                        class="
                            mb-1.5
                            block
                            text-[9px]
                            font-bold
                            uppercase
                            text-slate-500
                        "
                    >
                        Status
                    </label>


                    <select
                        name="status"
                        id="editUnitStatus"
                        class="unit-input"
                    >

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                </div>


            </div>


            <div
                class="
                    flex
                    justify-end
                    gap-2
                    border-t
                    border-slate-100
                    px-5
                    py-4
                    dark:border-slate-800
                "
            >


                <button
                    type="button"
                    onclick="closeUnitModal('editUnitModal')"
                    class="
                        h-9
                        rounded-xl
                        border
                        border-slate-200
                        px-4
                        text-[9px]
                        font-bold
                        text-slate-600
                        hover:bg-slate-50
                        dark:border-slate-700
                        dark:text-slate-300
                        dark:hover:bg-slate-800
                    "
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="
                        inline-flex
                        h-9
                        items-center
                        gap-2
                        rounded-xl
                        bg-blue-600
                        px-4
                        text-[9px]
                        font-extrabold
                        text-white
                        hover:bg-blue-700
                    "
                >

                    <i
                        data-lucide="save"
                        class="h-3.5 w-3.5"
                    ></i>

                    Update Unit

                </button>


            </div>


        </form>


    </div>

</div>


<?php endif; ?>


<?php if ($canDeleteUnit): ?>

<!-- =========================================================
     DELETE UNIT MODAL
========================================================= -->
<div
    id="deleteUnitModal"
    class="
        fixed
        inset-0
        z-[110]
        hidden
        items-center
        justify-center
        bg-slate-950/60
        p-4
        backdrop-blur-sm
    "
>
    <div
        class="
            modal-box
            w-full
            max-w-sm
            scale-95
            rounded-2xl
            border
            border-slate-200
            bg-white
            shadow-2xl
            transition
            dark:border-slate-800
            dark:bg-slate-900
        "
    >
        <div class="p-5 text-center">
            <div
                class="
                    mx-auto
                    flex
                    h-12
                    w-12
                    items-center
                    justify-center
                    rounded-2xl
                    bg-red-50
                    text-red-600
                    dark:bg-red-950/40
                    dark:text-red-300
                "
            >
                <i data-lucide="trash-2" class="h-5 w-5"></i>
            </div>

            <h3 class="mt-4 text-[13px] font-extrabold text-slate-900 dark:text-white">
                Delete Unit?
            </h3>

            <p class="mt-2 text-[10px] leading-5 text-slate-500">
                Are you sure you want to delete
                <span id="deleteUnitName" class="font-extrabold text-slate-800 dark:text-white"></span>?
                This action cannot be undone.
            </p>
        </div>

        <form method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= unitE($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="delete"
            >

            <input
                type="hidden"
                name="unit_id"
                id="deleteUnitId"
            >

            <div
                class="
                    flex
                    justify-end
                    gap-2
                    border-t
                    border-slate-100
                    px-5
                    py-4
                    dark:border-slate-800
                "
            >
                <button
                    type="button"
                    onclick="closeUnitModal('deleteUnitModal')"
                    class="
                        h-9
                        rounded-xl
                        border
                        border-slate-200
                        px-4
                        text-[9px]
                        font-bold
                        text-slate-600
                        hover:bg-slate-50
                        dark:border-slate-700
                        dark:text-slate-300
                        dark:hover:bg-slate-800
                    "
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="
                        inline-flex
                        h-9
                        items-center
                        gap-2
                        rounded-xl
                        bg-red-600
                        px-4
                        text-[9px]
                        font-extrabold
                        text-white
                        hover:bg-red-700
                    "
                >
                    <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                    Delete Unit
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>



<style>

.unit-input {

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


.unit-input:focus {

    border-color: rgb(59 130 246);

    box-shadow: 0 0 0 3px rgba(59,130,246,.08);

}


.dark .unit-input {

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


    /*
    |--------------------------------------------------------------------------
    | AUTO SEARCH
    |--------------------------------------------------------------------------
    */

    const searchInput =
        document.getElementById('unitSearch');


    const rows =
        document.querySelectorAll('.unit-row');


    const searchEmptyRow =
        document.getElementById('searchEmptyRow');


    if (searchInput) {

        searchInput.addEventListener('input', function () {


            const query =
                this.value
                    .trim()
                    .toLowerCase();


            let visible = 0;


            rows.forEach(function (row) {


                const searchable =
                    (
                        row.dataset.search
                        || ''
                    ).toLowerCase();


                const match =
                    searchable.includes(query);


                row.style.display =
                    match
                        ? ''
                        : 'none';


                if (match) {
                    visible++;
                }

            });


            if (searchEmptyRow) {

                if (
                    rows.length > 0
                    &&
                    visible === 0
                ) {

                    searchEmptyRow.classList.remove('hidden');

                } else {

                    searchEmptyRow.classList.add('hidden');

                }
            }


        });

    }


    /*
    |--------------------------------------------------------------------------
    | CLOSE MODAL ON BACKDROP
    |--------------------------------------------------------------------------
    */

    [
        'addUnitModal',
        'editUnitModal',
        'deleteUnitModal'

    ].forEach(function (modalId) {


        const modal =
            document.getElementById(modalId);


        if (!modal) {
            return;
        }


        modal.addEventListener('click', function (event) {


            if (event.target === modal) {
                closeUnitModal(modalId);
            }


        });


    });


    /*
    |--------------------------------------------------------------------------
    | ESC CLOSE
    |--------------------------------------------------------------------------
    */

    document.addEventListener('keydown', function (event) {


        if (event.key === 'Escape') {


            closeUnitModal(
                'addUnitModal'
            );


            closeUnitModal(
                'editUnitModal'
            );


            closeUnitModal(
                'deleteUnitModal'
            );

        }


    });


    /*
    |--------------------------------------------------------------------------
    | FLASH AUTO HIDE
    |--------------------------------------------------------------------------
    */

    const flash =
        document.getElementById('unitFlash');


    if (flash) {


        setTimeout(function () {


            flash.style.opacity = '0';

            flash.style.transform =
                'translateY(-5px)';


            setTimeout(function () {

                flash.remove();

            }, 300);


        }, 3500);


    }


});


function openAddUnitModal() {


    const modal =
        document.getElementById(
            'addUnitModal'
        );


    if (!modal) {
        return;
    }


    modal.classList.remove('hidden');

    modal.classList.add('flex');


    document.body.style.overflow =
        'hidden';


    setTimeout(function () {


        const box =
            modal.querySelector('.modal-box');


        if (box) {

            box.classList.remove('scale-95');

            box.classList.add('scale-100');

        }


        const input =
            modal.querySelector(
                'input[name="unit_name"]'
            );


        if (input) {
            input.focus();
        }


    }, 20);


}


function openEditUnitModal(unit) {


    document.getElementById(
        'editUnitId'
    ).value =
        unit.id || '';


    document.getElementById(
        'editUnitName'
    ).value =
        unit.unit_name || '';


    document.getElementById(
        'editUnitShortName'
    ).value =
        unit.short_name || '';


    document.getElementById(
        'editUnitDescription'
    ).value =
        unit.description || '';


    document.getElementById(
        'editUnitStatus'
    ).value =
        unit.status || 'Active';


    const modal =
        document.getElementById(
            'editUnitModal'
        );


    if (!modal) {
        return;
    }


    modal.classList.remove('hidden');

    modal.classList.add('flex');


    document.body.style.overflow =
        'hidden';


    setTimeout(function () {


        const box =
            modal.querySelector('.modal-box');


        if (box) {

            box.classList.remove('scale-95');

            box.classList.add('scale-100');

        }


        document.getElementById(
            'editUnitName'
        ).focus();


    }, 20);


}


function openDeleteUnitModal(unit) {

    const modal =
        document.getElementById('deleteUnitModal');

    if (!modal) {
        return;
    }

    document.getElementById('deleteUnitId').value =
        unit.id || '';

    document.getElementById('deleteUnitName').textContent =
        unit.unit_name || 'this unit';

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


function closeUnitModal(modalId) {


    const modal =
        document.getElementById(modalId);


    if (
        !modal
        ||
        modal.classList.contains('hidden')
    ) {
        return;
    }


    const box =
        modal.querySelector('.modal-box');


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


function confirmUnitStatus(currentStatus) {


    if (currentStatus === 'Active') {


        return confirm(
            'Deactivate this unit?'
        );


    }


    return confirm(
        'Activate this unit?'
    );


}

</script>


<script src="../assets/js/app.js"></script>


</body>
</html>