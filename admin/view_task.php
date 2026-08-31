<?php
require_once '../includes/functions.php';
requireAdmin();

$task_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$task = getTaskById($task_id);

if (!$task) {
    header("Location: manage_tasks.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Task - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .detail-card { background: white; border: 1px solid #ddd; padding: 20px; border-radius: 4px; max-width: 800px; margin: 0 auto; }
        h2 { font-weight: 600; color: #374151; margin: 0; font-size: 1.5rem; }
        .detail-row { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: 500; color: #9ca3af; display: block; margin-bottom: 5px; font-size: 0.95rem; }
        .detail-value { color: #111827; font-size: 1.1rem; font-weight: 500; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 14px; text-transform: uppercase; display: inline-block; }
        .btn-back { display: inline-block; margin-bottom: 20px; color: #666; text-decoration: none; }
        .btn-back:hover { color: #333; }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_admin.php'; ?>

    <main class="main-content">
        <a href="manage_tasks.php" class="btn-back">&larr; Back to Tasks</a>
        
        <div class="detail-card">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                <h2>Task Details</h2>
                <span class="status-badge status-<?= $task['status'] ?>" style="
                    <?php if($task['status']=='approved') echo 'background:#d1fae5; color:#065f46;'; 
                    elseif($task['status']=='pending') echo 'background:#fef3c7; color:#92400e;';
                    elseif($task['status']=='implemented') echo 'background:#dbeafe; color:#1e40af;';
                    elseif($task['status']=='verified') echo 'background:#e0e7ff; color:#3730a3;';
                    ?>">
                    <?= ucfirst($task['status']) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Employee</span>
                <div class="detail-value">
                    <?= htmlspecialchars($task['full_name']) ?> 
                    <span style="font-size: 0.9rem; color: #777;">(<?= htmlspecialchars($task['department']) ?>)</span>
                </div>
            </div>

            <div class="detail-row">
                <span class="detail-label">Date Created</span>
                <div class="detail-value"><?= date('F j, Y, g:i a', strtotime($task['created_at'])) ?></div>
            </div>

            <div class="detail-row">
                <span class="detail-label">Task Type</span>
                <div class="detail-value"><?= ucfirst($task['type']) ?></div>
            </div>

            <div class="detail-row">
                <span class="detail-label">Description</span>
                <div class="detail-value" style="background: #f9fafb; padding: 15px; border-radius: 4px; border: 1px solid #eee; line-height: 1.6;">
                    <?= nl2br(htmlspecialchars($task['description'])) ?>
                </div>
            </div>

            <?php if ($task['admin_feedback']): ?>
            <div class="detail-row">
                <span class="detail-label">Admin Feedback</span>
                <div class="detail-value" style="color: #dc2626;">
                    <?= nl2br(htmlspecialchars($task['admin_feedback'])) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

