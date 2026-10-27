<?php

require_once '../config/auth.php';
require_once '../config/audit.php';
require_once '../config/permissions.php';

requireBusinessUser();
requirePermission('products.view');

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Business';

if ($businessId <= 0) {
    http_response_code(403);
    exit('Business not available.');
}


/*
|--------------------------------------------------------------------------
| PRODUCT PERMISSIONS
|--------------------------------------------------------------------------
*/

$canAddProduct    = hasPermission('products.add');
$canUpdateProduct = hasPermission('products.update');
$canDeleteProduct = hasPermission('products.delete');


function productE($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['products_csrf'])) {
    $_SESSION['products_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['products_csrf'];


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

function productFlash(
    string $message,
    string $type = 'success'
): void {
    $_SESSION['product_flash'] = $message;
    $_SESSION['product_flash_type'] = $type;
}


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken =
        $_POST['csrf_token'] ?? '';

    if (
        !$postedToken ||
        !hash_equals(
            $_SESSION['products_csrf'],
            $postedToken
        )
    ) {

        productFlash(
            'Security token is invalid. Please try again.',
            'error'
        );

        header('Location: products.php');
        exit;
    }


    $action =
        $_POST['action'] ?? '';

    $isAjaxRequest =
        (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || (($_POST['ajax'] ?? '') === '1');


    /*
    |--------------------------------------------------------------------------
    | ADD PRODUCT
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        if (!$canAddProduct) {
            http_response_code(403);
            exit('Access denied.');
        }


        $productName =
            trim($_POST['product_name'] ?? '');

        $productCode =
            trim($_POST['product_code'] ?? '');

        $barcode =
            trim($_POST['barcode'] ?? '');

        $categoryId =
            !empty($_POST['category_id'])
                ? (int) $_POST['category_id']
                : null;

        $baseUnitId =
            (int) ($_POST['base_unit_id'] ?? 0);

        $description =
            trim($_POST['description'] ?? '');

        $costPrice =
            (float) ($_POST['cost_price'] ?? 0);

        $sellingPrice =
            (float) ($_POST['selling_price'] ?? 0);

        $minimumStock =
            (float) ($_POST['minimum_stock'] ?? 0);

        $trackExpiry =
            isset($_POST['track_expiry']) &&
            $_POST['track_expiry'] === '1'
                ? 1
                : 0;

        $status =
            $_POST['status'] ?? 'Active';


        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($productName === '') {

            productFlash(
                'Product name is required.',
                'error'
            );

            header('Location: products.php');
            exit;
        }


        if ($baseUnitId <= 0) {

            productFlash(
                'Base unit is required.',
                'error'
            );

            header('Location: products.php');
            exit;
        }


        if ($costPrice < 0) {
            $costPrice = 0;
        }

        if ($sellingPrice < 0) {
            $sellingPrice = 0;
        }

        if ($minimumStock < 0) {
            $minimumStock = 0;
        }


        if (
            !in_array(
                $status,
                ['Active', 'Inactive'],
                true
            )
        ) {
            $status = 'Active';
        }


        try {

            /*
            |--------------------------------------------------------------------------
            | VERIFY CATEGORY
            |--------------------------------------------------------------------------
            */

            if ($categoryId !== null) {

                $categoryCheck =
                    $conn->prepare("
                        SELECT id
                        FROM categories
                        WHERE id = ?
                        AND business_id = ?
                        LIMIT 1
                    ");

                $categoryCheck->execute([
                    $categoryId,
                    $businessId
                ]);


                if (!$categoryCheck->fetch()) {

                    productFlash(
                        'Selected category is invalid.',
                        'error'
                    );

                    header('Location: products.php');
                    exit;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY BASE UNIT
            |--------------------------------------------------------------------------
            */

            $unitCheck =
                $conn->prepare("
                    SELECT id
                    FROM units
                    WHERE id = ?
                    AND business_id = ?
                    LIMIT 1
                ");

            $unitCheck->execute([
                $baseUnitId,
                $businessId
            ]);


            if (!$unitCheck->fetch()) {

                productFlash(
                    'Selected base unit is invalid.',
                    'error'
                );

                header('Location: products.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | AUTO PRODUCT CODE
            |--------------------------------------------------------------------------
            */

            if ($productCode === '') {

                $nextStmt =
                    $conn->prepare("
                        SELECT
                            COALESCE(MAX(id), 0) + 1
                        FROM products
                        WHERE business_id = ?
                    ");

                $nextStmt->execute([
                    $businessId
                ]);

                $nextNumber =
                    (int) $nextStmt->fetchColumn();


                $productCode =
                    'PRD-' .
                    str_pad(
                        (string) $nextNumber,
                        4,
                        '0',
                        STR_PAD_LEFT
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | DUPLICATE PRODUCT CODE
            |--------------------------------------------------------------------------
            */

            $codeCheck =
                $conn->prepare("
                    SELECT id
                    FROM products
                    WHERE business_id = ?
                    AND product_code = ?
                    LIMIT 1
                ");

            $codeCheck->execute([
                $businessId,
                $productCode
            ]);


            if ($codeCheck->fetch()) {

                productFlash(
                    'This product code already exists.',
                    'error'
                );

                header('Location: products.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | DUPLICATE BARCODE
            |--------------------------------------------------------------------------
            */

            if ($barcode !== '') {

                $barcodeCheck =
                    $conn->prepare("
                        SELECT id
                        FROM products
                        WHERE business_id = ?
                        AND barcode = ?
                        LIMIT 1
                    ");

                $barcodeCheck->execute([
                    $businessId,
                    $barcode
                ]);


                if ($barcodeCheck->fetch()) {

                    productFlash(
                        'This barcode already exists.',
                        'error'
                    );

                    header('Location: products.php');
                    exit;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | BEGIN TRANSACTION
            |--------------------------------------------------------------------------
            */

            $conn->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | INSERT PRODUCT
            |--------------------------------------------------------------------------
            */

            $stmt =
                $conn->prepare("
                    INSERT INTO products
                    (
                        business_id,
                        category_id,
                        base_unit_id,
                        product_name,
                        product_code,
                        barcode,
                        description,
                        cost_price,
                        selling_price,
                        minimum_stock,
                        track_expiry,
                        status
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?
                    )
                ");


            $stmt->execute([
                $businessId,
                $categoryId,
                $baseUnitId,
                $productName,
                $productCode,
                $barcode !== ''
                    ? $barcode
                    : null,
                $description !== ''
                    ? $description
                    : null,
                $costPrice,
                $sellingPrice,
                $minimumStock,
                $trackExpiry,
                $status
            ]);


            $productId =
                (int) $conn->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | INSERT BASE UNIT INTO PRODUCT_UNITS
            |--------------------------------------------------------------------------
            */

            $productUnitStmt =
                $conn->prepare("
                    INSERT INTO product_units
                    (
                        business_id,
                        product_id,
                        unit_id,
                        conversion_qty,
                        selling_price,
                        purchase_price,
                        is_base_unit,
                        can_purchase,
                        can_sell,
                        status
                    )
                    VALUES
                    (
                        ?, ?, ?, 1.000,
                        ?, ?, 1, 1, 1, 'Active'
                    )
                ");


            $productUnitStmt->execute([
                $businessId,
                $productId,
                $baseUnitId,
                $sellingPrice,
                $costPrice
            ]);


            $conn->commit();


            /*
            |--------------------------------------------------------------------------
            | AUDIT
            |--------------------------------------------------------------------------
            */

            if (function_exists('logActivity')) {

                try {

                    logActivity(
                        'CREATE',
                        'Products',
                        $productId,
                        'Created product: ' .
                        $productName
                    );

                } catch (Throwable $e) {
                    // Do not break main operation.
                }
            }


            productFlash(
                'Product added successfully.'
            );


        } catch (PDOException $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }


            if (
                (string) $e->getCode()
                === '23000'
            ) {

                productFlash(
                    'Product code or barcode already exists.',
                    'error'
                );

            } else {

                productFlash(
                    'Unable to add product.',
                    'error'
                );
            }
        }


        header('Location: products.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE PRODUCT
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        if (!$canUpdateProduct) {
            http_response_code(403);
            exit('Access denied.');
        }


        $productId =
            (int) ($_POST['product_id'] ?? 0);

        $productName =
            trim($_POST['product_name'] ?? '');

        $productCode =
            trim($_POST['product_code'] ?? '');

        $barcode =
            trim($_POST['barcode'] ?? '');

        $categoryId =
            !empty($_POST['category_id'])
                ? (int) $_POST['category_id']
                : null;

        $baseUnitId =
            (int) ($_POST['base_unit_id'] ?? 0);

        $description =
            trim($_POST['description'] ?? '');

        $costPrice =
            (float) ($_POST['cost_price'] ?? 0);

        $sellingPrice =
            (float) ($_POST['selling_price'] ?? 0);

        $minimumStock =
            (float) ($_POST['minimum_stock'] ?? 0);

        $trackExpiry =
            isset($_POST['track_expiry']) &&
            $_POST['track_expiry'] === '1'
                ? 1
                : 0;

        $status =
            $_POST['status'] ?? 'Active';


        if ($productId <= 0) {

            productFlash(
                'Invalid product.',
                'error'
            );

            header('Location: products.php');
            exit;
        }


        if ($productName === '') {

            productFlash(
                'Product name is required.',
                'error'
            );

            header('Location: products.php');
            exit;
        }


        if ($baseUnitId <= 0) {

            productFlash(
                'Base unit is required.',
                'error'
            );

            header('Location: products.php');
            exit;
        }


        if ($costPrice < 0) {
            $costPrice = 0;
        }

        if ($sellingPrice < 0) {
            $sellingPrice = 0;
        }

        if ($minimumStock < 0) {
            $minimumStock = 0;
        }


        if (
            !in_array(
                $status,
                ['Active', 'Inactive'],
                true
            )
        ) {
            $status = 'Active';
        }


        try {

            /*
            |--------------------------------------------------------------------------
            | GET OLD PRODUCT
            |--------------------------------------------------------------------------
            */

            $oldStmt =
                $conn->prepare("
                    SELECT *
                    FROM products
                    WHERE id = ?
                    AND business_id = ?
                    LIMIT 1
                ");

            $oldStmt->execute([
                $productId,
                $businessId
            ]);


            $oldProduct =
                $oldStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$oldProduct) {

                productFlash(
                    'Product not found.',
                    'error'
                );

                header('Location: products.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY CATEGORY
            |--------------------------------------------------------------------------
            */

            if ($categoryId !== null) {

                $categoryCheck =
                    $conn->prepare("
                        SELECT id
                        FROM categories
                        WHERE id = ?
                        AND business_id = ?
                        LIMIT 1
                    ");

                $categoryCheck->execute([
                    $categoryId,
                    $businessId
                ]);


                if (!$categoryCheck->fetch()) {

                    productFlash(
                        'Selected category is invalid.',
                        'error'
                    );

                    header('Location: products.php');
                    exit;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY BASE UNIT
            |--------------------------------------------------------------------------
            */

            $unitCheck =
                $conn->prepare("
                    SELECT id
                    FROM units
                    WHERE id = ?
                    AND business_id = ?
                    LIMIT 1
                ");

            $unitCheck->execute([
                $baseUnitId,
                $businessId
            ]);


            if (!$unitCheck->fetch()) {

                productFlash(
                    'Selected base unit is invalid.',
                    'error'
                );

                header('Location: products.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | PRODUCT CODE
            |--------------------------------------------------------------------------
            */

            if ($productCode === '') {

                $productCode =
                    $oldProduct['product_code'];
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE CODE
            |--------------------------------------------------------------------------
            */

            $codeCheck =
                $conn->prepare("
                    SELECT id
                    FROM products
                    WHERE business_id = ?
                    AND product_code = ?
                    AND id <> ?
                    LIMIT 1
                ");

            $codeCheck->execute([
                $businessId,
                $productCode,
                $productId
            ]);


            if ($codeCheck->fetch()) {

                productFlash(
                    'Another product already uses this product code.',
                    'error'
                );

                header('Location: products.php');
                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE BARCODE
            |--------------------------------------------------------------------------
            */

            if ($barcode !== '') {

                $barcodeCheck =
                    $conn->prepare("
                        SELECT id
                        FROM products
                        WHERE business_id = ?
                        AND barcode = ?
                        AND id <> ?
                        LIMIT 1
                    ");

                $barcodeCheck->execute([
                    $businessId,
                    $barcode,
                    $productId
                ]);


                if ($barcodeCheck->fetch()) {

                    productFlash(
                        'Another product already uses this barcode.',
                        'error'
                    );

                    header('Location: products.php');
                    exit;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | BEGIN TRANSACTION
            |--------------------------------------------------------------------------
            */

            $conn->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | UPDATE PRODUCT
            |--------------------------------------------------------------------------
            */

            $update =
                $conn->prepare("
                    UPDATE products

                    SET
                        category_id = ?,
                        base_unit_id = ?,
                        product_name = ?,
                        product_code = ?,
                        barcode = ?,
                        description = ?,
                        cost_price = ?,
                        selling_price = ?,
                        minimum_stock = ?,
                        track_expiry = ?,
                        status = ?

                    WHERE id = ?
                    AND business_id = ?
                ");


            $update->execute([
                $categoryId,
                $baseUnitId,
                $productName,
                $productCode,
                $barcode !== ''
                    ? $barcode
                    : null,
                $description !== ''
                    ? $description
                    : null,
                $costPrice,
                $sellingPrice,
                $minimumStock,
                $trackExpiry,
                $status,
                $productId,
                $businessId
            ]);


            /*
            |--------------------------------------------------------------------------
            | REMOVE OLD BASE FLAG
            |--------------------------------------------------------------------------
            */

            $removeBase =
                $conn->prepare("
                    UPDATE product_units
                    SET is_base_unit = 0
                    WHERE business_id = ?
                    AND product_id = ?
                ");

            $removeBase->execute([
                $businessId,
                $productId
            ]);


            /*
            |--------------------------------------------------------------------------
            | FIND NEW BASE UNIT
            |--------------------------------------------------------------------------
            */

            $findBase =
                $conn->prepare("
                    SELECT id
                    FROM product_units
                    WHERE business_id = ?
                    AND product_id = ?
                    AND unit_id = ?
                    LIMIT 1
                ");

            $findBase->execute([
                $businessId,
                $productId,
                $baseUnitId
            ]);


            $baseRow =
                $findBase->fetch(
                    PDO::FETCH_ASSOC
                );


            if ($baseRow) {

                /*
                |--------------------------------------------------------------------------
                | UPDATE EXISTING BASE UNIT
                |--------------------------------------------------------------------------
                */

                $updateBase =
                    $conn->prepare("
                        UPDATE product_units

                        SET
                            conversion_qty = 1.000,
                            selling_price = ?,
                            purchase_price = ?,
                            is_base_unit = 1,
                            can_purchase = 1,
                            can_sell = 1,
                            status = 'Active'

                        WHERE id = ?
                        AND business_id = ?
                    ");


                $updateBase->execute([
                    $sellingPrice,
                    $costPrice,
                    $baseRow['id'],
                    $businessId
                ]);


            } else {

                /*
                |--------------------------------------------------------------------------
                | INSERT NEW BASE UNIT
                |--------------------------------------------------------------------------
                */

                $insertBase =
                    $conn->prepare("
                        INSERT INTO product_units
                        (
                            business_id,
                            product_id,
                            unit_id,
                            conversion_qty,
                            selling_price,
                            purchase_price,
                            is_base_unit,
                            can_purchase,
                            can_sell,
                            status
                        )
                        VALUES
                        (
                            ?, ?, ?, 1.000,
                            ?, ?, 1, 1, 1, 'Active'
                        )
                    ");


                $insertBase->execute([
                    $businessId,
                    $productId,
                    $baseUnitId,
                    $sellingPrice,
                    $costPrice
                ]);
            }


            $conn->commit();


            /*
            |--------------------------------------------------------------------------
            | AUDIT
            |--------------------------------------------------------------------------
            */

            if (function_exists('logActivity')) {

                try {

                    logActivity(
                        'UPDATE',
                        'Products',
                        $productId,
                        'Updated product: ' .
                        $productName
                    );

                } catch (Throwable $e) {
                    // Do not break main operation.
                }
            }


            productFlash(
                'Product updated successfully.'
            );


        } catch (PDOException $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }


            if (
                (string) $e->getCode()
                === '23000'
            ) {

                productFlash(
                    'Product code or barcode already exists.',
                    'error'
                );

            } else {

                productFlash(
                    'Unable to update product.',
                    'error'
                );
            }
        }


        header('Location: products.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TOGGLE STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_status') {

        if (!$canUpdateProduct) {
            http_response_code(403);
            exit('Access denied.');
        }


        $productId =
            (int) ($_POST['product_id'] ?? 0);


        if ($productId <= 0) {

            productFlash(
                'Invalid product.',
                'error'
            );

            header('Location: products.php');
            exit;
        }


        try {

            $stmt =
                $conn->prepare("
                    SELECT
                        id,
                        product_name,
                        status
                    FROM products
                    WHERE id = ?
                    AND business_id = ?
                    LIMIT 1
                ");

            $stmt->execute([
                $productId,
                $businessId
            ]);


            $product =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$product) {

                productFlash(
                    'Product not found.',
                    'error'
                );

                header('Location: products.php');
                exit;
            }


            $newStatus =
                $product['status'] === 'Active'
                    ? 'Inactive'
                    : 'Active';


            $update =
                $conn->prepare("
                    UPDATE products
                    SET status = ?
                    WHERE id = ?
                    AND business_id = ?
                ");

            $update->execute([
                $newStatus,
                $productId,
                $businessId
            ]);


            if (function_exists('logActivity')) {

                try {

                    logActivity(
                        'UPDATE',
                        'Products',
                        $productId,
                        'Changed product status to ' .
                        $newStatus .
                        ': ' .
                        $product['product_name']
                    );

                } catch (Throwable $e) {
                }
            }


            productFlash(
                'Product status updated successfully.'
            );


        } catch (PDOException $e) {

            productFlash(
                'Unable to update product status.',
                'error'
            );
        }


        header('Location: products.php');
        exit;
    }




    /*
    |--------------------------------------------------------------------------
    | ADD PRODUCT UNIT
    |--------------------------------------------------------------------------
    */
    if ($action === 'add_product_unit') {
        if (!$canUpdateProduct) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied.'
                ]);
                exit;
            }

            http_response_code(403);
            exit('Access denied.');
        }

        $productId = (int) ($_POST['product_id'] ?? 0);
        $unitId = (int) ($_POST['unit_id'] ?? 0);
        $conversionQty = (float) ($_POST['conversion_qty'] ?? 0);
        $purchasePrice = max(0, (float) ($_POST['purchase_price'] ?? 0));
        $sellingPrice = max(0, (float) ($_POST['selling_price'] ?? 0));
        $canPurchase = isset($_POST['can_purchase']) ? 1 : 0;
        $canSell = isset($_POST['can_sell']) ? 1 : 0;
        $status = $_POST['status'] ?? 'Active';

        if ($productId <= 0 || $unitId <= 0 || $conversionQty <= 0) {
            $message = 'Please select a valid product, unit and conversion quantity.';

            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => $message,
                    'debug' => [
                        'product_id' => $productId,
                        'unit_id' => $unitId,
                        'conversion_qty' => $conversionQty
                    ]
                ]);
                exit;
            }

            productFlash($message, 'error');
            header('Location: products.php');
            exit;
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        try {
            $productCheck = $conn->prepare("
                SELECT id, product_name
                FROM products
                WHERE id = ? AND business_id = ?
                LIMIT 1
            ");
            $productCheck->execute([$productId, $businessId]);
            $product = $productCheck->fetch(PDO::FETCH_ASSOC);

            $unitCheck = $conn->prepare("
                SELECT id, unit_name, short_name
                FROM units
                WHERE id = ? AND business_id = ?
                LIMIT 1
            ");
            $unitCheck->execute([$unitId, $businessId]);
            $unit = $unitCheck->fetch(PDO::FETCH_ASSOC);

            if (!$product || !$unit) {
                $message = 'Selected product or unit is invalid.';

                if ($isAjaxRequest) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'message' => $message
                    ]);
                    exit;
                }

                productFlash($message, 'error');
                header('Location: products.php');
                exit;
            }

            $duplicateCheck = $conn->prepare("
                SELECT id
                FROM product_units
                WHERE business_id = ?
                AND product_id = ?
                AND unit_id = ?
                LIMIT 1
            ");
            $duplicateCheck->execute([$businessId, $productId, $unitId]);

            if ($duplicateCheck->fetch()) {
                $message = 'This unit already exists for this product.';

                if ($isAjaxRequest) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'message' => $message
                    ]);
                    exit;
                }

                productFlash($message, 'error');
                header('Location: products.php');
                exit;
            }

            $insert = $conn->prepare("
                INSERT INTO product_units
                (
                    business_id,
                    product_id,
                    unit_id,
                    conversion_qty,
                    selling_price,
                    purchase_price,
                    is_base_unit,
                    can_purchase,
                    can_sell,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
            ");

            $insert->execute([
                $businessId,
                $productId,
                $unitId,
                $conversionQty,
                $sellingPrice,
                $purchasePrice,
                $canPurchase,
                $canSell,
                $status
            ]);

            $productUnitId = (int) $conn->lastInsertId();

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'CREATE',
                        'Product Units',
                        $productUnitId,
                        'Added unit ' . $unit['unit_name'] .
                        ' to product ' . $product['product_name']
                    );
                } catch (Throwable $e) {}
            }

            if ($isAjaxRequest) {
                $freshStmt = $conn->prepare("
                    SELECT
                        pu.id,
                        pu.product_id,
                        pu.unit_id,
                        pu.conversion_qty,
                        pu.purchase_price,
                        pu.selling_price,
                        pu.is_base_unit,
                        pu.can_purchase,
                        pu.can_sell,
                        pu.status,
                        u.unit_name,
                        u.short_name
                    FROM product_units pu
                    INNER JOIN units u
                        ON u.id = pu.unit_id
                        AND u.business_id = pu.business_id
                    WHERE pu.id = ?
                    AND pu.business_id = ?
                    LIMIT 1
                ");
                $freshStmt->execute([$productUnitId, $businessId]);
                $freshUnit = $freshStmt->fetch(PDO::FETCH_ASSOC);

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => 'Product unit added successfully.',
                    'unit' => $freshUnit
                ]);
                exit;
            }

            productFlash('Product unit added successfully.');

        } catch (PDOException $e) {
            $message = (string) $e->getCode() === '23000'
                ? 'This unit already exists for this product.'
                : 'Unable to add product unit.';

            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => $message
                ]);
                exit;
            }

            productFlash($message, 'error');
        }

        header('Location: products.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE PRODUCT UNIT
    |--------------------------------------------------------------------------
    */
    if ($action === 'update_product_unit') {
        if (!$canUpdateProduct) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied.'
                ]);
                exit;
            }

            http_response_code(403);
            exit('Access denied.');
        }

        $productUnitId = (int) ($_POST['product_unit_id'] ?? 0);
        $unitId = (int) ($_POST['unit_id'] ?? 0);
        $conversionQty = (float) ($_POST['conversion_qty'] ?? 0);
        $purchasePrice = max(0, (float) ($_POST['purchase_price'] ?? 0));
        $sellingPrice = max(0, (float) ($_POST['selling_price'] ?? 0));
        $canPurchase = isset($_POST['can_purchase']) ? 1 : 0;
        $canSell = isset($_POST['can_sell']) ? 1 : 0;
        $status = $_POST['status'] ?? 'Active';

        if ($productUnitId <= 0 || $unitId <= 0 || $conversionQty <= 0) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid product unit, unit or conversion quantity.'
                ]);
                exit;
            }

            productFlash('Invalid product unit, unit or conversion quantity.', 'error');
            header('Location: products.php');
            exit;
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        try {
            $check = $conn->prepare("
                SELECT
                    pu.id,
                    pu.product_id,
                    pu.unit_id,
                    pu.is_base_unit,
                    p.product_name,
                    u.unit_name,
                    u.short_name
                FROM product_units pu
                INNER JOIN products p
                    ON p.id = pu.product_id
                    AND p.business_id = pu.business_id
                INNER JOIN units u
                    ON u.id = pu.unit_id
                    AND u.business_id = pu.business_id
                WHERE pu.id = ?
                AND pu.business_id = ?
                LIMIT 1
            ");
            $check->execute([$productUnitId, $businessId]);
            $productUnit = $check->fetch(PDO::FETCH_ASSOC);

            if (!$productUnit) {
                if ($isAjaxRequest) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(404);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Product unit not found.'
                    ]);
                    exit;
                }

                productFlash('Product unit not found.', 'error');
                header('Location: products.php');
                exit;
            }

            // Verify the selected unit belongs to this business for BOTH base and normal units.
            $unitCheck = $conn->prepare("
                SELECT id, unit_name, short_name
                FROM units
                WHERE id = ?
                AND business_id = ?
                LIMIT 1
            ");
            $unitCheck->execute([$unitId, $businessId]);
            $selectedUnit = $unitCheck->fetch(PDO::FETCH_ASSOC);

            if (!$selectedUnit) {
                if ($isAjaxRequest) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Selected unit is invalid.'
                    ]);
                    exit;
                }

                productFlash('Selected unit is invalid.', 'error');
                header('Location: products.php');
                exit;
            }

            // Prevent duplicate unit on the same product, including when changing Base Unit.
            $duplicateCheck = $conn->prepare("
                SELECT id
                FROM product_units
                WHERE business_id = ?
                AND product_id = ?
                AND unit_id = ?
                AND id <> ?
                LIMIT 1
            ");
            $duplicateCheck->execute([
                $businessId,
                $productUnit['product_id'],
                $unitId,
                $productUnitId
            ]);

            if ($duplicateCheck->fetch()) {
                if ($isAjaxRequest) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'message' => 'This unit already exists for this product.'
                    ]);
                    exit;
                }

                productFlash('This unit already exists for this product.', 'error');
                header('Location: products.php');
                exit;
            }

            $isBaseUnit = (int) $productUnit['is_base_unit'] === 1;

            // Base Unit always represents itself.
            if ($isBaseUnit) {
                $conversionQty = 1;
                $canPurchase = 1;
                $canSell = 1;
                $status = 'Active';
            }

            // Keep Product Unit and Product master synchronized atomically.
            $conn->beginTransaction();

            $update = $conn->prepare("
                UPDATE product_units
                SET
                    unit_id = ?,
                    conversion_qty = ?,
                    selling_price = ?,
                    purchase_price = ?,
                    can_purchase = ?,
                    can_sell = ?,
                    status = ?
                WHERE id = ?
                AND business_id = ?
            ");
            $update->execute([
                $unitId,
                $conversionQty,
                $sellingPrice,
                $purchasePrice,
                $canPurchase,
                $canSell,
                $status,
                $productUnitId,
                $businessId
            ]);

            if ($isBaseUnit) {
                $updateProduct = $conn->prepare("
                    UPDATE products
                    SET
                        base_unit_id = ?,
                        cost_price = ?,
                        selling_price = ?
                    WHERE id = ?
                    AND business_id = ?
                ");
                $updateProduct->execute([
                    $unitId,
                    $purchasePrice,
                    $sellingPrice,
                    $productUnit['product_id'],
                    $businessId
                ]);
            }

            $conn->commit();

            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'UPDATE',
                        'Product Units',
                        $productUnitId,
                        'Updated unit ' . $productUnit['unit_name'] .
                        ' for product ' . $productUnit['product_name']
                    );
                } catch (Throwable $e) {}
            }

            if ($isAjaxRequest) {
                $freshStmt = $conn->prepare("
                    SELECT
                        pu.id,
                        pu.product_id,
                        pu.unit_id,
                        pu.conversion_qty,
                        pu.purchase_price,
                        pu.selling_price,
                        pu.is_base_unit,
                        pu.can_purchase,
                        pu.can_sell,
                        pu.status,
                        u.unit_name,
                        u.short_name
                    FROM product_units pu
                    INNER JOIN units u
                        ON u.id = pu.unit_id
                        AND u.business_id = pu.business_id
                    WHERE pu.id = ?
                    AND pu.business_id = ?
                    LIMIT 1
                ");
                $freshStmt->execute([$productUnitId, $businessId]);
                $freshUnit = $freshStmt->fetch(PDO::FETCH_ASSOC);

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => 'Product unit updated successfully.',
                    'unit' => $freshUnit
                ]);
                exit;
            }

            productFlash('Product unit updated successfully.');

        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => (string) $e->getCode() === '23000'
                        ? 'This unit already exists for this product.'
                        : 'Unable to update product unit.'
                ]);
                exit;
            }

            productFlash('Unable to update product unit.', 'error');
        }

        header('Location: products.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE PRODUCT UNIT
    |--------------------------------------------------------------------------
    */
    if ($action === 'delete_product_unit') {
        if (!$canUpdateProduct) {
            http_response_code(403);
            exit('Access denied.');
        }

        $productUnitId = (int) ($_POST['product_unit_id'] ?? 0);
        if ($productUnitId <= 0) {
            productFlash('Invalid product unit.', 'error');
            header('Location: products.php');
            exit;
        }

        try {
            $check = $conn->prepare("
                SELECT pu.id, pu.is_base_unit, p.product_name, u.unit_name
                FROM product_units pu
                INNER JOIN products p ON p.id = pu.product_id AND p.business_id = pu.business_id
                INNER JOIN units u ON u.id = pu.unit_id AND u.business_id = pu.business_id
                WHERE pu.id = ? AND pu.business_id = ?
                LIMIT 1
            ");
            $check->execute([$productUnitId, $businessId]);
            $productUnit = $check->fetch(PDO::FETCH_ASSOC);

            if (!$productUnit) {
                productFlash('Product unit not found.', 'error');
                header('Location: products.php');
                exit;
            }

            if ((int) $productUnit['is_base_unit'] === 1) {
                productFlash('Base unit cannot be deleted.', 'error');
                header('Location: products.php');
                exit;
            }

            $delete = $conn->prepare("
                DELETE FROM product_units
                WHERE id = ? AND business_id = ? AND is_base_unit = 0
            ");
            $delete->execute([$productUnitId, $businessId]);

            if (function_exists('logActivity')) {
                try {
                    logActivity('DELETE', 'Product Units', $productUnitId, 'Deleted unit ' . $productUnit['unit_name'] . ' from product ' . $productUnit['product_name']);
                } catch (Throwable $e) {}
            }
            productFlash('Product unit deleted successfully.');
        } catch (PDOException $e) {
            productFlash((string) $e->getCode() === '23000' ? 'This unit is already used and cannot be deleted.' : 'Unable to delete product unit.', 'error');
        }

        header('Location: products.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE PRODUCT
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        if (!$canDeleteProduct) {
            http_response_code(403);
            exit('Access denied.');
        }


        $productId =
            (int) ($_POST['product_id'] ?? 0);


        if ($productId <= 0) {

            productFlash(
                'Invalid product.',
                'error'
            );

            header('Location: products.php');
            exit;
        }


        try {

            $stmt =
                $conn->prepare("
                    SELECT
                        id,
                        product_name,
                        product_code
                    FROM products
                    WHERE id = ?
                    AND business_id = ?
                    LIMIT 1
                ");

            $stmt->execute([
                $productId,
                $businessId
            ]);


            $product =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$product) {

                productFlash(
                    'Product not found.',
                    'error'
                );

                header('Location: products.php');
                exit;
            }


            $delete =
                $conn->prepare("
                    DELETE FROM products
                    WHERE id = ?
                    AND business_id = ?
                ");

            $delete->execute([
                $productId,
                $businessId
            ]);


            if (function_exists('logActivity')) {

                try {

                    logActivity(
                        'DELETE',
                        'Products',
                        $productId,
                        'Deleted product: ' .
                        $product['product_name']
                    );

                } catch (Throwable $e) {
                }
            }


            productFlash(
                'Product deleted successfully.'
            );


        } catch (PDOException $e) {

            if (
                (string) $e->getCode()
                === '23000'
            ) {

                productFlash(
                    'This product is already used and cannot be deleted.',
                    'error'
                );

            } else {

                productFlash(
                    'Unable to delete product.',
                    'error'
                );
            }
        }


        header('Location: products.php');
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| LOAD CATEGORIES
|--------------------------------------------------------------------------
*/

$categoryStmt =
    $conn->prepare("
        SELECT
            id,
            category_name,
            status
        FROM categories
        WHERE business_id = ?
        ORDER BY category_name ASC
    ");

$categoryStmt->execute([
    $businessId
]);

$categories =
    $categoryStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| LOAD UNITS
|--------------------------------------------------------------------------
*/

$unitStmt =
    $conn->prepare("
        SELECT
            id,
            unit_name,
            short_name,
            status
        FROM units
        WHERE business_id = ?
        ORDER BY unit_name ASC
    ");

$unitStmt->execute([
    $businessId
]);

$units =
    $unitStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| LOAD PRODUCTS
|--------------------------------------------------------------------------
*/

$productStmt =
    $conn->prepare("
        SELECT
            p.id,
            p.product_name,
            p.product_code,
            p.barcode,
            p.category_id,
            p.base_unit_id,
            p.description,
            p.cost_price,
            p.selling_price,
            p.minimum_stock,
            p.track_expiry,
            p.status,
            p.created_at,

            c.category_name,

            u.unit_name,
            u.short_name

        FROM products p

        LEFT JOIN categories c
            ON c.id = p.category_id
            AND c.business_id = p.business_id

        INNER JOIN units u
            ON u.id = p.base_unit_id
            AND u.business_id = p.business_id

        WHERE p.business_id = ?

        ORDER BY p.id DESC
    ");

$productStmt->execute([
    $businessId
]);

$products =
    $productStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| LOAD PRODUCT UNITS
|--------------------------------------------------------------------------
*/
$productUnitsStmt = $conn->prepare("
    SELECT
        pu.id, pu.product_id, pu.unit_id, pu.conversion_qty,
        pu.purchase_price, pu.selling_price, pu.is_base_unit,
        pu.can_purchase, pu.can_sell, pu.status,
        u.unit_name, u.short_name
    FROM product_units pu
    INNER JOIN units u
        ON u.id = pu.unit_id
        AND u.business_id = pu.business_id
    WHERE pu.business_id = ?
    ORDER BY pu.product_id ASC, pu.is_base_unit DESC, pu.conversion_qty ASC
");
$productUnitsStmt->execute([$businessId]);
$allProductUnits = $productUnitsStmt->fetchAll(PDO::FETCH_ASSOC);
$productUnitsByProduct = [];
foreach ($allProductUnits as $productUnit) {
    $pid = (int) $productUnit['product_id'];
    if (!isset($productUnitsByProduct[$pid])) {
        $productUnitsByProduct[$pid] = [];
    }
    $productUnitsByProduct[$pid][] = $productUnit;
}



/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$totalProducts = count($products);

$activeProducts = 0;
$inactiveProducts = 0;
$expiryProducts = 0;


foreach ($products as $product) {

    if ($product['status'] === 'Active') {
        $activeProducts++;
    } else {
        $inactiveProducts++;
    }


    if (
        (int) $product['track_expiry']
        === 1
    ) {
        $expiryProducts++;
    }
}


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

$flashMessage =
    $_SESSION['product_flash']
    ?? null;

$flashType =
    $_SESSION['product_flash_type']
    ?? 'success';

unset(
    $_SESSION['product_flash'],
    $_SESSION['product_flash_type']
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
        Products - <?= productE($businessName) ?>
    </title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
    >

    <script src="https://cdn.tailwindcss.com"></script>

    <script src="https://unpkg.com/lucide@latest"></script>

    <script>
        tailwind.config = {
            darkMode: 'class'
        };
    </script>

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


<?php
require_once
    __DIR__ .
    '/includes/sidebar.php';
?>


<div
    class="
        min-h-screen
        lg:pl-64
    "
>


<?php
require_once
    __DIR__ .
    '/includes/header.php';
?>


<main class="p-4 sm:p-5 lg:p-6">


    <!-- PAGE HEADER -->

    <div
        class="
            mb-5
            flex
            flex-col
            gap-3
            sm:flex-row
            sm:items-center
            sm:justify-between
        "
    >

        <div>

            <h1
                class="
                    text-lg
                    font-extrabold
                    text-slate-900
                    dark:text-white
                "
            >
                Products
            </h1>

            <p
                class="
                    mt-1
                    text-[10px]
                    text-slate-400
                "
            >
                Manage your business products.
            </p>

        </div>


        <?php if ($canAddProduct): ?>

            <button
                type="button"
                onclick="openAddProductModal()"
                class="
                    inline-flex
                    h-9
                    items-center
                    justify-center
                    gap-2
                    rounded-xl
                    bg-blue-600
                    px-4
                    text-[10px]
                    font-bold
                    text-white
                    shadow-sm
                    transition
                    hover:bg-blue-700
                "
            >

                <i
                    data-lucide="plus"
                    class="h-4 w-4"
                ></i>

                Add Product

            </button>

        <?php endif; ?>

    </div>


    <!-- FLASH MESSAGE -->

    <?php if ($flashMessage): ?>

        <div
            id="productFlash"
            class="
                mb-5
                flex
                items-center
                gap-3
                rounded-xl
                border
                px-4
                py-3
                text-[10px]
                font-semibold
                transition-all
                duration-300

                <?= $flashType === 'error'
                    ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300'
                ?>
            "
        >

            <i
                data-lucide="<?= $flashType === 'error'
                    ? 'circle-alert'
                    : 'circle-check'
                ?>"
                class="h-4 w-4"
            ></i>

            <?= productE($flashMessage) ?>

        </div>

    <?php endif; ?>


    <!-- STATISTICS -->

    <div
        class="
            mb-5
            grid
            grid-cols-2
            gap-3
            xl:grid-cols-4
        "
    >


        <!-- TOTAL -->

        <div class="product-stat-card">

            <div
                class="
                    flex
                    items-center
                    justify-between
                "
            >

                <div>

                    <p class="product-stat-label">
                        Total Products
                    </p>

                    <p class="product-stat-value">
                        <?= number_format(
                            $totalProducts
                        ) ?>
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
                        data-lucide="package"
                        class="h-5 w-5"
                    ></i>

                </div>

            </div>

        </div>


        <!-- ACTIVE -->

        <div class="product-stat-card">

            <p class="product-stat-label">
                Active
            </p>

            <p
                class="
                    mt-2
                    text-xl
                    font-extrabold
                    text-emerald-600
                "
            >
                <?= number_format(
                    $activeProducts
                ) ?>
            </p>

        </div>


        <!-- INACTIVE -->

        <div class="product-stat-card">

            <p class="product-stat-label">
                Inactive
            </p>

            <p
                class="
                    mt-2
                    text-xl
                    font-extrabold
                    text-red-500
                "
            >
                <?= number_format(
                    $inactiveProducts
                ) ?>
            </p>

        </div>


        <!-- EXPIRY -->

        <div class="product-stat-card">

            <p class="product-stat-label">
                Expiry Tracked
            </p>

            <p
                class="
                    mt-2
                    text-xl
                    font-extrabold
                    text-amber-500
                "
            >
                <?= number_format(
                    $expiryProducts
                ) ?>
            </p>

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


        <!-- SEARCH HEADER -->

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
                        text-slate-900
                        dark:text-white
                    "
                >
                    Product List
                </h2>

                <p
                    class="
                        mt-1
                        text-[9px]
                        text-slate-400
                    "
                >
                    Search products automatically.
                </p>

            </div>


            <div
                class="
                    relative
                    w-full
                    sm:w-72
                "
            >

                <i
                    data-lucide="search"
                    class="
                        absolute
                        left-3
                        top-1/2
                        h-3.5
                        w-3.5
                        -translate-y-1/2
                        text-slate-400
                    "
                ></i>


                <input
                    type="text"
                    id="productSearch"
                    autocomplete="off"
                    placeholder="Search ID, name, code, barcode..."
                    class="
                        h-9
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
                        focus:bg-white
                        dark:border-slate-700
                        dark:bg-slate-950
                    "
                >

            </div>

        </div>


        <!-- TABLE -->

        <div class="overflow-x-auto">

            <table
                class="
                    w-full
                    min-w-[1100px]
                "
            >

                <thead
                    class="
                        bg-slate-50
                        text-left
                        dark:bg-slate-950/60
                    "
                >

                    <tr
                        class="
                            text-[9px]
                            uppercase
                            tracking-wider
                            text-slate-400
                        "
                    >

                        <th class="px-5 py-3">
                            ID
                        </th>

                        <th class="px-5 py-3">
                            Product
                        </th>

                        <th class="px-5 py-3">
                            Code
                        </th>

                        <th class="px-5 py-3">
                            Category
                        </th>

                        <th class="px-5 py-3">
                            Base Unit
                        </th>

                        <th class="px-5 py-3">
                            Cost
                        </th>

                        <th class="px-5 py-3">
                            Selling
                        </th>

                        <th class="px-5 py-3">
                            Min Stock
                        </th>

                        <th class="px-5 py-3">
                            Expiry
                        </th>

                        <th class="px-5 py-3">
                            Status
                        </th>


                        <?php if (
                            $canUpdateProduct ||
                            $canDeleteProduct
                        ): ?>

                            <th
                                class="
                                    px-5
                                    py-3
                                    text-center
                                "
                            >
                                Actions
                            </th>

                        <?php endif; ?>

                    </tr>

                </thead>


                <tbody id="productTableBody">


                <?php if (!$products): ?>

                    <tr>

                        <td
                            colspan="<?= (
                                $canUpdateProduct ||
                                $canDeleteProduct
                            ) ? 11 : 10 ?>"
                            class="
                                px-5
                                py-14
                                text-center
                            "
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
                                    data-lucide="package-open"
                                    class="h-5 w-5"
                                ></i>

                            </div>


                            <p
                                class="
                                    mt-3
                                    text-[11px]
                                    font-bold
                                    text-slate-700
                                    dark:text-slate-300
                                "
                            >
                                No products found.
                            </p>


                            <p
                                class="
                                    mt-1
                                    text-[9px]
                                    text-slate-400
                                "
                            >
                                Add your first product.
                            </p>

                        </td>

                    </tr>


                <?php else: ?>


                    <?php foreach (
                        $products as $product
                    ): ?>


                        <?php

                        $searchText =
                            $product['id'] . ' ' .
                            $product['product_name'] . ' ' .
                            ($product['product_code'] ?? '') . ' ' .
                            ($product['barcode'] ?? '') . ' ' .
                            ($product['category_name'] ?? '') . ' ' .
                            ($product['unit_name'] ?? '') . ' ' .
                            ($product['short_name'] ?? '') . ' ' .
                            ($product['description'] ?? '') . ' ' .
                            $product['status'];

                        ?>


                        <tr
                            class="
                                product-row
                                border-t
                                border-slate-100
                                text-[10px]
                                transition
                                hover:bg-slate-50/70
                                dark:border-slate-800
                                dark:hover:bg-slate-800/40
                            "
                            data-search="<?= productE(
                                strtolower(
                                    $searchText
                                )
                            ) ?>"
                        >


                            <!-- ID -->

                            <td
                                class="
                                    px-5
                                    py-4
                                    font-bold
                                    text-slate-400
                                "
                            >
                                #<?= (int) $product['id'] ?>
                            </td>


                            <!-- PRODUCT -->

                            <td class="px-5 py-4">

                                <p
                                    class="
                                        max-w-[220px]
                                        truncate
                                        font-extrabold
                                        text-slate-800
                                        dark:text-slate-100
                                    "
                                >
                                    <?= productE(
                                        $product['product_name']
                                    ) ?>
                                </p>


                                <?php if (
                                    !empty(
                                        $product['barcode']
                                    )
                                ): ?>

                                    <p
                                        class="
                                            mt-1
                                            text-[9px]
                                            text-slate-400
                                        "
                                    >
                                        Barcode:
                                        <?= productE(
                                            $product['barcode']
                                        ) ?>
                                    </p>

                                <?php endif; ?>

                            </td>


                            <!-- CODE -->

                            <td
                                class="
                                    px-5
                                    py-4
                                    font-semibold
                                "
                            >
                                <?= productE(
                                    $product['product_code']
                                    ?: '-'
                                ) ?>
                            </td>


                            <!-- CATEGORY -->

                            <td class="px-5 py-4">

                                <?= productE(
                                    $product['category_name']
                                    ?: 'Uncategorized'
                                ) ?>

                            </td>


                            <!-- UNIT -->

                            <td class="px-5 py-4">

                                <span
                                    class="
                                        inline-flex
                                        rounded-lg
                                        bg-blue-50
                                        px-2
                                        py-1
                                        font-bold
                                        text-blue-600
                                        dark:bg-blue-950/40
                                        dark:text-blue-300
                                    "
                                >
                                    <?= productE(
                                        $product['short_name']
                                    ) ?>
                                </span>

                            </td>


                            <!-- COST -->

                            <td
                                class="
                                    px-5
                                    py-4
                                    font-bold
                                "
                            >
                                $<?= number_format(
                                    (float)
                                    $product['cost_price'],
                                    2
                                ) ?>
                            </td>


                            <!-- SELLING -->

                            <td
                                class="
                                    px-5
                                    py-4
                                    font-extrabold
                                    text-emerald-600
                                "
                            >
                                $<?= number_format(
                                    (float)
                                    $product['selling_price'],
                                    2
                                ) ?>
                            </td>


                            <!-- MIN STOCK -->

                            <td class="px-5 py-4">

                                <?= number_format(
                                    (float)
                                    $product['minimum_stock'],
                                    3
                                ) ?>

                            </td>


                            <!-- EXPIRY -->

                            <td class="px-5 py-4">

                                <?php if (
                                    (int)
                                    $product['track_expiry']
                                    === 1
                                ): ?>

                                    <span
                                        class="
                                            inline-flex
                                            rounded-lg
                                            bg-amber-50
                                            px-2
                                            py-1
                                            font-bold
                                            text-amber-600
                                            dark:bg-amber-950/40
                                        "
                                    >
                                        Yes
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="
                                            inline-flex
                                            rounded-lg
                                            bg-slate-100
                                            px-2
                                            py-1
                                            font-bold
                                            text-slate-500
                                            dark:bg-slate-800
                                        "
                                    >
                                        No
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- STATUS -->

                            <td class="px-5 py-4">

                                <span
                                    class="
                                        inline-flex
                                        rounded-full
                                        px-2.5
                                        py-1
                                        text-[9px]
                                        font-extrabold

                                        <?= $product['status']
                                            === 'Active'
                                            ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40'
                                            : 'bg-red-50 text-red-600 dark:bg-red-950/40'
                                        ?>
                                    "
                                >

                                    <?= productE(
                                        $product['status']
                                    ) ?>

                                </span>

                            </td>


                            <!-- ACTIONS -->

                            <?php if (
                                $canUpdateProduct ||
                                $canDeleteProduct
                            ): ?>

                                <td class="px-5 py-4">

                                    <div
                                        class="
                                            flex
                                            items-center
                                            justify-center
                                            gap-1
                                        "
                                    >


                                        <?php if (
                                            $canUpdateProduct
                                        ): ?>

                                            <button
                                                type="button"
                                                title="Manage Units"
                                                onclick='openManageUnitsModal(
                                                    <?= json_encode(
                                                        [
                                                            'id' => (int) $product['id'],
                                                            'product_name' => $product['product_name'],
                                                            'base_unit_id' => (int) $product['base_unit_id'],
                                                            'cost_price' => (float) $product['cost_price'],
                                                            'selling_price' => (float) $product['selling_price'],
                                                            'units' => $productUnitsByProduct[(int) $product['id']] ?? []
                                                        ],
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_AMP |
                                                        JSON_HEX_QUOT
                                                    ) ?>
                                                )'
                                                class="
                                                    flex h-8 w-8 items-center justify-center rounded-lg
                                                    text-violet-600 transition hover:bg-violet-50
                                                    dark:hover:bg-violet-950/40
                                                "
                                            >
                                                <i data-lucide="package-plus" class="h-3.5 w-3.5"></i>
                                            </button>


                                            <button
                                                type="button"
                                                title="Edit"
                                                onclick='openEditProductModal(
                                                    <?= json_encode(
                                                        $product,
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_AMP |
                                                        JSON_HEX_QUOT
                                                    ) ?>
                                                )'
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


                                            <form
                                                method="POST"
                                                onsubmit="return confirmProductStatus(
                                                    '<?= productE(
                                                        $product['status']
                                                    ) ?>'
                                                );"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= productE(
                                                        $csrfToken
                                                    ) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle_status"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="product_id"
                                                    value="<?= (int)
                                                        $product['id']
                                                    ?>"
                                                >


                                                <button
                                                    type="submit"
                                                    title="<?= $product['status']
                                                        === 'Active'
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
                                                        text-amber-600
                                                        transition
                                                        hover:bg-amber-50
                                                        dark:hover:bg-amber-950/40
                                                    "
                                                >

                                                    <i
                                                        data-lucide="<?= $product['status']
                                                            === 'Active'
                                                            ? 'circle-pause'
                                                            : 'circle-play'
                                                        ?>"
                                                        class="h-3.5 w-3.5"
                                                    ></i>

                                                </button>

                                            </form>

                                        <?php endif; ?>


                                        <?php if (
                                            $canDeleteProduct
                                        ): ?>

                                            <button
                                                type="button"
                                                title="Delete"
                                                onclick='openDeleteProductModal(
                                                    <?= json_encode(
                                                        [
                                                            'id' =>
                                                                (int)
                                                                $product['id'],

                                                            'product_name' =>
                                                                $product['product_name'],

                                                            'product_code' =>
                                                                $product['product_code']
                                                        ],
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_AMP |
                                                        JSON_HEX_QUOT
                                                    ) ?>
                                                )'
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
                        colspan="<?= (
                            $canUpdateProduct ||
                            $canDeleteProduct
                        ) ? 11 : 10 ?>"
                        class="
                            px-5
                            py-12
                            text-center
                            text-[10px]
                            text-slate-400
                        "
                    >
                        No product matches your search.
                    </td>

                </tr>

                </tbody>

            </table>

        </div>

    </div>

</main>


<?php
require_once
    __DIR__ .
    '/includes/footer.php';
?>


</div>



<?php if ($canAddProduct): ?>

<!-- =========================================================
     ADD PRODUCT MODAL
========================================================= -->

<div
    id="addProductModal"
    class="
        fixed
        inset-0
        z-[100]
        hidden
        items-center
        justify-center
        bg-slate-950/60
        p-4
    "
>

    <div
        class="
            modal-box
            max-h-[92vh]
            w-full
            max-w-3xl
            scale-95
            overflow-y-auto
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


        <!-- MODAL HEADER -->

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
                    Add Product
                </h3>

                <p
                    class="
                        mt-1
                        text-[9px]
                        text-slate-400
                    "
                >
                    Add a new product.
                </p>

            </div>


            <button
                type="button"
                onclick="closeProductModal(
                    'addProductModal'
                )"
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


        <!-- FORM -->

        <form
            method="POST"
            class="p-5"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= productE(
                    $csrfToken
                ) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add"
            >


            <div
                class="
                    grid
                    grid-cols-1
                    gap-4
                    md:grid-cols-2
                "
            >


                <!-- PRODUCT NAME -->

                <div class="md:col-span-2">

                    <label class="product-label">
                        Product Name *
                    </label>

                    <input
                        type="text"
                        name="product_name"
                        required
                        autocomplete="off"
                        placeholder="e.g. Coca Cola 330ml"
                        class="product-input"
                    >

                </div>


                <!-- PRODUCT CODE -->

                <div>

                    <label class="product-label">
                        Product Code
                    </label>

                    <input
                        type="text"
                        name="product_code"
                        autocomplete="off"
                        placeholder="Auto if empty"
                        class="product-input"
                    >

                </div>


                <!-- BARCODE -->

                <div>

                    <label class="product-label">
                        Barcode
                    </label>

                    <input
                        type="text"
                        name="barcode"
                        autocomplete="off"
                        placeholder="Optional barcode"
                        class="product-input"
                    >

                </div>


                <!-- SEARCHABLE CATEGORY -->

                <div>

                    <label class="product-label">
                        Category
                    </label>


                    <div
                        class="searchable-select"
                        data-searchable-select
                    >

                        <input
                            type="hidden"
                            name="category_id"
                            id="addCategoryId"
                        >


                        <button
                            type="button"
                            class="searchable-select-button"
                            data-select-button
                        >

                            <span
                                data-select-label
                                class="truncate"
                            >
                                Select Category
                            </span>

                            <i
                                data-lucide="chevron-down"
                                class="
                                    h-3.5
                                    w-3.5
                                    shrink-0
                                "
                            ></i>

                        </button>


                        <div
                            class="
                                searchable-select-menu
                                hidden
                            "
                            data-select-menu
                        >

                            <div class="p-2">

                                <div class="relative">

                                    <i
                                        data-lucide="search"
                                        class="
                                            absolute
                                            left-3
                                            top-1/2
                                            h-3.5
                                            w-3.5
                                            -translate-y-1/2
                                            text-slate-400
                                        "
                                    ></i>


                                    <input
                                        type="text"
                                        placeholder="Search category..."
                                        autocomplete="off"
                                        class="
                                            searchable-select-search
                                            pl-9
                                        "
                                        data-select-search
                                    >

                                </div>

                            </div>


                            <div
                                class="
                                    searchable-select-options
                                "
                                data-select-options
                            >


                                <button
                                    type="button"
                                    class="searchable-option"
                                    data-value=""
                                    data-label="Select Category"
                                    data-search="select category"
                                >
                                    Select Category
                                </button>


                                <?php foreach (
                                    $categories as $category
                                ): ?>


                                    <?php if (
                                        $category['status']
                                        === 'Active'
                                    ): ?>


                                        <button
                                            type="button"
                                            class="searchable-option"
                                            data-value="<?= (int)
                                                $category['id']
                                            ?>"
                                            data-label="<?= productE(
                                                $category['category_name']
                                            ) ?>"
                                            data-search="<?= productE(
                                                strtolower(
                                                    $category['id']
                                                    . ' '
                                                    . $category['category_name']
                                                )
                                            ) ?>"
                                        >

                                            <span class="truncate">
                                                <?= productE(
                                                    $category['category_name']
                                                ) ?>
                                            </span>


                                            <span
                                                class="
                                                    ml-auto
                                                    text-[8px]
                                                    text-slate-400
                                                "
                                            >
                                                #<?= (int)
                                                    $category['id']
                                                ?>
                                            </span>

                                        </button>


                                    <?php endif; ?>

                                <?php endforeach; ?>


                                <div
                                    class="
                                        hidden
                                        px-3
                                        py-4
                                        text-center
                                        text-[9px]
                                        text-slate-400
                                    "
                                    data-select-empty
                                >
                                    No category found.
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- SEARCHABLE BASE UNIT -->

                <div>

                    <label class="product-label">
                        Base Unit *
                    </label>


                    <div
                        class="searchable-select"
                        data-searchable-select
                    >

                        <input
                            type="hidden"
                            name="base_unit_id"
                            id="addBaseUnitId"
                        >


                        <button
                            type="button"
                            class="searchable-select-button"
                            data-select-button
                        >

                            <span
                                data-select-label
                                class="truncate"
                            >
                                Select Base Unit
                            </span>

                            <i
                                data-lucide="chevron-down"
                                class="
                                    h-3.5
                                    w-3.5
                                    shrink-0
                                "
                            ></i>

                        </button>


                        <div
                            class="
                                searchable-select-menu
                                hidden
                            "
                            data-select-menu
                        >

                            <div class="p-2">

                                <div class="relative">

                                    <i
                                        data-lucide="search"
                                        class="
                                            absolute
                                            left-3
                                            top-1/2
                                            h-3.5
                                            w-3.5
                                            -translate-y-1/2
                                            text-slate-400
                                        "
                                    ></i>


                                    <input
                                        type="text"
                                        placeholder="Search unit..."
                                        autocomplete="off"
                                        class="
                                            searchable-select-search
                                            pl-9
                                        "
                                        data-select-search
                                    >

                                </div>

                            </div>


                            <div
                                class="
                                    searchable-select-options
                                "
                                data-select-options
                            >


                                <?php foreach (
                                    $units as $unit
                                ): ?>


                                    <?php if (
                                        $unit['status']
                                        === 'Active'
                                    ): ?>


                                        <button
                                            type="button"
                                            class="searchable-option"
                                            data-value="<?= (int)
                                                $unit['id']
                                            ?>"
                                            data-label="<?= productE(
                                                $unit['unit_name']
                                                . ' ('
                                                . $unit['short_name']
                                                . ')'
                                            ) ?>"
                                            data-search="<?= productE(
                                                strtolower(
                                                    $unit['id']
                                                    . ' '
                                                    . $unit['unit_name']
                                                    . ' '
                                                    . $unit['short_name']
                                                )
                                            ) ?>"
                                        >

                                            <div class="min-w-0">

                                                <p
                                                    class="
                                                        truncate
                                                        font-bold
                                                    "
                                                >
                                                    <?= productE(
                                                        $unit['unit_name']
                                                    ) ?>
                                                </p>

                                                <p
                                                    class="
                                                        mt-0.5
                                                        text-[8px]
                                                        text-slate-400
                                                    "
                                                >
                                                    <?= productE(
                                                        $unit['short_name']
                                                    ) ?>
                                                </p>

                                            </div>


                                            <span
                                                class="
                                                    ml-auto
                                                    text-[8px]
                                                    text-slate-400
                                                "
                                            >
                                                #<?= (int)
                                                    $unit['id']
                                                ?>
                                            </span>

                                        </button>


                                    <?php endif; ?>

                                <?php endforeach; ?>


                                <div
                                    class="
                                        hidden
                                        px-3
                                        py-4
                                        text-center
                                        text-[9px]
                                        text-slate-400
                                    "
                                    data-select-empty
                                >
                                    No unit found.
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- COST PRICE -->

                <div>

                    <label class="product-label">
                        Cost Price
                    </label>

                    <input
                        type="number"
                        name="cost_price"
                        step="0.01"
                        min="0"
                        value="0.00"
                        class="product-input"
                    >

                </div>


                <!-- SELLING PRICE -->

                <div>

                    <label class="product-label">
                        Selling Price
                    </label>

                    <input
                        type="number"
                        name="selling_price"
                        step="0.01"
                        min="0"
                        value="0.00"
                        class="product-input"
                    >

                </div>


                <!-- MINIMUM STOCK -->

                <div>

                    <label class="product-label">
                        Minimum Stock
                    </label>

                    <input
                        type="number"
                        name="minimum_stock"
                        step="0.001"
                        min="0"
                        value="0"
                        class="product-input"
                    >

                </div>


                <!-- STATUS -->

                <div>

                    <label class="product-label">
                        Status
                    </label>

                    <select
                        name="status"
                        class="product-input"
                    >

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                </div>


                <!-- EXPIRY -->

                <div class="md:col-span-2">

                    <label
                        class="
                            flex
                            cursor-pointer
                            items-center
                            gap-3
                            rounded-xl
                            border
                            border-slate-200
                            bg-slate-50
                            p-3
                            dark:border-slate-700
                            dark:bg-slate-950
                        "
                    >

                        <input
                            type="checkbox"
                            name="track_expiry"
                            value="1"
                            class="
                                h-4
                                w-4
                                rounded
                                border-slate-300
                                text-blue-600
                            "
                        >


                        <span>

                            <span
                                class="
                                    block
                                    text-[10px]
                                    font-bold
                                    text-slate-700
                                    dark:text-slate-200
                                "
                            >
                                Track Expiry
                            </span>

                            <span
                                class="
                                    mt-0.5
                                    block
                                    text-[9px]
                                    text-slate-400
                                "
                            >
                                Enable if this product has expiry dates.
                            </span>

                        </span>

                    </label>

                </div>


                <!-- DESCRIPTION -->

                <div class="md:col-span-2">

                    <label class="product-label">
                        Description
                    </label>

                    <textarea
                        name="description"
                        rows="3"
                        placeholder="Optional description"
                        class="
                            product-input
                            h-auto
                            py-3
                        "
                    ></textarea>

                </div>

            </div>


            <!-- FOOTER -->

            <div
                class="
                    mt-5
                    flex
                    justify-end
                    gap-2
                    border-t
                    border-slate-100
                    pt-4
                    dark:border-slate-800
                "
            >

                <button
                    type="button"
                    onclick="closeProductModal(
                        'addProductModal'
                    )"
                    class="product-btn-cancel"
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    onclick="return validateAddProduct()"
                    class="product-btn-primary"
                >

                    <i
                        data-lucide="plus"
                        class="h-3.5 w-3.5"
                    ></i>

                    Add Product

                </button>

            </div>

        </form>

    </div>

</div>

<?php endif; ?>



<?php if ($canUpdateProduct): ?>

<!-- =========================================================
     EDIT PRODUCT MODAL
========================================================= -->

<div
    id="editProductModal"
    class="
        fixed
        inset-0
        z-[100]
        hidden
        items-center
        justify-center
        bg-slate-950/60
        p-4
    "
>

    <div
        class="
            modal-box
            max-h-[92vh]
            w-full
            max-w-3xl
            scale-95
            overflow-y-auto
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
                    Edit Product
                </h3>

                <p
                    class="
                        mt-1
                        text-[9px]
                        text-slate-400
                    "
                >
                    Update product information.
                </p>

            </div>


            <button
                type="button"
                onclick="closeProductModal(
                    'editProductModal'
                )"
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


        <form
            method="POST"
            class="p-5"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= productE(
                    $csrfToken
                ) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="update"
            >

            <input
                type="hidden"
                name="product_id"
                id="editProductId"
            >


            <div
                class="
                    grid
                    grid-cols-1
                    gap-4
                    md:grid-cols-2
                "
            >


                <!-- NAME -->

                <div class="md:col-span-2">

                    <label class="product-label">
                        Product Name *
                    </label>

                    <input
                        type="text"
                        name="product_name"
                        id="editProductName"
                        required
                        class="product-input"
                    >

                </div>


                <!-- CODE -->

                <div>

                    <label class="product-label">
                        Product Code
                    </label>

                    <input
                        type="text"
                        name="product_code"
                        id="editProductCode"
                        class="product-input"
                    >

                </div>


                <!-- BARCODE -->

                <div>

                    <label class="product-label">
                        Barcode
                    </label>

                    <input
                        type="text"
                        name="barcode"
                        id="editProductBarcode"
                        class="product-input"
                    >

                </div>


                <!-- EDIT CATEGORY SEARCH -->

                <div>

                    <label class="product-label">
                        Category
                    </label>


                    <div
                        class="searchable-select"
                        data-searchable-select
                    >

                        <input
                            type="hidden"
                            name="category_id"
                            id="editProductCategory"
                        >


                        <button
                            type="button"
                            class="searchable-select-button"
                            data-select-button
                        >

                            <span
                                data-select-label
                                class="truncate"
                            >
                                Select Category
                            </span>

                            <i
                                data-lucide="chevron-down"
                                class="h-3.5 w-3.5"
                            ></i>

                        </button>


                        <div
                            class="
                                searchable-select-menu
                                hidden
                            "
                            data-select-menu
                        >

                            <div class="p-2">

                                <div class="relative">

                                    <i
                                        data-lucide="search"
                                        class="
                                            absolute
                                            left-3
                                            top-1/2
                                            h-3.5
                                            w-3.5
                                            -translate-y-1/2
                                            text-slate-400
                                        "
                                    ></i>


                                    <input
                                        type="text"
                                        placeholder="Search category..."
                                        autocomplete="off"
                                        class="
                                            searchable-select-search
                                            pl-9
                                        "
                                        data-select-search
                                    >

                                </div>

                            </div>


                            <div
                                class="
                                    searchable-select-options
                                "
                                data-select-options
                            >

                                <button
                                    type="button"
                                    class="searchable-option"
                                    data-value=""
                                    data-label="Select Category"
                                    data-search="select category"
                                >
                                    Select Category
                                </button>


                                <?php foreach (
                                    $categories as $category
                                ): ?>

                                    <button
                                        type="button"
                                        class="searchable-option"
                                        data-value="<?= (int)
                                            $category['id']
                                        ?>"
                                        data-label="<?= productE(
                                            $category['category_name']
                                        ) ?>"
                                        data-search="<?= productE(
                                            strtolower(
                                                $category['id']
                                                . ' '
                                                . $category['category_name']
                                            )
                                        ) ?>"
                                    >

                                        <span class="truncate">
                                            <?= productE(
                                                $category['category_name']
                                            ) ?>
                                        </span>


                                        <span
                                            class="
                                                ml-auto
                                                text-[8px]
                                                text-slate-400
                                            "
                                        >
                                            #<?= (int)
                                                $category['id']
                                            ?>
                                        </span>

                                    </button>

                                <?php endforeach; ?>


                                <div
                                    class="
                                        hidden
                                        px-3
                                        py-4
                                        text-center
                                        text-[9px]
                                        text-slate-400
                                    "
                                    data-select-empty
                                >
                                    No category found.
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- EDIT BASE UNIT SEARCH -->

                <div>

                    <label class="product-label">
                        Base Unit *
                    </label>


                    <div
                        class="searchable-select"
                        data-searchable-select
                    >

                        <input
                            type="hidden"
                            name="base_unit_id"
                            id="editProductBaseUnit"
                        >


                        <button
                            type="button"
                            class="searchable-select-button"
                            data-select-button
                        >

                            <span
                                data-select-label
                                class="truncate"
                            >
                                Select Base Unit
                            </span>

                            <i
                                data-lucide="chevron-down"
                                class="h-3.5 w-3.5"
                            ></i>

                        </button>


                        <div
                            class="
                                searchable-select-menu
                                hidden
                            "
                            data-select-menu
                        >

                            <div class="p-2">

                                <div class="relative">

                                    <i
                                        data-lucide="search"
                                        class="
                                            absolute
                                            left-3
                                            top-1/2
                                            h-3.5
                                            w-3.5
                                            -translate-y-1/2
                                            text-slate-400
                                        "
                                    ></i>


                                    <input
                                        type="text"
                                        placeholder="Search unit..."
                                        autocomplete="off"
                                        class="
                                            searchable-select-search
                                            pl-9
                                        "
                                        data-select-search
                                    >

                                </div>

                            </div>


                            <div
                                class="
                                    searchable-select-options
                                "
                                data-select-options
                            >

                                <?php foreach (
                                    $units as $unit
                                ): ?>

                                    <button
                                        type="button"
                                        class="searchable-option"
                                        data-value="<?= (int)
                                            $unit['id']
                                        ?>"
                                        data-label="<?= productE(
                                            $unit['unit_name']
                                            . ' ('
                                            . $unit['short_name']
                                            . ')'
                                        ) ?>"
                                        data-search="<?= productE(
                                            strtolower(
                                                $unit['id']
                                                . ' '
                                                . $unit['unit_name']
                                                . ' '
                                                . $unit['short_name']
                                            )
                                        ) ?>"
                                    >

                                        <div class="min-w-0">

                                            <p
                                                class="
                                                    truncate
                                                    font-bold
                                                "
                                            >
                                                <?= productE(
                                                    $unit['unit_name']
                                                ) ?>
                                            </p>

                                            <p
                                                class="
                                                    text-[8px]
                                                    text-slate-400
                                                "
                                            >
                                                <?= productE(
                                                    $unit['short_name']
                                                ) ?>
                                            </p>

                                        </div>


                                        <span
                                            class="
                                                ml-auto
                                                text-[8px]
                                                text-slate-400
                                            "
                                        >
                                            #<?= (int)
                                                $unit['id']
                                            ?>
                                        </span>

                                    </button>

                                <?php endforeach; ?>


                                <div
                                    class="
                                        hidden
                                        px-3
                                        py-4
                                        text-center
                                        text-[9px]
                                        text-slate-400
                                    "
                                    data-select-empty
                                >
                                    No unit found.
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- COST -->

                <div>

                    <label class="product-label">
                        Cost Price
                    </label>

                    <input
                        type="number"
                        name="cost_price"
                        id="editProductCost"
                        step="0.01"
                        min="0"
                        class="product-input"
                    >

                </div>


                <!-- SELLING -->

                <div>

                    <label class="product-label">
                        Selling Price
                    </label>

                    <input
                        type="number"
                        name="selling_price"
                        id="editProductSelling"
                        step="0.01"
                        min="0"
                        class="product-input"
                    >

                </div>


                <!-- MIN STOCK -->

                <div>

                    <label class="product-label">
                        Minimum Stock
                    </label>

                    <input
                        type="number"
                        name="minimum_stock"
                        id="editProductMinimum"
                        step="0.001"
                        min="0"
                        class="product-input"
                    >

                </div>


                <!-- STATUS -->

                <div>

                    <label class="product-label">
                        Status
                    </label>

                    <select
                        name="status"
                        id="editProductStatus"
                        class="product-input"
                    >

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                </div>


                <!-- EXPIRY -->

                <div class="md:col-span-2">

                    <label
                        class="
                            flex
                            cursor-pointer
                            items-center
                            gap-3
                            rounded-xl
                            border
                            border-slate-200
                            bg-slate-50
                            p-3
                            dark:border-slate-700
                            dark:bg-slate-950
                        "
                    >

                        <input
                            type="checkbox"
                            name="track_expiry"
                            value="1"
                            id="editProductExpiry"
                            class="
                                h-4
                                w-4
                                rounded
                                border-slate-300
                                text-blue-600
                            "
                        >


                        <span>

                            <span
                                class="
                                    block
                                    text-[10px]
                                    font-bold
                                "
                            >
                                Track Expiry
                            </span>

                            <span
                                class="
                                    mt-0.5
                                    block
                                    text-[9px]
                                    text-slate-400
                                "
                            >
                                Enable expiry tracking.
                            </span>

                        </span>

                    </label>

                </div>


                <!-- DESCRIPTION -->

                <div class="md:col-span-2">

                    <label class="product-label">
                        Description
                    </label>

                    <textarea
                        name="description"
                        id="editProductDescription"
                        rows="3"
                        class="
                            product-input
                            h-auto
                            py-3
                        "
                    ></textarea>

                </div>

            </div>


            <div
                class="
                    mt-5
                    flex
                    justify-end
                    gap-2
                    border-t
                    border-slate-100
                    pt-4
                    dark:border-slate-800
                "
            >

                <button
                    type="button"
                    onclick="closeProductModal(
                        'editProductModal'
                    )"
                    class="product-btn-cancel"
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    onclick="return validateEditProduct()"
                    class="product-btn-primary"
                >

                    <i
                        data-lucide="save"
                        class="h-3.5 w-3.5"
                    ></i>

                    Save Changes

                </button>

            </div>

        </form>

    </div>

</div>

<?php endif; ?>




<?php if ($canUpdateProduct): ?>

<!-- =========================================================
     MANAGE PRODUCT UNITS MODAL
========================================================= -->

<div
    id="manageUnitsModal"
    class="fixed inset-0 z-[105] hidden items-center justify-center bg-slate-950/60 p-4"
>
    <div
        class="modal-box max-h-[92vh] w-full max-w-5xl scale-95 overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl transition dark:border-slate-800 dark:bg-slate-900"
    >
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <div>
                <div class="flex items-center gap-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-50 text-violet-600 dark:bg-violet-950/40">
                        <i data-lucide="package-plus" class="h-4 w-4"></i>
                    </div>
                    <div>
                        <h3 class="text-[14px] font-extrabold text-slate-900 dark:text-white">Manage Product Units</h3>
                        <p id="manageUnitsProductName" class="mt-0.5 text-[10px] text-slate-500 dark:text-slate-400"></p>
                    </div>
                </div>
            </div>

            <button
                type="button"
                onclick="closeProductModal('manageUnitsModal')"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-white"
            >
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>

        <div class="grid gap-5 p-5 lg:grid-cols-[1.05fr_1.95fr]">
            <!-- FORM -->
            <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h4 id="productUnitFormTitle" class="text-[12px] font-extrabold text-slate-900 dark:text-white">Add Unit</h4>
                        <p class="mt-1 text-[9px] text-slate-500 dark:text-slate-400">Tusaale: 1 Kees = 24 Dhalo</p>
                    </div>
                    <button
                        id="cancelProductUnitEditBtn"
                        type="button"
                        onclick="resetProductUnitForm()"
                        class="hidden rounded-lg px-2.5 py-1.5 text-[9px] font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                    >
                        Cancel Edit
                    </button>
                </div>

                <form method="POST" id="productUnitForm">
                    <input type="hidden" name="csrf_token" value="<?= productE($csrfToken) ?>">
                    <input type="hidden" name="action" id="productUnitAction" value="add_product_unit">
                    <input type="hidden" name="product_id" id="productUnitProductId">
                    <input type="hidden" name="product_unit_id" id="productUnitId">

                    <div id="productUnitUnitField" class="mb-3">
                        <label class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-500">Unit <span class="text-red-500">*</span></label>

                        <div class="relative" data-searchable-select id="productUnitSelectWrapper">
                            <input type="hidden" name="unit_id" id="productUnitUnitId">

                            <button
                                type="button"
                                data-select-button
                                class="product-input flex w-full items-center justify-between text-left"
                            >
                                <span data-select-label>Select Unit</span>
                                <i data-lucide="chevron-down" class="h-3.5 w-3.5"></i>
                            </button>

                            <div
                                data-select-menu
                                class="absolute left-0 right-0 top-full z-[130] mt-1 hidden max-h-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
                            >
                                <div class="border-b border-slate-100 p-2 dark:border-slate-800">
                                    <div class="relative">
                                        <i data-lucide="search" class="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"></i>
                                        <input
                                            type="text"
                                            data-select-search
                                            placeholder="Search ID, unit or short name..."
                                            class="h-9 w-full rounded-lg border border-slate-200 bg-slate-50 pl-8 pr-3 text-[10px] outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-800"
                                        >
                                    </div>
                                </div>

                                <div class="max-h-48 overflow-y-auto p-1.5">
                                    <?php foreach ($units as $unit): ?>
                                        <button
                                            type="button"
                                            class="searchable-option flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-[10px]"
                                            data-value="<?= (int) $unit['id'] ?>"
                                            data-label="<?= productE($unit['unit_name'] . ' (' . $unit['short_name'] . ')') ?>"
                                            data-search="<?= productE($unit['id'] . ' ' . $unit['unit_name'] . ' ' . $unit['short_name']) ?>"
                                        >
                                            <span class="font-bold"><?= productE($unit['unit_name']) ?></span>
                                            <span class="text-[9px] text-slate-400">#<?= (int) $unit['id'] ?> · <?= productE($unit['short_name']) ?></span>
                                        </button>
                                    <?php endforeach; ?>

                                    <div data-select-empty class="hidden px-3 py-4 text-center text-[9px] text-slate-400">No unit found.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="productUnitLockedUnit" class="mb-3 hidden">
                        <label class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-500">Unit</label>
                        <div id="productUnitLockedUnitName" class="flex h-10 items-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-[10px] font-bold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"></div>
                    </div>

                    <div class="mb-3">
                        <label class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-500">Conversion Qty <span class="text-red-500">*</span></label>
                        <input
                            type="number"
                            name="conversion_qty"
                            id="productUnitConversion"
                            step="0.001"
                            min="0.001"
                            value="1"
                            class="product-input w-full"
                            oninput="suggestProductUnitPrices()"
                            required
                        >
                        <p id="productUnitConversionHelp" class="mt-1 text-[9px] text-slate-400">Tirada Base Unit-ka ku jirta unit-kan.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-500">Purchase Price</label>
                            <input
                                type="number"
                                name="purchase_price"
                                id="productUnitPurchasePrice"
                                step="0.01"
                                min="0"
                                value="0"
                                class="product-input w-full"
                            >
                        </div>

                        <div>
                            <label class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-500">Selling Price</label>
                            <input
                                type="number"
                                name="selling_price"
                                id="productUnitSellingPrice"
                                step="0.01"
                                min="0"
                                value="0"
                                class="product-input w-full"
                            >
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            <input type="checkbox" name="can_purchase" id="productUnitCanPurchase" value="1" checked class="h-4 w-4 rounded border-slate-300">
                            <span class="text-[10px] font-bold text-slate-700 dark:text-slate-200">Can Purchase</span>
                        </label>

                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            <input type="checkbox" name="can_sell" id="productUnitCanSell" value="1" checked class="h-4 w-4 rounded border-slate-300">
                            <span class="text-[10px] font-bold text-slate-700 dark:text-slate-200">Can Sell</span>
                        </label>
                    </div>

                    <div class="mt-3">
                        <label class="mb-1.5 block text-[9px] font-extrabold uppercase tracking-wide text-slate-500">Status</label>
                        <select name="status" id="productUnitStatus" class="product-input w-full">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        id="productUnitSubmitBtn"
                        class="product-btn-primary mt-4 w-full"
                    >
                        <i data-lucide="plus" class="h-3.5 w-3.5"></i>
                        <span id="productUnitSubmitText">Add Unit</span>
                    </button>
                </form>
            </div>

            <!-- LIST -->
            <div class="min-w-0">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h4 class="text-[12px] font-extrabold text-slate-900 dark:text-white">Product Units</h4>
                        <p class="mt-1 text-[9px] text-slate-500 dark:text-slate-400">Base Unit-ka conversion-kiisu mar walba waa 1.</p>
                    </div>
                    <span id="productUnitsCount" class="rounded-full bg-slate-100 px-2.5 py-1 text-[9px] font-extrabold text-slate-600 dark:bg-slate-800 dark:text-slate-300">0 Units</span>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
                    <table class="w-full min-w-[700px]">
                        <thead class="bg-slate-50 dark:bg-slate-800/70">
                            <tr class="text-left text-[9px] uppercase tracking-wide text-slate-500">
                                <th class="px-4 py-3">Unit</th>
                                <th class="px-4 py-3 text-right">Conversion</th>
                                <th class="px-4 py-3 text-right">Purchase</th>
                                <th class="px-4 py-3 text-right">Selling</th>
                                <th class="px-4 py-3 text-center">Use</th>
                                <th class="px-4 py-3 text-center">Status</th>
                                <th class="px-4 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productUnitsTableBody" class="divide-y divide-slate-100 dark:divide-slate-800"></tbody>
                    </table>
                </div>

                <div id="productUnitsEmpty" class="hidden rounded-2xl border border-dashed border-slate-200 py-10 text-center dark:border-slate-800">
                    <i data-lucide="package-open" class="mx-auto h-7 w-7 text-slate-300"></i>
                    <p class="mt-2 text-[10px] font-bold text-slate-500">No units found.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     DELETE PRODUCT UNIT MODAL
========================================================= -->

<div
    id="deleteProductUnitModal"
    class="fixed inset-0 z-[140] hidden items-center justify-center bg-slate-950/70 p-4"
>
    <div class="modal-box w-full max-w-md scale-95 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl transition dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-red-50 text-red-600 dark:bg-red-950/40">
            <i data-lucide="trash-2" class="h-5 w-5"></i>
        </div>

        <div class="mt-4 text-center">
            <h3 class="text-[14px] font-extrabold text-slate-900 dark:text-white">Delete Product Unit?</h3>
            <p class="mt-2 text-[10px] text-slate-500 dark:text-slate-400">
                Are you sure you want to delete <span id="deleteProductUnitName" class="font-extrabold text-slate-800 dark:text-white"></span>?
            </p>
            <p class="mt-2 text-[9px] font-semibold text-red-500">Base Unit cannot be deleted.</p>
        </div>

        <form method="POST" class="mt-5 flex justify-center gap-2">
            <input type="hidden" name="csrf_token" value="<?= productE($csrfToken) ?>">
            <input type="hidden" name="action" value="delete_product_unit">
            <input type="hidden" name="product_unit_id" id="deleteProductUnitId">

            <button type="button" onclick="closeProductModal('deleteProductUnitModal')" class="product-btn-cancel">Cancel</button>
            <button type="submit" class="inline-flex h-9 items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-[10px] font-bold text-white transition hover:bg-red-700">
                <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                Delete Unit
            </button>
        </form>
    </div>
</div>

<?php endif; ?>


<?php if ($canDeleteProduct): ?>

<!-- =========================================================
     DELETE PRODUCT MODAL
========================================================= -->

<div
    id="deleteProductModal"
    class="
        fixed
        inset-0
        z-[110]
        hidden
        items-center
        justify-center
        bg-slate-950/60
        p-4
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
            p-5
            shadow-2xl
            transition
            dark:border-slate-800
            dark:bg-slate-900
        "
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
                bg-red-50
                text-red-600
                dark:bg-red-950/40
            "
        >

            <i
                data-lucide="trash-2"
                class="h-5 w-5"
            ></i>

        </div>


        <div class="mt-4 text-center">

            <h3
                class="
                    text-[14px]
                    font-extrabold
                    text-slate-900
                    dark:text-white
                "
            >
                Delete Product?
            </h3>


            <p
                class="
                    mt-2
                    text-[10px]
                    text-slate-500
                    dark:text-slate-400
                "
            >

                Are you sure you want to delete

                <span
                    id="deleteProductName"
                    class="
                        font-extrabold
                        text-slate-800
                        dark:text-white
                    "
                ></span>?

            </p>


            <p
                class="
                    mt-2
                    text-[9px]
                    font-semibold
                    text-red-500
                "
            >
                This action cannot be undone.
            </p>

        </div>


        <form
            method="POST"
            class="
                mt-5
                flex
                justify-center
                gap-2
            "
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= productE(
                    $csrfToken
                ) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="delete"
            >

            <input
                type="hidden"
                name="product_id"
                id="deleteProductId"
            >


            <button
                type="button"
                onclick="closeProductModal(
                    'deleteProductModal'
                )"
                class="product-btn-cancel"
            >
                Cancel
            </button>


            <button
                type="submit"
                class="
                    inline-flex
                    h-9
                    items-center
                    justify-center
                    gap-2
                    rounded-xl
                    bg-red-600
                    px-4
                    text-[10px]
                    font-bold
                    text-white
                    transition
                    hover:bg-red-700
                "
            >

                <i
                    data-lucide="trash-2"
                    class="h-3.5 w-3.5"
                ></i>

                Delete Product

            </button>

        </form>

    </div>

</div>

<?php endif; ?>



<style>

/* =========================================================
   PRODUCT FORM
========================================================= */

.product-label {
    display: block;
    margin-bottom: 7px;
    font-size: 10px;
    font-weight: 700;
    color: rgb(71 85 105);
}

.dark .product-label {
    color: rgb(203 213 225);
}


.product-input {
    width: 100%;
    min-height: 40px;
    border: 1px solid rgb(226 232 240);
    border-radius: 12px;
    background: rgb(248 250 252);
    padding-left: 12px;
    padding-right: 12px;
    font-size: 10px;
    outline: none;
    transition: .15s ease;
}


.product-input:focus {
    border-color: rgb(59 130 246);
    background: white;
    box-shadow:
        0 0 0 3px
        rgba(59, 130, 246, .08);
}


.dark .product-input {
    border-color: rgb(51 65 85);
    background: rgb(2 6 23);
    color: rgb(226 232 240);
}


.dark .product-input:focus {
    border-color: rgb(59 130 246);
}


/* =========================================================
   BUTTONS
========================================================= */

.product-btn-primary {
    display: inline-flex;
    height: 36px;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 12px;
    background: rgb(37 99 235);
    padding-left: 16px;
    padding-right: 16px;
    font-size: 10px;
    font-weight: 700;
    color: white;
    transition: .15s ease;
}


.product-btn-primary:hover {
    background: rgb(29 78 216);
}


.product-btn-cancel {
    display: inline-flex;
    height: 36px;
    align-items: center;
    justify-content: center;
    border: 1px solid rgb(226 232 240);
    border-radius: 12px;
    background: white;
    padding-left: 16px;
    padding-right: 16px;
    font-size: 10px;
    font-weight: 700;
    color: rgb(71 85 105);
    transition: .15s ease;
}


.product-btn-cancel:hover {
    background: rgb(248 250 252);
}


.dark .product-btn-cancel {
    border-color: rgb(51 65 85);
    background: rgb(15 23 42);
    color: rgb(203 213 225);
}


/* =========================================================
   STATS
========================================================= */

.product-stat-card {
    border: 1px solid rgb(226 232 240);
    border-radius: 16px;
    background: white;
    padding: 16px;
    box-shadow:
        0 1px 2px
        rgba(15, 23, 42, .04);
}


.dark .product-stat-card {
    border-color: rgb(30 41 59);
    background: rgb(15 23 42);
}


.product-stat-label {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgb(148 163 184);
}


.product-stat-value {
    margin-top: 8px;
    font-size: 20px;
    font-weight: 800;
    color: rgb(15 23 42);
}


.dark .product-stat-value {
    color: white;
}


/* =========================================================
   SEARCHABLE SELECT
========================================================= */

.searchable-select {
    position: relative;
    width: 100%;
}


.searchable-select-button {
    display: flex;
    width: 100%;
    min-height: 40px;
    align-items: center;
    justify-content: space-between;
    gap: 10px;

    border: 1px solid rgb(226 232 240);
    border-radius: 12px;

    background: rgb(248 250 252);

    padding-left: 12px;
    padding-right: 12px;

    font-size: 10px;
    color: rgb(51 65 85);

    text-align: left;

    outline: none;
    transition: .15s ease;
}


.searchable-select-button:hover,
.searchable-select-button.active {
    border-color: rgb(59 130 246);
    background: white;
}


.dark .searchable-select-button {
    border-color: rgb(51 65 85);
    background: rgb(2 6 23);
    color: rgb(226 232 240);
}


.dark .searchable-select-button:hover,
.dark .searchable-select-button.active {
    border-color: rgb(59 130 246);
    background: rgb(15 23 42);
}


.searchable-select-menu {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 5px);

    z-index: 300;

    overflow: hidden;

    border: 1px solid rgb(226 232 240);
    border-radius: 12px;

    background: white;

    box-shadow:
        0 15px 35px
        rgba(15, 23, 42, .15);
}


.dark .searchable-select-menu {
    border-color: rgb(51 65 85);
    background: rgb(15 23 42);
}


.searchable-select-search {
    width: 100%;
    height: 36px;

    border: 1px solid rgb(226 232 240);
    border-radius: 9px;

    background: rgb(248 250 252);

    padding-right: 11px;

    font-size: 10px;

    outline: none;

    transition: .15s ease;
}


.searchable-select-search:focus {
    border-color: rgb(59 130 246);
}


.dark .searchable-select-search {
    border-color: rgb(51 65 85);
    background: rgb(2 6 23);
    color: rgb(226 232 240);
}


.searchable-select-options {
    max-height: 210px;
    overflow-y: auto;
    padding: 4px;
}


.searchable-option {
    display: flex;

    width: 100%;
    min-height: 36px;

    align-items: center;
    gap: 8px;

    border-radius: 9px;

    padding:
        7px
        10px;

    font-size: 10px;

    text-align: left;

    color: rgb(51 65 85);

    transition: .12s ease;
}


.searchable-option:hover {
    background: rgb(239 246 255);
    color: rgb(37 99 235);
}


.searchable-option.selected {
    background: rgb(239 246 255);
    color: rgb(37 99 235);
    font-weight: 700;
}


.dark .searchable-option {
    color: rgb(203 213 225);
}


.dark .searchable-option:hover,
.dark .searchable-option.selected {
    background:
        rgba(
            30,
            64,
            175,
            .25
        );

    color: rgb(147 197 253);
}

</style>



<script>

/* =========================================================
   SEARCHABLE SELECT INITIALIZER
========================================================= */

function initializeSearchableSelects() {

    const searchableSelects =
        document.querySelectorAll(
            '[data-searchable-select]'
        );


    searchableSelects.forEach(
        function (wrapper) {

            const hiddenInput =
                wrapper.querySelector(
                    'input[type="hidden"]'
                );

            const button =
                wrapper.querySelector(
                    '[data-select-button]'
                );

            const label =
                wrapper.querySelector(
                    '[data-select-label]'
                );

            const menu =
                wrapper.querySelector(
                    '[data-select-menu]'
                );

            const search =
                wrapper.querySelector(
                    '[data-select-search]'
                );

            const options =
                wrapper.querySelectorAll(
                    '.searchable-option'
                );


            if (
                !hiddenInput ||
                !button ||
                !menu
            ) {
                return;
            }


            /*
            |--------------------------------------------------------------------------
            | OPEN / CLOSE
            |--------------------------------------------------------------------------
            */

            button.addEventListener(
                'click',
                function (event) {

                    event.stopPropagation();


                    document
                        .querySelectorAll(
                            '[data-select-menu]'
                        )
                        .forEach(
                            function (
                                otherMenu
                            ) {

                                if (
                                    otherMenu
                                    !== menu
                                ) {
                                    otherMenu
                                        .classList
                                        .add(
                                            'hidden'
                                        );
                                }

                            }
                        );


                    document
                        .querySelectorAll(
                            '[data-select-button]'
                        )
                        .forEach(
                            function (
                                otherButton
                            ) {

                                if (
                                    otherButton
                                    !== button
                                ) {
                                    otherButton
                                        .classList
                                        .remove(
                                            'active'
                                        );
                                }

                            }
                        );


                    menu.classList.toggle(
                        'hidden'
                    );


                    button.classList.toggle(
                        'active',
                        !menu
                            .classList
                            .contains(
                                'hidden'
                            )
                    );


                    if (
                        !menu
                            .classList
                            .contains(
                                'hidden'
                            )
                    ) {

                        if (search) {

                            search.value = '';

                            filterSearchableOptions(
                                wrapper,
                                ''
                            );


                            setTimeout(
                                function () {
                                    search.focus();
                                },
                                30
                            );
                        }
                    }

                }
            );


            /*
            |--------------------------------------------------------------------------
            | AUTO SEARCH
            |--------------------------------------------------------------------------
            */

            if (search) {

                search.addEventListener(
                    'input',
                    function () {

                        filterSearchableOptions(
                            wrapper,
                            this.value
                        );

                    }
                );


                search.addEventListener(
                    'click',
                    function (event) {

                        event.stopPropagation();

                    }
                );

            }


            /*
            |--------------------------------------------------------------------------
            | SELECT OPTION
            |--------------------------------------------------------------------------
            */

            options.forEach(
                function (option) {

                    option.addEventListener(
                        'click',
                        function (event) {

                            event.stopPropagation();


                            const value =
                                option.dataset.value
                                ?? '';


                            const optionLabel =
                                option.dataset.label
                                ??
                                option.textContent
                                    .trim();


                            hiddenInput.value =
                                value;


                            if (label) {

                                label.textContent =
                                    optionLabel;
                            }


                            options.forEach(
                                function (item) {

                                    item.classList.remove(
                                        'selected'
                                    );

                                }
                            );


                            option.classList.add(
                                'selected'
                            );


                            menu.classList.add(
                                'hidden'
                            );


                            button.classList.remove(
                                'active'
                            );

                        }
                    );

                }
            );

        }
    );


    /*
    |--------------------------------------------------------------------------
    | CLICK OUTSIDE
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'click',
        function () {

            document
                .querySelectorAll(
                    '[data-select-menu]'
                )
                .forEach(
                    function (menu) {

                        menu.classList.add(
                            'hidden'
                        );

                    }
                );


            document
                .querySelectorAll(
                    '[data-select-button]'
                )
                .forEach(
                    function (button) {

                        button.classList.remove(
                            'active'
                        );

                    }
                );

        }
    );

}


/* =========================================================
   FILTER SEARCHABLE OPTIONS
========================================================= */

function filterSearchableOptions(
    wrapper,
    query
) {

    query =
        String(query || '')
            .trim()
            .toLowerCase();


    const options =
        wrapper.querySelectorAll(
            '.searchable-option'
        );


    const empty =
        wrapper.querySelector(
            '[data-select-empty]'
        );


    let visible = 0;


    options.forEach(
        function (option) {

            const searchText =
                (
                    option.dataset.search
                    ||
                    option.textContent
                    ||
                    ''
                )
                    .toLowerCase();


            const match =
                searchText.includes(query);


            option.style.display =
                match
                    ? ''
                    : 'none';


            if (match) {
                visible++;
            }

        }
    );


    if (empty) {

        empty.classList.toggle(
            'hidden',
            visible !== 0
        );

    }

}


/* =========================================================
   SET SEARCHABLE SELECT VALUE
========================================================= */

function setSearchableSelectValue(
    inputId,
    value
) {

    const input =
        document.getElementById(
            inputId
        );


    if (!input) {
        return;
    }


    const wrapper =
        input.closest(
            '[data-searchable-select]'
        );


    if (!wrapper) {
        return;
    }


    const label =
        wrapper.querySelector(
            '[data-select-label]'
        );


    const options =
        wrapper.querySelectorAll(
            '.searchable-option'
        );


    input.value =
        value ?? '';


    let found = false;


    options.forEach(
        function (option) {

            const isSelected =
                String(
                    option.dataset.value
                    ?? ''
                )
                ===
                String(
                    value
                    ?? ''
                );


            option.classList.toggle(
                'selected',
                isSelected
            );


            if (isSelected) {

                found = true;


                if (label) {

                    label.textContent =
                        option.dataset.label
                        ||
                        option.textContent
                            .trim();

                }
            }

        }
    );


    if (
        !found &&
        label
    ) {

        label.textContent =
            'Select';

    }

}


/* =========================================================
   OPEN ADD MODAL
========================================================= */

function openAddProductModal() {

    const modal =
        document.getElementById(
            'addProductModal'
        );


    if (!modal) {
        return;
    }


    modal.classList.remove(
        'hidden'
    );

    modal.classList.add(
        'flex'
    );


    document.body.style.overflow =
        'hidden';


    setTimeout(
        function () {

            const box =
                modal.querySelector(
                    '.modal-box'
                );


            if (box) {

                box.classList.remove(
                    'scale-95'
                );

                box.classList.add(
                    'scale-100'
                );
            }


            const nameInput =
                modal.querySelector(
                    'input[name="product_name"]'
                );


            if (nameInput) {
                nameInput.focus();
            }

        },
        20
    );

}


/* =========================================================
   OPEN EDIT MODAL
========================================================= */

function openEditProductModal(
    product
) {

    document.getElementById(
        'editProductId'
    ).value =
        product.id || '';


    document.getElementById(
        'editProductName'
    ).value =
        product.product_name || '';


    document.getElementById(
        'editProductCode'
    ).value =
        product.product_code || '';


    document.getElementById(
        'editProductBarcode'
    ).value =
        product.barcode || '';


    setSearchableSelectValue(
        'editProductCategory',
        product.category_id || ''
    );


    setSearchableSelectValue(
        'editProductBaseUnit',
        product.base_unit_id || ''
    );


    document.getElementById(
        'editProductCost'
    ).value =
        product.cost_price || 0;


    document.getElementById(
        'editProductSelling'
    ).value =
        product.selling_price || 0;


    document.getElementById(
        'editProductMinimum'
    ).value =
        product.minimum_stock || 0;


    document.getElementById(
        'editProductStatus'
    ).value =
        product.status
        || 'Active';


    document.getElementById(
        'editProductDescription'
    ).value =
        product.description
        || '';


    document.getElementById(
        'editProductExpiry'
    ).checked =
        parseInt(
            product.track_expiry
            || 0
        )
        === 1;


    const modal =
        document.getElementById(
            'editProductModal'
        );


    if (!modal) {
        return;
    }


    modal.classList.remove(
        'hidden'
    );

    modal.classList.add(
        'flex'
    );


    document.body.style.overflow =
        'hidden';


    setTimeout(
        function () {

            const box =
                modal.querySelector(
                    '.modal-box'
                );


            if (box) {

                box.classList.remove(
                    'scale-95'
                );

                box.classList.add(
                    'scale-100'
                );
            }


            document
                .getElementById(
                    'editProductName'
                )
                .focus();

        },
        20
    );

}



/* =========================================================
   PRODUCT UNITS
========================================================= */

let activeProductForUnits = null;
let activeProductUnits = [];

function escapeProductUnitHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatProductUnitNumber(value, decimals = 2) {
    const number = Number(value || 0);
    return Number.isFinite(number) ? number.toFixed(decimals) : Number(0).toFixed(decimals);
}

function openManageUnitsModal(product) {
    activeProductForUnits = product || null;
    activeProductUnits = Array.isArray(product?.units) ? product.units : [];

    document.getElementById('manageUnitsProductName').textContent =
        `${product?.product_name || 'Product'}${product?.id ? '  •  ID #' + product.id : ''}`;

    document.getElementById('productUnitProductId').value = product?.id || '';

    resetProductUnitForm();
    renderProductUnits();

    const modal = document.getElementById('manageUnitsModal');
    if (!modal) return;

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

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function renderProductUnits() {
    const tbody = document.getElementById('productUnitsTableBody');
    const empty = document.getElementById('productUnitsEmpty');
    const count = document.getElementById('productUnitsCount');

    if (!tbody) return;

    tbody.innerHTML = '';

    if (count) {
        count.textContent = `${activeProductUnits.length} ${activeProductUnits.length === 1 ? 'Unit' : 'Units'}`;
    }

    if (!activeProductUnits.length) {
        if (empty) empty.classList.remove('hidden');
        return;
    }

    if (empty) empty.classList.add('hidden');

    activeProductUnits.forEach(function (unit) {
        const isBase = Number(unit.is_base_unit) === 1;
        const isActive = unit.status === 'Active';
        const useText = [
            Number(unit.can_purchase) === 1 ? 'Purchase' : '',
            Number(unit.can_sell) === 1 ? 'Sell' : ''
        ].filter(Boolean).join(' + ') || 'None';

        const row = document.createElement('tr');
        row.className = 'text-[10px] text-slate-600 dark:text-slate-300';

        row.innerHTML = `
            <td class="px-4 py-3">
                <div class="font-extrabold text-slate-800 dark:text-white">${escapeProductUnitHtml(unit.unit_name)}</div>
                <div class="mt-0.5 flex items-center gap-1.5 text-[9px] text-slate-400">
                    <span>#${escapeProductUnitHtml(unit.unit_id)}</span>
                    <span>•</span>
                    <span>${escapeProductUnitHtml(unit.short_name)}</span>
                    ${isBase ? '<span class="ml-1 rounded-full bg-blue-50 px-2 py-0.5 font-extrabold text-blue-600 dark:bg-blue-950/40">BASE</span>' : ''}
                </div>
            </td>
            <td class="px-4 py-3 text-right font-extrabold">${formatProductUnitNumber(unit.conversion_qty, 3)}</td>
            <td class="px-4 py-3 text-right">$${formatProductUnitNumber(unit.purchase_price, 2)}</td>
            <td class="px-4 py-3 text-right font-extrabold text-slate-800 dark:text-white">$${formatProductUnitNumber(unit.selling_price, 2)}</td>
            <td class="px-4 py-3 text-center text-[9px] font-bold">${escapeProductUnitHtml(useText)}</td>
            <td class="px-4 py-3 text-center">
                <span class="inline-flex rounded-full px-2 py-1 text-[9px] font-extrabold ${isActive ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40' : 'bg-red-50 text-red-600 dark:bg-red-950/40'}">${escapeProductUnitHtml(unit.status)}</span>
            </td>
            <td class="px-4 py-3">
                <div class="flex items-center justify-center gap-1">
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-blue-600 transition hover:bg-blue-50 dark:hover:bg-blue-950/40" title="Edit Unit" data-unit-edit="${escapeProductUnitHtml(unit.id)}">
                        <i data-lucide="pencil" class="h-3.5 w-3.5"></i>
                    </button>
                    ${isBase ? '' : `
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950/40" title="Delete Unit" data-unit-delete="${escapeProductUnitHtml(unit.id)}">
                            <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                        </button>
                    `}
                </div>
            </td>
        `;

        tbody.appendChild(row);
    });

    tbody.querySelectorAll('[data-unit-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = Number(this.dataset.unitEdit || 0);
            const unit = activeProductUnits.find(item => Number(item.id) === id);
            if (unit) editProductUnit(unit);
        });
    });

    tbody.querySelectorAll('[data-unit-delete]').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = Number(this.dataset.unitDelete || 0);
            const unit = activeProductUnits.find(item => Number(item.id) === id);
            if (unit) openDeleteProductUnitModal(unit);
        });
    });

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function resetProductUnitForm() {
    const form = document.getElementById('productUnitForm');
    if (!form) return;

    document.getElementById('productUnitAction').value = 'add_product_unit';
    document.getElementById('productUnitId').value = '';
    document.getElementById('productUnitProductId').value = activeProductForUnits?.id || '';
    document.getElementById('productUnitConversion').value = '1';
    document.getElementById('productUnitPurchasePrice').value = '0.00';
    document.getElementById('productUnitSellingPrice').value = '0.00';
    document.getElementById('productUnitCanPurchase').checked = true;
    document.getElementById('productUnitCanSell').checked = true;
    document.getElementById('productUnitStatus').value = 'Active';

    document.getElementById('productUnitFormTitle').textContent = 'Add Unit';
    document.getElementById('productUnitSubmitText').textContent = 'Add Unit';
    document.getElementById('cancelProductUnitEditBtn').classList.add('hidden');
    document.getElementById('productUnitUnitField').classList.remove('hidden');
    document.getElementById('productUnitLockedUnit').classList.add('hidden');

    const conversion = document.getElementById('productUnitConversion');
    conversion.readOnly = false;
    conversion.classList.remove('bg-slate-100', 'dark:bg-slate-800');

    const purchaseToggle = document.getElementById('productUnitCanPurchase');
    const sellToggle = document.getElementById('productUnitCanSell');
    const status = document.getElementById('productUnitStatus');
    purchaseToggle.disabled = false;
    sellToggle.disabled = false;
    status.disabled = false;

    setSearchableSelectValue('productUnitUnitId', '');
    suggestProductUnitPrices();
}

function editProductUnit(unit) {
    const isBase = Number(unit.is_base_unit) === 1;

    document.getElementById('productUnitAction').value = 'update_product_unit';
    document.getElementById('productUnitId').value = unit.id || '';
    document.getElementById('productUnitProductId').value = unit.product_id || activeProductForUnits?.id || '';
    document.getElementById('productUnitConversion').value = isBase ? '1.000' : (unit.conversion_qty || '1');
    document.getElementById('productUnitPurchasePrice').value = unit.purchase_price || '0.00';
    document.getElementById('productUnitSellingPrice').value = unit.selling_price || '0.00';
    document.getElementById('productUnitCanPurchase').checked = Number(unit.can_purchase) === 1;
    document.getElementById('productUnitCanSell').checked = Number(unit.can_sell) === 1;
    document.getElementById('productUnitStatus').value = unit.status || 'Active';

    document.getElementById('productUnitFormTitle').textContent = isBase ? 'Edit Base Unit' : 'Edit Unit';
    document.getElementById('productUnitSubmitText').textContent = 'Save Changes';
    document.getElementById('cancelProductUnitEditBtn').classList.remove('hidden');

    // Keep the selected unit id in the form.
    setSearchableSelectValue('productUnitUnitId', unit.unit_id || '');

    // Base Unit and normal Product Units can both change their Unit.
    // The searchable dropdown stays available in both cases.
    document.getElementById('productUnitUnitField').classList.remove('hidden');
    document.getElementById('productUnitLockedUnit').classList.add('hidden');

    const conversion = document.getElementById('productUnitConversion');
    conversion.readOnly = isBase;
    conversion.classList.toggle('bg-slate-100', isBase);
    conversion.classList.toggle('dark:bg-slate-800', isBase);

    if (isBase) {
        document.getElementById('productUnitCanPurchase').checked = true;
        document.getElementById('productUnitCanSell').checked = true;
        document.getElementById('productUnitStatus').value = 'Active';
        document.getElementById('productUnitCanPurchase').disabled = true;
        document.getElementById('productUnitCanSell').disabled = true;
        document.getElementById('productUnitStatus').disabled = true;
    } else {
        document.getElementById('productUnitCanPurchase').disabled = false;
        document.getElementById('productUnitCanSell').disabled = false;
        document.getElementById('productUnitStatus').disabled = false;
    }
}

function suggestProductUnitPrices() {
    if (!activeProductForUnits) return;
    if (document.getElementById('productUnitAction').value !== 'add_product_unit') return;

    const conversion = Number(document.getElementById('productUnitConversion').value || 0);
    const cost = Number(activeProductForUnits.cost_price || 0);
    const selling = Number(activeProductForUnits.selling_price || 0);

    document.getElementById('productUnitPurchasePrice').value = (cost * conversion).toFixed(2);
    document.getElementById('productUnitSellingPrice').value = (selling * conversion).toFixed(2);
}

function validateProductUnitForm() {
    const productInput = document.getElementById('productUnitProductId');
    const unitInput = document.getElementById('productUnitUnitId');
    const conversionInput = document.getElementById('productUnitConversion');

    /*
     * The custom searchable select is not a native <select>.
     * Recover the value from the selected option if the hidden input was not
     * updated by the browser for any reason.
     */
    if (unitInput && !unitInput.value) {
        const wrapper = document.getElementById('productUnitSelectWrapper');
        const selectedOption = wrapper?.querySelector('.searchable-option.selected');

        if (selectedOption?.dataset?.value) {
            unitInput.value = selectedOption.dataset.value;
        }
    }

    /* Keep the currently opened product attached to the form. */
    if (productInput && !productInput.value && activeProductForUnits?.id) {
        productInput.value = String(activeProductForUnits.id);
    }

    const productId = Number(productInput?.value || 0);
    const unitId = Number(unitInput?.value || 0);
    const conversion = Number(conversionInput?.value || 0);

    if (!(productId > 0)) {
        showProductUnitSaveMessage('Product-ka lama aqoonsan. Xir Manage Units kadib dib u fur.', 'error');
        return false;
    }

    if (!(unitId > 0)) {
        showProductUnitSaveMessage('Fadlan Unit dooro.', 'error');
        return false;
    }

    if (!Number.isFinite(conversion) || !(conversion > 0)) {
        showProductUnitSaveMessage('Conversion Qty waa inuu ka weyn yahay 0.', 'error');
        conversionInput?.focus();
        return false;
    }

    /* Disabled inputs are not submitted; enable them before base-unit update. */
    document.getElementById('productUnitCanPurchase').disabled = false;
    document.getElementById('productUnitCanSell').disabled = false;
    document.getElementById('productUnitStatus').disabled = false;

    return true;
}


/* =========================================================
   SAVE PRODUCT UNIT UPDATE WITHOUT LEAVING MODAL
========================================================= */

async function saveProductUnitAddAjax(form) {
    const submitBtn = document.getElementById('productUnitSubmitBtn');
    const submitText = document.getElementById('productUnitSubmitText');
    const originalText = submitText ? submitText.textContent : 'Add Unit';

    if (submitBtn) submitBtn.disabled = true;
    if (submitText) submitText.textContent = 'Adding...';

    try {
        const formData = new FormData(form);
        formData.set('ajax', '1');
        formData.set('action', 'add_product_unit');

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        let data = null;
        try {
            data = await response.json();
        } catch (e) {
            throw new Error('Server returned an invalid response.');
        }

        if (!response.ok || !data || data.success !== true) {
            throw new Error(data?.message || 'Unable to add product unit.');
        }

        if (data.unit) {
            activeProductUnits.push(data.unit);

            if (activeProductForUnits) {
                activeProductForUnits.units = activeProductUnits;
            }

            renderProductUnits();
            resetProductUnitForm();
        }

        showProductUnitSaveMessage(
            data.message || 'Product unit added successfully.',
            'success'
        );

    } catch (error) {
        showProductUnitSaveMessage(
            error.message || 'Unable to add product unit.',
            'error'
        );
    } finally {
        if (submitBtn) submitBtn.disabled = false;
        if (submitText) submitText.textContent = originalText;
    }
}

async function saveProductUnitUpdateAjax(form) {
    const submitBtn = document.getElementById('productUnitSubmitBtn');
    const submitText = document.getElementById('productUnitSubmitText');
    const originalText = submitText ? submitText.textContent : 'Save Changes';

    if (submitBtn) submitBtn.disabled = true;
    if (submitText) submitText.textContent = 'Saving...';

    try {
        const formData = new FormData(form);
        formData.set('ajax', '1');

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        let data = null;
        try {
            data = await response.json();
        } catch (e) {
            throw new Error('Server returned an invalid response.');
        }

        if (!response.ok || !data || data.success !== true) {
            throw new Error(data?.message || 'Unable to update product unit.');
        }

        if (data.unit) {
            const updatedId = Number(data.unit.id || 0);
            const index = activeProductUnits.findIndex(
                item => Number(item.id) === updatedId
            );

            if (index !== -1) {
                activeProductUnits[index] = data.unit;
            }

            if (
                activeProductForUnits &&
                Number(data.unit.is_base_unit) === 1
            ) {
                activeProductForUnits.cost_price = data.unit.purchase_price;
                activeProductForUnits.selling_price = data.unit.selling_price;
            }

            renderProductUnits();
            editProductUnit(data.unit);
        }

        showProductUnitSaveMessage(
            data.message || 'Product unit updated successfully.',
            'success'
        );

    } catch (error) {
        showProductUnitSaveMessage(
            error.message || 'Unable to update product unit.',
            'error'
        );
    } finally {
        if (submitBtn) submitBtn.disabled = false;
        if (submitText) submitText.textContent = originalText;
    }
}

function showProductUnitSaveMessage(message, type = 'success') {
    let box = document.getElementById('productUnitInlineMessage');

    if (!box) {
        const form = document.getElementById('productUnitForm');
        if (!form) return;

        box = document.createElement('div');
        box.id = 'productUnitInlineMessage';
        box.className = 'mb-3 rounded-xl px-3 py-2 text-[10px] font-bold';
        form.prepend(box);
    }

    box.textContent = message;
    box.className =
        'mb-3 rounded-xl px-3 py-2 text-[10px] font-bold ' +
        (type === 'success'
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
            : 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300');

    box.classList.remove('hidden');

    clearTimeout(window.productUnitMessageTimer);
    window.productUnitMessageTimer = setTimeout(function () {
        box.classList.add('hidden');
    }, 3000);
}

function openDeleteProductUnitModal(unit) {
    if (Number(unit.is_base_unit) === 1) return;

    document.getElementById('deleteProductUnitId').value = unit.id || '';
    document.getElementById('deleteProductUnitName').textContent = `${unit.unit_name || ''} (${unit.short_name || ''})`;

    const modal = document.getElementById('deleteProductUnitModal');
    if (!modal) return;

    modal.classList.remove('hidden');
    modal.classList.add('flex');

    setTimeout(function () {
        const box = modal.querySelector('.modal-box');
        if (box) {
            box.classList.remove('scale-95');
            box.classList.add('scale-100');
        }
    }, 20);

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}


/* =========================================================
   OPEN DELETE MODAL
========================================================= */

function openDeleteProductModal(
    product
) {

    const modal =
        document.getElementById(
            'deleteProductModal'
        );


    if (!modal) {
        return;
    }


    document.getElementById(
        'deleteProductId'
    ).value =
        product.id || '';


    document.getElementById(
        'deleteProductName'
    ).textContent =
        (
            product.product_name
            || ''
        )
        +
        (
            product.product_code
                ? ' (' +
                  product.product_code +
                  ')'
                : ''
        );


    modal.classList.remove(
        'hidden'
    );

    modal.classList.add(
        'flex'
    );


    document.body.style.overflow =
        'hidden';


    setTimeout(
        function () {

            const box =
                modal.querySelector(
                    '.modal-box'
                );


            if (box) {

                box.classList.remove(
                    'scale-95'
                );

                box.classList.add(
                    'scale-100'
                );
            }

        },
        20
    );

}


/* =========================================================
   CLOSE MODAL
========================================================= */

function closeProductModal(
    modalId
) {

    const modal =
        document.getElementById(
            modalId
        );


    if (
        !modal ||
        modal.classList.contains(
            'hidden'
        )
    ) {
        return;
    }


    const box =
        modal.querySelector(
            '.modal-box'
        );


    if (box) {

        box.classList.remove(
            'scale-100'
        );

        box.classList.add(
            'scale-95'
        );
    }


    setTimeout(
        function () {

            modal.classList.add(
                'hidden'
            );

            modal.classList.remove(
                'flex'
            );


            document.body.style.overflow =
                '';

        },
        120
    );

}


/* =========================================================
   VALIDATE ADD PRODUCT
========================================================= */

function validateAddProduct() {

    const baseUnit =
        document.getElementById(
            'addBaseUnitId'
        );


    if (
        !baseUnit ||
        !baseUnit.value
    ) {

        alert(
            'Please select a base unit.'
        );

        return false;
    }


    return true;

}


/* =========================================================
   VALIDATE EDIT PRODUCT
========================================================= */

function validateEditProduct() {

    const baseUnit =
        document.getElementById(
            'editProductBaseUnit'
        );


    if (
        !baseUnit ||
        !baseUnit.value
    ) {

        alert(
            'Please select a base unit.'
        );

        return false;
    }


    return true;

}


/* =========================================================
   STATUS CONFIRM
========================================================= */

function confirmProductStatus(
    currentStatus
) {

    if (
        currentStatus
        === 'Active'
    ) {

        return confirm(
            'Deactivate this product?'
        );
    }


    return confirm(
        'Activate this product?'
    );

}


/* =========================================================
   DOM READY
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /*
        |--------------------------------------------------------------------------
        | ICONS
        |--------------------------------------------------------------------------
        */

        if (
            typeof lucide
            !== 'undefined'
        ) {

            lucide.createIcons();

        }


        /*
        |--------------------------------------------------------------------------
        | SEARCHABLE DROPDOWNS
        |--------------------------------------------------------------------------
        */

        initializeSearchableSelects();


        /*
        |--------------------------------------------------------------------------
        | PRODUCT UNIT AJAX UPDATE
        |--------------------------------------------------------------------------
        */
        const productUnitForm =
            document.getElementById('productUnitForm');

        if (productUnitForm) {
            productUnitForm.addEventListener(
                'submit',
                function (event) {
                    event.preventDefault();

                    if (!validateProductUnitForm()) {
                        return;
                    }

                    const action =
                        document.getElementById('productUnitAction')?.value || '';

                    if (action === 'update_product_unit') {
                        saveProductUnitUpdateAjax(this);
                        return;
                    }

                    if (action === 'add_product_unit') {
                        saveProductUnitAddAjax(this);
                        return;
                    }
                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | PRODUCT AUTO SEARCH
        |--------------------------------------------------------------------------
        */

        const searchInput =
            document.getElementById(
                'productSearch'
            );


        const rows =
            document.querySelectorAll(
                '.product-row'
            );


        const searchEmptyRow =
            document.getElementById(
                'searchEmptyRow'
            );


        if (searchInput) {

            searchInput.addEventListener(
                'input',
                function () {

                    const query =
                        this.value
                            .trim()
                            .toLowerCase();


                    let visible = 0;


                    rows.forEach(
                        function (row) {

                            const searchable =
                                (
                                    row.dataset.search
                                    || ''
                                )
                                .toLowerCase();


                            const match =
                                searchable
                                    .includes(
                                        query
                                    );


                            row.style.display =
                                match
                                    ? ''
                                    : 'none';


                            if (match) {
                                visible++;
                            }

                        }
                    );


                    if (searchEmptyRow) {

                        if (
                            rows.length > 0
                            &&
                            visible === 0
                        ) {

                            searchEmptyRow
                                .classList
                                .remove(
                                    'hidden'
                                );

                        } else {

                            searchEmptyRow
                                .classList
                                .add(
                                    'hidden'
                                );
                        }
                    }

                }
            );

        }


        /*
        |--------------------------------------------------------------------------
        | MODAL BACKDROP CLOSE
        |--------------------------------------------------------------------------
        */

        [
            'addProductModal',
            'editProductModal',
            'manageUnitsModal',
            'deleteProductUnitModal',
            'deleteProductModal'
        ]
        .forEach(
            function (modalId) {

                const modal =
                    document.getElementById(
                        modalId
                    );


                if (!modal) {
                    return;
                }


                modal.addEventListener(
                    'click',
                    function (event) {

                        if (
                            event.target
                            === modal
                        ) {

                            closeProductModal(
                                modalId
                            );
                        }

                    }
                );

            }
        );


        /*
        |--------------------------------------------------------------------------
        | ESC CLOSE
        |--------------------------------------------------------------------------
        */

        document.addEventListener(
            'keydown',
            function (event) {

                if (
                    event.key
                    === 'Escape'
                ) {

                    closeProductModal(
                        'addProductModal'
                    );

                    closeProductModal(
                        'editProductModal'
                    );

                    closeProductModal(
                        'manageUnitsModal'
                    );

                    closeProductModal(
                        'deleteProductUnitModal'
                    );

                    closeProductModal(
                        'deleteProductModal'
                    );
                }

            }
        );


        /*
        |--------------------------------------------------------------------------
        | FLASH AUTO HIDE
        |--------------------------------------------------------------------------
        */

        const flash =
            document.getElementById(
                'productFlash'
            );


        if (flash) {

            setTimeout(
                function () {

                    flash.style.opacity =
                        '0';

                    flash.style.transform =
                        'translateY(-5px)';


                    setTimeout(
                        function () {
                            flash.remove();
                        },
                        300
                    );

                },
                3500
            );

        }

    }
);

</script>


<script src="../assets/js/app.js"></script>

</body>
</html>