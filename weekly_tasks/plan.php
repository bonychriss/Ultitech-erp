<?php
require_once __DIR__ . '/includes/performance_bootstrap.php';
require_once __DIR__ . '/includes/performance_layout.php';
require_once __DIR__ . '/../includes/task_scoring.php';

$userId = $viewerId;
$userDept = $_SESSION['department'] ?? 'General';
$error = '';

$stmt = $pdo->prepare('SELECT * FROM weekly_plans WHERE user_id = ? AND week_start_date = ?');
$stmt->execute([$userId, $weekStartDate]);
$existingPlan = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tasks = $_POST['tasks'] ?? [];
    $validTasks = [];
    foreach ($tasks as $t) {
        if (!empty(trim((string) $t))) {
            $validTasks[] = trim((string) $t);
        }
    }
    if (empty($validTasks)) {
        $error = 'Please enter at least one task.';
    } else {
        try {
            $pdo->beginTransaction();
            if ($existingPlan) {
                $planId = $existingPlan['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO weekly_plans (user_id, week_start_date, status) VALUES (?, ?, 'active')");
                $stmt->execute([$userId, $weekStartDate]);
                $planId = $pdo->lastInsertId();
            }
            $stmtItem = $pdo->prepare('INSERT INTO weekly_plan_items (plan_id, task_description, weight) VALUES (?, ?, ?)');
            foreach ($validTasks as $taskDesc) {
                $weight = calculateTaskWeight($userDept, $taskDesc);
                $stmtItem->execute([$planId, $taskDesc, $weight]);
            }
            $pdo->commit();
            header('Location: view_plan.php?module=tasks');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save plan.';
        }
    }
}

perf_layout_start('my-plan', 'Describe your major tasks for this week.');
?>

<div class="perf-panel" style="max-width:640px;">
    <div class="perf-panel__head">
        <h3>Weekly Planner</h3>
        <span style="font-size:0.85rem;color:var(--perf-muted);"><?= htmlspecialchars($weekDisplayShort) ?></span>
    </div>
    <div style="padding:20px;">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div id="tasks-wrapper">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="mb-2 d-flex gap-2 align-items-center">
                    <span style="font-weight:700;color:var(--perf-muted);width:20px;"><?= $i ?>.</span>
                    <input type="text" name="tasks[]" class="form-control" placeholder="Enter a major task..." <?= $i === 1 ? 'required autofocus' : '' ?>>
                </div>
                <?php endfor; ?>
            </div>
            <button type="button" class="btn btn-link btn-sm px-0 mt-2" onclick="addTaskField()">+ Add another task</button>
            <button type="submit" class="perf-btn-ai w-100 mt-3">Save Weekly Plan</button>
        </form>
    </div>
</div>

<script>
let taskCount = 3;
function addTaskField() {
    taskCount++;
    const w = document.getElementById('tasks-wrapper');
    const div = document.createElement('div');
    div.className = 'mb-2 d-flex gap-2 align-items-center';
    div.innerHTML = '<span style="font-weight:700;color:var(--perf-muted);width:20px;">' + taskCount + '.</span><input type="text" name="tasks[]" class="form-control" placeholder="Enter another task...">';
    w.appendChild(div);
    div.querySelector('input').focus();
}
</script>

<?php perf_layout_end(); ?>
