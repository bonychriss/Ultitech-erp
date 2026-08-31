<?php
set_exception_handler(function($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Diagnostic API Exception: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine()]);
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Diagnostic API Fatal: ' . $err['message'] . ' at ' . basename($err['file']) . ':' . $err['line']]);
        exit;
    }
});
ob_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/weekly_mission_helpers.php';

if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

function wm_json_ok(array $data = []): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function wm_json_err(string $msg, int $code = 400): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isLoggedIn()) {
    wm_json_err('Unauthorized', 401);
}

global $pdo, $control_pdo;
if (!($pdo instanceof PDO) && $control_pdo instanceof PDO) {
    $pdo = $control_pdo;
}
if (!($pdo instanceof PDO)) {
    wm_json_err('Database unavailable', 500);
}

if (!wm_ensure_tables($pdo)) {
    wm_json_err('Could not initialize weekly mission tables', 500);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = strtolower(trim((string) ($_SESSION['role'] ?? 'employee')));
$isAdmin = ($role === 'admin' || (function_exists('isAdmin') && isAdmin()));
$companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;

$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
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

$action = $_GET['action'] ?? ($input['action'] ?? 'list');
$weekStart = $_GET['week_start'] ?? ($input['week_start'] ?? null);
$bounds = wm_get_week_bounds($weekStart ? (string) $weekStart : null);

try {
    switch ($action) {
        case 'list':
            $targetUser = $userId;
            if ($isAdmin && !empty($_GET['user_id'])) {
                $targetUser = (int) $_GET['user_id'];
            }
            $missions = wm_fetch_missions($pdo, $userId, $bounds['week_start'], $targetUser);
            $perf = wm_recalculate_performance($pdo, $targetUser, $bounds['week_start']);
            wm_json_ok([
                'week' => $bounds,
                'missions' => array_map('wm_format_mission_row', $missions),
                'performance' => $perf,
                'categories' => wm_mission_categories(),
                'due_days' => wm_due_days(),
            ]);
            break;

        case 'dashboard':
            $targetUser = $userId;
            if ($isAdmin && !empty($_GET['user_id'])) {
                $targetUser = (int) $_GET['user_id'];
            }
            $missions = wm_fetch_missions($pdo, $userId, $bounds['week_start'], $targetUser);
            $perf = wm_recalculate_performance($pdo, $targetUser, $bounds['week_start']);
            $prevBounds = wm_shift_week($bounds['week_start'], -1);
            $prevPerf = ['total_missions' => 0, 'completed_missions' => 0, 'award_points' => 0, 'completion_rate' => 0];
            $stPrev = $pdo->prepare('SELECT * FROM performance_points WHERE user_id = ? AND week_start = ? LIMIT 1');
            $stPrev->execute([$targetUser, $prevBounds['week_start']]);
            if ($row = $stPrev->fetch(PDO::FETCH_ASSOC)) {
                $prevPerf = $row;
            }

            $total = count($missions);
            $completed = 0;
            $pendingReview = 0;
            foreach ($missions as $m) {
                $formatted = wm_format_mission_row($m);
                if ($formatted['display_status'] === 'Completed') {
                    $completed++;
                }
                if ($formatted['display_status'] === 'Pending Review') {
                    $pendingReview++;
                }
            }
            $performanceScore = (int) round((float) $perf['completion_rate']);
            $monthPerf = wm_month_performance($pdo, $targetUser);

            wm_json_ok([
                'week' => $bounds,
                'missions' => array_map('wm_format_mission_row', $missions),
                'summary' => [
                    'total' => $total,
                    'completed' => $completed,
                    'pending_review' => $pendingReview,
                    'performance_score' => $performanceScore,
                    'completion_rate' => $perf['completion_rate'],
                    'award_points' => $perf['award_points'],
                    'streak_count' => $perf['streak_count'],
                ],
                'follow_up' => wm_latest_admin_followup($pdo, $targetUser, $bounds['week_start']),
                'month_performance' => $monthPerf,
                'leaderboard' => wm_leaderboard($pdo, $bounds['week_start'], 50),
                'team_progress' => wm_team_progress_chart($pdo, $targetUser, $bounds['week_start']),
            ]);
            break;

        case 'employee_reply':
            $id = (int) ($input['mission_id'] ?? $input['id'] ?? 0);
            $reply = trim((string) ($input['reply'] ?? ''));
            if ($id <= 0) {
                wm_json_err('Invalid mission id');
            }
            $st = $pdo->prepare('SELECT user_id FROM weekly_missions WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['user_id'] !== $userId) {
                wm_json_err('Forbidden', 403);
            }
            if (!columnExists('weekly_missions', 'employee_reply', $pdo)) {
                wm_json_err('Reply storage not available');
            }
            $up = $pdo->prepare('UPDATE weekly_missions SET employee_reply = ?, updated_at = NOW() WHERE id = ?');
            $up->execute([$reply !== '' ? $reply : null, $id]);
            wm_json_ok();
            break;

        case 'create':
            $title = trim((string) ($input['title'] ?? ''));
            $category = trim((string) ($input['category'] ?? 'Data Quality'));
            $dueDay = trim((string) ($input['due_day'] ?? 'Friday'));
            $description = trim((string) ($input['description'] ?? ''));

            if ($title === '') {
                wm_json_err('Mission title is required');
            }
            if (!in_array($dueDay, wm_due_days(), true)) {
                $dueDay = 'Friday';
            }
            if (!in_array($category, wm_mission_categories(), true)) {
                $category = 'Data Quality';
            }

            $count = wm_count_missions($pdo, $userId, $bounds['week_start']);
            if ($count >= WM_MAX_MISSIONS) {
                wm_json_err('Maximum ' . WM_MAX_MISSIONS . ' missions per week');
            }

            $priority = calculateMissionPriority($title, $category, $dueDay, false);
            $status = 'Pending';

            $ins = $pdo->prepare(
                'INSERT INTO weekly_missions
                (user_id, company_id, title, description, category, priority, priority_source, status, due_day, week_start, week_end)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $userId,
                $companyId > 0 ? $companyId : null,
                $title,
                $description !== '' ? $description : null,
                $category,
                $priority,
                'system',
                $status,
                $dueDay,
                $bounds['week_start'],
                $bounds['week_end'],
            ]);
            $id = (int) $pdo->lastInsertId();
            wm_recalculate_performance($pdo, $userId, $bounds['week_start']);

            $st = $pdo->prepare('SELECT * FROM weekly_missions WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            wm_json_ok(['mission' => wm_format_mission_row($row ?: [])]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                wm_json_err('Invalid mission id');
            }
            $st = $pdo->prepare('SELECT * FROM weekly_missions WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                wm_json_err('Mission not found', 404);
            }
            if ((int) $row['user_id'] !== $userId && !$isAdmin) {
                wm_json_err('Forbidden', 403);
            }
            if (!empty($row['completed_at'])) {
                wm_json_err('Cannot edit a completed mission');
            }

            $title = trim((string) ($input['title'] ?? $row['title']));
            $category = trim((string) ($input['category'] ?? $row['category']));
            $dueDay = trim((string) ($input['due_day'] ?? $row['due_day']));
            $description = array_key_exists('description', $input)
                ? trim((string) $input['description'])
                : ($row['description'] ?? '');

            if ($title === '') {
                wm_json_err('Title is required');
            }
            if (!in_array($dueDay, wm_due_days(), true)) {
                $dueDay = $row['due_day'];
            }

            $priority = $row['priority'];
            $prioritySource = $row['priority_source'] ?? 'system';
            if ($prioritySource !== 'admin_override') {
                $priority = calculateMissionPriority($title, $category, $dueDay, false);
            }

            $status = wm_compute_status($row);
            $up = $pdo->prepare(
                'UPDATE weekly_missions SET title = ?, description = ?, category = ?, due_day = ?, priority = ?, status = ? WHERE id = ?'
            );
            $up->execute([
                $title,
                $description !== '' ? $description : null,
                $category,
                $dueDay,
                $priority,
                $status,
                $id,
            ]);
            wm_recalculate_performance($pdo, (int) $row['user_id'], $row['week_start']);

            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            wm_json_ok(['mission' => wm_format_mission_row($row ?: [])]);
            break;

        case 'toggle_complete':
        case 'complete':
            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                wm_json_err('Invalid mission id');
            }
            $st = $pdo->prepare('SELECT * FROM weekly_missions WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                wm_json_err('Mission not found', 404);
            }
            if ((int) $row['user_id'] !== $userId && !$isAdmin) {
                wm_json_err('Forbidden', 403);
            }

            $markComplete = array_key_exists('completed', $input)
                ? (bool) $input['completed']
                : empty($row['completed_at']);

            if ($markComplete) {
                $pts = wm_mission_points(array_merge($row, ['completed_at' => date('Y-m-d H:i:s')]));
                $up = $pdo->prepare(
                    'UPDATE weekly_missions SET status = ?, completed_at = NOW(), points = ? WHERE id = ?'
                );
                $up->execute(['Completed', $pts, $id]);
            } else {
                $status = wm_compute_status(array_merge($row, ['completed_at' => null]));
                $up = $pdo->prepare(
                    'UPDATE weekly_missions SET status = ?, completed_at = NULL, points = 0 WHERE id = ?'
                );
                $up->execute([$status, $id]);
            }
            wm_recalculate_performance($pdo, (int) $row['user_id'], $row['week_start']);

            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            wm_json_ok(['mission' => wm_format_mission_row($row ?: []), 'completed' => !empty($row['completed_at'])]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                wm_json_err('Invalid mission id');
            }
            $st = $pdo->prepare('SELECT user_id, week_start FROM weekly_missions WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                wm_json_err('Mission not found', 404);
            }
            if ((int) $row['user_id'] !== $userId && !$isAdmin) {
                wm_json_err('Forbidden', 403);
            }
            $pdo->prepare('DELETE FROM weekly_missions WHERE id = ?')->execute([$id]);
            wm_recalculate_performance($pdo, (int) $row['user_id'], $row['week_start']);
            wm_json_ok();
            break;

        case 'admin_comment':
            if (!$isAdmin) {
                wm_json_err('Forbidden', 403);
            }
            $id = (int) ($input['id'] ?? 0);
            $comment = trim((string) ($input['admin_comment'] ?? ''));
            if ($id <= 0) {
                wm_json_err('Invalid mission id');
            }
            $up = $pdo->prepare('UPDATE weekly_missions SET admin_comment = ? WHERE id = ?');
            $up->execute([$comment !== '' ? $comment : null, $id]);
            wm_json_ok();
            break;

        case 'priority_override':
            if (!$isAdmin) {
                wm_json_err('Forbidden', 403);
            }
            $id = (int) ($input['id'] ?? 0);
            $priority = trim((string) ($input['priority'] ?? ''));
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($id <= 0 || !in_array($priority, ['High', 'Medium', 'Low'], true)) {
                wm_json_err('Invalid priority override');
            }
            $up = $pdo->prepare(
                'UPDATE weekly_missions SET priority = ?, priority_source = ?, priority_override_by = ?, priority_override_reason = ? WHERE id = ?'
            );
            $up->execute([$priority, 'admin_override', $userId, $reason !== '' ? $reason : null, $id]);
            $st = $pdo->prepare('SELECT user_id, week_start FROM weekly_missions WHERE id = ?');
            $st->execute([$id]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                wm_recalculate_performance($pdo, (int) $r['user_id'], $r['week_start']);
            }
            wm_json_ok();
            break;

        case 'import_local':
            $missions = $input['missions'] ?? [];
            if (!is_array($missions) || $missions === []) {
                wm_json_ok(['imported' => 0]);
            }
            $count = wm_count_missions($pdo, $userId, $bounds['week_start']);
            $imported = 0;
            foreach ($missions as $m) {
                if ($count >= WM_MAX_MISSIONS) {
                    break;
                }
                $title = trim((string) ($m['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $category = 'Data Quality';
                $dueDay = 'Friday';
                $priority = calculateMissionPriority($title, $category, $dueDay, false);
                $completed = !empty($m['completed']);
                $ins = $pdo->prepare(
                    'INSERT INTO weekly_missions
                    (user_id, company_id, title, category, priority, priority_source, status, due_day, week_start, week_end, completed_at, points)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $userId,
                    $companyId > 0 ? $companyId : null,
                    $title,
                    $category,
                    $priority,
                    'system',
                    $completed ? 'Completed' : 'Pending',
                    $dueDay,
                    $bounds['week_start'],
                    $bounds['week_end'],
                    $completed ? date('Y-m-d H:i:s') : null,
                    $completed ? wm_points_for_completion($priority) : 0,
                ]);
                $count++;
                $imported++;
            }
            wm_recalculate_performance($pdo, $userId, $bounds['week_start']);
            wm_json_ok(['imported' => $imported]);
            break;

        case 'admin_overview':
            if (!$isAdmin) {
                wm_json_err('Forbidden', 403);
            }
            $filters = [
                'department' => trim((string) ($_GET['department'] ?? '')),
                'user_id' => (int) ($_GET['user_id'] ?? 0),
            ];
            $users = wm_admin_list_users($pdo, $bounds['week_start'], $filters);
            $notSubmitted = [];
            $allMissions = [];
            foreach ($users as $u) {
                if ((int) $u['mission_count'] === 0) {
                    $notSubmitted[] = $u;
                }
            }
            $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
            $sql = 'SELECT wm.*, u.full_name, u.department FROM weekly_missions wm
                    INNER JOIN users u ON u.id = wm.user_id AND u.is_active = 1
                    WHERE wm.week_start = ?';
            $params = [$bounds['week_start']];
            if ($companyId > 0 && columnExists('users', 'company_id', $pdo)) {
                $sql .= ' AND u.company_id = ?';
                $params[] = $companyId;
            }
            $sql .= wm_sql_exclude_admin_users($pdo, 'u');
            if ($filters['department'] !== '') {
                $sql .= ' AND u.department = ?';
                $params[] = $filters['department'];
            }
            if ($filters['user_id'] > 0) {
                $sql .= ' AND wm.user_id = ?';
                $params[] = $filters['user_id'];
            }
            $statusFilter = trim((string) ($_GET['status'] ?? ''));
            $sql .= ' ORDER BY u.full_name, wm.id';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $completed = 0;
            $pendingReview = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
                $formatted = wm_format_mission_row($m);
                if ($statusFilter !== '' && $formatted['display_status'] !== $statusFilter) {
                    continue;
                }
                if ($formatted['display_status'] === 'Completed') {
                    $completed++;
                }
                if ($formatted['display_status'] === 'Pending Review') {
                    $pendingReview++;
                }
                $formatted['full_name'] = $m['full_name'];
                $formatted['department'] = $m['department'];
                $allMissions[] = $formatted;
            }
            $departments = [];
            if (tableExists('users', $pdo)) {
                $deptSql = "SELECT DISTINCT department FROM users u WHERE u.is_active = 1
                            AND department IS NOT NULL AND department != ''";
                $deptParams = [];
                if ($companyId > 0 && columnExists('users', 'company_id', $pdo)) {
                    $deptSql .= ' AND u.company_id = ?';
                    $deptParams[] = $companyId;
                }
                $deptSql .= wm_sql_exclude_admin_users($pdo, 'u');
                $deptSql .= ' ORDER BY department';
                $deptSt = $pdo->prepare($deptSql);
                $deptSt->execute($deptParams);
                $departments = $deptSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
            $totalMissions = count($allMissions);
            $teamScore = 0;
            if ($totalMissions > 0) {
                $teamScore = (int) round(($completed / $totalMissions) * 100);
            }
            wm_json_ok([
                'week' => $bounds,
                'users' => $users,
                'not_submitted' => $notSubmitted,
                'missions' => $allMissions,
                'departments' => $departments,
                'summary' => [
                    'total' => $totalMissions,
                    'completed' => $completed,
                    'pending_review' => $pendingReview,
                    'performance_score' => $teamScore,
                    'employees_with_missions' => count(array_filter($users, static fn(array $u): bool => (int) $u['mission_count'] > 0)),
                    'employees_without_missions' => count($notSubmitted),
                ],
                'leaderboard' => wm_leaderboard($pdo, $bounds['week_start'], 50),
                'team_progress' => wm_team_progress_chart($pdo, $userId, $bounds['week_start']),
            ]);
            break;

        default:
            wm_json_err('Unknown action');
    }
} catch (Throwable $e) {
    wm_json_err('Server error: ' . $e->getMessage(), 500);
}
