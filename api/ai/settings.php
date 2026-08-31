<?php
/**
 * AI settings API � Super Admin only. Returns masked key never plaintext.
 */
ob_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai_helpers.php';

if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

requireAdmin();

$pdo = ai_pdo();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}

if (!ensureAiSchema($pdo)) {
    echo json_encode(['success' => false, 'error' => 'Could not initialize AI tables']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
}

$action = $_GET['action'] ?? ($input['action'] ?? 'get');

try {
    switch ($action) {
        case 'get':
            echo json_encode([
                'success' => true,
                'settings' => ai_settings_for_api(),
                'usage_report' => ai_usage_report_by_company(30),
            ]);
            break;

        case 'save':
            if (!function_exists('verify_csrf') || !verify_csrf($input['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {
                if (empty($input['csrf_token']) && empty($_POST['csrf_token'])) {
                    /* allow JSON API without csrf if session-only super admin */
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
                    exit;
                }
            }
            ai_save_settings([
                'api_key' => $input['api_key'] ?? '',
                'model_name' => $input['model_name'] ?? 'gpt-4o-mini',
                'is_enabled' => !empty($input['is_enabled']),
                'daily_limit' => (int) ($input['daily_limit'] ?? 500),
            ], $userId);
            echo json_encode([
                'success' => true,
                'message' => 'AI settings saved',
                'settings' => ai_settings_for_api(),
            ]);
            break;

        case 'test':
            $test = ai_test_connection();
            echo json_encode([
                'success' => (bool) $test['success'],
                'message' => $test['message'],
                'tokens' => $test['tokens'],
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
