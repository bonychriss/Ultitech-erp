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

        $dateObj = DateTime::createFromFormat('Y-m-d', (string) $date);
        if (empty($name) || $amount <= 0) {
            $error = 'Please provide a valid Name and Amount.';
        } elseif (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
            $error = 'Please provide a valid date.';
        } elseif ($date < date('Y-m-d')) {
            $error = 'The date cannot be earlier than today.';
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

// Calculate Total Outstanding
$totalOutstanding = 0;
foreach ($invoices as $inv) {
    $totalOutstanding += $inv['amount'];
}

function oi_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, scalar|null> $extra
 */
function oi_url(array $extra = []): string
{
    $query = $_GET;
    unset($query['company_slug']);
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return '?' . http_build_query($query);
}

$search = trim((string) ($_GET['q'] ?? ''));
if (strlen($search) > 80) {
    $search = substr($search, 0, 80);
}
$sortKey = strtolower(trim((string) ($_GET['sort'] ?? 'date')));
$sortDir = strtolower(trim((string) ($_GET['dir'] ?? 'desc')));
if (!in_array($sortKey, ['date', 'name', 'number', 'amount'], true)) {
    $sortKey = 'date';
}
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}

$displayRows = $invoices;
if ($search !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
    $displayRows = array_values(array_filter($displayRows, static function (array $row) use ($needle): bool {
        $hay = implode(' ', [
            (string) ($row['entity_name'] ?? ''),
            (string) ($row['invoice_number'] ?? ''),
            (string) ($row['narration'] ?? ''),
        ]);
        $hay = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
        return str_contains($hay, $needle);
    }));
}
if ($sortKey !== 'date' || $sortDir !== 'desc') {
    $mult = $sortDir === 'asc' ? 1 : -1;
    usort($displayRows, static function (array $a, array $b) use ($sortKey, $mult): int {
        switch ($sortKey) {
            case 'name':
                $cmp = strcasecmp((string) ($a['entity_name'] ?? ''), (string) ($b['entity_name'] ?? ''));
                break;
            case 'number':
                $cmp = strnatcasecmp((string) ($a['invoice_number'] ?? ''), (string) ($b['invoice_number'] ?? ''));
                break;
            case 'amount':
                $cmp = ((float) ($a['amount'] ?? 0)) <=> ((float) ($b['amount'] ?? 0));
                break;
            default:
                $cmp = strcmp((string) ($a['invoice_date'] ?? ''), (string) ($b['invoice_date'] ?? ''));
                break;
        }
        if ($cmp === 0) {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        }
        return $cmp * $mult;
    });
}

$filteredTotal = 0.0;
foreach ($displayRows as $row) {
    $filteredTotal += (float) ($row['amount'] ?? 0);
}

$pageSize = 10;
$resultCount = count($displayRows);
$pageCount = max(1, (int) ceil($resultCount / $pageSize));
$page = (int) ($_GET['p'] ?? 1);
if ($page < 1) {
    $page = 1;
}
if ($page > $pageCount) {
    $page = $pageCount;
}
$pageRows = array_slice($displayRows, ($page - 1) * $pageSize, $pageSize);
$rangeFrom = $resultCount === 0 ? 0 : (($page - 1) * $pageSize) + 1;
$rangeTo = min($resultCount, $page * $pageSize);

function oi_sort_url(string $key, string $currentKey, string $currentDir): string
{
    $next = 'desc';
    if ($currentKey === $key) {
        $next = $currentDir === 'desc' ? 'asc' : 'desc';
    } elseif ($key === 'name' || $key === 'number') {
        $next = 'asc';
    }
    return oi_url(['sort' => $key, 'dir' => $next, 'p' => null]);
}

$isFinanceUser = isFinance();
$entityLabel = $activeTab === 'receivables' ? 'Customer' : 'Supplier';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outstanding Invoices - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        body { background: #f3f4f6; }
        .main-content { max-width: 1200px; margin: 0 auto; padding: 20px; }
        html body .main-content.oi-page { max-width: 1400px !important; margin: 0 auto !important; padding: 0.35rem 22px 2.4rem !important; background: transparent; }
        @media (max-width: 780px) {
            html body .main-content.oi-page { padding-left: 16px !important; padding-right: 16px !important; }
        }
        .oi-table a.badge-att { background: #e0f2fe !important; color: #0369a1 !important; font-weight: 600; opacity: 1; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-title { font-size: 1.5rem; font-weight: bold; color: #111; }
        
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; }
        .tab-link { padding: 10px 20px; text-decoration: none; color: #6b7280; border-bottom: 2px solid transparent; font-weight: 500; }
        .tab-link.active { color: #2563eb; border-bottom-color: #2563eb; }
        .tab-link:hover { color: #111; }
        
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        
        .oi-form-card { background: #fff; border: 1px solid #e7edf3; border-radius: 16px; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04); margin-bottom: 20px; }
        .oi-form-head { display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.15rem 0.85rem; border-bottom: 1px solid #eef2f6; }
        .oi-form-head h2 { margin: 0; font-size: 1.05rem; font-weight: 760; color: #0f172a; }
        .oi-form-head p { margin: 0.1rem 0 0; color: #64748b; font-size: 0.82rem; }
        .oi-form-mark { width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .oi-form-mark svg { width: 20px; height: 20px; }
        .oi-form-mark--in { background: #e8f8ee; color: #16a34a; }
        .oi-form-mark--out { background: #fdecec; color: #dc2626; }
        .oi-form { padding: 1rem 1.15rem 1.1rem; }
        .oi-form-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 0.9rem 1rem; }
        .oi-span-3 { grid-column: span 3; }
        .oi-span-4 { grid-column: span 4; }
        .oi-span-5 { grid-column: span 5; }
        .oi-span-8 { grid-column: span 8; }
        .oi-span-12 { grid-column: span 12; }
        .oi-field { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; }
        .oi-field > label { font-size: 0.76rem; font-weight: 700; color: #475569; letter-spacing: 0.01em; }
        .oi-field > label em { color: #dc2626; font-style: normal; }
        .oi-field input[type="text"], .oi-field input[type="date"], .oi-field input[type="number"] { width: 100%; height: 2.6rem; border: 1px solid #d7dee7; border-radius: 12px !important; background: #fff; color: #0f172a; padding: 0 0.85rem; font-size: 0.9rem; font-weight: 550; transition: border-color 0.15s, box-shadow 0.15s; }
        .oi-field input::placeholder { color: #94a3b8; font-weight: 500; }
        .oi-field input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .oi-money { display: flex; align-items: stretch; border: 1px solid #d7dee7; border-radius: 12px; overflow: hidden; background: #fff; transition: border-color 0.15s, box-shadow 0.15s; }
        .oi-money:focus-within { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .oi-money span { display: flex; align-items: center; padding: 0 0.75rem; background: #f1f5f9; color: #475569; font-size: 0.78rem; font-weight: 800; border-right: 1px solid #d7dee7; }
        .oi-field .oi-money input[type="number"] { border: 0; border-radius: 0 !important; box-shadow: none; text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; }
        .oi-file { position: relative; display: flex; align-items: center; gap: 0.75rem; min-height: 2.9rem; padding: 0.35rem 0.45rem; border: 1px dashed #cbd5e1; border-radius: 12px; background: #f8fafc; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
        .oi-file:hover { border-color: #2563eb; background: #f1f6ff; }
        .oi-file input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .oi-file-btn { display: inline-flex; align-items: center; gap: 0.4rem; height: 2.1rem; padding: 0 0.8rem; border-radius: 10px; background: #fff; border: 1px solid #d7dee7; color: #0f172a; font-size: 0.82rem; font-weight: 700; white-space: nowrap; }
        .oi-file-btn svg { width: 15px; height: 15px; }
        .oi-file-name { color: #64748b; font-size: 0.84rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
        .oi-file.has-file .oi-file-name { color: #0f172a; font-weight: 650; }
        .oi-form-foot { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; padding-top: 0.9rem; border-top: 1px solid #eef2f6; }
        .oi-btn-primary, .oi-btn-ghost { height: 2.5rem; padding: 0 1.1rem; border-radius: 12px; font-size: 0.88rem; font-weight: 700; cursor: pointer; }
        .oi-btn-primary { background: #2563eb; color: #fff; border: 1px solid #2563eb; box-shadow: 0 6px 14px rgba(37, 99, 235, 0.2); }
        .oi-btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .oi-btn-ghost { background: #fff; color: #334155; border: 1px solid #d7dee7; }
        .oi-btn-ghost:hover { background: #f8fafc; }
        html[data-theme="dark"] .oi-form-card { background: #1e293b; border-color: #334155; }
        html[data-theme="dark"] .oi-form-head, html[data-theme="dark"] .oi-form-foot { border-color: #334155; }
        html[data-theme="dark"] .oi-form-head h2 { color: #f8fafc; }
        html[data-theme="dark"] .oi-field > label { color: #cbd5e1; }
        html[data-theme="dark"] .oi-field input[type="text"],
        html[data-theme="dark"] .oi-field input[type="date"],
        html[data-theme="dark"] .oi-field input[type="number"],
        html[data-theme="dark"] .oi-money,
        html[data-theme="dark"] .oi-file-btn,
        html[data-theme="dark"] .oi-btn-ghost { background: #0f172a; color: #f8fafc; border-color: #334155; }
        html[data-theme="dark"] .oi-money span { background: #1e293b; color: #cbd5e1; border-color: #334155; }
        html[data-theme="dark"] .oi-file { background: #0f172a; border-color: #475569; }
        html[data-theme="dark"] .oi-file.has-file .oi-file-name { color: #f8fafc; }
        @media (max-width: 900px) {
            .oi-span-3, .oi-span-4, .oi-span-5 { grid-column: span 6; }
            .oi-span-8 { grid-column: span 12; }
        }
        @media (max-width: 560px) {
            .oi-span-3, .oi-span-4, .oi-span-5, .oi-span-8 { grid-column: span 12; }
            .oi-form-foot { flex-direction: column-reverse; }
            .oi-btn-primary, .oi-btn-ghost { width: 100%; }
        }
        
        .oi-rank-card { background: #fff; border: 1px solid #e7edf3; border-radius: 16px; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04); margin-bottom: 20px; overflow: hidden; }
        .oi-rank-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; padding: 0.95rem 1rem 0.75rem; }
        .oi-rank-head h2 { margin: 0; font-size: 1.05rem; font-weight: 760; color: #0f172a; }
        .oi-rank-head p { margin: 0.15rem 0 0; color: #64748b; font-size: 0.82rem; }
        .oi-search { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; }
        .oi-search input { height: 2.25rem; border: 1px solid #e2e8f0; border-radius: 999px !important; padding: 0 0.95rem; min-width: 290px; font-weight: 550; }
        .oi-rank-card .oi-search input[type="search"] { border-radius: 999px !important; -webkit-appearance: none; appearance: none; background-clip: padding-box; }
        .oi-rank-card .oi-search input[type="search"]:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .oi-search button, .oi-clear { height: 2.25rem; border-radius: 999px; border: 1px solid #e2e8f0; background: #fff; color: #334155; font-weight: 700; padding: 0 0.75rem; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
        .oi-table-wrap { overflow-x: auto; }
        .oi-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 820px; }
        .oi-table thead th { background: #1e293b; color: #fff; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; text-align: left; padding: 0.72rem 0.8rem; white-space: nowrap; border: 0; }
        .oi-table thead th a { color: inherit; text-decoration: none; }
        .oi-table thead th a:hover { color: #f8fafc; }
        .oi-table tbody td { padding: 0.72rem 0.8rem; border-bottom: 1px solid #eef2f6; font-size: 0.9rem; vertical-align: middle; text-align: left; }
        .oi-table tbody tr:nth-child(even) td { background: #f8fafc; }
        .oi-table tbody tr:hover td { background: #eef4ff; }
        .oi-col-sn { width: 52px; color: #64748b; font-weight: 700; }
        .oi-table .oi-num, .oi-table thead th.oi-num { text-align: right; }
        .oi-table .oi-actions, .oi-table thead th.oi-actions { text-align: right; width: 1%; white-space: nowrap; }
        .oi-name { color: #0f172a; font-weight: 700; }
        .oi-desc { color: #475569; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .oi-amt { font-variant-numeric: tabular-nums; font-weight: 650; }
        .oi-muted { color: #9ca3af; font-size: 0.8rem; }
        .oi-empty { padding: 2rem 1rem; text-align: center; color: #475569; border-top: 1px dashed #cbd5e1; }
        .oi-empty strong { display: block; color: #0f172a; font-size: 1.02rem; margin-bottom: 0.3rem; }
        .oi-pager { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem 0.95rem; color: #64748b; font-size: 0.82rem; flex-wrap: wrap; }
        .oi-total { color: #059669; font-weight: 750; font-size: 0.95rem; }
        .oi-pages { display: flex; gap: 0.3rem; flex-wrap: wrap; }
        .oi-pages a, .oi-pages span { min-width: 2rem; height: 2rem; padding: 0 0.45rem; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; color: #334155; border: 1px solid #e2e8f0; background: #fff; font-weight: 700; }
        .oi-pages span { background: #1e293b; color: #fff; border-color: #1e293b; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 500; }
        .badge-att { background: #e0f2fe; color: #0369a1; text-decoration: none; }

        html[data-theme="dark"] .oi-rank-card,
        html[data-theme="dark"] .oi-search input,
        html[data-theme="dark"] .oi-search button,
        html[data-theme="dark"] .oi-clear,
        html[data-theme="dark"] .oi-pages a { background: #1e293b; color: #f8fafc; border-color: #334155; }
        html[data-theme="dark"] .oi-rank-head h2,
        html[data-theme="dark"] .oi-name,
        html[data-theme="dark"] .oi-empty strong { color: #f8fafc; }
        html[data-theme="dark"] .oi-table thead th { background: #0f172a; }
        html[data-theme="dark"] .oi-table tbody td,
        html[data-theme="dark"] .oi-table tbody tr:nth-child(even) td { background: #1e293b; color: #e2e8f0; border-color: #334155; }
        html[data-theme="dark"] .oi-table tbody tr:hover td { background: #334155; }
        html[data-theme="dark"] .oi-desc { color: #cbd5e1; }
        @media (max-width: 780px) {
            .oi-rank-head { flex-direction: column; }
            .oi-search, .oi-search input { width: 100%; }
            .oi-search input { min-width: 0; flex: 1; }
            .oi-pager { flex-direction: column; align-items: flex-start; }
        }
        
        .oi-btn-primary, .oi-btn-ghost, .oi-file-btn { border-radius: 12px !important; }
        .oi-search button { border-radius: 999px !important; }
        .btn-pay { border-radius: 8px !important; font-weight: 600; }
        .oi-actions .icon-btn { border-radius: 8px !important; display: inline-flex; align-items: center; justify-content: center; }
        .btn-cancel, .btn-delete { border-radius: 10px !important; }
        .btn-pay { background: transparent; border: 1px solid #10b981; color: #10b981; padding: 4px 10px; cursor: pointer; font-size: 0.8rem; }
        .btn-pay:hover { background: #10b981; color: #fff; }
    </style>
</head>
<body>
    <?php include '../../includes/header_employee.php'; // Using employee header for simplified nav, check role if needed ?>
    
    <div class="main-content oi-page">
        <div class="page-header">
            <div>
                <div class="page-title">Outstanding Invoices</div>
            </div>
        </div>

        <?php if ($success): ?><div style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="tabs">
            <a href="<?= oi_h(oi_url(['tab' => 'receivables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>" class="tab-link <?= $activeTab === 'receivables' ? 'active' : '' ?>">Receivables (Income)</a>
            <a href="<?= oi_h(oi_url(['tab' => 'payables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>" class="tab-link <?= $activeTab === 'payables' ? 'active' : '' ?>">Payables (Expenses)</a>
        </div>

        <!-- Add New Form -->
        <section class="oi-form-card">
            <div class="oi-form-head">
                <span class="oi-form-mark oi-form-mark--<?= $activeTab === 'receivables' ? 'in' : 'out' ?>" aria-hidden="true">
                    <?php if ($activeTab === 'receivables'): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M19 12l-7 7-7-7"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="M5 12l7-7 7 7"/></svg>
                    <?php endif; ?>
                </span>
                <div>
                    <h2>Add <?= $activeTab === 'receivables' ? 'receivable' : 'payable' ?></h2>
                    <p><?= $activeTab === 'receivables' ? 'Record money a customer still owes you.' : 'Record money you still owe a supplier.' ?></p>
                </div>
            </div>
            <form method="POST" enctype="multipart/form-data" class="oi-form" id="oiAddForm">
                <input type="hidden" name="create_invoice" value="1">
                <input type="hidden" name="type" value="<?= $typeFilter ?>">
                <div class="oi-form-grid">
                    <div class="oi-field oi-span-3">
                        <label for="oiDate">Date <em>*</em></label>
                        <input type="date" id="oiDate" name="invoice_date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="oi-field oi-span-5">
                        <label for="oiEntity"><?= $entityLabel ?> name <em>*</em></label>
                        <input type="text" id="oiEntity" name="entity_name" placeholder="e.g. John Doe / ABC Corp" required autocomplete="off">
                    </div>
                    <div class="oi-field oi-span-4">
                        <label for="oiNumber">Invoice number</label>
                        <input type="text" id="oiNumber" name="invoice_number" placeholder="e.g. INV-001" autocomplete="off">
                    </div>
                    <div class="oi-field oi-span-8">
                        <label for="oiNarration">Description</label>
                        <input type="text" id="oiNarration" name="narration" placeholder="Details of goods or service">
                    </div>
                    <div class="oi-field oi-span-4">
                        <label for="oiAmount">Amount <em>*</em></label>
                        <div class="oi-money">
                            <span>TZS</span>
                            <input type="number" id="oiAmount" step="0.01" min="0.01" name="amount" placeholder="0.00" required>
                        </div>
                    </div>
                    <div class="oi-field oi-span-12">
                        <label for="oiFile">Attachment (invoice)</label>
                        <label class="oi-file" for="oiFile">
                            <input type="file" id="oiFile" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
                            <span class="oi-file-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>
                                Choose file
                            </span>
                            <span class="oi-file-name" data-empty="PDF, JPG or PNG (optional)">PDF, JPG or PNG (optional)</span>
                        </label>
                    </div>
                </div>
                <div class="oi-form-foot">
                    <button type="reset" class="oi-btn-ghost">Clear</button>
                    <button type="submit" class="oi-btn-primary">+ Add invoice</button>
                </div>
            </form>
        </section>

        <!-- List -->
        <section class="oi-rank-card">
            <div class="oi-rank-head">
                <div>
                    <h2><?= $activeTab === 'receivables' ? 'Outstanding receivables' : 'Outstanding payables' ?></h2>
                    <p>Invoices not yet paid, newest first unless you sort by a column</p>
                </div>
                <form class="oi-search" method="get" action="">
                    <?php foreach ($_GET as $queryKey => $queryValue): ?>
                        <?php if (in_array((string) $queryKey, ['q', 'p', 'company_slug'], true) || is_array($queryValue)) { continue; } ?>
                        <input type="hidden" name="<?= oi_h((string) $queryKey) ?>" value="<?= oi_h((string) $queryValue) ?>">
                    <?php endforeach; ?>
                    <input type="search" name="q" value="<?= oi_h($search) ?>" placeholder="Search <?= strtolower($entityLabel) ?>, invoice, description" maxlength="80" aria-label="Search invoices">
                    <button type="submit">Search</button>
                    <?php if ($search !== ''): ?>
                        <a class="oi-clear" href="<?= oi_h(oi_url(['q' => null, 'p' => null])) ?>">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($pageRows === []): ?>
                <div class="oi-empty">
                    <strong><?= $search !== '' ? 'No invoices match that search.' : 'No outstanding invoices found.' ?></strong>
                </div>
            <?php else: ?>
            <div class="oi-table-wrap">
            <table class="oi-table">
                <thead>
                    <tr>
                        <th class="oi-col-sn">No.</th>
                        <?php
                        $heads = [
                            'date' => ['Date', ''],
                            'name' => [$entityLabel, ''],
                            'number' => ['Invoice no', ''],
                        ];
                        foreach ($heads as $key => $meta):
                        ?>
                            <th class="<?= oi_h($meta[1]) ?>"><a href="<?= oi_h(oi_sort_url($key, $sortKey, $sortDir)) ?>"><?= oi_h($meta[0]) ?><?php if ($sortKey === $key): ?> <?= $sortDir === 'asc' ? '&#8593;' : '&#8595;' ?><?php endif; ?></a></th>
                        <?php endforeach; ?>
                        <th>Description</th>
                        <th>Attachment</th>
                        <th class="oi-num"><a href="<?= oi_h(oi_sort_url('amount', $sortKey, $sortDir)) ?>">Amount<?php if ($sortKey === 'amount'): ?> <?= $sortDir === 'asc' ? '&#8593;' : '&#8595;' ?><?php endif; ?></a></th>
                        <?php if ($isFinanceUser): ?><th class="oi-actions">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                        <?php foreach ($pageRows as $rowIndex => $inv): ?>
                        <tr>
                            <td class="oi-col-sn"><?= $rangeFrom + $rowIndex ?></td>
                            <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                            <td><span class="oi-name"><?= htmlspecialchars($inv['entity_name']) ?></span></td>
                            <td><?= htmlspecialchars(($inv['invoice_number'] ?? '') !== '' ? $inv['invoice_number'] : '-') ?></td>
                            <td><div class="oi-desc" title="<?= htmlspecialchars((string) $inv['narration']) ?>"><?= htmlspecialchars((string) $inv['narration']) !== '' ? htmlspecialchars((string) $inv['narration']) : '<span class="oi-muted">-</span>' ?></div></td>
                            <td>
                                <?php if (!empty($inv['attachment'])): ?>
                                    <a href="../../<?= htmlspecialchars($inv['attachment']) ?>" target="_blank" class="badge badge-att">View File</a>
                                <?php else: ?>
                                    <span class="oi-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="oi-num"><span class="oi-amt"><?= number_format($inv['amount'], 2) ?></span></td>
                            <?php if ($isFinanceUser): ?>
                            <td class="oi-actions">
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
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <?php if ($invoices !== []): ?>
                <div class="oi-pager">
                    <span>
                        <?php if ($resultCount > 0): ?>Showing <?= number_format($rangeFrom) ?>-<?= number_format($rangeTo) ?> of <?= number_format($resultCount) ?> &middot; <?php endif; ?>
                        <span class="oi-total">
                            <?php if ($search !== ''): ?>
                                Matching total: <?= number_format($filteredTotal, 2) ?> &middot; Total outstanding: <?= number_format($totalOutstanding, 2) ?>
                            <?php else: ?>
                                Total outstanding: <?= number_format($totalOutstanding, 2) ?>
                            <?php endif; ?>
                        </span>
                    </span>
                    <?php if ($pageCount > 1): ?>
                        <div class="oi-pages">
                            <?php if ($page > 1): ?><a href="<?= oi_h(oi_url(['p' => $page - 1])) ?>">Prev</a><?php endif; ?>
                            <?php for ($i = 1; $i <= $pageCount; $i++): ?>
                                <?php if ($i === $page): ?><span><?= $i ?></span><?php else: ?><a href="<?= oi_h(oi_url(['p' => $i === 1 ? null : $i])) ?>"><?= $i ?></a><?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($page < $pageCount): ?><a href="<?= oi_h(oi_url(['p' => $page + 1])) ?>">Next</a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
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
        (function () {
            var form = document.getElementById('oiAddForm');
            var input = document.getElementById('oiFile');
            if (!form || !input) return;
            var box = input.closest('.oi-file');
            var nameEl = box ? box.querySelector('.oi-file-name') : null;
            function syncFileName() {
                if (!box || !nameEl) return;
                var file = input.files && input.files[0];
                nameEl.textContent = file ? file.name : nameEl.getAttribute('data-empty');
                box.classList.toggle('has-file', !!file);
            }
            input.addEventListener('change', syncFileName);
            form.addEventListener('reset', function () { window.setTimeout(syncFileName, 0); });
        })();

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
</body>
</html>
