<?php

/*
|--------------------------------------------------------------------------
| AUDIT LOG HELPER
|--------------------------------------------------------------------------
|
| File:
|   config/audit.php
|
| Usage:
|
|   require_once '../config/audit.php';
|
|   logActivity(
|       $conn,
|       'CREATE',
|       'Products',
|       $productId,
|       'Product created',
|       null,
|       $newData
|   );
|
|--------------------------------------------------------------------------
*/


if (!function_exists('logActivity')) {

    function logActivity(
        PDO $conn,
        string $action,
        string $module,
        ?int $recordId = null,
        ?string $description = null,
        $oldValues = null,
        $newValues = null,
        ?int $targetBusinessId = null,
        ?int $targetBranchId = null
    ): void {

        /*
        |--------------------------------------------------------------------------
        | CURRENT SESSION / ACTOR
        |--------------------------------------------------------------------------
        */

        $userId =
            isset($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;


        $role =
            isset($_SESSION['role'])
            ? trim((string) $_SESSION['role'])
            : null;


        $authContext =
            isset($_SESSION['auth_context'])
            ? trim((string) $_SESSION['auth_context'])
            : null;


        /*
        |--------------------------------------------------------------------------
        | ACTOR BUSINESS
        |--------------------------------------------------------------------------
        |
        | Super Admin:
        |   actor business_id MUST always be NULL.
        |
        | Business User:
        |   actor business_id comes from current session.
        |
        |--------------------------------------------------------------------------
        */

        $businessId = null;


        if (
            $authContext === 'business'
            && $role !== 'Super Admin'
            && isset($_SESSION['business_id'])
            && $_SESSION['business_id'] !== null
        ) {

            $businessId =
                (int) $_SESSION['business_id'];
        }


        /*
        |--------------------------------------------------------------------------
        | ACTOR BRANCH
        |--------------------------------------------------------------------------
        |
        | Super Admin:
        |   actor branch should not automatically inherit any branch.
        |
        | Business User:
        |   use current active branch.
        |
        |--------------------------------------------------------------------------
        */

        $sessionBranchId = null;


        if (
            $authContext === 'business'
            && $role !== 'Super Admin'
            && isset($_SESSION['branch_id'])
            && $_SESSION['branch_id'] !== null
        ) {

            $sessionBranchId =
                (int) $_SESSION['branch_id'];
        }


        /*
        |--------------------------------------------------------------------------
        | TARGET BUSINESS
        |--------------------------------------------------------------------------
        |
        | BUSINESS USER
        |   By default target is own business.
        |
        | SUPER ADMIN
        |   targetBusinessId should normally be passed explicitly.
        |
        |--------------------------------------------------------------------------
        */

        if ($targetBusinessId !== null) {

            $targetBusinessId =
                (int) $targetBusinessId;

        } elseif ($businessId !== null) {

            $targetBusinessId =
                $businessId;

        } else {

            $targetBusinessId =
                null;
        }


        /*
        |--------------------------------------------------------------------------
        | TARGET BRANCH
        |--------------------------------------------------------------------------
        |
        | If a branch was explicitly supplied, use it.
        |
        | Otherwise business users use their active branch.
        |
        | Super Admin defaults to NULL.
        |
        |--------------------------------------------------------------------------
        */

        if ($targetBranchId !== null) {

            $branchId =
                (int) $targetBranchId;

        } elseif ($sessionBranchId !== null) {

            $branchId =
                $sessionBranchId;

        } else {

            $branchId =
                null;
        }


        /*
        |--------------------------------------------------------------------------
        | SECURITY: BUSINESS USER TARGET VALIDATION
        |--------------------------------------------------------------------------
        |
        | A business user must never log activity against another business.
        |
        |--------------------------------------------------------------------------
        */

        if (
            $businessId !== null
            && $targetBusinessId !== null
            && $businessId !== $targetBusinessId
        ) {

            error_log(
                'Audit Security Warning: '
                . 'Business user attempted cross-business audit log. '
                . 'Actor Business ID: '
                . $businessId
                . ', Target Business ID: '
                . $targetBusinessId
            );


            /*
            | Force activity back to actor's own business.
            */

            $targetBusinessId =
                $businessId;
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE TARGET BRANCH BELONGS TO TARGET BUSINESS
        |--------------------------------------------------------------------------
        */

        if (
            $branchId !== null
            && $targetBusinessId !== null
        ) {

            try {

                $branchStmt =
                    $conn->prepare("
                        SELECT
                            id
                        FROM branches
                        WHERE id = ?
                          AND business_id = ?
                        LIMIT 1
                    ");


                $branchStmt->execute([
                    $branchId,
                    $targetBusinessId
                ]);


                if (!$branchStmt->fetchColumn()) {

                    error_log(
                        'Audit Security Warning: '
                        . 'Invalid target branch/business relationship. '
                        . 'Branch ID: '
                        . $branchId
                        . ', Business ID: '
                        . $targetBusinessId
                    );


                    $branchId =
                        null;
                }


            } catch (Throwable $e) {

                /*
                | Audit logging must not break main application.
                */

                error_log(
                    'Audit Branch Validation Error: '
                    . $e->getMessage()
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | NORMALIZE ACTION
        |--------------------------------------------------------------------------
        */

        $action =
            strtoupper(
                trim($action)
            );


        $module =
            trim($module);


        /*
        |--------------------------------------------------------------------------
        | SAFE DEFAULTS
        |--------------------------------------------------------------------------
        */

        if ($action === '') {
            $action = 'UNKNOWN';
        }


        if ($module === '') {
            $module = 'Unknown';
        }


        /*
        |--------------------------------------------------------------------------
        | JSON VALUES
        |--------------------------------------------------------------------------
        */

        $oldValues =
            auditEncodeValues(
                $oldValues
            );


        $newValues =
            auditEncodeValues(
                $newValues
            );


        /*
        |--------------------------------------------------------------------------
        | CLIENT INFO
        |--------------------------------------------------------------------------
        */

        $ipAddress =
            auditClientIp();


        $userAgent =
            isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(
                (string) $_SERVER['HTTP_USER_AGENT'],
                0,
                1000
            )
            : null;


        /*
        |--------------------------------------------------------------------------
        | INSERT AUDIT LOG
        |--------------------------------------------------------------------------
        */

        try {

            $stmt =
                $conn->prepare("
                    INSERT INTO audit_logs (
                        user_id,
                        business_id,
                        target_business_id,
                        branch_id,
                        action,
                        module,
                        record_id,
                        description,
                        old_values,
                        new_values,
                        ip_address,
                        user_agent
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?
                    )
                ");


            $stmt->execute([

                $userId,

                $businessId,

                $targetBusinessId,

                $branchId,

                $action,

                $module,

                $recordId,

                $description,

                $oldValues,

                $newValues,

                $ipAddress,

                $userAgent

            ]);


        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | AUDIT FAILURE MUST NOT BREAK MAIN ACTION
            |--------------------------------------------------------------------------
            */

            error_log(
                'Audit Log Error: '
                . $e->getMessage()
            );
        }
    }
}



/*
|--------------------------------------------------------------------------
| JSON ENCODER
|--------------------------------------------------------------------------
*/

if (!function_exists('auditEncodeValues')) {

    function auditEncodeValues($value): ?string
    {
        if ($value === null) {
            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | ARRAYS / OBJECTS
        |--------------------------------------------------------------------------
        */

        if (
            is_array($value)
            || is_object($value)
        ) {

            $encoded =
                json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
                );


            return $encoded !== false
                ? $encoded
                : null;
        }


        /*
        |--------------------------------------------------------------------------
        | BOOLEAN
        |--------------------------------------------------------------------------
        */

        if (is_bool($value)) {

            return $value
                ? 'true'
                : 'false';
        }


        /*
        |--------------------------------------------------------------------------
        | STRING / NUMBER
        |--------------------------------------------------------------------------
        */

        return (string) $value;
    }
}



/*
|--------------------------------------------------------------------------
| CLIENT IP
|--------------------------------------------------------------------------
*/

if (!function_exists('auditClientIp')) {

    function auditClientIp(): ?string
    {
        /*
        |--------------------------------------------------------------------------
        | DEFAULT
        |--------------------------------------------------------------------------
        |
        | REMOTE_ADDR is safest unless your app is behind a trusted proxy.
        |
        |--------------------------------------------------------------------------
        */

        $ip =
            $_SERVER['REMOTE_ADDR']
            ?? null;


        if ($ip === null) {
            return null;
        }


        $ip =
            trim(
                (string) $ip
            );


        /*
        |--------------------------------------------------------------------------
        | VALID IP ONLY
        |--------------------------------------------------------------------------
        */

        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP
            ) === false
        ) {

            return null;
        }


        return $ip;
    }
}



/*
|--------------------------------------------------------------------------
| OPTIONAL SHORTCUT HELPERS
|--------------------------------------------------------------------------
*/


if (!function_exists('auditCreate')) {

    function auditCreate(
        PDO $conn,
        string $module,
        ?int $recordId = null,
        $newValues = null,
        ?string $description = null,
        ?int $targetBusinessId = null,
        ?int $targetBranchId = null
    ): void {

        logActivity(
            $conn,
            'CREATE',
            $module,
            $recordId,
            $description,
            null,
            $newValues,
            $targetBusinessId,
            $targetBranchId
        );
    }
}



if (!function_exists('auditUpdate')) {

    function auditUpdate(
        PDO $conn,
        string $module,
        ?int $recordId = null,
        $oldValues = null,
        $newValues = null,
        ?string $description = null,
        ?int $targetBusinessId = null,
        ?int $targetBranchId = null
    ): void {

        logActivity(
            $conn,
            'UPDATE',
            $module,
            $recordId,
            $description,
            $oldValues,
            $newValues,
            $targetBusinessId,
            $targetBranchId
        );
    }
}



if (!function_exists('auditDelete')) {

    function auditDelete(
        PDO $conn,
        string $module,
        ?int $recordId = null,
        $oldValues = null,
        ?string $description = null,
        ?int $targetBusinessId = null,
        ?int $targetBranchId = null
    ): void {

        logActivity(
            $conn,
            'DELETE',
            $module,
            $recordId,
            $description,
            $oldValues,
            null,
            $targetBusinessId,
            $targetBranchId
        );
    }
}