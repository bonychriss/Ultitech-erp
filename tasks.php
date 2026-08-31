<?php
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$type = $_GET['type'] ?? 'daily';
$valid_types = ['daily', 'weekly', 'monthly'];
if (!in_array($type, $valid_types)) $type = 'daily';

// Handle Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $description = trim($_POST['description']);
    if (!empty($description)) {
        createTask($user_id, $type, $description);
        header("Location: tasks.php?type=$type");
        exit;
    }
}

// Handle Mark Implemented
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'implement') {
    $task_id = (int)$_POST['task_id'];
    $task = getTaskById($task_id);
    if ($task && $task['user_id'] == $user_id && $task['status'] === 'approved') {
        updateTaskStatus($task_id, 'implemented');
        header("Location: tasks.php?type=$type");
        exit;
    }
}

$tasks = getTasks($user_id, $type);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
        .tab { padding: 8px 16px; text-decoration: none; color: #555; border-radius: 4px; }
        .tab.active { background-color: #007bff; color: white; }
        .task-card { background: white; border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; border-radius: 4px; }
        .task-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; text-transform: uppercase; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-implemented { background: #dbeafe; color: #1e40af; }
        .status-verified { background: #e0e7ff; color: #3730a3; }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>

    <main class="main-content">
        <h2>My Tasks</h2>

        <div class="tabs">
            <a href="?type=daily" class="tab <?= $type === 'daily' ? 'active' : '' ?>">Daily</a>
            <a href="?type=weekly" class="tab <?= $type === 'weekly' ? 'active' : '' ?>">Weekly</a>
            <a href="?type=monthly" class="tab <?= $type === 'monthly' ? 'active' : '' ?>">Monthly</a>
        </div>

        <div class="form-container" style="margin-bottom: 20px;">
            <h3>Add New <?= ucfirst($type) ?> Task</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <textarea name="description" class="form-control" placeholder="Describe your task..." required></textarea>
                </div>
                <button type="submit" class="btn">Add Task</button>
            </form>
        </div>

        <div class="tasks-list">
            <?php if (empty($tasks)): ?>
                <p>No tasks found.</p>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card">
                        <div class="task-header">
                            <span class="status-badge status-<?= $task['status'] ?>"><?= ucfirst($task['status']) ?></span>
                            <small><?= date('M d, Y H:i', strtotime($task['created_at'])) ?></small>
                        </div>
                        <p><?= nl2br(htmlspecialchars($task['description'])) ?></p>
                        <?php if ($task['admin_feedback']): ?>
                            <div style="margin-top: 10px; padding: 10px; background: #f9fafb; border-left: 3px solid #6b7280;">
                                <strong>Admin Feedback:</strong> <?= htmlspecialchars($task['admin_feedback']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($task['status'] === 'approved'): ?>
                            <div style="margin-top: 10px;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="implement">
                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                    <button type="submit" class="btn btn-sm">Mark as Implemented</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

