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
        s.expires_at,
        u.role,
        u.business_id,
        u.branch_id
    FROM chat_statuses s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.id = ?
    LIMIT 1
");
$stmt->execute([$statusId]);

$status = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$status || empty($status['media_path'])) {
    http_response_code(404);
    exit;
}

if (strtotime((string)$status['expires_at']) <= time()) {
    http_response_code(410);
    exit;
}

$viewerId = chatUserId();
$allowed = false;

if ((int)$status['user_id'] === $viewerId || chatIsSuperAdmin()) {
    $allowed = true;
} else {
    $ownerRole = strtolower(trim((string)$status['role']));

    if (in_array($ownerRole, ['super admin', 'super_admin'], true)) {
        $allowed = true;
    } else {
        $viewerBusiness = (int)(chatBusinessId() ?? 0);
        $ownerBusiness = (int)($status['business_id'] ?? 0);

        if ($viewerBusiness > 0 && $viewerBusiness === $ownerBusiness) {
            $viewerRole = strtolower(trim((string)chatRole()));

            if (in_array($viewerRole, ['business admin', 'business_admin', 'admin'], true)) {
                $allowed = true;
            } elseif (in_array($ownerRole, ['business admin', 'business_admin', 'admin'], true)) {
                $allowed = true;
            } else {
                $viewerBranch = (int)(chatBranchId() ?? 0);
                $ownerBranch = (int)($status['branch_id'] ?? 0);

                $allowed = $viewerBranch > 0 && $viewerBranch === $ownerBranch;
            }
        }
    }
}

if (!$allowed) {
    http_response_code(403);
    exit;
}

$storageRoot = realpath(__DIR__ . '/../../storage/chat/statuses');

if (!$storageRoot) {
    http_response_code(404);
    exit;
}

$file = realpath(
    __DIR__ . '/../../storage/chat/statuses/' .
    basename((string)$status['media_path'])
);

if (!$file || !str_starts_with($file, $storageRoot) || !is_file($file)) {
    http_response_code(404);
    exit;
}

$mime = (string)($status['media_mime'] ?: 'application/octet-stream');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

readfile($file);
exit;
