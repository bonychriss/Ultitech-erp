<?php
/**
 * Customer Performance ù leaderboard ranked by sales invoice value.
 *
 * Uses the existing sales connection and company scope. Eligible invoices are
 * those the sales reports treat as real documents: not cancelled, and not
 * draft / void / deleted. Totals come from invoices.total_amount.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../customers/includes/catalogue-lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

if (function_exists('isCompanyModuleEnabled') && !isCompanyModuleEnabled('sales')) {
    header('Location: ' . (function_exists('company_url') ? company_url('select-module') : app_url('/select-module.php')));
    exit;
}

$_GET['module'] = 'sales';
$_SESSION['active_module'] = 'sales';

$salesDb = function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null);
if (!($salesDb instanceof PDO)) {
    http_response_code(500);
    echo 'Sales database connection is not available.';
    exit;
}

/**
 * @return list<string>
 */
function cp_columns(PDO $db, string $table): array
{
    if (!function_exists('sales_connection_has_table') || !sales_connection_has_table($db, $table)) {
        return [];
    }
    try {
        $cols = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_COLUMN);
        return is_array($cols) ? array_map('strval', $cols) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function cp_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cp_flag_code(?string $country): string
{
    $country = trim((string) $country);
    if ($country === '' || strcasecmp($country, 'Other') === 0) {
        return '';
    }
    if (function_exists('customerDeskCountryFlagMap')) {
        foreach (customerDeskCountryFlagMap() as $name => $code) {
            if (strcasecmp((string) $name, $country) === 0) {
                $code = strtolower(trim((string) $code));
                return ($code !== '' && $code !== 'un') ? $code : '';
            }
        }
    }
    if (preg_match('/^[A-Za-z]{2}$/', $country)) {
        return strtolower($country);
    }
    return '';
}

function cp_money(float $amount, string $currency, bool $compact = false): string
{
    $currency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'TZS';
    $sign = $amount < 0 ? '-' : '';
    $abs = abs($amount);
    if ($compact) {
        if ($abs >= 1000000000) {
            return $sign . $currency . ' ' . number_format($abs / 1000000000, 2) . 'B';
        }
        if ($abs >= 1000000) {
            return $sign . $currency . ' ' . number_format($abs / 1000000, 1) . 'M';
        }
        if ($abs >= 1000) {
            return $sign . $currency . ' ' . number_format($abs / 1000, 1) . 'K';
        }
    }
    $decimals = abs($abs - round($abs)) < 0.005 ? 0 : 2;
    return $sign . $currency . ' ' . number_format($abs, $decimals);
}

function cp_date_label(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return 'ù';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return 'ù';
    }
    return date('j M Y', $ts);
}

/**
 * @param array<string, scalar|null> $extra
 */
function cp_url(array $extra = []): string
{
    $query = $_GET;
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $query['module'] = 'sales';
    $base = function_exists('sales_module_url')
        ? sales_module_url('customer-performance/index.php')
        : 'index.php';
    return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query);
}

$now = new DateTimeImmutable('today');
$year = (int) ($_GET['year'] ?? $now->format('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) $now->format('Y');
}
$periodType = strtolower(trim((string) ($_GET['period'] ?? 'monthly')));
if (!in_array($periodType, ['monthly', 'quarterly'], true)) {
    $periodType = 'monthly';
}
$month = (int) ($_GET['month'] ?? $now->format('n'));
if ($month < 1 || $month > 12) {
    $month = (int) $now->format('n');
}
$defaultQuarter = (int) ceil(((int) $now->format('n')) / 3);
$quarter = (int) ($_GET['quarter'] ?? $defaultQuarter);
if ($quarter < 1 || $quarter > 4) {
    $quarter = $defaultQuarter;
}

if ($periodType === 'quarterly') {
    $startMonth = (($quarter - 1) * 3) + 1;
    $rangeStart = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth)))->setTime(0, 0, 0);
    $rangeEnd = $rangeStart->modify('+3 months');
    $periodLabel = 'Q' . $quarter . ' ' . $year;
} else {
    $rangeStart = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->setTime(0, 0, 0);
    $rangeEnd = $rangeStart->modify('+1 month');
    $periodLabel = $rangeStart->format('F Y');
}

$sortKey = strtolower(trim((string) ($_GET['sort'] ?? 'sales')));
$sortDir = strtolower(trim((string) ($_GET['dir'] ?? 'desc')));
if (!in_array($sortKey, ['rank', 'customer', 'sales', 'invoices', 'paid', 'outstanding', 'last'], true)) {
    $sortKey = 'sales';
}
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}
if ($sortKey === 'sales' && !isset($_GET['dir'])) {
    $sortDir = 'desc';
}

$customerId = (int) ($_GET['customer'] ?? 0);
if ($customerId < 0) {
    $customerId = 0;
}

$invoiceCols = cp_columns($salesDb, 'invoices');
$customerCols = cp_columns($salesDb, 'customers');
$loadError = '';
$rows = [];
$totalCustomers = null;
$currency = 'TZS';

if (!in_array('total_amount', $invoiceCols, true) || !in_array('customer_id', $invoiceCols, true)) {
    $loadError = 'Sales invoices are not available for this company.';
} else {
    $dateParts = [];
    if (in_array('invoice_date', $invoiceCols, true)) {
        $dateParts[] = "NULLIF(i.invoice_date, '0000-00-00')";
        $dateParts[] = "NULLIF(i.invoice_date, '0000-00-00 00:00:00')";
    }
    if (in_array('created_at', $invoiceCols, true)) {
        $dateParts[] = 'i.created_at';
    }
    if ($dateParts === []) {
        $loadError = 'Sales invoices do not have a date that can be used for this report.';
    }
}

if ($loadError === '') {
    try {
        if (function_exists('currentCompanyId')) {
            $cid = (int) currentCompanyId();
            if ($cid > 0 && function_exists('sales_connection_has_table') && sales_connection_has_table($salesDb, 'sales_settings')) {
                $stCur = $salesDb->prepare('SELECT default_currency FROM sales_settings WHERE company_id = ? LIMIT 1');
                $stCur->execute([$cid]);
                $curRow = $stCur->fetch(PDO::FETCH_ASSOC);
                if (!empty($curRow['default_currency'])) {
                    $currency = strtoupper(trim((string) $curRow['default_currency']));
                }
            }
        }
        if ($currency === 'TZS' && function_exists('sales_connection_has_table') && sales_connection_has_table($salesDb, 'sales_settings')) {
            $curRow = $salesDb->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!empty($curRow['default_currency'])) {
                $currency = strtoupper(trim((string) $curRow['default_currency']));
            }
        }
    } catch (Throwable $e) {
        $currency = 'TZS';
    }
    if ($currency === '') {
        $currency = 'TZS';
    }

    $dateExpr = 'DATE(COALESCE(' . implode(', ', $dateParts) . '))';
    $nameExpr = "CONCAT('Customer #', i.customer_id)";
    if (in_array('company_name', $customerCols, true) && in_array('contact_person', $customerCols, true)) {
        $nameExpr = "COALESCE(NULLIF(TRIM(c.company_name), ''), NULLIF(TRIM(c.contact_person), ''), CONCAT('Customer #', i.customer_id))";
    } elseif (in_array('company_name', $customerCols, true)) {
        $nameExpr = "COALESCE(NULLIF(TRIM(c.company_name), ''), CONCAT('Customer #', i.customer_id))";
    } elseif (in_array('contact_person', $customerCols, true)) {
        $nameExpr = "COALESCE(NULLIF(TRIM(c.contact_person), ''), CONCAT('Customer #', i.customer_id))";
    }
    $countryExpr = in_array('country', $customerCols, true)
        ? "MAX(NULLIF(TRIM(c.country), ''))"
        : 'NULL';

    $paidExpr = 'NULL';
    $outstandingExpr = 'NULL';
    $hasPaid = in_array('amount_paid', $invoiceCols, true);
    $hasBalance = in_array('balance_due', $invoiceCols, true);
    if ($hasPaid && $hasBalance) {
        $paidExpr = 'SUM(COALESCE(i.amount_paid, 0))';
        $outstandingExpr = 'SUM(COALESCE(i.balance_due, GREATEST(COALESCE(i.total_amount, 0) - COALESCE(i.amount_paid, 0), 0)))';
    } elseif ($hasPaid) {
        $paidExpr = 'SUM(COALESCE(i.amount_paid, 0))';
        $outstandingExpr = 'SUM(GREATEST(COALESCE(i.total_amount, 0) - COALESCE(i.amount_paid, 0), 0))';
    } elseif ($hasBalance) {
        $outstandingExpr = 'SUM(COALESCE(i.balance_due, 0))';
        $paidExpr = 'SUM(GREATEST(COALESCE(i.total_amount, 0) - COALESCE(i.balance_due, 0), 0))';
    }

    $sql = "SELECT i.customer_id,
            MAX({$nameExpr}) AS customer_name,
            {$countryExpr} AS country,
            SUM(COALESCE(i.total_amount, 0)) AS total_sales,
            COUNT(i.id) AS invoice_count,
            {$paidExpr} AS paid_amount,
            {$outstandingExpr} AS outstanding_amount,
            MAX({$dateExpr}) AS last_purchase,
            MIN({$dateExpr}) AS first_purchase
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE i.customer_id IS NOT NULL AND i.customer_id > 0
          AND {$dateExpr} >= ? AND {$dateExpr} < ?";
    $params = [$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')];

    if (in_array('status', $invoiceCols, true)) {
        $sql .= " AND LOWER(TRIM(COALESCE(i.status, ''))) NOT IN ('cancelled', 'canceled', 'draft', 'void', 'voided', 'deleted')";
    }
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($sql, $params, 'invoices', 'i');
    }
    $sql .= ' GROUP BY i.customer_id
        HAVING SUM(COALESCE(i.total_amount, 0)) > 0
        ORDER BY total_sales DESC, customer_name ASC';

    try {
        $stmt = $salesDb->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('customer performance aggregate: ' . $e->getMessage());
        $loadError = 'Customer performance could not be loaded.';
        $rows = [];
    }

    if ($customerCols !== []) {
        try {
            $countSql = 'SELECT COUNT(*) FROM customers c WHERE 1=1';
            $countParams = [];
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($countSql, $countParams, 'customers', 'c');
            }
            $countStmt = $salesDb->prepare($countSql);
            $countStmt->execute($countParams);
            $totalCustomers = (int) $countStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('customer performance customer count: ' . $e->getMessage());
            $totalCustomers = null;
        }
    }
}

$rank = 0;
foreach ($rows as &$row) {
    $rank++;
    $row['rank'] = $rank;
    $row['customer_id'] = (int) ($row['customer_id'] ?? 0);
    $row['customer_name'] = trim((string) ($row['customer_name'] ?? ''));
    if ($row['customer_name'] === '') {
        $row['customer_name'] = 'Customer #' . $row['customer_id'];
    }
    $row['total_sales'] = (float) ($row['total_sales'] ?? 0);
    $row['invoice_count'] = (int) ($row['invoice_count'] ?? 0);
    $row['paid_amount'] = $row['paid_amount'] === null ? null : (float) $row['paid_amount'];
    $row['outstanding_amount'] = $row['outstanding_amount'] === null ? null : (float) $row['outstanding_amount'];
}
unset($row);

$activeCustomers = count($rows);
$totalSales = 0.0;
foreach ($rows as $row) {
    $totalSales += (float) $row['total_sales'];
}
$averageSales = $activeCustomers > 0 ? $totalSales / $activeCustomers : 0.0;

$displayRows = $rows;
if ($sortKey !== 'sales' || $sortDir !== 'desc') {
    $mult = $sortDir === 'asc' ? 1 : -1;
    usort($displayRows, static function (array $a, array $b) use ($sortKey, $mult): int {
        switch ($sortKey) {
            case 'customer':
                $cmp = strcasecmp((string) $a['customer_name'], (string) $b['customer_name']);
                break;
            case 'invoices':
                $cmp = ((int) $a['invoice_count']) <=> ((int) $b['invoice_count']);
                break;
            case 'paid':
                $cmp = ((float) ($a['paid_amount'] ?? 0)) <=> ((float) ($b['paid_amount'] ?? 0));
                break;
            case 'outstanding':
                $cmp = ((float) ($a['outstanding_amount'] ?? 0)) <=> ((float) ($b['outstanding_amount'] ?? 0));
                break;
            case 'last':
                $cmp = strcmp((string) ($a['last_purchase'] ?? ''), (string) ($b['last_purchase'] ?? ''));
                break;
            case 'rank':
                $cmp = ((int) $a['rank']) <=> ((int) $b['rank']);
                break;
            default:
                $cmp = ((float) $a['total_sales']) <=> ((float) $b['total_sales']);
                break;
        }
        if ($cmp === 0) {
            $cmp = ((int) $a['rank']) <=> ((int) $b['rank']);
        }
        return $cmp * $mult;
    });
}

$selected = null;
foreach ($rows as $row) {
    if ((int) $row['customer_id'] === $customerId) {
        $selected = $row;
        break;
    }
}

$detailInvoices = [];
if ($customerId > 0 && $loadError === '' && $selected !== null) {
    $dateExpr = 'DATE(COALESCE(' . implode(', ', $dateParts) . '))';
    $numberExpr = in_array('invoice_number', $invoiceCols, true)
        ? 'i.invoice_number'
        : "CONCAT('INV-', i.id)";
    $statusExpr = in_array('status', $invoiceCols, true) ? 'i.status' : "''";
    $paidSelect = $hasPaid ? 'i.amount_paid' : ($hasBalance ? 'GREATEST(COALESCE(i.total_amount, 0) - COALESCE(i.balance_due, 0), 0)' : 'NULL');
    $dueSelect = $hasBalance ? 'i.balance_due' : ($hasPaid ? 'GREATEST(COALESCE(i.total_amount, 0) - COALESCE(i.amount_paid, 0), 0)' : 'NULL');
    $detailSql = "SELECT i.id, {$numberExpr} AS invoice_number, {$dateExpr} AS invoice_day,
            COALESCE(i.total_amount, 0) AS total_amount, {$paidSelect} AS amount_paid,
            {$dueSelect} AS balance_due, {$statusExpr} AS status
        FROM invoices i
        WHERE i.customer_id = ?
          AND {$dateExpr} >= ? AND {$dateExpr} < ?";
    $detailParams = [$customerId, $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')];
    if (in_array('status', $invoiceCols, true)) {
        $detailSql .= " AND LOWER(TRIM(COALESCE(i.status, ''))) NOT IN ('cancelled', 'canceled', 'draft', 'void', 'voided', 'deleted')";
    }
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($detailSql, $detailParams, 'invoices', 'i');
    }
    $detailSql .= ' ORDER BY invoice_day DESC, i.id DESC';
    try {
        $detailStmt = $salesDb->prepare($detailSql);
        $detailStmt->execute($detailParams);
        $detailInvoices = $detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('customer performance detail: ' . $e->getMessage());
        $detailInvoices = [];
    }
}

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$quarterNames = [
    1 => 'Q1 ù January ù March',
    2 => 'Q2 ù April ù June',
    3 => 'Q3 ù July ù September',
    4 => 'Q4 ù October ù December',
];
$yearOptions = range((int) $now->format('Y') + 1, (int) $now->format('Y') - 8);
if (!in_array($year, $yearOptions, true)) {
    $yearOptions[] = $year;
    rsort($yearOptions);
}

$top = array_slice($rows, 0, 3);
$podium = [
    1 => $top[0] ?? null,
    2 => $top[1] ?? null,
    3 => $top[2] ?? null,
];

function cp_sort_url(string $key, string $currentKey, string $currentDir): string
{
    $next = 'desc';
    if ($currentKey === $key) {
        $next = $currentDir === 'desc' ? 'asc' : 'desc';
    } elseif ($key === 'customer' || $key === 'rank') {
        $next = 'asc';
    }
    return cp_url(['sort' => $key, 'dir' => $next, 'customer' => null]);
}

function cp_trophy_svg(string $tone): string
{
    $palette = [
        'gold' => ['#fff4c2', '#e0b33a', '#8d6b14'],
        'silver' => ['#f8fafc', '#c5ced8', '#64748b'],
        'bronze' => ['#f6d2b5', '#c4845a', '#7c4a2d'],
    ];
    $c = $palette[$tone] ?? $palette['gold'];
    $id = 'cpCup' . $tone;
    return '<svg class="cp-trophy" viewBox="0 0 64 64" aria-hidden="true">'
        . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="' . $c[0] . '"/><stop offset="0.55" stop-color="' . $c[1] . '"/><stop offset="1" stop-color="' . $c[2] . '"/>'
        . '</linearGradient></defs>'
        . '<path fill="url(#' . $id . ')" d="M18 10h28v8c0 8.4-5.4 15.4-12.6 17.4V42h8.2c1.2 0 2.2 1 2.2 2.2V48H20.2v-3.8c0-1.2 1-2.2 2.2-2.2h8.2v-6.6C23.4 33.4 18 26.4 18 18V10z"/>'
        . '<path fill="url(#' . $id . ')" d="M18 14h-6.2C10.3 14 9 15.4 9 17.1 9 22 12.6 26 17.2 26H18v-4h-1.1c-2.2 0-4-1.6-4-3.6 0-.7.5-1.4 1.4-1.4H18v-3zM46 14h6.2c1.5 0 2.8 1.4 2.8 3.1C55 22 51.4 26 46.8 26H46v-4h1.1c2.2 0 4-1.6 4-3.6 0-.7-.5-1.4-1.4-1.4H46v-3z"/>'
        . '<rect x="22" y="48" width="20" height="5" rx="1.5" fill="url(#' . $id . ')"/>'
        . '</svg>';
}

$page_title = 'Customer Performance';
$employeeHeaderTitle = 'Customer Performance';
$employeeHeaderSubtitle = $periodLabel;
$employeeHeaderExtraClass = 'employee-header--exp-desk';

$customerProfileUrl = '';
if ($selected !== null && function_exists('sales_module_url')) {
    $customerProfileUrl = sales_module_url('customers/view.php', [
        'id' => (int) $selected['customer_id'],
        'module' => 'sales',
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= cp_h($page_title) ?> - ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="dashboard page-exp-desk">
<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>
<style>
.cp-page { padding: 0.5rem 1.25rem 2.5rem; max-width: 1180px; color: #0f172a; }
.cp-filters { display: flex; flex-wrap: wrap; gap: 0.75rem 1rem; align-items: flex-end; margin-bottom: 1.25rem; }
.cp-field { display: flex; flex-direction: column; gap: 0.3rem; min-width: 9.5rem; }
.cp-field label { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; }
.cp-field select { height: 2.5rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; color: #0f172a; padding: 0 0.75rem; font-weight: 600; }
.cp-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.85rem; margin-bottom: 1.35rem; }
.cp-kpi { background: #fff; border: 1px solid #e8edf3; border-radius: 14px; padding: 0.95rem 1rem 0.85rem; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04); }
.cp-kpi span { display: block; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; }
.cp-kpi strong { display: block; margin-top: 0.35rem; font-size: 1.35rem; font-weight: 750; letter-spacing: -0.02em; }
.cp-section-title { font-size: 0.78rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #64748b; margin: 0 0 0.85rem; }
.cp-podium { display: grid; grid-template-columns: 1fr 1.12fr 1fr; grid-template-rows: auto auto; gap: 0.9rem; align-items: end; margin-bottom: 1.5rem; }
.cp-card { display: block; text-decoration: none; color: inherit; background: #fff; border: 1px solid #e8edf3; border-radius: 18px; padding: 1.15rem 1rem 1rem; text-align: center; box-shadow: 0 10px 28px rgba(15, 23, 42, 0.05); transition: transform 0.18s ease, box-shadow 0.18s ease; min-height: 210px; }
.cp-card:hover { transform: translateY(-3px); box-shadow: 0 16px 36px rgba(15, 23, 42, 0.1); color: inherit; }
.cp-card--1 { grid-column: 2; grid-row: 1 / span 2; align-self: stretch; padding-top: 1.45rem; background: linear-gradient(180deg, #fffdf6 0%, #fff 42%); border-color: #ead89a; }
.cp-card--2 { grid-column: 1; grid-row: 2; background: linear-gradient(180deg, #fbfcfe 0%, #fff 40%); }
.cp-card--3 { grid-column: 3; grid-row: 2; background: linear-gradient(180deg, #fff8f3 0%, #fff 42%); }
.cp-place { font-size: 0.78rem; font-weight: 800; letter-spacing: 0.08em; color: #94a3b8; }
.cp-card--1 .cp-place { color: #a16207; }
.cp-card--2 .cp-place { color: #64748b; }
.cp-card--3 .cp-place { color: #9a3412; }
.cp-trophy { width: 58px; height: 58px; margin: 0.35rem auto 0.45rem; display: block; filter: drop-shadow(0 6px 8px rgba(15, 23, 42, 0.12)); }
.cp-card--1 .cp-trophy { width: 74px; height: 74px; }
.cp-name { font-size: 1.02rem; font-weight: 750; line-height: 1.3; margin: 0.15rem 0 0.45rem; }
.cp-flag { width: 18px; height: 13px; object-fit: cover; border-radius: 2px; margin-right: 0.35rem; vertical-align: -1px; box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.08); }
.cp-amount { font-size: 1.05rem; font-weight: 750; letter-spacing: -0.02em; }
.cp-card--1 .cp-amount { font-size: 1.28rem; }
.cp-meta { margin-top: 0.25rem; color: #64748b; font-size: 0.84rem; font-weight: 600; }
.cp-table-wrap { background: #fff; border: 1px solid #e8edf3; border-radius: 16px; overflow: auto; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04); }
.cp-table { width: 100%; border-collapse: collapse; min-width: 760px; }
.cp-table th, .cp-table td { padding: 0.78rem 0.9rem; text-align: left; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
.cp-table th { font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; background: #f8fafc; }
.cp-table th a { color: inherit; text-decoration: none; }
.cp-table th a:hover { color: #0f172a; }
.cp-table tbody tr:hover { background: #f8fafc; }
.cp-table td a.cp-rowlink { color: #0f172a; font-weight: 700; text-decoration: none; }
.cp-table td a.cp-rowlink:hover { color: #1d4ed8; }
.cp-num { text-align: right !important; font-variant-numeric: tabular-nums; }
.cp-empty { background: #fff; border: 1px dashed #cbd5e1; border-radius: 16px; padding: 2.4rem 1rem; text-align: center; color: #475569; }
.cp-empty strong { display: block; color: #0f172a; font-size: 1.05rem; margin-bottom: 0.35rem; }
.cp-back { display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 650; color: #334155; text-decoration: none; margin-bottom: 0.85rem; }
.cp-back:hover { color: #0f172a; }
.cp-detail-head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; margin-bottom: 1rem; }
.cp-detail-head h2 { margin: 0; font-size: 1.45rem; font-weight: 780; }
.cp-profile { font-weight: 700; color: #1d4ed8; text-decoration: none; white-space: nowrap; }
.cp-detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.75rem; margin-bottom: 1.1rem; }
.cp-status { display: inline-block; padding: 0.15rem 0.45rem; border-radius: 999px; background: #f1f5f9; font-size: 0.75rem; font-weight: 700; text-transform: capitalize; }
html[data-theme="dark"] .cp-page { color: #f8fafc; }
html[data-theme="dark"] .cp-field select,
html[data-theme="dark"] .cp-kpi,
html[data-theme="dark"] .cp-card,
html[data-theme="dark"] .cp-table-wrap,
html[data-theme="dark"] .cp-empty { background: #1e293b; color: #f8fafc; border-color: #334155; }
html[data-theme="dark"] .cp-card--1 { background: linear-gradient(180deg, #3a3118 0%, #1e293b 48%); }
html[data-theme="dark"] .cp-card--2 { background: linear-gradient(180deg, #243044 0%, #1e293b 48%); }
html[data-theme="dark"] .cp-card--3 { background: linear-gradient(180deg, #3a2a22 0%, #1e293b 48%); }
html[data-theme="dark"] .cp-table th { background: #0f172a; color: #cbd5e1; }
html[data-theme="dark"] .cp-table td { border-color: #334155; }
html[data-theme="dark"] .cp-table tbody tr:hover { background: #243044; }
html[data-theme="dark"] .cp-table td a.cp-rowlink,
html[data-theme="dark"] .cp-name,
html[data-theme="dark"] .cp-amount,
html[data-theme="dark"] .cp-kpi strong,
html[data-theme="dark"] .cp-detail-head h2,
html[data-theme="dark"] .cp-empty strong { color: #f8fafc; }
html[data-theme="dark"] .cp-field label,
html[data-theme="dark"] .cp-kpi span,
html[data-theme="dark"] .cp-meta,
html[data-theme="dark"] .cp-section-title { color: #94a3b8; }
@media (max-width: 980px) {
    .cp-kpis, .cp-detail-grid { grid-template-columns: 1fr 1fr; }
    .cp-podium { display: flex; flex-direction: column; }
    .cp-card--1, .cp-card--2, .cp-card--3 { grid-column: auto; grid-row: auto; }
    .cp-card--2 { order: 2; }
    .cp-card--1 { order: 1; }
    .cp-card--3 { order: 3; }
}
@media (max-width: 640px) {
    .cp-page { padding-left: 0.85rem; padding-right: 0.85rem; }
    .cp-kpis, .cp-detail-grid { grid-template-columns: 1fr; }
    .cp-detail-head { flex-direction: column; }
}
</style>
<main class="cp-page">
    <form class="cp-filters" method="get" action="<?= cp_h(strtok(function_exists('sales_module_url') ? sales_module_url('customer-performance/index.php') : 'index.php', '?') ?: 'index.php') ?>">
        <input type="hidden" name="module" value="sales">
        <?php if ($sortKey !== 'sales' || $sortDir !== 'desc'): ?>
            <input type="hidden" name="sort" value="<?= cp_h($sortKey) ?>">
            <input type="hidden" name="dir" value="<?= cp_h($sortDir) ?>">
        <?php endif; ?>
        <?php if ($customerId > 0): ?>
            <input type="hidden" name="customer" value="<?= (int) $customerId ?>">
        <?php endif; ?>
        <div class="cp-field">
            <label for="cp-year">Year</label>
            <select id="cp-year" name="year" onchange="this.form.submit()">
                <?php foreach ($yearOptions as $y): ?>
                    <option value="<?= (int) $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int) $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cp-field">
            <label for="cp-period">Period type</label>
            <select id="cp-period" name="period" onchange="this.form.submit()">
                <option value="monthly" <?= $periodType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="quarterly" <?= $periodType === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
            </select>
        </div>
        <?php if ($periodType === 'quarterly'): ?>
            <div class="cp-field">
                <label for="cp-quarter">Quarter</label>
                <select id="cp-quarter" name="quarter" onchange="this.form.submit()">
                    <?php foreach ($quarterNames as $q => $label): ?>
                        <option value="<?= (int) $q ?>" <?= $q === $quarter ? 'selected' : '' ?>><?= cp_h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="cp-field">
                <label for="cp-month">Period</label>
                <select id="cp-month" name="month" onchange="this.form.submit()">
                    <?php foreach ($monthNames as $m => $label): ?>
                        <option value="<?= (int) $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= cp_h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </form>

    <section class="cp-kpis" aria-label="Period summary">
        <article class="cp-kpi"><span>Total customers</span><strong><?= $totalCustomers === null ? 'ù' : number_format($totalCustomers) ?></strong></article>
        <article class="cp-kpi"><span>Active customers</span><strong><?= number_format($activeCustomers) ?></strong></article>
        <article class="cp-kpi"><span>Total sales</span><strong><?= cp_h(cp_money($totalSales, $currency, true)) ?></strong></article>
        <article class="cp-kpi"><span>Average customer sales</span><strong><?= cp_h(cp_money($averageSales, $currency, true)) ?></strong></article>
    </section>

    <?php if ($loadError !== ''): ?>
        <div class="cp-empty"><strong>Customer performance is unavailable</strong><?= cp_h($loadError) ?></div>
    <?php elseif ($customerId > 0 && $selected === null): ?>
        <a class="cp-back" href="<?= cp_h(cp_url(['customer' => null])) ?>">? Back to ranking</a>
        <div class="cp-empty"><strong>No customer sales recorded for this period.</strong>This customer has no eligible sales invoices in <?= cp_h($periodLabel) ?>.</div>
    <?php elseif ($selected !== null): ?>
        <?php
            $flag = cp_flag_code((string) ($selected['country'] ?? ''));
            $invoiceCount = (int) $selected['invoice_count'];
            $avgInvoice = $invoiceCount > 0 ? ((float) $selected['total_sales'] / $invoiceCount) : 0.0;
        ?>
        <a class="cp-back" href="<?= cp_h(cp_url(['customer' => null])) ?>">? Back to ranking</a>
        <div class="cp-detail-head">
            <h2>
                <?php if ($flag !== ''): ?><img class="cp-flag" src="https://flagcdn.com/w40/<?= cp_h($flag) ?>.png" alt=""><?php endif; ?>
                <?= cp_h((string) $selected['customer_name']) ?>
            </h2>
            <?php if ($customerProfileUrl !== ''): ?>
                <a class="cp-profile" href="<?= cp_h($customerProfileUrl) ?>">Open customer</a>
            <?php endif; ?>
        </div>
        <section class="cp-detail-grid">
            <article class="cp-kpi"><span>Total sales</span><strong><?= cp_h(cp_money((float) $selected['total_sales'], $currency)) ?></strong></article>
            <article class="cp-kpi"><span>Invoices</span><strong><?= number_format($invoiceCount) ?></strong></article>
            <article class="cp-kpi"><span>Paid</span><strong><?= $selected['paid_amount'] === null ? 'ù' : cp_h(cp_money((float) $selected['paid_amount'], $currency)) ?></strong></article>
            <article class="cp-kpi"><span>Outstanding</span><strong><?= $selected['outstanding_amount'] === null ? 'ù' : cp_h(cp_money((float) $selected['outstanding_amount'], $currency)) ?></strong></article>
            <article class="cp-kpi"><span>Average invoice</span><strong><?= cp_h(cp_money($avgInvoice, $currency)) ?></strong></article>
            <article class="cp-kpi"><span>First purchase</span><strong><?= cp_h(cp_date_label((string) ($selected['first_purchase'] ?? ''))) ?></strong></article>
            <article class="cp-kpi"><span>Last purchase</span><strong><?= cp_h(cp_date_label((string) ($selected['last_purchase'] ?? ''))) ?></strong></article>
            <article class="cp-kpi"><span>Rank</span><strong>#<?= (int) $selected['rank'] ?></strong></article>
        </section>
        <h3 class="cp-section-title">Sales invoices ù <?= cp_h($periodLabel) ?></h3>
        <?php if ($detailInvoices === []): ?>
            <div class="cp-empty"><strong>No customer sales recorded for this period.</strong></div>
        <?php else: ?>
            <div class="cp-table-wrap">
                <table class="cp-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="cp-num">Total</th>
                            <th class="cp-num">Paid</th>
                            <th class="cp-num">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detailInvoices as $inv): ?>
                        <?php
                            $invId = (int) ($inv['id'] ?? 0);
                            $invUrl = function_exists('sales_module_url')
                                ? sales_module_url('invoices/view.php', ['id' => $invId, 'module' => 'sales'])
                                : '';
                            $paidVal = $inv['amount_paid'];
                            $dueVal = $inv['balance_due'];
                        ?>
                        <tr>
                            <td><?php if ($invUrl !== ''): ?><a class="cp-rowlink" href="<?= cp_h($invUrl) ?>"><?= cp_h((string) ($inv['invoice_number'] ?? ('INV-' . $invId))) ?></a><?php else: ?><?= cp_h((string) ($inv['invoice_number'] ?? '')) ?><?php endif; ?></td>
                            <td><?= cp_h(cp_date_label((string) ($inv['invoice_day'] ?? ''))) ?></td>
                            <td><span class="cp-status"><?= cp_h((string) ($inv['status'] ?? '')) ?></span></td>
                            <td class="cp-num"><?= cp_h(cp_money((float) ($inv['total_amount'] ?? 0), $currency)) ?></td>
                            <td class="cp-num"><?= $paidVal === null ? 'ù' : cp_h(cp_money((float) $paidVal, $currency)) ?></td>
                            <td class="cp-num"><?= $dueVal === null ? 'ù' : cp_h(cp_money((float) $dueVal, $currency)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($rows === []): ?>
        <div class="cp-empty">
            <strong>No customer sales recorded for this period.</strong>
            Eligible sales invoices in <?= cp_h($periodLabel) ?> will appear here.
        </div>
    <?php else: ?>
        <h2 class="cp-section-title">Top customers</h2>
        <section class="cp-podium" aria-label="Top three customers">
            <?php foreach ([1 => 'gold', 2 => 'silver', 3 => 'bronze'] as $place => $tone): ?>
                <?php if (empty($podium[$place])) { continue; } ?>
                <?php
                    $card = $podium[$place];
                    $flag = cp_flag_code((string) ($card['country'] ?? ''));
                    $href = cp_url(['customer' => (int) $card['customer_id']]);
                ?>
                <a class="cp-card cp-card--<?= (int) $place ?>" href="<?= cp_h($href) ?>">
                    <div class="cp-place">#<?= (int) $place ?></div>
                    <?= cp_trophy_svg($tone) ?>
                    <div class="cp-name">
                        <?php if ($flag !== ''): ?><img class="cp-flag" src="https://flagcdn.com/w40/<?= cp_h($flag) ?>.png" alt="<?= cp_h((string) ($card['country'] ?? '')) ?>"><?php endif; ?>
                        <?= cp_h((string) $card['customer_name']) ?>
                    </div>
                    <div class="cp-amount"><?= cp_h(cp_money((float) $card['total_sales'], $currency)) ?></div>
                    <div class="cp-meta"><?= number_format((int) $card['invoice_count']) ?> <?= ((int) $card['invoice_count'] === 1) ? 'invoice' : 'invoices' ?></div>
                </a>
            <?php endforeach; ?>
        </section>

        <h2 class="cp-section-title">Customer ranking</h2>
        <div class="cp-table-wrap">
            <table class="cp-table">
                <thead>
                    <tr>
                        <?php
                        $heads = [
                            'rank' => 'Rank',
                            'customer' => 'Customer',
                            'sales' => 'Total sales',
                            'invoices' => 'Invoices',
                            'paid' => 'Paid',
                            'outstanding' => 'Outstanding',
                            'last' => 'Last purchase',
                        ];
                        foreach ($heads as $key => $label):
                            $isNum = !in_array($key, ['customer', 'last'], true);
                        ?>
                            <th class="<?= $isNum ? 'cp-num' : '' ?>">
                                <a href="<?= cp_h(cp_sort_url($key, $sortKey, $sortDir)) ?>"><?= cp_h($label) ?><?php if ($sortKey === $key): ?> <?= $sortDir === 'asc' ? '?' : '?' ?><?php endif; ?></a>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($displayRows as $row): ?>
                    <?php $flag = cp_flag_code((string) ($row['country'] ?? '')); ?>
                    <tr>
                        <td class="cp-num">#<?= (int) $row['rank'] ?></td>
                        <td>
                            <a class="cp-rowlink" href="<?= cp_h(cp_url(['customer' => (int) $row['customer_id']])) ?>">
                                <?php if ($flag !== ''): ?><img class="cp-flag" src="https://flagcdn.com/w40/<?= cp_h($flag) ?>.png" alt=""><?php endif; ?>
                                <?= cp_h((string) $row['customer_name']) ?>
                            </a>
                        </td>
                        <td class="cp-num"><?= cp_h(cp_money((float) $row['total_sales'], $currency)) ?></td>
                        <td class="cp-num"><?= number_format((int) $row['invoice_count']) ?></td>
                        <td class="cp-num"><?= $row['paid_amount'] === null ? 'ù' : cp_h(cp_money((float) $row['paid_amount'], $currency, true)) ?></td>
                        <td class="cp-num"><?= $row['outstanding_amount'] === null ? 'ù' : cp_h(cp_money((float) $row['outstanding_amount'], $currency, true)) ?></td>
                        <td><?= cp_h(cp_date_label((string) ($row['last_purchase'] ?? ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
