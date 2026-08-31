<?php
/**
 * JSON API for the employee "My Vouchers" React desk.
 * Mirrors the list/filter logic of the admin desk but applies employee-scoped
 * permissions: restricted-voucher redaction and per-row edit/delete gating.
 */
require_once __DIR__ . '/../../../includes/functions.php';
$balancesFns = __DIR__ . '/../../../modules/balances/functions.php';
if (is_file($balancesFns)) {
    require_once $balancesFns;
}
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
if (function_exists('ensureSwiftDocumentColumn')) ensureSwiftDocumentColumn();
if (function_exists('ensurePostedColumnsOnPaymentVouchers')) ensurePostedColumnsOnPaymentVouchers();
if (function_exists('ensureRestrictedColumn')) ensureRestrictedColumn();
if (function_exists('ensureVoucherAttachmentsSchema')) ensureVoucherAttachmentsSchema();
if (function_exists('ensureApprovalsTableSchema')) ensureApprovalsTableSchema();
if (function_exists('ensureVoucherReferenceColumn')) ensureVoucherReferenceColumn();

try {
    if (!function_exists('tableExists') || !tableExists('payment_vouchers', $pdo)) {
        throw new RuntimeException('Payment vouchers table is not available for this company database.');
    }

    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(25, min(500, (int) $_GET['per_page'])) : 100;
    $offset = ($page - 1) * $per_page;

    $hasApprovalsTable = function_exists('tableExists') ? tableExists('approvals', $pdo) : true;
    $hasAttachmentsTable = function_exists('tableExists') ? tableExists('voucher_attachments', $pdo) : true;

    $status_filter = isset($_GET['status']) && $_GET['status'] !== 'all' ? (string) $_GET['status'] : '';
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $from_date = isset($_GET['from_date']) ? (string) $_GET['from_date'] : '';
    $to_date = isset($_GET['to_date']) ? (string) $_GET['to_date'] : '';

    $companyIdForFilters = (int) (currentCompanyId() ?? 0);
    $prefix_options = ($pdo instanceof PDO && function_exists('fetchPaymentVoucherPrefixFilterOptions'))
        ? fetchPaymentVoucherPrefixFilterOptions($pdo, $companyIdForFilters)
        : [];
    $configured_voucher_prefix = ($pdo instanceof PDO && function_exists('getCurrentPaymentVoucherSequencePrefix'))
        ? getCurrentPaymentVoucherSequencePrefix($pdo, $companyIdForFilters)
        : '';
    $prefix_explicit = array_key_exists('prefix', $_GET);
    $prefix_filter = $prefix_explicit ? trim((string) ($_GET['prefix'] ?? '')) : 'all';
    // Keep KPI drill-downs accurate: do not silently force the current sequence prefix.

    $where_conditions = [];
    $params = [];

    if (function_exists('companyScopeSql')) {
        list($scopeFrag, $scopeParams) = companyScopeSql('payment_vouchers', 'pv');
        if ($scopeFrag !== '') {
            $where_conditions[] = ltrim($scopeFrag, " \t\n\r\0\x0BAND");
            $params = array_merge($params, $scopeParams);
        }
    } else {
        $company_sql = getCompanySql('pv');
        if ($company_sql !== '') {
            $where_conditions[] = str_replace(' AND ', '', $company_sql);
            $params = array_merge($params, getCompanyParam());
        }
    }

    // Employees see only their own vouchers; admins see every voucher in scope.
    $isAdminViewer = function_exists('isAdmin') && isAdmin();
    if (!$isAdminViewer) {
        $where_conditions[] = 'pv.created_by = ?';
        $params[] = (int) ($_SESSION['user_id'] ?? 0);
    }

    if ($status_filter) {
        if ($status_filter === 'paid') {
            $where_conditions[] = 'IFNULL(pv.is_paid, 0) = 1 AND IFNULL(pv.is_posted, 0) = 0';
        } elseif ($status_filter === 'posted') {
            $where_conditions[] = 'IFNULL(pv.is_posted, 0) = 1';
        } elseif ($status_filter === 'draft') {
            $where_conditions[] = "pv.status IN ('pending', 'confirming') AND (COALESCE(pv.payee_name,'') = '' OR COALESCE(pv.total_amount,0) <= 0 OR NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id))";
        } elseif ($status_filter === 'pending') {
            $where_conditions[] = "pv.status IN ('pending', 'confirming') AND NOT (COALESCE(pv.payee_name,'') = '' OR COALESCE(pv.total_amount,0) <= 0 OR NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id))";
        } elseif ($status_filter === 'approved') {
            $where_conditions[] = "pv.status = 'approved' AND IFNULL(pv.is_paid, 0) = 0 AND IFNULL(pv.is_posted, 0) = 0";
        } else {
            $where_conditions[] = 'pv.status = ?';
            $params[] = $status_filter;
        }
    }

    if ($search !== '' && function_exists('buildPaymentVoucherSearchSql')) {
        $searchSql = buildPaymentVoucherSearchSql($search, $params, 'pv', 'u');
        if ($searchSql !== '') {
            $where_conditions[] = $searchSql;
        }
    }

    if ($from_date) {
        $where_conditions[] = 'DATE(pv.date_created) >= ?';
        $params[] = $from_date;
    }
    if ($to_date) {
        $where_conditions[] = 'DATE(pv.date_created) <= ?';
        $params[] = $to_date;
    }
    if ($prefix_filter !== '' && $prefix_filter !== 'all') {
        $where_conditions[] = 'pv.voucher_no LIKE ?';
        $params[] = $prefix_filter . '%';
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sort = isset($_GET['sort']) ? strtolower((string) $_GET['sort']) : 'newest';
    if (!in_array($sort, ['newest', 'asc', 'desc', 'voucher_no'], true)) {
        $sort = 'newest';
    }
    $order_by = function_exists('buildPaymentVoucherListOrderBySql')
        ? buildPaymentVoucherListOrderBySql($sort, 'pv')
        : 'ORDER BY pv.id DESC';

    $attachmentCountSql = $hasAttachmentsTable
        ? '(SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count'
        : '0 AS attachment_count';
    $pendingApprovalSql = $hasApprovalsTable
        ? '(SELECT id FROM approvals WHERE voucher_id = pv.id AND status = \'pending\' AND (approver_id = ' . (int) ($_SESSION['user_id'] ?? 0)
            . ' OR approver_name = ' . $pdo->quote((string) ($_SESSION['full_name'] ?? '')) . ') LIMIT 1) AS my_pending_approval_id'
        : 'NULL AS my_pending_approval_id';

    // Count
    $count_sql = "SELECT COUNT(*) FROM payment_vouchers pv LEFT JOIN users u ON pv.created_by = u.id $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_records = (int) ($stmt->fetchColumn() ?: 0);
    $total_pages = max(1, (int) ceil($total_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }

    $sql = "
        SELECT pv.id, pv.voucher_no, pv.payee_name, pv.total_amount, pv.status,
               pv.date_created, pv.currency, pv.created_at, pv.prepared_by, pv.description,
               pv.created_by,
               IFNULL(pv.is_paid,0) AS is_paid,
               IFNULL(pv.is_posted,0) AS is_posted,
               IFNULL(pv.is_reference,0) AS is_reference,
               IFNULL(pv.is_restricted,0) AS is_restricted,
               pv.approved_by,
               (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
               $attachmentCountSql,
               u.full_name as creator_name, u.department, ua.full_name as approver_name,
               (SELECT role FROM users WHERE id = pv.approved_by LIMIT 1) AS approver_role,
               $pendingApprovalSql
        FROM payment_vouchers pv
        LEFT JOIN users u ON pv.created_by = u.id
        LEFT JOIN users ua ON pv.approved_by = ua.id
        $where_clause
        $order_by
        LIMIT $per_page OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $myId = (int) ($_SESSION['user_id'] ?? 0);
    $uDept = strtolower(trim((string) ($_SESSION['department'] ?? '')));
    $isAdminUser = function_exists('isAdmin') ? isAdmin() : false;
    $canFinance = function_exists('isFinance') ? isFinance() : false;
    $canSeeRestricted = $isAdminUser || $canFinance || (preg_match('/(finance|accounts|accounting)/i', $uDept) === 1);
    $roleAdmin = defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin';
    $statusPending = defined('STATUS_PENDING') ? STATUS_PENDING : 'pending';
    $statusDraft = defined('STATUS_DRAFT') ? STATUS_DRAFT : 'draft';
    $statusConfirming = defined('STATUS_CONFIRMING') ? STATUS_CONFIRMING : 'confirming';
    $statusApproved = defined('STATUS_APPROVED') ? STATUS_APPROVED : 'approved';
    // Session-level flag (evaluated once, not per row) so we can compute edit/delete
    // permissions inline instead of running N+1 queries per voucher.
    $limitedEditEnabled = function_exists('isApprovedVoucherClassificationEditEnabled')
        ? isApprovedVoucherClassificationEditEnabled()
        : false;

    $vouchers = [];
    $sn = $offset + 1;
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
                empty($v['payee_name'])
                || $v['payee_name'] === '(Draft)'
                || (float) $v['total_amount'] <= 0
                || (int) ($v['item_count'] ?? 0) === 0
            );

        $derivedStatus = $v['status'];
        if ($looksDraft) {
            $derivedStatus = $statusDraft;
        }

        if ($isPostedFlag) {
            $displayStatus = 'Posted';
        } elseif ($isPaidFlag) {
            $displayStatus = 'Paid';
        } else {
            $displayStatus = ucfirst((string) $derivedStatus);
        }

        $approverRole = isset($v['approver_role']) ? (string) $v['approver_role'] : '';
        $approvedByAdmin = !empty($v['approved_by']) && $approverRole === $roleAdmin;
        $canMarkPaid = ($canFinance || $isAdminUser) && !$isPaidFlag && $statusLower === 'approved' && $approvedByAdmin;
        $canPost = $canFinance && $isPaidFlag && !$isPostedFlag;

        // Inline permission logic mirrors canEditVoucher / canLimitedEditApprovedVoucher /
        // canDeleteVoucher using row data already fetched above (avoids per-row queries).
        if ($isAdminUser) {
            $canEditFull = true;
        } elseif ($isRestricted) {
            $canEditFull = $canFinance || $isCreator;
        } elseif ($isPostedFlag) {
            $canEditFull = false;
        } elseif ($canFinance) {
            $canEditFull = true;
        } else {
            $canEditFull = in_array($statusLower, ['pending', 'confirming'], true);
        }

        $canLimitedEdit = false;
        if ($limitedEditEnabled && $statusLower === 'approved') {
            $canLimitedEdit = $isRestricted
                ? ($isAdminUser || $canFinance || $isCreator)
                : ($myId > 0);
        }
        $canEdit = $canEditFull || $canLimitedEdit;

        $canDelete = $statusLower !== $statusApproved && ($isAdminUser || $isCreator);

        $prep = trim((string) ($v['prepared_by'] ?? ''));
        if ($prep === '' && !empty($v['creator_name'])) {
            $prep = (string) $v['creator_name'];
        }

        $vouchers[] = [
            'sn' => $sn++,
            'id' => $vid,
            'voucher_no' => (string) ($v['voucher_no'] ?? ''),
            'payee_name' => $canView ? (string) ($v['payee_name'] ?? '') : '',
            'prepared_by' => $canView ? ($prep !== '' ? $prep : '-') : '',
            'department' => $canView ? (string) ($v['department'] ?? '') : '',
            'description' => $canView ? (string) ($v['description'] ?? '') : '',
            'currency' => (string) ($v['currency'] ?? ''),
            'total_amount' => (float) ($v['total_amount'] ?? 0),
            'date_created' => (string) ($v['date_created'] ?? ''),
            'created_at' => (string) ($v['created_at'] ?? ''),
            'status' => (string) ($v['status'] ?? ''),
            'display_status' => $displayStatus,
            'derived_status' => (string) $derivedStatus,
            'is_paid' => $isPaidFlag,
            'is_posted' => $isPostedFlag,
            'is_reference' => (int) ($v['is_reference'] ?? 0) === 1,
            'is_restricted' => $isRestricted,
            'item_count' => (int) ($v['item_count'] ?? 0),
            'attachment_count' => (int) ($v['attachment_count'] ?? 0),
            'my_pending_approval_id' => $v['my_pending_approval_id'] ?? null,
            'looks_draft' => $looksDraft,
            'can_view' => $canView,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'can_mark_paid' => $canMarkPaid,
            'can_post' => $canPost,
        ];
    }

    $prefixList = [];
    foreach ($prefix_options as $pfxOpt) {
        $pfxVal = (string) ($pfxOpt['value'] ?? '');
        if ($pfxVal === '') {
            continue;
        }
        $prefixList[] = [
            'value' => $pfxVal,
            'label' => (string) ($pfxOpt['label'] ?? $pfxVal),
        ];
    }

    // Active financial accounts for the Mark Paid modal.
    $payAccounts = [];
    try {
        $stmtAcc = $pdo->query("SELECT id, name, type, current_balance, currency FROM financial_accounts WHERE status = 'active' ORDER BY name ASC");
        foreach (($stmtAcc->fetchAll(PDO::FETCH_ASSOC) ?: []) as $acc) {
            $payAccounts[] = [
                'id' => (int) $acc['id'],
                'name' => (string) ($acc['name'] ?? ''),
                'currency' => (string) ($acc['currency'] ?? ''),
                'current_balance' => (float) ($acc['current_balance'] ?? 0),
            ];
        }
    } catch (Throwable $eAcc) {
        $payAccounts = [];
    }

    echo json_encode([
        'vouchers' => $vouchers,
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total_records' => $total_records,
            'total_pages' => $total_pages,
        ],
        'filters' => [
            'status' => $status_filter,
            'search' => $search,
            'from_date' => $from_date,
            'to_date' => $to_date,
            'prefix' => $prefix_filter,
            'sort' => $sort,
        ],
        'prefix_options' => $prefixList,
        'pay_accounts' => $payAccounts,
        'can_finance' => $canFinance,
        'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('employee/vouchers-ui/api/list.php failed: ' . $e->getMessage());
    http_response_code(500);
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $showDetail = in_array($host, ['localhost', '127.0.0.1'], true) || substr($host, -6) === '.local';
    echo json_encode([
        'error' => 'Unable to load vouchers right now.' . ($showDetail ? ' (' . $e->getMessage() . ')' : ''),
    ], JSON_UNESCAPED_SLASHES);
}
