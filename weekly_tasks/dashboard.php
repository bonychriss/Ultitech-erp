<?php
require_once __DIR__ . '/includes/performance_bootstrap.php';
require_once __DIR__ . '/includes/performance_layout.php';

$teamStats = perf_fetch_team_stats($pdo, $weekStartDate);
$summary = perf_week_summary($pdo, $weekStartDate, $teamStats);

$globalTotalTasks = 0;
$globalCompleted = 0;
$activeUsers = 0;
foreach ($teamStats as $s) {
    if ((int) ($s['total_tasks'] ?? 0) > 0) {
        $activeUsers++;
        $globalTotalTasks += (int) $s['total_tasks'];
        $globalCompleted += (int) $s['completed_count'];
    }
}
$globalPending = $globalTotalTasks - $globalCompleted;

$hasMyPlan = false;
foreach ($teamStats as $s) {
    if ((int) $s['id'] === $viewerId && (int) ($s['total_tasks'] ?? 0) > 0) {
        $hasMyPlan = true;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tasks'])) {
    $tasks = $_POST['tasks'] ?? [];
    $validTasks = [];
    foreach ($tasks as $t) {
        if (!empty(trim((string) $t))) {
            $validTasks[] = trim((string) $t);
        }
    }
    if (!empty($validTasks)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO weekly_plans (user_id, week_start_date, status) VALUES (?, ?, \'active\') ON DUPLICATE KEY UPDATE status=status');
            $stmt->execute([$viewerId, $weekStartDate]);
            $stmt = $pdo->prepare('SELECT id FROM weekly_plans WHERE user_id = ? AND week_start_date = ?');
            $stmt->execute([$viewerId, $weekStartDate]);
            $planId = $stmt->fetchColumn();
            $stmtItem = $pdo->prepare('INSERT INTO weekly_plan_items (plan_id, task_description, weight) VALUES (?, ?, ?)');
            require_once __DIR__ . '/../includes/task_scoring.php';
            $userDept = $_SESSION['department'] ?? 'General';
            foreach ($validTasks as $taskDesc) {
                $weight = calculateTaskWeight($userDept, $taskDesc);
                $stmtItem->execute([$planId, $taskDesc, $weight]);
            }
            $pdo->commit();
            header('Location: dashboard.php?module=tasks&week=' . $weekOffset);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
        }
    }
}

perf_layout_start('dashboard', 'Track team weekly plans and completion at a glance.');
?>

<div class="perf-dash-stats">
    <div class="perf-stat-box">
        <div class="label">Total Tasks</div>
        <div class="value"><?= (int) $globalTotalTasks ?></div>
    </div>
    <div class="perf-stat-box">
        <div class="label">Completed</div>
        <div class="value" style="color:var(--perf-green)"><?= (int) $globalCompleted ?></div>
    </div>
    <div class="perf-stat-box">
        <div class="label">Pending</div>
        <div class="value" style="color:var(--perf-orange)"><?= (int) $globalPending ?></div>
    </div>
    <div class="perf-stat-box">
        <div class="label">Completion</div>
        <div class="value" style="color:var(--perf-blue)"><?= (int) $summary['completion_pct'] ?>%</div>
    </div>
</div>

<?php if (!$hasMyPlan && $isCurrentWeek): ?>
<div class="perf-panel" style="margin-bottom:20px;">
    <div class="perf-panel__head"><h3>Submit your weekly plan</h3></div>
    <div style="padding:20px;">
        <form method="POST">
            <input type="text" name="tasks[]" class="form-control mb-2" placeholder="1. Major task for the week..." required>
            <input type="text" name="tasks[]" class="form-control mb-2" placeholder="2. Another key objective...">
            <button type="submit" class="perf-btn-ai" style="margin-top:8px;">Save Plan</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="perf-panel">
    <div class="perf-panel__head">
        <h3>Team overview � <?= htmlspecialchars($weekDisplayShort) ?></h3>
    </div>
    <div class="perf-table-wrap">
        <table class="perf-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Tasks</th>
                    <th>Score</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teamStats as $idx => $member): ?>
                <tr style="cursor:pointer" onclick="location.href='view_plan.php?user_id=<?= (int) $member['id'] ?>&week_offset=<?= $weekOffset ?>&module=tasks'">
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
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php perf_layout_end(); ?>
