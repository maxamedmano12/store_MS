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

$currentUserId =
    (int)($_SESSION['user_id'] ?? 0);

$currentBusinessId =
    isset($_SESSION['business_id'])
        ? (int)$_SESSION['business_id']
        : null;

$currentBranchId =
    isset($_SESSION['branch_id'])
        ? (int)$_SESSION['branch_id']
        : null;

$currentRole =
    (string)($_SESSION['role'] ?? '');


if ($currentUserId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Unauthorized.'
    ], 401);
}


/*
|--------------------------------------------------------------------------
| BASE QUERY
|--------------------------------------------------------------------------
*/

$sql = "
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

    WHERE
        u.id <> ?
        AND u.status = 'Active'
";


$params = [
    $currentUserId
];


/*
|--------------------------------------------------------------------------
| SUPER ADMIN
|--------------------------------------------------------------------------
|
| Super Admin wuxuu arkaa dhammaan active users.
|--------------------------------------------------------------------------
*/

if ($currentRole === 'Super Admin') {

    /*
     * No additional tenant filter.
     */


/*
|--------------------------------------------------------------------------
| BUSINESS ADMIN
|--------------------------------------------------------------------------
|
| Business Admin:
| - Super Admin
| - dhammaan users-ka business-kiisa
|--------------------------------------------------------------------------
*/

} elseif ($currentRole === 'Business Admin') {

    $sql .= "
        AND (
            r.role_name = 'Super Admin'
            OR u.business_id = ?
        )
    ";

    $params[] =
        $currentBusinessId;


/*
|--------------------------------------------------------------------------
| NORMAL USER
|--------------------------------------------------------------------------
|
| Normal User:
| - Super Admin
| - Business Admin business-kiisa
| - normal users isla branch-ka
|--------------------------------------------------------------------------
*/

} else {

    $sql .= "
        AND (
            r.role_name = 'Super Admin'

            OR (
                r.role_name = 'Business Admin'
                AND u.business_id = ?
            )

            OR (
                u.business_id = ?
                AND u.branch_id = ?
                AND r.role_name <> 'Business Admin'
                AND r.role_name <> 'Super Admin'
            )
        )
    ";

    $params[] =
        $currentBusinessId;

    $params[] =
        $currentBusinessId;

    $params[] =
        $currentBranchId;
}


/*
|--------------------------------------------------------------------------
| SORT
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY
        CASE
            WHEN r.role_name = 'Super Admin' THEN 1
            WHEN r.role_name = 'Business Admin' THEN 2
            ELSE 3
        END,

        b.business_name ASC,
        br.branch_name ASC,
        u.full_name ASC
";


$stmt =
    $conn->prepare($sql);

$stmt->execute($params);

$rows =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| FORMAT
|--------------------------------------------------------------------------
*/

$contacts = [];


foreach ($rows as $row) {

    $targetUserId =
        (int)$row['id'];


    /*
    |--------------------------------------------------------------------------
    | FINAL ACCESS CHECK
    |--------------------------------------------------------------------------
    |
    | Database query sax yahay, laakiin helper-kan mar kale ayuu xaqiijinayaa.
    |--------------------------------------------------------------------------
    */

    if (!chatCanContact($targetUserId)) {
        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | ONLINE
    |--------------------------------------------------------------------------
    */

    $isOnline = false;

    if (!empty($row['last_ping'])) {

        $lastPingTime =
            strtotime(
                (string)$row['last_ping']
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
    | PROFILE URL
    |--------------------------------------------------------------------------
    */

    $profileUrl = null;

    if (
        !empty(
            $row['profile_picture']
        )
    ) {

        $profileUrl =
            '../chat/api/profile-picture.php?user_id='
            . $targetUserId;
    }


    /*
    |--------------------------------------------------------------------------
    | LAST SEEN LABEL
    |--------------------------------------------------------------------------
    */

    $lastSeenText = null;

    if ($isOnline) {

        $lastSeenText =
            'Online';

    } elseif (!empty($row['last_seen'])) {

        $lastSeenTime =
            strtotime(
                (string)$row['last_seen']
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

                $lastSeenText =
                    'Last seen today at '
                    . date(
                        'g:i A',
                        $lastSeenTime
                    );

            } elseif ($seenDate === $yesterday) {

                $lastSeenText =
                    'Last seen yesterday at '
                    . date(
                        'g:i A',
                        $lastSeenTime
                    );

            } else {

                $lastSeenText =
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
    | EXISTING DIRECT CONVERSATION
    |--------------------------------------------------------------------------
    */

    $conversationId = null;


    $conversationStmt =
        $conn->prepare("
            SELECT
                c.id
            FROM chat_conversations c

            INNER JOIN chat_members cm1
                ON cm1.conversation_id = c.id
                AND cm1.user_id = ?

            INNER JOIN chat_members cm2
                ON cm2.conversation_id = c.id
                AND cm2.user_id = ?

            WHERE
                c.conversation_type IN ('direct', 'support')

                AND (
                    SELECT COUNT(*)
                    FROM chat_members cm_count
                    WHERE cm_count.conversation_id = c.id
                ) = 2

            ORDER BY c.updated_at DESC

            LIMIT 1
        ");


    $conversationStmt->execute([
        $currentUserId,
        $targetUserId
    ]);


    $existingConversation =
        $conversationStmt->fetchColumn();


    if ($existingConversation !== false) {

        $conversationId =
            (int)$existingConversation;
    }


    /*
    |--------------------------------------------------------------------------
    | LAST MESSAGE
    |--------------------------------------------------------------------------
    */

    $lastMessage = null;
    $lastMessageTime = null;
    $unreadCount = 0;


    if ($conversationId !== null) {

        /*
        |--------------------------------------------------------------------------
        | LAST MESSAGE QUERY
        |--------------------------------------------------------------------------
        */

        $lastMessageStmt =
            $conn->prepare("
                SELECT
                    id,
                    sender_id,
                    message,
                    message_type,
                    is_view_once,
                    viewed_at,
                    created_at
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


        $lastMessageStmt->execute([
            $conversationId
        ]);


        $lastMessageRow =
            $lastMessageStmt->fetch(PDO::FETCH_ASSOC);


        if ($lastMessageRow) {

            $lastMessageTime =
                $lastMessageRow['created_at'];


            $lastMessageType =
                (string)$lastMessageRow['message_type'];


            $lastMessageText =
                trim(
                    (string)(
                        $lastMessageRow['message']
                        ?? ''
                    )
                );


            if ($lastMessageText === '') {

                $lastMessageText =
                    match ($lastMessageType) {

                        'image' =>
                            '📷 Photo',

                        'video' =>
                            '🎬 Video',

                        'voice' =>
                            '🎤 Voice message',

                        'file' =>
                            '📎 File',

                        'system' =>
                            'System message',

                        default =>
                            'Message'
                    };
            }


            if (
                (int)$lastMessageRow['is_view_once'] === 1
            ) {

                if (
                    !empty(
                        $lastMessageRow['viewed_at']
                    )
                ) {

                    $lastMessageText =
                        '👁 Opened';

                } else {

                    $lastMessageText =
                        match ($lastMessageType) {

                            'image' =>
                                '👁 View Once photo',

                            'video' =>
                                '👁 View Once video',

                            'voice' =>
                                '🎤 Listen Once',

                            default =>
                                $lastMessageText
                        };
                }
            }


            if (
                (int)$lastMessageRow['sender_id']
                === $currentUserId
            ) {

                $lastMessage =
                    'You: '
                    . $lastMessageText;

            } else {

                $lastMessage =
                    $lastMessageText;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | UNREAD COUNT
        |--------------------------------------------------------------------------
        */

        $unreadStmt =
            $conn->prepare("
                SELECT COUNT(*)

                FROM chat_messages

                WHERE
                    conversation_id = ?
                    AND sender_id <> ?
                    AND read_at IS NULL
                    AND is_deleted = 0
                    AND (
                        expires_at IS NULL
                        OR expires_at > NOW()
                    )
            ");


        $unreadStmt->execute([
            $conversationId,
            $currentUserId
        ]);


        $unreadCount =
            (int)$unreadStmt->fetchColumn();
    }


    /*
    |--------------------------------------------------------------------------
    | CONTACT
    |--------------------------------------------------------------------------
    */

    $contacts[] = [

        'id' =>
            $targetUserId,

        'full_name' =>
            (string)$row['full_name'],

        'username' =>
            (string)$row['username'],

        'role' =>
            (string)$row['role_name'],

        'business_id' =>
            $row['business_id'] !== null
                ? (int)$row['business_id']
                : null,

        'business_name' =>
            $row['business_name'],

        'branch_id' =>
            $row['branch_id'] !== null
                ? (int)$row['branch_id']
                : null,

        'branch_name' =>
            $row['branch_name'],

        'profile_url' =>
            $profileUrl,

        'is_online' =>
            $isOnline ? 1 : 0,

        'last_seen' =>
            $row['last_seen'],

        'last_seen_text' =>
            $lastSeenText,

        'conversation_id' =>
            $conversationId,

        'last_message' =>
            $lastMessage,

        'last_message_at' =>
            $lastMessageTime,

        'unread_count' =>
            $unreadCount
    ];
}


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

chatJson([
    'ok' => true,

    'current_user' => [

        'id' =>
            $currentUserId,

        'role' =>
            $currentRole,

        'business_id' =>
            $currentBusinessId,

        'branch_id' =>
            $currentBranchId

    ],

    'contacts' =>
        $contacts
]);