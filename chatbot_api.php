<?php
// Chatbot API: prefers AI answers when enabled, falls back to local guides.
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/chatbot_guides.php';
require_once __DIR__ . '/includes/ai_helpers.php';

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if ($q === '' && isset($_POST['q'])) {
    $q = trim((string) $_POST['q']);
}

if ($q === '') {
    echo json_encode(['ok' => true, 'query' => '', 'results' => [], 'message' => 'Empty query']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$companyId = (int) ($_SESSION['company_id'] ?? 0);
if ($companyId <= 0 && function_exists('currentCompanyId')) {
    $companyId = (int) currentCompanyId();
}

$aiEnabled = false;
$aiError = null;
try {
    $settings = function_exists('ai_fetch_settings_row') ? ai_fetch_settings_row() : null;
    $aiEnabled = $settings && (int) ($settings['is_enabled'] ?? 0) === 1;
} catch (Throwable $e) {
    $aiEnabled = false;
}

// Prefer AI when the company has Ultimate Intelligence enabled
if ($aiEnabled) {
    try {
        if ($companyId <= 0) {
            throw new RuntimeException('Company context is required for AI answers.');
        }
        $aiRes = ai_handle_ask($userId, $companyId, $q, 'chatbot');
        if (!empty($aiRes['answer'])) {
            echo json_encode([
                'ok' => true,
                'query' => $q,
                'source' => 'ai',
                'results' => [
                    [
                        'id' => 'ai_answer',
                        'title' => 'Ultimate Intelligence',
                        'answer' => $aiRes['answer'],
                        'is_ai' => true,
                    ],
                ],
                'usage' => $aiRes['usage'] ?? null,
            ]);
            exit;
        }
    } catch (Throwable $e) {
        $aiError = $e->getMessage();
        error_log('chatbot_api.php AI error: ' . $e->getMessage());
        // Fall through to local guides
    }
}

$matches = chatbot_search_guides($q);
if (!empty($matches)) {
    foreach ($matches as &$m) {
        $m['answer_short'] = substr($m['answer'], 0, 240) . (strlen($m['answer']) > 240 ? '…' : '');
        $m['is_ai'] = false;
    }
    unset($m);

    echo json_encode([
        'ok' => true,
        'query' => $q,
        'source' => 'guides',
        'results' => $matches,
        'ai_error' => $aiError,
    ]);
    exit;
}

$message = 'No direct matches. Try keywords like "create voucher", "budget types", or "approvals".';
if ($aiEnabled && $aiError) {
    $message = 'AI could not answer right now (' . $aiError . '). Try again in a moment.';
} elseif (!$aiEnabled) {
    $message = 'AI assistant is not enabled for this company. Ask an admin to enable Ultimate Intelligence, or try keywords like "create voucher".';
}

echo json_encode([
    'ok' => true,
    'query' => $q,
    'source' => 'none',
    'results' => [],
    'message' => $message,
    'ai_error' => $aiError,
]);
