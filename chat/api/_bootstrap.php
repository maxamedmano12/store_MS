<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function chatJson(
    array $data,
    int $status = 200
): never {

    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| CURRENT USER ID
|--------------------------------------------------------------------------
*/

function chatUserId(): int
{
    $userId =
        (int)(
            $_SESSION['user_id']
            ?? 0
        );

    if ($userId <= 0) {

        chatJson([
            'ok' => false,
            'message' => 'Unauthenticated.'
        ], 401);
    }

    return $userId;
}


/*
|--------------------------------------------------------------------------
| CURRENT ROLE
|--------------------------------------------------------------------------
*/

function chatRole(): string
{
    return (string)(
        $_SESSION['role']
        ?? ''
    );
}


/*
|--------------------------------------------------------------------------
| BUSINESS ID
|--------------------------------------------------------------------------
*/

function chatBusinessId(): ?int
{
    $businessId =
        (int)(
            $_SESSION['business_id']
            ?? 0
        );

    return
        $businessId > 0
            ? $businessId
            : null;
}


/*
|--------------------------------------------------------------------------
| BRANCH ID
|--------------------------------------------------------------------------
*/

function chatBranchId(): ?int
{
    $branchId =
        (int)(
            $_SESSION['branch_id']
            ?? 0
        );

    return
        $branchId > 0
            ? $branchId
            : null;
}


/*
|--------------------------------------------------------------------------
| SUPER ADMIN
|--------------------------------------------------------------------------
*/

function chatIsSuperAdmin(): bool
{
    return
        chatRole() ===
        'Super Admin';
}


/*
|--------------------------------------------------------------------------
| BUSINESS ADMIN
|--------------------------------------------------------------------------
*/

function chatIsBusinessAdmin(): bool
{
    return
        chatRole() ===
        'Business Admin';
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function chatCsrfValid(): bool
{
    $sessionToken =
        (string)(
            $_SESSION['csrf_token']
            ?? ''
        );

    $postedToken =
        (string)(
            $_POST['csrf_token']
            ?? ''
        );

    if (
        $sessionToken === ''
        ||
        $postedToken === ''
    ) {
        return false;
    }

    return hash_equals(
        $sessionToken,
        $postedToken
    );
}


/*
|--------------------------------------------------------------------------
| CAN CONTACT USER
|--------------------------------------------------------------------------
|
| Rules:
|
| Super Admin
|   -> everyone
|
| Business Admin
|   -> Super Admin
|   -> all active users in own business
|
| Normal User
|   -> Super Admin
|   -> Business Admin in own business
|   -> normal users in same branch only
|
*/

function chatCanContact(
    int $targetUserId
): bool {

    global $conn;

    if (
        !isset($conn)
        ||
        !($conn instanceof PDO)
    ) {

        chatJson([
            'ok' => false,
            'message' =>
                'Database connection unavailable.'
        ], 500);
    }


    $currentUserId =
        chatUserId();


    if (
        $targetUserId <= 0
        ||
        $targetUserId ===
        $currentUserId
    ) {
        return false;
    }


    $stmt =
        $conn->prepare("
            SELECT
                u.id,
                u.business_id,
                u.branch_id,
                u.status,
                r.role_name

            FROM users u

            INNER JOIN roles r
                ON r.id = u.role_id

            WHERE u.id = ?

            LIMIT 1
        ");


    $stmt->execute([
        $targetUserId
    ]);


    $target =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$target) {
        return false;
    }


    if (
        (string)$target['status']
        !== 'Active'
    ) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Super Admin -> anyone
    |--------------------------------------------------------------------------
    */

    if (chatIsSuperAdmin()) {
        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Everyone -> Super Admin
    |--------------------------------------------------------------------------
    */

    if (
        (string)$target['role_name']
        === 'Super Admin'
    ) {
        return true;
    }


    $myBusinessId =
        chatBusinessId();

    $myBranchId =
        chatBranchId();


    $targetBusinessId =
        !empty(
            $target['business_id']
        )
            ? (int)$target['business_id']
            : null;


    $targetBranchId =
        !empty(
            $target['branch_id']
        )
            ? (int)$target['branch_id']
            : null;


    /*
    |--------------------------------------------------------------------------
    | Other businesses blocked
    |--------------------------------------------------------------------------
    */

    if (
        $myBusinessId === null
        ||
        $targetBusinessId !==
        $myBusinessId
    ) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Business Admin -> all own business
    |--------------------------------------------------------------------------
    */

    if (chatIsBusinessAdmin()) {
        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Normal User -> Business Admin
    |--------------------------------------------------------------------------
    */

    if (
        (string)$target['role_name']
        === 'Business Admin'
    ) {
        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Normal User -> same branch only
    |--------------------------------------------------------------------------
    */

    if ($myBranchId === null) {
        return false;
    }


    return
        $targetBranchId ===
        $myBranchId;
}


/*
|--------------------------------------------------------------------------
| CAN ACCESS CONVERSATION
|--------------------------------------------------------------------------
*/

function chatCanAccessConversation(
    int $conversationId
): bool {

    global $conn;

    if (
        !isset($conn)
        ||
        !($conn instanceof PDO)
    ) {
        return false;
    }


    if ($conversationId <= 0) {
        return false;
    }


    $stmt =
        $conn->prepare("
            SELECT 1

            FROM chat_members

            WHERE
                conversation_id = ?
                AND user_id = ?

            LIMIT 1
        ");


    $stmt->execute([
        $conversationId,
        chatUserId()
    ]);


    return
        (bool)$stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| FIND OR CREATE DIRECT CONVERSATION
|--------------------------------------------------------------------------
*/

function chatFindOrCreateDirect(
    int $targetUserId
): int {

    global $conn;

    if (
        !isset($conn)
        ||
        !($conn instanceof PDO)
    ) {

        chatJson([
            'ok' => false,
            'message' =>
                'Database connection unavailable.'
        ], 500);
    }


    $currentUserId =
        chatUserId();


    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    if (
        !chatCanContact(
            $targetUserId
        )
    ) {

        chatJson([
            'ok' => false,
            'message' =>
                'You cannot chat with this user.'
        ], 403);
    }


    /*
    |--------------------------------------------------------------------------
    | Existing conversation
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare("
            SELECT
                c.id

            FROM chat_conversations c

            INNER JOIN chat_members m1
                ON
                    m1.conversation_id =
                        c.id
                    AND m1.user_id = ?

            INNER JOIN chat_members m2
                ON
                    m2.conversation_id =
                        c.id
                    AND m2.user_id = ?

            WHERE
                c.conversation_type
                IN ('direct', 'support')

                AND (
                    SELECT COUNT(*)

                    FROM chat_members cm

                    WHERE
                        cm.conversation_id =
                            c.id
                ) = 2

            ORDER BY
                c.updated_at DESC,
                c.id DESC

            LIMIT 1
        ");


    $stmt->execute([
        $currentUserId,
        $targetUserId
    ]);


    $conversationId =
        (int)(
            $stmt->fetchColumn()
            ?: 0
        );


    if ($conversationId > 0) {
        return $conversationId;
    }


    /*
    |--------------------------------------------------------------------------
    | CONVERSATION BUSINESS ID
    |--------------------------------------------------------------------------
    |
    | Business user:
    |   -> own business_id
    |
    | Super Admin:
    |   -> target user's business_id
    |
    */

    $businessId =
        chatBusinessId();


    if (
        chatIsSuperAdmin()
        &&
        $businessId === null
    ) {

        $businessStmt =
            $conn->prepare("
                SELECT business_id

                FROM users

                WHERE id = ?

                LIMIT 1
            ");


        $businessStmt->execute([
            $targetUserId
        ]);


        $targetBusinessId =
            $businessStmt->fetchColumn();


        if (
            $targetBusinessId !== false
            &&
            $targetBusinessId !== null
        ) {

            $businessId =
                (int)$targetBusinessId;

        } else {

            $businessId = null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE CONVERSATION
    |--------------------------------------------------------------------------
    */

    try {

        $conn->beginTransaction();


        $stmt =
            $conn->prepare("
                INSERT INTO
                    chat_conversations
                (
                    conversation_type,
                    business_id,
                    created_by
                )

                VALUES
                (
                    'direct',
                    ?,
                    ?
                )
            ");


        $stmt->execute([
            $businessId,
            $currentUserId
        ]);


        $conversationId =
            (int)$conn
                ->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | ADD BOTH USERS
        |--------------------------------------------------------------------------
        */

        $stmt =
            $conn->prepare("
                INSERT INTO
                    chat_members
                (
                    conversation_id,
                    user_id
                )

                VALUES
                    (?, ?),
                    (?, ?)
            ");


        $stmt->execute([

            $conversationId,
            $currentUserId,

            $conversationId,
            $targetUserId

        ]);


        $conn->commit();


        return $conversationId;


    } catch (Throwable $e) {


        if (
            $conn->inTransaction()
        ) {
            $conn->rollBack();
        }


        throw $e;
    }
}