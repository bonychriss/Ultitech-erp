<?php

require_once __DIR__ . '/../includes/settings-lib.php';

settingsDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

global $pdo;

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $month = isset($_GET['month']) ? (string) $_GET['month'] : date('Y-m');
        echo json_encode(sales_settings_monthly_targets($pdo, $month), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $month = (string) ($payload['month'] ?? '');
        $rows = is_array($payload['targets'] ?? null) ? $payload['targets'] : [];
        $mode = (string) ($payload['mode'] ?? 'all') === 'each' ? 'each' : 'all';
        $scope = (string) ($payload['scope'] ?? 'month');
        if (!in_array($scope, ['month', 'ongoing', 'year'], true)) {
            $scope = 'month';
        }
        $shared = (string) ($payload['shared_amount'] ?? '');
        $saved = sales_settings_save_monthly_targets($pdo, $month, $rows, $mode, $shared, $scope);
        echo json_encode(['success' => true] + $saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
