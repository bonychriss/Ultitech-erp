<?php
/**
 * Attendance clock actions (JSON API for React desk).
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Attendance.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === []) {
    $payload = $_POST;
}

$action = (string) ($payload['action'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$attendance = new Attendance($pdo);

function attendance_pending_tasks(PDO $pdo, int $userId): array
{
    try {
        if (function_exists('tableExists') && !tableExists('user_tasks', $pdo)) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id, task_description, task_date FROM user_tasks WHERE user_id = ? AND is_completed = 0 ORDER BY task_date ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('attendance api pending tasks: ' . $e->getMessage());
        return [];
    }
}

function attendance_desk_payload(Attendance $attendance, PDO $pdo, int $userId): array
{
    $currentIp = $attendance->getCurrentUserIp();
    return [
        'todayRecord' => $attendance->getTodayRecord($userId) ?: null,
        'history' => $attendance->getHistory($userId) ?: [],
        'stats' => $attendance->getStats($userId) ?: [],
        'pendingTasks' => attendance_pending_tasks($pdo, $userId),
        'isIpAllowed' => $attendance->isIpAllowed($currentIp),
        'currentIp' => $currentIp,
    ];
}

$lat = isset($payload['latitude']) && $payload['latitude'] !== '' && $payload['latitude'] !== null
    ? (float) $payload['latitude']
    : null;
$lon = isset($payload['longitude']) && $payload['longitude'] !== '' && $payload['longitude'] !== null
    ? (float) $payload['longitude']
    : null;

if ($action === 'clock_in') {
    $pendingBefore = attendance_pending_tasks($pdo, $userId);
    $carriedOverCount = count($pendingBefore);
    $result = $attendance->clockIn($userId, $lat, $lon);

    if (empty($result['success'])) {
        echo json_encode([
            'success' => false,
            'message' => (string) ($result['message'] ?? 'Clock in failed.'),
            'data' => attendance_desk_payload($attendance, $pdo, $userId),
        ]);
        exit;
    }

    $sysTime = date('Y-m-d H:i:s');
    $sysDate = date('Y-m-d');
    $newTasksCount = 0;
    $newTaskTitles = [];
    $newTasks = $payload['new_tasks'] ?? [];
    if (is_array($newTasks) && $newTasks !== []) {
        $insertTaskStmt = $pdo->prepare(
            'INSERT INTO user_tasks (user_id, task_description, is_completed, task_date, created_at) VALUES (?, ?, 0, ?, ?)'
        );
        foreach ($newTasks as $taskDesc) {
            $taskDesc = trim((string) $taskDesc);
            if ($taskDesc === '') {
                continue;
            }
            $insertTaskStmt->execute([$userId, $taskDesc, $sysDate, $sysTime]);
            $newTasksCount++;
            $newTaskTitles[] = $taskDesc;
        }
    }

    $timeInRaw = $result['time_in'] ?? date('H:i:s');
    $clockInSuccess = [
        'time_in' => $timeInRaw,
        'time_in_display' => date('g:i A', strtotime((string) $timeInRaw)),
        'status' => $result['status'] ?? 'On Time',
        'new_tasks' => $newTasksCount,
        'carried_over' => $carriedOverCount,
        'tasks' => $newTaskTitles,
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Clocked in successfully.',
        'clockInSuccess' => $clockInSuccess,
        'data' => attendance_desk_payload($attendance, $pdo, $userId),
    ]);
    exit;
}

if ($action === 'clock_out') {
    $pendingAtClockOut = attendance_pending_tasks($pdo, $userId);
    $completedIds = [];
    if (!empty($payload['completed_task_ids']) && is_array($payload['completed_task_ids'])) {
        $completedIds = array_map('intval', $payload['completed_task_ids']);
    }

    $result = $attendance->clockOut($userId, $lat, $lon);
    if (empty($result['success'])) {
        echo json_encode([
            'success' => false,
            'message' => (string) ($result['message'] ?? 'Clock out failed.'),
            'data' => attendance_desk_payload($attendance, $pdo, $userId),
        ]);
        exit;
    }

    if ($completedIds !== []) {
        $updateTaskStmt = $pdo->prepare('UPDATE user_tasks SET is_completed = 1 WHERE id = ? AND user_id = ?');
        foreach ($completedIds as $taskId) {
            if ($taskId > 0) {
                $updateTaskStmt->execute([$taskId, $userId]);
            }
        }
    }

    $completedTitles = [];
    $carriedTitles = [];
    foreach ($pendingAtClockOut as $task) {
        if (in_array((int) $task['id'], $completedIds, true)) {
            $completedTitles[] = $task['task_description'];
        } else {
            $carriedTitles[] = $task['task_description'];
        }
    }

    $timeOutRaw = $result['time_out'] ?? date('H:i:s');
    $timeInRaw = $result['time_in'] ?? '';
    $clockOutSuccess = [
        'time_out_display' => date('g:i A', strtotime((string) $timeOutRaw)),
        'time_in_display' => $timeInRaw !== '' ? date('g:i A', strtotime((string) $timeInRaw)) : '',
        'total_hours' => (float) ($result['total_hours'] ?? 0),
        'overtime_hours' => (float) ($result['overtime_hours'] ?? 0),
        'completed' => count($completedTitles),
        'carried_over' => count($carriedTitles),
        'completed_tasks' => $completedTitles,
        'carried_tasks' => $carriedTitles,
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Clocked out successfully.',
        'clockOutSuccess' => $clockOutSuccess,
        'data' => attendance_desk_payload($attendance, $pdo, $userId),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action.']);
