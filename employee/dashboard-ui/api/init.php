<?php
/**
 * JSON API for the employee Dashboard React app.
 * Mirrors the user-scoped stats/currency/recent/top-payee queries that the
 * legacy employee/dashboard.php page rendered server-side.
 */
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
if (function_exists('ensureSwiftDocumentColumn')) ensureSwiftDocumentColumn();
if (function_exists('ensurePostedColumnsOnPaymentVouchers')) ensurePostedColumnsOnPaymentVouchers();
if (function_exists('ensureRestrictedColumn')) ensureRestrictedColumn();
if (function_exists('ensureVoucherReferenceColumn')) ensureVoucherReferenceColumn();

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $ownOnly = isset($_GET['mine']) && (string) $_GET['mine'] === '1';

    $companySql = '';
    $companyParams = [];
    if (function_exists('companyScopeSql')) {
        list($scopeFrag, $companyParams) = companyScopeSql('payment_vouchers', '');
        if ($scopeFrag !== '') {
            $companySql = $scopeFrag;
        }
    } else {
        $companySql = getCompanySql();
        $companyParams = getCompanyParam();
    }

    $ownerSql = '';
    $ownerParams = [];
    if ($ownOnly && $userId > 0) {
        $ownerSql = ' AND created_by = ?';
        $ownerParams = [$userId];
    }

    // Headline stats (company scope; optionally only vouchers created by current user).
    $stmt = $pdo->prepare("SELECT
            COUNT(*) AS total,
            SUM(total_amount) AS total_value,
            SUM(CASE WHEN status='confirming' THEN 1 ELSE 0 END) AS confirming,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status='approved' AND IFNULL(is_paid,0) = 0 AND IFNULL(is_posted,0) = 0 THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN IFNULL(is_paid,0) = 1 AND IFNULL(is_posted,0) = 0 THEN 1 ELSE 0 END) AS paid,
            SUM(CASE WHEN IFNULL(is_posted,0) = 1 THEN 1 ELSE 0 END) AS posted
        FROM payment_vouchers WHERE 1=1" . $companySql . $ownerSql);
    $stmt->execute(array_merge($companyParams, $ownerParams));
    $statsRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats = [
        'total' => (int) ($statsRow['total'] ?? 0),
        'total_value' => (float) ($statsRow['total_value'] ?? 0),
        'confirming' => (int) ($statsRow['confirming'] ?? 0),
        'pending' => (int) ($statsRow['pending'] ?? 0),
        'approved' => (int) ($statsRow['approved'] ?? 0),
        'rejected' => (int) ($statsRow['rejected'] ?? 0),
        'paid' => (int) ($statsRow['paid'] ?? 0),
        'posted' => (int) ($statsRow['posted'] ?? 0),
    ];

    // Breakdown by currency.
    $stmt = $pdo->prepare("SELECT currency, SUM(total_amount) AS val FROM payment_vouchers WHERE 1=1" . $companySql . $ownerSql . " GROUP BY currency");
    $stmt->execute(array_merge($companyParams, $ownerParams));
    $currencyTotals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cur = (string) ($row['currency'] ?? '');
        if ($cur === '') {
            $cur = 'TZS';
        }
        $currencyTotals[$cur] = (float) ($row['val'] ?? 0);
    }

    // Voucher rows (company scope; optionally only vouchers created by current user).
    $hasAttachmentsTable = function_exists('tableExists') ? tableExists('voucher_attachments', $pdo) : true;
    $attachmentCountSql = $hasAttachmentsTable
        ? '(SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count'
        : '0 AS attachment_count';

    $listScopeSql = '';
    $listScopeParams = [];
    if (function_exists('companyScopeSql')) {
        list($listScopeFrag, $listScopeParams) = companyScopeSql('payment_vouchers', 'pv');
        $listScopeSql = $listScopeFrag;
    } else {
        $listScopeSql = getCompanySql('pv');
        $listScopeParams = getCompanyParam();
    }

    $listOwnerSql = '';
    $listOwnerParams = [];
    if ($ownOnly && $userId > 0) {
        $listOwnerSql = ' AND pv.created_by = ?';
        $listOwnerParams = [$userId];
    }

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
            WHERE 1=1" . $listScopeSql . $listOwnerSql . " "
        . (function_exists('buildPaymentVoucherListOrderBySql')
            ? buildPaymentVoucherListOrderBySql('newest', 'pv')
            : 'ORDER BY pv.id DESC');
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($listScopeParams, $listOwnerParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $myId = $userId;
    $uDept = strtolower(trim((string) ($_SESSION['department'] ?? '')));
    $isAdminUser = function_exists('isAdmin') ? isAdmin() : false;
    $canFinance = function_exists('isFinance') ? isFinance() : false;
    $canSeeRestricted = $isAdminUser || $canFinance || (preg_match('/(finance|accounts|accounting)/i', $uDept) === 1);
    $statusApproved = defined('STATUS_APPROVED') ? STATUS_APPROVED : 'approved';
    $statusPending = defined('STATUS_PENDING') ? STATUS_PENDING : 'pending';
    $statusDraft = defined('STATUS_DRAFT') ? STATUS_DRAFT : 'draft';
    $statusConfirming = defined('STATUS_CONFIRMING') ? STATUS_CONFIRMING : 'confirming';
    $limitedEditEnabled = function_exists('isApprovedVoucherClassificationEditEnabled')
        ? isApprovedVoucherClassificationEditEnabled() : false;

    $recent = [];
    $sn = 1;
    foreach ($rows as $v) {
        $vid = (int) $v['id'];
        $isPaidFlag = (int) ($v['is_paid'] ?? 0) === 1;
        $isPostedFlag = (int) ($v['is_posted'] ?? 0) === 1;
        $isRestricted = (int) ($v['is_restricted'] ?? 0) === 1;
        $isCreator = ((int) ($v['created_by'] ?? 0) === $myId);
        $statusLower = strtolower((string) ($v['status'] ?? ''));

        $canView = $isRestricted ? ($canSeeRestricted || $isCreator) : true;

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

        // Inline edit/delete permissions mirroring the my-vouchers desk.
        if ($isAdminUser) {
            $canEditFull = true;
        } elseif ($isRestricted) {
            $canEditFull = $canFinance || $isCreator;
        } elseif ($isPostedFlag) {
            $canEditFull = false;
        } elseif ($canFinance) {
            $canEditFull = true;
        } else {
            $canEditFull = $isCreator && in_array($statusLower, ['pending', 'confirming'], true);
        }
        $canLimitedEdit = false;
        if ($limitedEditEnabled && $statusLower === 'approved') {
            $canLimitedEdit = $isRestricted ? ($isAdminUser || $canFinance || $isCreator) : ($myId > 0);
        }
        $canEdit = $canEditFull || $canLimitedEdit;
        $canDelete = $statusLower !== $statusApproved && ($isAdminUser || $isCreator);

        $prep = trim((string) ($v['prepared_by'] ?? ''));
        if ($prep === '' && !empty($v['creator_name'])) {
            $prep = (string) $v['creator_name'];
        }

        $recent[] = [
            'sn' => $sn++,
            'id' => $vid,
            'voucher_no' => (string) ($v['voucher_no'] ?? ''),
            'payee_name' => $canView ? (string) ($v['payee_name'] ?? '') : '',
            'prepared_by' => $canView ? ($prep !== '' ? $prep : '-') : '',
            'department' => $canView ? (string) ($v['department'] ?? '') : '',
            'description' => $canView ? (string) ($v['description'] ?? '') : '',
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
            'can_view' => $canView,
            'can_edit' => $canView && $canEdit,
            'can_delete' => $canView && $canDelete,
        ];
    }

    // Top payees (same scope as the list).
    $tpSql = "SELECT payee_name, SUM(total_amount) AS total FROM payment_vouchers WHERE 1=1" . $companySql . $ownerSql
        . " AND (status='approved' OR is_paid=1) GROUP BY payee_name ORDER BY total DESC LIMIT 5";
    $stmt = $pdo->prepare($tpSql);
    $stmt->execute(array_merge($companyParams, $ownerParams));
    $topPayees = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tp) {
        $topPayees[] = [
            'payee_name' => (string) ($tp['payee_name'] ?? ''),
            'total' => (float) ($tp['total'] ?? 0),
        ];
    }

    $approvalRate = $stats['total'] > 0 ? (int) round(($stats['approved'] / $stats['total']) * 100) : 0;

    $fullName = (string) ($_SESSION['full_name'] ?? '');
    $firstName = $fullName !== '' ? explode(' ', trim($fullName))[0] : '';

    echo json_encode([
        'user_first_name' => $firstName,
        'stats' => $stats,
        'currency_totals' => $currencyTotals,
        'recent' => $recent,
        'top_payees' => $topPayees,
        'approval_rate' => $approvalRate,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('employee/dashboard-ui/api/init.php failed: ' . $e->getMessage());
    http_response_code(500);
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $showDetail = in_array($host, ['localhost', '127.0.0.1'], true) || substr($host, -6) === '.local';
    echo json_encode([
        'error' => 'Unable to load the dashboard right now.' . ($showDetail ? ' (' . $e->getMessage() . ')' : ''),
    ], JSON_UNESCAPED_SLASHES);
}
