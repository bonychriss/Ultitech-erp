<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../../includes/ai_helpers.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('balances_ai_is_connected') || !balances_ai_is_connected()) {
    echo json_encode(['success' => false, 'ai_connected' => false, 'error' => 'AI is not connected']);
    exit;
}

$companyId = (int) (currentCompanyId() ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$limitCheck = ai_check_company_limit($companyId);
if (empty($limitCheck['allowed'])) {
    echo json_encode([
        'success' => false,
        'ai_connected' => true,
        'error' => 'Daily AI usage limit reached for your company.',
    ]);
    exit;
}

$accounts = function_exists('balancesFetchAccountsWithLiveBalance')
    ? balancesFetchAccountsWithLiveBalance($pdo, false)
    : [];
$activeAccounts = array_values(array_filter($accounts, static function ($acc) {
    return strtolower((string) ($acc['status'] ?? 'inactive')) === 'active';
}));

$totalLiquidity = 0.0;
$cashTotal = 0.0;
$bankTotal = 0.0;
$mobileTotal = 0.0;
foreach ($activeAccounts as $acc) {
    $bal = isset($acc['live_balance']) ? (float) $acc['live_balance'] : (float) ($acc['current_balance'] ?? 0);
    $totalLiquidity += $bal;
    $bucket = balancesAccountLiquidityBucket((string) ($acc['type'] ?? ''));
    if ($bucket === 'cash') {
        $cashTotal += $bal;
    } elseif ($bucket === 'mobile') {
        $mobileTotal += $bal;
    } else {
        $bankTotal += $bal;
    }
}

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
    error_log('balances ai_insights month: ' . $e->getMessage());
}

$alerts = balances_fetch_operational_alerts($pdo, $activeAccounts);
$alertTexts = array_map(static fn($a) => $a['text'] ?? '', $alerts);

$accountLines = [];
foreach (array_slice($activeAccounts, 0, 8) as $acc) {
    $bal = isset($acc['live_balance']) ? (float) $acc['live_balance'] : (float) ($acc['current_balance'] ?? 0);
    $accountLines[] = ($acc['name'] ?? 'Account') . ': TZS ' . number_format($bal, 0);
}

$context = [
    'total_liquidity_tzs' => round($totalLiquidity, 2),
    'cash_tzs' => round($cashTotal, 2),
    'bank_tzs' => round($bankTotal, 2),
    'mobile_tzs' => round($mobileTotal, 2),
    'active_accounts' => count($activeAccounts),
    'month_credits_tzs' => round($monthCredits, 2),
    'month_debits_tzs' => round($monthDebits, 2),
    'month_transactions' => $monthTxCount,
    'accounts' => $accountLines,
    'operational_alerts' => $alertTexts,
];

try {
    $systemPrompt = 'You are a finance assistant for a Tanzania ERP liquidity dashboard. '
        . 'Respond with 3 to 5 short bullet insights only (one line each, no numbering). '
        . 'Focus on: payments to make, pending voucher approvals, negative balances, liquidity concentration, and cash flow. '
        . 'Use TZS for amounts. Be specific and actionable. Do not repeat alerts verbatim if already listed.';

    $result = ai_openai_request([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => 'Analyze this liquidity data and suggest actions:\n' . json_encode($context, JSON_UNESCAPED_UNICODE)],
    ]);

    $content = trim((string) ($result['content'] ?? ''));
    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $suggestions = [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/^[\-\*�\d\.\)\s]+/', '', $line));
        if ($line !== '') {
            $suggestions[] = $line;
        }
    }
    $suggestions = array_slice($suggestions, 0, 6);

    if (function_exists('ai_log_usage')) {
        $model = $result['model'] ?? 'gpt-4o-mini';
        $cost = ai_estimate_cost(
            (int) ($result['prompt_tokens'] ?? 0),
            (int) ($result['completion_tokens'] ?? 0),
            $model
        );
        ai_log_usage(
            $companyId,
            $userId,
            'balances',
            'insights',
            (int) ($result['prompt_tokens'] ?? 0),
            (int) ($result['completion_tokens'] ?? 0),
            $cost
        );
    }

    if (function_exists('ai_log_chat') && $content !== '') {
        ai_log_chat($companyId, $userId, 'balances', 'liquidity dashboard insights', $content);
    }

    echo json_encode([
        'success' => true,
        'ai_connected' => true,
        'suggestions' => $suggestions,
    ]);
} catch (Throwable $e) {
    error_log('balances ai_insights: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'ai_connected' => true,
        'error' => 'Could not generate AI insights.',
    ]);
}
