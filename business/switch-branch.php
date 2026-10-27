<?php

require_once '../config/auth.php';
require_once '../config/audit.php';

requireBusinessUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$postedToken  = $_POST['csrf_token'] ?? '';

if (
    $sessionToken === ''
    || $postedToken === ''
    || !hash_equals($sessionToken, $postedToken)
) {
    http_response_code(419);
    exit('Invalid or expired request token.');
}

$userId     = (int) ($_SESSION['user_id'] ?? 0);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$currentBranchId = (int) ($_SESSION['branch_id'] ?? 0);
$role       = $_SESSION['role'] ?? '';

$allowedRoles = [
    'Business Admin',
    'Manager',
    'Accountant'
];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('You do not have permission to switch branches.');
}

$newBranchId = (int) ($_POST['branch_id'] ?? 0);

if ($businessId <= 0 || $newBranchId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT
        id,
        branch_name,
        business_id,
        status
    FROM branches
    WHERE id = ?
      AND business_id = ?
    LIMIT 1
");

$stmt->execute([
    $newBranchId,
    $businessId
]);

$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch || $branch['status'] !== 'Active') {
    $_SESSION['flash_message'] = 'Branch-ka lama heli karo ama ma active aha.';
    $_SESSION['flash_type'] = 'error';

    header('Location: dashboard.php');
    exit;
}

if ($currentBranchId === $newBranchId) {
    header('Location: dashboard.php');
    exit;
}

$oldBranchName = $_SESSION['branch_name'] ?? '';

$_SESSION['branch_id'] = (int) $branch['id'];
$_SESSION['branch_name'] = $branch['branch_name'];

try {
    $stmt = $conn->prepare("
        UPDATE users
        SET branch_id = ?
        WHERE id = ?
          AND business_id = ?
    ");

    $stmt->execute([
        $newBranchId,
        $userId,
        $businessId
    ]);
} catch (Throwable $e) {
    // Session branch is still updated even if persistent preference update fails.
}

if (function_exists('logActivity')) {
    try {
        logActivity(
            $conn,
            'UPDATE',
            'Branch',
            $newBranchId,
            'Switched active branch',
            [
                'branch_id' => $currentBranchId,
                'branch_name' => $oldBranchName
            ],
            [
                'branch_id' => $newBranchId,
                'branch_name' => $branch['branch_name']
            ],
            $businessId,
            $newBranchId
        );
    } catch (Throwable $e) {
        // Audit failure must not block branch switching.
    }
}

$_SESSION['flash_message'] =
    'Branch-ka waxaa loo beddelay ' . $branch['branch_name'] . '.';

$_SESSION['flash_type'] = 'success';

header('Location: dashboard.php');
exit;
