<?php

require_once __DIR__ . '/_bootstrap.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    exit('Unauthorized');
}

$messageId = (int)($_GET['message_id'] ?? 0);

if ($messageId <= 0) {
    http_response_code(422);
    exit('Invalid message.');
}


/*
|--------------------------------------------------------------------------
| GET MESSAGE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        m.id,
        m.conversation_id,
        m.sender_id,
        m.message_type,
        m.attachment_path,
        m.attachment_name,
        m.attachment_mime,
        m.is_view_once,
        m.viewed_at,
        m.expires_at,
        m.is_deleted
    FROM chat_messages m
    WHERE m.id = ?
    LIMIT 1
");

$stmt->execute([$messageId]);

$message = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$message) {
    http_response_code(404);
    exit('Media not found.');
}


/*
|--------------------------------------------------------------------------
| CONVERSATION ACCESS
|--------------------------------------------------------------------------
*/

$conversationId =
    (int)$message['conversation_id'];

if (!chatCanAccessConversation($conversationId)) {
    http_response_code(403);
    exit('Access denied.');
}


/*
|--------------------------------------------------------------------------
| DELETED
|--------------------------------------------------------------------------
*/

if ((int)$message['is_deleted'] === 1) {
    http_response_code(410);
    exit('Message deleted.');
}


/*
|--------------------------------------------------------------------------
| EXPIRED
|--------------------------------------------------------------------------
*/

if (
    !empty($message['expires_at'])
    && strtotime($message['expires_at']) <= time()
) {
    http_response_code(410);
    exit('Media expired.');
}


/*
|--------------------------------------------------------------------------
| VALID MEDIA TYPE
|--------------------------------------------------------------------------
*/

$allowedMessageTypes = [
    'image',
    'video',
    'voice',
    'file'
];

if (
    !in_array(
        $message['message_type'],
        $allowedMessageTypes,
        true
    )
) {
    http_response_code(422);
    exit('This message has no media.');
}


/*
|--------------------------------------------------------------------------
| VIEW ONCE
|--------------------------------------------------------------------------
*/

$isViewOnce =
    (int)$message['is_view_once'] === 1;

$isSender =
    (int)$message['sender_id'] === $userId;


/*
|--------------------------------------------------------------------------
| VIEW ONCE SENDER
|--------------------------------------------------------------------------
|
| Sender-ka dib uma furi karo View Once media.
|--------------------------------------------------------------------------
*/

if ($isViewOnce && $isSender) {
    http_response_code(403);
    exit('View Once media cannot be reopened by sender.');
}


/*
|--------------------------------------------------------------------------
| VIEW ONCE ALREADY OPENED
|--------------------------------------------------------------------------
*/

if (
    $isViewOnce
    && !empty($message['viewed_at'])
) {
    http_response_code(410);
    exit('This View Once media has already been opened.');
}


/*
|--------------------------------------------------------------------------
| STORAGE ROOT
|--------------------------------------------------------------------------
*/

$storageRoot =
    dirname(__DIR__, 2)
    . '/storage/chat';

$storageRootReal =
    realpath($storageRoot);

if ($storageRootReal === false) {
    http_response_code(500);
    exit('Storage directory not found.');
}


/*
|--------------------------------------------------------------------------
| INTERNAL PATH
|--------------------------------------------------------------------------
*/

$relativePath =
    ltrim(
        str_replace(
            ['\\', '..'],
            ['/', ''],
            (string)$message['attachment_path']
        ),
        '/'
    );

if ($relativePath === '') {
    http_response_code(404);
    exit('Media path not found.');
}


$absolutePath =
    $storageRootReal
    . DIRECTORY_SEPARATOR
    . str_replace(
        '/',
        DIRECTORY_SEPARATOR,
        $relativePath
    );


$fileReal =
    realpath($absolutePath);


/*
|--------------------------------------------------------------------------
| SECURITY PATH CHECK
|--------------------------------------------------------------------------
*/

if (
    $fileReal === false
    || !is_file($fileReal)
    || strpos(
        $fileReal,
        $storageRootReal . DIRECTORY_SEPARATOR
    ) !== 0
) {
    http_response_code(404);
    exit('Media file not found.');
}


/*
|--------------------------------------------------------------------------
| VIEW ONCE — ATOMIC OPEN
|--------------------------------------------------------------------------
*/

if ($isViewOnce) {

    try {

        $conn->beginTransaction();

        $lockStmt =
            $conn->prepare("
                SELECT
                    viewed_at,
                    sender_id
                FROM chat_messages
                WHERE id = ?
                FOR UPDATE
            ");

        $lockStmt->execute([$messageId]);

        $locked =
            $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$locked) {

            $conn->rollBack();

            http_response_code(404);
            exit('Message not found.');
        }

        if (
            (int)$locked['sender_id']
            === $userId
        ) {

            $conn->rollBack();

            http_response_code(403);
            exit('Sender cannot reopen View Once media.');
        }

        if (!empty($locked['viewed_at'])) {

            $conn->rollBack();

            http_response_code(410);
            exit('Already opened.');
        }


        $update =
            $conn->prepare("
                UPDATE chat_messages
                SET viewed_at = NOW()
                WHERE id = ?
            ");

        $update->execute([$messageId]);

        $conn->commit();

    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        http_response_code(500);
        exit('Could not open View Once media.');
    }
}


/*
|--------------------------------------------------------------------------
| MIME
|--------------------------------------------------------------------------
*/

$mime =
    trim(
        (string)$message['attachment_mime']
    );

if ($mime === '') {

    $finfo =
        new finfo(FILEINFO_MIME_TYPE);

    $mime =
        (string)$finfo->file($fileReal);
}


/*
|--------------------------------------------------------------------------
| FILE NAME
|--------------------------------------------------------------------------
*/

$fileName =
    trim(
        (string)$message['attachment_name']
    );

if ($fileName === '') {
    $fileName = basename($fileReal);
}


/*
|--------------------------------------------------------------------------
| HEADERS
|--------------------------------------------------------------------------
*/

header('X-Content-Type-Options: nosniff');

header(
    'Content-Type: '
    . $mime
);

header(
    'Content-Length: '
    . filesize($fileReal)
);


/*
|--------------------------------------------------------------------------
| DOCUMENT DOWNLOAD
|--------------------------------------------------------------------------
*/

if ($message['message_type'] === 'file') {

    header(
        'Content-Disposition: attachment; filename="' .
        rawurlencode($fileName) .
        '"'
    );

} else {

    header(
        'Content-Disposition: inline; filename="' .
        rawurlencode($fileName) .
        '"'
    );
}


/*
|--------------------------------------------------------------------------
| VIEW ONCE CACHE SECURITY
|--------------------------------------------------------------------------
*/

if ($isViewOnce) {

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    header('Pragma: no-cache');

    header('Expires: 0');

} else {

    header(
        'Cache-Control: private, max-age=300'
    );
}


/*
|--------------------------------------------------------------------------
| STREAM
|--------------------------------------------------------------------------
*/

$handle =
    fopen($fileReal, 'rb');

if ($handle === false) {
    http_response_code(500);
    exit;
}

while (!feof($handle)) {

    echo fread(
        $handle,
        8192
    );

    flush();
}

fclose($handle);

exit;