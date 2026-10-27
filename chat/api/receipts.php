<?php

require_once __DIR__ . '/_bootstrap.php';


/*
|--------------------------------------------------------------------------
| METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    chatJson([
        'ok' => false,
        'message' => 'Method not allowed.'
    ], 405);
}


/*
|--------------------------------------------------------------------------
| CURRENT USER
|--------------------------------------------------------------------------
*/

$userId =
    (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Unauthorized.'
    ], 401);
}


/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$conversationId =
    (int)($_GET['conversation_id'] ?? 0);

if ($conversationId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Conversation is required.'
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
        'message' => 'Access denied.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| FETCH OWN MESSAGE RECEIPTS
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT
            id,
            delivered_at,
            read_at

        FROM chat_messages

        WHERE
            conversation_id = ?
            AND sender_id = ?
            AND is_deleted = 0

        ORDER BY id DESC

        LIMIT 200
    ");


$stmt->execute([
    $conversationId,
    $userId
]);


$rows =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| FORMAT
|--------------------------------------------------------------------------
*/

$receipts = [];


foreach ($rows as $row) {

    $status = 'sent';


    if (!empty($row['read_at'])) {

        $status = 'read';

    } elseif (!empty($row['delivered_at'])) {

        $status = 'delivered';
    }


    $receipts[] = [

        'id' =>
            (int)$row['id'],

        'status' =>
            $status,

        'delivered_at' =>
            $row['delivered_at'],

        'read_at' =>
            $row['read_at']
    ];
}


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

chatJson([
    'ok' => true,

    'conversation_id' =>
        $conversationId,

    'receipts' =>
        $receipts
]);