<?php
require_once __DIR__ . '/_bootstrap.php';

$statusId = (int)($_GET['id'] ?? 0);

if ($statusId <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        s.id,
        s.user_id,
        s.media_path,
        s.media_mime,
        s.expires_at
    FROM chat_statuses s
    WHERE s.id = ?
    LIMIT 1
");

$stmt->execute([$statusId]);

$status = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$status ||
    empty($status['media_path'])
) {
    http_response_code(404);
    exit;
}

if (
    strtotime((string)$status['expires_at']) <= time()
) {
    http_response_code(410);
    exit;
}

/*
|--------------------------------------------------------------------------
| Same visibility rules as Chat.
|--------------------------------------------------------------------------
*/

$ownerId = (int)$status['user_id'];

if (
    $ownerId !== chatUserId() &&
    !chatCanContact($ownerId)
) {
    http_response_code(403);
    exit;
}

$storageRoot = realpath(
    __DIR__ . '/../../storage/chat/statuses'
);

if (!$storageRoot) {
    http_response_code(404);
    exit;
}

$file = realpath(
    __DIR__ .
    '/../../storage/chat/statuses/' .
    basename((string)$status['media_path'])
);

if (
    !$file ||
    !str_starts_with($file, $storageRoot) ||
    !is_file($file)
) {
    http_response_code(404);
    exit;
}

$mime = (string)(
    $status['media_mime'] ?: 'application/octet-stream'
);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

readfile($file);
exit;
