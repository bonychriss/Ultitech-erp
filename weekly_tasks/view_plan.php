<?php
require_once __DIR__ . '/includes/performance_bootstrap.php';
require_once __DIR__ . '/includes/performance_layout.php';

$targetUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : $viewerId;
$isOwner = ($targetUserId === $viewerId);
$canEdit = ($isOwner && $weekOffset === 0);

$stmtUser = $pdo->prepare('SELECT full_name, department FROM users WHERE id = ?');
$stmtUser->execute([$targetUserId]);
$targetUser = $stmtUser->fetch();
if (!$targetUser) {
    die('User not found.');
}

$stmt = $pdo->prepare('SELECT * FROM weekly_plans WHERE user_id = ? AND week_start_date = ?');
$stmt->execute([$targetUserId, $weekStartDate]);
$plan = $stmt->fetch();

$tasks = [];
$totalWeight = 0;
$completedWeight = 0;
$pct = 0;

if ($plan) {
    $stmtItems = $pdo->prepare('SELECT * FROM weekly_plan_items WHERE plan_id = ? ORDER BY id ASC');
    $stmtItems->execute([$plan['id']]);
    $tasks = $stmtItems->fetchAll();
    foreach ($tasks as $t) {
        $totalWeight += (int) $t['weight'];
        if ($t['is_completed']) {
            $completedWeight += (int) $t['weight'];
        }
    }
    if ($totalWeight > 0) {
        $pct = (int) round(($completedWeight / $totalWeight) * 100);
    }
}

if ($isOwner && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_task']) && $plan) {
    $newTask = trim((string) $_POST['new_task']);
    $priority = $_POST['priority'] ?? 'medium';
    $weights = ['high' => 5, 'medium' => 3, 'low' => 1];
    $weight = $weights[$priority] ?? 3;
    if ($newTask !== '') {
        $stmtInsert = $pdo->prepare('INSERT INTO weekly_plan_items (plan_id, task_description, weight, priority) VALUES (?, ?, ?, ?)');
        $stmtInsert->execute([$plan['id'], $newTask, $weight, $priority]);
        header('Location: view_plan.php?user_id=' . $targetUserId . '&week_offset=' . $weekOffset . '&module=tasks');
        exit;
    }
}

$planTitle = $isOwner ? 'My Weekly Plan' : $targetUser['full_name'] . "'s Plan";
perf_layout_start('my-plan', $isOwner ? 'Plan your week and mark tasks complete.' : 'View team member weekly plan.');
?>

<style>
.perf-plan-card { background: var(--perf-card); border: 1px solid var(--perf-border); border-radius: 12px; padding: 20px; }
.perf-plan-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--perf-border); }
.perf-plan-progress { height: 8px; background: #f3f4f6; border-radius: 4px; overflow: hidden; margin-top: 8px; }
.perf-plan-progress-fill { height: 100%; background: linear-gradient(90deg, #10b981, #059669); }
.perf-task-list { list-style: none; padding: 0; margin: 0; }
.perf-task-item { display: flex; align-items: center; padding: 14px 0; border-bottom: 1px solid #f3f4f6; }
.perf-check { width: 28px; height: 28px; border: 2px solid #d1d5db; border-radius: 50%; margin-right: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; cursor: default; }
.perf-check.clickable { cursor: pointer; }
.perf-check.done { background: #10b981; border-color: #10b981; color: #fff; }
.perf-task-item.done .perf-task-text { text-decoration: line-through; color: #9ca3af; }
.perf-badge-prio { padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-left: 8px; }
.p-high { background: #fee2e2; color: #991b1b; }
.p-medium { background: #ffedd5; color: #9a3412; }
.p-low { background: #dbeafe; color: #1e40af; }
</style>

<div class="perf-plan-card">
    <div class="perf-plan-head">
        <div>
            <h2 style="margin:0;font-size:1.1rem;font-weight:700;"><?= htmlspecialchars($planTitle) ?></h2>
            <span style="font-size:0.85rem;color:var(--perf-muted);"><?= htmlspecialchars($weekDisplayShort) ?></span>
        </div>
        <?php if (!$plan && $isOwner): ?>
            <a href="plan.php?module=tasks" class="perf-btn-ai" style="font-size:0.8rem;padding:8px 14px;">Create Plan</a>
        <?php elseif ($plan): ?>
            <span class="perf-pill reward">Active</span>
        <?php else: ?>
            <span class="perf-pill not-week">No Plan</span>
        <?php endif; ?>
    </div>

    <?php if ($plan): ?>
        <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;font-size:0.85rem;font-weight:600;">
                <span>Performance Score</span><span><?= $pct ?>%</span>
            </div>
            <div class="perf-plan-progress"><div class="perf-plan-progress-fill" style="width:<?= $pct ?>%"></div></div>
        </div>
        <ul class="perf-task-list">
            <?php foreach ($tasks as $t):
                $prio = $t['priority'] ?? 'medium';
            ?>
            <li class="perf-task-item <?= $t['is_completed'] ? 'done' : '' ?>" id="task-<?= (int) $t['id'] ?>">
                <div class="perf-check <?= $t['is_completed'] ? 'done' : '' ?> <?= $canEdit ? 'clickable' : '' ?>"
                     <?php if ($canEdit): ?>onclick="toggleTask(<?= (int) $t['id'] ?>, this)"<?php endif; ?>>
                    <?php if ($t['is_completed']): ?><i class="bi bi-check-lg"></i><?php endif; ?>
                </div>
                <span class="perf-task-text" style="flex:1;"><?= htmlspecialchars($t['task_description']) ?></span>
                <span class="perf-badge-prio p-<?= htmlspecialchars($prio) ?>"><?= htmlspecialchars(ucfirst($prio)) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="perf-empty">No plan for this week.</p>
    <?php endif; ?>

    <?php if ($plan && $canEdit): ?>
        <form method="POST" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--perf-border);display:flex;gap:8px;flex-wrap:wrap;">
            <input type="text" name="new_task" class="form-control" placeholder="Add a new task..." required style="flex:1;min-width:200px;">
            <select name="priority" class="form-select" style="max-width:120px;">
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="low">Low</option>
            </select>
            <button type="submit" class="perf-btn-ai" style="padding:8px 16px;">+ Add</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<script>
function toggleTask(taskId, el) {
    const newState = !el.classList.contains('done');
    el.classList.toggle('done', newState);
    el.closest('.perf-task-item').classList.toggle('done', newState);
    if (newState) el.innerHTML = '<i class="bi bi-check-lg"></i>';
    else el.innerHTML = '';
    fetch('api_toggle.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ item_id: taskId, completed: newState })
    }).then(r => r.json()).then(d => { if (!d.success) location.reload(); else location.reload(); })
      .catch(() => location.reload());
}
</script>
<?php endif; ?>

<?php perf_layout_end(); ?>
