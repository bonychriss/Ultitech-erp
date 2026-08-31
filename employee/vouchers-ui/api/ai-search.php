<?php
/**
 * AI-assisted search for the employee "My Vouchers" React desk.
 * Converts a natural-language query into structured voucher filters
 * (search term, status, date range, prefix, sort) using the system OpenAI proxy.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/ai_helpers.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $query = trim((string) ($input['query'] ?? ($_GET['query'] ?? '')));

    if ($query === '') {
        echo json_encode(['ok' => false, 'error' => 'Please type what you are looking for.']);
        exit;
    }

    // Confirm AI is configured + enabled before attempting a request.
    $settings = function_exists('ai_fetch_settings_row') ? ai_fetch_settings_row() : null;
    if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
        echo json_encode([
            'ok' => false,
            'available' => false,
            'error' => 'AI assistant is not configured. Using standard search instead.',
        ]);
        exit;
    }

    $companyId = (int) (currentCompanyId() ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if (function_exists('ai_check_company_limit') && $companyId > 0) {
        $limit = ai_check_company_limit($companyId);
        if (empty($limit['allowed'])) {
            echo json_encode(['ok' => false, 'error' => 'Daily AI limit reached. Using standard search instead.']);
            exit;
        }
    }

    // Provide prefixes as context so the model can map e.g. "PV vouchers".
    $prefixLabels = [];
    if (function_exists('fetchPaymentVoucherPrefixFilterOptions') && $pdo instanceof PDO) {
        foreach (fetchPaymentVoucherPrefixFilterOptions($pdo, $companyId) as $opt) {
            $val = (string) ($opt['value'] ?? '');
            if ($val !== '') {
                $prefixLabels[] = $val;
            }
        }
    }

    $today = date('Y-m-d');
    $prefixList = $prefixLabels ? implode(', ', array_slice($prefixLabels, 0, 30)) : '(none)';

    $system = 'You convert a user\'s natural-language request into JSON filters for a Payment Vouchers list. '
        . "Today's date is {$today}. Available voucher-number prefixes: {$prefixList}. "
        . 'Return ONLY a compact JSON object (no markdown, no prose) with EXACTLY these keys: '
        . '"search" (string: free-text keywords such as a payee name, username, description, voucher number, or month name; empty if not applicable), '
        . '"status" (one of "", "pending", "approved", "rejected"), '
        . '"from_date" (YYYY-MM-DD or ""), "to_date" (YYYY-MM-DD or ""), '
        . '"prefix" (one of the available prefixes, or "" for all), '
        . '"sort" (one of "newest", "asc", "voucher_no"), '
        . '"note" (a short human sentence describing what will be shown). '
        . 'Interpret relative dates (e.g. "last month", "this year", "May 2026") into concrete from_date/to_date. '
        . 'If the user names a person, put it in "search". Never invent data.';

    $result = ai_openai_request([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $query],
    ]);

    $content = (string) ($result['content'] ?? '');
    // Strip code fences if the model added them.
    $content = preg_replace('/^```(?:json)?|```$/m', '', trim($content));
    $content = trim($content);

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        // Fallback: attempt to extract the first {...} block.
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }
    if (!is_array($parsed)) {
        echo json_encode(['ok' => false, 'error' => 'Could not interpret that request. Try rephrasing.']);
        exit;
    }

    $allowedStatus = ['', 'pending', 'approved', 'rejected'];
    $allowedSort = ['newest', 'asc', 'voucher_no'];
    $dateRe = '/^\d{4}-\d{2}-\d{2}$/';

    $status = strtolower(trim((string) ($parsed['status'] ?? '')));
    if (!in_array($status, $allowedStatus, true)) {
        $status = '';
    }
    $sort = strtolower(trim((string) ($parsed['sort'] ?? 'newest')));
    if (!in_array($sort, $allowedSort, true)) {
        $sort = 'newest';
    }
    $fromDate = trim((string) ($parsed['from_date'] ?? ''));
    if (!preg_match($dateRe, $fromDate)) {
        $fromDate = '';
    }
    $toDate = trim((string) ($parsed['to_date'] ?? ''));
    if (!preg_match($dateRe, $toDate)) {
        $toDate = '';
    }
    $prefix = trim((string) ($parsed['prefix'] ?? ''));
    if ($prefix !== '' && $prefixLabels && !in_array($prefix, $prefixLabels, true)) {
        $prefix = '';
    }

    $filters = [
        'search' => trim((string) ($parsed['search'] ?? '')),
        'status' => $status,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'prefix' => $prefix,
        'sort' => $sort,
    ];
    $note = trim((string) ($parsed['note'] ?? ''));

    if (function_exists('ai_estimate_cost') && function_exists('ai_log_usage') && $companyId > 0) {
        $cost = ai_estimate_cost((int) $result['prompt_tokens'], (int) $result['completion_tokens'], (string) $result['model']);
        ai_log_usage($companyId, $userId, 'vouchers', 'search', (int) $result['prompt_tokens'], (int) $result['completion_tokens'], $cost);
    }

    echo json_encode(['ok' => true, 'filters' => $filters, 'note' => $note], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('employee/vouchers-ui/api/ai-search.php failed: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'AI search is unavailable right now. Using standard search instead.']);
}
