<?php

require_once __DIR__ . '/_bootstrap.php';

$userId = chatUserId();

if ($userId <= 0) {
    chatJson([
        'ok' => false,
        'message' => 'Unauthorized.'
    ], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function callGet(int $callId): ?array
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT
            c.*,
            caller.full_name AS caller_name,
            receiver.full_name AS receiver_name
        FROM chat_calls c
        INNER JOIN users caller
            ON caller.id = c.caller_id
        INNER JOIN users receiver
            ON receiver.id = c.receiver_id
        WHERE c.id = ?
        LIMIT 1
    ");

    $stmt->execute([$callId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}


function callCanAccess(array $call, int $userId): bool
{
    return
        (int)$call['caller_id'] === $userId
        ||
        (int)$call['receiver_id'] === $userId;
}


function callOtherUserId(
    array $call,
    int $userId
): int {

    if ((int)$call['caller_id'] === $userId) {
        return (int)$call['receiver_id'];
    }

    return (int)$call['caller_id'];
}



/*
|--------------------------------------------------------------------------
| AUTO MISSED CALLS
|--------------------------------------------------------------------------
|
| Any call that keeps ringing for 45 seconds without being answered
| is automatically marked as missed.
|
*/

function callExpireMissedCalls(?int $callId = null): void
{
    global $conn;

    if ($callId !== null && $callId > 0) {
        $stmt = $conn->prepare("
            UPDATE chat_calls
            SET
                status = 'missed',
                ended_at = NOW()
            WHERE id = ?
              AND status = 'ringing'
              AND started_at <= DATE_SUB(NOW(), INTERVAL 45 SECOND)
        ");
        $stmt->execute([$callId]);
        return;
    }

    $stmt = $conn->prepare("
        UPDATE chat_calls
        SET
            status = 'missed',
            ended_at = NOW()
        WHERE status = 'ringing'
          AND started_at <= DATE_SUB(NOW(), INTERVAL 45 SECOND)
    ");
    $stmt->execute();
}


/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
|
| poll
| incoming
| signals
| history
|
*/

if ($method === 'GET') {

    // Keep ringing calls clean even when nobody answers them.
    callExpireMissedCalls();

    $action =
        trim(
            (string)($_GET['action'] ?? 'incoming')
        );


    /*
    |--------------------------------------------------------------------------
    | INCOMING CALL
    |--------------------------------------------------------------------------
    */

    if ($action === 'incoming') {

        $stmt = $conn->prepare("
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

            WHERE c.receiver_id = ?
              AND c.status = 'ringing'

            ORDER BY c.id DESC

            LIMIT 1
        ");

        $stmt->execute([$userId]);

        $call =
            $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$call) {

            chatJson([
                'ok' => true,
                'call' => null
            ]);
        }


        $call['id'] =
            (int)$call['id'];

        $call['conversation_id'] =
            (int)$call['conversation_id'];

        $call['caller_id'] =
            (int)$call['caller_id'];

        $call['receiver_id'] =
            (int)$call['receiver_id'];


        chatJson([
            'ok' => true,
            'call' => $call
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | POLL CALL STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'poll') {

        $callId =
            (int)($_GET['call_id'] ?? 0);


        if ($callId <= 0) {

            chatJson([
                'ok' => false,
                'message' => 'Invalid call.'
            ], 422);
        }


        callExpireMissedCalls($callId);

        $call =
            callGet($callId);


        if (
            !$call
            ||
            !callCanAccess(
                $call,
                $userId
            )
        ) {

            chatJson([
                'ok' => false,
                'message' => 'Call not found.'
            ], 404);
        }


        chatJson([
            'ok' => true,
            'call' => $call
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | GET WEBRTC SIGNALS
    |--------------------------------------------------------------------------
    */

    if ($action === 'signals') {

        $callId =
            (int)($_GET['call_id'] ?? 0);

        $afterId =
            max(
                0,
                (int)($_GET['after_id'] ?? 0)
            );


        $call =
            callGet($callId);


        if (
            !$call
            ||
            !callCanAccess(
                $call,
                $userId
            )
        ) {

            chatJson([
                'ok' => false,
                'message' => 'Call not found.'
            ], 404);
        }


        $stmt = $conn->prepare("
            SELECT
                id,
                call_id,
                sender_id,
                signal_type,
                signal_data,
                created_at
            FROM chat_call_signals
            WHERE call_id = ?
              AND sender_id <> ?
              AND id > ?
            ORDER BY id ASC
            LIMIT 200
        ");

        $stmt->execute([
            $callId,
            $userId,
            $afterId
        ]);


        $signals =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach ($signals as &$signal) {

            $signal['id'] =
                (int)$signal['id'];

            $signal['call_id'] =
                (int)$signal['call_id'];

            $signal['sender_id'] =
                (int)$signal['sender_id'];
        }

        unset($signal);


        chatJson([
            'ok' => true,
            'signals' => $signals
        ]);
    }



    /*
    |--------------------------------------------------------------------------
    | CALL HISTORY
    |--------------------------------------------------------------------------
    */

    if ($action === 'history') {

        $conversationId =
            max(
                0,
                (int)($_GET['conversation_id'] ?? 0)
            );

        $limit =
            max(
                1,
                min(
                    100,
                    (int)($_GET['limit'] ?? 50)
                )
            );


        $sql = "
            SELECT
                c.id,
                c.conversation_id,
                c.caller_id,
                c.receiver_id,
                c.call_type,
                c.status,
                c.started_at,
                c.answered_at,
                c.ended_at,

                caller.full_name AS caller_name,
                receiver.full_name AS receiver_name,

                CASE
                    WHEN c.caller_id = :current_user
                        THEN c.receiver_id
                    ELSE c.caller_id
                END AS other_user_id,

                CASE
                    WHEN c.caller_id = :current_user2
                        THEN receiver.full_name
                    ELSE caller.full_name
                END AS other_user_name,

                CASE
                    WHEN c.caller_id = :current_user3
                        THEN 'outgoing'
                    ELSE 'incoming'
                END AS direction,

                CASE
                    WHEN c.answered_at IS NOT NULL
                     AND c.ended_at IS NOT NULL
                        THEN TIMESTAMPDIFF(
                            SECOND,
                            c.answered_at,
                            c.ended_at
                        )
                    ELSE 0
                END AS duration_seconds

            FROM chat_calls c

            INNER JOIN users caller
                ON caller.id = c.caller_id

            INNER JOIN users receiver
                ON receiver.id = c.receiver_id

            WHERE
                (
                    c.caller_id = :user_one
                    OR c.receiver_id = :user_two
                )
        ";


        $params = [
            ':current_user' => $userId,
            ':current_user2' => $userId,
            ':current_user3' => $userId,
            ':user_one' => $userId,
            ':user_two' => $userId
        ];


        if ($conversationId > 0) {

            if (!chatCanAccessConversation($conversationId)) {

                chatJson([
                    'ok' => false,
                    'message' =>
                        'You cannot access this conversation.'
                ], 403);
            }


            $sql .= "
                AND c.conversation_id = :conversation_id
            ";

            $params[':conversation_id'] =
                $conversationId;
        }


        $sql .= "
            ORDER BY c.id DESC
            LIMIT {$limit}
        ";


        $stmt =
            $conn->prepare($sql);

        $stmt->execute($params);


        $calls =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach ($calls as &$item) {

            $item['id'] =
                (int)$item['id'];

            $item['conversation_id'] =
                (int)$item['conversation_id'];

            $item['caller_id'] =
                (int)$item['caller_id'];

            $item['receiver_id'] =
                (int)$item['receiver_id'];

            $item['other_user_id'] =
                (int)$item['other_user_id'];

            $item['duration_seconds'] =
                (int)$item['duration_seconds'];

            $item['is_missed_for_me'] =
                (
                    $item['status'] === 'missed'
                    &&
                    $item['direction'] === 'incoming'
                );
        }

        unset($item);


        chatJson([
            'ok' => true,
            'calls' => $calls
        ]);
    }


    chatJson([
        'ok' => false,
        'message' => 'Invalid action.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($method === 'POST') {

    if (!chatCsrfValid()) {

        chatJson([
            'ok' => false,
            'message' => 'Invalid security token.'
        ], 419);
    }


    $action =
        trim(
            (string)($_POST['action'] ?? '')
        );


    /*
    |--------------------------------------------------------------------------
    | START CALL
    |--------------------------------------------------------------------------
    */

    if ($action === 'start') {

        callExpireMissedCalls();

        $conversationId =
            (int)(
                $_POST['conversation_id']
                ?? 0
            );

        $receiverId =
            (int)(
                $_POST['receiver_id']
                ?? 0
            );

        $callType =
            trim(
                (string)(
                    $_POST['call_type']
                    ?? ''
                )
            );


        if (
            $conversationId <= 0
            ||
            $receiverId <= 0
            ||
            !in_array(
                $callType,
                ['voice', 'video'],
                true
            )
        ) {

            chatJson([
                'ok' => false,
                'message' => 'Invalid call information.'
            ], 422);
        }


        if ($receiverId === $userId) {

            chatJson([
                'ok' => false,
                'message' => 'You cannot call yourself.'
            ], 422);
        }


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


        if (!chatCanContact($receiverId)) {

            chatJson([
                'ok' => false,
                'message' =>
                    'You cannot call this user.'
            ], 403);
        }


        /*
        | Make sure receiver belongs
        | to this conversation.
        */

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM chat_members
            WHERE conversation_id = ?
              AND user_id = ?
        ");

        $stmt->execute([
            $conversationId,
            $receiverId
        ]);


        if ((int)$stmt->fetchColumn() === 0) {

            chatJson([
                'ok' => false,
                'message' =>
                    'User is not in this conversation.'
            ], 403);
        }


        /*
        | Prevent duplicate active calls.
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM chat_calls
            WHERE
                (
                    caller_id = ?
                    OR receiver_id = ?
                    OR caller_id = ?
                    OR receiver_id = ?
                )
              AND status IN (
                    'ringing',
                    'accepted'
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([
            $userId,
            $userId,
            $receiverId,
            $receiverId
        ]);


        if ($stmt->fetchColumn()) {

            chatJson([
                'ok' => false,
                'message' =>
                    'One of the users is already in a call.'
            ], 409);
        }


        $stmt = $conn->prepare("
            INSERT INTO chat_calls (
                conversation_id,
                caller_id,
                receiver_id,
                call_type,
                status,
                started_at
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                'ringing',
                NOW()
            )
        ");

        $stmt->execute([
            $conversationId,
            $userId,
            $receiverId,
            $callType
        ]);


        $callId =
            (int)$conn->lastInsertId();


        chatJson([
            'ok' => true,
            'message' => 'Call started.',
            'call' => callGet($callId)
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | ACCEPT CALL
    |--------------------------------------------------------------------------
    */

    if ($action === 'accept') {

        $callId =
            (int)(
                $_POST['call_id']
                ?? 0
            );


        $call =
            callGet($callId);


        if (!$call) {

            chatJson([
                'ok' => false,
                'message' => 'Call not found.'
            ], 404);
        }


        if (
            (int)$call['receiver_id']
            !== $userId
        ) {

            chatJson([
                'ok' => false,
                'message' =>
                    'You cannot accept this call.'
            ], 403);
        }


        if (
            $call['status']
            !== 'ringing'
        ) {

            chatJson([
                'ok' => false,
                'message' =>
                    'This call is no longer ringing.'
            ], 409);
        }


        $stmt = $conn->prepare("
            UPDATE chat_calls
            SET
                status = 'accepted',
                answered_at = NOW()
            WHERE id = ?
              AND status = 'ringing'
        ");

        $stmt->execute([$callId]);


        chatJson([
            'ok' => true,
            'message' => 'Call accepted.',
            'call' => callGet($callId)
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | DECLINE CALL
    |--------------------------------------------------------------------------
    */

    if ($action === 'decline') {

        $callId =
            (int)(
                $_POST['call_id']
                ?? 0
            );


        $call =
            callGet($callId);


        if (
            !$call
            ||
            (int)$call['receiver_id']
                !== $userId
        ) {

            chatJson([
                'ok' => false,
                'message' =>
                    'You cannot decline this call.'
            ], 403);
        }


        $stmt = $conn->prepare("
            UPDATE chat_calls
            SET
                status = 'declined',
                ended_at = NOW()
            WHERE id = ?
              AND status = 'ringing'
        ");

        $stmt->execute([$callId]);


        chatJson([
            'ok' => true,
            'message' => 'Call declined.'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CANCEL CALL
    |--------------------------------------------------------------------------
    */

    if ($action === 'cancel') {

        $callId =
            (int)(
                $_POST['call_id']
                ?? 0
            );


        $call =
            callGet($callId);


        if (
            !$call
            ||
            (int)$call['caller_id']
                !== $userId
        ) {

            chatJson([
                'ok' => false,
                'message' =>
                    'You cannot cancel this call.'
            ], 403);
        }


        $stmt = $conn->prepare("
            UPDATE chat_calls
            SET
                status = 'cancelled',
                ended_at = NOW()
            WHERE id = ?
              AND status = 'ringing'
        ");

        $stmt->execute([$callId]);


        chatJson([
            'ok' => true,
            'message' => 'Call cancelled.'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | END CALL
    |--------------------------------------------------------------------------
    */

    if ($action === 'end') {

        $callId =
            (int)(
                $_POST['call_id']
                ?? 0
            );


        $call =
            callGet($callId);


        if (
            !$call
            ||
            !callCanAccess(
                $call,
                $userId
            )
        ) {

            chatJson([
                'ok' => false,
                'message' =>
                    'You cannot end this call.'
            ], 403);
        }


        if (
            !in_array(
                $call['status'],
                ['ringing', 'accepted'],
                true
            )
        ) {

            chatJson([
                'ok' => true,
                'message' =>
                    'Call already finished.'
            ]);
        }


        $newStatus =
            $call['status'] === 'ringing'
                ? 'cancelled'
                : 'ended';


        $stmt = $conn->prepare("
            UPDATE chat_calls
            SET
                status = ?,
                ended_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $newStatus,
            $callId
        ]);


        chatJson([
            'ok' => true,
            'message' => 'Call ended.'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SEND WEBRTC SIGNAL
    |--------------------------------------------------------------------------
    */

    if ($action === 'signal') {

        $callId =
            (int)(
                $_POST['call_id']
                ?? 0
            );

        $signalType =
            trim(
                (string)(
                    $_POST['signal_type']
                    ?? ''
                )
            );

        $signalData =
            (string)(
                $_POST['signal_data']
                ?? ''
            );


        if (
            $callId <= 0
            ||
            !in_array(
                $signalType,
                [
                    'offer',
                    'answer',
                    'ice'
                ],
                true
            )
            ||
            $signalData === ''
        ) {

            chatJson([
                'ok' => false,
                'message' =>
                    'Invalid call signal.'
            ], 422);
        }


        $call =
            callGet($callId);


        if (
            !$call
            ||
            !callCanAccess(
                $call,
                $userId
            )
        ) {

            chatJson([
                'ok' => false,
                'message' =>
                    'You cannot access this call.'
            ], 403);
        }


        if (
            !in_array(
                $call['status'],
                [
                    'ringing',
                    'accepted'
                ],
                true
            )
        ) {

            chatJson([
                'ok' => false,
                'message' =>
                    'This call has ended.'
            ], 409);
        }


        $stmt = $conn->prepare("
            INSERT INTO chat_call_signals (
                call_id,
                sender_id,
                signal_type,
                signal_data
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $callId,
            $userId,
            $signalType,
            $signalData
        ]);


        chatJson([
            'ok' => true,
            'signal_id' =>
                (int)$conn->lastInsertId()
        ]);
    }


    chatJson([
        'ok' => false,
        'message' => 'Invalid action.'
    ], 400);
}


chatJson([
    'ok' => false,
    'message' => 'Method not allowed.'
], 405);