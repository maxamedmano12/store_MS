<?php

require_once __DIR__ . '/_bootstrap.php';


/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    chatJson([
        'ok' => false,
        'message' => 'Method not allowed.'
    ], 405);
}


/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$currentUserId = chatUserId();


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (!chatCsrfValid()) {

    chatJson([
        'ok' => false,
        'message' => 'Invalid CSRF token.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$conversationId =
    (int)($_POST['conversation_id'] ?? 0);

$message =
    trim(
        (string)($_POST['message'] ?? '')
    );

$replyToId =
    (int)($_POST['reply_to_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| VALIDATE CONVERSATION
|--------------------------------------------------------------------------
*/

if ($conversationId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Invalid conversation.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| CHECK CONVERSATION ACCESS
|--------------------------------------------------------------------------
*/

if (
    !chatCanAccessConversation(
        $conversationId
    )
) {

    chatJson([
        'ok' => false,
        'message' =>
            'You cannot access this conversation.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| VALIDATE MESSAGE
|--------------------------------------------------------------------------
*/

if ($message === '') {

    chatJson([
        'ok' => false,
        'message' =>
            'Message cannot be empty.'
    ], 422);
}


if (mb_strlen($message) > 5000) {

    chatJson([
        'ok' => false,
        'message' =>
            'Message is too long.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| VALIDATE REPLY
|--------------------------------------------------------------------------
*/

$validReplyToId = null;


if ($replyToId > 0) {

    $stmt =
        $conn->prepare("
            SELECT id

            FROM chat_messages

            WHERE
                id = ?
                AND conversation_id = ?
                AND is_deleted = 0
                AND (
                    expires_at IS NULL
                    OR expires_at > NOW()
                )

            LIMIT 1
        ");


    $stmt->execute([
        $replyToId,
        $conversationId
    ]);


    $foundReply =
        $stmt->fetchColumn();


    if ($foundReply !== false) {

        $validReplyToId =
            (int)$foundReply;
    }
}


/*
|--------------------------------------------------------------------------
| GET DISAPPEARING SETTING
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT
            disappearing_seconds

        FROM chat_conversations

        WHERE id = ?

        LIMIT 1
    ");


$stmt->execute([
    $conversationId
]);


$disappearingSeconds =
    $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| CALCULATE EXPIRY
|--------------------------------------------------------------------------
*/

$expiresAt = null;


if (
    $disappearingSeconds !== false
    &&
    $disappearingSeconds !== null
    &&
    (int)$disappearingSeconds > 0
) {

    $expiresAt =
        date(
            'Y-m-d H:i:s',
            time()
            + (int)$disappearingSeconds
        );
}


/*
|--------------------------------------------------------------------------
| INSERT MESSAGE
|--------------------------------------------------------------------------
*/

try {

    $conn->beginTransaction();


    $stmt =
        $conn->prepare("
            INSERT INTO chat_messages
            (
                conversation_id,
                sender_id,
                reply_to_id,
                message,
                message_type,
                attachment_path,
                attachment_name,
                attachment_mime,
                is_view_once,
                viewed_at,
                delivered_at,
                read_at,
                expires_at,
                is_deleted
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                'text',
                NULL,
                NULL,
                NULL,
                0,
                NULL,
                NULL,
                NULL,
                ?,
                0
            )
        ");


    $stmt->execute([

        $conversationId,

        $currentUserId,

        $validReplyToId,

        $message,

        $expiresAt

    ]);


    $messageId =
        (int)$conn
            ->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | UPDATE CONVERSATION
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare("
            UPDATE chat_conversations

            SET
                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?
        ");


    $stmt->execute([
        $conversationId
    ]);


    /*
    |--------------------------------------------------------------------------
    | LOAD CREATED MESSAGE
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare("
            SELECT
                m.id,
                m.conversation_id,
                m.sender_id,
                m.reply_to_id,
                m.message,
                m.message_type,
                m.expires_at,
                m.created_at,

                u.full_name

            FROM chat_messages m

            INNER JOIN users u
                ON u.id = m.sender_id

            WHERE m.id = ?

            LIMIT 1
        ");


    $stmt->execute([
        $messageId
    ]);


    $messageRow =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    $conn->commit();


    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    chatJson([
        'ok' => true,

        'message' =>
            'Message sent successfully.',

        'message_data' => [

            'id' =>
                $messageId,

            'conversation_id' =>
                $conversationId,

            'sender_id' =>
                $currentUserId,

            'sender_name' =>
                $messageRow
                    ? (string)$messageRow['full_name']
                    : (string)(
                        $_SESSION['full_name']
                        ?? ''
                    ),

            'message' =>
                $message,

            'message_type' =>
                'text',

            'reply_to_id' =>
                $validReplyToId,

            'is_mine' =>
                1,

            'is_view_once' =>
                0,

            'viewed_at' =>
                null,

            'delivered_at' =>
                null,

            'read_at' =>
                null,

            'status' =>
                'sent',

            'expires_at' =>
                $expiresAt,

            'created_at' =>
                $messageRow
                    ? $messageRow['created_at']
                    : date(
                        'Y-m-d H:i:s'
                    )
        ]
    ]);


} catch (Throwable $e) {


    if ($conn->inTransaction()) {

        $conn->rollBack();
    }


    /*
    |--------------------------------------------------------------------------
    | DEVELOPMENT ERROR
    |--------------------------------------------------------------------------
    |
    | Intentionally return JSON so frontend never receives
    | an HTML PHP error page.
    |
    */

    chatJson([
        'ok' => false,

        'message' =>
            'Could not send message.',

        'error' =>
            $e->getMessage()

    ], 500);
}