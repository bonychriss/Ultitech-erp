<?php
require_once '../includes/functions.php';
requireAdmin();

// Handle Actions
$message = '';
$debug_info = '';

// Debug: Log all POST data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debug_info = 'POST received. Data: ' . print_r($_POST, true);
    error_log($debug_info);
    
    $action = $_POST['action'] ?? '';
    $task_id = (int)($_POST['task_id'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');

    if ($task_id > 0) {
        $success = false;
        if ($action === 'approve_plan') {
            $success = updateTaskStatus($task_id, 'approved');
            $message = $success ? 'Plan approved successfully!' : 'Failed to approve plan.';
        } elseif ($action === 'reject_plan') {
            $success = updateTaskStatus($task_id, 'pending', $feedback);
            $message = $success ? 'Plan rejected with feedback.' : 'Failed to reject plan.';
        } elseif ($action === 'verify_completion') {
            $success = updateTaskStatus($task_id, 'verified');
            $message = $success ? 'Task verified successfully!' : 'Failed to verify task.';
        } elseif ($action === 'reject_completion') {
            $success = updateTaskStatus($task_id, 'approved', $feedback);
            $message = $success ? 'Task rejected with feedback.' : 'Failed to reject task.';
        }
        
        if ($success) {
            header("Location: manage_tasks.php?success=1");
            exit;
        }
    } else {
        $message = 'Invalid task ID: ' . $task_id;
    }
} else {
    $debug_info = 'Request method: ' . $_SERVER['REQUEST_METHOD'];
}

$pending_plans = getPendingTasks('pending');
$approved_plans = getPendingTasks('approved');
$pending_verifications = getPendingTasks('implemented');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .task-card { background: white; border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; border-radius: 4px; }
        .task-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; text-transform: uppercase; }
        .btn-group { display: flex; gap: 5px; margin-top: 10px; }
        .feedback-input { width: 100%; padding: 5px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
    <script>
        function showRejectForm(id) {
            var form = document.getElementById('reject-form-' + id);
            var textarea = form.querySelector('.feedback-input');
            form.style.display = 'block';
            textarea.style.display = 'block';
            document.getElementById('actions-' + id).style.display = 'none';
        }
        function cancelReject(id) {
            document.getElementById('reject-form-' + id).style.display = 'none';
            document.getElementById('actions-' + id).style.display = 'flex';
        }
    </script>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_admin.php'; ?>

    <main class="main-content">
        <h2>Manage Tasks</h2>
        
        <?php if (!empty($debug_info)): ?>
            <div style="padding: 10px; background: #e0e7ff; color: #3730a3; border-radius: 4px; margin-bottom: 20px; font-family: monospace; font-size: 12px;">
                <strong>Debug:</strong> <?= htmlspecialchars($debug_info) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success'])): ?>
            <div style="padding: 10px; background: #d1fae5; color: #065f46; border-radius: 4px; margin-bottom: 20px;">
                Action completed successfully!
            </div>
        <?php endif; ?>
        
        <?php if (!empty($message)): ?>
            <div style="padding: 10px; background: #fef3c7; color: #92400e; border-radius: 4px; margin-bottom: 20px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <h3>Pending Plan Approvals</h3>
            <?php if (empty($pending_plans)): ?>
                <p>No pending plans.</p>
            <?php else: ?>
                <?php foreach ($pending_plans as $task): ?>
                    <div class="task-card">
                        <div class="task-header">
                            <strong><?= htmlspecialchars($task['full_name']) ?></strong>
                            <small><?= ucfirst($task['type']) ?> Task â€¢ <?= date('M d, H:i', strtotime($task['created_at'])) ?></small>
                        </div>
                        <p><?= nl2br(htmlspecialchars($task['description'])) ?></p>
                        
                        <div id="actions-<?= $task['id'] ?>" class="btn-group">
                            <form method="POST" action="" onsubmit="alert('Form submitting! Action: approve_plan, Task ID: <?= $task['id'] ?>'); return true;">
                                <input type="hidden" name="action" value="approve_plan">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary" onclick="alert('Button clicked!');">Approve Plan</button>
                            </form>
                            <button onclick="showRejectForm(<?= $task['id'] ?>)" class="btn btn-sm btn-danger">Reject</button>
                        </div>

                        <div id="reject-form-<?= $task['id'] ?>" style="display:none;">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="reject_plan">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <textarea name="feedback" class="feedback-input" placeholder="Reason for rejection..." required></textarea>
                                <div class="btn-group">
                                    <button type="submit" class="btn btn-sm btn-danger">Confirm Reject</button>
                                    <button type="button" onclick="cancelReject(<?= $task['id'] ?>)" class="btn btn-sm btn-secondary">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="section" style="margin-top: 30px;">
            <h3>Pending Completion Verification</h3>
            <?php if (empty($pending_verifications)): ?>
                <p>No tasks waiting for verification.</p>
            <?php else: ?>
                <?php foreach ($pending_verifications as $task): ?>
                    <div class="task-card">
                        <div class="task-header">
                            <strong><?= htmlspecialchars($task['full_name']) ?></strong>
                            <small><?= ucfirst($task['type']) ?> Task â€¢ Implemented</small>
                        </div>
                        <p><?= nl2br(htmlspecialchars($task['description'])) ?></p>
                        
                        <div id="actions-<?= $task['id'] ?>" class="btn-group">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="verify_completion">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Verify Completion</button>
                            </form>
                            <button onclick="showRejectForm(<?= $task['id'] ?>)" class="btn btn-sm btn-danger">Reject</button>
                        </div>

                        <div id="reject-form-<?= $task['id'] ?>" style="display:none;">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="reject_completion">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <textarea name="feedback" class="feedback-input" placeholder="Reason for rejection..." required></textarea>
                                <div class="btn-group">
                                    <button type="submit" class="btn btn-sm btn-danger">Confirm Reject</button>
                                    <button type="button" onclick="cancelReject(<?= $task['id'] ?>)" class="btn btn-sm btn-secondary">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

