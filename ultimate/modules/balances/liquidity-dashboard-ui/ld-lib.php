<?php
/**
 * Liquidity Dashboard � shared backend helpers for React API + shell.
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
    foreach ($accounts as $acc) {
        $bucket = function_exists('balancesAccountLiquidityBucket')
            ? balancesAccountLiquidityBucket((string) ($acc['type'] ?? ''))
            : strtolower(trim((string) ($acc['type'] ?? '')));
        if (isset($accountStats[$bucket])) {
            $accountStats[$bucket]++;
        }
    }
    $statsPct = [
        'cash' => $accountCount > 0 ? round(($accountStats['cash'] / $accountCount) * 100, 1) : 0.0,
        'bank' => $accountCount > 0 ? round(($accountStats['bank'] / $accountCount) * 100, 1) : 0.0,
        'mobile' => $accountCount > 0 ? round(($accountStats['mobile'] / $accountCount) * 100, 1) : 0.0,
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
            'accountCount' => $accountCount,
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
            'total' => $accountCount,
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
