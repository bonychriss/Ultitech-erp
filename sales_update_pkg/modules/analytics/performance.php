<?php
require_once __DIR__ . '/includes/layout.php';
extract(analytics_bootstrap());

$showWeekFilter = true;
$showStatusFilter = true;
$showModuleFilter = true;

$rows = analytics_performance_rows($pdo, $filters);
$leaderboard = function_exists('wm_leaderboard')
    ? wm_leaderboard($pdo, analytics_week_start($filters), 20)
    : [];
$missionStatus = analytics_mission_status_chart($pdo, $filters);
$empPerf = analytics_employee_performance_chart($pdo, $filters);
$topPerformer = analytics_monthly_top_performer($pdo);

if ($filters['department'] !== '') {
    $leaderboard = array_values(array_filter($leaderboard, static function ($r) use ($filters) {
        return ($r['department'] ?? '') === $filters['department'];
    }));
}

analytics_page_start(
    'Weekly Mission Performance',
    'Team mission tracking, completion rates, award points, and leaderboard.',
    'performance'
);
?>
<div class="da-actions" style="margin-bottom:16px;">
    <a href="<?= htmlspecialchars(analytics_export_url('performance')) ?>" class="da-btn da-btn-secondary">
        <i class="bi bi-download"></i> Export CSV
    </a>
</div>

<?php include __DIR__ . '/includes/filters.php'; ?>

<?php if ($topPerformer): ?>
<div class="da-highlight">
    <h3><i class="bi bi-star-fill"></i> Monthly Top Performer</h3>
    <div class="da-kpi-value"><?= htmlspecialchars($topPerformer['full_name']) ?></div>
    <div class="da-kpi-sub" style="color:rgba(255,255,255,0.85);margin-top:8px;">
        <?= htmlspecialchars($topPerformer['department'] ?? '') ?>
        � <?= (int) $topPerformer['total_points'] ?> pts � <?= number_format((float) $topPerformer['avg_rate'], 1) ?>%
    </div>
</div>
<?php endif; ?>

<div class="da-charts">
    <div class="da-chart-card">
        <h3>Employee Performance</h3>
        <div class="da-chart-wrap"><canvas id="empPerfChart"></canvas></div>
    </div>
    <div class="da-chart-card">
        <h3>Mission Status Distribution</h3>
        <div class="da-chart-wrap"><canvas id="missionStatusChart"></canvas></div>
    </div>
</div>

<div class="da-table-card">
    <div class="da-table-head">
        <h3>All Users � Week of <?= htmlspecialchars(date('M j, Y', strtotime(analytics_week_start($filters)))) ?></h3>
    </div>
    <div class="da-table-wrap">
        <?php if (empty($rows)): ?>
            <div class="da-empty">No performance data for this week. Missions may not be set up yet.</div>
        <?php else: ?>
        <table class="da-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Completed</th>
                    <th>Pending</th>
                    <th>Delayed</th>
                    <th>Completion %</th>
                    <th>Award Points</th>
                    <th>Streak</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                    <td><?= htmlspecialchars($row['department'] ?? '�') ?></td>
                    <td><?= (int) $row['completed_missions'] ?> / <?= (int) $row['total_missions'] ?></td>
                    <td><?= (int) $row['pending_missions'] ?></td>
                    <td><?= (int) $row['delayed_missions'] ?></td>
                    <td>
                        <span class="da-badge da-badge--<?= (float) $row['completion_rate'] >= 80 ? 'green' : ((float) $row['completion_rate'] >= 50 ? 'amber' : 'red') ?>">
                            <?= number_format((float) $row['completion_rate'], 1) ?>%
                        </span>
                    </td>
                    <td><?= (int) $row['award_points'] ?></td>
                    <td><?= (int) $row['streak_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="da-table-card">
    <div class="da-table-head">
        <h3>Leaderboard / Ranking</h3>
    </div>
    <div class="da-table-wrap">
        <?php if (empty($leaderboard)): ?>
            <div class="da-empty">No leaderboard data for this week.</div>
        <?php else: ?>
        <table class="da-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Completion %</th>
                    <th>Award Points</th>
                    <th>Streak</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaderboard as $item): ?>
                <tr>
                    <td><span class="da-rank da-rank--<?= min(3, (int) $item['rank']) ?>"><?= (int) $item['rank'] ?></span></td>
                    <td><strong><?= htmlspecialchars($item['full_name']) ?></strong></td>
                    <td><?= htmlspecialchars($item['department'] ?? '�') ?></td>
                    <td><?= number_format((float) $item['completion_rate'], 1) ?>%</td>
                    <td><?= (int) $item['award_points'] ?></td>
                    <td><?= (int) $item['streak_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php
analytics_render_chart_script('empPerfChart', [
    'type' => 'bar',
    'data' => [
        'labels' => $empPerf['labels'],
        'datasets' => [[
            'label' => 'Completion %',
            'data' => $empPerf['data'],
            'backgroundColor' => '#6366f1',
        ]],
    ],
    'options' => [
        'responsive' => true,
        'maintainAspectRatio' => false,
        'indexAxis' => count($empPerf['labels']) > 6 ? 'y' : 'x',
    ],
]);

analytics_render_chart_script('missionStatusChart', [
    'type' => 'doughnut',
    'data' => [
        'labels' => $missionStatus['labels'],
        'datasets' => [[
            'data' => $missionStatus['data'],
            'backgroundColor' => ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
        ]],
    ],
    'options' => ['responsive' => true, 'maintainAspectRatio' => false],
]);

analytics_page_end();
