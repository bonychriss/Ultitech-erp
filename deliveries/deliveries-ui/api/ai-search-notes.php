<?php

declare(strict_types=1);

/**
 * AI-assisted search for Delivery Notes list.
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/ai_helpers.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $query = trim((string) ($input['query'] ?? ($_GET['query'] ?? '')));

    if ($query === '') {
        echo json_encode(['ok' => false, 'error' => 'Please type what you are looking for.']);
        exit;
    }

    $settings = function_exists('ai_fetch_settings_row') ? ai_fetch_settings_row() : null;
    if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
        echo json_encode([
            'ok' => false,
            'available' => false,
            'error' => 'AI assistant is not configured. Using standard search instead.',
        ]);
        exit;
    }

    $companyId = 0;
    if (function_exists('currentCompanyId')) {
        $companyId = (int) currentCompanyId();
    }
    if ($companyId <= 0) {
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
    }
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if (function_exists('ai_check_company_limit') && $companyId > 0) {
        $limit = ai_check_company_limit($companyId);
        if (empty($limit['allowed'])) {
            echo json_encode(['ok' => false, 'error' => 'Daily AI limit reached. Using standard search instead.']);
            exit;
        }
    }

    $today = date('Y-m-d');

    $system = 'You convert a user\'s natural-language request into JSON filters for a Delivery Notes list. '
        . "Today's date is {$today}. "
        . 'Return ONLY a compact JSON object (no markdown, no prose) with EXACTLY these keys: '
        . '"search" (string: free-text keywords such as note number like DN-1234, customer name, phone, destination address, creator name; empty if not applicable), '
        . '"creator" (string: creator/staff name to match, or ""), '
        . '"from_date" (YYYY-MM-DD or ""), "to_date" (YYYY-MM-DD or ""), '
        . '"min_items" (integer >= 0 or 0 if not applicable), '
        . '"note" (a short human sentence describing what will be shown). '
        . 'Interpret relative dates (e.g. "last month", "this week", "March 2026") into concrete from_date/to_date based on delivery date or created date. '
        . 'If the user names a customer or destination, put it in "search". Never invent data.';

    $result = ai_openai_request([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $query],
    ]);

    $content = (string) ($result['content'] ?? '');
    $content = preg_replace('/^```(?:json)?|```$/m', '', trim($content));
    $content = trim($content);

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }
    if (!is_array($parsed)) {
        echo json_encode(['ok' => false, 'error' => 'Could not interpret that request. Try rephrasing.']);
        exit;
    }

    $dateRe = '/^\d{4}-\d{2}-\d{2}$/';

    $fromDate = trim((string) ($parsed['from_date'] ?? ''));
    if (!preg_match($dateRe, $fromDate)) {
        $fromDate = '';
    }
    $toDate = trim((string) ($parsed['to_date'] ?? ''));
    if (!preg_match($dateRe, $toDate)) {
        $toDate = '';
    }

    $minItems = (int) ($parsed['min_items'] ?? 0);
    if ($minItems < 0) {
        $minItems = 0;
    }

    $filters = [
        'search' => trim((string) ($parsed['search'] ?? '')),
        'creator' => trim((string) ($parsed['creator'] ?? '')),
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'min_items' => $minItems,
    ];
    $note = trim((string) ($parsed['note'] ?? ''));

    if (function_exists('ai_estimate_cost') && function_exists('ai_log_usage') && $companyId > 0) {
        $cost = ai_estimate_cost((int) $result['prompt_tokens'], (int) $result['completion_tokens'], (string) $result['model']);
        ai_log_usage($companyId, $userId, 'deliveries', 'search_notes', (int) $result['prompt_tokens'], (int) $result['completion_tokens'], $cost);
    }

    echo json_encode(['ok' => true, 'filters' => $filters, 'note' => $note], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('deliveries/deliveries-ui/api/ai-search-notes.php failed: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'AI search is unavailable right now. Using standard search instead.']);
}
