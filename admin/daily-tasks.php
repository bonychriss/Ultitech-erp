<?php
require_once '../includes/functions.php';
requireAdmin();

$success = '';
$error = '';
$dateFilter = $_GET['date'] ?? date('Y-m-d');

// Handle Feedback Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['task_id'])) {
    $taskId = (int)$_POST['task_id'];
    $feedback = trim($_POST['feedback'] ?? '');
    
    if ($taskId > 0) {
        $stmt = $pdo->prepare("UPDATE tasks SET admin_feedback = ? WHERE id = ?");
        if ($stmt->execute([$feedback, $taskId])) {
            $success = 'Feedback sent successfully.';
        } else {
            $error = 'Failed to send feedback.';
        }
    }
}

// Fetch Tasks
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name, u.department 
    FROM tasks t 
    JOIN users u ON t.user_id = u.id 
    WHERE t.type = 'daily' AND DATE(t.created_at) = ? 
    ORDER BY u.department, u.full_name
");
$stmt->execute([$dateFilter]);
$tasks = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Tasks Review - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .task-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .task-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 20px;
            border: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
        }
        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        .user-name { font-weight: 600; color: #111827; }
        .user-dept { font-size: 0.85rem; color: #6b7280; }
        .task-desc { 
            white-space: pre-wrap; 
            color: #374151; 
            font-size: 0.95rem; 
            margin-bottom: 20px;
            flex-grow: 1;
        }
        .feedback-section {
            background: #f9fafb;
            padding: 12px;
            border-radius: 6px;
            margin-top: auto;
        }
        .feedback-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        .btn-send {
            background: #2563eb;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            width: 100%;
        }
        .btn-send:hover { background: #1d4ed8; }
        .date-picker {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            margin-left: 10px;
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_admin.php'; ?>
    
    <main class="main-content">
        <div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h1>Daily User Tasks</h1>
            <form method="GET" style="display:flex; align-items:center;">
                <label>Date:</label>
                <input type="date" name="date" value="<?= $dateFilter ?>" class="date-picker" onchange="this.form.submit()">
            </form>
        </div>

        <?php if ($success): ?><div style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (empty($tasks)): ?>
            <div style="text-align:center; padding:40px; color:#6b7280;">No tasks found for this date.</div>
        <?php else: ?>
            <div class="task-grid">
                <?php foreach ($tasks as $task): ?>
                <div class="task-card">
                    <div class="task-header">
                        <div>
                            <div class="user-name"><?= htmlspecialchars($task['full_name']) ?></div>
                            <div class="user-dept"><?= htmlspecialchars($task['department']) ?></div>
                        </div>
                        <div style="font-size:0.8rem; color:#9ca3af;"><?= date('H:i', strtotime($task['created_at'])) ?></div>
                    </div>
                    <div class="task-desc"><?= nl2br(htmlspecialchars($task['description'])) ?></div>
                    
                    <div class="feedback-section">
                        <form method="POST">
                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                            <label style="font-size:0.8rem; font-weight:600; color:#4b5563; display:block; margin-bottom:4px;">Admin Feedback</label>
                            <textarea name="feedback" class="feedback-input" rows="2" placeholder="Write feedback..."><?= htmlspecialchars($task['admin_feedback'] ?? '') ?></textarea>
                            <button type="submit" class="btn-send">Update Feedback</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
