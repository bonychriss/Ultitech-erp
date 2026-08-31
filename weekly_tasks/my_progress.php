<?php
require_once __DIR__ . '/includes/performance_bootstrap.php';
require_once __DIR__ . '/includes/performance_layout.php';

$myStats = null;
$teamStats = perf_fetch_team_stats($pdo, $weekStartDate);
foreach ($teamStats as $s) {
    if ((int) $s['id'] === $viewerId) {
        $myStats = $s;
        break;
    }
}

if (!$myStats) {
    $st = $pdo->prepare('SELECT full_name, department FROM users WHERE id = ?');
    $st->execute([$viewerId]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $myStats = [
        'full_name' => $u['full_name'] ?? $viewerName,
        'department' => $u['department'] ?? $viewerDept,
        'total_tasks' => 0,
        'completed_count' => 0,
        'score_pct' => 0,
        'total_points' => 0,
        'completed_points' => 0,
    ];
}

perf_layout_start('my-progress', 'Your weekly completion and weighted score.');
?>

<div class="perf-summary-grid" style="grid-template-columns:repeat(2,1fr);">
    <article class="perf-summary-card">
        <div class="perf-summary-card__head">
            <span class="perf-summary-icon perf-summary-icon--blue"><i class="bi bi-graph-up-arrow"></i></span>
            <span class="perf-summary-card__title perf-summary-card__title--blue">This Week</span>
        </div>
        <div class="perf-top-score"><?= (int) $myStats['score_pct'] ?>%</div>
        <p class="perf-reward-text"><?= (int) $myStats['completed_count'] ?> of <?= (int) $myStats['total_tasks'] ?> tasks completed</p>
        <a href="view_plan.php?module=tasks&week_offset=<?= $weekOffset ?>" class="perf-summary-card__link perf-summary-card__link--blue">Open my plan</a>
    </article>
    <article class="perf-summary-card">
        <div class="perf-summary-card__head">
            <span class="perf-summary-icon perf-summary-icon--green"><i class="bi bi-check-lg"></i></span>
            <span class="perf-summary-card__title perf-summary-card__title--green">Weighted points</span>
        </div>
        <div class="perf-top-score" style="color:var(--perf-green)"><?= (int) $myStats['completed_points'] ?> / <?= (int) $myStats['total_points'] ?></div>
        <p class="perf-reward-text">Points earned from completed tasks</p>
    </article>
</div>

<div class="perf-panel">
    <div class="perf-panel__head"><h3>Tip</h3></div>
    <div style="padding:20px;color:var(--perf-muted);">
        Use <strong>My Plan</strong> to add tasks and mark them complete. Higher-weight tasks improve your score faster.
    </div>
</div>

<?php perf_layout_end(); ?>
