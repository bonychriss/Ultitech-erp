<?php
/**
 * Admin attendance settings API (JSON) for React desk.
 */
require_once dirname(__DIR__) . '/../includes/functions.php';
require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/classes/Attendance.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please sign in again.']);
    exit;
}
if (!function_exists('isAdmin') || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (function_exists('ensureAttendanceClockModuleSchema')) {
    ensureAttendanceClockModuleSchema();
}

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'data' => attendanceSettingsFetchPayload($pdo),
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = [];
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if ($input === [] && !empty($_POST)) {
    $input = $_POST;
}

$action = trim((string) ($input['action'] ?? 'save'));

try {
    if ($action === 'add_current_ip') {
        $attendance = new Attendance($pdo);
        $currentIp = (string) $attendance->getCurrentUserIp();
        if ($attendance->rememberOfficeIp($currentIp)) {
            echo json_encode([
                'success' => true,
                'message' => 'Added current office IP: ' . $currentIp,
                'data' => attendanceSettingsFetchPayload($pdo),
            ]);
            exit;
        }
        if ($attendance->isIpAllowed($currentIp)) {
            echo json_encode([
                'success' => true,
                'message' => 'Current IP (' . $currentIp . ') is already allowed.',
                'data' => attendanceSettingsFetchPayload($pdo),
            ]);
            exit;
        }
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Could not add current IP (' . $currentIp . ').',
            'data' => attendanceSettingsFetchPayload($pdo),
        ]);
        exit;
    }

    $result = attendanceSettingsSave($pdo, $input);
    if (!$result['success']) {
        http_response_code(400);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('admin-settings.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
