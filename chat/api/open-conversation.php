<?php

require_once __DIR__ . '/_bootstrap.php';


/*
|--------------------------------------------------------------------------
| METHOD
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
| CURRENT USER
|--------------------------------------------------------------------------
*/

$currentUserId =
    (int)($_SESSION['user_id'] ?? 0);

if ($currentUserId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Unauthorized.'
    ], 401);
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrf =
    (string)($_POST['csrf_token'] ?? '');

$sessionCsrf =
    (string)($_SESSION['csrf_token'] ?? '');

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
        'message' => 'Invalid CSRF token.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| TARGET USER
|--------------------------------------------------------------------------
*/

$targetUserId =
    (int)($_POST['target_user_id'] ?? 0);

if ($targetUserId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Target user is required.'
    ], 422);
}


if ($targetUserId === $currentUserId) {

    chatJson([
        'ok' => false,
        'message' => 'You cannot chat with yourself.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| CONTACT PERMISSION
|--------------------------------------------------------------------------
*/

if (!chatCanContact($targetUserId)) {

    chatJson([
        'ok' => false,
        'message' => 'You are not allowed to contact this user.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| TARGET USER INFO
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.username,
            u.business_id,
            u.branch_id,
            u.profile_picture,
            u.status,

            r.role_name,

            b.business_name,

            br.branch_name,

            cp.last_ping,
            cp.last_seen

        FROM users u

        INNER JOIN roles r
            ON r.id = u.role_id

        LEFT JOIN businesses b
            ON b.id = u.business_id

        LEFT JOIN branches br
            ON br.id = u.branch_id

        LEFT JOIN chat_presence cp
            ON cp.user_id = u.id

        WHERE u.id = ?
          AND u.status = 'Active'

        LIMIT 1
    ");


$stmt->execute([
    $targetUserId
]);


$target =
    $stmt->fetch(PDO::FETCH_ASSOC);


if (!$target) {

    chatJson([
        'ok' => false,
        'message' => 'User not found or inactive.'
    ], 404);
}


/*
|--------------------------------------------------------------------------
| FIND OR CREATE DIRECT CHAT
|--------------------------------------------------------------------------
*/

try {

    $conversationId =
        chatFindOrCreateDirect(
            $targetUserId
        );

} catch (Throwable $e) {

    chatJson([
        'ok' => false,
        'message' => 'Could not open conversation.'
    ], 500);
}


if ($conversationId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Conversation could not be created.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| ONLINE STATUS
|--------------------------------------------------------------------------
*/

$isOnline = false;

if (!empty($target['last_ping'])) {

    $lastPingTime =
        strtotime(
            (string)$target['last_ping']
        );

    if (
        $lastPingTime !== false
        && $lastPingTime >= time() - 35
    ) {

        $isOnline = true;
    }
}


/*
|--------------------------------------------------------------------------
| LAST SEEN TEXT
|--------------------------------------------------------------------------
*/

$statusText = 'Offline';


if ($isOnline) {

    $statusText = 'Online';

} elseif (!empty($target['last_seen'])) {

    $lastSeenTime =
        strtotime(
            (string)$target['last_seen']
        );


    if ($lastSeenTime !== false) {

        $today =
            date('Y-m-d');

        $yesterday =
            date(
                'Y-m-d',
                strtotime('-1 day')
            );

        $seenDate =
            date(
                'Y-m-d',
                $lastSeenTime
            );


        if ($seenDate === $today) {

            $statusText =
                'Last seen today at '
                . date(
                    'g:i A',
                    $lastSeenTime
                );

        } elseif ($seenDate === $yesterday) {

            $statusText =
                'Last seen yesterday at '
                . date(
                    'g:i A',
                    $lastSeenTime
                );

        } else {

            $statusText =
                'Last seen '
                . date(
                    'M j, Y g:i A',
                    $lastSeenTime
                );
        }
    }
}


/*
|--------------------------------------------------------------------------
| PROFILE URL
|--------------------------------------------------------------------------
*/

$profileUrl = null;

if (
    !empty(
        $target['profile_picture']
    )
) {

    $profileUrl =
        '../chat/api/profile-picture.php?user_id='
        . $targetUserId;
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
    $disappearingSeconds !== false
    && $disappearingSeconds !== null
        ? (int)$disappearingSeconds
        : 0;


/*
|--------------------------------------------------------------------------
| LAST MESSAGE
|--------------------------------------------------------------------------
*/

$lastMessageId = 0;


$lastStmt =
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


$lastStmt->execute([
    $conversationId
]);


$lastMessage =
    $lastStmt->fetchColumn();


if ($lastMessage !== false) {

    $lastMessageId =
        (int)$lastMessage;
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

    'last_message_id' =>
        $lastMessageId,

    'disappearing_seconds' =>
        $disappearingSeconds,

    'user' => [

        'id' =>
            (int)$target['id'],

        'full_name' =>
            (string)$target['full_name'],

        'username' =>
            (string)$target['username'],

        'role' =>
            (string)$target['role_name'],

        'business_id' =>
            $target['business_id'] !== null
                ? (int)$target['business_id']
                : null,

        'business_name' =>
            $target['business_name'],

        'branch_id' =>
            $target['branch_id'] !== null
                ? (int)$target['branch_id']
                : null,

        'branch_name' =>
            $target['branch_name'],

        'profile_url' =>
            $profileUrl,

        'is_online' =>
            $isOnline ? 1 : 0,

        'last_seen' =>
            $target['last_seen'],

        'status_text' =>
            $statusText
    ]
]);