<?php
require_once '../../includes/functions.php';
requireLogin();
ensureOutstandingInvoicesSchema();
$rootPath = '../../';
$modulesLink = '../../select-module.php';
$logoBase = '../../';

$success = (string) ($_SESSION['outstanding_invoice_success'] ?? '');
unset($_SESSION['outstanding_invoice_success']);
$error = '';

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
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_outstanding_invoices (type, invoice_date, entity_name, invoice_number, narration, amount, attachment) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$type, $date, $name, $invoiceNo, $narration, $amount, $attachmentPath])) {
                $_SESSION['outstanding_invoice_success'] = 'Invoice added successfully.';
                $returnQuery = $_GET;
                unset($returnQuery['entry']);
                header('Location: ?' . http_build_query($returnQuery), true, 303);
                exit;
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
$activeTab = (($_GET['tab'] ?? 'receivables') === 'payables') ? 'payables' : 'receivables';
$entryMode = (($_GET['entry'] ?? '') === 'new');
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
        .new-invoice-btn:hover { background: #1d4ed8; }
        .oi-header-search { width: min(26rem, 38vw); max-width: 26rem; min-width: 12rem; margin: 0; position: relative; z-index: 35; }
        .oi-header-search-field { position: relative; display: flex; align-items: center; width: 100%; height: 2.45rem; border: 1px solid #d7dee7; border-radius: 9999px; background: #fff; overflow: hidden; }
        .oi-header-search-field:focus-within { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12); }
        .oi-header-search-icon { position: absolute; left: 0.9rem; width: 1rem; height: 1rem; color: #94a3b8; pointer-events: none; }
        .oi-header-search-input { width: 100%; min-width: 0; height: 100%; padding: 0 5.2rem 0 2.5rem; border: 0 !important; border-radius: 9999px !important; outline: 0 !important; box-shadow: none !important; background: transparent !important; color: #0f172a; font: inherit; font-size: 0.875rem; }
        .oi-header-search-input::-webkit-search-cancel-button { display: none; }
        .oi-header-search-clear { position: absolute; right: 2.6rem; width: 1.8rem; height: 1.8rem; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; color: #64748b; text-decoration: none; }
        .oi-header-search-clear:hover { background: #f1f5f9; color: #334155; }
        .oi-header-search-submit { position: absolute; right: 0.2rem; width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 50%; background: transparent; color: #475569; cursor: pointer; }
        .oi-header-search-submit:hover { background: transparent; color: #0f172a; }
        .oi-header-search-submit svg { width: 1rem; height: 1rem; }
        .oi-header-search--mobile { display: none; }
        body.page-outstanding-invoices .employee-header--outstanding .header-content { position: relative; }
        body.page-outstanding-invoices .employee-header--outstanding .employee-header-center-slot { position: absolute; left: 50%; top: calc(50% + 14px); width: min(26rem, 38vw); flex: 0 0 auto !important; transform: translate(-50%, -50%); z-index: 30; }
        body.page-outstanding-invoices .employee-header--outstanding .header-right { position: relative; z-index: 35; }
        body.page-outstanding-invoices .employee-header--outstanding .header-actions-tray { pointer-events: none; }
        body.page-outstanding-invoices .employee-header--outstanding .header-actions-tray > * { pointer-events: auto; }
        @media (max-width: 767.98px) {
            body.page-outstanding-invoices .employee-header--outstanding .employee-header-center-slot,
            .oi-header-search--desktop { display: none !important; }
            .oi-header-search--mobile { display: block; width: 100%; max-width: none; min-width: 0; margin: 0 0 0.9rem; }
        }
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
        
        .oi-form-card { background: #fff; border: 1px solid #e7edf3; border-radius: 16px; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04); margin-bottom: 20px; }
        .oi-form-back { float: right; color: #475569; font-size: 0.82rem; font-weight: 700; text-decoration: none; }
        .oi-form-back:hover { color: #1d4ed8; }
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
        
        .oi-rank-card { background: transparent; border: 0; border-radius: 0; box-shadow: none; margin-bottom: 20px; overflow: visible; }
        .oi-rank-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; padding: 0 0 0.65rem; }
        .oi-rank-head h2 { margin: 0; font-size: 1.05rem; font-weight: 760; color: #0f172a; }
        .oi-rank-head p { margin: 0.15rem 0 0; color: #64748b; font-size: 0.82rem; }
        .oi-search { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; }
        .oi-search input { height: 2.25rem; border: 1px solid #e2e8f0; border-radius: 999px !important; padding: 0 0.95rem; min-width: 290px; font-weight: 550; }
        .oi-rank-card .oi-search input[type="search"] { border-radius: 999px !important; -webkit-appearance: none; appearance: none; background-clip: padding-box; }
        .oi-rank-card .oi-search input[type="search"]:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .oi-search button, .oi-clear { height: 2.25rem; border-radius: 999px; border: 1px solid #e2e8f0; background: #fff; color: #334155; font-weight: 700; padding: 0 0.75rem; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
        .oi-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; }
        .oi-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 820px; font-size: 0.78rem; }
        .oi-table thead th { background: #f8fafc; color: #0f172a; font-size: 0.75rem; font-weight: 600; text-align: left; padding: 0.55rem 0.45rem; white-space: nowrap; border: 0; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .oi-table th:first-child, .oi-table td:first-child { padding-left: 0.75rem; }
        .oi-table th:last-child, .oi-table td:last-child { padding-right: 0.75rem; }
        .oi-table thead th a { color: inherit; text-decoration: none; }
        .oi-table thead th a:hover { color: #f8fafc; }
        .oi-table tbody td { padding: 0.55rem 0.45rem; border-bottom: 1px solid #f1f5f9; font-size: 0.78rem; vertical-align: middle; text-align: left; }
        .oi-table tbody tr:hover td { background: #ecfdf5; }
        .oi-col-sn { width: 52px; color: #64748b; font-weight: 700; }
        .oi-table .oi-num, .oi-table thead th.oi-num { text-align: right; }
        .oi-table .oi-actions, .oi-table thead th.oi-actions { text-align: right; width: 1%; white-space: nowrap; }
        .oi-table a.oi-attachment-link { display: inline-flex; align-items: center; gap: 0.35rem; max-width: 13rem; padding: 0.3rem 0.45rem; border: 1px solid #e2e8f0 !important; border-radius: 8px; background: #f8fafc !important; color: #1d4ed8 !important; font-size: 0.75rem; font-weight: 650; text-decoration: none; vertical-align: middle; }
        .oi-table a.oi-attachment-link:hover { text-decoration: underline; }
        .oi-table a.oi-attachment-link svg { width: 0.9rem; height: 0.9rem; flex: 0 0 auto; }
        .oi-table a.oi-attachment-link span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .oi-row-actions { position: relative; display: inline-flex; }
        .oi-row-actions-trigger { width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; padding: 0; border: 1px solid transparent; border-radius: 8px; background: transparent; color: #64748b; cursor: pointer; list-style: none; }
        .oi-row-actions-trigger::-webkit-details-marker { display: none; }
        .oi-row-actions-trigger:hover, .oi-row-actions[open] .oi-row-actions-trigger { border-color: #e2e8f0; background: #f8fafc; color: #0f172a; }
        .oi-row-actions-trigger svg { width: 18px; height: 18px; }
        .oi-row-actions-menu { position: absolute; top: calc(100% + 0.3rem); right: 0; z-index: 50; display: grid; min-width: 9rem; gap: 0.15rem; padding: 0.3rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14); }
        .oi-row-action-item { display: flex; width: 100%; align-items: center; padding: 0.55rem 0.65rem; border: 0; border-radius: 5px; background: transparent; color: #334155; font: inherit; font-size: 0.82rem; font-weight: 650; text-align: left; text-decoration: none; cursor: pointer; }
        .oi-row-action-item:hover { background: #f1f5f9; color: #0f172a; }
        .oi-row-action-item--delete { color: #b91c1c; }
        .oi-row-action-item--delete:hover { background: #fef2f2; color: #991b1b; }
        .oi-invoice-row { cursor: pointer; }
        .oi-invoice-row:focus-visible td { outline: 2px solid #2563eb; outline-offset: -2px; }
        .oi-details-overlay { position: fixed; inset: 0; z-index: 10000; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, 0.45); }
        .oi-details-modal { width: min(32rem, calc(100vw - 1.5rem)); max-height: calc(100vh - 2rem); overflow: hidden; display: flex; flex-direction: column; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2); }
        .oi-details-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; padding: 1rem 1.15rem 0.85rem; border-bottom: 1px solid #e2e8f0; }
        .oi-details-head-main { min-width: 0; }
        .oi-details-head h2 { margin: 0; color: #0f172a; font-size: 1.1rem; font-weight: 800; line-height: 1.25; }
        .oi-details-head p { margin: 0.2rem 0 0; color: #0f172a; font-size: 0.875rem; font-weight: 600; }
        .oi-details-close { width: 2rem; height: 2rem; flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 8px; background: transparent; color: #64748b; cursor: pointer; }
        .oi-details-close:hover { background: #f1f5f9; color: #0f172a; }
        .oi-details-close svg { width: 1rem; height: 1rem; }
        .oi-details-meta { display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap; margin-top: 0.55rem; color: #0f172a; font-size: 0.8125rem; }
        .oi-details-type { padding: 0.25rem 0.55rem; border-radius: 999px; background: #e8f8ee; color: #15803d; font-size: 0.75rem; font-weight: 700; }
        .oi-details-type--payable { background: #fdecec; color: #b91c1c; }
        .oi-details-body { padding: 1rem 1.15rem; display: grid; gap: 0.9rem; overflow-y: auto; }
        .oi-details-list { display: grid; gap: 0.5rem; margin: 0; }
        .oi-details-row { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; font-size: 0.875rem; }
        .oi-details-row dt { margin: 0; color: #0f172a; font-weight: 600; flex: 0 0 auto; }
        .oi-details-row dd { min-width: 0; margin: 0; color: #0f172a; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
        .oi-details-row dd.oi-details-amount { color: #16a34a; font-weight: 700; font-variant-numeric: tabular-nums; }
        .oi-details-attachments { margin-top: 0.85rem; padding-top: 0.85rem; border-top: 1px solid #e2e8f0; }
        .oi-details-attachments[hidden] { display: none; }
        .oi-details-attachments-label { margin-bottom: 0.4rem; color: #64748b; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
        .oi-details-attachment-link { display: inline-flex; align-items: center; gap: 0.4rem; max-width: 100%; padding: 0.4rem 0.55rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #1d4ed8; font-size: 0.8rem; font-weight: 600; line-height: 1.25; text-decoration: none; }
        .oi-details-attachment-link:hover { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
        .oi-details-attachment-link svg { width: 0.95rem; height: 0.95rem; }
        .oi-details-attachment-link span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
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

        html[data-theme="dark"] .oi-table-wrap,
        html[data-theme="dark"] .oi-search input,
        html[data-theme="dark"] .oi-search button,
        html[data-theme="dark"] .oi-clear,
        html[data-theme="dark"] .oi-pages a { background: #111b21; color: #e9edef; border-color: #2a3942; }
        html[data-theme="dark"] .oi-rank-head h2,
        html[data-theme="dark"] .oi-name,
        html[data-theme="dark"] .oi-empty strong { color: #f8fafc; }
        html[data-theme="dark"] .oi-table thead th { background: #111b21; color: #8696a0; border-color: #2a3942; }
        html[data-theme="dark"] .oi-table tbody td,
        html[data-theme="dark"] .oi-table tbody tr:nth-child(even) td { background: #111b21; color: #e9edef; border-color: #2a3942; }
        html[data-theme="dark"] .oi-table tbody tr:hover td { background: #202c33; }
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
        html[data-theme="dark"] body.page-outstanding-invoices,
        html[data-theme="dark"] body.page-outstanding-invoices .layout-main-wrapper,
        html[data-theme="dark"] body.page-outstanding-invoices .layout-main-wrapper > .flex-grow-1,
        html[data-theme="dark"] body.page-outstanding-invoices .main-content.oi-page {
            background: #0b141a !important;
            background-color: #0b141a !important;
            color: #e9edef !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .employee-header.employee-header--page-context {
            background: #0b141a !important;
            background-color: #0b141a !important;
            border: 0 !important;
            box-shadow: none !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .employee-header-page-title { color: #e9edef !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-field { background: #111b21; border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-input { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-input::placeholder { color: #8696a0; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-icon { color: #8696a0; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-clear { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-clear:hover { background: #26343c; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-submit,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-submit:hover { background: transparent; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .tabs { border-bottom-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .tab-link { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .tab-link.active { color: #00a884; border-bottom-color: #00a884; }
        html[data-theme="dark"] body.page-outstanding-invoices .tab-link:hover { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-card,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table-wrap { background: #111b21; border-color: #2a3942; box-shadow: none; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-actions-trigger { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-actions-trigger:hover,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-actions[open] .oi-row-actions-trigger { background: #202c33; border-color: #2a3942; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-actions-menu { background: #111b21; border-color: #2a3942; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.35); }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-action-item { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-action-item:hover { background: #202c33; color: #fff; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-action-item--delete { color: #fca5a5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-action-item--delete:hover { background: #3b1b20; color: #fecaca; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-modal { background: #1e293b; border-color: #334155; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45); }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-head { border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-head h2,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-head p,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-meta,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-row dt,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-row dd { color: #f8fafc; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-row dd.oi-details-amount { color: #4ade80; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-head p,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-meta,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-attachments-label { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-close { background: #111b21; border-color: #2a3942; color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-close:hover { background: #202c33; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-attachments { border-color: #334155; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-type { background: #123026; color: #b7f7d8; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-type--payable { background: #3b1b20; color: #fecaca; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-attachment-link,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table a.oi-attachment-link { background: #0b141a !important; border-color: #2a3942 !important; color: #93c5fd !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-attachment-link:hover,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table a.oi-attachment-link:hover { background: #202c33 !important; border-color: #334155 !important; color: #bfdbfe !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-foot { border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-head h2,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-rank-head h2,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-empty strong,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-name { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-head p,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-rank-head p,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-back,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-pager,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-empty,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-muted,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-col-sn,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-desc,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-file-name { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field > label { color: #d3dde2; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field input[type="text"],
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field input[type="date"],
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field input[type="number"],
        html[data-theme="dark"] body.page-outstanding-invoices .oi-money,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-file,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-file-btn,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-btn-ghost,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-search input,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-search button,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-clear,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-pages a {
            background: #0b141a;
            color: #e9edef;
            border-color: #2a3942;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field input::placeholder,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-search input::placeholder { color: #8696a0; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-money span { background: #111b21; color: #b0bec5; border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-file:hover { background: #14222a; border-color: #00a884; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table thead th {
            background: #111b21 !important;
            background-color: #111b21 !important;
            color: #8696a0 !important;
            border-bottom-color: #2a3942 !important;
            box-shadow: inset 0 -1px 0 #2a3942 !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table tbody td,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table tbody tr:nth-child(even) td {
            background: #111b21 !important;
            background-color: #111b21 !important;
            color: #e9edef !important;
            border-bottom-color: #2a3942 !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table tbody tr:hover td {
            background: #202c33 !important;
            background-color: #202c33 !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-total { color: #34d399; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-success-notice { background: #123026 !important; color: #b7f7d8 !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-error-notice { background: #3b1b20 !important; color: #fecaca !important; }
    </style>
</head>
<body class="page-outstanding-invoices">
    <?php
    $hideHeaderCompanyBranding = true;
    $employeeHeaderTitle = 'Outstanding Invoices';
    $employeeHeaderExtraClass = 'employee-header--outstanding';
    $searchHiddenInputs = '';
    foreach ($_GET as $queryKey => $queryValue) {
        if (in_array((string) $queryKey, ['q', 'p', 'company_slug'], true) || is_array($queryValue)) {
            continue;
        }
        $searchHiddenInputs .= '<input type="hidden" name="' . oi_h((string) $queryKey) . '" value="' . oi_h((string) $queryValue) . '">';
    }
    $searchFieldMarkup = '<div class="oi-header-search-field">'
        . '<svg class="oi-header-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>'
        . '<input class="oi-header-search-input" type="search" name="q" value="' . oi_h($search) . '" placeholder="Search ' . strtolower($entityLabel) . ', invoice, description" maxlength="80" aria-label="Search invoices">'
        . ($search !== '' ? '<a class="oi-header-search-clear" href="' . oi_h(oi_url(['q' => null, 'p' => null])) . '" aria-label="Clear search" title="Clear search"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m18 6-12 12M6 6l12 12"></path></svg></a>' : '')
        . '<button class="oi-header-search-submit" type="submit" aria-label="Search invoices" title="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg></button>'
        . '</div>';
    $employeeHeaderCenterHtml = !$entryMode
        ? '<form class="oi-header-search oi-header-search--desktop" method="get" action="">' . $searchHiddenInputs . $searchFieldMarkup . '</form>'
        : '';
    $mobileSearchMarkup = !$entryMode
        ? '<form class="oi-header-search oi-header-search--mobile" method="get" action="">' . $searchHiddenInputs . $searchFieldMarkup . '</form>'
        : '';
    $employeeHeaderRightHtml = '
        <div class="outstanding-invoice-header-actions">
            <button type="button" class="new-invoice-btn" data-toggle-target="new-outstanding-invoice-panel" aria-expanded="false">New Outstanding Invoice</button>
            <section class="oi-invoice-picker" id="new-outstanding-invoice-panel" hidden>
                <div class="oi-invoice-picker-grid">
                    <a href="' . oi_h(oi_url(['tab' => 'receivables', 'entry' => 'new', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) . '" class="oi-invoice-picker-card ' . ($activeTab === 'receivables' ? 'active' : '') . '">
                        <span class="oi-picker-icon oi-picker-icon--in">↗</span>
                        <div>
                            <strong>Receivables</strong>
                            <small>Track customer invoices still waiting to be collected.</small>
                        </div>
                    </a>
                    <a href="' . oi_h(oi_url(['tab' => 'payables', 'entry' => 'new', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) . '" class="oi-invoice-picker-card ' . ($activeTab === 'payables' ? 'active' : '') . '">
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

        <?php if ($success): ?><div class="oi-success-notice" style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="oi-error-notice" style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (!$entryMode): ?>
        <div class="tabs">
            <a href="<?= oi_h(oi_url(['tab' => 'receivables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>" class="tab-link <?= $activeTab === 'receivables' ? 'active' : '' ?>">Receivables (Income)</a>
            <a href="<?= oi_h(oi_url(['tab' => 'payables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>" class="tab-link <?= $activeTab === 'payables' ? 'active' : '' ?>">Payables (Expenses)</a>
        </div>
        <?= $mobileSearchMarkup ?>

        <?php endif; ?>

        <?php if ($entryMode): ?>
        <div class="card oi-form-card" id="new-outstanding-invoice-form">
            <a class="oi-form-back" href="<?= oi_h(oi_url(['entry' => null, 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>">Back to outstanding invoices</a>
            <h3 style="font-size:1rem; margin-bottom:15px; color:#374151;">Add <?= $activeTab === 'receivables' ? 'receivable' : 'payable' ?></h3>
            <form method="POST" enctype="multipart/form-data" id="oiAddForm">
                <input type="hidden" name="create_invoice" value="1">
                <input type="hidden" name="type" value="<?= $typeFilter ?>">
                <div class="oi-form-grid">
                    <div class="oi-field oi-span-3">
                        <label for="oiDate">Date <em>*</em></label>
                        <input type="date" id="oiDate" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
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
        </div>
        <?php endif; ?>

        <!-- List -->
        <?php if (!$entryMode): ?>
        <section class="oi-rank-card">
            <div class="oi-rank-head">
                <div>
                    <h2><?= $activeTab === 'receivables' ? 'Outstanding receivables' : 'Outstanding payables' ?></h2>
                    <p>Invoices not yet paid, newest first unless you sort by a column</p>
                </div>
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
                        <?php
                            $attachmentPath = str_replace('\\', '/', (string) ($inv['attachment'] ?? ''));
                            $attachmentName = $attachmentPath !== '' ? basename($attachmentPath) : '';
                            $attachmentExtension = strtoupper((string) pathinfo($attachmentName, PATHINFO_EXTENSION));
                            $attachmentLabel = $activeTab === 'receivables' ? 'Customer invoice' : 'Supplier invoice';
                        ?>
                        <tr class="oi-invoice-row" tabindex="0" aria-label="View invoice details for <?= oi_h((string) $inv['entity_name']) ?>"
                            data-invoice-type="<?= $activeTab === 'receivables' ? 'Receivable' : 'Payable' ?>"
                            data-invoice-entity="<?= oi_h((string) $inv['entity_name']) ?>"
                            data-invoice-number="<?= oi_h((string) ($inv['invoice_number'] ?? '')) ?>"
                            data-invoice-date="<?= oi_h(date('d/m/Y', strtotime($inv['invoice_date']))) ?>"
                            data-invoice-description="<?= oi_h((string) ($inv['narration'] ?? '')) ?>"
                            data-invoice-amount="<?= oi_h(number_format((float) $inv['amount'], 2)) ?>">
                            <td class="oi-col-sn"><?= $rangeFrom + $rowIndex ?></td>
                            <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                            <td><span class="oi-name"><?= htmlspecialchars($inv['entity_name']) ?></span></td>
                            <td><?= htmlspecialchars(($inv['invoice_number'] ?? '') !== '' ? $inv['invoice_number'] : '-') ?></td>
                            <td><div class="oi-desc" title="<?= htmlspecialchars((string) $inv['narration']) ?>"><?= htmlspecialchars((string) $inv['narration']) !== '' ? htmlspecialchars((string) $inv['narration']) : '<span class="oi-muted">-</span>' ?></div></td>
                            <td>
                                <?php if ($attachmentPath !== ''): ?>
                                    <a href="<?= oi_h(function_exists('app_url') ? app_url('/' . ltrim($attachmentPath, '/')) : '/' . ltrim($attachmentPath, '/')) ?>" target="_blank" rel="noopener" class="badge badge-att oi-attachment-link" data-file-name="<?= oi_h($attachmentName) ?>" data-file-label="<?= oi_h($attachmentLabel) ?>" data-file-format="<?= oi_h($attachmentExtension) ?>" title="<?= oi_h($attachmentLabel . ($attachmentExtension !== '' ? ' · ' . $attachmentExtension : '') . ': ' . $attachmentName) ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"></path></svg>
                                        <span><?= oi_h($attachmentLabel) ?></span>
                                    </a>
                                <?php else: ?>
                                    <span class="oi-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="oi-num"><span class="oi-amt"><?= number_format($inv['amount'], 2) ?></span></td>
                            <?php if ($isFinanceUser): ?>
                            <td class="oi-actions">
                                <details class="oi-row-actions">
                                    <summary class="oi-row-actions-trigger" aria-label="Row actions" title="Row actions">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"></circle><circle cx="12" cy="12" r="1.8"></circle><circle cx="12" cy="19" r="1.8"></circle></svg>
                                    </summary>
                                    <div class="oi-row-actions-menu">
                                    <form method="POST" onsubmit="return confirm('Mark this invoice as Paid?');">
                                        <input type="hidden" name="mark_paid" value="1">
                                        <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                                        <button type="submit" class="oi-row-action-item">Mark Paid</button>
                                    </form>
                                    <button type="button" class="oi-row-action-item oi-row-action-item--delete" onclick="confirmDelete(<?= $inv['id'] ?>)">Delete</button>
                                    </div>
                                </details>
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
        <?php endif; ?>
    </div>
    <div id="oiInvoiceDetailsModal" class="oi-details-overlay" role="presentation" style="display:none;">
        <section class="oi-details-modal" role="dialog" aria-modal="true" aria-labelledby="oiInvoiceDetailsTitle" aria-describedby="oiInvoiceDetailsEntity">
            <header class="oi-details-head">
                <div class="oi-details-head-main">
                    <h2 id="oiInvoiceDetailsTitle">Invoice details</h2>
                    <p id="oiInvoiceDetailsEntity"></p>
                    <div class="oi-details-meta">
                        <span class="oi-details-type" id="oiInvoiceDetailsType"></span>
                        <span id="oiInvoiceDetailsDate"></span>
                    </div>
                </div>
                <button type="button" class="oi-details-close" id="oiInvoiceDetailsClose" aria-label="Close invoice details">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m18 6-12 12M6 6l12 12"></path></svg>
                </button>
            </header>
            <div class="oi-details-body">
                <dl class="oi-details-list">
                    <div class="oi-details-row"><dt>Invoice number</dt><dd id="oiInvoiceDetailsNumber"></dd></div>
                    <div class="oi-details-row"><dt>Description</dt><dd id="oiInvoiceDetailsDescription"></dd></div>
                    <div class="oi-details-row"><dt>Amount</dt><dd id="oiInvoiceDetailsAmount" class="oi-details-amount"></dd></div>
                </dl>
                <section class="oi-details-attachments" id="oiInvoiceDetailsAttachments" hidden>
                    <div class="oi-details-attachments-label" id="oiInvoiceDetailsAttachmentLabel"></div>
                    <a class="oi-details-attachment-link" id="oiInvoiceDetailsAttachment" href="#" target="_blank" rel="noopener">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"></path></svg>
                        <span id="oiInvoiceDetailsAttachmentName">View invoice</span>
                    </a>
                </section>
            </div>
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const successNotice = document.querySelector('.oi-success-notice');
            if (successNotice && successNotice.textContent.trim() === 'Invoice deleted successfully.') {
                window.setTimeout(function () {
                    successNotice.remove();
                }, 5000);
            }

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

        var activeInvoiceDetailsRow = null;
        var invoiceDetailsBodyOverflow = '';
        function openInvoiceDetails(row) {
            var modal = document.getElementById('oiInvoiceDetailsModal');
            var invoiceNumber = row.dataset.invoiceNumber || '';
            document.getElementById('oiInvoiceDetailsTitle').textContent = invoiceNumber || 'Invoice details';
            document.getElementById('oiInvoiceDetailsEntity').textContent = row.dataset.invoiceEntity || '-';
            var typeLabel = document.getElementById('oiInvoiceDetailsType');
            typeLabel.textContent = 'Outstanding ' + (row.dataset.invoiceType || 'invoice').toLowerCase();
            typeLabel.classList.toggle('oi-details-type--payable', row.dataset.invoiceType === 'Payable');
            document.getElementById('oiInvoiceDetailsDate').textContent = row.dataset.invoiceDate || '-';
            document.getElementById('oiInvoiceDetailsNumber').textContent = invoiceNumber || '-';
            document.getElementById('oiInvoiceDetailsDescription').textContent = row.dataset.invoiceDescription || '-';
            document.getElementById('oiInvoiceDetailsAmount').textContent = 'TZS ' + (row.dataset.invoiceAmount || '0.00');
            var attachment = row.querySelector('a.badge-att');
            var attachmentSection = document.getElementById('oiInvoiceDetailsAttachments');
            var attachmentLink = document.getElementById('oiInvoiceDetailsAttachment');
            var attachmentLabel = document.getElementById('oiInvoiceDetailsAttachmentLabel');
            attachmentSection.hidden = !attachment;
            if (attachment) {
                attachmentLink.href = attachment.href;
                document.getElementById('oiInvoiceDetailsAttachmentName').textContent = attachment.dataset.fileLabel || 'Invoice';
                attachmentLink.title = attachment.title || attachment.textContent.trim();
                attachmentLink.setAttribute('aria-label', attachmentLink.title);
                attachmentLabel.textContent = (attachment.dataset.fileLabel || 'Invoice').toUpperCase();
            }
            activeInvoiceDetailsRow = row;
            invoiceDetailsBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            document.getElementById('oiInvoiceDetailsClose').focus();
        }
        function closeInvoiceDetails() {
            var modal = document.getElementById('oiInvoiceDetailsModal');
            if (!modal || modal.style.display === 'none') return;
            modal.style.display = 'none';
            document.body.style.overflow = invoiceDetailsBodyOverflow;
            if (activeInvoiceDetailsRow) activeInvoiceDetailsRow.focus();
            activeInvoiceDetailsRow = null;
        }
        document.querySelectorAll('.oi-invoice-row').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button, input, select, textarea, form, summary, details')) return;
                openInvoiceDetails(row);
            });
            row.addEventListener('keydown', function (event) {
                if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
                event.preventDefault();
                openInvoiceDetails(row);
            });
        });
        document.getElementById('oiInvoiceDetailsClose').addEventListener('click', closeInvoiceDetails);
        document.getElementById('oiInvoiceDetailsModal').addEventListener('click', function (event) {
            if (event.target === this) closeInvoiceDetails();
        });

        function confirmDelete(id) {
            document.querySelectorAll('.oi-row-actions[open]').forEach(function (menu) { menu.open = false; });
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
            if (e.key === 'Escape') {
                closeInvoiceDetails();
                closeDeleteModal();
                document.querySelectorAll('.oi-row-actions[open]').forEach(function (menu) { menu.open = false; });
            }
        });
        document.addEventListener('click', function (event) {
            var activeMenu = event.target.closest ? event.target.closest('.oi-row-actions') : null;
            document.querySelectorAll('.oi-row-actions[open]').forEach(function (menu) {
                if (menu !== activeMenu) menu.open = false;
            });
        });
    </script>
</body>
</html>
