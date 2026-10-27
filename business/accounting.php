<?php
session_start();

require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/accounting_engine.php';

requireBusinessUser();

$businessId   = (int)($_SESSION['business_id'] ?? 0);
$userId       = (int)($_SESSION['user_id'] ?? 0);
$businessName = trim((string)($_SESSION['business_name'] ?? 'Business'));

/* =========================================================
   BUSINESS ADMIN ONLY
========================================================= */

$stmt = $conn->prepare("
    SELECT r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
      AND u.business_id = ?
    LIMIT 1
");

$stmt->execute([
    $userId,
    $businessId
]);

$roleName = (string)$stmt->fetchColumn();

if ($roleName !== 'Business Admin') {
    http_response_code(403);

    exit(
        'Access denied. Accounting is available to Business Admin only.'
    );
}

/* =========================================================
   AUTOMATIC ACCOUNTING SYNC
========================================================= */

$accountingSyncError = '';

try {

    syncAutomaticAccounting(
        $conn,
        $businessId
    );

} catch (Throwable $e) {

    $accountingSyncError = $e->getMessage();

    error_log(
        'Accounting Sync Error | Business ID: '
        . $businessId
        . ' | '
        . $e->getMessage()
    );
}

/* =========================================================
   BRANCHES
========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        branch_name
    FROM branches
    WHERE business_id = ?
      AND status = 'Active'
    ORDER BY
        is_main DESC,
        branch_name ASC
");

$stmt->execute([
    $businessId
]);

$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   SELECTED BRANCH
========================================================= */

$selectedBranchId = (int)($_GET['branch_id'] ?? 0);

if ($selectedBranchId > 0) {

    $validBranch = false;

    foreach ($branches as $branch) {

        if (
            (int)$branch['id']
            ===
            $selectedBranchId
        ) {

            $validBranch = true;
            break;
        }
    }

    if (!$validBranch) {
        $selectedBranchId = 0;
    }
}

/* =========================================================
   SEARCH
========================================================= */

$search = trim(
    (string)($_GET['search'] ?? '')
);

/* =========================================================
   JOURNAL ENTRIES
========================================================= */

$sql = "
    SELECT
        je.id,
        je.journal_no,
        je.entry_date,
        je.reference_type,
        je.reference_id,
        je.description,
        je.status,

        br.branch_name,

        COALESCE(
            SUM(jel.debit),
            0
        ) AS total_debit,

        COALESCE(
            SUM(jel.credit),
            0
        ) AS total_credit

    FROM journal_entries je

    INNER JOIN branches br
        ON br.id = je.branch_id
        AND br.business_id = je.business_id

    LEFT JOIN journal_entry_lines jel
        ON jel.journal_entry_id = je.id

    WHERE je.business_id = ?
";

$params = [
    $businessId
];

/* Branch Filter */

if ($selectedBranchId > 0) {

    $sql .= "
        AND je.branch_id = ?
    ";

    $params[] = $selectedBranchId;
}

/* Search */

if ($search !== '') {

    $sql .= "
        AND
        (
            je.journal_no LIKE ?

            OR je.reference_type LIKE ?

            OR je.description LIKE ?

            OR br.branch_name LIKE ?

            OR CAST(
                je.reference_id AS CHAR
            ) LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like,
        $like
    );
}

$sql .= "
    GROUP BY

        je.id,
        je.journal_no,
        je.entry_date,
        je.reference_type,
        je.reference_id,
        je.description,
        je.status,
        br.branch_name

    ORDER BY

        je.entry_date DESC,
        je.id DESC
";

$stmt = $conn->prepare($sql);

$stmt->execute($params);

$journals =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   ACCOUNTING SUMMARY
========================================================= */

$sql = "
    SELECT

        COUNT(
            DISTINCT je.id
        ) AS total_journals,

        COALESCE(
            SUM(jel.debit),
            0
        ) AS total_debit,

        COALESCE(
            SUM(jel.credit),
            0
        ) AS total_credit

    FROM journal_entries je

    LEFT JOIN journal_entry_lines jel
        ON jel.journal_entry_id = je.id

    WHERE je.business_id = ?

      AND je.status = 'Posted'
";

$params = [
    $businessId
];

if ($selectedBranchId > 0) {

    $sql .= "
        AND je.branch_id = ?
    ";

    $params[] =
        $selectedBranchId;
}

$stmt =
    $conn->prepare($sql);

$stmt->execute($params);

$summary =
    $stmt->fetch(PDO::FETCH_ASSOC)
    ?: [];

$totalJournals =
    (int)($summary['total_journals'] ?? 0);

$totalDebit =
    (float)($summary['total_debit'] ?? 0);

$totalCredit =
    (float)($summary['total_credit'] ?? 0);

$difference =
    round(
        $totalDebit - $totalCredit,
        2
    );

/* =========================================================
   ACCOUNT BALANCES
========================================================= */

$sql = "
    SELECT

        a.id,

        a.account_code,

        a.account_name,

        a.account_type,

        a.normal_balance,

        COALESCE(
            SUM(
                CASE

                    WHEN je.status = 'Posted'

                    THEN jel.debit

                    ELSE 0

                END
            ),
            0
        ) AS debit_total,

        COALESCE(
            SUM(
                CASE

                    WHEN je.status = 'Posted'

                    THEN jel.credit

                    ELSE 0

                END
            ),
            0
        ) AS credit_total

    FROM accounts a

    LEFT JOIN journal_entry_lines jel

        ON jel.account_id = a.id

    LEFT JOIN journal_entries je

        ON je.id = jel.journal_entry_id

        AND je.business_id = a.business_id
";

$params = [];

if ($selectedBranchId > 0) {

    $sql .= "
        AND je.branch_id = ?
    ";

    $params[] =
        $selectedBranchId;
}

$sql .= "

    WHERE a.business_id = ?

      AND a.status = 'Active'

    GROUP BY

        a.id,
        a.account_code,
        a.account_name,
        a.account_type,
        a.normal_balance

    ORDER BY

        a.account_code ASC
";

$params[] =
    $businessId;

$stmt =
    $conn->prepare($sql);

$stmt->execute($params);

$accounts =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Calculate account balances */

foreach ($accounts as &$account) {

    $debit =
        (float)$account['debit_total'];

    $credit =
        (float)$account['credit_total'];

    if (
        $account['normal_balance']
        ===
        'Credit'
    ) {

        $account['balance'] =
            $credit - $debit;

    } else {

        $account['balance'] =
            $debit - $credit;
    }
}

unset($account);

/* =========================================================
   MONEY FORMAT
========================================================= */

function accountingMoney($amount)
{
    return '$'
        . number_format(
            (float)$amount,
            2
        );
}
?>