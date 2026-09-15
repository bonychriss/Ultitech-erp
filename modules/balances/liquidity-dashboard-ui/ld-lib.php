<?php
/**
 * Liquidity Dashboard - shared backend helpers for React API + shell.
 */
declare(strict_types=1);

/**
 * Resolve the Balances module root that contains config/database.php.
 * Supports both modules/balances and ultimate/modules/balances (partial mirror).
 */
function ldBalancesRoot(): string
{
    static $root = null;
    if (is_string($root)) {
        return $root;
    }

    $local = dirname(__DIR__);
    $candidates = [
        $local,
        // ultimate/modules/balances ? public_html/modules/balances
        dirname($local, 2) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'balances',
        dirname($local, 3) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'balances',
        dirname($local, 4) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'balances',
    ];

    foreach ($candidates as $candidate) {
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        if (is_file($candidate . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php')) {
            $root = $candidate;

            return $root;
        }
    }

    $root = $local;

    return $root;
}

function ldBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        $database = ldBalancesRoot() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (!is_file($database)) {
            throw new RuntimeException(
                'Balances bootstrap not found. Expected config/database.php under the Balances module.'
            );
        }
        require_once $database;
        $booted = true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    return $pdo;
}

function ldRequireAccess(): void
{
    ldBootstrap();
    requireLogin();
}

function ldDeskShellScriptSuffix(): string
{
    return '/index.php';
}

function ldDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $suffix = ldDeskShellScriptSuffix();
    if ($script !== '' && substr($script, -strlen($suffix)) === $suffix) {
        return rtrim(dirname($script), '/') . '/' . $relativePath;
    }

    // Script may be .../modules/balances/ or .../modules/balances/index (rewrite)
    if ($script !== '' && str_contains($script, '/modules/balances')) {
        $base = preg_replace('#/modules/balances(?:/index(?:\\.php)?)?$#', '/modules/balances', $script);
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . '/' . $relativePath;
        }
    }

    if (function_exists('app_url')) {
        return app_url('modules/balances/' . $relativePath);
    }

    return $relativePath;
}

function ldFormatValue(float $n): string
{
    $sign = $n < 0 ? '-' : '';
    return $sign . 'TZS ' . number_format(abs($n), 2, '.', ',');
}

/**
 * @return array<string, mixed>
 */
function ldBuildInitPayload(PDO $pdo): array
{
    if (!function_exists('bal_dashboard_format_value')) {
        function bal_dashboard_format_value(float $n): string
        {
            return ldFormatValue($n);
        }
    }

    $accounts = [];
    $totalLiquidity = 0.0;
    $cashTotal = 0.0;
    $bankTotal = 0.0;
    $mobileTotal = 0.0;
    $hasCash = false;
    $hasBank = false;
    $hasMobile = false;

    try {
        $accounts = function_exists('balancesFetchAccountsWithLiveBalance')
            ? balancesFetchAccountsWithLiveBalance($pdo, false)
            : [];

        foreach ($accounts as $acc) {
            if (strtolower((string) ($acc['status'] ?? 'inactive')) !== 'active') {
                continue;
            }

            $bal = isset($acc['live_balance']) ? (float) $acc['live_balance'] : (float) ($acc['current_balance'] ?? 0);
            $totalLiquidity += $bal;

            $bucket = function_exists('balancesAccountLiquidityBucket')
                ? balancesAccountLiquidityBucket((string) ($acc['type'] ?? ''))
                : strtolower(trim((string) ($acc['type'] ?? '')));

            if ($bucket === 'cash') {
                $cashTotal += $bal;
                $hasCash = true;
            } elseif ($bucket === 'bank') {
                $bankTotal += $bal;
                $hasBank = true;
            } elseif ($bucket === 'mobile') {
                $mobileTotal += $bal;
                $hasMobile = true;
            }
        }

        usort($accounts, static function ($a, $b) {
            $ba = isset($a['live_balance']) ? (float) $a['live_balance'] : (float) ($a['current_balance'] ?? 0);
            $bb = isset($b['live_balance']) ? (float) $b['live_balance'] : (float) ($b['current_balance'] ?? 0);
            return $bb <=> $ba;
        });
    } catch (Throwable $e) {
        error_log('balances liquidity-dashboard init accounts: ' . $e->getMessage());
    }

    $accountCount = count($accounts);
    $activeAccountRows = array_values(array_filter($accounts, static function ($acc) {
        return strtolower((string) ($acc['status'] ?? 'inactive')) === 'active';
    }));

    $monthStart = date('Y-m-01 00:00:00');
    $monthCredits = 0.0;
    $monthDebits = 0.0;
    $monthTxCount = 0;

    try {
        $stmtMonth = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credits,
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debits,
                COUNT(*) AS tx_count
            FROM account_transactions
            WHERE transaction_date >= ?
        ");
        $stmtMonth->execute([$monthStart]);
        $monthRow = $stmtMonth->fetch(PDO::FETCH_ASSOC) ?: [];
        $monthCredits = (float) ($monthRow['credits'] ?? 0);
        $monthDebits = (float) ($monthRow['debits'] ?? 0);
        $monthTxCount = (int) ($monthRow['tx_count'] ?? 0);
    } catch (Throwable $e) {
        error_log('balances liquidity-dashboard month flow: ' . $e->getMessage());
    }

    $monthNet = $monthCredits - $monthDebits;

    $trendLabels = [];
    $creditsTrend = [];
    $debitsTrend = [];
    $dailyMap = [];

    try {
        $stmtTrend = $pdo->query("
            SELECT DATE(transaction_date) AS d,
                   COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credits,
                   COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debits
            FROM account_transactions
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY DATE(transaction_date)
            ORDER BY d
        ");
        foreach ($stmtTrend->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dailyMap[$row['d']] = [
                'credits' => (float) $row['credits'],
                'debits' => (float) $row['debits'],
            ];
        }
    } catch (Throwable $e) {
        error_log('balances liquidity-dashboard trend: ' . $e->getMessage());
    }

    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $trendLabels[] = date('M j', strtotime($d));
        $creditsTrend[] = $dailyMap[$d]['credits'] ?? 0.0;
        $debitsTrend[] = $dailyMap[$d]['debits'] ?? 0.0;
    }

    $topAccounts = array_slice($accounts, 0, 5);
    $topAccountLabels = array_map(static fn($a) => (string) ($a['name'] ?? 'Account'), $topAccounts);
    $topAccountValues = array_map(static function ($a) {
        return isset($a['live_balance']) ? (float) $a['live_balance'] : (float) ($a['current_balance'] ?? 0);
    }, $topAccounts);
    $topAccountColorPalettes = [
        'cash' => ['#16a34a', '#22c55e', '#15803d'],
        'bank' => ['#2563eb', '#4f46e5', '#0284c7', '#1d4ed8'],
        'mobile' => ['#7c3aed', '#9333ea', '#c026d3'],
    ];
    $topAccountBucketCounts = ['cash' => 0, 'bank' => 0, 'mobile' => 0];
    $topAccountColors = array_map(static function ($a) use ($topAccountColorPalettes, &$topAccountBucketCounts) {
        $bal = isset($a['live_balance']) ? (float) $a['live_balance'] : (float) ($a['current_balance'] ?? 0);
        if ($bal < 0) {
            return '#ef4444';
        }
        $bucket = function_exists('balancesAccountLiquidityBucket')
            ? balancesAccountLiquidityBucket((string) ($a['type'] ?? ''))
            : 'bank';
        if (!isset($topAccountColorPalettes[$bucket])) {
            $bucket = 'bank';
        }
        $idx = $topAccountBucketCounts[$bucket]++;
        $palette = $topAccountColorPalettes[$bucket];
        return $palette[$idx % count($palette)];
    }, $topAccounts);

    $accountStats = ['cash' => 0, 'bank' => 0, 'mobile' => 0];
    foreach ($activeAccountRows as $acc) {
        $bucket = function_exists('balancesAccountLiquidityBucket')
            ? balancesAccountLiquidityBucket((string) ($acc['type'] ?? ''))
            : strtolower(trim((string) ($acc['type'] ?? '')));
        if (isset($accountStats[$bucket])) {
            $accountStats[$bucket]++;
        }
    }
    $activeCount = count($activeAccountRows);
    $statsPct = [
        'cash' => $activeCount > 0 ? round(($accountStats['cash'] / $activeCount) * 100, 1) : 0.0,
        'bank' => $activeCount > 0 ? round(($accountStats['bank'] / $activeCount) * 100, 1) : 0.0,
        'mobile' => $activeCount > 0 ? round(($accountStats['mobile'] / $activeCount) * 100, 1) : 0.0,
    ];

    $topAccount = $accounts[0] ?? null;
    $topAccountBalance = $topAccount
        ? (isset($topAccount['live_balance']) ? (float) $topAccount['live_balance'] : (float) ($topAccount['current_balance'] ?? 0))
        : 0.0;

    $companyDisplay = (string) ($_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company'));
    $moduleParam = (string) ($_GET['module'] ?? 'balances');
    $coaCreateUrl = ldDeskPublicUrl('coa_create.php') . '?' . http_build_query(['module' => $moduleParam]);
    $canManageAccount = (function_exists('isAdmin') && isAdmin())
        || (function_exists('isFinance') && isFinance());

    $balInsights = function_exists('balances_build_insights')
        ? balances_build_insights([
            'total_liquidity' => $totalLiquidity,
            'active_count' => count($activeAccountRows),
            'account_count' => $accountCount,
            'month_credits' => $monthCredits,
            'month_debits' => $monthDebits,
            'month_net' => $monthNet,
            'month_tx_count' => $monthTxCount,
            'has_cash' => $hasCash,
            'has_bank' => $hasBank,
            'has_mobile' => $hasMobile,
            'account_stats' => $accountStats,
            'top_account' => $topAccount,
            'top_account_balance' => $topAccountBalance,
            'active_accounts' => $activeAccountRows,
        ], $pdo)
        : ['highlights' => [], 'suggestions' => [], 'alerts' => [], 'ai_connected' => false];

    $insightSummaries = [];
    $insightMoreItems = [];
    foreach ($balInsights['highlights'] as $line) {
        $insightSummaries[] = [
            'label' => 'Summary',
            'class' => 'highlight',
            'text' => (string) $line,
            'link' => '',
        ];
    }
    foreach ($balInsights['suggestions'] as $line) {
        $insightMoreItems[] = [
            'label' => 'Suggestion',
            'class' => 'tip',
            'text' => (string) $line,
            'link' => '',
        ];
    }
    foreach ($balInsights['alerts'] as $alert) {
        $alertType = $alert['type'] ?? 'alert';
        $insightMoreItems[] = [
            'label' => $alertType === 'payment' ? 'Payment due' : ($alertType === 'warning' ? 'Balance alert' : 'Pending approval'),
            'class' => $alertType === 'payment' ? 'payment' : 'alert',
            'text' => (string) ($alert['text'] ?? ''),
            'link' => (string) ($alert['link'] ?? ''),
        ];
    }

    $insightVisible = array_slice($insightSummaries, 0, 4);
    $insightHidden = array_merge(array_slice($insightSummaries, 4), $insightMoreItems);

    $aiInsightsUrl = ldDeskPublicUrl('api/ai_insights.php');
    $qs = [];
    foreach (['module', 'company_slug'] as $key) {
        if (!empty($_GET[$key])) {
            $qs[$key] = (string) $_GET[$key];
        }
    }
    if ($qs !== []) {
        $aiInsightsUrl .= '?' . http_build_query($qs);
    }

    return [
        'todayLabel' => date('l, d M Y'),
        'companyDisplay' => $companyDisplay,
        'year' => (int) date('Y'),
        'canManageAccount' => $canManageAccount,
        'coaCreateUrl' => $coaCreateUrl,
        'aiInsightsUrl' => $aiInsightsUrl,
        'kpis' => [
            'totalLiquidity' => $totalLiquidity,
            'totalLiquidityDisplay' => ldFormatValue($totalLiquidity),
            'cashTotal' => $cashTotal,
            'cashTotalDisplay' => ldFormatValue($cashTotal),
            'bankTotal' => $bankTotal,
            'bankTotalDisplay' => ldFormatValue($bankTotal),
            'mobileTotal' => $mobileTotal,
            'mobileTotalDisplay' => ldFormatValue($mobileTotal),
            'accountCount' => count($activeAccountRows),
            'hasCash' => $hasCash,
            'hasBank' => $hasBank,
            'hasMobile' => $hasMobile,
        ],
        'trend' => [
            'labels' => $trendLabels,
            'credits' => $creditsTrend,
            'debits' => $debitsTrend,
        ],
        'accountStats' => [
            'counts' => [
                'cash' => (int) $accountStats['cash'],
                'bank' => (int) $accountStats['bank'],
                'mobile' => (int) $accountStats['mobile'],
            ],
            'pct' => $statsPct,
            // Keep chart total aligned with active accounts used in KPIs.
            'total' => count($activeAccountRows),
        ],
        'topAccounts' => [
            'labels' => $topAccountLabels,
            'values' => $topAccountValues,
            'colors' => $topAccountColors,
            'displays' => array_map('ldFormatValue', $topAccountValues),
        ],
        'insights' => [
            'aiConnected' => !empty($balInsights['ai_connected']),
            'visible' => $insightVisible,
            'hidden' => $insightHidden,
            'hiddenCount' => count($insightHidden),
        ],
    ];
}

/**
 * Absolute/public URL helper for source documents opened from liquidity drill-down.
 */
function ldAppUrl(string $path): string
{
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    if (function_exists('app_url')) {
        return (string) app_url($path);
    }
    return $path;
}

/**
 * Map ledger reference_type/reference_id to a viewable ERP document when possible.
 *
 * @return array{label:string,url:?string}|null
 */
function ldResolveTransactionSource(?string $referenceType, $referenceId): ?array
{
    $referenceType = strtolower(trim((string) $referenceType));
    $referenceId = (int) $referenceId;
    if ($referenceType === '' || $referenceId <= 0) {
        return null;
    }

    $label = ucwords(str_replace('_', ' ', $referenceType)) . ' #' . $referenceId;
    $url = null;

    if (in_array($referenceType, ['payment_voucher', 'voucher', 'expense_voucher'], true)) {
        $url = ldAppUrl('/view-voucher.php') . '?id=' . $referenceId;
        $label = 'Payment Voucher #' . $referenceId;
    } elseif (in_array($referenceType, ['revenue_entry', 'revenue', 'revenue_payment'], true)) {
        $url = ldAppUrl('/revenue_details.php') . '?id=' . $referenceId;
        $label = 'Revenue #' . $referenceId;
    } elseif (str_contains($referenceType, 'invoice')) {
        $url = ldAppUrl('/sales.php') . '?desk=invoice-view&id=' . $referenceId;
        $label = 'Invoice #' . $referenceId;
    } elseif (str_contains($referenceType, 'cash_book')) {
        $url = ldAppUrl('/cashbook.php') . '?entry_id=' . $referenceId;
        $label = 'Cash Book Entry #' . $referenceId;
    } elseif (str_contains($referenceType, 'transfer')) {
        $label = 'Internal Transfer #' . $referenceId;
        // Transfer pairs live in account_transactions; ledger view is the best available surface.
        $url = 'view-transaction.php?id=' . $referenceId;
    }

    return ['label' => $label, 'url' => $url];
}

/**
 * Active accounts that currently compose Total Liquidity (same rules as dashboard KPI).
 *
 * @return array<string, mixed>
 */
function ldBuildLiquidityAccountsPayload(PDO $pdo): array
{
    $groups = [
        'bank' => ['key' => 'bank', 'label' => 'Bank Accounts', 'accounts' => [], 'total' => 0.0],
        'cash' => ['key' => 'cash', 'label' => 'Cash on Hand', 'accounts' => [], 'total' => 0.0],
        'mobile' => ['key' => 'mobile', 'label' => 'Mobile Money', 'accounts' => [], 'total' => 0.0],
        'other' => ['key' => 'other', 'label' => 'Other Accounts', 'accounts' => [], 'total' => 0.0],
    ];
    $totalLiquidity = 0.0;
    $rows = [];

    $accounts = function_exists('balancesFetchAccountsWithLiveBalance')
        ? balancesFetchAccountsWithLiveBalance($pdo, false)
        : [];

    foreach ($accounts as $acc) {
        if (strtolower((string) ($acc['status'] ?? 'inactive')) !== 'active') {
            continue;
        }

        $id = (int) ($acc['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $bal = isset($acc['live_balance']) ? (float) $acc['live_balance'] : (float) ($acc['current_balance'] ?? 0);
        $type = strtolower(trim((string) ($acc['type'] ?? '')));
        $bucket = function_exists('balancesAccountLiquidityBucket')
            ? balancesAccountLiquidityBucket($type)
            : $type;
        if (!isset($groups[$bucket])) {
            $bucket = 'other';
        }

        $nameRaw = trim((string) ($acc['name'] ?? ''));
        $code = '';
        $displayName = $nameRaw !== '' ? $nameRaw : ('Account #' . $id);
        if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $nameRaw, $m)) {
            $code = trim($m[1]);
            $displayName = trim($m[2]);
        }

        $row = [
            'id' => $id,
            'name' => $displayName,
            'code' => $code,
            'fullName' => $nameRaw,
            'type' => $type,
            'typeLabel' => ucwords(str_replace('_', ' ', $type)),
            'bucket' => $bucket,
            'bucketLabel' => $groups[$bucket]['label'],
            'currency' => (string) ($acc['currency'] ?? 'TZS'),
            'openingBalance' => (float) ($acc['opening_balance_safe'] ?? $acc['opening_balance'] ?? 0),
            'txCredits' => (float) ($acc['tx_credits'] ?? 0),
            'txDebits' => (float) ($acc['tx_debits'] ?? 0),
            'balance' => $bal,
            'balanceDisplay' => ldFormatValue($bal),
            'parentId' => (int) ($acc['parent_id'] ?? 0),
        ];

        $groups[$bucket]['accounts'][] = $row;
        $groups[$bucket]['total'] += $bal;
        $totalLiquidity += $bal;
        $rows[] = $row;
    }

    foreach ($groups as $key => $group) {
        usort($groups[$key]['accounts'], static function (array $a, array $b): int {
            return ($b['balance'] <=> $a['balance']);
        });
        $groups[$key]['totalDisplay'] = ldFormatValue((float) $group['total']);
        $groups[$key]['accountCount'] = count($groups[$key]['accounts']);
    }

    $orderedGroups = [];
    foreach (['bank', 'cash', 'mobile', 'other'] as $key) {
        if (($groups[$key]['accountCount'] ?? 0) > 0) {
            $orderedGroups[] = $groups[$key];
        }
    }

    return [
        'totalLiquidity' => $totalLiquidity,
        'totalLiquidityDisplay' => ldFormatValue($totalLiquidity),
        'accountCount' => count($rows),
        'groups' => $orderedGroups,
        'accounts' => $rows,
        'formula' => 'live_balance = opening_balance + credits - debits (active financial_accounts)',
    ];
}

/**
 * @return array<string, mixed>|null
 */
function ldFindActiveAccount(PDO $pdo, int $accountId): ?array
{
    if ($accountId <= 0 || !function_exists('balancesFetchAccountsWithLiveBalance')) {
        return null;
    }
    foreach (balancesFetchAccountsWithLiveBalance($pdo, false) as $acc) {
        if ((int) ($acc['id'] ?? 0) !== $accountId) {
            continue;
        }
        if (strtolower((string) ($acc['status'] ?? 'inactive')) !== 'active') {
            // Still allow viewing inactive accounts from deep links, but mark status.
        }
        return $acc;
    }
    return null;
}

/**
 * Monthly opening / money-in / money-out / closing for one account.
 *
 * @return array<string, mixed>
 */
function ldBuildAccountMonthsPayload(PDO $pdo, int $accountId): array
{
    $account = ldFindActiveAccount($pdo, $accountId);
    if ($account === null) {
        throw new RuntimeException('Account not found.');
    }

    $nameRaw = trim((string) ($account['name'] ?? ''));
    $displayName = $nameRaw !== '' ? $nameRaw : ('Account #' . $accountId);
    $code = '';
    if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $nameRaw, $m)) {
        $code = trim($m[1]);
        $displayName = trim($m[2]);
    }

    $type = strtolower(trim((string) ($account['type'] ?? '')));
    $bucket = function_exists('balancesAccountLiquidityBucket')
        ? balancesAccountLiquidityBucket($type)
        : $type;
    $openingBalance = (float) ($account['opening_balance_safe'] ?? $account['opening_balance'] ?? 0);
    $liveBalance = isset($account['live_balance'])
        ? (float) $account['live_balance']
        : (float) ($account['current_balance'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
            COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS money_in,
            COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS money_out,
            COUNT(*) AS tx_count
        FROM account_transactions
        WHERE account_id = ?
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ORDER BY ym ASC
    ");
    $stmt->execute([$accountId]);
    $monthRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Prefetch cumulative activity before each month for opening balances.
    $months = [];
    $runningOpen = $openingBalance;
    foreach ($monthRows as $row) {
        $ym = (string) ($row['ym'] ?? '');
        if ($ym === '') {
            continue;
        }
        $moneyIn = (float) ($row['money_in'] ?? 0);
        $moneyOut = (float) ($row['money_out'] ?? 0);
        $closing = $runningOpen + $moneyIn - $moneyOut;
        $months[] = [
            'ym' => $ym,
            'label' => date('F Y', strtotime($ym . '-01')),
            'openingBalance' => $runningOpen,
            'openingBalanceDisplay' => ldFormatValue($runningOpen),
            'moneyIn' => $moneyIn,
            'moneyInDisplay' => ldFormatValue($moneyIn),
            'moneyOut' => $moneyOut,
            'moneyOutDisplay' => ldFormatValue($moneyOut),
            'closingBalance' => $closing,
            'closingBalanceDisplay' => ldFormatValue($closing),
            'transactionCount' => (int) ($row['tx_count'] ?? 0),
        ];
        $runningOpen = $closing;
    }

    // If there are no transactions, still expose opening as current.
    if ($months === []) {
        $months[] = [
            'ym' => date('Y-m'),
            'label' => date('F Y'),
            'openingBalance' => $openingBalance,
            'openingBalanceDisplay' => ldFormatValue($openingBalance),
            'moneyIn' => 0.0,
            'moneyInDisplay' => ldFormatValue(0),
            'moneyOut' => 0.0,
            'moneyOutDisplay' => ldFormatValue(0),
            'closingBalance' => $openingBalance,
            'closingBalanceDisplay' => ldFormatValue($openingBalance),
            'transactionCount' => 0,
        ];
    }

    $lastClose = (float) ($months[count($months) - 1]['closingBalance'] ?? $openingBalance);

    return [
        'account' => [
            'id' => $accountId,
            'name' => $displayName,
            'code' => $code,
            'fullName' => $nameRaw,
            'type' => $type,
            'typeLabel' => ucwords(str_replace('_', ' ', $type)),
            'bucket' => $bucket,
            'bucketLabel' => $bucket === 'cash' ? 'Cash on Hand' : ($bucket === 'mobile' ? 'Mobile Money' : ($bucket === 'bank' ? 'Bank Accounts' : 'Other')),
            'currency' => (string) ($account['currency'] ?? 'TZS'),
            'status' => strtolower((string) ($account['status'] ?? 'inactive')),
            'openingBalance' => $openingBalance,
            'openingBalanceDisplay' => ldFormatValue($openingBalance),
            'balance' => $liveBalance,
            'balanceDisplay' => ldFormatValue($liveBalance),
            'reconcilingClose' => $lastClose,
            'reconcilingCloseDisplay' => ldFormatValue($lastClose),
            'balanceMatchesLedger' => abs($liveBalance - $lastClose) < 0.005,
        ],
        'months' => array_reverse($months), // newest first for UI
        'monthsChronological' => $months,
    ];
}

/**
 * @return array<string, mixed>
 */
function ldBuildMonthTransactionsPayload(PDO $pdo, int $accountId, string $ym, array $filters = []): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        throw new InvalidArgumentException('Invalid month. Use YYYY-MM.');
    }

    $accountPayload = ldBuildAccountMonthsPayload($pdo, $accountId);
    $monthMeta = null;
    foreach ($accountPayload['monthsChronological'] as $m) {
        if (($m['ym'] ?? '') === $ym) {
            $monthMeta = $m;
            break;
        }
    }
    if ($monthMeta === null) {
        // Empty month still allowed - opening equals previous close / account opening.
        $opening = (float) ($accountPayload['account']['openingBalance'] ?? 0);
        foreach ($accountPayload['monthsChronological'] as $m) {
            if (($m['ym'] ?? '') < $ym) {
                $opening = (float) ($m['closingBalance'] ?? $opening);
            }
        }
        $monthMeta = [
            'ym' => $ym,
            'label' => date('F Y', strtotime($ym . '-01')),
            'openingBalance' => $opening,
            'openingBalanceDisplay' => ldFormatValue($opening),
            'moneyIn' => 0.0,
            'moneyInDisplay' => ldFormatValue(0),
            'moneyOut' => 0.0,
            'moneyOutDisplay' => ldFormatValue(0),
            'closingBalance' => $opening,
            'closingBalanceDisplay' => ldFormatValue($opening),
            'transactionCount' => 0,
        ];
    }

    $start = $ym . '-01 00:00:00';
    $end = date('Y-m-d 23:59:59', strtotime('last day of ' . $ym . '-01'));

    $where = ['t.account_id = ?', 't.transaction_date >= ?', 't.transaction_date <= ?'];
    $params = [$accountId, $start, $end];

    $typeFilter = strtolower(trim((string) ($filters['type'] ?? '')));
    if (in_array($typeFilter, ['credit', 'debit'], true)) {
        $where[] = 't.type = ?';
        $params[] = $typeFilter;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(IFNULL(t.description, "") LIKE ? OR IFNULL(t.reference_type, "") LIKE ? OR CAST(t.id AS CHAR) LIKE ? OR CAST(IFNULL(t.reference_id, 0) AS CHAR) LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql = '
        SELECT t.*, u.full_name AS user_name
        FROM account_transactions t
        LEFT JOIN users u ON t.created_by = u.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY t.transaction_date ASC, t.id ASC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $running = (float) ($monthMeta['openingBalance'] ?? 0);
    $transactions = [];
    foreach ($raw as $tx) {
        $isCredit = strtolower((string) ($tx['type'] ?? '')) === 'credit';
        $amount = (float) ($tx['amount'] ?? 0);
        $running += $isCredit ? $amount : -$amount;
        $refType = (string) ($tx['reference_type'] ?? '');
        $refId = isset($tx['reference_id']) ? (int) $tx['reference_id'] : 0;
        $source = ldResolveTransactionSource($refType, $refId);

        $transactions[] = [
            'id' => (int) ($tx['id'] ?? 0),
            'transactionDate' => (string) ($tx['transaction_date'] ?? ''),
            'description' => (string) ($tx['description'] ?? ''),
            'type' => $isCredit ? 'credit' : 'debit',
            'typeLabel' => $isCredit ? 'Credit (Money In)' : 'Debit (Money Out)',
            'amount' => $amount,
            'debit' => $isCredit ? 0.0 : $amount,
            'credit' => $isCredit ? $amount : 0.0,
            'debitDisplay' => $isCredit ? '-' : ldFormatValue($amount),
            'creditDisplay' => $isCredit ? ldFormatValue($amount) : '-',
            'amountDisplay' => ldFormatValue($amount),
            'runningBalance' => $running,
            'runningBalanceDisplay' => ldFormatValue($running),
            'referenceType' => $refType,
            'referenceId' => $refId > 0 ? $refId : null,
            'referenceLabel' => $source['label'] ?? ($refType !== '' ? ucwords(str_replace('_', ' ', $refType)) : ''),
            'sourceUrl' => $source['url'] ?? null,
            'createdBy' => (string) ($tx['user_name'] ?? 'System'),
            'viewUrl' => 'view-transaction.php?id=' . (int) ($tx['id'] ?? 0),
        ];
    }

    return [
        'account' => $accountPayload['account'],
        'month' => $monthMeta,
        'filters' => [
            'type' => $typeFilter,
            'q' => $q,
        ],
        'transactions' => $transactions,
        'transactionCount' => count($transactions),
    ];
}

/**
 * @return array<string, mixed>
 */
function ldBuildTransactionDetailPayload(PDO $pdo, int $txId): array
{
    if ($txId <= 0) {
        throw new InvalidArgumentException('Invalid transaction id.');
    }

    $stmt = $pdo->prepare('
        SELECT t.*, a.name AS account_name, a.type AS account_type, a.currency, u.full_name AS user_name
        FROM account_transactions t
        JOIN financial_accounts a ON t.account_id = a.id
        LEFT JOIN users u ON t.created_by = u.id
        WHERE t.id = ?
        LIMIT 1
    ');
    $stmt->execute([$txId]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        throw new RuntimeException('Transaction not found.');
    }

    $isCredit = strtolower((string) ($tx['type'] ?? '')) === 'credit';
    $amount = (float) ($tx['amount'] ?? 0);
    $refType = (string) ($tx['reference_type'] ?? '');
    $refId = isset($tx['reference_id']) ? (int) $tx['reference_id'] : 0;
    $source = ldResolveTransactionSource($refType, $refId);
    $accountId = (int) ($tx['account_id'] ?? 0);
    $txDate = (string) ($tx['transaction_date'] ?? '');
    $ym = $txDate !== '' ? date('Y-m', strtotime($txDate)) : date('Y-m');

    // Running balance after this transaction on the account.
    $balStmt = $pdo->prepare("
        SELECT
            (SELECT COALESCE(opening_balance, 0) FROM financial_accounts WHERE id = ?) +
            COALESCE((
                SELECT SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END)
                FROM account_transactions
                WHERE account_id = ?
                  AND (transaction_date < ? OR (transaction_date = ? AND id <= ?))
            ), 0) AS running_balance
    ");
    $balStmt->execute([$accountId, $accountId, $txDate, $txDate, $txId]);
    $runningBalance = (float) ($balStmt->fetchColumn() ?: 0);

    return [
        'transaction' => [
            'id' => $txId,
            'accountId' => $accountId,
            'accountName' => (string) ($tx['account_name'] ?? ''),
            'accountType' => (string) ($tx['account_type'] ?? ''),
            'currency' => (string) ($tx['currency'] ?? 'TZS'),
            'transactionDate' => $txDate,
            'ym' => $ym,
            'monthLabel' => date('F Y', strtotime($ym . '-01')),
            'description' => (string) ($tx['description'] ?? ''),
            'type' => $isCredit ? 'credit' : 'debit',
            'typeLabel' => $isCredit ? 'Credit (Money In)' : 'Debit (Money Out)',
            'amount' => $amount,
            'amountDisplay' => ldFormatValue($amount),
            'referenceType' => $refType,
            'referenceId' => $refId > 0 ? $refId : null,
            'referenceLabel' => $source['label'] ?? '',
            'sourceUrl' => $source['url'] ?? null,
            'createdBy' => (string) ($tx['user_name'] ?? 'System'),
            'runningBalance' => $runningBalance,
            'runningBalanceDisplay' => ldFormatValue($runningBalance),
            'viewUrl' => 'view-transaction.php?id=' . $txId,
            'whyIncluded' => $isCredit
                ? 'This credit increases the account live balance (money in).'
                : 'This debit decreases the account live balance (money out).',
        ],
    ];
}
