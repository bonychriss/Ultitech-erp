<?php
require_once '../../includes/functions.php';
requireLogin();
ensureOutstandingInvoicesSchema();
$rootPath = '../../';
$modulesLink = '../../select-module.php';
$logoBase = '../../';

$success = $error = '';

// Handle Actions (Create, Delete/Pay)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_invoice'])) {
        $type = $_POST['type'] ?? 'receivable'; // receivable or payable
        $date = $_POST['invoice_date'] ?? date('Y-m-d');
        $name = trim($_POST['entity_name'] ?? '');
        $invoiceNo = trim($_POST['invoice_number'] ?? '');
        $narration = trim($_POST['narration'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        
        // Handle Attachment Upload
        $attachmentPath = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../../assets/uploads/invoices/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $newFilename = uniqid('inv_') . '.' . $ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $newFilename)) {
                $attachmentPath = 'assets/uploads/invoices/' . $newFilename;
            }
        }

        if (empty($name) || $amount <= 0) {
            $error = 'Please provide a valid Name and Amount.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_outstanding_invoices (type, invoice_date, entity_name, invoice_number, narration, amount, attachment) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$type, $date, $name, $invoiceNo, $narration, $amount, $attachmentPath])) {
                $success = 'Invoice added successfully.';
            } else {
                $error = 'Failed to add invoice.';
            }
        }
    }
    
    // Mark as Paid (Soft delete or status update) - User asked for "Outstanding", so marking paid removes it from list usually.
    // Mark as Paid (Soft delete or status update) - User asked for "Outstanding", so marking paid removes it from list usually.
    if (isset($_POST['mark_paid'])) {
        if (!isFinance()) {
            $error = 'Only Finance users can mark invoices as paid.';
        } else {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM erp_outstanding_invoices WHERE id = ?");
            if ($stmt->execute([$id])) {
                $success = 'Invoice marked as paid and removed.';
            }
        }
    }

    // Delete Invoice
    if (isset($_POST['delete_invoice'])) {
        if (!isFinance()) {
            $error = 'Only Finance users can delete invoices.';
        } else {
            $id = (int)$_POST['id'];
            // Get attachment to delete file if exists
            $stmt = $pdo->prepare("SELECT attachment FROM erp_outstanding_invoices WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch();
            
            $stmt = $pdo->prepare("DELETE FROM erp_outstanding_invoices WHERE id = ?");
            if ($stmt->execute([$id])) {
                if ($inv && !empty($inv['attachment']) && file_exists('../../' . $inv['attachment'])) {
                    @unlink('../../' . $inv['attachment']);
                }
                $success = 'Invoice deleted successfully.';
            }
        }
    }
}

// Fetch Data
// Default tab: Receivables
$activeTab = $_GET['tab'] ?? 'receivables';
$typeFilter = ($activeTab === 'payables') ? 'payable' : 'receivable';

$stmt = $pdo->prepare("SELECT * FROM erp_outstanding_invoices WHERE type = ? AND status = 'outstanding' ORDER BY invoice_date DESC");
$stmt->execute([$typeFilter]);
$invoices = $stmt->fetchAll();

$summaryStmt = $pdo->query("SELECT type, COUNT(*) AS invoice_count, COALESCE(SUM(amount), 0) AS total_amount FROM erp_outstanding_invoices WHERE status = 'outstanding' GROUP BY type");
$invoiceSummaries = [
    'receivable' => ['invoice_count' => 0, 'total_amount' => 0],
    'payable' => ['invoice_count' => 0, 'total_amount' => 0],
];
foreach ($summaryStmt->fetchAll() as $summary) {
    if (isset($invoiceSummaries[$summary['type']])) {
        $invoiceSummaries[$summary['type']] = $summary;
    }
}

// Calculate Total Outstanding
$totalOutstanding = 0;
foreach ($invoices as $inv) {
    $totalOutstanding += $inv['amount'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outstanding Invoices - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        body { background: #f3f4f6; }
        .main-content { max-width: 1200px; margin: 0 auto; padding: 12px 20px 20px; }
        .outstanding-invoice-header-actions {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .new-invoice-btn {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
        }
        .new-invoice-btn-mobile-label { display: none; }
        .new-invoice-btn:hover { background: #1d4ed8; }
        .oi-invoice-picker {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(560px, 72vw);
            margin: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
            padding: 18px;
            z-index: 20;
            animation: oi-panel-pop 0.18s ease-out;
        }
        .oi-invoice-picker[hidden] { display: none; }
        .oi-invoice-picker-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        @keyframes oi-panel-pop {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .oi-invoice-picker-card {
            display: flex;
            align-items: center;
            gap: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px;
            text-decoration: none;
            color: #0f172a;
            transition: 0.2s ease;
        }
        .oi-invoice-picker-card.active {
            border-color: #93c5fd;
            background: #eff6ff;
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.08);
        }
        .oi-invoice-picker-card:hover {
            transform: translateY(-1px);
            border-color: #cbd5e1;
        }
        .oi-picker-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .oi-picker-icon--in { background: #dcfce7; color: #15803d; }
        .oi-picker-icon--out { background: #fee2e2; color: #b91c1c; }
        .oi-invoice-picker-card strong {
            display: block;
            font-size: 1rem;
            margin-bottom: 4px;
        }
        .oi-invoice-picker-card small {
            color: #475569;
            line-height: 1.4;
            display: block;
        }
        
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; }
        .tab-link { padding: 10px 20px; text-decoration: none; color: #6b7280; border-bottom: 2px solid transparent; font-weight: 500; }
        .tab-link.active { color: #2563eb; border-bottom-color: #2563eb; }
        .tab-link:hover { color: #111; }
        
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 5px; color: #374151; }
        .form-group input, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.9rem; }
        .btn-submit { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 500; }
        .btn-submit:hover { background: #1d4ed8; }
        
        .invoice-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .invoice-table th, .invoice-table td { text-align: left; padding: 12px; border-bottom: 1px solid #e5e7eb;font-size: 0.9rem; }
        .invoice-table th { background: #f9fafb; font-weight: 600; color: #374151; }
        .invoice-table tr:hover { background: #f9fafb; }
        
        .amount-col { font-weight: 600; color: #111; text-align: right !important; }
        .actions-col { text-align: right !important; }
        
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 500; }
        .badge-att { background: #e0f2fe; color: #0369a1; text-decoration: none; }
        
        .total-box { font-size: 1.1rem; font-weight: bold; text-align: right; margin-top: 10px; color: #059669; }
        
        .btn-pay { background: transparent; border: 1px solid #10b981; color: #10b981; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 0.8rem; }
        .btn-pay:hover { background: #10b981; color: #fff; }

        .oi-mobile-invoice-list { display: none; }
        .oi-mobile-invoice-card {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
        }
        .oi-mobile-invoice-top,
        .oi-mobile-invoice-party,
        .oi-mobile-invoice-aside,
        .oi-mobile-invoice-facts,
        .oi-mobile-invoice-fact,
        .oi-mobile-invoice-actions {
            display: flex;
            align-items: center;
        }
        .oi-mobile-invoice-top {
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .oi-mobile-invoice-party { gap: 9px; min-width: 0; }
        .oi-mobile-invoice-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 28px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #312e81;
            color: #e0e7ff;
            font-size: 0.65rem;
            font-weight: 700;
        }
        .oi-mobile-invoice-identity { display: flex; flex-direction: column; min-width: 0; gap: 2px; }
        .oi-mobile-invoice-number {
            color: #0f172a;
            font-size: 0.82rem;
            font-weight: 800;
            overflow-wrap: anywhere;
        }
        .oi-mobile-invoice-name {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .oi-mobile-invoice-aside {
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
            flex: 0 0 auto;
        }
        .oi-mobile-invoice-amount {
            color: #059669;
            font-size: 0.78rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .oi-mobile-invoice-status {
            padding: 2px 8px;
            border: 1px solid #f59e0b;
            border-radius: 999px;
            color: #b45309;
            font-size: 0.64rem;
            font-weight: 700;
        }
        .oi-mobile-invoice-facts {
            gap: 12px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }
        .oi-mobile-invoice-fact {
            align-items: flex-start;
            flex: 1 1 0;
            min-width: 0;
            gap: 7px;
        }
        .oi-mobile-invoice-fact-icon {
            color: #64748b;
            font-size: 0.85rem;
            line-height: 1.2;
        }
        .oi-mobile-invoice-fact-body { display: flex; flex-direction: column; min-width: 0; gap: 2px; }
        .oi-mobile-invoice-fact-label {
            color: #94a3b8;
            font-size: 0.58rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .oi-mobile-invoice-fact-value {
            color: #0f172a;
            font-size: 0.72rem;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .oi-mobile-invoice-actions {
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 8px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }
        .oi-mobile-invoice-actions form { margin: 0; }
        .oi-mobile-invoice-delete {
            padding: 4px 9px;
            border: 1px solid #fecaca;
            border-radius: 5px;
            background: #fff;
            color: #dc2626;
            font-size: 0.75rem;
            cursor: pointer;
        }
        .oi-mobile-invoice-empty {
            padding: 18px 12px;
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            color: #64748b;
            text-align: center;
            font-size: 0.85rem;
        }
        .oi-mobile-overview { display: none; }
        .oi-mobile-search {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 48px;
            margin-bottom: 12px;
            padding: 0 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #fff;
            color: #64748b;
        }
        .oi-mobile-search svg { flex: 0 0 auto; }
        .oi-mobile-search input {
            width: 100%;
            min-width: 0;
            padding: 10px 0;
            border: 0;
            outline: 0;
            background: transparent;
            color: #0f172a;
            font: inherit;
        }
        .oi-mobile-search input::placeholder { color: #94a3b8; }
        .oi-mobile-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .oi-mobile-summary-card {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 9px;
            min-width: 0;
            min-height: 120px;
            padding: 12px 10px;
            overflow: hidden;
            border: 1px solid #bfdbfe;
            border-radius: 14px;
            background: #eff6ff;
            color: #1e3a8a;
            text-decoration: none;
        }
        .oi-mobile-summary-card.active { border-color: #2563eb; box-shadow: inset 0 0 0 1px #2563eb; }
        .oi-mobile-summary-icon {
            display: inline-flex;
            flex: 0 0 34px;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #dbeafe;
            color: #1d4ed8;
        }
        .oi-mobile-summary-icon svg { width: 19px; height: 19px; }
        .oi-mobile-summary-copy { display: flex; flex-direction: column; min-width: 0; gap: 3px; }
        .oi-mobile-summary-title { font-size: 0.78rem; font-weight: 800; }
        .oi-mobile-summary-subtitle { color: #64748b; font-size: 0.63rem; line-height: 1.25; }
        .oi-mobile-summary-amount {
            margin-top: 5px;
            font-size: 0.75rem;
            font-weight: 800;
            overflow-wrap: anywhere;
        }
        .oi-mobile-summary-count { color: #64748b; font-size: 0.62rem; }
        .oi-mobile-summary-arrow {
            position: absolute;
            right: 8px;
            top: 8px;
            color: #2563eb;
            font-size: 1rem;
            font-weight: 800;
        }
        .oi-search-empty { display: none; }

        html[data-theme="dark"] .oi-mobile-invoice-card { background: #111c22; border-color: #26363d; }
        html[data-theme="dark"] .oi-mobile-invoice-number,
        html[data-theme="dark"] .oi-mobile-invoice-fact-value { color: #f8fafc !important; }
        html[data-theme="dark"] .oi-mobile-invoice-name { color: #b6c4ca !important; }
        html[data-theme="dark"] .oi-mobile-invoice-amount { color: #34d399 !important; }
        html[data-theme="dark"] .oi-mobile-invoice-status {
            color: #fbbf24 !important;
            border-color: #92400e;
        }
        html[data-theme="dark"] .oi-mobile-invoice-fact-label,
        html[data-theme="dark"] .oi-mobile-invoice-fact-icon { color: #94a3b8 !important; }
        html[data-theme="dark"] .oi-mobile-invoice-facts,
        html[data-theme="dark"] .oi-mobile-invoice-actions { border-color: #26363d; }
        html[data-theme="dark"] .oi-mobile-invoice-delete { background: #111c22; }
        html[data-theme="dark"] .oi-mobile-search { background: #111c22; border-color: #334155; color: #cbd5e1; }
        html[data-theme="dark"] .oi-mobile-search input { color: #f8fafc; }
        html[data-theme="dark"] .oi-mobile-summary-card { background: #172554; border-color: #1e40af; color: #eff6ff; }
        html[data-theme="dark"] .oi-mobile-summary-card.active { border-color: #60a5fa; box-shadow: inset 0 0 0 1px #60a5fa; }
        html[data-theme="dark"] .oi-mobile-summary-icon { background: #1e3a8a; color: #bfdbfe; }
        html[data-theme="dark"] .oi-mobile-summary-subtitle,
        html[data-theme="dark"] .oi-mobile-summary-count { color: #cbd5e1; }
        html[data-theme="dark"] .oi-mobile-summary-arrow { color: #93c5fd; }

        @media (max-width: 767.98px) {
            .main-content { padding: 12px 12px 20px; }
            .oi-mobile-overview { display: block; margin-bottom: 14px; }
            .new-invoice-btn-full-label { display: none; }
            .new-invoice-btn-mobile-label { display: inline; }
            .tabs { display: none; }
            .card { padding: 12px; }
            .invoice-table { display: none; }
            .oi-mobile-invoice-list { display: flex; flex-direction: column; gap: 10px; }
            .total-box { font-size: 0.95rem; }
        }
    </style>
</head>
<body>
    <?php
    $employeeHeaderTitle = 'Outstanding Invoices';
    $employeeHeaderRightHtml = '
        <div class="outstanding-invoice-header-actions">
            <button type="button" class="new-invoice-btn" data-toggle-target="new-outstanding-invoice-panel" aria-expanded="false">
                <span class="new-invoice-btn-full-label">New Outstanding Invoice</span>
                <span class="new-invoice-btn-mobile-label">+ New</span>
            </button>
            <section class="oi-invoice-picker" id="new-outstanding-invoice-panel" hidden>
                <div class="oi-invoice-picker-grid">
                    <a href="' . oi_h(oi_url(['tab' => 'receivables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) . '" class="oi-invoice-picker-card ' . ($activeTab === 'receivables' ? 'active' : '') . '">
                        <span class="oi-picker-icon oi-picker-icon--in">↗</span>
                        <div>
                            <strong>Receivables</strong>
                            <small>Track customer invoices still waiting to be collected.</small>
                        </div>
                    </a>
                    <a href="' . oi_h(oi_url(['tab' => 'payables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) . '" class="oi-invoice-picker-card ' . ($activeTab === 'payables' ? 'active' : '') . '">
                        <span class="oi-picker-icon oi-picker-icon--out">↘</span>
                        <div>
                            <strong>Payables</strong>
                            <small>Track supplier invoices still waiting to be paid.</small>
                        </div>
                    </a>
                </div>
            </section>
        </div>
    ';
    include '../../includes/header_employee.php';
    ?>

        <section class="oi-mobile-overview" aria-label="Outstanding invoice overview">
            <label class="oi-mobile-search">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <input type="search" id="oi-mobile-search-input" placeholder="Search supplier, invoice, description..." aria-label="Search outstanding invoices">
            </label>
            <div class="oi-mobile-summary-grid">
                <a class="oi-mobile-summary-card <?= $activeTab === 'receivables' ? 'active' : '' ?>" href="<?= oi_h(oi_url(['tab' => 'receivables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>">
                    <span class="oi-mobile-summary-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 3.75h9l4 4v11.5a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2v-13a1.5 1.5 0 0 1 2-1.5Z"></path>
                            <path d="M14.5 4v4h4M8 12h7M8 15h5"></path>
                        </svg>
                    </span>
                    <span class="oi-mobile-summary-copy">
                        <span class="oi-mobile-summary-title">Receivables</span>
                        <span class="oi-mobile-summary-subtitle">Customer invoices</span>
                        <span class="oi-mobile-summary-amount">TZS <?= number_format((float) $invoiceSummaries['receivable']['total_amount'], 2) ?></span>
                        <span class="oi-mobile-summary-count"><?= (int) $invoiceSummaries['receivable']['invoice_count'] ?> <?= (int) $invoiceSummaries['receivable']['invoice_count'] === 1 ? 'invoice' : 'invoices' ?></span>
                    </span>
                    <span class="oi-mobile-summary-arrow" aria-hidden="true">›</span>
                </a>
                <a class="oi-mobile-summary-card <?= $activeTab === 'payables' ? 'active' : '' ?>" href="<?= oi_h(oi_url(['tab' => 'payables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>">
                    <span class="oi-mobile-summary-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="7" width="18" height="13" rx="2"></rect>
                            <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2"></path>
                        </svg>
                    </span>
                    <span class="oi-mobile-summary-copy">
                        <span class="oi-mobile-summary-title">Payables</span>
                        <span class="oi-mobile-summary-subtitle">Supplier bills</span>
                        <span class="oi-mobile-summary-amount">TZS <?= number_format((float) $invoiceSummaries['payable']['total_amount'], 2) ?></span>
                        <span class="oi-mobile-summary-count"><?= (int) $invoiceSummaries['payable']['invoice_count'] ?> <?= (int) $invoiceSummaries['payable']['invoice_count'] === 1 ? 'invoice' : 'invoices' ?></span>
                    </span>
                    <span class="oi-mobile-summary-arrow" aria-hidden="true">›</span>
                </a>
            </div>
        </section>

        <?php if ($success): ?><div style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="tabs">
            <a href="?tab=receivables" class="tab-link <?= $activeTab === 'receivables' ? 'active' : '' ?>">Receivables (Income)</a>
            <a href="?tab=payables" class="tab-link <?= $activeTab === 'payables' ? 'active' : '' ?>">Payables (Expenses)</a>
        </div>

        <!-- Add New Form -->
        <div class="card" id="new-outstanding-invoice-form">
            <h3 style="font-size:1rem; margin-bottom:15px; color:#374151;">Add <?= $activeTab === 'receivables' ? 'Receivable' : 'Payable' ?></h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="create_invoice" value="1">
                <input type="hidden" name="type" value="<?= $typeFilter ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?= $activeTab === 'receivables' ? 'Customer Name' : 'Supplier Name' ?></label>
                        <input type="text" name="entity_name" placeholder="e.g. John Doe / ABC Corp" required>
                    </div>
                    <div class="form-group">
                        <label>Invoice Number</label>
                        <input type="text" name="invoice_number" placeholder="e.g. INV-001">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="narration" placeholder="Details of goods/service">
                    </div>
                    <div class="form-group">
                        <label>Amount (TZS)</label>
                        <input type="number" step="0.01" name="amount" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Attachment (Invoice)</label>
                        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="form-group" style="padding-bottom:1px;">
                        <button type="submit" class="btn-submit">+ Add Invoice</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- List -->
        <div class="card">
            <div class="oi-mobile-invoice-list" aria-label="<?= $activeTab === 'receivables' ? 'Receivables' : 'Payables' ?>">
                <?php if (empty($invoices)): ?>
                    <div class="oi-mobile-invoice-empty">No outstanding invoices found.</div>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <?php
                            $invoiceName = trim((string) $inv['entity_name']);
                            $invoiceInitials = strtoupper(substr($invoiceName, 0, 2));
                        ?>
                        <article class="oi-mobile-invoice-card">
                            <div class="oi-mobile-invoice-top">
                                <div class="oi-mobile-invoice-party">
                                    <span class="oi-mobile-invoice-avatar" aria-hidden="true"><?= htmlspecialchars($invoiceInitials) ?></span>
                                    <div class="oi-mobile-invoice-identity">
                                        <span class="oi-mobile-invoice-number"><?= htmlspecialchars($inv['invoice_number'] ?: 'Invoice') ?></span>
                                        <span class="oi-mobile-invoice-name"><?= htmlspecialchars($invoiceName) ?></span>
                                    </div>
                                </div>
                                <div class="oi-mobile-invoice-aside">
                                    <span class="oi-mobile-invoice-amount">TZS <?= number_format((float) $inv['amount'], 2) ?></span>
                                    <span class="oi-mobile-invoice-status">Outstanding</span>
                                </div>
                            </div>
                            <div class="oi-mobile-invoice-facts">
                                <div class="oi-mobile-invoice-fact">
                                    <span class="oi-mobile-invoice-fact-icon" aria-hidden="true">▦</span>
                                    <span class="oi-mobile-invoice-fact-body">
                                        <span class="oi-mobile-invoice-fact-label">Date</span>
                                        <span class="oi-mobile-invoice-fact-value"><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></span>
                                    </span>
                                </div>
                                <div class="oi-mobile-invoice-fact">
                                    <span class="oi-mobile-invoice-fact-icon" aria-hidden="true">≡</span>
                                    <span class="oi-mobile-invoice-fact-body">
                                        <span class="oi-mobile-invoice-fact-label">Description</span>
                                        <span class="oi-mobile-invoice-fact-value"><?= htmlspecialchars($inv['narration'] ?: '-') ?></span>
                                        <?php if (!empty($inv['attachment'])): ?>
                                            <a href="../../<?= htmlspecialchars($inv['attachment']) ?>" target="_blank" rel="noopener noreferrer" class="badge badge-att">View File</a>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <?php if (isFinance()): ?>
                                <div class="oi-mobile-invoice-actions">
                                    <form method="POST" onsubmit="return confirm('Mark this invoice as Paid?');">
                                        <input type="hidden" name="mark_paid" value="1">
                                        <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                                        <button type="submit" class="btn-pay">Mark Paid</button>
                                    </form>
                                    <button type="button" onclick="confirmDelete(<?= (int) $inv['id'] ?>)" class="oi-mobile-invoice-delete">Delete</button>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="oi-mobile-invoice-empty oi-search-empty" id="oi-mobile-search-empty">No invoices match your search.</div>
            </div>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Name</th>
                        <th>Invoice No</th>
                        <th>Description</th>
                        <th>Attachment</th>
                        <th class="amount-col">Amount</th>
                        <th class="actions-col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr class="oi-no-invoices-row"><td colspan="7" style="text-align:center; padding:20px; color:#6b7280;">No outstanding invoices found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                            <td><?= htmlspecialchars($inv['entity_name']) ?></td>
                            <td><?= htmlspecialchars($inv['invoice_number'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($inv['narration']) ?></td>
                            <td>
                                <?php if (!empty($inv['attachment'])): ?>
                                    <a href="../../<?= htmlspecialchars($inv['attachment']) ?>" target="_blank" class="badge badge-att">View File</a>
                                <?php else: ?>
                                    <span style="color:#9ca3af; font-size:0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="amount-col"><?= number_format($inv['amount'], 2) ?></td>
                            <td class="actions-col">
                                <?php if (isFinance()): ?>
                                <div style="display:flex; gap:8px; justify-content:flex-end;">
                                    <form method="POST" onsubmit="return confirm('Mark this invoice as Paid?');" style="display:inline;">
                                        <input type="hidden" name="mark_paid" value="1">
                                        <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                                        <button type="submit" class="btn-pay">Mark Paid</button>
                                    </form>
                                    
                                    <button type="button" onclick="confirmDelete(<?= $inv['id'] ?>)" class="icon-btn icon-danger" style="border:1px solid #fee2e2; background:#fff; width:28px; height:28px; border-radius:4px;" title="Delete">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                        </svg>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="oi-search-empty" id="oi-table-search-empty"><td colspan="7" style="text-align:center; padding:20px; color:#6b7280;">No invoices match your search.</td></tr>
                </tbody>
            </table>
            
            <?php if (!empty($invoices)): ?>
                <div class="total-box">
                    Total Outstanding: <?= number_format($totalOutstanding, 2) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Custom Confirmation Modal -->
    <div id="deleteModal" class="custom-modal-overlay">
        <div class="custom-modal">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h3>Delete Invoice?</h3>
            <p>Are you sure you want to permanently delete this invoice? This action cannot be undone.</p>
            <div class="modal-actions">
                <button onclick="closeDeleteModal()" class="btn-cancel">Cancel</button>
                <form method="POST" id="deleteForm" style="display:inline;">
                    <input type="hidden" name="delete_invoice" value="1">
                    <input type="hidden" id="delete_id" name="id" value="">
                    <button type="submit" class="btn-delete">Yes, Delete</button>
                </form>
            </div>
        </div>
    </div>

    <style>
        .custom-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .custom-modal {
            background: white;
            padding: 24px;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: modalPop 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes modalPop {
            0% { transform: scale(0.9); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .modal-icon {
            width: 48px;
            height: 48px;
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .custom-modal h3 {
            margin: 0 0 8px;
            font-size: 1.125rem;
            color: #111827;
        }
        .custom-modal p {
            margin: 0 0 24px;
            color: #6b7280;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .btn-cancel {
            padding: 8px 16px;
            border: 1px solid #d1d5db;
            background: white;
            color: #374151;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .btn-cancel:hover { background: #f3f4f6; }
        .btn-delete {
            padding: 8px 16px;
            border: none;
            background: #dc2626;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .btn-delete:hover { background: #b91c1c; }
    </style>

    <script>
        function confirmDelete(id) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        // Close on clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDeleteModal();
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const trigger = document.querySelector('.new-invoice-btn');
            const panel = document.getElementById('new-outstanding-invoice-panel');

            if (!trigger || !panel) {
                return;
            }

            trigger.addEventListener('click', function () {
                const isHidden = panel.hidden;
                panel.hidden = !isHidden;
                trigger.setAttribute('aria-expanded', String(!isHidden));
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const search = document.getElementById('oi-mobile-search-input');
            if (!search) {
                return;
            }

            const mobileCards = Array.from(document.querySelectorAll('.oi-mobile-invoice-card'));
            const tableRows = Array.from(document.querySelectorAll('.invoice-table tbody tr:not(.oi-search-empty):not(.oi-no-invoices-row)'));
            const mobileEmpty = document.getElementById('oi-mobile-search-empty');
            const mobileNoInvoices = document.querySelector('.oi-mobile-invoice-list > .oi-mobile-invoice-empty:not(.oi-search-empty)');
            const tableEmpty = document.getElementById('oi-table-search-empty');
            const tableNoInvoices = document.querySelector('.invoice-table .oi-no-invoices-row');

            search.addEventListener('input', function () {
                const query = search.value.trim().toLocaleLowerCase();
                let visibleCards = 0;
                let visibleRows = 0;

                mobileCards.forEach(function (card) {
                    const matches = !query || card.textContent.toLocaleLowerCase().includes(query);
                    card.hidden = !matches;
                    if (matches) visibleCards += 1;
                });
                tableRows.forEach(function (row) {
                    const matches = !query || row.textContent.toLocaleLowerCase().includes(query);
                    row.hidden = !matches;
                    if (matches) visibleRows += 1;
                });

                if (mobileEmpty) mobileEmpty.style.display = query && visibleCards === 0 ? 'block' : 'none';
                if (mobileNoInvoices) mobileNoInvoices.style.display = query ? 'none' : '';
                if (tableEmpty) tableEmpty.style.display = query && visibleRows === 0 ? 'table-row' : 'none';
                if (tableNoInvoices) tableNoInvoices.hidden = Boolean(query);
            });
        });
    </script>

</body>
</html>
