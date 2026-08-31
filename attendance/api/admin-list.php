<?php
/**
 * Admin attendance list API (JSON) for React desk.
 */
require_once dirname(__DIR__) . '/../includes/functions.php';
require_once dirname(__DIR__) . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!function_exists('requireAdmin')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Auth helpers missing.']);
    exit;
}

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (function_exists('ensureAttendanceClockModuleSchema')) {
    ensureAttendanceClockModuleSchema();
}

$payload = attendanceAdminFetchPayload($pdo, [
    'date' => isset($_GET['date']) ? trim((string) $_GET['date']) : '',
    'user_id' => $_GET['user_id'] ?? 0,
    'module' => $_GET['module'] ?? 'attendance',
]);

echo json_encode([
    'success' => true,
    'data' => $payload,
]);
