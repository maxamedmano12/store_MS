<?php

require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('categories.view');

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Business';

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business not available.');
}

/*
|--------------------------------------------------------------------------
| CATEGORY PERMISSIONS
|--------------------------------------------------------------------------
*/

$canAddCategory    = hasPermission('categories.add');
$canUpdateCategory = hasPermission('categories.update');
$canDeleteCategory = hasPermission('categories.delete');


function categoryE($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['categories_csrf'])) {
    $_SESSION['categories_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['categories_csrf'];


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

function categoryFlash(string $message, string $type = 'success'): void
{
    $_SESSION['category_flash'] = $message;
    $_SESSION['category_flash_type'] = $type;
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
        !hash_equals($_SESSION['categories_csrf'], $postedToken)
    ) {
        categoryFlash('Security token is invalid. Please try again.', 'error');
        header('Location: categories.php');
        exit;
    }

    $action = $_POST['action'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | ADD CATEGORY
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        if (!$canAddCategory) {
            http_response_code(403);
            exit('Access denied.');
        }

        $categoryName = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'Active';

        if ($categoryName === '') {
            categoryFlash('Category name is required.', 'error');
            header('Location: categories.php');
            exit;
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        try {

            /*
            | Check duplicate only inside current business.
            */

            $check = $conn->prepare("
                SELECT id
                FROM categories
                WHERE business_id = ?
                AND category_name = ?
                LIMIT 1
            ");

            $check->execute([
                $businessId,
                $categoryName
            ]);

            if ($check->fetch()) {
                categoryFlash('This category already exists.', 'error');
                header('Location: categories.php');
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO categories
                (
                    business_id,
                    category_name,
                    description,
                    status
                )
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $businessId,
                $categoryName,
                $description !== '' ? $description : null,
                $status
            ]);

            $categoryId = (int) $conn->lastInsertId();

            /*
            | Audit trail.
            | Wrapped safely because audit helper signatures
            | may differ while project is being built.
            */

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'CREATE',
                        'Categories',
                        $categoryId,
                        'Created category: ' . $categoryName
                    );
                } catch (Throwable $e) {
                    // Category creation must not fail because of audit logging.
                }
            }

            categoryFlash('Category added successfully.');

        } catch (PDOException $e) {

            if ((string) $e->getCode() === '23000') {
                categoryFlash('This category already exists.', 'error');
            } else {
                categoryFlash('Unable to add category.', 'error');
            }
        }

        header('Location: categories.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE CATEGORY
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        if (!$canUpdateCategory) {
            http_response_code(403);
            exit('Access denied.');
        }

        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $categoryName = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'Active';

        if ($categoryId <= 0 || $categoryName === '') {
            categoryFlash('Please enter valid category information.', 'error');
            header('Location: categories.php');
            exit;
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        try {

            /*
            | Fetch old record using BOTH id + business_id.
            | Prevents tenant A from updating tenant B data.
            */

            $oldStmt = $conn->prepare("
                SELECT
                    id,
                    category_name,
                    description,
                    status
                FROM categories
                WHERE id = ?
                AND business_id = ?
                LIMIT 1
            ");

            $oldStmt->execute([
                $categoryId,
                $businessId
            ]);

            $oldCategory = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oldCategory) {
                categoryFlash('Category not found.', 'error');
                header('Location: categories.php');
                exit;
            }

            $duplicate = $conn->prepare("
                SELECT id
                FROM categories
                WHERE business_id = ?
                AND category_name = ?
                AND id <> ?
                LIMIT 1
            ");

            $duplicate->execute([
                $businessId,
                $categoryName,
                $categoryId
            ]);

            if ($duplicate->fetch()) {
                categoryFlash('Another category already uses this name.', 'error');
                header('Location: categories.php');
                exit;
            }

            $stmt = $conn->prepare("
                UPDATE categories

                SET
                    category_name = ?,
                    description = ?,
                    status = ?

                WHERE id = ?
                AND business_id = ?
            ");

            $stmt->execute([
                $categoryName,
                $description !== '' ? $description : null,
                $status,
                $categoryId,
                $businessId
            ]);

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'UPDATE',
                        'Categories',
                        $categoryId,
                        'Updated category: ' . $categoryName
                    );
                } catch (Throwable $e) {
                    // Do not break main operation.
                }
            }

            categoryFlash('Category updated successfully.');

        } catch (PDOException $e) {

            if ((string) $e->getCode() === '23000') {
                categoryFlash('Another category already uses this name.', 'error');
            } else {
                categoryFlash('Unable to update category.', 'error');
            }
        }

        header('Location: categories.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TOGGLE STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_status') {

        if (!$canUpdateCategory) {
            http_response_code(403);
            exit('Access denied.');
        }

        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($categoryId <= 0) {
            categoryFlash('Invalid category.', 'error');
            header('Location: categories.php');
            exit;
        }

        try {

            $stmt = $conn->prepare("
                SELECT
                    id,
                    category_name,
                    status

                FROM categories

                WHERE id = ?
                AND business_id = ?

                LIMIT 1
            ");

            $stmt->execute([
                $categoryId,
                $businessId
            ]);

            $category = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                categoryFlash('Category not found.', 'error');
                header('Location: categories.php');
                exit;
            }

            $newStatus =
                $category['status'] === 'Active'
                    ? 'Inactive'
                    : 'Active';

            $update = $conn->prepare("
                UPDATE categories
                SET status = ?
                WHERE id = ?
                AND business_id = ?
            ");

            $update->execute([
                $newStatus,
                $categoryId,
                $businessId
            ]);

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'UPDATE',
                        'Categories',
                        $categoryId,
                        'Changed category status to ' . $newStatus
                    );
                } catch (Throwable $e) {
                    // Ignore audit failure.
                }
            }

            categoryFlash(
                'Category is now ' . $newStatus . '.'
            );

        } catch (PDOException $e) {

            categoryFlash(
                'Unable to change category status.',
                'error'
            );
        }

        header('Location: categories.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE CATEGORY
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        if (!$canDeleteCategory) {
            http_response_code(403);
            exit('Access denied.');
        }

        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($categoryId <= 0) {
            categoryFlash('Invalid category.', 'error');
            header('Location: categories.php');
            exit;
        }

        try {

            $categoryStmt = $conn->prepare("
                SELECT
                    id,
                    category_name

                FROM categories

                WHERE id = ?
                AND business_id = ?

                LIMIT 1
            ");

            $categoryStmt->execute([
                $categoryId,
                $businessId
            ]);

            $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                categoryFlash('Category not found.', 'error');
                header('Location: categories.php');
                exit;
            }

            /*
            | Haddii products table-ku category-ga isticmaalayo,
            | foreign key-ga DB-ga ayaa delete-ka joojin kara.
            | Waxaan siinaynaa user-ka fariin cad.
            */

            $deleteStmt = $conn->prepare("
                DELETE FROM categories
                WHERE id = ?
                AND business_id = ?
            ");

            $deleteStmt->execute([
                $categoryId,
                $businessId
            ]);

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'DELETE',
                        'Categories',
                        $categoryId,
                        'Deleted category: ' . $category['category_name']
                    );
                } catch (Throwable $e) {
                    // Delete must not fail because of audit logging.
                }
            }

            categoryFlash('Category deleted successfully.');

        } catch (PDOException $e) {

            if ((string) $e->getCode() === '23000') {
                categoryFlash(
                    'This category is already being used and cannot be deleted.',
                    'error'
                );
            } else {
                categoryFlash('Unable to delete category.', 'error');
            }
        }

        header('Location: categories.php');
        exit;
    }

}


/*
|--------------------------------------------------------------------------
| GET CATEGORIES
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            id,
            category_name,
            description,
            status,
            created_at

        FROM categories

        WHERE business_id = ?

        ORDER BY id DESC
    ");

    $stmt->execute([
        $businessId
    ]);

    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $categories = [];
}


/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$totalCategories = count($categories);

$activeCategories = 0;
$inactiveCategories = 0;

foreach ($categories as $category) {

    if ($category['status'] === 'Active') {
        $activeCategories++;
    } else {
        $inactiveCategories++;
    }
}


/*
|--------------------------------------------------------------------------
| FLASH READ
|--------------------------------------------------------------------------
*/

$flashMessage = $_SESSION['category_flash'] ?? null;
$flashType = $_SESSION['category_flash_type'] ?? 'success';

unset(
    $_SESSION['category_flash'],
    $_SESSION['category_flash_type']
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
        Categories - <?= categoryE($businessName) ?>
    </title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
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
                    Categories
                </div>

                <h1
                    class="
                        text-xl
                        font-black
                        text-slate-900
                        dark:text-white
                    "
                >
                    Categories
                </h1>

                <p
                    class="
                        mt-1
                        text-[10px]
                        text-slate-500
                    "
                >
                    Organize your products into categories.
                </p>

            </div>


            <?php if ($canAddCategory): ?>

            <button
                type="button"
                onclick="openAddCategoryModal()"
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

                Add Category

            </button>

            <?php endif; ?>

        </div>


        <!-- FLASH -->

        <?php if ($flashMessage): ?>

            <div
                id="categoryFlash"
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

                    <?= categoryE($flashMessage) ?>

                </span>


                <button
                    type="button"
                    onclick="document.getElementById('categoryFlash').remove()"
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
                            Total Categories
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
                            <?= $totalCategories ?>
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
                            data-lucide="tags"
                            class="h-5 w-5"
                        ></i>
                    </div>

                </div>

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
                            <?= $activeCategories ?>
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
                            <?= $inactiveCategories ?>
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
                        Category List
                    </h2>

                    <p
                        class="
                            mt-1
                            text-[9px]
                            text-slate-400
                        "
                    >
                        <?= $totalCategories ?> categories
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
                        id="categorySearch"
                        placeholder="Search category..."
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
                                Category
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

                            <?php if ($canUpdateCategory || $canDeleteCategory): ?>

                                <th class="px-5 py-3 text-right">
                                    Actions
                                </th>

                            <?php endif; ?>

                        </tr>

                    </thead>


                    <tbody id="categoryTableBody">

                    <?php if (!$categories): ?>

                        <tr id="emptyCategoryRow">

                            <td
                                colspan="<?= ($canUpdateCategory || $canDeleteCategory) ? 6 : 5 ?>"
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
                                        data-lucide="tags"
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
                                    No categories found
                                </p>

                                <p
                                    class="
                                        mt-1
                                        text-[9px]
                                        text-slate-400
                                    "
                                >
                                    Add your first product category.
                                </p>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($categories as $index => $category): ?>

                            <tr
                                class="
                                    category-row
                                    border-t
                                    border-slate-100
                                    text-[10px]
                                    transition
                                    hover:bg-slate-50/70
                                    dark:border-slate-800
                                    dark:hover:bg-slate-800/40
                                "
                                data-search="<?= categoryE(
                                    strtolower(
                                        $category['category_name']
                                        . ' '
                                        . ($category['description'] ?? '')
                                        . ' '
                                        . $category['status']
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
                                                data-lucide="tag"
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
                                            <?= categoryE(
                                                $category['category_name']
                                            ) ?>
                                        </span>

                                    </div>

                                </td>


                                <td
                                    class="
                                        max-w-[280px]
                                        px-5
                                        py-3.5
                                        text-slate-500
                                    "
                                >

                                    <span
                                        class="
                                            block
                                            truncate
                                        "
                                    >
                                        <?= categoryE(
                                            $category['description']
                                            ?: '—'
                                        ) ?>
                                    </span>

                                </td>


                                <td class="px-5 py-3.5">

                                    <?php if ($category['status'] === 'Active'): ?>

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
                                    <?= categoryE(
                                        date(
                                            'd M Y',
                                            strtotime($category['created_at'])
                                        )
                                    ) ?>
                                </td>


                                <?php if ($canUpdateCategory || $canDeleteCategory): ?>

                                <td class="px-5 py-3.5">

                                    <div class="flex items-center justify-end gap-1">

                                        <?php if ($canUpdateCategory): ?>

                                            <!-- EDIT -->

                                            <button
                                                type="button"
                                                title="Edit"
                                                onclick='openEditCategoryModal(<?= json_encode([
                                                    'id' => (int) $category['id'],
                                                    'category_name' => $category['category_name'],
                                                    'description' => $category['description'] ?? '',
                                                    'status' => $category['status']
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
                                                onsubmit="return confirmCategoryStatus('<?= categoryE(
                                                    $category['status']
                                                ) ?>');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= categoryE($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle_status"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="category_id"
                                                    value="<?= (int) $category['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    title="<?= $category['status'] === 'Active'
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

                                                        <?= $category['status'] === 'Active'
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
                                                        data-lucide="<?= $category['status'] === 'Active'
                                                            ? 'circle-off'
                                                            : 'circle-check'
                                                        ?>"
                                                        class="h-3.5 w-3.5"
                                                    ></i>
                                                </button>
                                            </form>

                                        <?php endif; ?>


                                        <?php if ($canDeleteCategory): ?>

                                            <!-- DELETE -->

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Delete this category permanently?');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= categoryE($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="category_id"
                                                    value="<?= (int) $category['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    title="Delete"
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
                                            </form>

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
                                colspan="<?= ($canUpdateCategory || $canDeleteCategory) ? 6 : 5 ?>"
                                class="
                                    px-5
                                    py-12
                                    text-center
                                    text-[10px]
                                    text-slate-400
                                "
                            >
                                No category matches your search.
                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </div>


    </main>


    <?php require_once __DIR__ . '/includes/footer.php'; ?>


</div>



<?php if ($canAddCategory): ?>

<!-- =========================================================
     ADD CATEGORY MODAL
========================================================= -->

<div
    id="addCategoryModal"
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
                    Add Category
                </h3>

                <p
                    class="
                        mt-1
                        text-[9px]
                        text-slate-400
                    "
                >
                    Create a new product category.
                </p>

            </div>


            <button
                type="button"
                onclick="closeCategoryModal('addCategoryModal')"
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
                value="<?= categoryE($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add"
            >


            <div class="space-y-4 p-5">


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
                        Category Name
                        <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="category_name"
                        maxlength="120"
                        required
                        autocomplete="off"
                        placeholder="e.g. Beverages"
                        class="category-input"
                    >

                </div>


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
                        class="category-input resize-none"
                    ></textarea>

                </div>


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
                        class="category-input"
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
                    onclick="closeCategoryModal('addCategoryModal')"
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

                    Save Category

                </button>

            </div>

        </form>

    </div>

</div>



<?php endif; ?>


<?php if ($canUpdateCategory): ?>

<!-- =========================================================
     EDIT CATEGORY MODAL
========================================================= -->

<div
    id="editCategoryModal"
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
                    Edit Category
                </h3>

                <p
                    class="
                        mt-1
                        text-[9px]
                        text-slate-400
                    "
                >
                    Update category information.
                </p>

            </div>


            <button
                type="button"
                onclick="closeCategoryModal('editCategoryModal')"
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
                value="<?= categoryE($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="update"
            >

            <input
                type="hidden"
                name="category_id"
                id="editCategoryId"
            >


            <div class="space-y-4 p-5">


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
                        Category Name
                        <span class="text-red-500">*</span>
                    </label>

                    <input
                        type="text"
                        name="category_name"
                        id="editCategoryName"
                        maxlength="120"
                        required
                        autocomplete="off"
                        class="category-input"
                    >

                </div>


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
                        id="editCategoryDescription"
                        maxlength="255"
                        rows="3"
                        class="category-input resize-none"
                    ></textarea>

                </div>


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
                        id="editCategoryStatus"
                        class="category-input"
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
                    onclick="closeCategoryModal('editCategoryModal')"
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

                    Update Category

                </button>

            </div>

        </form>

    </div>

</div>



<?php endif; ?>


<style>

.category-input {

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

.category-input:focus {

    border-color: rgb(59 130 246);

    box-shadow: 0 0 0 3px rgba(59,130,246,.08);

}

.dark .category-input {

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
        document.getElementById('categorySearch');

    const rows =
        document.querySelectorAll('.category-row');

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
        'addCategoryModal',
        'editCategoryModal'

    ].forEach(function (modalId) {

        const modal =
            document.getElementById(modalId);

        if (!modal) {
            return;
        }

        modal.addEventListener('click', function (event) {

            if (event.target === modal) {
                closeCategoryModal(modalId);
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

            closeCategoryModal(
                'addCategoryModal'
            );

            closeCategoryModal(
                'editCategoryModal'
            );
        }

    });


    /*
    |--------------------------------------------------------------------------
    | FLASH AUTO HIDE
    |--------------------------------------------------------------------------
    */

    const flash =
        document.getElementById('categoryFlash');

    if (flash) {

        setTimeout(function () {

            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-5px)';

            setTimeout(function () {
                flash.remove();
            }, 300);

        }, 3500);

    }

});


function openAddCategoryModal() {

    const modal =
        document.getElementById(
            'addCategoryModal'
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
                'input[name="category_name"]'
            );

        if (input) {
            input.focus();
        }

    }, 20);

}


function openEditCategoryModal(category) {

    document.getElementById(
        'editCategoryId'
    ).value =
        category.id || '';

    document.getElementById(
        'editCategoryName'
    ).value =
        category.category_name || '';

    document.getElementById(
        'editCategoryDescription'
    ).value =
        category.description || '';

    document.getElementById(
        'editCategoryStatus'
    ).value =
        category.status || 'Active';


    const modal =
        document.getElementById(
            'editCategoryModal'
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
            'editCategoryName'
        ).focus();

    }, 20);

}


function closeCategoryModal(modalId) {

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


function confirmCategoryStatus(currentStatus) {

    if (currentStatus === 'Active') {

        return confirm(
            'Deactivate this category?'
        );

    }

    return confirm(
        'Activate this category?'
    );

}

</script>


<script src="../assets/js/app.js"></script>


</body>
</html>