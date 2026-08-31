<?php
/**
 * JSON API for the Admin Dashboard React app.
 * Company-scoped stats + recent vouchers with admin action flags.
 */
require_once __DIR__ . '/../../../includes/functions.php';
$balancesFns = __DIR__ . '/../../../modules/balances/functions.php';
if (is_file($balancesFns)) {
    require_once $balancesFns;
}
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
if (function_exists('ensurePaymentVouchersCoreSchema')) {
    ensurePaymentVouchersCoreSchema();
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

    $stmt = $pdo->prepare("SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN pv.status IN ('pending', 'confirming') AND NOT (
                COALESCE(pv.payee_name,'') = '' OR COALESCE(pv.total_amount,0) <= 0 OR
                NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id)
            ) THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN pv.status='approved' AND IFNULL(pv.is_paid,0) = 0 AND IFNULL(pv.is_posted,0) = 0 THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN pv.status='rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN IFNULL(pv.is_paid,0) = 1 AND IFNULL(pv.is_posted,0) = 0 THEN 1 ELSE 0 END) AS paid,
            SUM(CASE WHEN IFNULL(pv.is_posted,0) = 1 THEN 1 ELSE 0 END) AS posted,
            SUM(CASE WHEN LOWER(pv.status) IN ('pending', 'confirming') AND (
                COALESCE(pv.payee_name,'') = '' OR COALESCE(pv.total_amount,0) <= 0 OR
                NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id)
            ) THEN 1 ELSE 0 END) AS draft,
            SUM(CASE WHEN pv.status='approved' AND pv.currency='TZS' THEN pv.total_amount ELSE 0 END) AS approved_amount_tzs,
            SUM(CASE WHEN pv.status='approved' AND pv.currency='USD' THEN pv.total_amount ELSE 0 END) AS approved_amount_usd,
            SUM(pv.total_amount) AS total_value
        FROM payment_vouchers pv" . $companySql);
    $stmt->execute($companyParams);
    $statsRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats = [
        'total' => (int) ($statsRow['total'] ?? 0),
        'pending' => (int) ($statsRow['pending'] ?? 0),
        'approved' => (int) ($statsRow['approved'] ?? 0),
        'rejected' => (int) ($statsRow['rejected'] ?? 0),
        'paid' => (int) ($statsRow['paid'] ?? 0),
        'posted' => (int) ($statsRow['posted'] ?? 0),
        'draft' => (int) ($statsRow['draft'] ?? 0),
        'approved_amount_tzs' => (float) ($statsRow['approved_amount_tzs'] ?? 0),
        'approved_amount_usd' => (float) ($statsRow['approved_amount_usd'] ?? 0),
        'total_value' => (float) ($statsRow['total_value'] ?? 0),
    ];

    $currSql = "SELECT currency, SUM(total_amount) AS val FROM payment_vouchers pv" . $companySql . " GROUP BY currency";
    $stmt = $pdo->prepare($currSql);
    $stmt->execute($companyParams);
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

    $listWhere = $companySql !== '' ? $companySql : ' WHERE 1=1';
    $orderSql = function_exists('buildPaymentVoucherListOrderBySql')
        ? buildPaymentVoucherListOrderBySql('newest', 'pv')
        : 'ORDER BY pv.id DESC';

    $sql = "SELECT pv.id, pv.voucher_no, pv.payee_name, pv.total_amount, pv.status,
                pv.date_created, pv.currency, pv.created_at, pv.prepared_by, pv.description,
                pv.created_by,
                IFNULL(pv.is_paid,0) AS is_paid,
                IFNULL(pv.is_posted,0) AS is_posted,
                IFNULL(pv.is_restricted,0) AS is_restricted,
                IFNULL(pv.is_reference,0) AS is_reference,
                (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
                $attachmentCountSql,
                u.full_name AS creator_name, u.department
            FROM payment_vouchers pv
            LEFT JOIN users u ON pv.created_by = u.id
            $listWhere
            $orderSql
            LIMIT 2000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($companyParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        $looksDraft = !$isPaidFlag && !$isPostedFlag
            && in_array($statusLower, [$statusPending, $statusConfirming], true)
            && (
                empty($v['payee_name']) || $v['payee_name'] === '(Draft)'
                || (float) $v['total_amount'] <= 0 || (int) ($v['item_count'] ?? 0) === 0
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
            'created_at' => (string) ($v['created_at'] ?? ''),
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => $statusLower !== $statusApproved,
            'can_approve' => $canApprove,
            'can_reject' => $canReject,
            'can_mark_paid' => $canMarkPaid,
        ];
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
        $faStmt = $pdo->query("SELECT id, name, current_balance, currency, type FROM financial_accounts WHERE status = 'active' ORDER BY name ASC");
        if ($faStmt) {
            foreach ($faStmt->fetchAll(PDO::FETCH_ASSOC) as $acc) {
                $accounts[] = [
                    'id' => (int) ($acc['id'] ?? 0),
                    'name' => (string) ($acc['name'] ?? ''),
                    'current_balance' => (float) ($acc['current_balance'] ?? 0),
                    'currency' => (string) ($acc['currency'] ?? 'TZS'),
                    'type' => (string) ($acc['type'] ?? ''),
                ];
            }
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
