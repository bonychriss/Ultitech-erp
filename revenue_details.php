<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/revenue_ledger.php';
require_once __DIR__ . '/includes/accounting_service.php';

requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}

$revenueListUrl = function_exists('company_url')
    ? company_url('revenue_entries.php') . '?module=revenue'
    : 'revenue_entries.php?module=revenue';

$entryId = (int)($_GET['id'] ?? 0);
if ($entryId <= 0) {
    header('Location: ' . $revenueListUrl . '&error=invalid_id');
    exit();
}

// Fetch revenue entry with customer and invoice info
$stmt = $pdo->prepare("
    SELECT re.*, 
           i.invoice_number AS linked_invoice_number, i.invoice_date, i.due_date AS invoice_due_date,
           cust.company_name AS resolved_company_name, cust.customer_code AS resolved_customer_code,
           u.full_name AS creator_name,
           app.full_name AS approver_name
    FROM revenue_entries re
    LEFT JOIN invoices i ON i.id = re.source_invoice_id
    LEFT JOIN customers cust ON cust.id = i.customer_id
    LEFT JOIN users u ON u.id = re.approved_by -- Assuming approved_by tracks actions, might need created_by
    LEFT JOIN users app ON app.id = re.approved_by
    WHERE re.id = ?
    LIMIT 1
");
$stmt->execute([$entryId]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    header('Location: ' . $revenueListUrl . '&error=not_found');
    exit();
}

// Format values
$voucherId = $entry['voucher_number'] ?? 'N/A';
$status = $entry['approval_status'] ?? 'Pending';
$paymentStatus = $entry['payment_status'] ?? 'Unpaid';
$amountNet = (float)($entry['amount_exclusive'] ?? 0);
$amountVat = (float)($entry['vat_amount'] ?? 0);
$amountTotal = (float)($entry['amount_total'] ?? 0);
$amountPaid = (float)($entry['total_paid'] ?? 0);
$balance = $amountTotal - $amountPaid;

// Ensure erp_journal_entries schema compatibility
try {
    $pdo->exec("ALTER TABLE erp_journal_entries ADD COLUMN IF NOT EXISTS reference VARCHAR(100) DEFAULT NULL");
} catch (Throwable $e) {
    // If IF NOT EXISTS is not supported, try manual check
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM erp_journal_entries")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('reference', $cols)) {
            $pdo->exec("ALTER TABLE erp_journal_entries ADD COLUMN reference VARCHAR(100) DEFAULT NULL");
        }
    } catch (Throwable $e2) {}
}

// Fetch Ledger Entries by reference (voucher number)
$ledgerItems = [];
if ($voucherId !== 'N/A') {
    // Determine correct reference column (reference vs entry_number)
    $refCol = resolveExistingColumn('erp_journal_entries', 'reference', ['entry_number', 'ref_no', 'voucher_no']) ?: 'reference';
    
    $stmtLedger = $pdo->prepare("
        SELECT ji.*, a.name AS account_name, a.code AS account_code, je.date AS j_date
        FROM erp_journal_items ji
        JOIN erp_journal_entries je ON je.id = ji.journal_id
        JOIN erp_accounts a ON a.id = ji.account_id
        WHERE je.{$refCol} = ?
        ORDER BY je.id ASC, ji.debit DESC, ji.credit ASC
    ");
    $stmtLedger->execute([$voucherId]);
    $ledgerItems = $stmtLedger->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch attachments (preferred: generic attachments table, fallback: revenue_entries.attachment)
$attachments = [];
try {
    $stmtAtt = $pdo->prepare("
        SELECT a.id, a.file_name, a.file_path, a.file_size, a.uploaded_at
        FROM attachments a
        WHERE a.related_type = 'revenue_entry' AND a.related_id = ?
        ORDER BY a.uploaded_at DESC
    ");
    $stmtAtt->execute([$entryId]);
    $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $attachments = [];
}
if (!$attachments && !empty($entry['attachment'])) {
    $attachments[] = [
        'id' => 0,
        'file_name' => basename((string) $entry['attachment']),
        'file_path' => (string) $entry['attachment'],
        'file_size' => null,
        'uploaded_at' => (string) ($entry['created_at'] ?? ''),
    ];
}

// Notes: prefer generic notes table, fallback legacy revenue_notes
$notes = [];
try {
    $stmtNotes = $pdo->prepare("
        SELECT n.id, n.note, n.created_at, COALESCE(u.full_name, 'System') AS user_name
        FROM notes n
        LEFT JOIN users u ON u.id = n.created_by
        WHERE n.related_type = 'revenue_entry' AND n.related_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmtNotes->execute([$entryId]);
    $notes = $stmtNotes->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `revenue_notes` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `entry_id` int(11) NOT NULL,
          `user_id` int(11) NOT NULL,
          `note` text NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `entry_id` (`entry_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $stmtNotesLegacy = $pdo->prepare("
            SELECT rn.id, rn.note, rn.created_at, COALESCE(u.full_name, 'System') AS user_name
            FROM revenue_notes rn
            LEFT JOIN users u ON u.id = rn.user_id
            WHERE rn.entry_id = ?
            ORDER BY rn.created_at DESC
        ");
        $stmtNotesLegacy->execute([$entryId]);
        $notes = $stmtNotesLegacy->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        $notes = [];
    }
}

// Handle Add Note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $noteText = trim((string)$_POST['note']);
    if ($noteText !== '') {
        try {
            $stmtAddNote = $pdo->prepare("INSERT INTO notes (related_type, related_id, note, created_by, created_at) VALUES ('revenue_entry', ?, ?, ?, NOW())");
            $stmtAddNote->execute([$entryId, $noteText, (int) $_SESSION['user_id']]);
        } catch (Throwable $e) {
            $stmtAddNoteLegacy = $pdo->prepare("INSERT INTO revenue_notes (entry_id, user_id, note) VALUES (?, ?, ?)");
            $stmtAddNoteLegacy->execute([$entryId, (int) $_SESSION['user_id'], $noteText]);
        }
        header("Location: revenue_details.php?id=$entryId&success=note_added");
        exit();
    }
}
function getStatusBadgeClass(string $status): string {
    return match (strtolower($status)) {
        'ratified', 'posted' => 'bg-success-subtle text-success border-success',
        'pending' => 'bg-warning-subtle text-warning border-warning',
        'voided', 'cancelled' => 'bg-danger-subtle text-danger border-danger',
        default => 'bg-secondary-subtle text-secondary border-secondary',
    };
}

// Helper for payment status badge class
function getPaymentBadgeClass(string $status): string {
    return match (strtolower($status)) {
        'paid' => 'bg-success-subtle text-success',
        'partial' => 'bg-info-subtle text-info',
        'unpaid' => 'bg-danger-subtle text-danger',
        default => 'bg-secondary-subtle text-secondary',
    };
}

function rev_detail_type_label(array $row): string
{
    $n = strtolower((string) ($row['narration'] ?? ''));
    if (strpos($n, 'credit') !== false) {
        return 'Credit Note';
    }
    if (!empty($row['source_invoice_id']) || !empty($row['linked_invoice_number'])) {
        return 'Sales Invoice';
    }

    return 'Other';
}

function rev_detail_description(array $row): string
{
    $inv = trim((string) ($row['linked_invoice_number'] ?? ''));
    if ($inv !== '') {
        return 'Sales Invoice ' . $inv . ' (from Sales)';
    }
    $nar = trim((string) ($row['narration'] ?? ''));

    return $nar !== '' ? $nar : 'â€”';
}

$employeeHeaderTitle = '';
$employeeHeaderSubtitle = '';
$employeeHeaderCenterHtml = null;

$headerDate = date('l, d M Y');
$dashDisplayName = $_SESSION['full_name'] ?? 'System Administrator';
$dashRoleLabel = ucfirst((string) ($_SESSION['role'] ?? 'Administrator'));
$dashParts = preg_split('/\s+/', trim($dashDisplayName));
$dashInitials = '';
foreach (array_slice($dashParts, 0, 2) as $dp) {
    if ($dp !== '') {
        $dashInitials .= strtoupper(substr($dp, 0, 1));
    }
}
if ($dashInitials === '') {
    $dashInitials = 'SA';
}

$displayStatusLabel = (strcasecmp((string) $status, 'Ratified') === 0) ? 'Posted' : (string) $status;
$typeLabel = rev_detail_type_label($entry);
$descLine = rev_detail_description($entry);
$custLine = trim((string) ($entry['resolved_company_name'] ?? '')) !== '' ? trim((string) $entry['resolved_company_name']) : trim((string) ($entry['customer_name'] ?? ''));
$vatPct = $amountNet > 0.0001 ? (int) round(($amountVat / $amountNet) * 100) : 18;
$vatRowLabel = $vatPct > 0 ? 'Tax (VAT ' . $vatPct . '%)' : 'Tax (VAT)';

$canDeleteEntry = isAdmin() || strcasecmp((string) $status, 'Pending') === 0;
$isPostedLike = in_array(strtolower((string) $status), ['ratified', 'posted'], true);
$postedEventTs = !empty($entry['approved_at']) ? strtotime((string) $entry['approved_at']) : ($isPostedLike ? strtotime((string) ($entry['created_at'] ?? 'now')) : false);

$typeTagClass = 'rev-det-type-tag--other';
if (stripos($typeLabel, 'credit') !== false) {
    $typeTagClass = 'rev-det-type-tag--credit';
} elseif (stripos($typeLabel, 'sales') !== false) {
    $typeTagClass = 'rev-det-type-tag--sales';
}

$paymentStatusLower = strtolower((string) $paymentStatus);
$approvalLower = strtolower((string) $status);
$titleStatusLabel = 'Pending';
if (in_array($paymentStatusLower, ['paid', 'partial'], true)) {
    $titleStatusLabel = $paymentStatusLower === 'paid' ? 'Paid' : 'Partial';
} elseif (in_array($approvalLower, ['posted', 'ratified'], true)) {
    $titleStatusLabel = $balance > 0.009 ? 'Partial' : 'Paid';
}
$titleStatusClass = match (strtolower($titleStatusLabel)) {
    'paid' => 'rd-badge-status rd-badge-status-paid',
    'partial' => 'rd-badge-status rd-badge-status-partial',
    default => 'rd-badge-status rd-badge-status-pending',
};

$typeBadgeClass = match (strtolower($typeLabel)) {
    'sales', 'sales invoice' => 'rd-badge-type rd-badge-type-sales',
    default => 'rd-badge-type rd-badge-type-other',
};

$timelineEvents = [];
$timelineEvents[] = [
    'action' => 'Created',
    'description' => 'Revenue entry was created.',
    'time' => !empty($entry['created_at']) ? (string) $entry['created_at'] : null,
    'user' => (string) ($entry['creator_name'] ?: 'System'),
    'tone' => 'primary',
];
if ($isPostedLike) {
    $timelineEvents[] = [
        'action' => 'Posted',
        'description' => 'Revenue entry was posted / ratified.',
        'time' => !empty($entry['approved_at']) ? (string) $entry['approved_at'] : null,
        'user' => (string) ($entry['approver_name'] ?: $entry['creator_name'] ?: 'System'),
        'tone' => 'success',
    ];
}
if ($amountPaid > 0.0) {
    $timelineEvents[] = [
        'action' => 'Payment recorded',
        'description' => 'Payment was recorded for this voucher.',
        'time' => !empty($entry['updated_at']) ? (string) $entry['updated_at'] : (!empty($entry['approved_at']) ? (string) $entry['approved_at'] : null),
        'user' => (string) ($entry['approver_name'] ?: $entry['creator_name'] ?: 'System'),
        'tone' => 'success',
    ];
}
if (!empty($entry['updated_at'])) {
    $timelineEvents[] = [
        'action' => 'Updated',
        'description' => 'Revenue entry details were updated.',
        'time' => (string) $entry['updated_at'],
        'user' => (string) ($entry['approver_name'] ?: $entry['creator_name'] ?: 'System'),
        'tone' => 'neutral',
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Entry Details - <?= h($voucherId) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <style>
        :root {
            --rd-primary: #2563eb;
            --rd-success: #16a34a;
            --rd-danger: #dc2626;
            --rd-warning: #f59e0b;
            --rd-bg-page: #f9fafb;
            --rd-bg-card: #ffffff;
            --rd-border: #e5e7eb;
            --rd-text: #111827;
            --rd-text-secondary: #6b7280;
            --rd-section-gap: 24px;
            --rd-card-padding: 20px;
            --rd-inner-gap: 12px;
            --rd-row-gap: 16px;
            --rd-col-gap: 20px;
            --rd-radius: 10px;
            --rd-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        html, body { margin: 0; padding: 0; }
        body.rev-det-page { background: var(--rd-bg-page) !important; color: var(--rd-text); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        body.rev-det-page .layout-main-wrapper > .flex-grow-1 { background: var(--rd-bg-page); }
        body.rev-det-page .employee-header { background: #fff !important; border-bottom: 1px solid var(--rd-border) !important; box-shadow: none !important; }
        body.rev-det-page .main-wrapper { max-width: 1320px; margin: 0 auto; padding: var(--rd-card-padding); }
        .rd-page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--rd-col-gap); margin-bottom: var(--rd-section-gap); }
        .rd-breadcrumb { font-size: 13px; color: var(--rd-text-secondary); margin-bottom: 6px; }
        .rd-breadcrumb a { color: var(--rd-primary); text-decoration: none; font-weight: 600; }
        .rd-breadcrumb a:hover { text-decoration: underline; }
        .rd-title-wrap h1 { margin: 0; font-size: 28px; line-height: 1.2; font-weight: 700; display: inline-flex; align-items: center; gap: 10px; }
        .rd-subtitle { margin-top: 6px; color: var(--rd-text-secondary); font-size: 14px; }
        .rd-badge-status { display: inline-block; font-size: 12px; font-weight: 700; line-height: 1; padding: 6px 10px; border-radius: 999px; }
        .rd-badge-status-pending { background: #fef3c7; color: #b45309; }
        .rd-badge-status-paid { background: #dcfce7; color: #166534; }
        .rd-badge-status-partial { background: #dbeafe; color: #1e40af; }
        .rd-header-actions { display: flex; gap: var(--rd-inner-gap); flex-wrap: wrap; justify-content: flex-end; }
        .rd-btn { border-radius: 8px; font-size: 14px; font-weight: 600; padding: 10px 14px; line-height: 1; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; cursor: pointer; }
        .rd-btn-primary { background: var(--rd-primary); color: #fff; }
        .rd-btn-success { background: var(--rd-success); color: #fff; }
        .rd-btn-danger { background: var(--rd-danger); color: #fff; }
        .rd-btn-secondary { background: #fff; color: var(--rd-text); border-color: var(--rd-border); }
        .rd-card { background: var(--rd-bg-card); border: 1px solid var(--rd-border); border-radius: var(--rd-radius); box-shadow: var(--rd-shadow); padding: var(--rd-card-padding); height: 100%; }
        .rd-card h3 { margin: 0 0 14px; font-size: 18px; font-weight: 700; }
        .rd-grid-top, .rd-grid-mid, .rd-grid-bottom { display: grid; gap: var(--rd-col-gap); margin-bottom: var(--rd-section-gap); }
        .rd-grid-top { grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr); }
        .rd-grid-mid { grid-template-columns: minmax(280px, 1fr) minmax(0, 2fr); }
        .rd-grid-bottom { grid-template-columns: 1fr 1fr; }
        .rd-voucher-head { display: flex; align-items: center; gap: 14px; margin-bottom: var(--rd-row-gap); }
        .rd-voucher-icon { width: 46px; height: 46px; border-radius: 50%; background: #eef2ff; color: var(--rd-primary); display: inline-flex; align-items: center; justify-content: center; }
        .rd-voucher-id { font-size: 32px; line-height: 1.05; font-weight: 800; letter-spacing: -0.3px; margin: 0; }
        .rd-badge-type { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .rd-badge-type-sales { background: #dbeafe; color: #1e40af; }
        .rd-badge-type-other { background: #f3f4f6; color: #374151; }
        .rd-detail-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--rd-row-gap) var(--rd-col-gap); }
        .rd-field { display: flex; gap: 10px; min-width: 0; }
        .rd-field-ico { width: 18px; color: #94a3b8; margin-top: 2px; text-align: center; flex: 0 0 18px; }
        .rd-label { margin: 0 0 4px; color: var(--rd-text-secondary); font-size: 12px; font-weight: 600; }
        .rd-value { margin: 0; color: var(--rd-text); font-size: 14px; font-weight: 600; word-break: break-word; }
        .rd-value a { color: var(--rd-primary); text-decoration: none; }
        .rd-value a:hover { text-decoration: underline; }
        .rd-amount-list { display: grid; gap: 2px; }
        .rd-amount-row { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--rd-border); font-size: 14px; }
        .rd-amount-row:last-child { border-bottom: 0; }
        .rd-amount-total { color: var(--rd-primary); font-size: 18px; font-weight: 800; }
        .rd-amount-paid { color: var(--rd-success); font-weight: 800; }
        .rd-amount-balance { color: var(--rd-danger); font-size: 18px; font-weight: 800; }
        .rd-timeline { position: relative; margin: 0; padding: 0 0 0 20px; list-style: none; }
        .rd-timeline::before { content: ""; position: absolute; left: 7px; top: 2px; bottom: 2px; width: 2px; background: var(--rd-border); }
        .rd-timeline li { position: relative; margin-bottom: 16px; }
        .rd-timeline li:last-child { margin-bottom: 0; }
        .rd-timeline-dot { position: absolute; left: -20px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: var(--rd-primary); border: 2px solid #fff; box-shadow: 0 0 0 1px var(--rd-border); }
        .rd-timeline-dot.success { background: var(--rd-success); box-shadow: 0 0 0 1px #86efac; }
        .rd-timeline-dot.neutral { background: #9ca3af; box-shadow: 0 0 0 1px #d1d5db; }
        .rd-tl-action { margin: 0 0 3px; font-size: 14px; font-weight: 700; }
        .rd-tl-desc, .rd-tl-meta { margin: 0; font-size: 12px; color: var(--rd-text-secondary); }
        .rd-tl-meta { margin-top: 3px; display: flex; flex-wrap: wrap; gap: 10px; }
        .rd-table-wrap { margin: 0 -20px -20px; overflow: auto; }
        .rd-table { width: 100%; border-collapse: collapse; min-width: 720px; }
        .rd-table thead th { background: #f3f4f6; color: var(--rd-text); font-size: 12px; font-weight: 700; padding: 12px 16px; border-top: 1px solid var(--rd-border); border-bottom: 1px solid var(--rd-border); text-align: left; }
        .rd-table td { padding: 12px 16px; border-bottom: 1px solid var(--rd-border); font-size: 14px; }
        .rd-table tbody tr:hover { background: #f9fafb; }
        .rd-num { text-align: right; font-variant-numeric: tabular-nums; }
        .rd-empty { text-align: center; color: var(--rd-text-secondary); padding: 28px 16px; }
        .rd-empty i { font-size: 32px; display: block; margin-bottom: 8px; color: #9ca3af; }
        .rd-attachment-item { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--rd-border); border-radius: 8px; margin-bottom: 12px; }
        .rd-attachment-file { min-width: 0; flex: 1; }
        .rd-attachment-name { margin: 0; font-size: 14px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rd-attachment-meta { margin: 2px 0 0; font-size: 12px; color: var(--rd-text-secondary); }
        .rd-notes-empty { color: var(--rd-text-secondary); font-size: 14px; margin: 0; }
        .rd-footer-bar { display: flex; justify-content: space-between; align-items: center; gap: var(--rd-inner-gap); margin-top: var(--rd-section-gap); }
        .rd-footer-actions { display: flex; gap: var(--rd-inner-gap); flex-wrap: wrap; }
        .rd-back-link { display: inline-flex; margin-top: 10px; color: var(--rd-primary); text-decoration: none; font-weight: 600; font-size: 14px; }
        .rd-back-link:hover { text-decoration: underline; }
        @media (max-width: 1199.98px) {
            .rd-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 991.98px) {
            .rd-page-header { flex-direction: column; align-items: stretch; }
            .rd-header-actions { justify-content: flex-start; }
            .rd-grid-top, .rd-grid-mid, .rd-grid-bottom { grid-template-columns: 1fr; }
            .rd-voucher-id { font-size: 26px; }
            .rd-footer-bar { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 575.98px) {
            body.rev-det-page .main-wrapper { padding: 14px; }
            .rd-card { padding: 16px; }
            .rd-table-wrap { margin: 0 -16px -16px; }
            .rd-detail-grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php require __DIR__ . '/includes/nav-back-script.php'; ?>
</head>
<body class="dashboard rev-det-page">
<?php require __DIR__ . '/includes/header_employee.php'; ?>
<div class="main-wrapper">
<div class="sh">

<?php if (isset($_GET['success']) && $_GET['success'] === 'note_added'): ?>
<div class="alert alert-success alert-dismissible fade show py-2 mb-3" role="alert">Note added.
<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="rd-page-header">
    <div class="rd-title-wrap">
        <div class="rd-breadcrumb"><a href="<?= htmlspecialchars($revenueListUrl) ?>" class="erp-nav-back-link">Revenue Entries</a> <i class="fas fa-chevron-right mx-1"></i> <?= h($voucherId) ?></div>
        <h1>Revenue Entry Details <span class="<?= h($titleStatusClass) ?>"><?= h($titleStatusLabel) ?></span></h1>
        <p class="rd-subtitle">View and manage the details of this revenue entry</p>
    </div>
    <div class="rd-header-actions">
        <a href="revenue_edit.php?id=<?= $entryId ?>" class="rd-btn rd-btn-secondary"><i class="fas fa-pen"></i> Edit</a>
        <div class="dropdown">
            <button class="rd-btn rd-btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Actions</button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><button type="button" class="dropdown-item" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Voucher</button></li>
                <li><a class="dropdown-item" href="#"><i class="fas fa-envelope me-2"></i>Email Customer</a></li>
                <li><hr class="dropdown-divider"></li>
                <?php if ($canDeleteEntry): ?>
                <li>
                    <form action="revenue_process.php" method="post" class="m-0" onsubmit="return confirm('Delete this revenue entry? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete_entry">
                        <input type="hidden" name="entry_id" value="<?= $entryId ?>">
                        <button type="submit" class="dropdown-item text-danger"><i class="fas fa-trash me-2"></i>Delete Entry</button>
                    </form>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <a href="revenue_record_payment.php?id=<?= (int) $entryId ?>" class="rd-btn rd-btn-primary"><i class="fas fa-wallet"></i> Record Payment</a>
    </div>
</div>

<section class="rd-grid-top">
    <article class="rd-card">
        <div class="rd-voucher-head">
            <div class="rd-voucher-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <p class="rd-label">Voucher ID</p>
                <h2 class="rd-voucher-id"><?= h($voucherId) ?></h2>
                <span class="<?= h($typeBadgeClass) ?>"><?= h($typeLabel) ?></span>
            </div>
        </div>
        <div class="rd-detail-grid">
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-user"></i></span><div><p class="rd-label">Customer name</p><p class="rd-value"><?= h($custLine !== '' ? $custLine : 'N/A') ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-id-badge"></i></span><div><p class="rd-label">Customer code</p><p class="rd-value"><?= h((string) ($entry['resolved_customer_code'] ?? 'N/A')) ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="far fa-calendar"></i></span><div><p class="rd-label">Transaction date</p><p class="rd-value"><?= h(date('d M Y', strtotime((string) ($entry['entry_date'] ?? 'now')))) ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="far fa-calendar-check"></i></span><div><p class="rd-label">Due date</p><p class="rd-value"><?= h(!empty($entry['invoice_due_date']) ? date('d M Y', strtotime((string) $entry['invoice_due_date'])) : 'N/A') ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-align-left"></i></span><div><p class="rd-label">Description</p><p class="rd-value"><?= h($descLine) ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-tag"></i></span><div><p class="rd-label">Type</p><p class="rd-value"><?= h($typeLabel) ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-money-check-dollar"></i></span><div><p class="rd-label">Payment method</p><p class="rd-value"><?= h(trim((string) ($entry['payment_mode'] ?? '')) !== '' ? (string) $entry['payment_mode'] : 'N/A') ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-circle-check"></i></span><div><p class="rd-label">Status</p><p class="rd-value"><?= h($displayStatusLabel) ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-hashtag"></i></span><div><p class="rd-label">Invoice reference</p><p class="rd-value"><?= !empty($entry['linked_invoice_number']) ? h((string) $entry['linked_invoice_number']) : 'â€”' ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-user-gear"></i></span><div><p class="rd-label">Created by</p><p class="rd-value"><?= h((string) ($entry['creator_name'] ?: 'System')) ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="far fa-clock"></i></span><div><p class="rd-label">Created at</p><p class="rd-value"><?= h(date('d M Y, h:i A', strtotime((string) ($entry['created_at'] ?? 'now')))) ?></p></div></div>
            <div class="rd-field"><span class="rd-field-ico"><i class="fas fa-rotate"></i></span><div><p class="rd-label">Last updated</p><p class="rd-value"><?= h(!empty($entry['updated_at']) ? date('d M Y, h:i A', strtotime((string) $entry['updated_at'])) : (!empty($entry['approved_at']) ? date('d M Y, h:i A', strtotime((string) $entry['approved_at'])) : 'N/A')) ?></p></div></div>
        </div>
    </article>
    <article class="rd-card">
        <h3>Amount Summary (TZS)</h3>
        <div class="rd-amount-list">
            <div class="rd-amount-row"><span>Net Amount</span><strong><?= h(number_format($amountNet, 2)) ?></strong></div>
            <div class="rd-amount-row"><span>VAT Amount</span><strong><?= h(number_format($amountVat, 2)) ?></strong></div>
            <div class="rd-amount-row"><span>Total Amount Incl. Tax</span><strong class="rd-amount-total"><?= h(number_format($amountTotal, 2)) ?></strong></div>
            <div class="rd-amount-row"><span>Paid Amount</span><strong class="rd-amount-paid"><?= h(number_format($amountPaid, 2)) ?></strong></div>
            <div class="rd-amount-row"><span>Balance</span><strong class="<?= $balance > 0 ? 'rd-amount-balance' : '' ?>"><?= h(number_format($balance, 2)) ?></strong></div>
        </div>
    </article>
</section>

<section class="rd-grid-mid">
    <article class="rd-card">
        <h3>Timeline</h3>
        <?php if (!$timelineEvents): ?>
            <p class="rd-notes-empty">No further updates.</p>
        <?php else: ?>
            <ul class="rd-timeline">
                <?php foreach ($timelineEvents as $te): ?>
                    <?php $toneClass = $te['tone'] === 'success' ? 'success' : ($te['tone'] === 'neutral' ? 'neutral' : ''); ?>
                    <li>
                        <span class="rd-timeline-dot <?= h($toneClass) ?>"></span>
                        <p class="rd-tl-action"><?= h($te['action']) ?></p>
                        <p class="rd-tl-desc"><?= h($te['description']) ?></p>
                        <p class="rd-tl-meta">
                            <span><i class="far fa-clock me-1"></i><?= h(!empty($te['time']) ? date('d M Y, h:i A', strtotime((string) $te['time'])) : 'N/A') ?></span>
                            <span><i class="far fa-user me-1"></i><?= h($te['user']) ?></span>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
    <article class="rd-card">
        <h3>Ledger</h3>
        <?php if (empty($ledgerItems)): ?>
            <div class="rd-empty">
                <i class="far fa-folder-open"></i>
                <p class="mb-2">No ledger lines for this voucher</p>
                <button type="button" class="rd-btn rd-btn-secondary" disabled><i class="fas fa-plus"></i> Add Ledger Line</button>
            </div>
        <?php else: ?>
            <div class="rd-table-wrap">
                <table class="rd-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Account</th>
                            <th>Description</th>
                            <th class="rd-num">Debit (TZS)</th>
                            <th class="rd-num">Credit (TZS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totalDebit = 0.0;
                        $totalCredit = 0.0;
                        foreach ($ledgerItems as $li):
                            $totalDebit += (float) $li['debit'];
                            $totalCredit += (float) $li['credit'];
                            $lineDesc = trim((string) ($li['memo'] ?? $li['description'] ?? $li['narration'] ?? ''));
                            if ($lineDesc === '') {
                                $lineDesc = trim((string) ($entry['narration'] ?? '')) ?: 'â€”';
                            }
                        ?>
                        <tr>
                            <td><?= h(date('d M Y', strtotime((string) ($li['j_date'] ?? $entry['entry_date'] ?? 'now')))) ?></td>
                            <td><strong><?= h((string) $li['account_name']) ?></strong><br><span class="text-muted"><?= h((string) $li['account_code']) ?></span></td>
                            <td><?= h($lineDesc) ?></td>
                            <td class="rd-num"><?= (float) $li['debit'] > 0 ? h(number_format((float) $li['debit'], 2)) : 'â€”' ?></td>
                            <td class="rd-num"><?= (float) $li['credit'] > 0 ? h(number_format((float) $li['credit'], 2)) : 'â€”' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="3" class="rd-num"><strong>Total</strong></td>
                            <td class="rd-num"><strong><?= h(number_format($totalDebit, 2)) ?></strong></td>
                            <td class="rd-num"><strong><?= h(number_format($totalCredit, 2)) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="rd-grid-bottom">
    <article class="rd-card">
        <h3>Attachments (<?= (int) count($attachments) ?>)</h3>
        <?php if (!$attachments): ?>
            <p class="rd-notes-empty">No attachments.</p>
        <?php else: ?>
            <?php foreach ($attachments as $file): ?>
                <?php
                $fileSize = isset($file['file_size']) && is_numeric($file['file_size']) ? (float) $file['file_size'] : 0.0;
                $fileSizeLabel = $fileSize > 0 ? number_format($fileSize / 1024, 1) . ' KB' : 'Size N/A';
                $uploadLabel = !empty($file['uploaded_at']) ? date('d M Y, h:i A', strtotime((string) $file['uploaded_at'])) : 'Date N/A';
                ?>
                <div class="rd-attachment-item">
                    <i class="fas fa-file text-danger"></i>
                    <div class="rd-attachment-file">
                        <p class="rd-attachment-name"><?= h((string) ($file['file_name'] ?? 'Attachment')) ?></p>
                        <p class="rd-attachment-meta">Uploaded: <?= h($uploadLabel) ?> â€¢ <?= h($fileSizeLabel) ?></p>
                    </div>
                    <a class="rd-btn rd-btn-secondary" href="<?= h((string) ($file['file_path'] ?? '#')) ?>" target="_blank" rel="noopener"><i class="fas fa-download"></i> View</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <button type="button" class="rd-btn rd-btn-secondary w-100" disabled><i class="fas fa-plus"></i> Add Attachment</button>
    </article>
    <article class="rd-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">Notes</h3>
            <button type="button" class="rd-btn rd-btn-secondary" data-bs-toggle="modal" data-bs-target="#noteModal"><i class="fas fa-plus"></i> Add Note</button>
        </div>
        <?php if (empty($notes)): ?>
            <p class="rd-notes-empty">No notes yet.</p>
        <?php else: ?>
            <?php foreach ($notes as $note): ?>
                <div class="pb-3 mb-3 border-bottom">
                    <p class="mb-1"><strong><?= h((string) $note['user_name']) ?></strong> <span class="text-muted small">â€¢ <?= h(date('d M Y, H:i', strtotime((string) $note['created_at']))) ?></span></p>
                    <p class="mb-0"><?= nl2br(h((string) $note['note'])) ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>
</section>

<div class="rd-footer-bar">
    <div>
        <?php if ($canDeleteEntry): ?>
        <form action="revenue_process.php" method="post" class="d-inline" onsubmit="return confirm('Delete this revenue entry? This cannot be undone.');">
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="entry_id" value="<?= $entryId ?>">
            <button type="submit" class="rd-btn rd-btn-danger"><i class="fas fa-trash"></i> Delete</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="rd-footer-actions">
        <button type="button" class="rd-btn rd-btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        <a href="revenue_record_payment.php?id=<?= (int) $entryId ?>" class="rd-btn rd-btn-success"><i class="fas fa-wallet"></i> Record Payment</a>
    </div>
</div>
<a href="<?= htmlspecialchars($revenueListUrl) ?>" class="rd-back-link erp-nav-back-link"><i class="fas fa-arrow-left me-2"></i>Back to Revenue Entries</a>

</div>
</div>

<!-- Simple Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">Record Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="revenue_process.php" method="POST">
                <input type="hidden" name="action" value="collect_payment">
                <input type="hidden" name="entry_id" value="<?= $entryId ?>">
                <div class="modal-body">
                    <p class="text-muted small mb-4">Record a payment for voucher <span class="fw-bold text-dark"><?= h($voucherId) ?></span>.</p>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Payment Date</label>
                        <input type="date" name="collection_date" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Amount Collected (TZS)</label>
                        <div class="input-group">
                            <span class="input-group-text">TZS</span>
                            <input type="number" step="0.01" name="amount_collected" class="form-control form-control-lg" value="<?= $balance ?>" max="<?= $balance ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Deposit Account</label>
                        <select name="account_id" class="form-select form-select-lg" required>
                            <option value="">Select Account...</option>
                            <?php
                            // Check both erp_bank_accounts and financial_accounts (depending on which exists)
                            $bankAccs = [];
                            try {
                                $stmtAcc = $pdo->query("SELECT id, account_name FROM erp_bank_accounts WHERE status = 'active' ORDER BY account_name ASC");
                                $bankAccs = $stmtAcc->fetchAll();
                            } catch (Exception $e) {
                                try {
                                    $stmtAcc = $pdo->query("SELECT id, name AS account_name FROM financial_accounts WHERE status = 'active' ORDER BY name ASC");
                                    $bankAccs = $stmtAcc->fetchAll();
                                } catch (Exception $e2) {}
                            }
                            foreach($bankAccs as $acc) {
                                echo "<option value='{$acc['id']}'>{$acc['account_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">Add Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="action" value="add_note">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Internal Note</label>
                        <textarea name="note" class="form-control" rows="4" placeholder="Type your note here..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">Add Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
