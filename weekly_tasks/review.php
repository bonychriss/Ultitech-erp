<?php
require_once __DIR__ . '/includes/performance_bootstrap.php';
require_once __DIR__ . '/includes/performance_layout.php';

if (!$isAdmin) {
    header('Location: ai_assistant.php?module=tasks');
    exit;
}

$teamStats = perf_fetch_team_stats($pdo, $weekStartDate);

perf_layout_start('review', 'Review submitted plans and team progress (admin).');
?>

<div class="perf-panel">
    <div class="perf-panel__head"><h3>Plans to review � <?= htmlspecialchars($weekDisplayShort) ?></h3></div>
    <div class="perf-table-wrap">
        <table class="perf-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Tasks</th>
                    <th>Score</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teamStats as $member): ?>
                <tr>
                    <td><?= htmlspecialchars($member['full_name']) ?></td>
                    <td><?= htmlspecialchars($member['department'] ?? '�') ?></td>
                    <td><?= (int) $member['total_tasks'] ?></td>
                    <td><span class="perf-score <?= perf_score_class((int) $member['score_pct']) ?>"><?= (int) $member['score_pct'] ?>%</span></td>
                    <td>
                        <a href="view_plan.php?user_id=<?= (int) $member['id'] ?>&week_offset=<?= $weekOffset ?>&module=tasks" class="perf-summary-card__link perf-summary-card__link--blue">View plan</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php perf_layout_end(); ?>
