<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    chatJson([
        'success' => false,
        'message' => 'Method not allowed.'
    ], 405);
}

$userId = chatUserId();

try {
    $result = [
        'success' => true,
        'messages' => [],
        'calls' => [],
        'server_time' => date('Y-m-d H:i:s')
    ];

    /* =========================================================
       NEW UNREAD MESSAGES
       ========================================================= */

    $sqlMessages = "
        SELECT
            m.id,
            m.conversation_id,
            m.sender_id,
            m.message,
            m.message_type,
            m.created_at,

            u.full_name AS sender_name,
            u.profile_picture,

            cm.last_read_message_id

        FROM chat_messages m

        INNER JOIN chat_members cm
            ON cm.conversation_id = m.conversation_id
            AND cm.user_id = :user_id

        INNER JOIN users u
            ON u.id = m.sender_id

        WHERE
            m.sender_id <> :user_id2
            AND m.is_deleted = 0

            AND (
                m.expires_at IS NULL
                OR m.expires_at > NOW()
            )

            AND (
                cm.last_read_message_id IS NULL
                OR m.id > cm.last_read_message_id
            )

        ORDER BY m.id DESC

        LIMIT 20
    ";

    $stmtMessages = $conn->prepare($sqlMessages);

    $stmtMessages->execute([
        ':user_id' => $userId,
        ':user_id2' => $userId
    ]);

    $messages = $stmtMessages->fetchAll(PDO::FETCH_ASSOC);

    foreach ($messages as $message) {

        $preview = '';

        switch ($message['message_type']) {

            case 'image':
                $preview = '📷 Sent you a photo';
                break;

            case 'video':
                $preview = '🎥 Sent you a video';
                break;

            case 'voice':
                $preview = '🎤 Sent you a voice message';
                break;

            case 'file':
                $preview = '📎 Sent you a file';
                break;

            case 'system':
                $preview = 'New chat update';
                break;

            default:

                $text = trim((string)($message['message'] ?? ''));

                if ($text === '') {
                    $preview = 'New message';
                } else {

                    if (mb_strlen($text) > 80) {
                        $text = mb_substr($text, 0, 80) . '...';
                    }

                    $preview = $text;
                }

                break;
        }

        $result['messages'][] = [
            'id' => (int)$message['id'],
            'conversation_id' => (int)$message['conversation_id'],
            'sender_id' => (int)$message['sender_id'],
            'sender_name' => (string)$message['sender_name'],
            'profile_picture' => $message['profile_picture'],
            'message_type' => (string)$message['message_type'],
            'preview' => $preview,
            'created_at' => (string)$message['created_at']
        ];
    }


    /* =========================================================
       INCOMING CALLS
       ========================================================= */

    $sqlCalls = "
        SELECT
            c.id,
            c.conversation_id,
            c.caller_id,
            c.receiver_id,
            c.call_type,
            c.status,
            c.started_at,

            u.full_name AS caller_name,
            u.profile_picture

        FROM chat_calls c

        INNER JOIN users u
            ON u.id = c.caller_id

        WHERE
            c.receiver_id = :receiver_id
            AND c.status = 'ringing'
            AND c.started_at >= DATE_SUB(NOW(), INTERVAL 45 SECOND)

        ORDER BY c.id DESC

        LIMIT 5
    ";

    $stmtCalls = $conn->prepare($sqlCalls);

    $stmtCalls->execute([
        ':receiver_id' => $userId
    ]);

    $calls = $stmtCalls->fetchAll(PDO::FETCH_ASSOC);

    foreach ($calls as $call) {

        $callType = $call['call_type'] === 'video'
            ? 'video'
            : 'voice';

        $result['calls'][] = [
            'id' => (int)$call['id'],
            'conversation_id' => (int)$call['conversation_id'],
            'caller_id' => (int)$call['caller_id'],
            'caller_name' => (string)$call['caller_name'],
            'profile_picture' => $call['profile_picture'],
            'call_type' => $callType,
            'status' => (string)$call['status'],
            'started_at' => (string)$call['started_at']
        ];
    }


    chatJson($result);

} catch (Throwable $e) {

    chatJson([
        'success' => false,
        'message' => 'Unable to load notifications.',
        'error' => $e->getMessage()
    ], 500);
}