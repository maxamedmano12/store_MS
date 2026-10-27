<?php

require_once __DIR__ . '/_bootstrap.php';


/*
|--------------------------------------------------------------------------
| POST only
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
| CSRF protection
|--------------------------------------------------------------------------
*/

if (!chatCsrfValid()) {

    chatJson([
        'ok' => false,
        'message' => 'Invalid CSRF token.'
    ], 419);
}


/*
|--------------------------------------------------------------------------
| Current user
|--------------------------------------------------------------------------
*/

$currentUserId = chatUserId();


/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$conversationId =
    (int)($_POST['conversation_id'] ?? 0);

$isTyping =
    (int)($_POST['is_typing'] ?? 0);


/*
|--------------------------------------------------------------------------
| Validate conversation if provided
|--------------------------------------------------------------------------
*/

if ($conversationId > 0) {

    if (
        !chatCanAccessConversation(
            $conn,
            $conversationId
        )
    ) {

        chatJson([
            'ok' => false,
            'message' => 'You cannot access this conversation.'
        ], 403);
    }
}


/*
|--------------------------------------------------------------------------
| Typing values
|--------------------------------------------------------------------------
*/

$typingConversationId = null;
$typingUntil = null;


if (
    $conversationId > 0 &&
    $isTyping === 1
) {

    $typingConversationId =
        $conversationId;

    $typingUntil =
        date(
            'Y-m-d H:i:s',
            time() + 4
        );
}


/*
|--------------------------------------------------------------------------
| Check if presence row exists
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT user_id

    FROM chat_presence

    WHERE user_id = ?

    LIMIT 1
");

$stmt->execute([
    $currentUserId
]);

$exists =
    (bool)$stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| Update presence
|--------------------------------------------------------------------------
*/

if ($exists) {

    $stmt = $conn->prepare("
        UPDATE chat_presence

        SET
            last_ping = NOW(),
            last_seen = NOW(),
            typing_conversation_id = ?,
            typing_until = ?

        WHERE user_id = ?
    ");

    $stmt->execute([
        $typingConversationId,
        $typingUntil,
        $currentUserId
    ]);

} else {

    $stmt = $conn->prepare("
        INSERT INTO chat_presence
        (
            user_id,
            last_ping,
            last_seen,
            typing_conversation_id,
            typing_until
        )

        VALUES
        (
            ?,
            NOW(),
            NOW(),
            ?,
            ?
        )
    ");

    $stmt->execute([
        $currentUserId,
        $typingConversationId,
        $typingUntil
    ]);
}


/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

chatJson([

    'ok' => true,

    'user_id' =>
        $currentUserId,

    'conversation_id' =>
        $typingConversationId,

    'is_typing' =>
        $typingConversationId !== null,

    'last_seen' =>
        date('Y-m-d H:i:s')

]);