<?php

require_once __DIR__ . '/_bootstrap.php';

$userId =
    (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Unauthorized.'
    ], 401);
}


$conversationId =
    (int)(
        $_GET['conversation_id']
        ?? $_POST['conversation_id']
        ?? 0
    );


if ($conversationId <= 0) {

    chatJson([
        'ok' => false,
        'message' =>
            'Conversation is required.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| ACCESS
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
            'Access denied.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt =
        $conn->prepare("
            SELECT disappearing_seconds
            FROM chat_conversations
            WHERE id = ?
            LIMIT 1
        ");

    $stmt->execute([
        $conversationId
    ]);

    $seconds =
        $stmt->fetchColumn();


    $seconds =
        $seconds !== null
        && $seconds !== false
            ? (int)$seconds
            : 0;


    chatJson([
        'ok' => true,

        'conversation_id' =>
            $conversationId,

        'disappearing_seconds' =>
            $seconds,

        'label' =>
            disappearingLabel(
                $seconds
            )
    ]);
}


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    chatJson([
        'ok' => false,
        'message' =>
            'Method not allowed.'
    ], 405);
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrf =
    (string)(
        $_POST['csrf_token']
        ?? ''
    );

$sessionCsrf =
    (string)(
        $_SESSION['csrf_token']
        ?? ''
    );


if (
    $csrf === ''
    || $sessionCsrf === ''
    || !hash_equals(
        $sessionCsrf,
        $csrf
    )
) {

    chatJson([
        'ok' => false,
        'message' =>
            'Invalid CSRF token.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| SECONDS
|--------------------------------------------------------------------------
|
| 0        = Off
| 3600     = 1 Hour
| 86400    = 24 Hours
| 604800   = 7 Days
| 2592000  = 30 Days
|
*/

$seconds =
    (int)(
        $_POST['seconds']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| CUSTOM LIMIT
|--------------------------------------------------------------------------
|
| Off ama:
| minimum 60 seconds
| maximum 1 year
|--------------------------------------------------------------------------
*/

if (
    $seconds !== 0
    && (
        $seconds < 60
        || $seconds > 31536000
    )
) {

    chatJson([
        'ok' => false,
        'message' =>
            'Custom disappearing time must be between 1 minute and 1 year.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| USER NAME
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT full_name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

$stmt->execute([
    $userId
]);

$fullName =
    (string)(
        $stmt->fetchColumn()
        ?: 'User'
    );


/*
|--------------------------------------------------------------------------
| LABEL
|--------------------------------------------------------------------------
*/

$label =
    disappearingLabel(
        $seconds
    );


/*
|--------------------------------------------------------------------------
| SYSTEM MESSAGE
|--------------------------------------------------------------------------
*/

if ($seconds === 0) {

    $systemText =
        $fullName
        . ' turned off disappearing messages.';

} else {

    $systemText =
        $fullName
        . ' set disappearing messages to '
        . $label
        . '.';
}


/*
|--------------------------------------------------------------------------
| SAVE
|--------------------------------------------------------------------------
*/

try {

    $conn->beginTransaction();


    $update =
        $conn->prepare("
            UPDATE chat_conversations
            SET
                disappearing_seconds = ?,
                updated_at = NOW()
            WHERE id = ?
        ");


    $update->execute([

        $seconds === 0
            ? null
            : $seconds,

        $conversationId

    ]);


    /*
    |--------------------------------------------------------------------------
    | SYSTEM EVENT MESSAGE
    |--------------------------------------------------------------------------
    */

    $insert =
        $conn->prepare("
            INSERT INTO chat_messages (
                conversation_id,
                sender_id,
                message,
                message_type,
                is_view_once
            )
            VALUES (
                ?,
                ?,
                ?,
                'system',
                0
            )
        ");


    $insert->execute([

        $conversationId,

        $userId,

        $systemText

    ]);


    $conn->commit();


} catch (Throwable $e) {

    if ($conn->inTransaction()) {
        $conn->rollBack();
    }


    chatJson([
        'ok' => false,
        'message' =>
            'Could not update disappearing messages.'
    ], 500);
}


chatJson([
    'ok' => true,

    'message' =>
        'Disappearing messages updated.',

    'disappearing_seconds' =>
        $seconds,

    'label' =>
        $label
]);


/*
|--------------------------------------------------------------------------
| LABEL FUNCTION
|--------------------------------------------------------------------------
*/

function disappearingLabel(
    int $seconds
): string {

    if ($seconds <= 0) {
        return 'Off';
    }


    if ($seconds === 3600) {
        return '1 Hour';
    }


    if ($seconds === 86400) {
        return '24 Hours';
    }


    if ($seconds === 604800) {
        return '7 Days';
    }


    if ($seconds === 2592000) {
        return '30 Days';
    }


    if (
        $seconds % 86400 === 0
    ) {

        $days =
            (int)(
                $seconds / 86400
            );

        return
            $days
            . (
                $days === 1
                    ? ' Day'
                    : ' Days'
            );
    }


    if (
        $seconds % 3600 === 0
    ) {

        $hours =
            (int)(
                $seconds / 3600
            );

        return
            $hours
            . (
                $hours === 1
                    ? ' Hour'
                    : ' Hours'
            );
    }


    if (
        $seconds % 60 === 0
    ) {

        $minutes =
            (int)(
                $seconds / 60
            );

        return
            $minutes
            . (
                $minutes === 1
                    ? ' Minute'
                    : ' Minutes'
            );
    }


    return
        $seconds
        . ' Seconds';
}