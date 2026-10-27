<?php
/**
 * Automatic Accounting Engine
 * Path: config/accounting_engine.php
 *
 * Reads operational tables and synchronizes accounting journals.
 * No manual journal entry is required.
 *
 * Supported:
 * - Sales + COGS
 * - Sales Returns + COGS reversal
 * - Purchases
 * - Purchase Returns
 * - Expenses
 * - Payroll
 * - Depreciation
 *
 * Payment methods: Cash, Zaad, Edahab only.
 */

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('Accounting Engine requires PDO connection in $conn.');
}

function aePaymentAccountCode(string $method): string
{
    return match (trim($method)) {
        'Zaad'   => '1010',
        'Edahab' => '1020',
        default  => '1000', // Cash
    };
}

function aeEnsureBusinessAccounts(PDO $conn, int $businessId): void
{
    $stmt = $conn->prepare("
        INSERT IGNORE INTO accounts
        (
            business_id, account_code, account_name, account_type,
            account_group, normal_balance, is_system, status, description
        )
        SELECT
            ?, da.account_code, da.account_name, da.account_type,
            da.account_group, da.normal_balance, 1, da.status, da.description
        FROM default_accounts da
        WHERE da.status = 'Active'
    ");
    $stmt->execute([$businessId]);
}

function aeAccountId(PDO $conn, int $businessId, string $code): int
{
    $stmt = $conn->prepare("
        SELECT id
        FROM accounts
        WHERE business_id = ?
          AND account_code = ?
          AND status = 'Active'
        LIMIT 1
    ");
    $stmt->execute([$businessId, $code]);
    $id = (int)$stmt->fetchColumn();

    if ($id <= 0) {
        throw new RuntimeException("Accounting account {$code} is missing for business {$businessId}.");
    }

    return $id;
}

function aeNextJournalNo(PDO $conn, int $businessId): string
{
    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(id), 0) + 1
        FROM journal_entries
        WHERE business_id = ?
    ");
    $stmt->execute([$businessId]);
    $next = (int)$stmt->fetchColumn();

    return 'JRN-' . date('Ymd') . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
}

function aeDeleteReferenceJournal(
    PDO $conn,
    int $businessId,
    string $referenceType,
    int $referenceId
): void {
    $stmt = $conn->prepare("
        DELETE FROM journal_entries
        WHERE business_id = ?
          AND reference_type = ?
          AND reference_id = ?
    ");
    $stmt->execute([$businessId, $referenceType, $referenceId]);
}

function aePostJournal(
    PDO $conn,
    int $businessId,
    int $branchId,
    string $entryDate,
    string $referenceType,
    int $referenceId,
    string $description,
    int $createdBy,
    array $lines
): void {
    $clean = [];
    $totalDebit = 0.0;
    $totalCredit = 0.0;

    foreach ($lines as $line) {
        $debit  = round((float)($line['debit'] ?? 0), 2);
        $credit = round((float)($line['credit'] ?? 0), 2);

        if ($debit <= 0 && $credit <= 0) {
            continue;
        }

        if ($debit > 0 && $credit > 0) {
            throw new RuntimeException('A journal line cannot contain both debit and credit.');
        }

        $clean[] = [
            'code'        => (string)$line['code'],
            'debit'       => $debit,
            'credit'      => $credit,
            'description' => (string)($line['description'] ?? $description),
        ];

        $totalDebit += $debit;
        $totalCredit += $credit;
    }

    $totalDebit  = round($totalDebit, 2);
    $totalCredit = round($totalCredit, 2);

    if (!$clean) {
        return;
    }

    if (abs($totalDebit - $totalCredit) > 0.01) {
        throw new RuntimeException(
            "{$referenceType} #{$referenceId} is not balanced. Debit={$totalDebit}, Credit={$totalCredit}"
        );
    }

    $branchCheck = $conn->prepare("
        SELECT COUNT(*)
        FROM branches
        WHERE id = ? AND business_id = ?
    ");
    $branchCheck->execute([$branchId, $businessId]);

    if ((int)$branchCheck->fetchColumn() !== 1) {
        throw new RuntimeException("Invalid accounting branch {$branchId} for business {$businessId}.");
    }

    aeDeleteReferenceJournal($conn, $businessId, $referenceType, $referenceId);

    $journalNo = aeNextJournalNo($conn, $businessId);

    $stmt = $conn->prepare("
        INSERT INTO journal_entries
        (
            business_id, branch_id, journal_no, entry_date,
            reference_type, reference_id, description,
            status, created_by
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Posted', ?)
    ");
    $stmt->execute([
        $businessId,
        $branchId,
        $journalNo,
        date('Y-m-d', strtotime($entryDate)),
        $referenceType,
        $referenceId,
        $description,
        $createdBy
    ]);

    $journalId = (int)$conn->lastInsertId();

    $lineStmt = $conn->prepare("
        INSERT INTO journal_entry_lines
        (journal_entry_id, account_id, debit, credit, description)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($clean as $line) {
        $accountId = aeAccountId($conn, $businessId, $line['code']);

        $lineStmt->execute([
            $journalId,
            $accountId,
            $line['debit'],
            $line['credit'],
            $line['description']
        ]);
    }
}

function aeCancelMissingOrCancelled(
    PDO $conn,
    int $businessId,
    string $referenceType,
    string $table,
    string $statusColumn = 'status'
): void {
    // Delete accounting journals whose source was cancelled.
    // Table names are internal constants only, never user input.
    $sql = "
        DELETE je
        FROM journal_entries je
        INNER JOIN {$table} src
            ON src.id = je.reference_id
           AND src.business_id = je.business_id
        WHERE je.business_id = ?
          AND je.reference_type = ?
          AND src.{$statusColumn} = 'Cancelled'
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$businessId, $referenceType]);
}

function aeSyncSales(PDO $conn, int $businessId): int
{
    aeCancelMissingOrCancelled($conn, $businessId, 'SALE', 'sales');

    $stmt = $conn->prepare("
        SELECT
            s.*,
            COALESCE((
                SELECT SUM(si.total_cost)
                FROM sale_items si
                WHERE si.sale_id = s.id
                  AND si.business_id = s.business_id
            ), 0) AS cogs
        FROM sales s
        WHERE s.business_id = ?
          AND s.status = 'Completed'
        ORDER BY s.id
    ");
    $stmt->execute([$businessId]);

    $count = 0;

    while ($s = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $total   = round((float)$s['total_amount'], 2);
        $paid    = min(round((float)$s['paid_amount'], 2), $total);
        $balance = round($total - $paid, 2);
        $cogs    = round((float)$s['cogs'], 2);

        $lines = [];

        if ($paid > 0) {
            $lines[] = [
                'code' => aePaymentAccountCode((string)($s['payment_method'] ?? 'Cash')),
                'debit' => $paid,
                'credit' => 0,
                'description' => 'Sale payment'
            ];
        }

        if ($balance > 0) {
            $lines[] = [
                'code' => '1100',
                'debit' => $balance,
                'credit' => 0,
                'description' => 'Customer receivable'
            ];
        }

        if ($total > 0) {
            $lines[] = [
                'code' => '4000',
                'debit' => 0,
                'credit' => $total,
                'description' => 'Sales revenue'
            ];
        }

        if ($cogs > 0) {
            $lines[] = [
                'code' => '5000',
                'debit' => $cogs,
                'credit' => 0,
                'description' => 'Cost of goods sold'
            ];
            $lines[] = [
                'code' => '1200',
                'debit' => 0,
                'credit' => $cogs,
                'description' => 'Inventory issued'
            ];
        }

        aePostJournal(
            $conn,
            $businessId,
            (int)$s['branch_id'],
            (string)$s['sale_date'],
            'SALE',
            (int)$s['id'],
            'Automatic sale journal - ' . $s['sale_no'],
            (int)$s['created_by'],
            $lines
        );
        $count++;
    }

    return $count;
}

function aeSyncSalesReturns(PDO $conn, int $businessId): int
{
    aeCancelMissingOrCancelled($conn, $businessId, 'SALE_RETURN', 'sales_returns');

    $stmt = $conn->prepare("
        SELECT
            sr.*,
            COALESCE((
                SELECT SUM(sri.total_cost)
                FROM sales_return_items sri
                WHERE sri.sales_return_id = sr.id
                  AND sri.business_id = sr.business_id
            ), 0) AS returned_cost
        FROM sales_returns sr
        WHERE sr.business_id = ?
          AND sr.status = 'Completed'
        ORDER BY sr.id
    ");
    $stmt->execute([$businessId]);

    $count = 0;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $total      = round((float)$r['total_amount'], 2);
        $refund     = min(round((float)$r['refund_amount'], 2), $total);
        $receivable = round($total - $refund, 2);
        $cost       = round((float)$r['returned_cost'], 2);

        $lines = [];

        if ($total > 0) {
            $lines[] = [
                'code' => '4000',
                'debit' => $total,
                'credit' => 0,
                'description' => 'Sales return'
            ];
        }

        if ($refund > 0) {
            $lines[] = [
                'code' => aePaymentAccountCode((string)($r['refund_method'] ?? 'Cash')),
                'debit' => 0,
                'credit' => $refund,
                'description' => 'Customer refund'
            ];
        }

        if ($receivable > 0) {
            $lines[] = [
                'code' => '1100',
                'debit' => 0,
                'credit' => $receivable,
                'description' => 'Receivable reduction'
            ];
        }

        if ($cost > 0) {
            $lines[] = [
                'code' => '1200',
                'debit' => $cost,
                'credit' => 0,
                'description' => 'Returned inventory'
            ];
            $lines[] = [
                'code' => '5000',
                'debit' => 0,
                'credit' => $cost,
                'description' => 'COGS reversal'
            ];
        }

        aePostJournal(
            $conn,
            $businessId,
            (int)$r['branch_id'],
            (string)$r['return_date'],
            'SALE_RETURN',
            (int)$r['id'],
            'Automatic sales return journal - ' . $r['return_no'],
            (int)$r['created_by'],
            $lines
        );
        $count++;
    }

    return $count;
}

function aeSyncPurchases(PDO $conn, int $businessId): int
{
    aeCancelMissingOrCancelled($conn, $businessId, 'PURCHASE', 'purchases');

    $stmt = $conn->prepare("
        SELECT *
        FROM purchases
        WHERE business_id = ?
          AND status = 'Completed'
        ORDER BY id
    ");
    $stmt->execute([$businessId]);

    $count = 0;

    while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $total   = round((float)$p['total_amount'], 2);
        $paid    = min(round((float)$p['paid_amount'], 2), $total);
        $balance = round($total - $paid, 2);

        $lines = [];

        if ($total > 0) {
            $lines[] = [
                'code' => '1200',
                'debit' => $total,
                'credit' => 0,
                'description' => 'Inventory purchased'
            ];
        }

        if ($paid > 0) {
            $lines[] = [
                'code' => aePaymentAccountCode((string)($p['payment_method'] ?? 'Cash')),
                'debit' => 0,
                'credit' => $paid,
                'description' => 'Purchase payment'
            ];
        }

        if ($balance > 0) {
            $lines[] = [
                'code' => '2000',
                'debit' => 0,
                'credit' => $balance,
                'description' => 'Supplier payable'
            ];
        }

        aePostJournal(
            $conn,
            $businessId,
            (int)$p['branch_id'],
            (string)$p['purchase_date'],
            'PURCHASE',
            (int)$p['id'],
            'Automatic purchase journal - ' . $p['purchase_no'],
            (int)$p['created_by'],
            $lines
        );
        $count++;
    }

    return $count;
}

function aeSyncPurchaseReturns(PDO $conn, int $businessId): int
{
    aeCancelMissingOrCancelled($conn, $businessId, 'PURCHASE_RETURN', 'purchase_returns');

    $stmt = $conn->prepare("
        SELECT *
        FROM purchase_returns
        WHERE business_id = ?
          AND status = 'Completed'
        ORDER BY id
    ");
    $stmt->execute([$businessId]);

    $count = 0;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $total  = round((float)$r['total_amount'], 2);
        $refund = min(round((float)$r['refund_amount'], 2), $total);
        $payableReduction = round($total - $refund, 2);

        $lines = [];

        if ($refund > 0) {
            $lines[] = [
                'code' => aePaymentAccountCode((string)($r['refund_method'] ?? 'Cash')),
                'debit' => $refund,
                'credit' => 0,
                'description' => 'Supplier refund received'
            ];
        }

        if ($payableReduction > 0) {
            $lines[] = [
                'code' => '2000',
                'debit' => $payableReduction,
                'credit' => 0,
                'description' => 'Supplier payable reduction'
            ];
        }

        if ($total > 0) {
            $lines[] = [
                'code' => '1200',
                'debit' => 0,
                'credit' => $total,
                'description' => 'Inventory returned to supplier'
            ];
        }

        aePostJournal(
            $conn,
            $businessId,
            (int)$r['branch_id'],
            (string)$r['return_date'],
            'PURCHASE_RETURN',
            (int)$r['id'],
            'Automatic purchase return journal - ' . $r['return_no'],
            (int)$r['created_by'],
            $lines
        );
        $count++;
    }

    return $count;
}

function aeSyncExpenses(PDO $conn, int $businessId): int
{
    aeCancelMissingOrCancelled($conn, $businessId, 'EXPENSE', 'expenses');

    $stmt = $conn->prepare("
        SELECT *
        FROM expenses
        WHERE business_id = ?
          AND status = 'Completed'
        ORDER BY id
    ");
    $stmt->execute([$businessId]);

    $count = 0;

    while ($e = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amount = round((float)$e['amount'], 2);

        aePostJournal(
            $conn,
            $businessId,
            (int)$e['branch_id'],
            (string)$e['expense_date'],
            'EXPENSE',
            (int)$e['id'],
            'Automatic expense journal - ' . $e['expense_no'],
            (int)$e['created_by'],
            [
                [
                    'code' => '5100',
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'General expense'
                ],
                [
                    'code' => aePaymentAccountCode((string)$e['payment_method']),
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Expense payment'
                ]
            ]
        );
        $count++;
    }

    return $count;
}

function aeSyncPayroll(PDO $conn, int $businessId): int
{
    aeCancelMissingOrCancelled($conn, $businessId, 'PAYROLL', 'payrolls');

    $stmt = $conn->prepare("
        SELECT *
        FROM payrolls
        WHERE business_id = ?
          AND status IN ('Pending','Paid')
        ORDER BY id
    ");
    $stmt->execute([$businessId]);

    $count = 0;

    while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amount = round((float)$p['net_salary'], 2);

        if ($p['status'] === 'Paid') {
            $creditCode = aePaymentAccountCode((string)$p['payment_method']);
            $creditText = 'Salary paid';
            $entryDate = !empty($p['paid_at']) ? $p['paid_at'] : $p['payroll_month'];
        } else {
            $creditCode = '2100';
            $creditText = 'Salary payable';
            $entryDate = $p['payroll_month'];
        }

        aePostJournal(
            $conn,
            $businessId,
            (int)$p['branch_id'],
            (string)$entryDate,
            'PAYROLL',
            (int)$p['id'],
            'Automatic payroll journal - ' . $p['payroll_no'],
            (int)$p['created_by'],
            [
                [
                    'code' => '5200',
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Payroll expense'
                ],
                [
                    'code' => $creditCode,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => $creditText
                ]
            ]
        );
        $count++;
    }

    return $count;
}

function aeSyncDepreciation(PDO $conn, int $businessId): int
{
    aeCancelMissingOrCancelled(
        $conn,
        $businessId,
        'DEPRECIATION',
        'asset_depreciations'
    );

    $stmt = $conn->prepare("
        SELECT *
        FROM asset_depreciations
        WHERE business_id = ?
          AND status = 'Posted'
        ORDER BY id
    ");
    $stmt->execute([$businessId]);

    $count = 0;

    while ($d = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amount = round((float)$d['depreciation_amount'], 2);

        aePostJournal(
            $conn,
            $businessId,
            (int)$d['branch_id'],
            (string)$d['depreciation_month'],
            'DEPRECIATION',
            (int)$d['id'],
            'Automatic depreciation journal - Asset #' . (int)$d['asset_id'],
            (int)$d['created_by'],
            [
                [
                    'code' => '5300',
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Depreciation expense'
                ],
                [
                    'code' => '1310',
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Accumulated depreciation'
                ]
            ]
        );
        $count++;
    }

    return $count;
}

/**
 * Main synchronization function.
 * Safe to run repeatedly: each source transaction has one journal reference.
 */
function syncAutomaticAccounting(PDO $conn, int $businessId): array
{
    if ($businessId <= 0) {
        throw new InvalidArgumentException('Invalid business ID.');
    }

    aeEnsureBusinessAccounts($conn, $businessId);

    $startedHere = !$conn->inTransaction();

    if ($startedHere) {
        $conn->beginTransaction();
    }

    try {
        $result = [
            'sales'            => aeSyncSales($conn, $businessId),
            'sales_returns'    => aeSyncSalesReturns($conn, $businessId),
            'purchases'        => aeSyncPurchases($conn, $businessId),
            'purchase_returns' => aeSyncPurchaseReturns($conn, $businessId),
            'expenses'         => aeSyncExpenses($conn, $businessId),
            'payroll'          => aeSyncPayroll($conn, $businessId),
            'depreciation'     => aeSyncDepreciation($conn, $businessId),
        ];

        if ($startedHere) {
            $conn->commit();
        }

        return $result;
    } catch (Throwable $e) {
        if ($startedHere && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
