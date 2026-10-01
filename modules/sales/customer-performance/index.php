<?php
/**
 * Customer Performance - leaderboard ranked by sales invoice value.
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

function cp_kpi_change(float $current, float $previous, string $kind, string $currency = 'TZS'): string
{
    $delta = $current - $previous;
    if (abs($delta) < 0.5) {
        return '<span class="cp-kpi-flat">0</span>';
    }
    $up = $delta > 0;
    if ($kind === 'count') {
        $text = ($up ? '+ ' : '- ') . number_format(abs($delta));
    } else {
        $text = ($up ? '+ ' : '- ') . cp_money(abs($delta), $currency, true);
    }
    return '<span class="' . ($up ? 'cp-kpi-up' : 'cp-kpi-down') . '">'
        . cp_h($text) . ($up ? ' &#8593;' : ' &#8595;') . '</span>';
}

function cp_date_label(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '-';
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
if (!in_array($periodType, ['monthly', 'quarterly', 'yearly'], true)) {
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

if ($periodType === 'yearly') {
    $rangeStart = (new DateTimeImmutable(sprintf('%04d-01-01', $year)))->setTime(0, 0, 0);
    $rangeEnd = $rangeStart->modify('+1 year');
    $periodLabel = (string) $year;
} elseif ($periodType === 'quarterly') {
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
$prevActive = null;
$prevSales = null;
$newCustomers = null;
$trendMap = [];
$prevStart = null;
$compareLabel = $periodType === 'yearly' ? 'vs previous year' : ($periodType === 'quarterly' ? 'vs previous quarter' : 'vs previous month');

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

    if ($loadError === '') {
        if ($periodType === 'yearly') {
            $prevStart = $rangeStart->modify('-1 year');
        } elseif ($periodType === 'quarterly') {
            $prevStart = $rangeStart->modify('-3 months');
        } else {
            $prevStart = $rangeStart->modify('-1 month');
        }
        $prevSql = "SELECT COUNT(*) AS active_customers, COALESCE(SUM(sales_total), 0) AS total_sales FROM (
            SELECT SUM(COALESCE(i.total_amount, 0)) AS sales_total
            FROM invoices i
            WHERE i.customer_id IS NOT NULL AND i.customer_id > 0
              AND {$dateExpr} >= ? AND {$dateExpr} < ?";
        $prevParams = [$prevStart->format('Y-m-d'), $rangeStart->format('Y-m-d')];
        if (in_array('status', $invoiceCols, true)) {
            $prevSql .= " AND LOWER(TRIM(COALESCE(i.status, ''))) NOT IN ('cancelled', 'canceled', 'draft', 'void', 'voided', 'deleted')";
        }
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($prevSql, $prevParams, 'invoices', 'i');
        }
        $prevSql .= ' GROUP BY i.customer_id HAVING SUM(COALESCE(i.total_amount, 0)) > 0) prev_customers';
        try {
            $prevStmt = $salesDb->prepare($prevSql);
            $prevStmt->execute($prevParams);
            $prevRow = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $prevActive = (int) ($prevRow['active_customers'] ?? 0);
            $prevSales = (float) ($prevRow['total_sales'] ?? 0);
        } catch (Throwable $e) {
            error_log('customer performance previous period: ' . $e->getMessage());
        }

        if (in_array('created_at', $customerCols, true)) {
            try {
                $newSql = 'SELECT COUNT(*) FROM customers c WHERE c.created_at >= ? AND c.created_at < ?';
                $newParams = [$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d H:i:s')];
                if (function_exists('salesAppendCompanyScope')) {
                    salesAppendCompanyScope($newSql, $newParams, 'customers', 'c');
                }
                $newStmt = $salesDb->prepare($newSql);
                $newStmt->execute($newParams);
                $newCustomers = (int) $newStmt->fetchColumn();
            } catch (Throwable $e) {
                error_log('customer performance new customers: ' . $e->getMessage());
            }
        }

        $bucketExpr = $periodType === 'monthly' ? 'DAY(' . $dateExpr . ')' : 'MONTH(' . $dateExpr . ')';
        $trendSql = "SELECT {$bucketExpr} AS bucket, SUM(COALESCE(i.total_amount, 0)) AS amount
            FROM invoices i
            WHERE i.customer_id IS NOT NULL AND i.customer_id > 0
              AND {$dateExpr} >= ? AND {$dateExpr} < ?";
        $trendParams = [$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')];
        if (in_array('status', $invoiceCols, true)) {
            $trendSql .= " AND LOWER(TRIM(COALESCE(i.status, ''))) NOT IN ('cancelled', 'canceled', 'draft', 'void', 'voided', 'deleted')";
        }
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($trendSql, $trendParams, 'invoices', 'i');
        }
        $trendSql .= ' GROUP BY bucket';
        try {
            $trendStmt = $salesDb->prepare($trendSql);
            $trendStmt->execute($trendParams);
            foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $trendRow) {
                $trendMap[(int) $trendRow['bucket']] = (float) $trendRow['amount'];
            }
        } catch (Throwable $e) {
            error_log('customer performance trend: ' . $e->getMessage());
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
            return ((int) $a['rank']) <=> ((int) $b['rank']);
        }
        return $cmp * $mult;
    });
}

$search = trim((string) ($_GET['q'] ?? ''));
if (strlen($search) > 80) {
    $search = substr($search, 0, 80);
}
if ($search !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
    $displayRows = array_values(array_filter($displayRows, static function (array $row) use ($needle): bool {
        $name = function_exists('mb_strtolower')
            ? mb_strtolower((string) $row['customer_name'])
            : strtolower((string) $row['customer_name']);
        return str_contains($name, $needle);
    }));
}

$pageSize = 10;
$pageCount = max(1, (int) ceil(count($displayRows) / $pageSize));
$page = (int) ($_GET['p'] ?? 1);
if ($page < 1) {
    $page = 1;
}
if ($page > $pageCount) {
    $page = $pageCount;
}
$pageRows = array_slice($displayRows, ($page - 1) * $pageSize, $pageSize);

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
    $dueDateSelect = in_array('due_date', $invoiceCols, true) ? 'i.due_date' : 'NULL';
    $detailSql = "SELECT i.id, {$numberExpr} AS invoice_number, {$dateExpr} AS invoice_day,
            {$dueDateSelect} AS due_date,
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
    1 => 'Q1 - January - March',
    2 => 'Q2 - April - June',
    3 => 'Q3 - July - September',
    4 => 'Q4 - October - December',
];
$yearOptions = range((int) $now->format('Y') + 1, (int) $now->format('Y') - 8);
if (!in_array($year, $yearOptions, true)) {
    $yearOptions[] = $year;
    rsort($yearOptions);
}

$topPerformer = $rows[0] ?? null;
$contribution = array_slice($rows, 0, 5);

$trendPoints = [];
if ($periodType === 'monthly') {
    $days = (int) $rangeStart->format('t');
    for ($day = 1; $day <= $days; $day++) {
        $trendPoints[] = [
            'label' => (string) $day,
            'amount' => (float) ($trendMap[$day] ?? 0),
        ];
    }
    $trendTitle = 'Daily sales';
    $trendSubtitle = $rangeStart->format('F Y');
} elseif ($periodType === 'quarterly') {
    $startMonth = (int) $rangeStart->format('n');
    for ($offset = 0; $offset < 3; $offset++) {
        $bucket = $startMonth + $offset;
        $trendPoints[] = [
            'label' => $monthNames[$bucket] ?? (string) $bucket,
            'amount' => (float) ($trendMap[$bucket] ?? 0),
        ];
    }
    $trendTitle = 'Monthly sales';
    $trendSubtitle = $periodLabel;
} else {
    for ($bucket = 1; $bucket <= 12; $bucket++) {
        $trendPoints[] = [
            'label' => substr($monthNames[$bucket], 0, 3),
            'amount' => (float) ($trendMap[$bucket] ?? 0),
        ];
    }
    $trendTitle = 'Monthly sales';
    $trendSubtitle = (string) $year;
}
$trendMax = 0.0;
foreach ($trendPoints as $point) {
    if ($point['amount'] > $trendMax) {
        $trendMax = $point['amount'];
    }
}

function cp_sort_url(string $key, string $currentKey, string $currentDir): string
{
    $next = 'desc';
    if ($currentKey === $key) {
        $next = $currentDir === 'desc' ? 'asc' : 'desc';
    } elseif ($key === 'customer' || $key === 'rank') {
        $next = 'asc';
    }
    return cp_url(['sort' => $key, 'dir' => $next, 'customer' => null, 'p' => null]);
}

$customerEmail = '';
$priorSales = null;
$detailMonths = [];
$agingBuckets = [
    ['key' => 'current', 'label' => '0-30 days', 'tone' => 'green', 'amount' => 0.0],
    ['key' => 'd30', 'label' => '31-60 days', 'tone' => 'yellow', 'amount' => 0.0],
    ['key' => 'd60', 'label' => '61-90 days', 'tone' => 'orange', 'amount' => 0.0],
    ['key' => 'd90', 'label' => '90+ days', 'tone' => 'red', 'amount' => 0.0],
];
$invQuery = trim((string) ($_GET['inv_q'] ?? ''));
if (strlen($invQuery) > 80) {
    $invQuery = substr($invQuery, 0, 80);
}
$invStatus = strtolower(trim((string) ($_GET['inv_status'] ?? '')));
if (strlen($invStatus) > 40) {
    $invStatus = '';
}
$invFiltered = $detailInvoices;
$invStatuses = [];
if ($selected !== null) {
    if (in_array('email', $customerCols, true)) {
        try {
            $emailSql = 'SELECT c.email FROM customers c WHERE c.id = ?';
            $emailParams = [(int) $selected['customer_id']];
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($emailSql, $emailParams, 'customers', 'c');
            }
            $emailSql .= ' LIMIT 1';
            $emailStmt = $salesDb->prepare($emailSql);
            $emailStmt->execute($emailParams);
            $customerEmail = trim((string) $emailStmt->fetchColumn());
        } catch (Throwable $e) {
            error_log('customer performance email: ' . $e->getMessage());
        }
    }
    if ($prevStart instanceof DateTimeImmutable && $loadError === '' && isset($dateExpr)) {
        try {
            $priorSql = "SELECT COALESCE(SUM(COALESCE(i.total_amount, 0)), 0)
                FROM invoices i
                WHERE i.customer_id = ?
                  AND {$dateExpr} >= ? AND {$dateExpr} < ?";
            $priorParams = [(int) $selected['customer_id'], $prevStart->format('Y-m-d'), $rangeStart->format('Y-m-d')];
            if (in_array('status', $invoiceCols, true)) {
                $priorSql .= " AND LOWER(TRIM(COALESCE(i.status, ''))) NOT IN ('cancelled', 'canceled', 'draft', 'void', 'voided', 'deleted')";
            }
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($priorSql, $priorParams, 'invoices', 'i');
            }
            $priorStmt = $salesDb->prepare($priorSql);
            $priorStmt->execute($priorParams);
            $priorSales = (float) $priorStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('customer performance prior sales: ' . $e->getMessage());
            $priorSales = null;
        }
    }
    $today = new DateTimeImmutable('today');
    foreach ($detailInvoices as $inv) {
        $statusName = strtolower(trim((string) ($inv['status'] ?? '')));
        if ($statusName !== '' && !in_array($statusName, $invStatuses, true)) {
            $invStatuses[] = $statusName;
        }
        $stamp = strtotime((string) ($inv['invoice_day'] ?? ''));
        if ($stamp !== false) {
            if ($periodType === 'monthly') {
                $bucket = (int) date('j', $stamp);
                $bucketLabel = (string) $bucket;
            } else {
                $bucket = (int) date('n', $stamp);
                $bucketLabel = date('M', $stamp);
            }
            if (!isset($detailMonths[$bucket])) {
                $detailMonths[$bucket] = ['label' => $bucketLabel, 'amount' => 0.0];
            }
            $detailMonths[$bucket]['amount'] += (float) ($inv['total_amount'] ?? 0);
        }
        $open = $inv['balance_due'];
        $openAmount = $open === null ? 0.0 : (float) $open;
        if ($openAmount > 0.5) {
            $basis = trim((string) ($inv['due_date'] ?? ''));
            if ($basis === '' || str_starts_with($basis, '0000-00-00')) {
                $basis = (string) ($inv['invoice_day'] ?? '');
            }
            $basisTs = strtotime($basis);
            $ageDays = 0;
            if ($basisTs !== false) {
                $basisDay = (new DateTimeImmutable(date('Y-m-d', $basisTs)))->setTime(0, 0, 0);
                if ($basisDay < $today) {
                    $ageDays = (int) $basisDay->diff($today)->days;
                }
            }
            $ageIndex = $ageDays <= 30 ? 0 : ($ageDays <= 60 ? 1 : ($ageDays <= 90 ? 2 : 3));
            $agingBuckets[$ageIndex]['amount'] += $openAmount;
        }
    }
    ksort($detailMonths);
    if ($invQuery !== '' || $invStatus !== '') {
        $needle = function_exists('mb_strtolower') ? mb_strtolower($invQuery) : strtolower($invQuery);
        $invFiltered = array_values(array_filter($detailInvoices, static function (array $inv) use ($needle, $invStatus): bool {
            if ($invStatus !== '' && strtolower(trim((string) ($inv['status'] ?? ''))) !== $invStatus) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $number = function_exists('mb_strtolower')
                ? mb_strtolower((string) ($inv['invoice_number'] ?? ''))
                : strtolower((string) ($inv['invoice_number'] ?? ''));
            return str_contains($number, $needle);
        }));
    }
}
$invPageSize = 8;
$invPageCount = max(1, (int) ceil(count($invFiltered) / $invPageSize));
$invPage = (int) ($_GET['inv_p'] ?? 1);
if ($invPage < 1) {
    $invPage = 1;
}
if ($invPage > $invPageCount) {
    $invPage = $invPageCount;
}
$invPageRows = array_slice($invFiltered, ($invPage - 1) * $invPageSize, $invPageSize);
$invFrom = count($invFiltered) === 0 ? 0 : (($invPage - 1) * $invPageSize) + 1;
$invTo = min(count($invFiltered), $invPage * $invPageSize);

if ($selected !== null && (string) ($_GET['export'] ?? '') === 'csv') {
    $safeName = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $selected['customer_name']), '-');
    if ($safeName === '') {
        $safeName = 'customer';
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="customer-performance-' . $safeName . '.csv"');
    $csv = fopen('php://output', 'w');
    fputcsv($csv, ['Invoice', 'Date', 'Status', 'Total', 'Paid', 'Outstanding']);
    foreach ($invFiltered as $inv) {
        fputcsv($csv, [
            (string) ($inv['invoice_number'] ?? ''),
            (string) ($inv['invoice_day'] ?? ''),
            (string) ($inv['status'] ?? ''),
            (string) ($inv['total_amount'] ?? ''),
            $inv['amount_paid'] === null ? '' : (string) $inv['amount_paid'],
            $inv['balance_due'] === null ? '' : (string) $inv['balance_due'],
        ]);
    }
    fclose($csv);
    exit;
}

$page_title = 'Customer Performance';
$employeeHeaderTitle = '';
$employeeHeaderSubtitle = '';
$employeeHeaderExtraClass = 'employee-header--exp-desk';

$customerProfileUrl = '';
if ($selected !== null && function_exists('sales_module_url')) {
    $customerProfileUrl = sales_module_url('customers/view.php', [
        'id' => (int) $selected['customer_id'],
        'module' => 'sales',
    ]);
}

$formAction = strtok(function_exists('sales_module_url') ? sales_module_url('customer-performance/index.php') : 'index.php', '?') ?: 'index.php';
$filterQuery = [
    'module' => 'sales',
    'year' => $year,
    'period' => $periodType,
];
if ($periodType === 'monthly') {
    $filterQuery['month'] = $month;
} elseif ($periodType === 'quarterly') {
    $filterQuery['quarter'] = $quarter;
}
if ($sortKey !== 'sales' || $sortDir !== 'desc') {
    $filterQuery['sort'] = $sortKey;
    $filterQuery['dir'] = $sortDir;
}
$searchQuery = $filterQuery;
if ($search !== '') {
    $searchQuery['q'] = $search;
}
$periodFieldLabel = $periodType === 'quarterly' ? 'Quarter' : 'Month';
$resultCount = count($displayRows);
$rangeFrom = $resultCount === 0 ? 0 : (($page - 1) * $pageSize) + 1;
$rangeTo = min($resultCount, $page * $pageSize);
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
.cp-page { padding: 0.35rem 1.25rem 2.4rem; max-width: 1240px; color: #0f172a; }
html body main.cp-page { padding-left: 52px !important; padding-right: 52px !important; }
.cp-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1.25rem; margin-bottom: 1.15rem; }
.cp-title { display: flex; gap: 0.85rem; align-items: flex-start; min-width: 0; }
.cp-title-mark { width: 46px; height: 46px; border-radius: 50%; background: #e8eefc; color: #2563eb; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
.cp-title-mark svg { width: 24px; height: 24px; }
.cp-title h1 { margin: 0; font-size: 1.55rem; font-weight: 780; letter-spacing: -0.03em; color: #111827; }
.cp-title p { margin: 0.15rem 0 0.45rem; color: #64748b; font-size: 0.92rem; }
.cp-chip { display: inline-flex; align-items: center; gap: 0.35rem; color: #475569; font-size: 0.82rem; font-weight: 650; }
.cp-chip svg { width: 14px; height: 14px; }
.cp-filters { display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: flex-end; background: #fff; border: 1px solid #e7edf3; border-radius: 16px; padding: 0.7rem 0.8rem; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04); }
.cp-field { display: flex; flex-direction: column; gap: 0.28rem; min-width: 8.6rem; }
.cp-field label { font-size: 0.72rem; font-weight: 650; color: #64748b; }
html body main.cp-page .cp-field select { height: 2.45rem; border: 1px solid #d7dee7; border-radius: 12px !important; background-color: #fff; color: #0f172a; padding: 0 2rem 0 0.85rem; font-weight: 650; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='none' stroke='%2364748b' stroke-width='1.6' stroke-linecap='round' d='M1 1.5 6 6.5 11 1.5'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.75rem center; }
.cp-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 1.15rem; }
.cp-kpi { border-radius: 16px; padding: 16px 16px 14px; min-width: 0; border: 1px solid transparent; }
.cp-kpi--customers { background: #eef3ff; }
.cp-kpi--active { background: #e8f8ee; }
.cp-kpi--sales { background: #f3f0ff; }
.cp-kpi--average { background: #e7f8ef; }
.cp-kpi-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.cp-kpi-label { color: #334155; font-size: 0.92rem; font-weight: 650; }
.cp-kpi-icon { width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.72); }
.cp-kpi-icon svg { width: 18px; height: 18px; }
.cp-kpi--customers .cp-kpi-icon { color: #2563eb; }
.cp-kpi--active .cp-kpi-icon, .cp-kpi--average .cp-kpi-icon { color: #16a34a; }
.cp-kpi--sales .cp-kpi-icon { color: #7c3aed; }
.cp-kpi strong { display: block; margin: 12px 0 12px; font-size: 1.7rem; font-weight: 780; letter-spacing: -0.03em; color: #111827; line-height: 1; }
.cp-kpi-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 0.78rem; color: #64748b; font-weight: 550; }
.cp-kpi-up { color: #16a34a; font-weight: 700; white-space: nowrap; }
.cp-kpi-down { color: #dc2626; font-weight: 700; white-space: nowrap; }
.cp-kpi-flat { color: #94a3b8; font-weight: 700; white-space: nowrap; }
.cp-hero { position: relative; overflow: hidden; isolation: isolate; display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(260px, 0.8fr); gap: 1rem; align-items: center; background: linear-gradient(145deg, rgba(16, 28, 52, 0.92), rgba(12, 20, 38, 0.88)); backdrop-filter: blur(8px) saturate(120%); -webkit-backdrop-filter: blur(8px) saturate(120%); color: #f8fafc; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 18px; padding: 1.25rem 1.35rem; margin-bottom: 1.15rem; text-decoration: none; box-shadow: 0 16px 36px rgba(15, 23, 42, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.1); }
.cp-hero::before { content: ""; position: absolute; inset: -20%; background: radial-gradient(circle at 16% 40%, rgba(245, 196, 81, 0.12), transparent 36%), radial-gradient(circle at 84% 72%, rgba(96, 165, 250, 0.1), transparent 40%); pointer-events: none; z-index: 0; }
.cp-hero:hover { color: #fff; }
.cp-hero-main { position: relative; z-index: 1; display: flex; gap: 1rem; align-items: center; min-width: 0; }
.cp-cup { width: 92px; height: 92px; flex: 0 0 auto; }
.cp-badge { display: inline-flex; align-items: center; height: 22px; padding: 0 0.55rem; border-radius: 999px; background: #f5c451; color: #3f2d04; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
.cp-hero-rank { margin-top: 0.45rem; color: #f5c451; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.08em; }
.cp-hero h2 { margin: 0.45rem 0 0.15rem; font-size: 1.15rem; font-weight: 780; letter-spacing: 0.01em; }
.cp-hero-amount { margin: 0; color: #f5c451; font-size: 1.85rem; font-weight: 800; letter-spacing: -0.03em; }
.cp-hero-pill { display: inline-flex; margin-top: 0.55rem; padding: 0.28rem 0.65rem; border-radius: 999px; background: rgba(255,255,255,0.08); color: #e2e8f0; font-size: 0.78rem; font-weight: 650; }
.cp-hero-stats { position: relative; z-index: 1; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 14px; padding: 0.35rem 0.85rem; }
.cp-hero-stats div { display: flex; justify-content: space-between; gap: 1rem; padding: 0.42rem 0; border-bottom: 1px solid rgba(255,255,255,0.06); font-size: 0.84rem; }
.cp-hero-stats div:last-child { border-bottom: 0; }
.cp-hero-stats span { color: #cbd5e1; display: inline-flex; align-items: center; gap: 0.4rem; }
.cp-hero-stats strong { font-weight: 700; color: #fff; }
.cp-rank-card, .cp-panel { background: #fff; border: 1px solid #e7edf3; border-radius: 16px; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04); }
.cp-rank-head, .cp-panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; padding: 0.95rem 1rem 0.75rem; }
.cp-rank-head h2, .cp-panel-head h2 { margin: 0; font-size: 1.05rem; font-weight: 760; }
.cp-rank-head p, .cp-panel-head p { margin: 0.15rem 0 0; color: #64748b; font-size: 0.82rem; }
.cp-tools { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 0.5rem; }
.cp-search { display: flex; align-items: center; gap: 0.35rem; }
.cp-search input { height: 2.25rem; border: 1px solid #e2e8f0; border-radius: 999px; padding: 0 0.8rem; min-width: 180px; font-weight: 550; }
.cp-search button, .cp-clear { height: 2.25rem; border-radius: 999px; border: 1px solid #e2e8f0; background: #fff; color: #334155; font-weight: 700; padding: 0 0.75rem; text-decoration: none; display: inline-flex; align-items: center; }
.cp-switch { display: inline-flex; background: #f1f5f9; border-radius: 999px; padding: 3px; }
.cp-switch a { text-decoration: none; color: #475569; font-weight: 700; font-size: 0.8rem; padding: 0.35rem 0.75rem; border-radius: 999px; }
.cp-switch a.is-on { background: #2563eb; color: #fff; }
.cp-table-wrap { overflow-x: auto; }
.cp-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 760px; }
.cp-table thead th { background: #1e293b; color: #fff; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; text-align: left; padding: 0.72rem 0.8rem; white-space: nowrap; }
.cp-table thead th:first-child { border-radius: 0; }
.cp-table thead th a { color: inherit; text-decoration: none; }
.cp-table thead th a:hover { color: #f8fafc; }
.cp-table tbody td { padding: 0.72rem 0.8rem; border-bottom: 1px solid #eef2f6; font-size: 0.9rem; vertical-align: middle; }
.cp-table tbody tr:nth-child(even) td { background: #f8fafc; }
.cp-table tbody tr.cp-row { cursor: pointer; }
.cp-table tbody tr.cp-row:hover td { background: #eef4ff; }
.cp-col-sn { width: 52px; color: #64748b; font-weight: 700; }
.cp-num { text-align: right; }
.cp-table thead th.cp-num { text-align: right; }
.cp-customer { display: inline-flex; align-items: center; gap: 0.45rem; color: #0f172a; font-weight: 700; text-decoration: none; }
.cp-flag { width: 18px; height: 13px; object-fit: cover; border-radius: 2px; box-shadow: 0 0 0 1px rgba(15,23,42,0.08); }
.cp-amt { font-variant-numeric: tabular-nums; font-weight: 650; }
.cp-go { width: 28px; color: #94a3b8; text-align: right; }
.cp-pager { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem 0.95rem; color: #64748b; font-size: 0.82rem; }
.cp-pages { display: flex; gap: 0.3rem; flex-wrap: wrap; }
.cp-pages a, .cp-pages span { min-width: 2rem; height: 2rem; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; color: #334155; border: 1px solid #e2e8f0; background: #fff; font-weight: 700; }
.cp-pages span { background: #1e293b; color: #fff; border-color: #1e293b; }
.cp-analytics { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.85fr); gap: 14px; margin-top: 14px; }
.cp-bars { display: flex; align-items: flex-end; gap: 4px; height: 190px; padding: 0.4rem 1rem 0.9rem; }
.cp-bar { flex: 1 1 0; min-width: 0; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 0.3rem; }
.cp-bar-fill { width: 100%; max-width: 22px; border-radius: 6px 6px 2px 2px; background: #2563eb; min-height: 0; }
.cp-bar-fill.is-zero { background: #e2e8f0; height: 3px !important; }
.cp-bar span { font-size: 0.62rem; color: #64748b; line-height: 1; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-share { padding: 0 1rem 0.85rem; }
.cp-share-row { margin-bottom: 0.75rem; }
.cp-share-top { display: flex; justify-content: space-between; gap: 0.75rem; font-size: 0.84rem; margin-bottom: 0.28rem; }
.cp-share-top span { font-weight: 650; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-share-top strong { font-weight: 750; white-space: nowrap; }
.cp-share-track { height: 8px; border-radius: 999px; background: #eef2f6; overflow: hidden; }
.cp-share-track i { display: block; height: 100%; border-radius: inherit; background: #2563eb; }
.cp-share-pct { margin-top: 0.18rem; color: #64748b; font-size: 0.75rem; font-weight: 650; }
.cp-note { margin: 0.85rem 0 0; color: #94a3b8; font-size: 0.78rem; }
.cp-empty { background: #fff; border: 1px dashed #cbd5e1; border-radius: 16px; padding: 2rem 1rem; text-align: center; color: #475569; }
.cp-empty strong { display: block; color: #0f172a; font-size: 1.02rem; margin-bottom: 0.3rem; }
.cp-back { display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 700; color: #334155; text-decoration: none; margin-bottom: 0.85rem; }
.cp-crumb { margin: 0 0 0.85rem; color: #64748b; font-size: 0.82rem; }
.cp-crumb a { color: inherit; text-decoration: none; }
.cp-crumb a:hover { color: #0f172a; }
.cp-cust-head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; margin-bottom: 0.9rem; }
.cp-cust-id { display: flex; gap: 0.75rem; align-items: center; min-width: 0; }
.cp-avatar { width: 46px; height: 46px; border-radius: 50%; background: #2563eb; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; letter-spacing: 0.02em; flex: 0 0 auto; }
.cp-cust-id h2 { margin: 0; font-size: 1.45rem; font-weight: 780; }
.cp-cust-id p { margin: 0.15rem 0 0; color: #64748b; font-size: 0.84rem; }
.cp-cust-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.cp-ghost { display: inline-flex; align-items: center; height: 2.3rem; padding: 0 0.85rem; border: 1px solid #d7dee7; border-radius: 12px !important; background: #fff; color: #0f172a; text-decoration: none; font-weight: 700; font-size: 0.86rem; }
.cp-alert { display: flex; justify-content: space-between; gap: 1rem; align-items: center; background: #fdecec; border: 1px solid #f3c1c1; color: #7f1d1d; border-radius: 14px; padding: 0.85rem 1rem; margin-bottom: 0.9rem; }
.cp-alert p { margin: 0; font-weight: 650; }
.cp-alert-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.cp-alert .cp-ghost { background: transparent; border-color: #e7a3a3; color: #7f1d1d; }
.cp-cust-kpis { display: grid; grid-template-columns: 1.1fr 1.3fr 0.8fr; gap: 12px; margin-bottom: 0.9rem; }
.cp-cust-kpis article { background: #fff; border: 1px solid #e7edf3; border-radius: 14px; padding: 0.9rem 1rem; }
.cp-cust-kpis span { display: block; color: #64748b; font-size: 0.82rem; font-weight: 650; }
.cp-cust-kpis strong { display: block; margin-top: 0.35rem; font-size: 1.55rem; letter-spacing: -0.03em; }
.cp-cust-kpis em { display: block; margin-top: 0.25rem; color: #94a3b8; font-style: normal; font-size: 0.78rem; }
.cp-rate { color: #dc2626; }
.cp-rate-track, .cp-age-track { height: 6px; border-radius: 999px; background: #e8edf3; overflow: hidden; margin-top: 0.7rem; }
.cp-rate-track i { display: block; height: 100%; background: #dc2626; border-radius: inherit; }
.cp-split { display: flex; align-items: stretch; gap: 12px; margin-bottom: 0.9rem; }
.cp-mchart-card { flex: 0 1 auto; width: fit-content; max-width: 100%; }
.cp-age-card { flex: 0 1 auto; width: fit-content; max-width: 100%; }
.cp-split article { background: #fff; border: 1px solid #e7edf3; border-radius: 14px; padding: 0.95rem 1rem 0.8rem; }
.cp-split h3 { margin: 0 0 0.8rem; font-size: 0.95rem; font-weight: 750; }
.cp-mchart { display: flex; align-items: flex-end; justify-content: flex-start; gap: 0.85rem; min-height: 180px; padding-top: 0.4rem; overflow-x: auto; }
.cp-mbar { flex: 0 0 58px; width: 58px; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 170px; gap: 0.35rem; }
.cp-mbar b { font-size: 0.75rem; font-weight: 700; color: #334155; }
.cp-mbar i { display: block; width: 46px; max-width: 46px; border-radius: 6px 6px 2px 2px; background: #3b82f6; }
.cp-mbar em { font-style: normal; font-size: 0.75rem; color: #64748b; }
.cp-age-row { display: grid; grid-template-columns: 92px 160px auto; gap: 0.65rem; align-items: center; margin-bottom: 0.7rem; font-size: 0.84rem; }
.cp-age-track { margin-top: 0; }
.cp-age-track i { display: block; height: 100%; border-radius: inherit; }
.cp-age-track .green { background: #22c55e; }
.cp-age-track .yellow { background: #eab308; }
.cp-age-track .orange { background: #f59e0b; }
.cp-age-track .red { background: #ef4444; }
.cp-inv-tools { display: flex; gap: 0.55rem; align-items: center; flex-wrap: wrap; padding: 0.85rem 1rem 0.35rem; }
.cp-inv-tools input, .cp-inv-tools select { height: 2.35rem; border: 1px solid #d7dee7; border-radius: 12px !important; background: #fff; padding: 0 0.75rem; }
.cp-inv-tools input { flex: 1 1 180px; }
.cp-inv-link { color: #2563eb; font-weight: 700; text-decoration: none; }
.cp-inv-foot { display: flex; justify-content: space-between; gap: 0.75rem; align-items: center; padding: 0.75rem 1rem 0.95rem; color: #64748b; font-size: 0.82rem; }
.cp-detail-head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; margin-bottom: 1rem; }
.cp-detail-head h2 { margin: 0; font-size: 1.35rem; font-weight: 780; display: flex; align-items: center; gap: 0.45rem; }
.cp-profile { font-weight: 700; color: #1d4ed8; text-decoration: none; white-space: nowrap; }
.cp-detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.cp-detail-grid article { background: #fff; border: 1px solid #e8edf3; border-radius: 14px; padding: 0.9rem 1rem; }
.cp-detail-grid span { display: block; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; }
.cp-detail-grid strong { display: block; margin-top: 0.3rem; font-size: 1.15rem; }
.cp-status { display: inline-flex; padding: 0.15rem 0.45rem; border-radius: 999px; background: #e2e8f0; color: #334155; font-size: 0.75rem; font-weight: 700; text-transform: capitalize; }
.cp-status--paid { background: #dcfce7; color: #166534; }
.cp-status--sent, .cp-status--partial { background: #fff4de; color: #b45309; }
html[data-theme="dark"] .cp-page,
html[data-theme="dark"] .cp-title h1,
html[data-theme="dark"] .cp-kpi strong,
html[data-theme="dark"] .cp-rank-head h2,
html[data-theme="dark"] .cp-panel-head h2,
html[data-theme="dark"] .cp-customer,
html[data-theme="dark"] .cp-empty strong,
html[data-theme="dark"] .cp-detail-head h2,
html[data-theme="dark"] .cp-detail-grid strong { color: #f8fafc; }
html[data-theme="dark"] .cp-filters,
html[data-theme="dark"] .cp-rank-card,
html[data-theme="dark"] .cp-panel,
html[data-theme="dark"] .cp-search input,
html[data-theme="dark"] .cp-search button,
html[data-theme="dark"] .cp-clear,
html[data-theme="dark"] .cp-detail-grid article,
html[data-theme="dark"] .cp-empty,
html[data-theme="dark"] .cp-cust-kpis article,
html[data-theme="dark"] .cp-split article,
html[data-theme="dark"] .cp-ghost,
html[data-theme="dark"] .cp-inv-tools input,
html[data-theme="dark"] .cp-inv-tools select { background: #1e293b; color: #f8fafc; border-color: #334155; }
html[data-theme="dark"] .cp-alert { background: #3f1d24; border-color: #7f1d1d; color: #fecaca; }
html[data-theme="dark"] .cp-alert .cp-ghost { background: transparent; border-color: #9f4a4a; color: #fecaca; }
html[data-theme="dark"] .cp-cust-id h2,
html[data-theme="dark"] .cp-split h3,
html[data-theme="dark"] .cp-cust-kpis strong,
html[data-theme="dark"] .cp-mbar b { color: #f8fafc; }
html[data-theme="dark"] .cp-rate-track,
html[data-theme="dark"] .cp-age-track { background: #334155; }
html[data-theme="dark"] .cp-field select { background-color: #1e293b; color: #f8fafc; border-color: #334155; }
html[data-theme="dark"] .cp-kpi--customers { background: #1c2a44; }
html[data-theme="dark"] .cp-kpi--active, html[data-theme="dark"] .cp-kpi--average { background: #163226; }
html[data-theme="dark"] .cp-kpi--sales { background: #2a2144; }
html[data-theme="dark"] .cp-table thead th { background: #0f172a; }
html[data-theme="dark"] .cp-table tbody td,
html[data-theme="dark"] .cp-table tbody tr:nth-child(even) td { background: #1e293b; color: #e2e8f0; border-color: #334155; }
html[data-theme="dark"] .cp-table tbody tr.cp-row:hover td { background: #334155; }
@media (max-width: 1100px) {
    .cp-kpis, .cp-analytics, .cp-detail-grid, .cp-cust-kpis, .cp-split { grid-template-columns: 1fr 1fr; }
    .cp-hero { grid-template-columns: 1fr; }
}
@media (max-width: 780px) {
    .cp-top { flex-direction: column; }
    .cp-filters, .cp-tools { width: 100%; }
    .cp-search input { min-width: 0; flex: 1; }
}
@media (max-width: 640px) {
    .cp-page { padding-left: 0.75rem; padding-right: 0.75rem; }
    .cp-kpis, .cp-analytics, .cp-detail-grid, .cp-cust-kpis { grid-template-columns: 1fr; }
    .cp-split, .cp-detail-head, .cp-cust-head, .cp-alert, .cp-inv-foot { flex-direction: column; align-items: stretch; }
    .cp-mchart-card, .cp-age-card { width: 100%; }
    .cp-hero-main { align-items: flex-start; }
    .cp-cup { width: 64px; height: 64px; }
    .cp-pager { flex-direction: column; align-items: flex-start; }
}
</style>
<main class="cp-page">
<?php if ($selected === null): ?>
    <div class="cp-top">
        <div class="cp-title">
            <div class="cp-title-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M8 4h8v3a4 4 0 0 1-8 0V4z"/><path d="M8 6H5.5A2.5 2.5 0 0 0 8 10.5"/><path d="M16 6h2.5A2.5 2.5 0 0 1 16 10.5"/><path d="M12 13v3"/><path d="M9 20h6"/><path d="M10 16h4v2a2 2 0 0 1-4 0v-2z"/></svg>
            </div>
            <div>
                <h1>Customer Performance</h1>
                <p>Top customers based on total sales value</p>
                <div class="cp-chip">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
                    <?= cp_h($periodLabel) ?>
                </div>
            </div>
        </div>
        <form class="cp-filters" method="get" action="<?= cp_h($formAction) ?>">
            <input type="hidden" name="module" value="sales">
            <?php if ($sortKey !== 'sales' || $sortDir !== 'desc'): ?>
                <input type="hidden" name="sort" value="<?= cp_h($sortKey) ?>">
                <input type="hidden" name="dir" value="<?= cp_h($sortDir) ?>">
            <?php endif; ?>
            <?php if ($search !== ''): ?>
                <input type="hidden" name="q" value="<?= cp_h($search) ?>">
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
                    <option value="yearly" <?= $periodType === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                </select>
            </div>
            <?php if ($periodType === 'monthly'): ?>
                <div class="cp-field">
                    <label for="cp-month"><?= cp_h($periodFieldLabel) ?></label>
                    <select id="cp-month" name="month" onchange="this.form.submit()">
                        <?php foreach ($monthNames as $m => $label): ?>
                            <option value="<?= (int) $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= cp_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($periodType === 'quarterly'): ?>
                <div class="cp-field">
                    <label for="cp-quarter"><?= cp_h($periodFieldLabel) ?></label>
                    <select id="cp-quarter" name="quarter" onchange="this.form.submit()">
                        <?php for ($q = 1; $q <= 4; $q++): ?>
                            <option value="<?= $q ?>" <?= $q === $quarter ? 'selected' : '' ?>>Q<?= $q ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <section class="cp-kpis" aria-label="Period summary">
        <?php
        $prevAverage = ($prevActive !== null && $prevActive > 0 && $prevSales !== null) ? ($prevSales / $prevActive) : ($prevSales !== null ? 0.0 : null);
        $summaryCards = [
            [
                'tone' => 'customers',
                'label' => 'Total customers',
                'value' => $totalCustomers === null ? '-' : number_format($totalCustomers),
                'change' => ($totalCustomers !== null && $newCustomers !== null)
                    ? cp_kpi_change((float) $totalCustomers, (float) $totalCustomers - (float) $newCustomers, 'count')
                    : '',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 19v-1a3 3 0 0 0-3-3H7a3 3 0 0 0-3 3v1"/><circle cx="10" cy="8" r="3"/><path d="M20 19v-1a3 3 0 0 0-2.2-2.9"/><path d="M16 5.1a3 3 0 0 1 0 5.8"/></svg>',
            ],
            [
                'tone' => 'active',
                'label' => 'Active customers',
                'value' => number_format($activeCustomers),
                'change' => $prevActive === null ? '' : cp_kpi_change((float) $activeCustomers, (float) $prevActive, 'count'),
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3"/><path d="M6 19v-1a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v1"/></svg>',
            ],
            [
                'tone' => 'sales',
                'label' => 'Total sales',
                'value' => cp_money($totalSales, $currency, true),
                'change' => $prevSales === null ? '' : cp_kpi_change($totalSales, (float) $prevSales, 'money', $currency),
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15l3-3 2 2 5-6"/></svg>',
            ],
            [
                'tone' => 'average',
                'label' => 'Average customer sales',
                'value' => cp_money($averageSales, $currency, true),
                'change' => $prevAverage === null ? '' : cp_kpi_change($averageSales, (float) $prevAverage, 'money', $currency),
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 4h8v3a4 4 0 0 1-8 0V4z"/><path d="M12 13v3M9 20h6"/></svg>',
            ],
        ];
        foreach ($summaryCards as $card):
        ?>
        <article class="cp-kpi cp-kpi--<?= cp_h($card['tone']) ?>">
            <div class="cp-kpi-top">
                <span class="cp-kpi-label"><?= cp_h($card['label']) ?></span>
                <span class="cp-kpi-icon" aria-hidden="true"><?= $card['icon'] ?></span>
            </div>
            <strong><?= cp_h($card['value']) ?></strong>
            <div class="cp-kpi-foot">
                <span><?= cp_h($compareLabel) ?></span>
                <?= $card['change'] ?>
            </div>
        </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

    <?php if ($loadError !== ''): ?>
        <div class="cp-empty"><strong>Customer performance is unavailable</strong><?= cp_h($loadError) ?></div>
    <?php elseif ($customerId > 0 && $selected === null): ?>
        <a class="cp-back" href="<?= cp_h(cp_url(['customer' => null])) ?>">Back to ranking</a>
        <div class="cp-empty"><strong>No customer sales recorded for this period.</strong>This customer has no eligible sales invoices in <?= cp_h($periodLabel) ?>.</div>
    <?php elseif ($selected !== null): ?>
        <?php
            $custName = (string) $selected['customer_name'];
            $nameParts = preg_split('/\s+/', trim($custName)) ?: [];
            $initials = '';
            foreach ($nameParts as $namePart) {
                if ($namePart === '') {
                    continue;
                }
                $initials .= strtoupper(substr($namePart, 0, 1));
                if (strlen($initials) >= 2) {
                    break;
                }
            }
            if ($initials === '') {
                $initials = 'C';
            }
            $invoiceCount = (int) $selected['invoice_count'];
            $salesAmount = (float) $selected['total_sales'];
            $paidAmount = $selected['paid_amount'] === null ? 0.0 : (float) $selected['paid_amount'];
            $openAmount = $selected['outstanding_amount'] === null ? 0.0 : (float) $selected['outstanding_amount'];
            $avgInvoice = $invoiceCount > 0 ? $salesAmount / $invoiceCount : 0.0;
            $collection = $salesAmount > 0 ? ($paidAmount / $salesAmount) * 100 : 0.0;
            $share = $totalSales > 0 ? ($salesAmount / $totalSales) * 100 : 0.0;
            $rankOf = $totalCustomers ?? count($rows);
            $periodChip = $periodType === 'yearly'
                ? $year . ' ù Yearly'
                : ($periodType === 'quarterly' ? 'Q' . $quarter . ' ' . $year . ' ù Quarterly' : $periodLabel);
            $priorNoun = $periodType === 'yearly' ? 'year' : ($periodType === 'quarterly' ? 'quarter' : 'month');
            $chartMax = 0.0;
            foreach ($detailMonths as $monthBar) {
                if ($monthBar['amount'] > $chartMax) {
                    $chartMax = $monthBar['amount'];
                }
            }
            $chartDiv = $chartMax >= 1000000 ? 1000000 : ($chartMax >= 1000 ? 1000 : 1);
            $chartUnit = $chartDiv === 1000000 ? 'M' : ($chartDiv === 1000 ? 'K' : '');
            $chartHeading = ($periodType === 'monthly' ? 'Daily sales' : 'Monthly sales') . ($chartUnit !== '' ? ' (TZS ' . $chartUnit . ')' : '');
            $ageMax = 0.0;
            foreach ($agingBuckets as $bucket) {
                if ($bucket['amount'] > $ageMax) {
                    $ageMax = $bucket['amount'];
                }
            }
            $customersUrl = function_exists('sales_module_url')
                ? sales_module_url('customers/index.php', ['module' => 'sales'])
                : cp_url(['customer' => null]);
            $paymentUrl = '';
            $largestOpen = -1.0;
            foreach ($detailInvoices as $inv) {
                $invOpen = $inv['balance_due'] === null ? 0.0 : (float) $inv['balance_due'];
                if ($invOpen > $largestOpen && function_exists('sales_module_url')) {
                    $largestOpen = $invOpen;
                    $paymentUrl = sales_module_url('invoices/view.php', ['id' => (int) ($inv['id'] ?? 0), 'module' => 'sales']);
                }
            }
            $reminderLines = [];
            foreach ($detailInvoices as $inv) {
                $invOpen = $inv['balance_due'] === null ? 0.0 : (float) $inv['balance_due'];
                if ($invOpen <= 0.5) {
                    continue;
                }
                $reminderLines[] = (string) ($inv['invoice_number'] ?? '') . '  ' . cp_money($invOpen, $currency) . ' outstanding';
                if (count($reminderLines) >= 12) {
                    break;
                }
            }
            $reminderBody = $custName . ' has ' . cp_money($openAmount, $currency) . " outstanding for {$periodLabel}.";
            if ($reminderLines !== []) {
                $reminderBody .= "\n\n" . implode("\n", $reminderLines);
            }
            $reminderHref = $customerProfileUrl;
            if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                $reminderHref = 'mailto:' . $customerEmail
                    . '?subject=' . rawurlencode('Payment reminder: ' . $custName)
                    . '&body=' . rawurlencode($reminderBody);
            }
        ?>
        <nav class="cp-crumb" aria-label="Breadcrumb">
            <a href="<?= cp_h($customersUrl) ?>">Customers</a>
            <span> / </span>
            <a href="<?= cp_h(cp_url(['customer' => null])) ?>">Ranking</a>
            <span> / </span>
            <span><?= cp_h($custName) ?></span>
        </nav>
        <div class="cp-cust-head">
            <div class="cp-cust-id">
                <span class="cp-avatar"><?= cp_h($initials) ?></span>
                <div>
                    <h2><?= cp_h($custName) ?></h2>
                    <p>Rank <?= (int) $selected['rank'] ?> of <?= number_format((int) $rankOf) ?> ù first purchase <?= cp_h(cp_date_label((string) ($selected['first_purchase'] ?? ''))) ?> ù last <?= cp_h(cp_date_label((string) ($selected['last_purchase'] ?? ''))) ?></p>
                </div>
            </div>
            <div class="cp-cust-actions">
                <span class="cp-ghost"><?= cp_h($periodChip) ?></span>
                <a class="cp-ghost" href="<?= cp_h(cp_url(['export' => 'csv', 'inv_p' => null])) ?>">Export</a>
            </div>
        </div>
        <?php if ($openAmount > 0.5): ?>
            <div class="cp-alert">
                <p>
                    <?= cp_h(cp_money($openAmount, $currency, true)) ?> outstanding<?= $paidAmount < 0.5 ? ' and no payments received' : '' ?>.
                    <?php if ($share >= 0.05): ?>This customer is <?= cp_h(number_format($share, 1)) ?>% of company sales.<?php endif; ?>
                </p>
                <div class="cp-alert-actions">
                    <?php if ($reminderHref !== ''): ?><a class="cp-ghost" href="<?= cp_h($reminderHref) ?>">Send reminder</a><?php endif; ?>
                    <?php if ($paymentUrl !== ''): ?><a class="cp-ghost" href="<?= cp_h($paymentUrl) ?>">Record payment</a><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <section class="cp-cust-kpis">
            <article>
                <span>Total sales</span>
                <strong><?= cp_h(cp_money($salesAmount, $currency, true)) ?></strong>
                <em><?php if ($priorSales === null || $priorSales < 0.5): ?>No prior-<?= cp_h($priorNoun) ?> data<?php else: ?><?= cp_kpi_change($salesAmount, $priorSales, 'money', $currency) ?><?php endif; ?></em>
            </article>
            <article>
                <span>Collection rate</span>
                <strong class="<?= $collection < 50 ? 'cp-rate' : '' ?>"><?= cp_h(number_format($collection, $collection >= 10 || $collection < 0.05 ? 0 : 1)) ?>%</strong>
                <div class="cp-rate-track"><i style="width: <?= number_format(min(100, max($collection, $collection > 0 ? 2 : 0)), 2, '.', '') ?>%"></i></div>
            </article>
            <article>
                <span>Invoices</span>
                <strong><?= number_format($invoiceCount) ?></strong>
                <em>Avg <?= cp_h(cp_money($avgInvoice, $currency, true)) ?></em>
            </article>
        </section>
        <section class="cp-split">
            <article class="cp-mchart-card">
                <h3><?= cp_h($chartHeading) ?></h3>
                <?php if ($detailMonths === []): ?>
                    <div class="cp-empty"><strong>No customer sales recorded for this period.</strong></div>
                <?php else: ?>
                    <div class="cp-mchart">
                        <?php foreach ($detailMonths as $monthBar): ?>
                            <?php
                                $barPct = $chartMax > 0 ? max(8, ($monthBar['amount'] / $chartMax) * 100) : 0;
                                $barLabel = $chartDiv > 1
                                    ? number_format($monthBar['amount'] / $chartDiv, 1)
                                    : number_format($monthBar['amount'], 0);
                            ?>
                            <div class="cp-mbar" title="<?= cp_h($monthBar['label'] . ': ' . cp_money((float) $monthBar['amount'], $currency)) ?>">
                                <b><?= cp_h($barLabel) ?></b>
                                <i style="height: <?= number_format($barPct, 2, '.', '') ?>%"></i>
                                <em><?= cp_h($monthBar['label']) ?></em>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
            <article class="cp-age-card">
                <h3>Receivables aging<?= $chartUnit !== '' ? ' (TZS ' . cp_h($chartUnit) . ')' : '' ?></h3>
                <?php foreach ($agingBuckets as $bucket): ?>
                    <?php
                        $agePct = $ageMax > 0 ? ($bucket['amount'] / $ageMax) * 100 : 0;
                        $ageLabel = $chartDiv > 1
                            ? number_format($bucket['amount'] / $chartDiv, 1)
                            : number_format($bucket['amount'], 0);
                    ?>
                    <div class="cp-age-row">
                        <span><?= cp_h($bucket['label']) ?></span>
                        <div class="cp-age-track"><i class="<?= cp_h($bucket['tone']) ?>" style="width: <?= number_format(max($agePct, $bucket['amount'] > 0 ? 4 : 0), 2, '.', '') ?>%"></i></div>
                        <strong><?= cp_h($ageLabel) ?></strong>
                    </div>
                <?php endforeach; ?>
            </article>
        </section>
        <section class="cp-rank-card">
            <form class="cp-inv-tools" method="get" action="<?= cp_h($formAction) ?>">
                <?php foreach ($_GET as $queryKey => $queryValue): ?>
                    <?php if (in_array((string) $queryKey, ['inv_q', 'inv_p', 'export'], true) || is_array($queryValue)) { continue; } ?>
                    <input type="hidden" name="<?= cp_h((string) $queryKey) ?>" value="<?= cp_h((string) $queryValue) ?>">
                <?php endforeach; ?>
                <input type="search" name="inv_q" value="<?= cp_h($invQuery) ?>" placeholder="Search invoices" maxlength="80" aria-label="Search invoices">
                <select name="inv_status" aria-label="Invoice status" onchange="this.form.submit()">
                    <option value="">Status: all</option>
                    <?php foreach ($invStatuses as $statusOption): ?>
                        <option value="<?= cp_h($statusOption) ?>" <?= $invStatus === $statusOption ? 'selected' : '' ?>><?= cp_h(ucfirst($statusOption)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($reminderHref !== '' && $openAmount > 0.5): ?><a class="cp-ghost" href="<?= cp_h($reminderHref) ?>">Send reminders</a><?php endif; ?>
            </form>
            <?php if ($invPageRows === []): ?>
                <div class="cp-empty"><strong><?= $invQuery !== '' || $invStatus !== '' ? 'No invoices match that search.' : 'No customer sales recorded for this period.' ?></strong></div>
            <?php else: ?>
                <div class="cp-table-wrap">
                    <table class="cp-table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="cp-num">Total</th>
                                <th class="cp-num">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invPageRows as $inv): ?>
                            <?php
                                $invId = (int) ($inv['id'] ?? 0);
                                $invUrl = function_exists('sales_module_url')
                                    ? sales_module_url('invoices/view.php', ['id' => $invId, 'module' => 'sales'])
                                    : '';
                                $dueVal = $inv['balance_due'];
                                $rowStatus = strtolower(trim((string) ($inv['status'] ?? '')));
                                $plainTotal = abs((float) ($inv['total_amount'] ?? 0) - round((float) ($inv['total_amount'] ?? 0))) < 0.005
                                    ? number_format((float) ($inv['total_amount'] ?? 0), 0)
                                    : number_format((float) ($inv['total_amount'] ?? 0), 2);
                                $plainDue = $dueVal === null
                                    ? '-'
                                    : (abs((float) $dueVal - round((float) $dueVal)) < 0.005 ? number_format((float) $dueVal, 0) : number_format((float) $dueVal, 2));
                            ?>
                            <tr class="cp-row"<?= $invUrl !== '' ? ' data-href="' . cp_h($invUrl) . '"' : '' ?>>
                                <td><?php if ($invUrl !== ''): ?><a class="cp-inv-link" href="<?= cp_h($invUrl) ?>"><?= cp_h((string) ($inv['invoice_number'] ?? ('INV-' . $invId))) ?></a><?php else: ?><?= cp_h((string) ($inv['invoice_number'] ?? '')) ?><?php endif; ?></td>
                                <td><?= cp_h(cp_date_label((string) ($inv['invoice_day'] ?? ''))) ?></td>
                                <td><span class="cp-status<?= $rowStatus !== '' ? ' cp-status--' . cp_h(preg_replace('/[^a-z]/', '', $rowStatus) ?? '') : '' ?>"><?= cp_h((string) ($inv['status'] ?? '')) ?></span></td>
                                <td class="cp-num"><span class="cp-amt"><?= cp_h($plainTotal) ?></span></td>
                                <td class="cp-num"><span class="cp-amt"><?= cp_h($plainDue) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="cp-inv-foot">
                    <span>Showing <?= number_format($invFrom) ?>-<?= number_format($invTo) ?> of <?= number_format(count($invFiltered)) ?></span>
                    <span>
                        Total outstanding <?= cp_h(cp_money($openAmount, $currency)) ?>
                        <?php if ($invPageCount > 1): ?>
                            ù Page
                            <?php for ($i = 1; $i <= $invPageCount; $i++): ?>
                                <?php if ($i === $invPage): ?><strong><?= $i ?></strong><?php else: ?><a href="<?= cp_h(cp_url(['inv_p' => $i === 1 ? null : $i, 'export' => null])) ?>"><?= $i ?></a><?php endif; ?>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php if ($topPerformer !== null): ?>
            <?php
                $heroFlag = cp_flag_code((string) ($topPerformer['country'] ?? ''));
                $heroInvoices = (int) $topPerformer['invoice_count'];
                $heroHref = cp_url(['customer' => (int) $topPerformer['customer_id']]);
            ?>
            <a class="cp-hero" href="<?= cp_h($heroHref) ?>">
                <div class="cp-hero-main">
                    <svg class="cp-cup" viewBox="0 0 96 96" aria-hidden="true">
                        <path fill="#f5c451" d="M28 18h40v14c0 12-8 22-18 24v8h10v8H36v-8h10v-8c-10-2-18-12-18-24V18z"/>
                        <path fill="#e0a82e" d="M28 24h-8c-4 0-8 4-8 9s4 9 8 9h8v-6h-6c-2 0-4-2-4-4s2-4 4-4h6v-4zm40 0h8c4 0 8 4 8 9s-4 9-8 9h-8v-6h6c2 0 4-2 4-4s-2-4-4-4h-6v-4z"/>
                        <rect x="34" y="74" width="28" height="6" rx="2" fill="#f5c451"/>
                    </svg>
                    <div>
                        <span class="cp-badge">Top performer</span>
                        <div class="cp-hero-rank">#1</div>
                        <h2>
                            <?php if ($heroFlag !== ''): ?><img class="cp-flag" src="https://flagcdn.com/w40/<?= cp_h($heroFlag) ?>.png" alt=""><?php endif; ?>
                            <?= cp_h((string) $topPerformer['customer_name']) ?>
                        </h2>
                        <p class="cp-hero-amount"><?= cp_h(cp_money((float) $topPerformer['total_sales'], $currency)) ?></p>
                        <span class="cp-hero-pill"><?= number_format($heroInvoices) ?> <?= $heroInvoices === 1 ? 'Invoice' : 'Invoices' ?></span>
                    </div>
                </div>
                <div class="cp-hero-stats">
                    <div><span>Total sales</span><strong><?= cp_h(cp_money((float) $topPerformer['total_sales'], $currency)) ?></strong></div>
                    <div><span>Invoices</span><strong><?= number_format($heroInvoices) ?></strong></div>
                    <div><span>Paid</span><strong><?= $topPerformer['paid_amount'] === null ? '-' : cp_h(cp_money((float) $topPerformer['paid_amount'], $currency, true)) ?></strong></div>
                    <div><span>Outstanding</span><strong><?= $topPerformer['outstanding_amount'] === null ? '-' : cp_h(cp_money((float) $topPerformer['outstanding_amount'], $currency, true)) ?></strong></div>
                    <div><span>Last purchase</span><strong><?= cp_h(cp_date_label((string) ($topPerformer['last_purchase'] ?? ''))) ?></strong></div>
                </div>
            </a>
        <?php endif; ?>

        <section class="cp-rank-card">
            <div class="cp-rank-head">
                <div>
                    <h2>Customer ranking</h2>
                    <p>Customers sorted by total sales amount</p>
                </div>
                <div class="cp-tools">
                    <form class="cp-search" method="get" action="<?= cp_h($formAction) ?>">
                        <?php foreach ($filterQuery as $key => $value): ?>
                            <input type="hidden" name="<?= cp_h((string) $key) ?>" value="<?= cp_h((string) $value) ?>">
                        <?php endforeach; ?>
                        <input type="search" name="q" value="<?= cp_h($search) ?>" placeholder="Search customer" maxlength="80" aria-label="Search customer">
                        <button type="submit">Search</button>
                        <?php if ($search !== ''): ?>
                            <a class="cp-clear" href="<?= cp_h(cp_url(['q' => null, 'p' => null, 'customer' => null])) ?>">Clear</a>
                        <?php endif; ?>
                    </form>
                    <div class="cp-switch" role="group" aria-label="Sort ranking">
                        <a class="<?= $sortKey === 'sales' ? 'is-on' : '' ?>" href="<?= cp_h(cp_url(['sort' => 'sales', 'dir' => 'desc', 'p' => null, 'customer' => null])) ?>">By sales</a>
                        <a class="<?= $sortKey === 'customer' ? 'is-on' : '' ?>" href="<?= cp_h(cp_url(['sort' => 'customer', 'dir' => 'asc', 'p' => null, 'customer' => null])) ?>">By customers</a>
                    </div>
                </div>
            </div>
            <?php if ($pageRows === []): ?>
                <div class="cp-empty"><strong><?= $search !== '' ? 'No customers match that search.' : 'No customer sales recorded for this period.' ?></strong><?= $search === '' ? 'Eligible sales invoices in ' . cp_h($periodLabel) . ' will appear here.' : '' ?></div>
            <?php else: ?>
                <div class="cp-table-wrap">
                    <table class="cp-table">
                        <thead>
                            <tr>
                                <?php
                                $heads = [
                                    'rank' => ['#', ''],
                                    'customer' => ['Customer', ''],
                                    'sales' => ['Total sales', 'cp-num'],
                                    'invoices' => ['Invoices', 'cp-num'],
                                    'paid' => ['Paid', 'cp-num'],
                                    'outstanding' => ['Outstanding', 'cp-num'],
                                    'last' => ['Last purchase', ''],
                                ];
                                foreach ($heads as $key => $meta):
                                ?>
                                    <th class="<?= cp_h($meta[1]) ?>"><a href="<?= cp_h(cp_sort_url($key, $sortKey, $sortDir)) ?>"><?= cp_h($meta[0]) ?><?php if ($sortKey === $key): ?> <?= $sortDir === 'asc' ? '&#8593;' : '&#8595;' ?><?php endif; ?></a></th>
                                <?php endforeach; ?>
                                <th class="cp-go" aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pageRows as $row): ?>
                            <?php $flag = cp_flag_code((string) ($row['country'] ?? '')); ?>
                            <tr class="cp-row" data-href="<?= cp_h(cp_url(['customer' => (int) $row['customer_id']])) ?>">
                                <td class="cp-col-sn"><?= (int) $row['rank'] ?></td>
                                <td>
                                    <a class="cp-customer" href="<?= cp_h(cp_url(['customer' => (int) $row['customer_id']])) ?>">
                                        <?php if ($flag !== ''): ?><img class="cp-flag" src="https://flagcdn.com/w40/<?= cp_h($flag) ?>.png" alt=""><?php endif; ?>
                                        <?= cp_h((string) $row['customer_name']) ?>
                                    </a>
                                </td>
                                <td class="cp-num"><span class="cp-amt"><?= cp_h(cp_money((float) $row['total_sales'], $currency)) ?></span></td>
                                <td class="cp-num"><span class="cp-amt"><?= number_format((int) $row['invoice_count']) ?></span></td>
                                <td class="cp-num"><span class="cp-amt"><?= $row['paid_amount'] === null ? '-' : cp_h(cp_money((float) $row['paid_amount'], $currency, true)) ?></span></td>
                                <td class="cp-num"><span class="cp-amt"><?= $row['outstanding_amount'] === null ? '-' : cp_h(cp_money((float) $row['outstanding_amount'], $currency, true)) ?></span></td>
                                <td><?= cp_h(cp_date_label((string) ($row['last_purchase'] ?? ''))) ?></td>
                                <td class="cp-go">&#8250;</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pageCount > 1): ?>
                    <div class="cp-pager">
                        <span>Showing <?= number_format($rangeFrom) ?>-<?= number_format($rangeTo) ?> of <?= number_format($resultCount) ?></span>
                        <div class="cp-pages">
                            <?php if ($page > 1): ?><a href="<?= cp_h(cp_url(['p' => $page - 1, 'customer' => null])) ?>">Prev</a><?php endif; ?>
                            <?php for ($i = 1; $i <= $pageCount; $i++): ?>
                                <?php if ($i === $page): ?><span><?= $i ?></span><?php else: ?><a href="<?= cp_h(cp_url(['p' => $i === 1 ? null : $i, 'customer' => null])) ?>"><?= $i ?></a><?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($page < $pageCount): ?><a href="<?= cp_h(cp_url(['p' => $page + 1, 'customer' => null])) ?>">Next</a><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="cp-analytics">
            <article class="cp-panel">
                <div class="cp-panel-head">
                    <div>
                        <h2><?= cp_h($trendTitle) ?></h2>
                        <p><?= cp_h($trendSubtitle) ?></p>
                    </div>
                </div>
                <div class="cp-bars" role="img" aria-label="<?= cp_h($trendTitle . ' for ' . $trendSubtitle) ?>">
                    <?php foreach ($trendPoints as $point): ?>
                        <?php
                            $barPct = ($trendMax > 0 && $point['amount'] > 0) ? max(4, ($point['amount'] / $trendMax) * 100) : 0;
                        ?>
                        <?php $barTip = ($periodType === 'monthly' ? $rangeStart->format('F') . ' ' : '') . $point['label']; ?>
                        <div class="cp-bar" title="<?= cp_h($barTip . ': ' . cp_money((float) $point['amount'], $currency)) ?>">
                            <div class="cp-bar-fill<?= $point['amount'] > 0 ? '' : ' is-zero' ?>" style="height: <?= $point['amount'] > 0 ? number_format($barPct, 2, '.', '') . '%' : '3px' ?>"></div>
                            <span><?= cp_h($point['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="cp-panel">
                <div class="cp-panel-head">
                    <div>
                        <h2>Top customers by sales</h2>
                        <p>Share of <?= cp_h($periodLabel) ?> sales</p>
                    </div>
                </div>
                <div class="cp-share">
                    <?php if ($contribution === []): ?>
                        <div class="cp-empty"><strong>No customer sales recorded for this period.</strong></div>
                    <?php else: ?>
                        <?php foreach ($contribution as $share): ?>
                            <?php $sharePct = $totalSales > 0 ? (((float) $share['total_sales'] / $totalSales) * 100) : 0; ?>
                            <div class="cp-share-row">
                                <div class="cp-share-top">
                                    <span><?= cp_h((string) $share['customer_name']) ?></span>
                                    <strong><?= cp_h(cp_money((float) $share['total_sales'], $currency, true)) ?></strong>
                                </div>
                                <div class="cp-share-track"><i style="width: <?= number_format(min(100, $sharePct), 2, '.', '') ?>%"></i></div>
                                <div class="cp-share-pct"><?= cp_h(number_format($sharePct, $sharePct >= 10 ? 0 : 1)) ?>% of sales</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>
        </section>
        <p class="cp-note">Figures come from eligible sales invoices for the selected period.</p>
    <?php endif; ?>
<script>
document.querySelectorAll('.cp-row[data-href]').forEach(function (row) {
    row.addEventListener('click', function (event) {
        if (event.target.closest('a, button, input')) return;
        var href = row.getAttribute('data-href');
        if (href) window.location.href = href;
    });
});
</script>
</main>
</body>
</html>
