<?php
require_once '../includes/functions.php';
requireLogin();

$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_user = $_GET['user_id'] ?? '';

$tasks = getAllTasks($filter_date, $filter_user);

// Get users for filter
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Tasks - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .task-card { background: white; border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; border-radius: 4px; }
        .task-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
        .status-badge { padding: 2px 6px; border-radius: 4px; font-size: 11px; text-transform: uppercase; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-implemented { background: #dbeafe; color: #1e40af; }
        .status-verified { background: #e0e7ff; color: #3730a3; }
        .filters { background: #f3f4f6; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>

    <main class="main-content">
        <h2>Community Tasks</h2>

        <div class="filters">
            <form method="GET" style="display: flex; gap: 10px; align-items: flex-end;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Date</label>
                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label>User</label>
                    <select name="user_id" class="form-control">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn">Filter</button>
            </form>
        </div>

        <div class="tasks-list">
            <?php if (empty($tasks)): ?>
                <p>No tasks found for this selection.</p>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card">
                        <div class="task-header">
                            <strong><?= htmlspecialchars($task['full_name']) ?></strong>
                            <span class="status-badge status-<?= $task['status'] ?>"><?= ucfirst($task['status']) ?></span>
                        </div>
                        <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                            <?= ucfirst($task['type']) ?> Task â€¢ <?= date('H:i', strtotime($task['created_at'])) ?>
                        </div>
                        <p><?= nl2br(htmlspecialchars($task['description'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

