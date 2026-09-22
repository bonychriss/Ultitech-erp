<?php
/**
 * VAT Control - shared backend helpers for React API + shell.
 */
declare(strict_types=1);

function vatBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        $functions = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
        if (!is_file($functions)) {
            throw new RuntimeException('ERP bootstrap not found (includes/functions.php).');
        }
        require_once $functions;
        $booted = true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    return $pdo;
}

function vatRequireAccess(): void
{
    vatBootstrap();

    // JSON APIs must never HTML-redirect to login (fetch would surface "API returned HTML").
    $isApi = str_contains(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/vat-control-ui/api/');
    if ($isApi) {
        if (!function_exists('isLoggedIn') || !isLoggedIn()) {
            throw new RuntimeException('Not logged in.');
        }
        // Ignore bogus path-derived company slugs like "accounting" for this nested API path.
        $requested = function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '';
        if ($requested !== '' && function_exists('findCompanyBySlug')) {
            $company = findCompanyBySlug($requested);
            if (!$company) {
                // Fall through to session company context
                unset($_GET['company_slug']);
            }
        }
        if (!(function_exists('isAdmin') && isAdmin()) && !(function_exists('isFinance') && isFinance())) {
            http_response_code(403);
            throw new RuntimeException('Access denied. Admin or Finance role required.');
        }
        return;
    }

    requireLogin();
    if (!(function_exists('isAdmin') && isAdmin()) && !(function_exists('isFinance') && isFinance())) {
        http_response_code(403);
        throw new RuntimeException('Access denied. Admin or Finance role required.');
    }
}

function vatPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($script !== '' && str_ends_with($script, 'vat-control.php')) {
        return rtrim(dirname($script), '/') . '/' . $relativePath;
    }

    // Rewrite may strip .php: .../accounting/vat-control
    if ($script !== '' && preg_match('#/accounting/vat-control(?:\\.php)?$#', $script)) {
        $base = preg_replace('#/vat-control(?:\\.php)?$#', '', $script);
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . '/' . $relativePath;
        }
    }

    if (function_exists('app_url')) {
        return app_url('accounting/' . $relativePath);
    }

    return $relativePath;
}

function vatAppUrl(string $path): string
{
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    if (function_exists('app_url')) {
        return (string) app_url($path);
    }
    return $path;
}

function vatCompanyId(): int
{
    if (function_exists('currentCompanyId')) {
        return (int) currentCompanyId();
    }
    return (int) ($_SESSION['company_id'] ?? 0);
}

function vatEnsurePeriodsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `erp_vat_periods` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `period_ym` CHAR(7) NOT NULL,
      `company_id` INT NOT NULL DEFAULT 0,
      `opening_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      `closing_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      `output_vat` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      `input_purchases` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      `input_expenses` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      `net_vat` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      `position` VARCHAR(20) NOT NULL DEFAULT 'nil',
      `status` ENUM('open','reconciled','closed') NOT NULL DEFAULT 'open',
      `reconciled_at` DATETIME NULL,
      `reconciled_by` INT NULL,
      `closed_at` DATETIME NULL,
      `closed_by` INT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_vat_period_company` (`period_ym`, `company_id`),
      KEY `idx_vat_period_status` (`status`),
      KEY `idx_vat_period_company` (`company_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function vatFmt($n): string
{
    $n = (float) $n;
    $sign = $n < 0 ? '-' : '';
    return $sign . 'TZS ' . number_format(abs($n), 2, '.', ',');
}

function vatMonthLabel(string $ym): string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        return $ym;
    }
    $ts = strtotime($ym . '-01');
    return $ts ? date('F Y', $ts) : $ym;
}

function vatCategoryLabel(string $cat): string
{
    return match (strtolower(trim($cat))) {
        'output', 'output_vat' => 'Output VAT',
        'purchases', 'input_purchases' => 'Purchases',
        'expenses', 'input_expenses' => 'Expenses',
        default => ucwords(str_replace('_', ' ', $cat)),
    };
}

function vatNormalizeCategory(string $cat): string
{
    $cat = strtolower(trim($cat));
    return match ($cat) {
        'output', 'output_vat' => 'output',
        'purchases', 'input_purchases' => 'purchases',
        'expenses', 'input_expenses' => 'expenses',
        default => $cat,
    };
}

function vatPositionFromNet(float $net): string
{
    if (abs($net) < 0.005) {
        return 'nil';
    }
    return $net > 0 ? 'payable' : 'credit';
}

/**
 * Detect first existing date column on stocks_purchase_orders.
 *
 * @return list<string>
 */
function vatPurchaseDateColumns(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $cached = [];
    if (!function_exists('tableExists') || !tableExists('stocks_purchase_orders', $pdo)) {
        return $cached;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cols = array_map('strtolower', array_map('strval', $cols));
        foreach (['purchase_date', 'order_date', 'po_date', 'date', 'created_at'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $cached[] = $candidate;
            }
        }
    } catch (Throwable $e) {
        $cached = [];
    }
    return $cached;
}

function vatPurchaseDateExpr(PDO $pdo, string $alias = ''): ?string
{
    $cols = vatPurchaseDateColumns($pdo);
    if ($cols === []) {
        return null;
    }
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $qualified = array_map(static fn(string $c): string => $prefix . $c, $cols);
    if (count($qualified) === 1) {
        return $qualified[0];
    }
    return 'COALESCE(' . implode(', ', $qualified) . ')';
}

/**
 * @return array{
 *   output: float,
 *   input_purchases: float,
 *   input_expenses: float,
 *   input_total: float,
 *   net: float,
 *   position: string
 * }
 */
function vatComputeMonthTotals(PDO $pdo, string $ym): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        throw new InvalidArgumentException('Invalid month. Use YYYY-MM.');
    }

    $output = 0.0;
    $purchases = 0.0;
    $expenses = 0.0;

    if (function_exists('tableExists') && tableExists('revenue_entries', $pdo)) {
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(vat_amount), 0)
                FROM revenue_entries
                WHERE DATE_FORMAT(entry_date, '%Y-%m') = ?
            ");
            $stmt->execute([$ym]);
            $output = (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('vatComputeMonthTotals output: ' . $e->getMessage());
        }
    }

    $dateExpr = vatPurchaseDateExpr($pdo);
    $hasTax = function_exists('columnExists')
        ? columnExists('stocks_purchase_orders', 'tax_amount', $pdo)
        : false;
    if ($dateExpr !== null && $hasTax) {
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(tax_amount), 0)
                FROM stocks_purchase_orders
                WHERE DATE_FORMAT({$dateExpr}, '%Y-%m') = ?
            ");
            $stmt->execute([$ym]);
            $purchases = (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('vatComputeMonthTotals purchases: ' . $e->getMessage());
        }
    }

    if (function_exists('tableExists') && tableExists('erp_expenses', $pdo)) {
        try {
            $hasPosted = function_exists('columnExists') && columnExists('erp_expenses', 'is_posted', $pdo);
            $hasTaxExp = !function_exists('columnExists') || columnExists('erp_expenses', 'tax_amount', $pdo);
            if ($hasTaxExp) {
                $sql = "
                    SELECT COALESCE(SUM(tax_amount), 0)
                    FROM erp_expenses
                    WHERE DATE_FORMAT(`date`, '%Y-%m') = ?
                ";
                if ($hasPosted) {
                    $sql .= ' AND is_posted = 1';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$ym]);
                $expenses = (float) $stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            error_log('vatComputeMonthTotals expenses: ' . $e->getMessage());
        }
    }

    $inputTotal = $purchases + $expenses;
    $net = $output - $inputTotal;

    return [
        'output' => $output,
        'input_purchases' => $purchases,
        'input_expenses' => $expenses,
        'input_total' => $inputTotal,
        'net' => $net,
        'position' => vatPositionFromNet($net),
    ];
}

/**
 * Collect every YYYY-MM that has any VAT activity across sources.
 *
 * @return list<string>
 */
function vatCollectActivityMonths(PDO $pdo): array
{
    $months = [];

    if (function_exists('tableExists') && tableExists('revenue_entries', $pdo)) {
        try {
            $rows = $pdo->query("
                SELECT DISTINCT DATE_FORMAT(entry_date, '%Y-%m') AS ym
                FROM revenue_entries
                WHERE entry_date IS NOT NULL
                  AND COALESCE(vat_amount, 0) <> 0
            ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($rows as $ym) {
                if (is_string($ym) && preg_match('/^\d{4}-\d{2}$/', $ym)) {
                    $months[$ym] = true;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $dateExpr = vatPurchaseDateExpr($pdo);
    $hasTax = function_exists('columnExists') && columnExists('stocks_purchase_orders', 'tax_amount', $pdo);
    if ($dateExpr !== null && $hasTax) {
        try {
            $rows = $pdo->query("
                SELECT DISTINCT DATE_FORMAT({$dateExpr}, '%Y-%m') AS ym
                FROM stocks_purchase_orders
                WHERE {$dateExpr} IS NOT NULL
                  AND COALESCE(tax_amount, 0) <> 0
            ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($rows as $ym) {
                if (is_string($ym) && preg_match('/^\d{4}-\d{2}$/', $ym)) {
                    $months[$ym] = true;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (function_exists('tableExists') && tableExists('erp_expenses', $pdo)) {
        try {
            $hasPosted = function_exists('columnExists') && columnExists('erp_expenses', 'is_posted', $pdo);
            $hasTaxExp = !function_exists('columnExists') || columnExists('erp_expenses', 'tax_amount', $pdo);
            if ($hasTaxExp) {
                $sql = "SELECT DISTINCT DATE_FORMAT(`date`, '%Y-%m') AS ym
                        FROM erp_expenses
                        WHERE `date` IS NOT NULL
                          AND COALESCE(tax_amount, 0) <> 0";
                if ($hasPosted) {
                    $sql .= ' AND is_posted = 1';
                }
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($rows as $ym) {
                    if (is_string($ym) && preg_match('/^\d{4}-\d{2}$/', $ym)) {
                        $months[$ym] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Include closed/reconciled periods even if source data vanished
    try {
        vatEnsurePeriodsTable($pdo);
        $companyId = vatCompanyId();
        $stmt = $pdo->prepare('SELECT period_ym FROM erp_vat_periods WHERE company_id = ?');
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ym) {
            if (is_string($ym) && preg_match('/^\d{4}-\d{2}$/', $ym)) {
                $months[$ym] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $list = array_keys($months);
    rsort($list);
    return $list;
}

/**
 * @return array<string, mixed>|null
 */
function vatFetchPeriodRow(PDO $pdo, string $ym, int $companyId): ?array
{
    vatEnsurePeriodsTable($pdo);
    $stmt = $pdo->prepare('SELECT * FROM erp_vat_periods WHERE period_ym = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$ym, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function vatGetOpeningBalance(PDO $pdo, string $ym, int $companyId): float
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        return 0.0;
    }

    vatEnsurePeriodsTable($pdo);

    // Prefer previous closed period closing_balance
    $stmt = $pdo->prepare("
        SELECT closing_balance
        FROM erp_vat_periods
        WHERE company_id = ?
          AND period_ym < ?
          AND status = 'closed'
        ORDER BY period_ym DESC
        LIMIT 1
    ");
    $stmt->execute([$companyId, $ym]);
    $closed = $stmt->fetchColumn();
    if ($closed !== false && $closed !== null) {
        return (float) $closed;
    }

    // Chain from earliest activity month up to (but not including) $ym
    $allMonths = vatCollectActivityMonths($pdo);
    sort($allMonths);
    $running = 0.0;
    foreach ($allMonths as $m) {
        if ($m >= $ym) {
            break;
        }
        $prevClosed = vatFetchPeriodRow($pdo, $m, $companyId);
        if ($prevClosed && strtolower((string) ($prevClosed['status'] ?? '')) === 'closed') {
            $running = (float) ($prevClosed['closing_balance'] ?? 0);
            continue;
        }
        $totals = vatComputeMonthTotals($pdo, $m);
        $running = $running + (float) $totals['net'];
    }

    return $running;
}

/**
 * @return array<string, mixed>
 */
function vatBuildDashboard(PDO $pdo): array
{
    vatEnsurePeriodsTable($pdo);
    $companyId = vatCompanyId();
    $thisYm = date('Y-m');
    $year = (int) date('Y');
    $yearPrefix = sprintf('%04d-', $year);

    $months = vatCollectActivityMonths($pdo);
    if (!in_array($thisYm, $months, true)) {
        // Ensure current month appears even with zero activity
        array_unshift($months, $thisYm);
        $months = array_values(array_unique($months));
        rsort($months);
    }

    $ytdOutput = 0.0;
    $ytdPurchases = 0.0;
    $ytdExpenses = 0.0;
    $allOutput = 0.0;
    $allPurchases = 0.0;
    $allExpenses = 0.0;

    foreach ($months as $ym) {
        $t = vatComputeMonthTotals($pdo, $ym);
        $allOutput += $t['output'];
        $allPurchases += $t['input_purchases'];
        $allExpenses += $t['input_expenses'];
        if (str_starts_with($ym, $yearPrefix)) {
            $ytdOutput += $t['output'];
            $ytdPurchases += $t['input_purchases'];
            $ytdExpenses += $t['input_expenses'];
        }
    }

    $thisMonth = vatComputeMonthTotals($pdo, $thisYm);
    $opening = vatGetOpeningBalance($pdo, $thisYm, $companyId);
    $closing = $opening + (float) $thisMonth['net'];

    $ytdInput = $ytdPurchases + $ytdExpenses;
    $ytdNet = $ytdOutput - $ytdInput;
    $allInput = $allPurchases + $allExpenses;
    $allNet = $allOutput - $allInput;

    $periodRow = vatFetchPeriodRow($pdo, $thisYm, $companyId);
    $thisStatus = strtolower((string) ($periodRow['status'] ?? 'open'));

    $history = [];
    foreach ($months as $ym) {
        $t = vatComputeMonthTotals($pdo, $ym);
        $row = vatFetchPeriodRow($pdo, $ym, $companyId);
        $status = strtolower((string) ($row['status'] ?? 'open'));
        $openBal = vatGetOpeningBalance($pdo, $ym, $companyId);
        $closeBal = $row && $status === 'closed'
            ? (float) ($row['closing_balance'] ?? ($openBal + $t['net']))
            : $openBal + $t['net'];
        $history[] = [
            'ym' => $ym,
            'label' => vatMonthLabel($ym),
            'output' => $t['output'],
            'outputDisplay' => vatFmt($t['output']),
            'inputPurchases' => $t['input_purchases'],
            'inputPurchasesDisplay' => vatFmt($t['input_purchases']),
            'inputExpenses' => $t['input_expenses'],
            'inputExpensesDisplay' => vatFmt($t['input_expenses']),
            'inputTotal' => $t['input_total'],
            'inputTotalDisplay' => vatFmt($t['input_total']),
            'net' => $t['net'],
            'netDisplay' => vatFmt($t['net']),
            'position' => $t['position'],
            'openingBalance' => $openBal,
            'openingBalanceDisplay' => vatFmt($openBal),
            'closingBalance' => $closeBal,
            'closingBalanceDisplay' => vatFmt($closeBal),
            'status' => $status,
            'statusLabel' => ucfirst($status),
        ];
    }

    $companyDisplay = (string) ($_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company'));

    return [
        'todayLabel' => date('l, d M Y'),
        'companyDisplay' => $companyDisplay,
        'year' => $year,
        'thisYm' => $thisYm,
        'thisMonthLabel' => vatMonthLabel($thisYm),
        'kpis' => [
            'ytdNet' => $ytdNet,
            'ytdNetDisplay' => vatFmt($ytdNet),
            'ytdOutput' => $ytdOutput,
            'ytdOutputDisplay' => vatFmt($ytdOutput),
            'ytdInput' => $ytdInput,
            'ytdInputDisplay' => vatFmt($ytdInput),
            'allTimeNet' => $allNet,
            'allTimeNetDisplay' => vatFmt($allNet),
            'thisMonthNet' => $thisMonth['net'],
            'thisMonthNetDisplay' => vatFmt($thisMonth['net']),
            'thisMonthPosition' => $thisMonth['position'],
            'thisMonthStatus' => $thisStatus,
            'openingBalance' => $opening,
            'openingBalanceDisplay' => vatFmt($opening),
            'closingBalance' => $closing,
            'closingBalanceDisplay' => vatFmt($closing),
        ],
        'categories' => [
            [
                'key' => 'output',
                'label' => vatCategoryLabel('output'),
                'total' => $ytdOutput,
                'totalDisplay' => vatFmt($ytdOutput),
                'allTime' => $allOutput,
                'allTimeDisplay' => vatFmt($allOutput),
                'thisMonth' => $thisMonth['output'],
                'thisMonthDisplay' => vatFmt($thisMonth['output']),
                'tone' => 'blue',
            ],
            [
                'key' => 'purchases',
                'label' => vatCategoryLabel('purchases'),
                'total' => $ytdPurchases,
                'totalDisplay' => vatFmt($ytdPurchases),
                'allTime' => $allPurchases,
                'allTimeDisplay' => vatFmt($allPurchases),
                'thisMonth' => $thisMonth['input_purchases'],
                'thisMonthDisplay' => vatFmt($thisMonth['input_purchases']),
                'tone' => 'indigo',
            ],
            [
                'key' => 'expenses',
                'label' => vatCategoryLabel('expenses'),
                'total' => $ytdExpenses,
                'totalDisplay' => vatFmt($ytdExpenses),
                'allTime' => $allExpenses,
                'allTimeDisplay' => vatFmt($allExpenses),
                'thisMonth' => $thisMonth['input_expenses'],
                'thisMonthDisplay' => vatFmt($thisMonth['input_expenses']),
                'tone' => 'violet',
            ],
        ],
        'history' => $history,
        'historyCount' => count($history),
    ];
}

/**
 * @return array<string, mixed>
 */
function vatBuildCategoryYears(PDO $pdo, string $category): array
{
    $category = vatNormalizeCategory($category);
    if (!in_array($category, ['output', 'purchases', 'expenses'], true)) {
        throw new InvalidArgumentException('Invalid category.');
    }

    $months = vatCollectActivityMonths($pdo);
    $byYear = [];

    foreach ($months as $ym) {
        $year = (int) substr($ym, 0, 4);
        $t = vatComputeMonthTotals($pdo, $ym);
        $amount = match ($category) {
            'output' => $t['output'],
            'purchases' => $t['input_purchases'],
            'expenses' => $t['input_expenses'],
            default => 0.0,
        };
        if (!isset($byYear[$year])) {
            $byYear[$year] = ['year' => $year, 'total' => 0.0, 'monthCount' => 0];
        }
        $byYear[$year]['total'] += $amount;
        $byYear[$year]['monthCount']++;
    }

    krsort($byYear);
    $years = [];
    foreach ($byYear as $row) {
        $years[] = [
            'year' => $row['year'],
            'label' => (string) $row['year'],
            'total' => $row['total'],
            'totalDisplay' => vatFmt($row['total']),
            'monthCount' => $row['monthCount'],
        ];
    }

    $grand = array_sum(array_column($years, 'total'));

    return [
        'category' => $category,
        'categoryLabel' => vatCategoryLabel($category),
        'years' => $years,
        'yearCount' => count($years),
        'grandTotal' => $grand,
        'grandTotalDisplay' => vatFmt($grand),
    ];
}

/**
 * @return array<string, mixed>
 */
function vatBuildYearMonths(PDO $pdo, string $category, int $year): array
{
    $rawCategory = strtolower(trim($category));
    $hasCategory = $rawCategory !== '' && $rawCategory !== 'all';
    $normalized = '';
    if ($hasCategory) {
        $normalized = vatNormalizeCategory($rawCategory);
        if (!in_array($normalized, ['output', 'purchases', 'expenses'], true)) {
            throw new InvalidArgumentException('Invalid category.');
        }
    }
    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Invalid year.');
    }

    $companyId = vatCompanyId();
    $prefix = sprintf('%04d-', $year);
    $monthsOut = [];
    $yearOutput = 0.0;
    $yearPurchases = 0.0;
    $yearExpenses = 0.0;
    $yearNet = 0.0;
    $yearCategoryTotal = 0.0;

    $months = vatCollectActivityMonths($pdo);
    $thisYm = date('Y-m');
    if ((int) date('Y') === $year && !in_array($thisYm, $months, true)) {
        $months[] = $thisYm;
        rsort($months);
    }

    foreach ($months as $ym) {
        if (!str_starts_with($ym, $prefix)) {
            continue;
        }
        $t = vatComputeMonthTotals($pdo, $ym);
        $amount = $hasCategory
            ? match ($normalized) {
                'output' => $t['output'],
                'purchases' => $t['input_purchases'],
                'expenses' => $t['input_expenses'],
                default => 0.0,
            }
            : $t['net'];
        $row = vatFetchPeriodRow($pdo, $ym, $companyId);
        $status = strtolower((string) ($row['status'] ?? 'open'));
        $monthsOut[] = [
            'ym' => $ym,
            'label' => vatMonthLabel($ym),
            'amount' => $amount,
            'amountDisplay' => vatFmt($amount),
            'output' => $t['output'],
            'outputDisplay' => vatFmt($t['output']),
            'inputPurchases' => $t['input_purchases'],
            'inputPurchasesDisplay' => vatFmt($t['input_purchases']),
            'inputExpenses' => $t['input_expenses'],
            'inputExpensesDisplay' => vatFmt($t['input_expenses']),
            'inputTotal' => $t['input_total'],
            'inputTotalDisplay' => vatFmt($t['input_total']),
            'net' => $t['net'],
            'netDisplay' => vatFmt($t['net']),
            'position' => $t['position'],
            'status' => $status,
            'statusLabel' => ucfirst($status),
        ];
        $yearOutput += $t['output'];
        $yearPurchases += $t['input_purchases'];
        $yearExpenses += $t['input_expenses'];
        $yearNet += $t['net'];
        $yearCategoryTotal += $amount;
    }

    $yearInput = $yearPurchases + $yearExpenses;

    return [
        'category' => $hasCategory ? $normalized : null,
        'categoryLabel' => $hasCategory ? vatCategoryLabel($normalized) : 'All categories',
        'year' => $year,
        'months' => $monthsOut,
        'monthCount' => count($monthsOut),
        'yearTotal' => $hasCategory ? $yearCategoryTotal : $yearNet,
        'yearTotalDisplay' => vatFmt($hasCategory ? $yearCategoryTotal : $yearNet),
        'yearOutput' => $yearOutput,
        'yearOutputDisplay' => vatFmt($yearOutput),
        'yearInput' => $yearInput,
        'yearInputDisplay' => vatFmt($yearInput),
        'yearNet' => $yearNet,
        'yearNetDisplay' => vatFmt($yearNet),
    ];
}

/**
 * @return array<string, mixed>
 */
function vatBuildMonthDetail(PDO $pdo, string $ym): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        throw new InvalidArgumentException('Invalid month. Use YYYY-MM.');
    }

    vatEnsurePeriodsTable($pdo);
    $companyId = vatCompanyId();
    $totals = vatComputeMonthTotals($pdo, $ym);
    $opening = vatGetOpeningBalance($pdo, $ym, $companyId);
    $closing = $opening + (float) $totals['net'];
    $period = vatFetchPeriodRow($pdo, $ym, $companyId);
    $status = strtolower((string) ($period['status'] ?? 'open'));

    // Same source for system vs module - difference 0 until an independent control ledger exists
    $system = [
        'output' => $totals['output'],
        'inputPurchases' => $totals['input_purchases'],
        'inputExpenses' => $totals['input_expenses'],
        'inputTotal' => $totals['input_total'],
        'net' => $totals['net'],
    ];
    $module = $system;
    $difference = [
        'output' => 0.0,
        'inputPurchases' => 0.0,
        'inputExpenses' => 0.0,
        'inputTotal' => 0.0,
        'net' => 0.0,
    ];

    $activity = [
        [
            'source' => 'output',
            'label' => 'Revenue',
            'amount' => $totals['output'],
            'amountDisplay' => vatFmt($totals['output']),
            'description' => 'Output VAT from revenue / sales',
        ],
        [
            'source' => 'purchases',
            'label' => 'Purchases',
            'amount' => $totals['input_purchases'],
            'amountDisplay' => vatFmt($totals['input_purchases']),
            'description' => 'Input VAT from purchase orders',
        ],
        [
            'source' => 'expenses',
            'label' => 'Expenses',
            'amount' => $totals['input_expenses'],
            'amountDisplay' => vatFmt($totals['input_expenses']),
            'description' => 'Input VAT from posted expenses',
        ],
    ];

    $canReconcile = $status === 'open';
    $canClose = $status === 'reconciled';
    $hasActivity = abs((float) $totals['output']) > 0.005
        || abs((float) $totals['input_total']) > 0.005;

    return [
        'ym' => $ym,
        'label' => vatMonthLabel($ym),
        'hasActivity' => $hasActivity,
        'summary' => [
            'openingBalance' => $opening,
            'openingBalanceDisplay' => vatFmt($opening),
            'output' => $totals['output'],
            'outputDisplay' => vatFmt($totals['output']),
            'inputPurchases' => $totals['input_purchases'],
            'inputPurchasesDisplay' => vatFmt($totals['input_purchases']),
            'inputExpenses' => $totals['input_expenses'],
            'inputExpensesDisplay' => vatFmt($totals['input_expenses']),
            'inputTotal' => $totals['input_total'],
            'inputTotalDisplay' => vatFmt($totals['input_total']),
            'net' => $totals['net'],
            'netDisplay' => vatFmt($totals['net']),
            'closingBalance' => $closing,
            'closingBalanceDisplay' => vatFmt($closing),
            'position' => $totals['position'],
            'positionLabel' => match ($totals['position']) {
                'payable' => 'VAT Payable',
                'credit' => 'VAT Credit',
                default => 'Nil',
            },
        ],
        'activity' => $activity,
        'reconciliation' => [
            'system' => array_merge($system, [
                'outputDisplay' => vatFmt($system['output']),
                'inputPurchasesDisplay' => vatFmt($system['inputPurchases']),
                'inputExpensesDisplay' => vatFmt($system['inputExpenses']),
                'inputTotalDisplay' => vatFmt($system['inputTotal']),
                'netDisplay' => vatFmt($system['net']),
            ]),
            'module' => array_merge($module, [
                'outputDisplay' => vatFmt($module['output']),
                'inputPurchasesDisplay' => vatFmt($module['inputPurchases']),
                'inputExpensesDisplay' => vatFmt($module['inputExpenses']),
                'inputTotalDisplay' => vatFmt($module['inputTotal']),
                'netDisplay' => vatFmt($module['net']),
            ]),
            'difference' => array_merge($difference, [
                'outputDisplay' => vatFmt(0),
                'inputPurchasesDisplay' => vatFmt(0),
                'inputExpensesDisplay' => vatFmt(0),
                'inputTotalDisplay' => vatFmt(0),
                'netDisplay' => vatFmt(0),
            ]),
            'isBalanced' => true,
        ],
        'period' => [
            'status' => $status,
            'statusLabel' => ucfirst($status),
            'reconciledAt' => $period['reconciled_at'] ?? null,
            'closedAt' => $period['closed_at'] ?? null,
            'canReconcile' => $canReconcile,
            'canClose' => $canClose,
        ],
    ];
}

function vatSourceUrl(string $source, int $id): ?string
{
    if ($id <= 0) {
        return null;
    }
    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));

    if ($source === 'output') {
        return vatAppUrl('/revenue_details.php?id=' . $id);
    }
    if ($source === 'purchases') {
        $path = '/stock/modules/purchases/view_po.php?id=' . $id;
        if ($slug !== '' && function_exists('company_url')) {
            // Prefer app_url for deep stock paths; company_url may not map view_po
            return vatAppUrl($path);
        }
        return vatAppUrl($path);
    }
    if ($source === 'expenses') {
        return vatAppUrl('/modules/expenses/index.php?module=expenses&focus=' . $id);
    }
    return null;
}

/**
 * @return array<string, mixed>
 */
function vatBuildTransactions(PDO $pdo, string $ym, string $source): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        throw new InvalidArgumentException('Invalid month. Use YYYY-MM.');
    }
    $source = vatNormalizeCategory($source);
    if (!in_array($source, ['output', 'purchases', 'expenses'], true)) {
        throw new InvalidArgumentException('Invalid source. Use output, purchases, or expenses.');
    }

    $rows = [];

    if ($source === 'output' && function_exists('tableExists') && tableExists('revenue_entries', $pdo)) {
        $stmt = $pdo->prepare("
            SELECT id, entry_date AS txn_date, voucher_number, customer_name, narration,
                   vat_amount, amount_exclusive, amount_total, payment_status, approval_status
            FROM revenue_entries
            WHERE DATE_FORMAT(entry_date, '%Y-%m') = ?
              AND COALESCE(vat_amount, 0) <> 0
            ORDER BY entry_date DESC, id DESC
        ");
        $stmt->execute([$ym]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int) ($r['id'] ?? 0);
            $vat = (float) ($r['vat_amount'] ?? 0);
            $taxable = (float) ($r['amount_exclusive'] ?? 0);
            $rate = $taxable > 0.0001 ? round(($vat / $taxable) * 100, 2) : 0.0;
            $status = (string) ($r['approval_status'] ?? $r['payment_status'] ?? '');
            $rows[] = [
                'id' => $id,
                'date' => (string) ($r['txn_date'] ?? ''),
                'reference' => (string) ($r['voucher_number'] ?? ('#' . $id)),
                'party' => (string) ($r['customer_name'] ?? ''),
                'description' => (string) ($r['narration'] ?? ''),
                'taxableAmount' => $taxable,
                'taxableAmountDisplay' => vatFmt($taxable),
                'vatRate' => $rate,
                'vatRateDisplay' => $rate > 0 ? (rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%') : '-',
                'vatAmount' => $vat,
                'vatAmountDisplay' => vatFmt($vat),
                'grossAmount' => (float) ($r['amount_total'] ?? 0),
                'grossAmountDisplay' => vatFmt((float) ($r['amount_total'] ?? 0)),
                'status' => $status !== '' ? $status : '-',
                'sourceUrl' => vatSourceUrl('output', $id),
            ];
        }
    }

    if ($source === 'purchases') {
        $dateExpr = vatPurchaseDateExpr($pdo);
        $hasTax = function_exists('columnExists') && columnExists('stocks_purchase_orders', 'tax_amount', $pdo);
        if ($dateExpr !== null && $hasTax) {
            $hasSupplierJoin = function_exists('tableExists') && tableExists('stocks_suppliers', $pdo);
            $dateExpr = vatPurchaseDateExpr($pdo, 'p');
            $hasTaxPct = function_exists('columnExists') && columnExists('stocks_purchase_orders', 'tax_percentage', $pdo);
            $taxPctSelect = $hasTaxPct ? 'p.tax_percentage' : 'NULL AS tax_percentage';
            $supplierSelect = $hasSupplierJoin
                ? "COALESCE(ss.name, CONCAT('Supplier #', p.supplier_id)) AS supplier_name"
                : "CONCAT('Supplier #', COALESCE(p.supplier_id, 0)) AS supplier_name";
            $joinSql = $hasSupplierJoin ? ' LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id' : '';
            $sql = "
                SELECT p.id, {$dateExpr} AS txn_date, p.po_number, {$supplierSelect},
                       p.tax_amount, {$taxPctSelect}, p.total_amount, p.status
                FROM stocks_purchase_orders p
                {$joinSql}
                WHERE DATE_FORMAT({$dateExpr}, '%Y-%m') = ?
                  AND COALESCE(p.tax_amount, 0) <> 0
                ORDER BY {$dateExpr} DESC, p.id DESC
            ";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$ym]);
                $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $fallbackDate = vatPurchaseDateExpr($pdo);
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM stocks_purchase_orders
                    WHERE DATE_FORMAT({$fallbackDate}, '%Y-%m') = ?
                    ORDER BY id DESC
                ");
                $stmt->execute([$ym]);
                $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            foreach ($raw as $r) {
                $id = (int) ($r['id'] ?? 0);
                $vat = (float) ($r['tax_amount'] ?? 0);
                $ref = (string) ($r['po_number'] ?? $r['purchase_order_number'] ?? ('PO #' . $id));
                $party = (string) ($r['supplier_name'] ?? $r['vendor_name'] ?? '');
                $desc = (string) ($r['notes'] ?? $r['description'] ?? $r['remarks'] ?? '');
                $gross = (float) ($r['total_amount'] ?? $r['grand_total'] ?? $r['amount'] ?? 0);
                $taxable = max(0.0, $gross - $vat);
                $rate = (float) ($r['tax_percentage'] ?? 0);
                if ($rate <= 0 && $taxable > 0.0001) {
                    $rate = round(($vat / $taxable) * 100, 2);
                }
                $dateVal = (string) ($r['txn_date'] ?? $r['purchase_date'] ?? $r['order_date'] ?? $r['po_date'] ?? $r['date'] ?? $r['created_at'] ?? '');
                $rows[] = [
                    'id' => $id,
                    'date' => $dateVal,
                    'reference' => $ref,
                    'party' => $party,
                    'description' => $desc,
                    'taxableAmount' => $taxable,
                    'taxableAmountDisplay' => vatFmt($taxable),
                    'vatRate' => $rate,
                    'vatRateDisplay' => $rate > 0 ? (rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%') : '-',
                    'vatAmount' => $vat,
                    'vatAmountDisplay' => vatFmt($vat),
                    'grossAmount' => $gross,
                    'grossAmountDisplay' => vatFmt($gross),
                    'status' => (string) ($r['status'] ?? '-'),
                    'sourceUrl' => vatSourceUrl('purchases', $id),
                ];
            }
        }
    }

    if ($source === 'expenses' && function_exists('tableExists') && tableExists('erp_expenses', $pdo)) {
        $hasPosted = function_exists('columnExists') && columnExists('erp_expenses', 'is_posted', $pdo);
        $sql = "
            SELECT id, `date` AS txn_date, expense_number, payee, description, tax_amount, amount, status
            FROM erp_expenses
            WHERE DATE_FORMAT(`date`, '%Y-%m') = ?
              AND COALESCE(tax_amount, 0) <> 0
        ";
        if ($hasPosted) {
            $sql .= ' AND is_posted = 1';
        }
        $sql .= ' ORDER BY `date` DESC, id DESC';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ym]);
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $sql2 = "SELECT * FROM erp_expenses WHERE DATE_FORMAT(`date`, '%Y-%m') = ? AND COALESCE(tax_amount, 0) <> 0";
            if ($hasPosted) {
                $sql2 .= ' AND is_posted = 1';
            }
            $sql2 .= ' ORDER BY id DESC';
            $stmt = $pdo->prepare($sql2);
            $stmt->execute([$ym]);
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        foreach ($raw as $r) {
            $id = (int) ($r['id'] ?? 0);
            $vat = (float) ($r['tax_amount'] ?? 0);
            $gross = (float) ($r['amount'] ?? 0);
            $taxable = max(0.0, $gross - $vat);
            $rate = $taxable > 0.0001 ? round(($vat / $taxable) * 100, 2) : 0.0;
            $rows[] = [
                'id' => $id,
                'date' => (string) ($r['txn_date'] ?? $r['date'] ?? ''),
                'reference' => (string) ($r['expense_number'] ?? ('EXP #' . $id)),
                'party' => (string) ($r['payee'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'taxableAmount' => $taxable,
                'taxableAmountDisplay' => vatFmt($taxable),
                'vatRate' => $rate,
                'vatRateDisplay' => $rate > 0 ? (rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%') : '-',
                'vatAmount' => $vat,
                'vatAmountDisplay' => vatFmt($vat),
                'grossAmount' => $gross,
                'grossAmountDisplay' => vatFmt($gross),
                'status' => (string) ($r['status'] ?? '-'),
                'sourceUrl' => vatSourceUrl('expenses', $id),
            ];
        }
    }

    $totalVat = array_sum(array_map(static fn($r) => (float) $r['vatAmount'], $rows));

    return [
        'ym' => $ym,
        'label' => vatMonthLabel($ym),
        'source' => $source,
        'sourceLabel' => vatCategoryLabel($source),
        'transactions' => $rows,
        'transactionCount' => count($rows),
        'totalVat' => $totalVat,
        'totalVatDisplay' => vatFmt($totalVat),
    ];
}

/**
 * @return array<string, mixed>
 */
function vatReconcilePeriod(PDO $pdo, string $ym): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        throw new InvalidArgumentException('Invalid month. Use YYYY-MM.');
    }

    vatEnsurePeriodsTable($pdo);
    $companyId = vatCompanyId();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $existing = vatFetchPeriodRow($pdo, $ym, $companyId);
    $status = strtolower((string) ($existing['status'] ?? 'open'));
    if ($status === 'closed') {
        throw new RuntimeException('Period is already closed.');
    }

    $totals = vatComputeMonthTotals($pdo, $ym);
    $opening = vatGetOpeningBalance($pdo, $ym, $companyId);
    $closing = $opening + (float) $totals['net'];

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE erp_vat_periods SET
                opening_balance = ?, closing_balance = ?,
                output_vat = ?, input_purchases = ?, input_expenses = ?,
                net_vat = ?, position = ?, status = 'reconciled',
                reconciled_at = NOW(), reconciled_by = ?
            WHERE period_ym = ? AND company_id = ?
        ");
        $stmt->execute([
            $opening,
            $closing,
            $totals['output'],
            $totals['input_purchases'],
            $totals['input_expenses'],
            $totals['net'],
            $totals['position'],
            $userId > 0 ? $userId : null,
            $ym,
            $companyId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO erp_vat_periods
                (period_ym, company_id, opening_balance, closing_balance,
                 output_vat, input_purchases, input_expenses, net_vat, position,
                 status, reconciled_at, reconciled_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'reconciled', NOW(), ?)
        ");
        $stmt->execute([
            $ym,
            $companyId,
            $opening,
            $closing,
            $totals['output'],
            $totals['input_purchases'],
            $totals['input_expenses'],
            $totals['net'],
            $totals['position'],
            $userId > 0 ? $userId : null,
        ]);
    }

    return vatBuildMonthDetail($pdo, $ym);
}

/**
 * @return array<string, mixed>
 */
function vatClosePeriod(PDO $pdo, string $ym): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        throw new InvalidArgumentException('Invalid month. Use YYYY-MM.');
    }

    vatEnsurePeriodsTable($pdo);
    $companyId = vatCompanyId();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $existing = vatFetchPeriodRow($pdo, $ym, $companyId);
    $status = strtolower((string) ($existing['status'] ?? 'open'));
    if ($status === 'closed') {
        throw new RuntimeException('Period is already closed.');
    }

    $totals = vatComputeMonthTotals($pdo, $ym);
    $opening = vatGetOpeningBalance($pdo, $ym, $companyId);
    $closing = $opening + (float) $totals['net'];

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE erp_vat_periods SET
                opening_balance = ?, closing_balance = ?,
                output_vat = ?, input_purchases = ?, input_expenses = ?,
                net_vat = ?, position = ?, status = 'closed',
                reconciled_at = COALESCE(reconciled_at, NOW()),
                reconciled_by = COALESCE(reconciled_by, ?),
                closed_at = NOW(), closed_by = ?
            WHERE period_ym = ? AND company_id = ?
        ");
        $stmt->execute([
            $opening,
            $closing,
            $totals['output'],
            $totals['input_purchases'],
            $totals['input_expenses'],
            $totals['net'],
            $totals['position'],
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
            $ym,
            $companyId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO erp_vat_periods
                (period_ym, company_id, opening_balance, closing_balance,
                 output_vat, input_purchases, input_expenses, net_vat, position,
                 status, reconciled_at, reconciled_by, closed_at, closed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'closed', NOW(), ?, NOW(), ?)
        ");
        $stmt->execute([
            $ym,
            $companyId,
            $opening,
            $closing,
            $totals['output'],
            $totals['input_purchases'],
            $totals['input_expenses'],
            $totals['net'],
            $totals['position'],
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ]);
    }

    return vatBuildMonthDetail($pdo, $ym);
}
