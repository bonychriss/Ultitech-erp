<?php
/**
 * Weekly Mission � schema, priority, status, and performance points.
 */

if (!defined('WM_MAX_MISSIONS')) {
    define('WM_MAX_MISSIONS', 7);
}

function wm_mission_categories(): array
{
    return [
        'Data Quality',
        'System Analysis',
        'Bug Fixes',
        'Documentation',
        'Customer Support',
        'Maintenance',
        'System Maintenance',
        'Data Correction',
        'Security',
        'Finance',
        'Reports',
        'Testing',
        'Module Check',
        'Follow-up',
        'Learning',
        'Internal Notes',
        'UI Improvement',
    ];
}

function wm_due_days(): array
{
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

function wm_timezone(): DateTimeZone
{
    return new DateTimeZone('Africa/Dar_es_Salaam');
}

/** Monday�Sunday bounds for a week (default: current week in Dar es Salaam). */
function wm_get_week_bounds(?string $weekStart = null): array
{
    $tz = wm_timezone();
    if ($weekStart && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
        $monday = new DateTime($weekStart . ' 12:00:00', $tz);
    } else {
        $now = new DateTime('now', $tz);
        $w = (int) $now->format('w');
        $diff = $w === 0 ? -6 : 1 - $w;
        $monday = clone $now;
        $monday->modify($diff . ' days');
        $monday->setTime(0, 0, 0);
    }
    $sunday = clone $monday;
    $sunday->modify('+6 days');
    return [
        'week_start' => $monday->format('Y-m-d'),
        'week_end' => $sunday->format('Y-m-d'),
    ];
}

function wm_shift_week(string $weekStart, int $weeks): array
{
    $tz = wm_timezone();
    $d = new DateTime($weekStart . ' 12:00:00', $tz);
    $d->modify(($weeks * 7) . ' days');
    return wm_get_week_bounds($d->format('Y-m-d'));
}

function wm_due_day_index(string $dueDay): int
{
    $map = array_flip(wm_due_days());
    return isset($map[$dueDay]) ? $map[$dueDay] + 1 : 5;
}

function wm_today_index(): int
{
    return (int) (new DateTime('now', wm_timezone()))->format('N');
}

/**
 * Auto-assign priority (employees never set this manually).
 */
function calculateMissionPriority(string $title, string $category, string $dueDay, bool $adminImportant = false): string
{
    $text = strtolower(trim($title . ' ' . $category));

    $highKeywords = [
        'urgent', 'critical', 'error', 'bug', 'repair', 'fix',
        'deadline', 'payment', 'system down', 'broken', 'failed',
        'issue', 'complaint', 'missing data', 'damaged', 'security',
    ];
    $mediumKeywords = [
        'check', 'analysis', 'review', 'report', 'test',
        'follow up', 'verify', 'update', 'module',
    ];

    if ($adminImportant) {
        return 'High';
    }

    foreach ($highKeywords as $word) {
        if ($word !== '' && strpos($text, $word) !== false) {
            return 'High';
        }
    }

    $highCategories = [
        'Bug Fixes', 'System Maintenance', 'Security', 'Finance',
        'Customer Support', 'Data Correction',
    ];
    $mediumCategories = [
        'System Analysis', 'Data Quality', 'Reports', 'Testing',
        'Documentation', 'Module Check', 'Follow-up',
    ];

    if ($category !== '') {
        if (in_array($category, $highCategories, true)) {
            return 'High';
        }
        if (in_array($category, $mediumCategories, true)) {
            return 'Medium';
        }
    }

    foreach ($mediumKeywords as $word) {
        if ($word !== '' && strpos($text, $word) !== false) {
            return 'Medium';
        }
    }

    if (in_array($dueDay, ['Monday', 'Tuesday'], true)) {
        $opsCategories = ['Bug Fixes', 'System Maintenance', 'Customer Support', 'Data Correction', 'Security'];
        if (in_array($category, $opsCategories, true)) {
            return 'High';
        }
    }

    return 'Low';
}

function wm_compute_status(array $row): string
{
    if (!empty($row['completed_at']) || ($row['status'] ?? '') === 'Completed') {
        return 'Completed';
    }
    $dueIdx = wm_due_day_index((string) ($row['due_day'] ?? 'Friday'));
    $todayIdx = wm_today_index();
    if ($todayIdx > $dueIdx) {
        return 'Delayed';
    }
    if ($todayIdx >= $dueIdx) {
        return 'In Progress';
    }
    return 'Pending';
}

function wm_points_for_completion(string $priority): int
{
    switch ($priority) {
        case 'High':
            return 15;
        case 'Medium':
            return 10;
        default:
            return 5;
    }
}

function wm_mission_points(array $mission): int
{
    if (empty($mission['completed_at'])) {
        return 0;
    }
    $priority = (string) ($mission['priority'] ?? 'Medium');
    $pts = wm_points_for_completion($priority);
    $dueIdx = wm_due_day_index((string) ($mission['due_day'] ?? 'Friday'));
    $completedAt = $mission['completed_at'];
    if ($completedAt) {
        $tz = wm_timezone();
        $done = new DateTime($completedAt, $tz);
        $doneIdx = (int) $done->format('N');
        if ($doneIdx < $dueIdx) {
            $pts += 2;
        }
    }
    if (wm_compute_status($mission) === 'Delayed' && empty($mission['completed_at'])) {
        $pts -= 3;
    }
    return max(0, $pts);
}

function wm_ensure_tables(PDO $pdo): bool
{
    if (!tableExists('weekly_missions', $pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `weekly_missions` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `company_id` int(11) DEFAULT NULL,
          `title` varchar(255) NOT NULL,
          `description` text DEFAULT NULL,
          `category` varchar(80) NOT NULL DEFAULT 'Data Quality',
          `priority` enum('High','Medium','Low') NOT NULL DEFAULT 'Medium',
          `priority_source` enum('system','admin_override') NOT NULL DEFAULT 'system',
          `priority_override_by` int(11) DEFAULT NULL,
          `priority_override_reason` varchar(255) DEFAULT NULL,
          `status` enum('Pending','In Progress','Completed','Delayed') NOT NULL DEFAULT 'Pending',
          `due_day` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL DEFAULT 'Friday',
          `week_start` date NOT NULL,
          `week_end` date NOT NULL,
          `completed_at` datetime DEFAULT NULL,
          `admin_comment` text DEFAULT NULL,
          `points` int(11) NOT NULL DEFAULT 0,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_wm_user_week` (`user_id`,`week_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
    if (tableExists('weekly_missions', $pdo) && !columnExists('weekly_missions', 'employee_reply', $pdo)) {
        try {
            $pdo->exec('ALTER TABLE weekly_missions ADD COLUMN employee_reply TEXT NULL AFTER admin_comment');
        } catch (Throwable $e) {
            /* column may already exist */
        }
    }

    if (!tableExists('performance_points', $pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `performance_points` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `company_id` int(11) DEFAULT NULL,
          `week_start` date NOT NULL,
          `week_end` date NOT NULL,
          `total_missions` int(11) NOT NULL DEFAULT 0,
          `completed_missions` int(11) NOT NULL DEFAULT 0,
          `delayed_missions` int(11) NOT NULL DEFAULT 0,
          `completion_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
          `award_points` int(11) NOT NULL DEFAULT 0,
          `streak_count` int(11) NOT NULL DEFAULT 0,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_pp_user_week` (`user_id`,`week_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
    return tableExists('weekly_missions', $pdo) && tableExists('performance_points', $pdo);
}

function wm_count_missions(PDO $pdo, int $userId, string $weekStart): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM weekly_missions WHERE user_id = ? AND week_start = ?');
    $st->execute([$userId, $weekStart]);
    return (int) $st->fetchColumn();
}

function wm_fetch_missions(PDO $pdo, int $userId, string $weekStart, ?int $filterUserId = null): array
{
    $targetUser = $filterUserId ?? $userId;
    $st = $pdo->prepare('SELECT * FROM weekly_missions WHERE user_id = ? AND week_start = ? ORDER BY id ASC');
    $st->execute([$targetUser, $weekStart]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        if (($row['status'] ?? '') !== 'Completed') {
            $row['status'] = wm_compute_status($row);
        }
    }
    unset($row);
    return $rows;
}

function wm_streak_count(PDO $pdo, int $userId, string $beforeWeekStart): int
{
    $streak = 0;
    $cursor = $beforeWeekStart;
    for ($i = 0; $i < 52; $i++) {
        $bounds = wm_get_week_bounds($cursor);
        $prev = wm_shift_week($bounds['week_start'], -1);
        $cursor = $prev['week_start'];
        $st = $pdo->prepare(
            'SELECT total_missions, completed_missions FROM performance_points WHERE user_id = ? AND week_start = ? LIMIT 1'
        );
        $st->execute([$userId, $cursor]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['total_missions'] < 1) {
            break;
        }
        if ((int) $row['completed_missions'] < (int) $row['total_missions']) {
            break;
        }
        $streak++;
        if ($streak >= 4) {
            break;
        }
    }
    return $streak;
}

function wm_recalculate_performance(PDO $pdo, int $userId, string $weekStart): array
{
    $bounds = wm_get_week_bounds($weekStart);
    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
    $missions = wm_fetch_missions($pdo, $userId, $bounds['week_start'], $userId);

    $total = count($missions);
    $completed = 0;
    $delayed = 0;
    $awardPoints = 0;

    foreach ($missions as &$m) {
        $status = wm_compute_status($m);
        if ($status === 'Completed' || !empty($m['completed_at'])) {
            $completed++;
            $pts = wm_mission_points($m);
            $awardPoints += $pts;
            if ((int) ($m['points'] ?? 0) !== $pts) {
                $up = $pdo->prepare('UPDATE weekly_missions SET points = ? WHERE id = ?');
                $up->execute([$pts, (int) $m['id']]);
            }
        } elseif ($status === 'Delayed') {
            $delayed++;
            $awardPoints = max(0, $awardPoints - 3);
        }
    }
    unset($m);

    if ($total > 0 && $completed === $total) {
        $awardPoints += 10;
    }

    $streak = wm_streak_count($pdo, $userId, $bounds['week_start']);
    if ($streak >= 4 && $total > 0 && $completed === $total) {
        $awardPoints += 20;
    }

    $rate = $total > 0 ? round(($completed / $total) * 100, 2) : 0.0;

    $st = $pdo->prepare(
        'INSERT INTO performance_points
        (user_id, company_id, week_start, week_end, total_missions, completed_missions, delayed_missions, completion_rate, award_points, streak_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        company_id = VALUES(company_id),
        week_end = VALUES(week_end),
        total_missions = VALUES(total_missions),
        completed_missions = VALUES(completed_missions),
        delayed_missions = VALUES(delayed_missions),
        completion_rate = VALUES(completion_rate),
        award_points = VALUES(award_points),
        streak_count = VALUES(streak_count),
        updated_at = NOW()'
    );
    $st->execute([
        $userId,
        $companyId > 0 ? $companyId : null,
        $bounds['week_start'],
        $bounds['week_end'],
        $total,
        $completed,
        $delayed,
        $rate,
        max(0, $awardPoints),
        $streak,
    ]);

    return [
        'total_missions' => $total,
        'completed_missions' => $completed,
        'delayed_missions' => $delayed,
        'completion_rate' => $rate,
        'award_points' => max(0, $awardPoints),
        'streak_count' => $streak,
    ];
}

/** UI-facing status label for mission list */
function wm_display_status(array $row): string
{
    if (!empty($row['completed_at']) || ($row['status'] ?? '') === 'Completed') {
        return 'Completed';
    }
    $adminComment = trim((string) ($row['admin_comment'] ?? ''));
    if ($adminComment !== '') {
        return 'Pending Review';
    }
    $raw = wm_compute_status($row);
    if ($raw === 'In Progress' || $raw === 'Delayed') {
        return 'In Progress';
    }
    return 'Not Started';
}

function wm_format_mission_row(array $row): array
{
    $status = wm_compute_status($row);
    if (!empty($row['completed_at'])) {
        $status = 'Completed';
    }
    $updated = $row['updated_at'] ?? $row['created_at'] ?? null;
    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'title' => $row['title'],
        'description' => $row['description'] ?? '',
        'category' => $row['category'],
        'priority' => $row['priority'],
        'priority_source' => $row['priority_source'] ?? 'system',
        'priority_override_by' => $row['priority_override_by'] ? (int) $row['priority_override_by'] : null,
        'priority_override_reason' => $row['priority_override_reason'] ?? '',
        'status' => $status,
        'display_status' => wm_display_status($row),
        'due_day' => $row['due_day'],
        'week_start' => $row['week_start'],
        'week_end' => $row['week_end'],
        'completed_at' => $row['completed_at'],
        'admin_comment' => $row['admin_comment'] ?? '',
        'employee_reply' => $row['employee_reply'] ?? '',
        'points' => (int) ($row['points'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $updated,
    ];
}

function wm_team_progress_chart(PDO $pdo, int $userId, string $weekStart): array
{
    $labels = [];
    $yourProgress = [];
    $teamAverage = [];
    $cursor = $weekStart;
    for ($i = 0; $i < 5; $i++) {
        $bounds = wm_get_week_bounds($cursor);
        $labels[] = date('M j', strtotime($bounds['week_start']));
        $stUser = $pdo->prepare('SELECT completion_rate FROM performance_points WHERE user_id = ? AND week_start = ? LIMIT 1');
        $stUser->execute([$userId, $bounds['week_start']]);
        $ur = $stUser->fetch(PDO::FETCH_ASSOC);
        $yourProgress[] = round((float) ($ur['completion_rate'] ?? 0), 1);

        $stTeam = $pdo->prepare('SELECT AVG(completion_rate) AS avg_rate FROM performance_points WHERE week_start = ?');
        $stTeam->execute([$bounds['week_start']]);
        $tr = $stTeam->fetch(PDO::FETCH_ASSOC);
        $teamAverage[] = round((float) ($tr['avg_rate'] ?? 0), 1);

        $prev = wm_shift_week($bounds['week_start'], -1);
        $cursor = $prev['week_start'];
    }
    return [
        'labels' => array_reverse($labels),
        'your_progress' => array_reverse($yourProgress),
        'team_average' => array_reverse($teamAverage),
    ];
}

function wm_latest_admin_followup(PDO $pdo, int $userId, string $weekStart): ?array
{
    $hasReply = columnExists('weekly_missions', 'employee_reply', $pdo);
    $sql = 'SELECT wm.id, wm.title, wm.admin_comment, wm.updated_at'
        . ($hasReply ? ', wm.employee_reply' : '')
        . ' FROM weekly_missions wm
         WHERE wm.user_id = ? AND wm.week_start = ? AND wm.admin_comment IS NOT NULL AND TRIM(wm.admin_comment) != \'\'
         ORDER BY wm.updated_at DESC LIMIT 1';
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$userId, $weekStart]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    return [
        'mission_id' => (int) $row['id'],
        'mission_title' => $row['title'],
        'admin_comment' => $row['admin_comment'],
        'employee_reply' => $hasReply ? ($row['employee_reply'] ?? '') : '',
        'admin_name' => 'Admin',
        'updated_at' => $row['updated_at'],
    ];
}

function wm_month_performance(PDO $pdo, int $userId): array
{
    $tz = wm_timezone();
    $now = new DateTime('now', $tz);
    $monthStart = $now->format('Y-m-01');
    $st = $pdo->prepare(
        'SELECT AVG(completion_rate) AS avg_rate FROM performance_points
         WHERE user_id = ? AND week_start >= ?'
    );
    $st->execute([$userId, $monthStart]);
    $current = round((float) ($st->fetchColumn() ?: 0));

    $prevMonth = (clone $now)->modify('-1 month')->format('Y-m-01');
    $prevEnd = (clone $now)->modify('-1 month')->format('Y-m-t');
    $st2 = $pdo->prepare(
        'SELECT AVG(completion_rate) AS avg_rate FROM performance_points
         WHERE user_id = ? AND week_start >= ? AND week_start <= ?'
    );
    $st2->execute([$userId, $prevMonth, $prevEnd]);
    $previous = round((float) ($st2->fetchColumn() ?: 0));
    $delta = $current - $previous;

    $awardEarnedAt = null;
    $stAward = $pdo->prepare(
        'SELECT week_start FROM performance_points
         WHERE user_id = ? AND completion_rate >= 85
         ORDER BY week_start DESC LIMIT 1'
    );
    $stAward->execute([$userId]);
    $awardWeek = $stAward->fetchColumn();
    if ($awardWeek) {
        $awardEarnedAt = $awardWeek;
    }

    return [
        'score' => $current,
        'prev_score' => $previous,
        'delta' => $delta,
        'award_label' => $current >= 85 ? 'Consistent Performer' : ($current >= 60 ? 'Rising Star' : 'Keep Going'),
        'award_earned_at' => $awardEarnedAt,
    ];
}

/** Roles excluded from Top Performers (employees only). */
function wm_leaderboard_excluded_roles(): array
{
    return [
        'admin',
        'administrator',
        'company_admin',
        'superadmin',
        'super_admin',
        'platform_admin',
        'system_admin',
        'owner',
    ];
}

/** SQL fragment: active users table alias must not be an admin role. */
function wm_sql_exclude_admin_users(PDO $pdo, string $alias = 'u'): string
{
    if (!columnExists('users', 'role', $pdo)) {
        return '';
    }
    $quoted = array_map(
        static fn(string $r): string => $pdo->quote(strtolower($r)),
        wm_leaderboard_excluded_roles()
    );
    return ' AND LOWER(TRIM(' . $alias . '.role)) NOT IN (' . implode(',', $quoted) . ')';
}

/**
 * Ensure performance_points rows exist for all active users in the current company.
 * (Otherwise the leaderboard only shows whoever opened Weekly Mission this week.)
 */
function wm_sync_team_performance(PDO $pdo, string $weekStart): void
{
    static $synced = [];
    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
    $cacheKey = $weekStart . ':' . $companyId;
    if (isset($synced[$cacheKey])) {
        return;
    }
    $synced[$cacheKey] = true;

    if (!tableExists('users', $pdo)) {
        return;
    }

    $ids = [];
    $sql = 'SELECT id FROM users u WHERE u.is_active = 1';
    $params = [];
    if ($companyId > 0 && columnExists('users', 'company_id', $pdo)) {
        $sql .= ' AND u.company_id = ?';
        $params[] = $companyId;
    }
    $sql .= wm_sql_exclude_admin_users($pdo, 'u');
    $sql .= ' ORDER BY u.id ASC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uid) {
        $ids[(int) $uid] = true;
    }

    if (tableExists('weekly_missions', $pdo)) {
        $mSql = 'SELECT DISTINCT wm.user_id FROM weekly_missions wm
                 INNER JOIN users u ON u.id = wm.user_id AND u.is_active = 1
                 WHERE wm.week_start = ?';
        $mParams = [$weekStart];
        if ($companyId > 0 && columnExists('weekly_missions', 'company_id', $pdo)) {
            $mSql .= ' AND (wm.company_id = ? OR wm.company_id IS NULL)';
            $mParams[] = $companyId;
        }
        $mSql .= wm_sql_exclude_admin_users($pdo, 'u');
        $mSql .= ' LIMIT 200';
        $mst = $pdo->prepare($mSql);
        $mst->execute($mParams);
        foreach ($mst->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uid) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $ids[$uid] = true;
            }
        }
    }

    foreach (array_keys($ids) as $uid) {
        if ($uid > 0) {
            wm_recalculate_performance($pdo, $uid, $weekStart);
        }
    }
}

function wm_leaderboard(PDO $pdo, string $weekStart, int $limit = 10): array
{
    if (!tableExists('performance_points', $pdo) || !tableExists('users', $pdo)) {
        return [];
    }

    wm_sync_team_performance($pdo, $weekStart);

    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
    $sql = 'SELECT pp.user_id, pp.award_points, pp.streak_count, pp.completion_rate,
                   pp.total_missions, pp.completed_missions,
                   u.full_name, u.department, u.profile_photo
            FROM performance_points pp
            INNER JOIN users u ON u.id = pp.user_id AND u.is_active = 1
            WHERE pp.week_start = ?';
    $params = [$weekStart];
    $sql .= wm_sql_exclude_admin_users($pdo, 'u');
    if ($companyId > 0 && columnExists('performance_points', 'company_id', $pdo)) {
        $sql .= ' AND (pp.company_id = ? OR pp.company_id IS NULL)';
        $params[] = $companyId;
    }
    $sql .= ' ORDER BY pp.completion_rate DESC, pp.award_points DESC, u.full_name ASC
            LIMIT ' . (int) $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    $rank = 1;
    foreach ($rows as $r) {
        $out[] = [
            'rank' => $rank++,
            'user_id' => (int) $r['user_id'],
            'full_name' => $r['full_name'],
            'department' => $r['department'] ?? '',
            'profile_photo' => $r['profile_photo'] ?? '',
            'award_points' => (int) $r['award_points'],
            'streak_count' => (int) $r['streak_count'],
            'completion_rate' => (float) $r['completion_rate'],
            'total_missions' => (int) ($r['total_missions'] ?? 0),
            'completed_missions' => (int) ($r['completed_missions'] ?? 0),
        ];
    }
    return $out;
}

function wm_chart_stats(PDO $pdo, string $weekStart): array
{
    $leaderboard = wm_leaderboard($pdo, $weekStart, 15);
    $barLabels = [];
    $barData = [];
    foreach ($leaderboard as $item) {
        $barLabels[] = $item['full_name'];
        $barData[] = (float) $item['completion_rate'];
    }

    $trendLabels = [];
    $trendCompletion = [];
    $trendPoints = [];
    $cursor = $weekStart;
    for ($i = 0; $i < 6; $i++) {
        $bounds = wm_get_week_bounds($cursor);
        $trendLabels[] = date('M j', strtotime($bounds['week_start']));
        $st = $pdo->prepare(
            'SELECT AVG(completion_rate) AS cr, SUM(award_points) AS pts FROM performance_points WHERE week_start = ?'
        );
        $st->execute([$bounds['week_start']]);
        $agg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $trendCompletion[] = round((float) ($agg['cr'] ?? 0), 1);
        $trendPoints[] = (int) ($agg['pts'] ?? 0);
        $prev = wm_shift_week($bounds['week_start'], -1);
        $cursor = $prev['week_start'];
    }
    $trendLabels = array_reverse($trendLabels);
    $trendCompletion = array_reverse($trendCompletion);
    $trendPoints = array_reverse($trendPoints);

    $statusCounts = ['Completed' => 0, 'In Progress' => 0, 'Pending' => 0, 'Delayed' => 0];
    $st = $pdo->prepare('SELECT status, completed_at, due_day FROM weekly_missions WHERE week_start = ?');
    $st->execute([$weekStart]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
        $s = wm_compute_status($m);
        if (!isset($statusCounts[$s])) {
            $statusCounts[$s] = 0;
        }
        $statusCounts[$s]++;
    }

    return [
        'bar' => ['labels' => $barLabels, 'data' => $barData],
        'trend' => [
            'labels' => $trendLabels,
            'completion' => $trendCompletion,
            'points' => $trendPoints,
        ],
        'status' => [
            'labels' => array_keys($statusCounts),
            'data' => array_values($statusCounts),
        ],
    ];
}

function wm_admin_list_users(PDO $pdo, string $weekStart, array $filters = []): array
{
    if (!tableExists('users', $pdo)) {
        return [];
    }
    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
    $sql = "SELECT u.id, u.full_name, u.department,
            (SELECT COUNT(*) FROM weekly_missions wm WHERE wm.user_id = u.id AND wm.week_start = ?) AS mission_count
            FROM users u WHERE u.is_active = 1";
    $params = [$weekStart];
    if ($companyId > 0 && columnExists('users', 'company_id', $pdo)) {
        $sql .= ' AND u.company_id = ?';
        $params[] = $companyId;
    }
    $sql .= wm_sql_exclude_admin_users($pdo, 'u');
    if (!empty($filters['department'])) {
        $sql .= ' AND u.department = ?';
        $params[] = $filters['department'];
    }
    if (!empty($filters['user_id'])) {
        $sql .= ' AND u.id = ?';
        $params[] = (int) $filters['user_id'];
    }
    $sql .= ' ORDER BY u.full_name ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Stats for My Tasks "My Plan Achievements" panel (missions + chart by due day).
 */
function todo_build_plan_achievements(PDO $pdo, int $userId, string $weekStart): array
{
    $tz = wm_timezone();
    $monday = new DateTime($weekStart . ' 12:00:00', $tz);
    $labels = [];
    $byDay = [];
    for ($i = 0; $i < 7; $i++) {
        $d = clone $monday;
        if ($i > 0) {
            $d->modify('+' . $i . ' days');
        }
        $labels[] = $d->format('D');
        $byDay[] = ['total' => 0, 'done' => 0];
    }

    $weekTotal = 0;
    $weekDone = 0;
    $overdue = 0;

    if ($userId > 0 && wm_ensure_tables($pdo)) {
        $missions = wm_fetch_missions($pdo, $userId, $weekStart, $userId);
        $weekTotal = count($missions);
        foreach ($missions as $m) {
            $idx = wm_due_day_index((string) ($m['due_day'] ?? 'Friday')) - 1;
            if ($idx < 0 || $idx > 6) {
                continue;
            }
            $byDay[$idx]['total']++;
            $isDone = !empty($m['completed_at']) || ($m['status'] ?? '') === 'Completed'
                || wm_compute_status($m) === 'Completed';
            if ($isDone) {
                $byDay[$idx]['done']++;
                $weekDone++;
            }
            if (wm_compute_status($m) === 'Delayed') {
                $overdue++;
            }
        }
    }

    $chart = [];
    foreach ($byDay as $day) {
        $chart[] = $day['total'] > 0
            ? (int) round(($day['done'] / $day['total']) * 100)
            : null;
    }

    $weekPct = $weekTotal > 0 ? (int) round(($weekDone / $weekTotal) * 100) : 0;
    $minutes = $weekDone * 30;

    return [
        'labels' => $labels,
        'chart' => $chart,
        'byDay' => $byDay,
        'weekTotal' => $weekTotal,
        'weekDone' => $weekDone,
        'weekPct' => $weekPct,
        'overdue' => $overdue,
        'timeSpent' => sprintf('%dh %dm', intdiv($minutes, 60), $minutes % 60),
        'hasData' => $weekTotal > 0,
    ];
}

/**
 * Absolute Weekly Mission API URL (works from /todo/ and /ultimate/todo/ alias routes).
 */
function wm_api_url(): string
{
    $path = 'todo/api/weekly_missions.php';
    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));
    if ($slug === '' && function_exists('getRequestedCompanySlug')) {
        $slug = trim((string) getRequestedCompanySlug());
    }
    if ($slug !== '' && function_exists('company_url')) {
        return company_url($path);
    }
    if (function_exists('app_url')) {
        return app_url('/' . $path);
    }
    return 'api/weekly_missions.php';
}
