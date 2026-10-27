<?php

require_once __DIR__ . '/_bootstrap.php';

/*
|--------------------------------------------------------------------------
| ONLY POST
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

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
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

$csrfToken = (string)($_POST['csrf_token'] ?? '');
$sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');

if (
    $sessionCsrf === ''
    || $csrfToken === ''
    || !hash_equals($sessionCsrf, $csrfToken)
) {
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

$conversationId = (int)($_POST['conversation_id'] ?? 0);

$caption = trim(
    (string)($_POST['caption'] ?? '')
);

$isViewOnce =
    (int)($_POST['is_view_once'] ?? 0) === 1
        ? 1
        : 0;

$replyToId =
    !empty($_POST['reply_to_id'])
        ? (int)$_POST['reply_to_id']
        : null;


if ($conversationId <= 0) {
    chatJson([
        'ok' => false,
        'message' => 'Conversation-ka lama helin.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| ACCESS CHECK
|--------------------------------------------------------------------------
*/

if (!chatCanAccessConversation($conversationId)) {
    chatJson([
        'ok' => false,
        'message' => 'Conversation-kan access uma lihid.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| FILE CHECK
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['attachment'])
    || !is_array($_FILES['attachment'])
) {
    chatJson([
        'ok' => false,
        'message' => 'Fayl lama soo dirin.'
    ], 422);
}


$file = $_FILES['attachment'];


if (
    ($file['error'] ?? UPLOAD_ERR_NO_FILE)
    !== UPLOAD_ERR_OK
) {

    $uploadError =
        (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    $message = match ($uploadError) {

        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE =>
            'File-ku aad ayuu u weyn yahay.',

        UPLOAD_ERR_PARTIAL =>
            'File-ku si buuxda uma soo upload-garin.',

        UPLOAD_ERR_NO_FILE =>
            'Fayl lama dooran.',

        default =>
            'Upload-ku wuu fashilmay.'
    };

    chatJson([
        'ok' => false,
        'message' => $message
    ], 422);
}


/*
|--------------------------------------------------------------------------
| BASIC FILE DATA
|--------------------------------------------------------------------------
*/

$tmpPath =
    (string)$file['tmp_name'];

$originalName =
    trim(
        (string)($file['name'] ?? 'file')
    );

$fileSize =
    (int)($file['size'] ?? 0);


if (
    $tmpPath === ''
    || !is_uploaded_file($tmpPath)
) {
    chatJson([
        'ok' => false,
        'message' => 'Upload file-ka sax ma aha.'
    ], 422);
}


if ($fileSize <= 0) {
    chatJson([
        'ok' => false,
        'message' => 'File-ku waa empty.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| DETECT REAL MIME
|--------------------------------------------------------------------------
*/

$finfo =
    new finfo(FILEINFO_MIME_TYPE);

$mime =
    strtolower(
        (string)$finfo->file($tmpPath)
    );


/*
|--------------------------------------------------------------------------
| ALLOWED TYPES
|--------------------------------------------------------------------------
|
| Important:
| Browser-ka kama aamino filename extension-ka.
| MIME-ka dhabta ah ayaan hubineynaa.
|
*/

$imageTypes = [

    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',

];


$videoTypes = [

    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',

];


$voiceTypes = [

    'audio/webm'      => 'webm',
    'audio/ogg'       => 'ogg',
    'audio/mpeg'      => 'mp3',
    'audio/mp4'       => 'm4a',
    'audio/x-m4a'     => 'm4a',
    'audio/wav'       => 'wav',
    'audio/x-wav'     => 'wav',

];


$fileTypes = [

    'application/pdf' =>
        'pdf',

    'text/plain' =>
        'txt',

    'text/csv' =>
        'csv',

    'application/msword' =>
        'doc',

    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' =>
        'docx',

    'application/vnd.ms-excel' =>
        'xls',

    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' =>
        'xlsx',

    'application/vnd.ms-powerpoint' =>
        'ppt',

    'application/vnd.openxmlformats-officedocument.presentationml.presentation' =>
        'pptx',

    'application/zip' =>
        'zip',

    'application/x-zip-compressed' =>
        'zip',

];


/*
|--------------------------------------------------------------------------
| REQUESTED MEDIA TYPE
|--------------------------------------------------------------------------
|
| Frontend-ku voice recorder marka uu dirayo:
| media_kind = voice
|
| Sawir/video/file caadi ah:
| media_kind waa optional.
|
*/

$requestedKind =
    strtolower(
        trim(
            (string)($_POST['media_kind'] ?? '')
        )
    );


$messageType = '';
$folderName = '';
$extension = '';
$maxSize = 0;


/*
|--------------------------------------------------------------------------
| VOICE
|--------------------------------------------------------------------------
*/

if (
    $requestedKind === 'voice'
    && isset($voiceTypes[$mime])
) {

    $messageType = 'voice';
    $folderName = 'voice';
    $extension = $voiceTypes[$mime];

    // 20 MB
    $maxSize =
        20 * 1024 * 1024;


/*
|--------------------------------------------------------------------------
| IMAGE
|--------------------------------------------------------------------------
*/

} elseif (
    isset($imageTypes[$mime])
) {

    $messageType = 'image';
    $folderName = 'images';
    $extension = $imageTypes[$mime];

    // 12 MB
    $maxSize =
        12 * 1024 * 1024;


/*
|--------------------------------------------------------------------------
| VIDEO
|--------------------------------------------------------------------------
*/

} elseif (
    isset($videoTypes[$mime])
) {

    $messageType = 'video';
    $folderName = 'videos';
    $extension = $videoTypes[$mime];

    // 60 MB
    $maxSize =
        60 * 1024 * 1024;


/*
|--------------------------------------------------------------------------
| NORMAL FILE
|--------------------------------------------------------------------------
*/

} elseif (
    isset($fileTypes[$mime])
) {

    $messageType = 'file';
    $folderName = 'files';
    $extension = $fileTypes[$mime];

    // 30 MB
    $maxSize =
        30 * 1024 * 1024;


} else {

    chatJson([
        'ok' => false,
        'message' =>
            'File type-kan lama oggola.',
        'mime' => $mime
    ], 422);
}


/*
|--------------------------------------------------------------------------
| SIZE LIMIT
|--------------------------------------------------------------------------
*/

if ($fileSize > $maxSize) {

    chatJson([
        'ok' => false,
        'message' =>
            'File-ku wuxuu ka weyn yahay xadka loo oggol yahay.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| VIEW ONCE
|--------------------------------------------------------------------------
|
| View Once waxaa loo oggol yahay:
| image
| video
| voice
|
| File/document caadi ah view-once ma noqonayo.
|
*/

if (
    !in_array(
        $messageType,
        ['image', 'video', 'voice'],
        true
    )
) {
    $isViewOnce = 0;
}


/*
|--------------------------------------------------------------------------
| REPLY VALIDATION
|--------------------------------------------------------------------------
*/

if ($replyToId !== null) {

    $replyStmt =
        $conn->prepare("
            SELECT id
            FROM chat_messages
            WHERE id = ?
              AND conversation_id = ?
              AND is_deleted = 0
            LIMIT 1
        ");

    $replyStmt->execute([
        $replyToId,
        $conversationId
    ]);


    if (!$replyStmt->fetchColumn()) {

        chatJson([
            'ok' => false,
            'message' =>
                'Reply message-ka lama helin.'
        ], 422);
    }
}


/*
|--------------------------------------------------------------------------
| DISAPPEARING SETTING
|--------------------------------------------------------------------------
*/

$conversationStmt =
    $conn->prepare("
        SELECT disappearing_seconds
        FROM chat_conversations
        WHERE id = ?
        LIMIT 1
    ");

$conversationStmt->execute([
    $conversationId
]);

$disappearingSeconds =
    $conversationStmt->fetchColumn();


$expiresAt = null;


if (
    $disappearingSeconds !== false
    && $disappearingSeconds !== null
    && (int)$disappearingSeconds > 0
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
| STORAGE PATH
|--------------------------------------------------------------------------
|
| bakhaar/
| └── storage/
|     └── chat/
|         ├── images/
|         ├── videos/
|         ├── voice/
|         └── files/
|
*/

$storageRoot =
    dirname(__DIR__, 2)
    . '/storage/chat';


$destinationDirectory =
    $storageRoot
    . '/'
    . $folderName;


if (!is_dir($destinationDirectory)) {

    if (
        !mkdir(
            $destinationDirectory,
            0755,
            true
        )
        && !is_dir($destinationDirectory)
    ) {

        chatJson([
            'ok' => false,
            'message' =>
                'Storage folder-ka lama abuuri karin.'
        ], 500);
    }
}


/*
|--------------------------------------------------------------------------
| RANDOM SECURE NAME
|--------------------------------------------------------------------------
*/

$randomName =
    bin2hex(random_bytes(24))
    . '.'
    . $extension;


$absolutePath =
    $destinationDirectory
    . '/'
    . $randomName;


/*
|--------------------------------------------------------------------------
| MOVE FILE
|--------------------------------------------------------------------------
*/

if (
    !move_uploaded_file(
        $tmpPath,
        $absolutePath
    )
) {

    chatJson([
        'ok' => false,
        'message' =>
            'File-ka lama kaydin karin.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| INTERNAL PATH
|--------------------------------------------------------------------------
|
| Database-ka kuma kaydinayno public URL.
|
*/

$internalPath =
    $folderName
    . '/'
    . $randomName;


/*
|--------------------------------------------------------------------------
| SANITIZE ORIGINAL NAME
|--------------------------------------------------------------------------
*/

$cleanOriginalName =
    preg_replace(
        '/[^\pL\pN\.\-\_\s]/u',
        '',
        basename($originalName)
    );


if (
    $cleanOriginalName === null
    || trim($cleanOriginalName) === ''
) {

    $cleanOriginalName =
        'attachment.'
        . $extension;
}


/*
|--------------------------------------------------------------------------
| CAPTION LIMIT
|--------------------------------------------------------------------------
*/

if (
    mb_strlen($caption) > 3000
) {

    @unlink($absolutePath);

    chatJson([
        'ok' => false,
        'message' =>
            'Caption-ku aad ayuu u dheer yahay.'
    ], 422);
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
            INSERT INTO chat_messages (
                conversation_id,
                sender_id,
                reply_to_id,
                message,
                message_type,
                attachment_path,
                attachment_name,
                attachment_mime,
                is_view_once,
                expires_at
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");


    $stmt->execute([

        $conversationId,

        $userId,

        $replyToId,

        $caption !== ''
            ? $caption
            : null,

        $messageType,

        $internalPath,

        $cleanOriginalName,

        $mime,

        $isViewOnce,

        $expiresAt

    ]);


    $messageId =
        (int)$conn->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | UPDATE CONVERSATION
    |--------------------------------------------------------------------------
    */

    $updateStmt =
        $conn->prepare("
            UPDATE chat_conversations
            SET updated_at = NOW()
            WHERE id = ?
        ");

    $updateStmt->execute([
        $conversationId
    ]);


    $conn->commit();


} catch (Throwable $e) {

    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    @unlink($absolutePath);


    chatJson([
        'ok' => false,
        'message' =>
            'Message-ka lama kaydin karin.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
|
| attachment_path-ka intentionally frontend-ka uma direyno.
| Media endpoint gaar ah ayaa file-ka soo saaraya.
|
*/

chatJson([
    'ok' => true,

    'message' =>
        'Attachment sent successfully.',

    'data' => [

        'id' =>
            $messageId,

        'conversation_id' =>
            $conversationId,

        'message_type' =>
            $messageType,

        'attachment_name' =>
            $cleanOriginalName,

        'attachment_mime' =>
            $mime,

        'is_view_once' =>
            $isViewOnce,

        'expires_at' =>
            $expiresAt

    ]
]);