<?php
/**
 * Performance module data helpers � Weekly Missions (todo) + weekly plans fallback.
 */

function perf_user_avatar_url(?string $photo, string $name): string
{
    if ($photo !== null && $photo !== '') {
        $photo = ltrim(str_replace('\\', '/', $photo), '/');
        if (function_exists('app_url')) {
            return app_url('/' . $photo);
        }
        return '../' . $photo;
    }
    return '';
}

function perf_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $init = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $init .= strtoupper(substr($p, 0, 1));
    }
    return $init !== '' ? $init : 'U';
}

function perf_missions_available(PDO $pdo): bool
{
    return function_exists('wm_recalculate_performance')
        && function_exists('tableExists')
        && tableExists('weekly_missions', $pdo)
        && tableExists('performance_points', $pdo);
}

/** Recalculate performance_points for all active users (same engine as todo/weekly_mission.php). */
function perf_sync_mission_performance(PDO $pdo, string $weekStart): void
{
    if (!perf_missions_available($pdo)) {
        return;
    }
    $st = $pdo->query('SELECT id FROM users WHERE is_active = 1');
    if (!$st) {
        return;
    }
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uid) {
        wm_recalculate_performance($pdo, (int) $uid, $weekStart);
    }
}

function perf_fetch_team_stats_from_missions(PDO $pdo, string $weekStartDate): array
{
    $sql = "
        SELECT
            u.id,
            u.full_name,
            u.department,
            u.role,
            u.profile_photo,
            COALESCE(pp.total_missions, 0) AS total_tasks,
            COALESCE(pp.completed_missions, 0) AS completed_count,
            GREATEST(0, COALESCE(pp.total_missions, 0) - COALESCE(pp.completed_missions, 0)) AS pending_count,
            COALESCE(pp.delayed_missions, 0) AS delayed_tasks,
            COALESCE(pp.completion_rate, 0) AS completion_rate,
            COALESCE(pp.award_points, 0) AS award_points,
            'missions' AS data_source
        FROM users u
        LEFT JOIN performance_points pp ON pp.user_id = u.id AND pp.week_start = ?
        WHERE u.is_active = 1
        ORDER BY
            COALESCE(pp.completion_rate, 0) DESC,
            COALESCE(pp.award_points, 0) DESC,
            u.full_name ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$weekStartDate]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['score_pct'] = (int) round((float) ($row['completion_rate'] ?? 0));
        $row['total_points'] = (int) ($row['award_points'] ?? 0);
        $row['completed_points'] = (int) ($row['completed_count'] ?? 0);
        $row['plan_id'] = null;
    }
    unset($row);

    return $rows;
}

function perf_fetch_team_stats_from_plans(PDO $pdo, string $weekStartDate): array
{
    if (!tableExists('weekly_plans', $pdo)) {
        return [];
    }

    $sql = "
        SELECT
            u.id, u.full_name, u.department, u.role,
            u.profile_photo,
            p.id AS plan_id,
            SUM(i.weight) AS total_points,
            SUM(CASE WHEN i.is_completed = 1 THEN i.weight ELSE 0 END) AS completed_points,
            COUNT(i.id) AS total_tasks,
            SUM(CASE WHEN i.is_completed = 1 THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN i.is_completed = 0 THEN 1 ELSE 0 END) AS pending_count,
            'plans' AS data_source
        FROM users u
        LEFT JOIN weekly_plans p ON u.id = p.user_id AND p.week_start_date = ?
        LEFT JOIN weekly_plan_items i ON p.id = i.plan_id
        WHERE u.is_active = 1 AND LOWER(TRIM(u.role)) NOT IN ('admin', 'administrator', 'superadmin', 'super_admin', 'company_admin')
        GROUP BY u.id
        ORDER BY
            (CASE WHEN SUM(i.weight) > 0 THEN (SUM(CASE WHEN i.is_completed = 1 THEN i.weight ELSE 0 END) / SUM(i.weight)) ELSE 0 END) DESC,
            u.full_name ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$weekStartDate]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $total = (float) ($row['total_points'] ?? 0);
        $done = (float) ($row['completed_points'] ?? 0);
        $row['score_pct'] = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        $row['delayed_tasks'] = max(0, (int) ($row['pending_count'] ?? 0));
        $row['award_points'] = 0;
        $row['completion_rate'] = $row['score_pct'];
    }
    unset($row);

    return $rows;
}

function perf_fetch_team_stats(PDO $pdo, string $weekStartDate): array
{
    if (perf_missions_available($pdo)) {
        perf_sync_mission_performance($pdo, $weekStartDate);
        $missionRows = perf_fetch_team_stats_from_missions($pdo, $weekStartDate);

        $hasMissionActivity = false;
        foreach ($missionRows as $r) {
            if ((int) ($r['total_tasks'] ?? 0) > 0) {
                $hasMissionActivity = true;
                break;
            }
        }
        if ($hasMissionActivity) {
            return $missionRows;
        }
    }

    return perf_fetch_team_stats_from_plans($pdo, $weekStartDate);
}

function perf_data_source(array $teamStats): string
{
    foreach ($teamStats as $s) {
        if (($s['data_source'] ?? '') === 'missions') {
            return 'missions';
        }
    }
    return 'plans';
}

function perf_week_summary(PDO $pdo, string $weekStartDate, array $teamStats): array
{
    $source = perf_data_source($teamStats);
    $participants = 0;
    $totalTasks = 0;
    $completedTasks = 0;
    $delayedTasks = 0;

    foreach ($teamStats as $s) {
        $total = (int) ($s['total_tasks'] ?? 0);
        if ($total > 0) {
            $participants++;
        }
        $totalTasks += $total;
        $completedTasks += (int) ($s['completed_count'] ?? 0);
        if ($source === 'missions') {
            $delayedTasks += (int) ($s['delayed_tasks'] ?? 0);
        } else {
            $delayedTasks += max(0, (int) ($s['pending_count'] ?? 0));
        }
    }

    $completionPct = $totalTasks > 0 ? (int) round(($completedTasks / $totalTasks) * 100) : 0;

    return [
        'completion_pct' => $completionPct,
        'plans_submitted' => $participants,
        'delayed_tasks' => $delayedTasks,
        'data_source' => $source,
        'total_missions' => $totalTasks,
        'completed_missions' => $completedTasks,
    ];
}

function perf_prev_week_start(string $weekStartDate): string
{
    if (function_exists('wm_shift_week')) {
        $prev = wm_shift_week($weekStartDate, -1);
        return $prev['week_start'];
    }
    return date('Y-m-d', strtotime($weekStartDate . ' -7 days'));
}

function perf_overview_stats(PDO $pdo, string $weekStartDate, array $teamStats, array $summary): array
{
    $totalEmployees = count($teamStats);

    $scores = [];
    foreach ($teamStats as $s) {
        if ((int) ($s['total_tasks'] ?? 0) > 0) {
            $scores[] = (int) $s['score_pct'];
        }
    }
    $avgPerformance = !empty($scores) ? (int) round(array_sum($scores) / count($scores)) : 0;

    $plansPct = $totalEmployees > 0
        ? (int) round(($summary['plans_submitted'] / $totalEmployees) * 100)
        : 0;

    $trend = 0;
    try {
        $prevWeekStart = perf_prev_week_start($weekStartDate);
        $prevStats = perf_fetch_team_stats($pdo, $prevWeekStart);
        $prevSummary = perf_week_summary($pdo, $prevWeekStart, $prevStats);
        $trend = (int) $summary['completion_pct'] - (int) $prevSummary['completion_pct'];
    } catch (Throwable $e) {
        $trend = 0;
    }

    return [
        'total_employees' => $totalEmployees,
        'plans_submitted' => (int) $summary['plans_submitted'],
        'plans_pct' => $plansPct,
        'avg_performance' => $avgPerformance,
        'delayed_tasks' => (int) $summary['delayed_tasks'],
        'completion_pct' => (int) $summary['completion_pct'],
        'trend' => $trend,
        'data_source' => $summary['data_source'] ?? perf_data_source($teamStats),
    ];
}

function perf_ai_suggestion_label(int $score): array
{
    if ($score >= 85) {
        return ['label' => 'Reward', 'class' => 'reward'];
    }
    if ($score >= 80) {
        return ['label' => 'Consider', 'class' => 'consider'];
    }
    if ($score >= 70) {
        return ['label' => 'Not this week', 'class' => 'not-week'];
    }
    return ['label' => 'Needs Review', 'class' => 'needs-review'];
}

function perf_build_insights(array $teamStats, array $summary, ?array $topPerformer): array
{
    $achievements = [];
    $suggestions = [];
    $fromMissions = ($summary['data_source'] ?? '') === 'missions';

    if ($summary['completion_pct'] >= 70) {
        if ($fromMissions) {
            $achievements[] = $summary['completion_pct'] . '% of weekly missions were completed.';
        } else {
            $achievements[] = $summary['completion_pct'] . '% of weighted tasks were completed.';
        }
    } else {
        $suggestions[] = $fromMissions
            ? 'Team mission completion is below 70% � review weekly missions in To-Do.'
            : 'Team completion is below 70% � review weekly plans.';
    }

    if ($summary['plans_submitted'] > 0) {
        if ($fromMissions) {
            $achievements[] = $summary['plans_submitted'] . ' team member' . ($summary['plans_submitted'] === 1 ? '' : 's') . ' submitted missions this week.';
        } else {
            $achievements[] = $summary['plans_submitted'] . ' plan' . ($summary['plans_submitted'] === 1 ? '' : 's') . ' submitted on time.';
        }
    }

    if ($summary['delayed_tasks'] > 0) {
        $suggestions[] = $summary['delayed_tasks'] . ' mission' . ($summary['delayed_tasks'] === 1 ? '' : 's') . ' ' . ($summary['delayed_tasks'] === 1 ? 'is' : 'are') . ' delayed.';
    } else {
        $achievements[] = 'No delayed missions for this week.';
    }

    $byDept = [];
    foreach ($teamStats as $s) {
        $dept = trim((string) ($s['department'] ?? 'General')) ?: 'General';
        if (!isset($byDept[$dept])) {
            $byDept[$dept] = ['scores' => [], 'count' => 0];
        }
        if ((int) ($s['total_tasks'] ?? 0) > 0) {
            $byDept[$dept]['scores'][] = (int) $s['score_pct'];
            $byDept[$dept]['count']++;
        }
    }

    foreach ($byDept as $dept => $data) {
        if (empty($data['scores'])) {
            continue;
        }
        $avg = array_sum($data['scores']) / count($data['scores']);
        if ($avg >= 80) {
            $achievements[] = $dept . ' maintained strong completion (' . round($avg) . '%).';
        } elseif ($avg < 50) {
            $suggestions[] = $dept . ' department needs attention (' . round($avg) . '% avg).';
        }
    }

    if ($topPerformer) {
        $achievements[] = $topPerformer['full_name'] . ' leads the team at ' . (int) $topPerformer['score_pct'] . '%.';
    }

    if (count($achievements) < 3) {
        $achievements[] = $fromMissions
            ? 'Add weekly missions in To-Do before the week starts.'
            : 'Keep submitting weekly plans before Monday.';
    }
    if (count($suggestions) < 3) {
        $suggestions[] = 'Encourage early mission completion before due dates.';
        $suggestions[] = 'Split large missions into smaller items when needed.';
    }

    return [
        'achievements' => array_slice($achievements, 0, 5),
        'suggestions' => array_slice($suggestions, 0, 5),
    ];
}

function perf_viewer_profile(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare('SELECT full_name, role, department, profile_photo FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'full_name' => $row['full_name'] ?? 'User',
        'role' => ucfirst($row['role'] ?? 'employee'),
        'department' => $row['department'] ?? '',
        'profile_photo' => $row['profile_photo'] ?? '',
    ];
}
