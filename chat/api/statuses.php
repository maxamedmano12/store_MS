<?php
require_once __DIR__ . '/_bootstrap.php';

$userId = chatUserId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list'));

/*
|--------------------------------------------------------------------------
| STATUS VISIBILITY
|--------------------------------------------------------------------------
| Do NOT read users.role.
| Visibility follows the same permission/contact rules already used by Chat.
*/

function statusCanSeeOwner(int $ownerId): bool
{
    if ($ownerId === chatUserId()) {
        return true;
    }

    return chatCanContact($ownerId);
}

function statusGet(int $statusId): ?array
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT
            s.*,
            u.full_name,
            u.profile_picture
        FROM chat_statuses s
        INNER JOIN users u
            ON u.id = s.user_id
        WHERE s.id = ?
        LIMIT 1
    ");

    $stmt->execute([$statusId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function statusMediaUrl(array $status): ?string
{
    if (empty($status['media_path'])) {
        return null;
    }

    return '../chat/api/status-media.php?id=' . (int)$status['id'];
}

function statusSerialize(array $status): array
{
    return [
        'id' => (int)$status['id'],
        'user_id' => (int)$status['user_id'],
        'full_name' => (string)($status['full_name'] ?? 'User'),

        'profile_picture' => !empty($status['profile_picture'])
            ? '../chat/api/profile-picture.php?user_id=' . (int)$status['user_id']
            : null,

        'business_id' => $status['business_id'] !== null
            ? (int)$status['business_id']
            : null,

        'branch_id' => $status['branch_id'] !== null
            ? (int)$status['branch_id']
            : null,

        'status_type' => (string)$status['status_type'],
        'text_content' => $status['text_content'],
        'caption' => $status['caption'],

        'background_color' => $status['background_color'] ?: '#1d4ed8',
        'text_color' => $status['text_color'] ?: '#ffffff',

        'media_url' => statusMediaUrl($status),

        'created_at' => $status['created_at'],
        'expires_at' => $status['expires_at'],

        'is_mine' => (int)$status['user_id'] === chatUserId()
    ];
}

function statusAllowedColor(?string $value, string $fallback): string
{
    $value = trim((string)$value);

    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return $value;
    }

    return $fallback;
}

function statusDeleteMedia(?string $path): void
{
    if (!$path) {
        return;
    }

    $root = realpath(__DIR__ . '/../../storage/chat/statuses');

    if (!$root) {
        return;
    }

    $file = realpath(
        __DIR__ . '/../../storage/chat/statuses/' .
        basename($path)
    );

    if (
        $file &&
        str_starts_with($file, $root) &&
        is_file($file)
    ) {
        @unlink($file);
    }
}

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
*/

if ($method === 'GET') {

    /*
    |--------------------------------------------------------------------------
    | LIST ACTIVE STATUSES
    |--------------------------------------------------------------------------
    */
    if ($action === 'list') {
        $stmt = $conn->query("
            SELECT
                s.*,
                u.full_name,
                u.profile_picture
            FROM chat_statuses s
            INNER JOIN users u
                ON u.id = s.user_id
            WHERE s.expires_at > NOW()
            ORDER BY s.created_at DESC, s.id DESC
            LIMIT 500
        ");

        $statuses = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ownerId = (int)$row['user_id'];

            if (!statusCanSeeOwner($ownerId)) {
                continue;
            }

            $statuses[] = statusSerialize($row);
        }

        chatJson([
            'ok' => true,
            'statuses' => $statuses
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | VIEWERS
    |--------------------------------------------------------------------------
    */
    if ($action === 'viewers') {
        $statusId = (int)($_GET['status_id'] ?? 0);

        $status = statusGet($statusId);

        if (!$status) {
            chatJson([
                'ok' => false,
                'message' => 'Status not found.'
            ], 404);
        }

        if ((int)$status['user_id'] !== $userId) {
            chatJson([
                'ok' => false,
                'message' => 'Not allowed.'
            ], 403);
        }

        $stmt = $conn->prepare("
            SELECT
                v.viewer_id,
                v.viewed_at,
                u.full_name
            FROM chat_status_views v
            INNER JOIN users u
                ON u.id = v.viewer_id
            WHERE v.status_id = ?
            ORDER BY v.viewed_at DESC
        ");

        $stmt->execute([$statusId]);

        chatJson([
            'ok' => true,
            'viewers' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }

    chatJson([
        'ok' => false,
        'message' => 'Unknown action.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| POST CSRF
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
| CREATE TEXT / EMOJI STATUS
|--------------------------------------------------------------------------
*/

if ($action === 'create') {
    $statusType = trim(
        (string)($_POST['status_type'] ?? 'text')
    );

    if (!in_array($statusType, ['text', 'emoji'], true)) {
        chatJson([
            'ok' => false,
            'message' => 'Invalid status type.'
        ], 422);
    }

    $text = trim(
        (string)($_POST['text_content'] ?? '')
    );

    if ($text === '') {
        chatJson([
            'ok' => false,
            'message' => 'Status text is required.'
        ], 422);
    }

    if (mb_strlen($text) > 1000) {
        chatJson([
            'ok' => false,
            'message' => 'Status is too long.'
        ], 422);
    }

    $background = statusAllowedColor(
        $_POST['background_color'] ?? null,
        '#1d4ed8'
    );

    $textColor = statusAllowedColor(
        $_POST['text_color'] ?? null,
        '#ffffff'
    );

    $businessId = chatBusinessId();
    $branchId = chatBranchId();

    $stmt = $conn->prepare("
        INSERT INTO chat_statuses (
            user_id,
            business_id,
            branch_id,
            status_type,
            text_content,
            background_color,
            text_color,
            expires_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            DATE_ADD(NOW(), INTERVAL 24 HOUR)
        )
    ");

    $stmt->execute([
        $userId,
        $businessId ?: null,
        $branchId ?: null,
        $statusType,
        $text,
        $background,
        $textColor
    ]);

    $status = statusGet(
        (int)$conn->lastInsertId()
    );

    chatJson([
        'ok' => true,
        'status' => statusSerialize($status)
    ]);
}

/*
|--------------------------------------------------------------------------
| IMAGE STATUS
|--------------------------------------------------------------------------
*/

if ($action === 'upload') {
    if (
        empty($_FILES['image']) ||
        !is_uploaded_file($_FILES['image']['tmp_name'])
    ) {
        chatJson([
            'ok' => false,
            'message' => 'Please choose an image.'
        ], 422);
    }

    $file = $_FILES['image'];

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        chatJson([
            'ok' => false,
            'message' => 'Image upload failed.'
        ], 422);
    }

    if ((int)$file['size'] > 10 * 1024 * 1024) {
        chatJson([
            'ok' => false,
            'message' => 'Image must be 10 MB or smaller.'
        ], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];

    if (!isset($allowed[$mime])) {
        chatJson([
            'ok' => false,
            'message' => 'Only JPG, PNG, WEBP or GIF images are allowed.'
        ], 422);
    }

    $caption = trim(
        (string)($_POST['caption'] ?? '')
    );

    if (mb_strlen($caption) > 1000) {
        chatJson([
            'ok' => false,
            'message' => 'Caption is too long.'
        ], 422);
    }

    $storageDir =
        __DIR__ . '/../../storage/chat/statuses';

    if (
        !is_dir($storageDir) &&
        !mkdir($storageDir, 0755, true)
    ) {
        chatJson([
            'ok' => false,
            'message' => 'Could not create status storage.'
        ], 500);
    }

    $filename =
        bin2hex(random_bytes(24)) .
        '.' .
        $allowed[$mime];

    $destination =
        $storageDir . '/' . $filename;

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {
        chatJson([
            'ok' => false,
            'message' => 'Could not save image.'
        ], 500);
    }

    $businessId = chatBusinessId();
    $branchId = chatBranchId();

    $statusType =
        $caption !== ''
            ? 'image_caption'
            : 'image';

    try {
        $stmt = $conn->prepare("
            INSERT INTO chat_statuses (
                user_id,
                business_id,
                branch_id,
                status_type,
                media_path,
                media_mime,
                caption,
                expires_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                DATE_ADD(NOW(), INTERVAL 24 HOUR)
            )
        ");

        $stmt->execute([
            $userId,
            $businessId ?: null,
            $branchId ?: null,
            $statusType,
            $filename,
            $mime,
            $caption !== '' ? $caption : null
        ]);
    } catch (Throwable $e) {
        @unlink($destination);
        throw $e;
    }

    $status = statusGet(
        (int)$conn->lastInsertId()
    );

    chatJson([
        'ok' => true,
        'status' => statusSerialize($status)
    ]);
}

/*
|--------------------------------------------------------------------------
| MARK STATUS AS VIEWED
|--------------------------------------------------------------------------
*/

if ($action === 'view') {
    $statusId = (int)(
        $_POST['status_id'] ?? 0
    );

    $status = statusGet($statusId);

    if (
        !$status ||
        strtotime((string)$status['expires_at']) <= time()
    ) {
        chatJson([
            'ok' => false,
            'message' => 'Status not found or expired.'
        ], 404);
    }

    if (
        !statusCanSeeOwner(
            (int)$status['user_id']
        )
    ) {
        chatJson([
            'ok' => false,
            'message' => 'Not allowed.'
        ], 403);
    }

    if ((int)$status['user_id'] !== $userId) {
        $stmt = $conn->prepare("
            INSERT INTO chat_status_views (
                status_id,
                viewer_id,
                viewed_at
            )
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                viewed_at = viewed_at
        ");

        $stmt->execute([
            $statusId,
            $userId
        ]);
    }

    chatJson([
        'ok' => true
    ]);
}

/*
|--------------------------------------------------------------------------
| DELETE OWN STATUS
|--------------------------------------------------------------------------
*/

if ($action === 'delete') {
    $statusId = (int)(
        $_POST['status_id'] ?? 0
    );

    $status = statusGet($statusId);

    if (!$status) {
        chatJson([
            'ok' => false,
            'message' => 'Status not found.'
        ], 404);
    }

    if ((int)$status['user_id'] !== $userId) {
        chatJson([
            'ok' => false,
            'message' => 'You can only delete your own status.'
        ], 403);
    }

    $stmt = $conn->prepare("
        DELETE FROM chat_statuses
        WHERE id = ?
    ");

    $stmt->execute([$statusId]);

    statusDeleteMedia(
        $status['media_path'] ?? null
    );

    chatJson([
        'ok' => true
    ]);
}

chatJson([
    'ok' => false,
    'message' => 'Unknown action.'
], 400);
