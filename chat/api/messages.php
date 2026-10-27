<?php

require_once __DIR__ . '/_bootstrap.php';


/*
|--------------------------------------------------------------------------
| GET ONLY
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

$userId = chatUserId();


/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$conversationId =
    (int)($_GET['conversation_id'] ?? 0);

$afterId =
    (int)($_GET['after_id'] ?? 0);


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

if (!chatCanAccessConversation($conversationId)) {

    chatJson([
        'ok' => false,
        'message' => 'Access denied.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| MARK OTHER USER MESSAGES AS DELIVERED / READ
|--------------------------------------------------------------------------
*/

try {

    $updateStmt =
        $conn->prepare("
            UPDATE chat_messages

            SET
                delivered_at =
                    COALESCE(delivered_at, NOW()),

                read_at =
                    COALESCE(read_at, NOW())

            WHERE
                conversation_id = ?
                AND sender_id <> ?
                AND is_deleted = 0
                AND (
                    expires_at IS NULL
                    OR expires_at > NOW()
                )
        ");

    $updateStmt->execute([
        $conversationId,
        $userId
    ]);

} catch (Throwable $e) {
    // Receipt error should not stop chat.
}


/*
|--------------------------------------------------------------------------
| FETCH MESSAGES
|--------------------------------------------------------------------------
|
| after_id > 0
|   -> only newer messages
|
| after_id = 0
|   -> latest 100 messages
|
*/

if ($afterId > 0) {

    /*
    |--------------------------------------------------------------------------
    | POLLING — NEW MESSAGES ONLY
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            m.id,
            m.conversation_id,
            m.sender_id,
            m.reply_to_id,
            m.message,
            m.message_type,
            m.attachment_name,
            m.attachment_mime,
            m.is_view_once,
            m.viewed_at,
            m.delivered_at,
            m.read_at,
            m.expires_at,
            m.is_deleted,
            m.created_at,

            u.full_name AS sender_name,
            u.profile_picture AS sender_profile_picture,

            rm.id AS reply_id,
            rm.sender_id AS reply_sender_id,
            rm.message AS reply_message,
            rm.message_type AS reply_message_type,

            ru.full_name AS reply_sender_name

        FROM chat_messages m

        INNER JOIN users u
            ON u.id = m.sender_id

        LEFT JOIN chat_messages rm
            ON rm.id = m.reply_to_id

        LEFT JOIN users ru
            ON ru.id = rm.sender_id

        WHERE
            m.conversation_id = ?
            AND m.id > ?
            AND m.is_deleted = 0
            AND (
                m.expires_at IS NULL
                OR m.expires_at > NOW()
            )

        ORDER BY m.id ASC

        LIMIT 200
    ";

    $params = [
        $conversationId,
        $afterId
    ];

} else {

    /*
    |--------------------------------------------------------------------------
    | INITIAL LOAD — LATEST 100
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT *

        FROM (

            SELECT
                m.id,
                m.conversation_id,
                m.sender_id,
                m.reply_to_id,
                m.message,
                m.message_type,
                m.attachment_name,
                m.attachment_mime,
                m.is_view_once,
                m.viewed_at,
                m.delivered_at,
                m.read_at,
                m.expires_at,
                m.is_deleted,
                m.created_at,

                u.full_name AS sender_name,
                u.profile_picture AS sender_profile_picture,

                rm.id AS reply_id,
                rm.sender_id AS reply_sender_id,
                rm.message AS reply_message,
                rm.message_type AS reply_message_type,

                ru.full_name AS reply_sender_name

            FROM chat_messages m

            INNER JOIN users u
                ON u.id = m.sender_id

            LEFT JOIN chat_messages rm
                ON rm.id = m.reply_to_id

            LEFT JOIN users ru
                ON ru.id = rm.sender_id

            WHERE
                m.conversation_id = ?
                AND m.is_deleted = 0
                AND (
                    m.expires_at IS NULL
                    OR m.expires_at > NOW()
                )

            ORDER BY m.id DESC

            LIMIT 100

        ) AS latest_messages

        ORDER BY id ASC
    ";

    $params = [
        $conversationId
    ];
}


/*
|--------------------------------------------------------------------------
| EXECUTE
|--------------------------------------------------------------------------
*/

try {

    $stmt =
        $conn->prepare($sql);

    $stmt->execute($params);

    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

} catch (Throwable $e) {

    chatJson([
        'ok' => false,
        'message' => 'Could not load messages.',
        'error' => $e->getMessage()
    ], 500);
}


/*
|--------------------------------------------------------------------------
| FORMAT
|--------------------------------------------------------------------------
*/

$messages = [];


foreach ($rows as $row) {

    $messageId =
        (int)$row['id'];

    $senderId =
        (int)$row['sender_id'];

    $isMine =
        $senderId === $userId;


    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    $status = 'sent';

    if (!empty($row['read_at'])) {

        $status = 'read';

    } elseif (!empty($row['delivered_at'])) {

        $status = 'delivered';
    }


    /*
    |--------------------------------------------------------------------------
    | VIEW ONCE
    |--------------------------------------------------------------------------
    */

    $isViewOnce =
        (int)$row['is_view_once'] === 1;

    $hasBeenViewed =
        !empty($row['viewed_at']);


    /*
    |--------------------------------------------------------------------------
    | MEDIA URL
    |--------------------------------------------------------------------------
    */

    $mediaUrl = null;

    $messageType =
        (string)$row['message_type'];


    if (
        in_array(
            $messageType,
            [
                'image',
                'video',
                'voice',
                'file'
            ],
            true
        )
    ) {

        if ($isViewOnce) {

            /*
            | Recipient can open only if not opened yet.
            */

            if (
                !$isMine
                &&
                !$hasBeenViewed
            ) {

                $mediaUrl =
                    '../chat/api/media.php?message_id='
                    . $messageId;
            }

        } else {

            $mediaUrl =
                '../chat/api/media.php?message_id='
                . $messageId;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | PROFILE PICTURE
    |--------------------------------------------------------------------------
    */

    $senderProfileUrl = null;

    if (
        !empty(
            $row['sender_profile_picture']
        )
    ) {

        $senderProfileUrl =
            '../chat/api/profile-picture.php?user_id='
            . $senderId;
    }


    /*
    |--------------------------------------------------------------------------
    | REPLY
    |--------------------------------------------------------------------------
    */

    $reply = null;

    if (!empty($row['reply_id'])) {

        $replyText =
            trim(
                (string)(
                    $row['reply_message']
                    ?? ''
                )
            );

        $replyType =
            (string)(
                $row['reply_message_type']
                ?? 'text'
            );


        if ($replyText === '') {

            switch ($replyType) {

                case 'image':
                    $replyText = '📷 Photo';
                    break;

                case 'video':
                    $replyText = '🎬 Video';
                    break;

                case 'voice':
                    $replyText = '🎤 Voice message';
                    break;

                case 'file':
                    $replyText = '📎 File';
                    break;

                case 'system':
                    $replyText = 'System message';
                    break;

                default:
                    $replyText = '';
                    break;
            }
        }


        $reply = [

            'id' =>
                (int)$row['reply_id'],

            'sender_id' =>
                (int)(
                    $row['reply_sender_id']
                    ?? 0
                ),

            'sender_name' =>
                (string)(
                    $row['reply_sender_name']
                    ?? 'User'
                ),

            'message' =>
                $replyText,

            'message_type' =>
                $replyType
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | VIEW ONCE STATE
    |--------------------------------------------------------------------------
    */

    $viewOnceState = null;

    if ($isViewOnce) {

        if ($isMine) {

            $viewOnceState =
                $hasBeenViewed
                    ? 'opened'
                    : 'sent';

        } else {

            $viewOnceState =
                $hasBeenViewed
                    ? 'opened'
                    : 'available';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | MESSAGE
    |--------------------------------------------------------------------------
    */

    $messages[] = [

        'id' =>
            $messageId,

        'conversation_id' =>
            (int)$row['conversation_id'],

        'sender_id' =>
            $senderId,

        'sender_name' =>
            (string)$row['sender_name'],

        'sender_profile_url' =>
            $senderProfileUrl,

        'is_mine' =>
            $isMine ? 1 : 0,

        'reply_to_id' =>
            !empty($row['reply_to_id'])
                ? (int)$row['reply_to_id']
                : null,

        'reply' =>
            $reply,

        'message' =>
            $row['message'],

        'message_type' =>
            $messageType,

        'attachment_name' =>
            $row['attachment_name'],

        'attachment_mime' =>
            $row['attachment_mime'],

        'media_url' =>
            $mediaUrl,

        'is_view_once' =>
            $isViewOnce ? 1 : 0,

        'viewed_at' =>
            $row['viewed_at'],

        'view_once_state' =>
            $viewOnceState,

        'delivered_at' =>
            $row['delivered_at'],

        'read_at' =>
            $row['read_at'],

        'status' =>
            $status,

        'expires_at' =>
            $row['expires_at'],

        'created_at' =>
            $row['created_at']
    ];
}


/*
|--------------------------------------------------------------------------
| ACTUAL LAST MESSAGE ID
|--------------------------------------------------------------------------
|
| Important:
| We get the latest ID from the database,
| not only from the current response.
|--------------------------------------------------------------------------
*/

$latestStmt =
    $conn->prepare("
        SELECT id

        FROM chat_messages

        WHERE
            conversation_id = ?
            AND is_deleted = 0
            AND (
                expires_at IS NULL
                OR expires_at > NOW()
            )

        ORDER BY id DESC

        LIMIT 1
    ");

$latestStmt->execute([
    $conversationId
]);

$latestMessageId =
    (int)(
        $latestStmt->fetchColumn()
        ?: 0
    );


/*
|--------------------------------------------------------------------------
| RESPONSE LAST ID
|--------------------------------------------------------------------------
*/

$responseLastMessageId =
    $afterId;


if (!empty($messages)) {

    $lastIndex =
        count($messages) - 1;

    $responseLastMessageId =
        (int)$messages[
            $lastIndex
        ]['id'];
}


if (
    $responseLastMessageId <= 0
    &&
    $latestMessageId > 0
) {

    $responseLastMessageId =
        $latestMessageId;
}


/*
|--------------------------------------------------------------------------
| UPDATE MEMBER LAST READ
|--------------------------------------------------------------------------
*/

if ($latestMessageId > 0) {

    try {

        $memberStmt =
            $conn->prepare("
                UPDATE chat_members

                SET last_read_message_id = ?

                WHERE
                    conversation_id = ?
                    AND user_id = ?
            ");

        $memberStmt->execute([
            $latestMessageId,
            $conversationId,
            $userId
        ]);

    } catch (Throwable $e) {
        // Don't stop chat.
    }
}


/*
|--------------------------------------------------------------------------
| RECEIPTS
|--------------------------------------------------------------------------
*/

$receiptStmt =
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
            AND (
                expires_at IS NULL
                OR expires_at > NOW()
            )

        ORDER BY id DESC

        LIMIT 200
    ");

$receiptStmt->execute([
    $conversationId,
    $userId
]);


$receiptRows =
    $receiptStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


$receipts = [];


foreach ($receiptRows as $receiptRow) {

    $receiptStatus =
        'sent';


    if (!empty($receiptRow['read_at'])) {

        $receiptStatus =
            'read';

    } elseif (!empty($receiptRow['delivered_at'])) {

        $receiptStatus =
            'delivered';
    }


    $receipts[] = [

        'id' =>
            (int)$receiptRow['id'],

        'status' =>
            $receiptStatus,

        'delivered_at' =>
            $receiptRow['delivered_at'],

        'read_at' =>
            $receiptRow['read_at']
    ];
}


/*
|--------------------------------------------------------------------------
| DISAPPEARING SETTING
|--------------------------------------------------------------------------
*/

$conversationStmt =
    $conn->prepare("
        SELECT
            disappearing_seconds

        FROM chat_conversations

        WHERE id = ?

        LIMIT 1
    ");

$conversationStmt->execute([
    $conversationId
]);


$disappearingSeconds =
    $conversationStmt->fetchColumn();


$disappearingSeconds =
    (
        $disappearingSeconds !== false
        &&
        $disappearingSeconds !== null
    )
        ? (int)$disappearingSeconds
        : 0;


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

chatJson([

    'ok' => true,

    'conversation_id' =>
        $conversationId,

    'messages' =>
        $messages,

    'receipts' =>
        $receipts,

    'last_message_id' =>
        $responseLastMessageId,

    'latest_message_id' =>
        $latestMessageId,

    'disappearing_seconds' =>
        $disappearingSeconds

]);