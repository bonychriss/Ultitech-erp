<?php
require_once __DIR__ . '/includes/performance_bootstrap.php';
require_once __DIR__ . '/includes/performance_layout.php';

$teamStats = perf_fetch_team_stats($pdo, $weekStartDate);

perf_layout_start('leaderboard', 'Rankings by weighted completion for the selected week.');
?>

<div class="perf-panel">
    <div class="perf-panel__head">
        <h3>Leaderboard � <?= htmlspecialchars($weekDisplayShort) ?></h3>
    </div>
    <div class="perf-table-wrap">
        <table class="perf-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Completed</th>
                    <th>Score</th>
                    <th>AI Suggestion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teamStats as $idx => $member):
                    if ((int) ($member['total_tasks'] ?? 0) === 0) {
                        continue;
                    }
                    $sug = perf_ai_suggestion_label((int) $member['score_pct']);
                ?>
                <tr onclick="location.href='view_plan.php?user_id=<?= (int) $member['id'] ?>&week_offset=<?= $weekOffset ?>&module=tasks'" style="cursor:pointer">
                    <td><?= perf_medal_rank($idx) ?></td>
                    <td>
                        <div class="perf-employee-cell">
                            <span class="perf-avatar-sm"><?= htmlspecialchars(perf_initials($member['full_name'])) ?></span>
                            <span><?= htmlspecialchars($member['full_name']) ?></span>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($member['department'] ?? '�') ?></td>
                    <td><?= (int) $member['completed_count'] ?> / <?= (int) $member['total_tasks'] ?></td>
                    <td><span class="perf-score <?= perf_score_class((int) $member['score_pct']) ?>"><?= (int) $member['score_pct'] ?>%</span></td>
                    <td><span class="perf-pill <?= htmlspecialchars($sug['class']) ?>"><?= htmlspecialchars($sug['label']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="perf-panel__foot">
        <a href="ai_assistant.php?module=tasks<?= $weekOffset ? '&week=' . $weekOffset : '' ?>">Open AI Assistant</a>
    </div>
</div>

<?php perf_layout_end(); ?>
