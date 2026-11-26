<?php
/**
 * JSON API for the Admin Dashboard React app.
 * Company-scoped stats + recent vouchers with admin action flags.
 */
if (!defined('ERP_FORCE_JSON_RESPONSE')) {
    define('ERP_FORCE_JSON_RESPONSE', true);
}
require_once __DIR__ . '/../../../includes/functions.php';
$balancesFns = __DIR__ . '/../../../modules/balances/functions.php';
if (is_file($balancesFns)) {
    require_once $balancesFns;
}
requireAdmin();
// Release the session lock so parallel desk/notifications requests do not block or race.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
if (function_exists('ensurePaymentVouchersCoreSchema')) {
    ensurePaymentVouchersCoreSchema();
}
if (function_exists('ensurePaymentVoucherIsDraftColumn')) {
    ensurePaymentVoucherIsDraftColumn();
}
if (function_exists('ensureSwiftDocumentColumn')) {
    ensureSwiftDocumentColumn();
}
if (function_exists('ensurePostedColumnsOnPaymentVouchers')) {
    ensurePostedColumnsOnPaymentVouchers();
}
if (function_exists('ensureRestrictedColumn')) {
    ensureRestrictedColumn();
}
if (function_exists('ensureVoucherReferenceColumn')) {
    ensureVoucherReferenceColumn();
}
if (function_exists('ensureVoucherAttachmentsSchema')) {
    ensureVoucherAttachmentsSchema();
}

try {
    if (!function_exists('tableExists') || !tableExists('payment_vouchers', $pdo)) {
        throw new RuntimeException('Payment vouchers table is not available for this company database.');
    }

    $companySql = '';
    $companyParams = [];
    if (function_exists('companyScopeSql')) {
        list($scopeFrag, $companyParams) = companyScopeSql('payment_vouchers', 'pv');
        if ($scopeFrag !== '') {
            $companySql = ' WHERE 1=1' . $scopeFrag;
        }
    } else {
        $companySql = ' WHERE 1=1' . getCompanySql('pv');
        $companyParams = getCompanyParam();
    }

    $privateDraftSql = '';
    $privateDraftParams = [];
    if (function_exists('paymentVoucherHideOthersPrivateDraftsSql')) {
        $privateDraftSql = paymentVoucherHideOthersPrivateDraftsSql('pv', $privateDraftParams);
    }
    $statsWhere = ($companySql !== '' ? $companySql : ' WHERE 1=1') . $privateDraftSql;

    $stmt = $pdo->prepare("SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN pv.status='pending' AND IFNULL(pv.is_paid,0) = 0 AND IFNULL(pv.is_posted,0) = 0 AND NOT (
                COALESCE(pv.payee_name,'') = '' OR COALESCE(pv.total_amount,0) <= 0 OR
                NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id)
            ) THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN pv.status='confirming' AND IFNULL(pv.is_paid,0) = 0 AND IFNULL(pv.is_posted,0) = 0 AND NOT (
                COALESCE(pv.payee_name,'') = '' OR COALESCE(pv.total_amount,0) <= 0 OR
                NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id)
            ) THEN 1 ELSE 0 END) AS confirming,
            SUM(CASE WHEN pv.status='approved' AND IFNULL(pv.is_paid,0) = 0 AND IFNULL(pv.is_posted,0) = 0 THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN pv.status='rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN IFNULL(pv.is_paid,0) = 1 AND IFNULL(pv.is_posted,0) = 0 THEN 1 ELSE 0 END) AS paid,
            SUM(CASE WHEN IFNULL(pv.is_posted,0) = 1 THEN 1 ELSE 0 END) AS posted,
            SUM(CASE WHEN LOWER(pv.status) = 'draft' OR IFNULL(pv.is_draft,0) = 1 OR (LOWER(pv.status) IN ('pending', 'confirming') AND (
                COALESCE(pv.payee_name,'') = '' OR COALESCE(pv.total_amount,0) <= 0 OR
                NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id)
            )) THEN 1 ELSE 0 END) AS draft,
            SUM(CASE WHEN pv.status='approved' AND pv.currency='TZS' THEN pv.total_amount ELSE 0 END) AS approved_amount_tzs,
            SUM(CASE WHEN pv.status='approved' AND pv.currency='USD' THEN pv.total_amount ELSE 0 END) AS approved_amount_usd,
            SUM(pv.total_amount) AS total_value
        FROM payment_vouchers pv" . $statsWhere);
    $stmt->execute(array_merge($companyParams, $privateDraftParams));
    $statsRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Credit work queue: unpaid, unposted PVs with a credit/payable line item.
    // Paid credit vouchers are excluded here so they count as normal paid PVs.
    $creditCount = 0;
    $creditUnpaid = 0;
    $creditAmountTzs = 0.0;
    $creditAmountUsd = 0.0;
    $creditUnpaidAmountTzs = 0.0;
    $creditUnpaidAmountUsd = 0.0;
    try {
        $creditWhere = ($companySql !== '' ? $companySql : ' WHERE 1=1') . $privateDraftSql
            . " AND IFNULL(pv.is_paid, 0) = 0
                AND IFNULL(pv.is_posted, 0) = 0
                AND EXISTS (
                    SELECT 1 FROM voucher_items vi
                    WHERE vi.voucher_id = pv.id
                      AND (
                        LOWER(vi.payment_type) LIKE '%credit%'
                        OR LOWER(vi.payment_type) LIKE '%payable%'
                      )
                )";
        $creditStmt = $pdo->prepare(
            'SELECT
                COUNT(DISTINCT pv.id) AS credit_unpaid,
                COALESCE(SUM(CASE
                    WHEN UPPER(TRIM(IFNULL(pv.currency, \'TZS\'))) = \'USD\' THEN 0
                    ELSE pv.total_amount
                END), 0) AS credit_unpaid_amount_tzs,
                COALESCE(SUM(CASE
                    WHEN UPPER(TRIM(IFNULL(pv.currency, \'TZS\'))) = \'USD\' THEN pv.total_amount
                    ELSE 0
                END), 0) AS credit_unpaid_amount_usd
             FROM payment_vouchers pv' . $creditWhere
        );
        $creditStmt->execute(array_merge($companyParams, $privateDraftParams));
        $creditRow = $creditStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $creditUnpaid = (int) ($creditRow['credit_unpaid'] ?? 0);
        $creditCount = $creditUnpaid;
        $creditAmountTzs = (float) ($creditRow['credit_unpaid_amount_tzs'] ?? 0);
        $creditAmountUsd = (float) ($creditRow['credit_unpaid_amount_usd'] ?? 0);
        $creditUnpaidAmountTzs = $creditAmountTzs;
        $creditUnpaidAmountUsd = $creditAmountUsd;
    } catch (Throwable $e) {
        $creditCount = 0;
        $creditUnpaid = 0;
        $creditAmountTzs = 0.0;
        $creditAmountUsd = 0.0;
        $creditUnpaidAmountTzs = 0.0;
        $creditUnpaidAmountUsd = 0.0;
    }

    $stats = [
        'total' => (int) ($statsRow['total'] ?? 0),
        'pending' => (int) ($statsRow['pending'] ?? 0),
        'confirming' => (int) ($statsRow['confirming'] ?? 0),
        'approved' => (int) ($statsRow['approved'] ?? 0),
        'rejected' => (int) ($statsRow['rejected'] ?? 0),
        'paid' => (int) ($statsRow['paid'] ?? 0),
        'posted' => (int) ($statsRow['posted'] ?? 0),
        'draft' => (int) ($statsRow['draft'] ?? 0),
        'credit' => $creditCount,
        'credit_unpaid' => $creditUnpaid,
        'credit_amount_tzs' => $creditAmountTzs,
        'credit_amount_usd' => $creditAmountUsd,
        'credit_unpaid_amount_tzs' => $creditUnpaidAmountTzs,
        'credit_unpaid_amount_usd' => $creditUnpaidAmountUsd,
        'approved_amount_tzs' => (float) ($statsRow['approved_amount_tzs'] ?? 0),
        'approved_amount_usd' => (float) ($statsRow['approved_amount_usd'] ?? 0),
        'total_value' => (float) ($statsRow['total_value'] ?? 0),
    ];

    $currSql = "SELECT currency, SUM(total_amount) AS val FROM payment_vouchers pv" . $statsWhere . " GROUP BY currency";
    $stmt = $pdo->prepare($currSql);
    $stmt->execute(array_merge($companyParams, $privateDraftParams));
    $currencyTotals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cur = (string) ($row['currency'] ?? '');
        if ($cur === '') {
            $cur = 'TZS';
        }
        $currencyTotals[$cur] = (float) ($row['val'] ?? 0);
    }

    $hasAttachmentsTable = function_exists('tableExists') ? tableExists('voucher_attachments', $pdo) : true;
    $attachmentCountSql = $hasAttachmentsTable
        ? '(SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count'
        : '0 AS attachment_count';

    $purposeSelectSql = '';
    foreach (['purpose', 'payment_purpose', 'voucher_purpose'] as $purposeCol) {
        if (function_exists('columnExists') && columnExists('payment_vouchers', $purposeCol, $pdo)) {
            $purposeSelectSql .= ", pv.`{$purposeCol}`";
        }
    }

    $listWhere = ($companySql !== '' ? $companySql : ' WHERE 1=1') . $privateDraftSql;
    $orderSql = function_exists('buildPaymentVoucherListOrderBySql')
        ? buildPaymentVoucherListOrderBySql('newest', 'pv')
        : 'ORDER BY pv.id DESC';

    $sql = "SELECT pv.id, pv.voucher_no, pv.payee_name, pv.total_amount, pv.status,
                pv.date_created, pv.due_date, pv.currency, pv.created_at, pv.prepared_by, pv.description,
                pv.created_by,
                IFNULL(pv.is_paid,0) AS is_paid,
                IFNULL(pv.is_posted,0) AS is_posted,
                IFNULL(pv.is_restricted,0) AS is_restricted,
                IFNULL(pv.is_reference,0) AS is_reference,
                IFNULL(pv.is_draft,0) AS is_draft,
                (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
                (
                    SELECT vi.payment_type FROM voucher_items vi
                    WHERE vi.voucher_id = pv.id
                    ORDER BY vi.id ASC LIMIT 1
                ) AS item_payment_type,
                (
                    IFNULL(pv.is_paid, 0) = 0
                    AND IFNULL(pv.is_posted, 0) = 0
                    AND EXISTS (
                        SELECT 1 FROM voucher_items vi
                        WHERE vi.voucher_id = pv.id
                          AND (
                            LOWER(vi.payment_type) LIKE '%credit%'
                            OR LOWER(vi.payment_type) LIKE '%payable%'
                          )
                    )
                ) AS is_credit,
                $attachmentCountSql
                $purposeSelectSql,
                u.full_name AS creator_name, u.department
            FROM payment_vouchers pv
            LEFT JOIN users u ON pv.created_by = u.id
            $listWhere
            $orderSql
            LIMIT 2000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($companyParams, $privateDraftParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $classificationsByVoucher = [];
    $voucherIds = array_values(array_filter(array_map(
        static fn($row) => (int) ($row['id'] ?? 0),
        $rows
    )));
    if ($voucherIds !== [] && function_exists('tableExists') && tableExists('voucher_items', $pdo)) {
        $itemColumns = [];
        foreach (['payment_type', 'budget_type'] as $column) {
            $itemColumns[] = function_exists('columnExists') && columnExists('voucher_items', $column, $pdo)
                ? "vi.`{$column}`"
                : "'' AS `{$column}`";
        }
        foreach (array_chunk($voucherIds, 500) as $voucherIdChunk) {
            $placeholders = implode(',', array_fill(0, count($voucherIdChunk), '?'));
            $itemStmt = $pdo->prepare(
                'SELECT vi.voucher_id, ' . implode(', ', $itemColumns)
                . " FROM voucher_items vi WHERE vi.voucher_id IN ({$placeholders})"
            );
            $itemStmt->execute($voucherIdChunk);
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $voucherId = (int) ($item['voucher_id'] ?? 0);
                if ($voucherId <= 0) {
                    continue;
                }
                $classificationsByVoucher[$voucherId] ??= ['payment_types' => [], 'budget_types' => []];
                foreach (['payment_type' => 'payment_types', 'budget_type' => 'budget_types'] as $column => $key) {
                    $value = trim((string) ($item[$column] ?? ''));
                    if ($value !== '' && !in_array($value, $classificationsByVoucher[$voucherId][$key], true)) {
                        $classificationsByVoucher[$voucherId][$key][] = $value;
                    }
                }
            }
        }
    }

    $statusApproved = defined('STATUS_APPROVED') ? STATUS_APPROVED : 'approved';
    $statusPending = defined('STATUS_PENDING') ? STATUS_PENDING : 'pending';
    $statusDraft = defined('STATUS_DRAFT') ? STATUS_DRAFT : 'draft';
    $statusConfirming = defined('STATUS_CONFIRMING') ? STATUS_CONFIRMING : 'confirming';

    $recent = [];
    $sn = 1;
    foreach ($rows as $v) {
        $vid = (int) $v['id'];
        $isPaidFlag = (int) ($v['is_paid'] ?? 0) === 1;
        $isPostedFlag = (int) ($v['is_posted'] ?? 0) === 1;
        $isRestricted = (int) ($v['is_restricted'] ?? 0) === 1;
        $statusLower = strtolower((string) ($v['status'] ?? ''));

        $looksDraft = ((int) ($v['is_draft'] ?? 0) === 1)
            || $statusLower === $statusDraft
            || $statusLower === 'draft'
            || (
                !$isPaidFlag && !$isPostedFlag
                && in_array($statusLower, [$statusPending, $statusConfirming], true)
                && (
                    empty($v['payee_name']) || $v['payee_name'] === '(Draft)'
                    || (float) $v['total_amount'] <= 0 || (int) ($v['item_count'] ?? 0) === 0
                )
            );
        $derivedStatus = $looksDraft ? $statusDraft : $v['status'];
        if ($isPostedFlag) {
            $displayStatus = 'Posted';
        } elseif ($isPaidFlag) {
            $displayStatus = 'Paid';
        } else {
            $displayStatus = ucfirst((string) $derivedStatus);
        }

        $prep = trim((string) ($v['prepared_by'] ?? ''));
        if ($prep === '' && !empty($v['creator_name'])) {
            $prep = (string) $v['creator_name'];
        }

        $canApprove = !$looksDraft && !$isPaidFlag && in_array($statusLower, [$statusPending, $statusConfirming], true);
        $canReject = !$isPaidFlag && in_array($statusLower, [$statusPending, $statusConfirming, $statusApproved], true)
            && $statusLower !== 'rejected';
        $canMarkPaid = !$isPaidFlag && $statusLower === $statusApproved;

        $recent[] = [
            'sn' => $sn++,
            'id' => $vid,
            'voucher_no' => (string) ($v['voucher_no'] ?? ''),
            'payee_name' => (string) ($v['payee_name'] ?? ''),
            'prepared_by' => $prep !== '' ? $prep : '-',
            'department' => (string) ($v['department'] ?? ''),
            'description' => (string) ($v['description'] ?? ''),
            'status' => (string) ($v['status'] ?? ''),
            'display_status' => $displayStatus,
            'derived_status' => (string) $derivedStatus,
            'is_paid' => $isPaidFlag,
            'is_posted' => $isPostedFlag,
            'is_restricted' => $isRestricted,
            'is_reference' => (int) ($v['is_reference'] ?? 0) === 1,
            'attachment_count' => (int) ($v['attachment_count'] ?? 0),
            'total_amount' => (float) ($v['total_amount'] ?? 0),
            'currency' => (string) ($v['currency'] ?? ''),
            'date_created' => (string) ($v['date_created'] ?? ''),
            'due_date' => (string) ($v['due_date'] ?? ''),
            'created_at' => (string) ($v['created_at'] ?? ''),
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => $statusLower !== $statusApproved,
            'can_approve' => $canApprove,
            'can_reject' => $canReject,
            'can_mark_paid' => $canMarkPaid,
            'purpose' => function_exists('resolvePaymentVoucherPurposeFromRow')
                ? resolvePaymentVoucherPurposeFromRow($v)
                : 'general',
            'payment_type' => (string) ($v['item_payment_type'] ?? ''),
            'is_credit' => (int) ($v['is_credit'] ?? 0) === 1,
            'payment_types' => $classificationsByVoucher[$vid]['payment_types'] ?? [],
            'budget_types' => $classificationsByVoucher[$vid]['budget_types'] ?? [],
            'paid_so_far' => 0.0,
            'prior_payments' => [],
            'display_status_key' => $isPostedFlag
                ? 'posted'
                : ($isPaidFlag ? 'paid' : strtolower((string) $derivedStatus)),
            'payment_execution_status' => '',
        ];
    }

    // Balances may live on a different connection than vouchers; payment pickers and
    // partial-payment totals must come from the same PDO the stock payment endpoint posts to.
    $balancesPdo = function_exists('balances_resolve_pdo') ? balances_resolve_pdo() : null;
    if (!($balancesPdo instanceof PDO)) {
        $balancesPdo = $pdo;
    }

    // Prior installment totals + line details for any unpaid approved voucher.
    $openPayIds = [];
    foreach ($recent as $idx => $r) {
        if (!empty($r['can_mark_paid'])) {
            $openPayIds[(int) $r['id']] = $idx;
        }
    }
    if ($openPayIds !== [] && function_exists('tableExists') && tableExists('account_transactions', $balancesPdo)) {
        try {
            $ph = implode(',', array_fill(0, count($openPayIds), '?'));
            $paidStmt = $balancesPdo->prepare(
                "SELECT reference_id, COALESCE(SUM(amount), 0) AS paid FROM account_transactions
                 WHERE reference_type = 'payment_voucher' AND type = 'debit' AND reference_id IN ($ph)
                 GROUP BY reference_id"
            );
            $paidStmt->execute(array_keys($openPayIds));
            foreach ($paidStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $rid = (int) ($pr['reference_id'] ?? 0);
                if (isset($openPayIds[$rid])) {
                    $recent[$openPayIds[$rid]]['paid_so_far'] = (float) ($pr['paid'] ?? 0);
                }
            }

            $hasAccounts = tableExists('financial_accounts', $balancesPdo);
            $detailSql = $hasAccounts
                ? "SELECT t.reference_id, t.amount, t.transaction_date, t.description,
                          COALESCE(a.name, '') AS account_name, COALESCE(a.type, '') AS account_type
                   FROM account_transactions t
                   LEFT JOIN financial_accounts a ON a.id = t.account_id
                   WHERE t.reference_type = 'payment_voucher' AND t.type = 'debit' AND t.reference_id IN ($ph)
                   ORDER BY t.transaction_date ASC, t.id ASC"
                : "SELECT t.reference_id, t.amount, t.transaction_date, t.description,
                          '' AS account_name, '' AS account_type
                   FROM account_transactions t
                   WHERE t.reference_type = 'payment_voucher' AND t.type = 'debit' AND t.reference_id IN ($ph)
                   ORDER BY t.transaction_date ASC, t.id ASC";
            $detailStmt = $balancesPdo->prepare($detailSql);
            $detailStmt->execute(array_keys($openPayIds));
            foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rid = (int) ($row['reference_id'] ?? 0);
                if (!isset($openPayIds[$rid])) {
                    continue;
                }
                $txDate = substr(trim((string) ($row['transaction_date'] ?? '')), 0, 10);
                $recent[$openPayIds[$rid]]['prior_payments'][] = [
                    'amount' => (float) ($row['amount'] ?? 0),
                    'date' => $txDate,
                    'account_name' => (string) ($row['account_name'] ?? ''),
                    'account_type' => (string) ($row['account_type'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                ];
            }

            // Partial installments stay is_paid=0; surface Partially paid from ledger totals.
            foreach ($openPayIds as $vid => $idx) {
                $paidSoFar = (float) ($recent[$idx]['paid_so_far'] ?? 0);
                $totalAmt = (float) ($recent[$idx]['total_amount'] ?? 0);
                if ($paidSoFar <= 0.009 || !empty($recent[$idx]['is_paid'])) {
                    continue;
                }
                if ($totalAmt > 0 && $paidSoFar >= ($totalAmt - 0.009)) {
                    continue;
                }
                $recent[$idx]['display_status'] = 'Partially paid';
                $recent[$idx]['display_status_key'] = 'partially_paid';
                $recent[$idx]['payment_execution_status'] = 'partially_paid';
            }
        } catch (Throwable $ePaid) {
            // optional
        }
    }

    $tpSql = "SELECT payee_name, SUM(total_amount) AS total FROM payment_vouchers pv"
        . ($companySql !== '' ? $companySql . ' AND' : ' WHERE')
        . " (status='approved' OR is_paid=1) GROUP BY payee_name ORDER BY total DESC LIMIT 5";
    // Fix AND/WHERE when companySql already has WHERE
    if ($companySql !== '') {
        $tpSql = "SELECT payee_name, SUM(total_amount) AS total FROM payment_vouchers pv"
            . $companySql . " AND (status='approved' OR is_paid=1) GROUP BY payee_name ORDER BY total DESC LIMIT 5";
    }
    $stmt = $pdo->prepare($tpSql);
    $stmt->execute($companyParams);
    $topPayees = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tp) {
        $topPayees[] = [
            'payee_name' => (string) ($tp['payee_name'] ?? ''),
            'total' => (float) ($tp['total'] ?? 0),
        ];
    }

    $accounts = [];
    try {
        $accRows = function_exists('balancesFetchAccountsWithLiveBalance')
            ? balancesFetchAccountsWithLiveBalance($balancesPdo, true)
            : [];
        if ($accRows === []) {
            $faStmt = $balancesPdo->query("SELECT * FROM financial_accounts WHERE status = 'active' ORDER BY name ASC");
            $accRows = $faStmt ? ($faStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }
        foreach ($accRows as $acc) {
            if (function_exists('balancesAccountLiquidityBucket')
                && !in_array(balancesAccountLiquidityBucket((string) ($acc['type'] ?? '')), ['cash', 'bank', 'mobile'], true)) {
                continue;
            }
            $accounts[] = [
                'id' => (int) ($acc['id'] ?? 0),
                'name' => (string) ($acc['name'] ?? ''),
                'current_balance' => (float) ($acc['live_balance'] ?? $acc['current_balance'] ?? 0),
                'currency' => (string) ($acc['currency'] ?? 'TZS'),
                'type' => (string) ($acc['type'] ?? ''),
                'image_url' => function_exists('balances_account_logo_url')
                    ? balances_account_logo_url($acc, $balancesPdo)
                    : '',
            ];
        }
    } catch (Throwable $eAcc) {
        // optional
    }

    $fullName = (string) ($_SESSION['full_name'] ?? '');
    $firstName = $fullName !== '' ? explode(' ', trim($fullName))[0] : '';
    $approvalRate = $stats['total'] > 0 ? (int) round(($stats['approved'] / $stats['total']) * 100) : 0;

    echo json_encode([
        'user_first_name' => $firstName,
        'stats' => $stats,
        'currency_totals' => $currencyTotals,
        'recent' => $recent,
        'top_payees' => $topPayees,
        'accounts' => $accounts,
        'approval_rate' => $approvalRate,
        'payables_url' => app_url('/view-voucher-ui/api/voucher/send-to-payables.php'),
        'csrf_token' => csrf_token(),
        'role' => 'admin',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('admin/dashboard-ui/api/init.php failed: ' . $e->getMessage());
    http_response_code(500);
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $showDetail = in_array($host, ['localhost', '127.0.0.1'], true) || substr($host, -6) === '.local';
    echo json_encode([
        'error' => 'Unable to load the dashboard right now.' . ($showDetail ? ' (' . $e->getMessage() . ')' : ''),
    ], JSON_UNESCAPED_SLASHES);
}
