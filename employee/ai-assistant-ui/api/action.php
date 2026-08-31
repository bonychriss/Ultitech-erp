<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/ai_assistant_helper.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$companyId = (int) currentCompanyId();
$role = 'employee';

$apiConfig = ai_settings_for_api();
if (empty($apiConfig['is_enabled'])) {
    echo json_encode(['success' => false, 'error' => 'AI Assistant is currently disabled.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = '';
$params = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['ajax_action'])) {
        $action = (string) $_POST['ajax_action'];
        $params = is_array($_POST['params'] ?? null) ? $_POST['params'] : [];
    } else {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $action = (string) ($body['action'] ?? $body['ajax_action'] ?? '');
            $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        }
    }
}

if ($action === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing action.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (in_array($action, ['predict_growth', 'scan_errors', 'smart_report', 'full_system_report'], true)) {
    echo json_encode(['success' => false, 'error' => 'Access denied. You do not have permission to execute this request.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $response = ai_assistant_handle_action($pdo, $userId, $companyId, $role, $action, $params);
    $response = ai_assistant_sanitize_utf8_recursive($response);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
