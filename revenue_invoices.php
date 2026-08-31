<?php
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
define('REVENUE_INVOICES_BUILD', '20260609a');

$invPageError = '';
$invShowErrorPage = function ($message) {
    $safe = htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Revenue invoices error</title></head><body style="font-family:system-ui,sans-serif;padding:2rem;max-width:720px">';
    echo '<h1>Revenue invoices could not load</h1>';
    echo '<p style="color:#b91c1c;">' . $safe . '</p>';
    echo '<p>Upload <code>revenue_invoices.php</code>, <code>includes/revenue_ledger.php</code>, and <code>includes/revenue_sync.php</code>.</p>';
    echo '</body></html>';
    exit;
};
set_exception_handler(function ($e) use (&$invPageError, $invShowErrorPage) {
    $invPageError = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    $invShowErrorPage($invPageError);
});
register_shutdown_function(function () use ($invShowErrorPage) {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $invShowErrorPage($e['message'] . ' (' . basename((string) $e['file']) . ':' . (int) $e['line'] . ')');
});

try {
    require_once __DIR__ . '/includes/functions.php';
    if (!is_file(__DIR__ . '/includes/revenue_ledger.php')) {
        throw new RuntimeException('Missing includes/revenue_ledger.php on server.');
    }
    require_once __DIR__ . '/includes/revenue_ledger.php';
} catch (Throwable $e) {
    $invShowErrorPage('Bootstrap failed: ' . $e->getMessage());
}

requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}

$revPdo = function_exists('revenue_resolve_pdo') ? revenue_resolve_pdo() : null;
if (!($revPdo instanceof PDO)) {
    $invShowErrorPage('Database connection is not available. Invoice data is stored in the tenant database.');
}
global $pdo;
$pdo = $revPdo;
$GLOBALS['pdo'] = $revPdo;

$search = trim((string) ($_GET['search'] ?? ''));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$kpi = strtolower(trim((string) ($_GET['kpi'] ?? '')));
$allowedKpi = ['all', 'paid', 'outstanding', 'overdue'];
if (!in_array($kpi, $allowedKpi, true)) {
    $kpi = 'all';
}
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$customerId = (int) ($_GET['customer_id'] ?? 0);

$invCols = function_exists('invoiceTableColumns') ? invoiceTableColumns($revPdo) : [];
if (!$invCols) {
    try {
        $invCols = $revPdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $invCols = [];
    }
}
if (!$invCols) {
    $invShowErrorPage('The invoices table was not found in the tenant database. Sync sales invoices first or check DATA_DB_NAME.');
}

$hasBalanceDue = in_array('balance_due', $invCols, true);
$hasAmountPaid = in_array('amount_paid', $invCols, true);
$hasDueDate = in_array('due_date', $invCols, true);
$hasInvoiceDate = in_array('invoice_date', $invCols, true);
$hasOrderId = in_array('order_id', $invCols, true);
$hasCustomerId = in_array('customer_id', $invCols, true);

$amountPaidExpr = $hasAmountPaid ? 'COALESCE(i.amount_paid, 0)' : '0';
$totalExpr = in_array('total_amount', $invCols, true) ? 'COALESCE(i.total_amount, 0)' : '0';
$balanceExpr = $hasBalanceDue
    ? "COALESCE(i.balance_due, {$totalExpr} - {$amountPaidExpr})"
    : "({$totalExpr} - {$amountPaidExpr})";
$dueDateSelect = $hasDueDate ? 'i.due_date' : ($hasInvoiceDate ? 'i.invoice_date' : 'NULL');
$dueDateFilterCol = $hasDueDate ? 'i.due_date' : ($hasInvoiceDate ? 'i.invoice_date' : null);

$soJoinSql = '';
$orderSearchSql = '';
if ($hasOrderId && function_exists('tableExists') && tableExists('sales_orders', $revPdo)) {
    $soCols = function_exists('salesOrdersTableColumns') ? salesOrdersTableColumns($revPdo) : [];
    if (!$soCols) {
        try {
            $soCols = $revPdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $soCols = [];
        }
    }
    if (in_array('order_number', $soCols, true)) {
        $soJoinSql = ' LEFT JOIN sales_orders so ON so.id = i.order_id';
        $orderSearchSql = ' OR so.order_number LIKE :q';
    }
}

$custJoinSql = $hasCustomerId ? ' LEFT JOIN customers c ON c.id = i.customer_id' : '';
$customerSelect = $hasCustomerId ? "COALESCE(c.company_name, '-')" : "'-'";

$hasStatus = in_array('status', $invCols, true);
$where = ['1=1'];
if ($hasStatus) {
    $where = ["LOWER(COALESCE(i.status,'')) NOT IN ('cancelled','canceled')"];
}
$params = [];
if ($status !== '' && $hasStatus) {
    $where[] = "LOWER(COALESCE(i.status,'')) = :status";
    $params[':status'] = $status;
}
if ($search !== '') {
    $searchParts = ['i.invoice_number LIKE :q'];
    if ($hasCustomerId) {
        $searchParts[] = 'c.company_name LIKE :q';
    }
    if ($orderSearchSql !== '') {
        $searchParts[] = 'so.order_number LIKE :q';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
    $params[':q'] = '%' . $search . '%';
}
if ($customerId > 0 && $hasCustomerId) {
    $where[] = 'i.customer_id = :customer_id';
    $params[':customer_id'] = $customerId;
}
if ($dateFrom !== '' && $hasInvoiceDate) {
    $where[] = 'i.invoice_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '' && $hasInvoiceDate) {
    $where[] = 'i.invoice_date <= :date_to';
    $params[':date_to'] = $dateTo;
}
$todaySql = date('Y-m-d');
if ($kpi === 'paid') {
    $paidStatusSql = $hasStatus ? " OR LOWER(COALESCE(i.status,'')) = 'paid'" : '';
    $where[] = "({$balanceExpr} <= 0.001{$paidStatusSql})";
} elseif ($kpi === 'outstanding') {
    $where[] = "{$balanceExpr} > 0.001";
} elseif ($kpi === 'overdue' && $dueDateFilterCol !== null) {
    $where[] = "({$dueDateFilterCol} IS NOT NULL AND {$dueDateFilterCol} < :today AND {$balanceExpr} > 0.001)";
    $params[':today'] = $todaySql;
}

$invoiceDateSelect = $hasInvoiceDate ? 'i.invoice_date' : 'NULL';
$sql = '
    SELECT
        i.id,
        i.invoice_number,
        ' . $invoiceDateSelect . ' AS invoice_date,
        ' . $dueDateSelect . ' AS due_date,
        ' . $totalExpr . ' AS total_amount,
        ' . $amountPaidExpr . ' AS amount_paid,
        ' . $balanceExpr . ' AS balance_due,
        ' . ($hasStatus ? 'i.status' : "''") . ' AS status,
        ' . $customerSelect . ' AS customer_name,
        re.id AS revenue_entry_id
    FROM invoices i' . $custJoinSql . $soJoinSql . '
    LEFT JOIN revenue_entries re ON re.source_invoice_id = i.id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY ' . ($hasInvoiceDate ? 'i.invoice_date DESC, ' : '') . 'i.id DESC
    LIMIT 500';

$rows = [];
$sqlError = '';
try {
    $stmt = $revPdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $sqlError = $e->getMessage();
    error_log('revenue_invoices list: ' . $sqlError);
    $rows = [];
}

$customers = [];
try {
    if (function_exists('tableExists') && tableExists('customers', $revPdo)) {
        $custCols = $revPdo->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $custSql = in_array('status', $custCols, true)
            ? "SELECT id, company_name FROM customers WHERE status = 'active' ORDER BY company_name ASC"
            : 'SELECT id, company_name FROM customers ORDER BY company_name ASC';
        $customers = $revPdo->query($custSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('revenue_invoices customers: ' . $e->getMessage());
    $customers = [];
}

$totalInvoices = 0.0;
$totalPaid = 0.0;
$totalOutstanding = 0.0;
$totalOverdue = 0.0;

foreach ($rows as $r) {
    $invTotal = (float) ($r['total_amount'] ?? 0);
    $invBal = (float) ($r['balance_due'] ?? 0);
    $invStatus = strtolower(trim((string) ($r['status'] ?? '')));
    $isPaidInvoice = ($invBal <= 0.001 || $invStatus === 'paid');
    $positiveBalance = max(0.0, $invBal);

    $totalInvoices += $invTotal;
    if ($isPaidInvoice) {
        // Paid card should represent paid-invoice value, not raw sum(amount_paid).
        $totalPaid += max(0.0, $invTotal);
    }
    $totalOutstanding += $positiveBalance;

    $due = (string) ($r['due_date'] ?? '');
    if ($due !== '' && strtotime($due) < strtotime(date('Y-m-d')) && $positiveBalance > 0.001) {
        $totalOverdue += $positiveBalance;
    }
}

$pctPaid = $totalInvoices > 0 ? ($totalPaid / $totalInvoices) * 100 : 0;
$pctOutstanding = $totalInvoices > 0 ? ($totalOutstanding / $totalInvoices) * 100 : 0;
$pctOverdue = $totalInvoices > 0 ? ($totalOverdue / $totalInvoices) * 100 : 0;
$totalRows = count($rows);
$perPage = (int) ($_GET['per_page'] ?? 100);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 100;
}
$pageSize = $perPage;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $pageSize;
$pagedRows = array_slice($rows, $offset, $pageSize);
$showFrom = $totalRows > 0 ? $offset + 1 : 0;
$showTo = min($offset + $pageSize, $totalRows);
$queryBase = $_GET;
unset($queryBase['page']);

function invStatusClass($status, $balance)
{
    $s = strtolower(trim($status));
    if ($balance <= 0.001 || $s === 'paid') {
        return 'st-paid';
    }
    if ($s === 'sent') {
        return 'st-sent';
    }
    if ($s === 'draft') {
        return 'st-draft';
    }
    if ($s === 'partial') {
        return 'st-partial';
    }
    if ($s === 'overdue') {
        return 'st-overdue';
    }
    return 'st-unpaid';
}

function invStatusLabel($status, $balance)
{
    $s = strtolower(trim($status));
    if ($balance <= 0.001 || $s === 'paid') {
        return 'Paid';
    }
    if ($s === 'partial') {
        return 'Partial';
    }
    if ($s === 'overdue') {
        return 'Overdue';
    }
    return 'Unpaid';
}

function fmtDate($date)
{
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : '-';
}

function filterUrl($queryBase, $page)
{
    $q = $queryBase;
    $q['module'] = 'revenue';
    $q['page'] = $page;
    return 'revenue_invoices.php?' . http_build_query($q);
}
function invUrl($queryBase, $replace = [])
{
    $q = $queryBase;
    foreach ($replace as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    $q['module'] = 'revenue';
    if (!isset($q['page'])) {
        $q['page'] = 1;
    }
    return 'revenue_invoices.php?' . http_build_query($q);
}

function inv_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function inv_qs($replace = [])
{
    $base = [
        'module' => 'revenue',
        'search' => $GLOBALS['search'],
        'date_from' => $GLOBALS['dateFrom'],
        'date_to' => $GLOBALS['dateTo'],
        'customer_id' => $GLOBALS['customerId'] > 0 ? (string) $GLOBALS['customerId'] : '',
        'status' => $GLOBALS['status'],
        'kpi' => ($GLOBALS['kpi'] ?? '') !== 'all' ? ($GLOBALS['kpi'] ?? '') : '',
        'page' => $GLOBALS['currentPage'],
        'per_page' => $GLOBALS['perPage'],
    ];
    if (!empty($_GET['company_slug'])) {
        $base['company_slug'] = (string) $_GET['company_slug'];
    }
    foreach ($replace as $k => $v) {
        if ($v === null || $v === '') {
            unset($base[$k]);
        } else {
            $base[$k] = $v;
        }
    }

    return http_build_query(array_filter($base, function ($v) {
        return $v !== '' && $v !== null;
    }));
}

function inv_pages($cur, $last)
{
    if ($last <= 1) {
        return $last === 1 ? [1] : [];
    }
    $d = 1;
    $pages = [1];
    $L = max(2, $cur - $d);
    $R = min($last - 1, $cur + $d);
    if ($L > 2) {
        $pages[] = '...';
    }
    if ($L <= $R) {
        for ($i = $L; $i <= $R; $i++) {
            $pages[] = $i;
        }
    }
    if ($R < $last - 1) {
        $pages[] = '...';
    }
    $pages[] = $last;
    $seen = [];
    $out = [];
    foreach ($pages as $p) {
        $k = (string) $p;
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $p;
    }

    return $out;
}

function inv_fmt($n)
{
    return number_format((float) $n, 2) . ' TZS';
}

$hasActiveFilters = ($dateFrom !== '' || $dateTo !== '' || $customerId > 0 || $status !== '');
$invPageTitle = 'Invoices';
$invPageSubtitle = 'View and manage all sales invoices';
$pageNumbers = inv_pages($currentPage, $totalPages);

$employeeHeaderCenterHtml =
    '<div class="ren-hdr-search" role="search">'
    . '<i class="fas fa-search" aria-hidden="true"></i>'
    . '<input type="search" class="ren-hdr-inp" placeholder="Search invoices..." autocomplete="off" id="invPageSearch" value="' . inv_h($search) . '" aria-label="Search invoices" title="Press Enter to search">'
    . '<span class="ren-hdr-kbd"><kbd>Ctrl</kbd> + <kbd>K</kbd></span>'
    . '</div>';
$hideHeaderCompanyBranding = true;
?>
<!-- REVENUE_INVOICES_BUILD <?= REVENUE_INVOICES_BUILD ?> -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Revenue Invoices - <?= inv_h(defined('COMPANY_NAME') ? COMPANY_NAME : 'Company') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="<?= inv_h(function_exists('app_url') ? app_url('/assets/css/style.css') : 'assets/css/style.css') ?>?v=<?= time() ?>" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#ffffff;--bd:#E2E8F0;--tx:#0f172a;--mu:#64748B;--pr:#2563EB}
html,body{margin:0;padding:0}
body.ren-page{font-family:'Outfit',system-ui,-apple-system,sans-serif;background:var(--bg)!important;color:var(--tx);margin:0;padding:0;font-size:15px}
body.ren-page .layout-main-wrapper{margin:0;padding:0;align-items:stretch}
body.ren-page .layout-main-wrapper > .flex-grow-1{min-height:0;display:flex;flex-direction:column;background:#fff}
body.ren-page main.main-content{flex:1 1 auto;min-height:0;overflow:visible!important;width:100%;max-width:none;margin:0;padding:0;background:#fff}
body.ren-page .employee-header{background:#fff!important;border:none!important;border-bottom:none!important;box-shadow:none!important;min-height:0;padding:.25rem 0;margin:0}
body.ren-page header.employee-header::after{display:none!important}
body.ren-page .header-content{display:flex!important;align-items:center!important;width:100%!important;padding:0 .75rem 0 1rem!important;position:relative;min-height:48px}
body.ren-page .header-actions-tray .logout-btn{display:none!important}
body.ren-page .header-actions-tray{gap:.65rem!important;flex-wrap:nowrap!important}
@media(min-width:992px){body.ren-page .employee-header--has-center-slot .header-left{position:absolute;left:1rem;top:50%;transform:translateY(-50%);z-index:6}}
body.ren-page .employee-header:not(.employee-header--has-center-slot) .header-left{position:static;transform:none}
body.ren-page .employee-header-center-slot{min-width:0;flex:1 1 auto;max-width:none;justify-content:center!important}
body.ren-page .employee-header-center-slot .ren-hdr-search{max-width:min(100%,520px);width:100%;margin:0 auto}
.ren-hdr-search{display:flex;align-items:center;gap:.5rem;width:100%;background:#fff;border:1px solid var(--bd);border-radius:8px;padding:.4rem .85rem}
.ren-hdr-inp{flex:1;min-width:0;border:0;background:transparent;font-size:1rem;outline:0;color:var(--tx)}
.ren-hdr-kbd{display:none;font-size:.75rem;color:var(--mu);white-space:nowrap}
@media(min-width:992px){.ren-hdr-kbd{display:inline-flex;gap:2px;align-items:center}}
.ren-hdr-kbd kbd{border:none;background:transparent;padding:0;font-size:inherit}
.ren-dash{padding:.65rem 2rem 2rem;max-width:1600px;margin:0 auto;width:100%;box-sizing:border-box}
.ren-top-bar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.65rem;flex-wrap:nowrap}
.ren-breadcrumbs{display:flex;align-items:center;gap:.5rem;font-size:.875rem;color:var(--mu);min-width:0}
.ren-breadcrumbs a{color:var(--mu);text-decoration:none;font-weight:500}
.ren-breadcrumbs a:hover{color:var(--pr)}
.ren-breadcrumbs .ren-crumb-current{color:var(--tx);font-weight:600}
.ren-page-hd{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem 1.25rem;margin-bottom:2.5rem}
.ren-page-hd-intro{display:flex;align-items:flex-start;gap:1rem;min-width:0}
.ren-page-hd-icon{width:48px;height:48px;border-radius:12px;background:#DBEAFE;color:#2563EB;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.ren-page-hd h1{margin:0;font-size:1.75rem;font-weight:800;color:var(--tx);line-height:1.2;letter-spacing:-.02em}
.ren-page-hd-sub{margin:.25rem 0 0;font-size:.875rem;color:var(--mu)}
.rh{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:.5rem;margin:0;padding:0}
.act{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;justify-content:flex-end}
.btn-ren{padding:.5rem 1rem;border-radius:8px;font-weight:600;border:1px solid var(--bd);background:#fff;text-decoration:none;color:var(--tx);font-size:.95rem;display:inline-flex;align-items:center;gap:.4rem}
.btn-ren:hover{background:#f8fafc;color:var(--tx)}
.btn-pri{background:var(--pr)!important;border-color:var(--pr)!important;color:#fff!important}
.btn-ren-purple{background:#7c3aed!important;border-color:#7c3aed!important;color:#fff!important;box-shadow:0 4px 12px rgba(124,58,237,.18)}
.act .btn-ren.ren-pill,.act a.btn-ren.ren-pill{border-radius:9999px;padding:.58rem 1.35rem;min-height:42px;font-size:.9375rem;font-weight:600;line-height:1.2;gap:.45rem}
.act a.btn-ren.btn-ren-purple.ren-pill:hover{background:#6d28d9!important;border-color:#6d28d9!important;color:#fff!important;filter:none}
.act a.btn-ren.btn-pri.ren-pill:hover{filter:brightness(0.95);color:#fff!important}
.ren-filter-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:.25rem;border:0;border-radius:0;background:transparent;color:#0f172a;cursor:pointer}
.ren-filter-btn:hover,.ren-filter-btn.is-active{color:#2563eb}
.ren-filter-btn svg{display:block;width:18px;height:18px}
.ren-filter-badge{position:absolute;top:-4px;right:-4px;width:9px;height:9px;border-radius:50%;background:#2563eb;border:2px solid #fff}
#invFilterModal .modal-content{border:1px solid var(--bd);border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.12)}
#invFilterModal .modal-header{border-bottom:1px solid #eef2f7;padding:1rem 1.15rem}
#invFilterModal .modal-title{font-size:1rem;font-weight:700;color:#0f172a}
#invFilterModal .modal-body{padding:1.15rem 1.15rem .5rem}
#invFilterModal .modal-footer{border-top:1px solid #eef2f7;padding:.85rem 1.15rem;gap:.5rem}
#invFilterModal .fr{display:flex;flex-direction:column;gap:1rem}
#invFilterModal .fd{flex:none;width:100%;min-width:0}
#invFilterModal .fd label{display:block;font-size:.78rem;font-weight:700;text-transform:uppercase;color:var(--mu);margin-bottom:.35rem}
#invFilterModal .fd select,#invFilterModal .fd input[type=date]{width:100%;padding:.55rem .7rem;border:1px solid var(--bd);border-radius:10px;font-size:.95rem}
.ren-kpi-grid{width:100%;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:2.5rem;box-sizing:border-box}
@media(max-width:1199px){.ren-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767px){.ren-kpi-grid{grid-template-columns:1fr}}
.ren-kpi-card{display:flex;align-items:stretch;gap:.85rem;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;box-shadow:none;min-width:0;text-decoration:none;color:inherit;transition:border-color .15s,box-shadow .15s}
a.ren-kpi-card:hover{border-color:#93c5fd;box-shadow:0 4px 12px rgba(37,99,235,.08)}
a.ren-kpi-card.ren-kpi-card--active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb}
.ren-kpi-icon{flex-shrink:0;width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.15rem}
.ren-kpi-icon--blue{background:#EFF6FF;color:#2563EB}
.ren-kpi-icon--green{background:#dcfce7;color:#16a34a}
.ren-kpi-icon--orange{background:#ffedd5;color:#ea580c}
.ren-kpi-icon--purple{background:#ede9fe;color:#7c3aed}
.ren-kpi-body{min-width:0;flex:1;display:flex;flex-direction:column;justify-content:center;gap:.25rem}
.ren-kpi-label{font-size:.98rem;font-weight:600;color:var(--mu)}
.ren-kpi-value{font-size:1.55rem;font-weight:800;color:var(--tx);line-height:1.25;word-break:break-word}
.ren-kpi-foot{font-size:.92rem;font-weight:500;color:var(--mu);margin-top:.15rem}
.tw{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:1rem}
.ts{overflow-x:auto}
.tbl.inv-tbl{width:100%;border-collapse:collapse;border-spacing:0;font-size:.92rem}
.tbl.inv-tbl th,.tbl.inv-tbl td{border-left:none!important;border-right:none!important;border-top:none!important;vertical-align:middle}
.tbl.inv-tbl thead th{font-family:'Outfit',system-ui,sans-serif;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#0f172a;background:transparent;padding:.9rem 1rem .75rem;text-align:left;border-bottom:1px solid #eef2f6!important}
.tbl.inv-tbl thead th.num{text-align:right}
.tbl.inv-tbl tbody td{padding:.85rem 1rem;color:#475569;border-bottom:1px solid #eef2f6!important}
.tbl.inv-tbl tbody tr:last-child td{border-bottom:none!important}
.tbl.inv-tbl tbody tr:hover{background:#f8fafc}
.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.vb{font-weight:700;color:var(--pr);cursor:pointer;background:none;border:0;padding:0;text-decoration:none;font-size:inherit}
.vb:hover{text-decoration:underline}
.cn{font-weight:700;font-size:.96rem}
.tp{display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.8rem;font-weight:700}
.st-paid{background:#dcfce7;color:#166534}
.st-sent{background:#dbeafe;color:#1e40af}
.st-draft{background:#f3e8ff;color:#6b21a8}
.st-partial{background:#ffedd5;color:#9a3412}
.st-overdue,.st-unpaid{background:#fee2e2;color:#dc2626}
.inv-bal-due{color:#dc2626!important;font-weight:700}
.inv-bal-paid{color:#16a34a!important;font-weight:700}
.inv-due-over{color:#dc2626;font-weight:700}
.action-pay{display:inline-flex;align-items:center;gap:6px;font-size:.88rem;font-weight:600;color:#16a34a;text-decoration:none}
.action-pay:hover{text-decoration:underline;color:#15803d}
.ft{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem 1rem;padding:1rem 1.15rem;border-top:none;font-size:.95rem;background:#fff}
.ren-ft-entries{color:var(--mu);font-size:.95rem;flex:1;min-width:12rem}
.ren-ft-entries strong{color:var(--tx);font-weight:700}
.ren-ft-controls{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:1.35rem;margin-left:auto}
.ren-rows-per{display:inline-flex;align-items:stretch;gap:.4rem}
.ren-rows-per__lbl{display:inline-flex;align-items:center;padding:.45rem .95rem;border:1px solid var(--bd);border-radius:8px;background:#fff;font-size:.9rem;font-weight:600;color:#334155;white-space:nowrap}
.ren-rows-per__wrap{position:relative;display:inline-flex;align-items:center}
.ren-rows-per__sel{appearance:none;-webkit-appearance:none;padding:.45rem 1.9rem .45rem .75rem;border:1px solid var(--bd);border-radius:8px;background:#fff;font-size:.9rem;font-weight:600;color:var(--tx);min-width:4.25rem;cursor:pointer}
.ren-rows-per__chev{position:absolute;right:.55rem;top:50%;transform:translateY(-50%);pointer-events:none;font-size:.55rem;color:#64748b}
.ren-pg{display:inline-flex;flex-wrap:wrap;align-items:center;gap:.35rem}
.ren-pg a,.ren-pg span.ren-pg__stub{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 .4rem;border-radius:8px;border:1px solid var(--bd);background:#fff;text-decoration:none;font-weight:600;font-size:.9rem;color:#334155}
.ren-pg span.el{min-width:auto;height:auto;padding:0 .25rem;border:none;background:transparent;color:var(--mu)}
.ren-pg a.on{background:var(--pr);border-color:var(--pr);color:#fff}
.ren-pg a:hover:not(.on){background:#f8fafc;border-color:#cbd5e1}
.ren-pg a.ren-pg__edge,.ren-pg span.ren-pg__stub.ren-pg__edge{min-width:38px}
.ren-pg span.ren-pg__stub.ren-pg__dis{opacity:.55;cursor:not-allowed;pointer-events:none}
.ren-top-new-mobile{display:inline-flex!important}
@media(min-width:992px){.ren-top-new-mobile{display:none!important}}
@media(max-width:991px){
body.ren-page .header-content{flex-wrap:wrap;padding-bottom:.35rem!important}
body.ren-page .employee-header-center-slot{order:3;flex:0 0 100%;width:100%;padding:.35rem .75rem .15rem!important}
.ren-page-hd-intro{display:none}
.ren-page-hd{justify-content:flex-end;margin-bottom:2rem}
.ren-page-hd-actions{width:100%;justify-content:flex-end}
.ren-top-bar .ren-breadcrumbs .ren-crumb-mid{display:none}
.ren-dash{padding:.5rem 1rem 1rem}
}
</style>
</head>
<body class="dashboard ren-page">
<?php
try {
    require __DIR__ . '/includes/header_employee.php';
} catch (Throwable $e) {
    error_log('revenue_invoices header: ' . $e->getMessage());
    echo '<div style="padding:12px 24px;background:#fef2f2;color:#b91c1c;">Header could not load: ' . inv_h($e->getMessage()) . '</div>';
}
?>
<main class="main-content ren-page-main">
<div class="ren-dash">

<div class="ren-top-bar">
<nav class="ren-breadcrumbs" aria-label="Breadcrumb">
<a href="<?= inv_h($invDashboardUrl) ?>">Revenue Dashboard</a>
<span aria-hidden="true">/</span>
<span class="ren-crumb-mid"><a href="revenue_entries.php?module=revenue<?= !empty($_GET['company_slug']) ? '&company_slug=' . urlencode((string) $_GET['company_slug']) : '' ?>">Revenues</a></span>
<span aria-hidden="true">/</span>
<span class="ren-crumb-current"><?= inv_h($invPageTitle) ?></span>
</nav>
<a class="btn-ren btn-ren-purple ren-pill ren-top-new-mobile" href="modules/sales/invoices/create.php"><i class="fas fa-plus"></i> Create Invoice</a>
</div>

<header class="ren-page-hd">
<div class="ren-page-hd-intro">
<div class="ren-page-hd-icon" aria-hidden="true"><i class="fas fa-file-invoice"></i></div>
<div>
<h1><?= inv_h($invPageTitle) ?></h1>
<p class="ren-page-hd-sub"><?= inv_h($invPageSubtitle) ?></p>
</div>
</div>
<div class="ren-page-hd-actions">
<div class="rh">
<div class="act">
<button type="button" class="ren-filter-btn<?= $hasActiveFilters ? ' is-active' : '' ?>" id="invFilterOpen" data-bs-toggle="modal" data-bs-target="#invFilterModal" aria-label="Open filters" title="Filters">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
<line x1="4" y1="6" x2="20" y2="6"></line><circle cx="8" cy="6" r="2" fill="#fff"></circle>
<line x1="4" y1="12" x2="20" y2="12"></line><circle cx="16" cy="12" r="2" fill="#fff"></circle>
<line x1="4" y1="18" x2="20" y2="18"></line><circle cx="11" cy="18" r="2" fill="#fff"></circle>
</svg>
<?php if ($hasActiveFilters): ?><span class="ren-filter-badge" aria-hidden="true"></span><?php endif; ?>
</button>
<a class="btn-ren ren-pill" href="modules/sales/invoices/index.php"><i class="fas fa-download"></i> Export</a>
<a class="btn-ren btn-ren-purple ren-pill" href="modules/sales/invoices/create.php"><i class="fas fa-plus"></i> Create Invoice</a>
</div>
</div>
</div>
</header>

<?php if ($sqlError !== ''): ?>
<div class="alert alert-warning mb-3" role="alert">Invoice list query failed. Showing empty results. Check server error log.</div>
<?php endif; ?>

<div class="ren-kpi-grid" aria-label="Invoice summary">
<a class="ren-kpi-card<?= $kpi === 'all' ? ' ren-kpi-card--active' : '' ?>" href="revenue_invoices.php?<?= inv_h(inv_qs(['kpi' => null, 'page' => 1])) ?>">
<div class="ren-kpi-icon ren-kpi-icon--blue" aria-hidden="true"><i class="far fa-file-lines"></i></div>
<div class="ren-kpi-body">
<div class="ren-kpi-label">Total Invoices</div>
<div class="ren-kpi-value"><?= inv_h(inv_fmt($totalInvoices)) ?></div>
<span class="ren-kpi-foot">All invoices in view</span>
</div>
</a>
<a class="ren-kpi-card<?= $kpi === 'paid' ? ' ren-kpi-card--active' : '' ?>" href="revenue_invoices.php?<?= inv_h(inv_qs(['kpi' => 'paid', 'page' => 1])) ?>">
<div class="ren-kpi-icon ren-kpi-icon--green" aria-hidden="true"><i class="far fa-circle-check"></i></div>
<div class="ren-kpi-body">
<div class="ren-kpi-label">Paid Invoices</div>
<div class="ren-kpi-value"><?= inv_h(inv_fmt($totalPaid)) ?></div>
<span class="ren-kpi-foot"><?= inv_h(number_format($pctPaid, 1)) ?>% of total</span>
</div>
</a>
<a class="ren-kpi-card<?= $kpi === 'outstanding' ? ' ren-kpi-card--active' : '' ?>" href="revenue_invoices.php?<?= inv_h(inv_qs(['kpi' => 'outstanding', 'page' => 1])) ?>">
<div class="ren-kpi-icon ren-kpi-icon--orange" aria-hidden="true"><i class="far fa-clock"></i></div>
<div class="ren-kpi-body">
<div class="ren-kpi-label">Outstanding Invoices</div>
<div class="ren-kpi-value"><?= inv_h(inv_fmt($totalOutstanding)) ?></div>
<span class="ren-kpi-foot"><?= inv_h(number_format($pctOutstanding, 1)) ?>% of total</span>
</div>
</a>
<a class="ren-kpi-card<?= $kpi === 'overdue' ? ' ren-kpi-card--active' : '' ?>" href="revenue_invoices.php?<?= inv_h(inv_qs(['kpi' => 'overdue', 'page' => 1])) ?>">
<div class="ren-kpi-icon ren-kpi-icon--purple" aria-hidden="true"><i class="far fa-file"></i></div>
<div class="ren-kpi-body">
<div class="ren-kpi-label">Overdue Invoices</div>
<div class="ren-kpi-value"><?= inv_h(inv_fmt($totalOverdue)) ?></div>
<span class="ren-kpi-foot"><?= inv_h(number_format($pctOverdue, 1)) ?>% of total</span>
</div>
</a>
</div>

<form method="get" action="revenue_invoices.php" id="invF">
<input type="hidden" name="module" value="revenue">
<input type="hidden" name="search" id="invFSearch" value="<?= inv_h($search) ?>">
<input type="hidden" name="page" value="1">
<input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
<?php if ($kpi !== 'all'): ?><input type="hidden" name="kpi" value="<?= inv_h($kpi) ?>"><?php endif; ?>
<?php if (!empty($_GET['company_slug'])): ?><input type="hidden" name="company_slug" value="<?= inv_h((string) $_GET['company_slug']) ?>"><?php endif; ?>

<div class="modal fade" id="invFilterModal" tabindex="-1" aria-labelledby="invFilterModalLabel" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title" id="invFilterModalLabel">Filters</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<div class="fr">
<div class="fd"><label for="invDateFrom">Start Date</label><input type="date" name="date_from" id="invDateFrom" value="<?= inv_h($dateFrom) ?>"></div>
<div class="fd"><label for="invDateTo">End Date</label><input type="date" name="date_to" id="invDateTo" value="<?= inv_h($dateTo) ?>"></div>
<div class="fd"><label for="invCustomer">Customer</label><select name="customer_id" id="invCustomer"><option value="0">All Customers</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $customerId === (int) $c['id'] ? ' selected' : '' ?>><?= inv_h((string) $c['company_name']) ?></option><?php endforeach; ?></select></div>
<div class="fd"><label for="invStatus">Status</label><select name="status" id="invStatus"><option value="">All Statuses</option><option value="sent"<?= $status==='sent'?' selected':'' ?>>Sent</option><option value="overdue"<?= $status==='overdue'?' selected':'' ?>>Overdue</option><option value="partial"<?= $status==='partial'?' selected':'' ?>>Partial</option><option value="draft"<?= $status==='draft'?' selected':'' ?>>Draft</option><option value="unpaid"<?= $status==='unpaid'?' selected':'' ?>>Unpaid</option></select></div>
</div>
</div>
<div class="modal-footer">
<button type="submit" class="btn-ren btn-pri"><i class="fas fa-filter"></i> Filters</button>
<a class="btn-ren" href="revenue_invoices.php?module=revenue<?= !empty($_GET['company_slug']) ? '&company_slug=' . urlencode((string) $_GET['company_slug']) : '' ?>">Clear Filters</a>
</div>
</div>
</div>
</div>
</form>

<div class="tw">
<div class="ts">
<table class="tbl inv-tbl">
<thead>
<tr>
<th>Invoice #</th>
<th>Customer</th>
<th>Due Date</th>
<th>Inv Date</th>
<th class="num">Total Amount</th>
<th class="num">Balance</th>
<th>Status</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php if (!$pagedRows): ?>
<tr><td colspan="8" class="text-center text-muted py-5">No invoices found.</td></tr>
<?php else: foreach ($pagedRows as $r):
$bal = (float) ($r['balance_due'] ?? 0);
$st = (string) ($r['status'] ?? '');
$pill = invStatusClass($st, $bal);
$label = invStatusLabel($st, $bal);
$dueTs = strtotime((string) ($r['due_date'] ?? ''));
$isDueOver = $dueTs && $dueTs < strtotime(date('Y-m-d')) && $bal > 0.001;
$canPayInvoice = !($bal <= 0.001 || strtolower(trim($st)) === 'paid');

$revEntryId = (int) ($r['revenue_entry_id'] ?? 0);
if ($revEntryId <= 0) {
    require_once __DIR__ . '/includes/revenue_sync.php';
    $revEntryId = syncInvoiceToRevenue($pdo, (int) $r['id']);
}
?>
<tr>
<td><a class="vb" href="modules/sales/invoices/view.php?id=<?= (int) $r['id'] ?>"><?= inv_h((string) $r['invoice_number']) ?></a></td>
<td><span class="cn"><?= inv_h((string) $r['customer_name']) ?></span></td>
<td class="<?= $isDueOver ? 'inv-due-over' : '' ?>"><?= inv_h(fmtDate((string) ($r['due_date'] ?? ''))) ?></td>
<td><?= inv_h(fmtDate((string) ($r['invoice_date'] ?? ''))) ?></td>
<td class="num"><?= inv_h(number_format((float) $r['total_amount'], 2)) ?></td>
<td class="num <?= $bal <= 0.001 ? 'inv-bal-paid' : 'inv-bal-due' ?>"><?= inv_h(number_format($bal, 2)) ?></td>
<td><span class="tp <?= inv_h($pill) ?>"><?= inv_h($label) ?></span></td>
<td><?php if ($canPayInvoice): ?><a class="action-pay" href="revenue_record_payment.php?id=<?= $revEntryId ?>"><i class="fas fa-money-bill-transfer" aria-hidden="true"></i> Pay</a><?php endif; ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<div class="ft">
<span class="ren-ft-entries">Showing <strong><?= (int) $showFrom ?></strong> to <strong><?= (int) $showTo ?></strong> of <strong><?= (int) $totalRows ?></strong> <?= $totalRows === 1 ? 'invoice' : 'invoices' ?></span>
<div class="ren-ft-controls">
<div class="ren-rows-per">
<span class="ren-rows-per__lbl">Rows per page</span>
<label class="ren-rows-per__wrap">
<select id="invPerPage" class="ren-rows-per__sel" aria-label="Rows per page" onchange="location='revenue_invoices.php?'+invQ({per_page:this.value,page:1})"><?php foreach ([10, 25, 50, 100] as $pp): ?><option value="<?= $pp ?>"<?= $perPage === $pp ? ' selected' : '' ?>><?= $pp ?></option><?php endforeach; ?></select>
<span class="ren-rows-per__chev" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
</label>
</div>
<nav class="ren-pg" aria-label="Pagination"><?php
$pgFirst = '<span class="ren-pg__ic-first"><span class="ren-pg-bar">|</span><i class="fas fa-chevron-left"></i></span>';
$pgLast = '<span class="ren-pg__ic-last"><i class="fas fa-chevron-right"></i><span class="ren-pg-bar">|</span></span>';
if ($currentPage > 1): ?>
<a class="ren-pg__edge" href="revenue_invoices.php?<?= inv_h(inv_qs(['page' => 1])) ?>" title="First page"><?= $pgFirst ?></a>
<a class="ren-pg__edge" href="revenue_invoices.php?<?= inv_h(inv_qs(['page' => $currentPage - 1])) ?>" title="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
<?php else: ?>
<span class="ren-pg__stub ren-pg__edge ren-pg__dis" title="First page"><?= $pgFirst ?></span>
<span class="ren-pg__stub ren-pg__edge ren-pg__dis" title="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></span>
<?php endif;
foreach ($pageNumbers as $pn):
if ($pn === '...'): ?><span class="el" aria-hidden="true">...</span><?php
else:
$pi = (int) $pn; ?><a class="<?= $pi === $currentPage ? 'on' : '' ?>" href="revenue_invoices.php?<?= inv_h(inv_qs(['page' => $pi])) ?>"><?= $pi ?></a><?php
endif;
endforeach;
if ($currentPage < $totalPages): ?>
<a class="ren-pg__edge" href="revenue_invoices.php?<?= inv_h(inv_qs(['page' => $currentPage + 1])) ?>" title="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
<a class="ren-pg__edge" href="revenue_invoices.php?<?= inv_h(inv_qs(['page' => $totalPages])) ?>" title="Last page"><?= $pgLast ?></a>
<?php else: ?>
<span class="ren-pg__stub ren-pg__edge ren-pg__dis" title="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
<span class="ren-pg__stub ren-pg__edge ren-pg__dis" title="Last page"><?= $pgLast ?></span>
<?php endif; ?></nav>
</div>
</div>
</div>

<p class="mt-3 mb-0 small text-muted"><i class="fas fa-info-circle me-1"></i> Invoices are generated from sales. Record payments to update invoice balances.</p>
</div>
</main>
</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->

<script>
var invQStr=<?= json_encode(inv_qs([]), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
function invQ(x){var p=new URLSearchParams(invQStr);if(x)Object.keys(x).forEach(function(k){if(x[k]==null||x[k]==='')p.delete(k);else p.set(k,String(x[k]));});return p.toString();}
document.addEventListener('DOMContentLoaded',function(){
var h=document.getElementById('invPageSearch'),x=document.getElementById('invFSearch'),f=document.getElementById('invF');
function invPullSearch(){if(h&&x)x.value=h.value.trim();}
if(h&&x){h.addEventListener('input',invPullSearch);invPullSearch();}
if(f){f.addEventListener('submit',function(){invPullSearch();var m=document.getElementById('invFilterModal');if(m&&typeof bootstrap!=='undefined'){var inst=bootstrap.Modal.getInstance(m);if(inst)inst.hide();}});}
function invSubmitSearch(e){if(e&&e.key!=='Enter')return;if(e)e.preventDefault();invPullSearch();if(f&&typeof f.requestSubmit==='function')f.requestSubmit();else if(f)f.submit();}
if(h&&f){h.addEventListener('keydown',invSubmitSearch);}
document.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&(e.key==='k'||e.key==='K')){e.preventDefault();if(h)h.focus();}});
});
</script>
</body></html>
