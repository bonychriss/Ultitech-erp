<?php
/**
 * Attendance clock actions (JSON API for React desk).
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Attendance.php';
require_once dirname(__DIR__) . '/classes/AttendanceWebAuthn.php';
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

try {
    if (function_exists('ensureAttendanceClockModuleSchema')) {
        ensureAttendanceClockModuleSchema();
    }
    $attendance = new Attendance($pdo);
    $webauthn = new AttendanceWebAuthn($pdo);
} catch (Throwable $e) {
    error_log('attendance api bootstrap: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Attendance biometric service failed to start. Please try again.',
        'detail' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null,
    ]);
    exit;
}

function attendance_webauthn_public_key_payload($publicKey)
{
    if ($publicKey === null) {
        return null;
    }
    try {
        $json = json_encode($publicKey, JSON_THROW_ON_ERROR);
        return json_decode($json, true);
    } catch (Throwable $e) {
        error_log('attendance webauthn json: ' . $e->getMessage());
        return null;
    }
}

function attendance_user_names(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT username, full_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'username' => (string) ($row['username'] ?? ('user' . $userId)),
            'display' => (string) ($row['full_name'] ?? $row['username'] ?? ('User ' . $userId)),
        ];
    } catch (Throwable $e) {
        return [
            'username' => 'user' . $userId,
            'display' => 'User ' . $userId,
        ];
    }
}

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

function attendance_desk_payload(Attendance $attendance, PDO $pdo, int $userId, ?string $historyMonth = null, ?AttendanceWebAuthn $webauthn = null): array
{
    $currentIp = $attendance->getCurrentUserIp();
    $month = $attendance->normalizeHistoryMonth($historyMonth);
    $biometric = null;
    try {
        $wa = $webauthn instanceof AttendanceWebAuthn ? $webauthn : new AttendanceWebAuthn($pdo);
        $biometric = $wa->statusForUser($userId);
    } catch (Throwable $e) {
        $biometric = [
            'required' => false,
            'enrolled' => false,
            'count' => 0,
            'max' => AttendanceWebAuthn::MAX_CREDENTIALS_PER_USER,
            'devices' => [],
            'library_ok' => false,
            'secure_ok' => false,
            'rp_id' => '',
        ];
    }
    return [
        'todayRecord' => $attendance->getTodayRecord($userId) ?: null,
        'todayPresent' => $attendance->getTodayPresentList(),
        'teamBoard' => $attendance->getTeamHoursBoard($userId),
        'punctualityBoard' => $attendance->getPunctualityBoard($userId),
        'history' => $attendance->getHistory($userId, $month) ?: [],
        'historyMonth' => $month,
        'historyMonthLabel' => $attendance->formatHistoryMonthLabel($month),
        'historyMonths' => $attendance->getHistoryMonthOptions(12),
        'stats' => $attendance->getStats($userId) ?: [],
        'pendingTasks' => attendance_pending_tasks($pdo, $userId),
        'isIpAllowed' => $attendance->isIpAllowed($currentIp),
        'currentIp' => $currentIp,
        'biometric' => $biometric,
        'activeAway' => $attendance->getActiveAwaySession($userId),
        'awayReasons' => $attendance->getAwayReasonOptions(),
    ];
}

$lat = isset($payload['latitude']) && $payload['latitude'] !== '' && $payload['latitude'] !== null
    ? (float) $payload['latitude']
    : null;
$lon = isset($payload['longitude']) && $payload['longitude'] !== '' && $payload['longitude'] !== null
    ? (float) $payload['longitude']
    : null;

$historyMonth = isset($payload['history_month']) ? (string) $payload['history_month'] : null;

if (str_starts_with($action, 'webauthn_')) {
    try {
        if ($action === 'webauthn_status') {
            echo json_encode([
                'success' => true,
                'message' => '',
                'data' => [
                    'biometric' => $webauthn->statusForUser($userId),
                ],
            ]);
            exit;
        }

        if ($action === 'webauthn_register_options') {
            $names = attendance_user_names($pdo, $userId);
            $mode = isset($payload['mode']) ? (string) $payload['mode'] : 'platform';
            $result = $webauthn->createRegisterOptions($userId, $names['username'], $names['display'], $mode);
            echo json_encode([
                'success' => !empty($result['success']),
                'message' => (string) ($result['message'] ?? ''),
                'mode' => (string) ($result['mode'] ?? $mode),
                'publicKey' => attendance_webauthn_public_key_payload($result['publicKey'] ?? null),
                'data' => ['biometric' => $webauthn->statusForUser($userId)],
            ]);
            exit;
        }

        if ($action === 'webauthn_register') {
            $client = is_array($payload['credential'] ?? null) ? $payload['credential'] : [];
            $deviceLabel = isset($payload['device_label']) ? (string) $payload['device_label'] : null;
            $mode = isset($payload['mode']) ? strtolower((string) $payload['mode']) : 'platform';
            if ($mode !== 'passkey') {
                $mode = 'platform';
            }
            if ($deviceLabel === null || trim($deviceLabel) === '') {
                $deviceLabel = $mode === 'passkey' ? 'Google passkey' : null;
            }
            $result = $webauthn->finishRegister($userId, $client, $deviceLabel, $mode);
            echo json_encode([
                'success' => !empty($result['success']),
                'message' => (string) ($result['message'] ?? ''),
                'data' => array_merge(
                    attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
                    is_array($result['data'] ?? null) ? $result['data'] : ['biometric' => $webauthn->statusForUser($userId)]
                ),
            ]);
            exit;
        }

        if ($action === 'webauthn_assert_options') {
            $result = $webauthn->createAssertOptions($userId);
            echo json_encode([
                'success' => !empty($result['success']),
                'message' => (string) ($result['message'] ?? ''),
                'publicKey' => attendance_webauthn_public_key_payload($result['publicKey'] ?? null),
                'data' => ['biometric' => $webauthn->statusForUser($userId)],
            ]);
            exit;
        }

        if ($action === 'webauthn_remove') {
            $credId = (int) ($payload['credential_row_id'] ?? $payload['id'] ?? 0);
            $result = $webauthn->removeCredential($userId, $credId);
            echo json_encode([
                'success' => !empty($result['success']),
                'message' => (string) ($result['message'] ?? ''),
                'data' => array_merge(
                    attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
                    is_array($result['data'] ?? null) ? $result['data'] : ['biometric' => $webauthn->statusForUser($userId)]
                ),
            ]);
            exit;
        }

        if ($action === 'webauthn_reset') {
            $result = $webauthn->resetCredentials($userId);
            echo json_encode([
                'success' => !empty($result['success']),
                'message' => (string) ($result['message'] ?? ''),
                'data' => array_merge(
                    attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
                    is_array($result['data'] ?? null) ? $result['data'] : ['biometric' => $webauthn->statusForUser($userId)]
                ),
            ]);
            exit;
        }
    } catch (Throwable $e) {
        error_log('attendance webauthn action ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Biometric request failed: ' . $e->getMessage(),
        ]);
        exit;
    }
}

if ($action === 'history') {
    $desk = attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn);
    echo json_encode([
        'success' => true,
        'message' => '',
        'data' => [
            'history' => $desk['history'],
            'historyMonth' => $desk['historyMonth'],
            'historyMonthLabel' => $desk['historyMonthLabel'],
            'historyMonths' => $desk['historyMonths'],
            'biometric' => $desk['biometric'],
        ],
    ]);
    exit;
}

if ($action === 'analytics') {
    $period = isset($payload['period']) ? (int) $payload['period'] : 30;
    if (!in_array($period, [7, 30, 90], true)) {
        $period = 30;
    }
    $scope = strtolower(trim((string) ($payload['scope'] ?? 'personal')));
    if ($scope !== 'team') {
        $scope = 'personal';
    }
    $rangeStart = isset($payload['start']) ? trim((string) $payload['start']) : '';
    $rangeEnd = isset($payload['end']) ? trim((string) $payload['end']) : '';
    $data = $attendance->getAnalytics(
        $userId,
        $period,
        $scope,
        $rangeStart !== '' ? $rangeStart : null,
        $rangeEnd !== '' ? $rangeEnd : null
    );
    $companySlug = trim((string) ($_SESSION['company_slug'] ?? ($_GET['company_slug'] ?? '')));
    $data['apiUrl'] = function_exists('company_url')
        ? (string) company_url('attendance/api/action.php', $companySlug !== '' ? $companySlug : null)
        : (function_exists('app_url')
            ? (string) app_url(($companySlug !== '' ? '/' . rawurlencode($companySlug) : '')
                . '/attendance/api/action.php')
            : (($companySlug !== '' ? '/' . rawurlencode($companySlug) : '')
                . '/attendance/api/action.php'));
    echo json_encode([
        'success' => true,
        'message' => '',
        'data' => $data,
    ]);
    exit;
}

if ($action === 'clock_in') {
    $assertion = is_array($payload['webauthn'] ?? null) ? $payload['webauthn'] : null;
    $bioCheck = $webauthn->verifyAssertion($userId, $assertion);
    if (empty($bioCheck['success'])) {
        echo json_encode([
            'success' => false,
            'message' => (string) ($bioCheck['message'] ?? 'Biometric verification required.'),
            'data' => attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
            'biometricRequired' => $webauthn->isBiometricRequired(),
        ]);
        exit;
    }

    $pendingBefore = attendance_pending_tasks($pdo, $userId);
    $carriedOverCount = count($pendingBefore);
    $result = $attendance->clockIn($userId, $lat, $lon);

    if (empty($result['success'])) {
        echo json_encode([
            'success' => false,
            'message' => (string) ($result['message'] ?? 'Clock in failed.'),
            'data' => attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
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
        'data' => attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
    ]);
    exit;
}

if ($action === 'away_start') {
    $reason = isset($payload['reason']) ? (string) $payload['reason'] : '';
    $note = isset($payload['note']) ? (string) $payload['note'] : null;
    $result = $attendance->startAway($userId, $reason, $note);
    echo json_encode([
        'success' => !empty($result['success']),
        'message' => (string) ($result['message'] ?? ''),
        'data' => attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
    ]);
    exit;
}

if ($action === 'away_end') {
    $result = $attendance->endAway($userId);
    echo json_encode([
        'success' => !empty($result['success']),
        'message' => (string) ($result['message'] ?? ''),
        'data' => attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
    ]);
    exit;
}

if ($action === 'clock_out') {
    $assertion = is_array($payload['webauthn'] ?? null) ? $payload['webauthn'] : null;
    $bioCheck = $webauthn->verifyAssertion($userId, $assertion);
    if (empty($bioCheck['success'])) {
        echo json_encode([
            'success' => false,
            'message' => (string) ($bioCheck['message'] ?? 'Biometric verification required.'),
            'data' => attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
            'biometricRequired' => $webauthn->isBiometricRequired(),
        ]);
        exit;
    }

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
            'data' => attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
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
        'data' => attendance_desk_payload($attendance, $pdo, $userId, $historyMonth, $webauthn),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action.']);
