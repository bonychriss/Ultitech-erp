<?php
/**
 * AI ask endpoint � all authenticated users. Company-scoped; never calls OpenAI from browser.
 */
ob_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai_helpers.php';

if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);

$raw = file_get_contents('php://input');
$input = [];
if ($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if (!$input && !empty($_POST)) {
    $input = $_POST;
}

$question = trim((string) ($input['question'] ?? ''));
$module = trim((string) ($input['module'] ?? 'general'));

try {
    if (!ensureAiSchema(ai_pdo())) {
        throw new RuntimeException('AI module is not available');
    }
    $settings = ai_fetch_settings_row();
    if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
        throw new RuntimeException('AI assistant is currently disabled. Contact your administrator.');
    }

    $result = ai_handle_ask($userId, $companyId, $question, $module);
    echo json_encode(array_merge(['success' => true], $result));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $code = strpos($e->getMessage(), 'daily AI limit') !== false ? 429 : 400;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/ai/ask: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not process your question. Please try again later.']);
}
