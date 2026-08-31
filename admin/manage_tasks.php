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
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; text-transform: uppercase; }
        .btn-group { display: flex; gap: 5px; margin-top: 10px; }
        .feedback-input { width: 100%; padding: 5px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px; }
        
        /* Table Compact Styles */
        table { font-size: 13px; }
        th, td { padding: 8px 10px !important; }
        h3 { font-size: 1.1rem; margin-bottom: 15px; }
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
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; background: white; border: 1px solid #ddd; border-radius: 4px;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left; border-bottom: 1px solid #ddd;">
                                <th style="padding: 12px; color: #555;">Date</th>
                                <th style="padding: 12px; color: #555;">Employee</th>
                                <th style="padding: 12px; color: #555;">Type</th>
                                <th style="padding: 12px; color: #555;">Description</th>
                                <th style="padding: 12px; color: #555;">View</th>
                                <th style="padding: 12px; color: #555; width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_plans as $task): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px; white-space: nowrap;">
                                        <?= date('M d, H:i', strtotime($task['created_at'])) ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <strong><?= htmlspecialchars($task['full_name']) ?></strong><br>
                                        <small style="color: #777;"><?= htmlspecialchars($task['department']) ?></small>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span class="status-badge" style="background: #fef3c7; color: #92400e;">
                                            <?= ucfirst($task['type']) ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?= nl2br(htmlspecialchars($task['description'])) ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <a href="view_task.php?id=<?= $task['id'] ?>" style="color: #007bff; text-decoration: none; font-weight: 500;">View</a>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div id="actions-<?= $task['id'] ?>" class="btn-group">
                                            <form method="POST" action="" style="display:inline;">
                                                <input type="hidden" name="action" value="approve_plan">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <button type="submit" style="background:none; border:none; color:#059669; font-weight:600; cursor:pointer; padding:0; margin-right:10px; text-decoration:underline;">Approve</button>
                                            </form>
                                            <button onclick="showRejectForm(<?= $task['id'] ?>)" style="background:none; border:none; color:#dc2626; font-weight:600; cursor:pointer; padding:0; text-decoration:underline;">Reject</button>
                                        </div>

                                        <div id="reject-form-<?= $task['id'] ?>" style="display:none; margin-top: 10px;">
                                            <form method="POST" action="">
                                                <input type="hidden" name="action" value="reject_plan">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <textarea name="feedback" class="feedback-input" placeholder="Reason..." required style="min-height: 60px;"></textarea>
                                                <div class="btn-group" style="margin-top: 5px;">
                                                    <button type="submit" class="btn btn-sm btn-danger">Confirm</button>
                                                    <button type="button" onclick="cancelReject(<?= $task['id'] ?>)" class="btn btn-sm btn-secondary">Cancel</button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="section" style="margin-top: 30px;">
            <h3>Pending Completion Verification</h3>
            <?php if (empty($pending_verifications)): ?>
                <p>No tasks waiting for verification.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; background: white; border: 1px solid #ddd; border-radius: 4px;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left; border-bottom: 1px solid #ddd;">
                                <th style="padding: 12px; color: #555;">Date</th>
                                <th style="padding: 12px; color: #555;">Employee</th>
                                <th style="padding: 12px; color: #555;">Type</th>
                                <th style="padding: 12px; color: #555;">Description</th>
                                <th style="padding: 12px; color: #555;">View</th>
                                <th style="padding: 12px; color: #555; width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_verifications as $task): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px; white-space: nowrap;">
                                        <?= date('M d, H:i', strtotime($task['created_at'])) ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <strong><?= htmlspecialchars($task['full_name']) ?></strong><br>
                                        <small style="color: #777;"><?= htmlspecialchars($task['department']) ?></small>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span class="status-badge" style="background: #dbeafe; color: #1e40af;">
                                            <?= ucfirst($task['type']) ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?= nl2br(htmlspecialchars($task['description'])) ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <a href="view_task.php?id=<?= $task['id'] ?>" style="color: #007bff; text-decoration: none; font-weight: 500;">View</a>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div id="actions-<?= $task['id'] ?>" class="btn-group">
                                            <form method="POST" action="" style="display:inline;">
                                                <input type="hidden" name="action" value="verify_completion">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <button type="submit" style="background:none; border:none; color:#2563eb; font-weight:600; cursor:pointer; padding:0; margin-right:10px; text-decoration:underline;">Verify</button>
                                            </form>
                                            <button onclick="showRejectForm(<?= $task['id'] ?>)" style="background:none; border:none; color:#dc2626; font-weight:600; cursor:pointer; padding:0; text-decoration:underline;">Reject</button>
                                        </div>

                                        <div id="reject-form-<?= $task['id'] ?>" style="display:none; margin-top: 10px;">
                                            <form method="POST" action="">
                                                <input type="hidden" name="action" value="reject_completion">
                                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                <textarea name="feedback" class="feedback-input" placeholder="Reason..." required style="min-height: 60px;"></textarea>
                                                <div class="btn-group" style="margin-top: 5px;">
                                                    <button type="submit" class="btn btn-sm btn-danger">Confirm</button>
                                                    <button type="button" onclick="cancelReject(<?= $task['id'] ?>)" class="btn btn-sm btn-secondary">Cancel</button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="section" style="margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px;">
            <h3>Task History (All Tasks)</h3>
            <?php 
            // Fetch all tasks (limit to last 50 for performance)
            $allTasks = getAllTasks(); 
            $allTasks = array_slice($allTasks, 0, 50);
            ?>
            
            <?php if (empty($allTasks)): ?>
                <p>No tasks found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; background: white; border: 1px solid #ddd; border-radius: 4px;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left; border-bottom: 1px solid #ddd;">
                                <th style="padding: 12px; color: #555;">Date</th>
                                <th style="padding: 12px; color: #555;">Employee</th>
                                <th style="padding: 12px; color: #555;">Type</th>
                                <th style="padding: 12px; color: #555;">Description</th>
                                <th style="padding: 12px; color: #555;">View</th>
                                <th style="padding: 12px; color: #555;">Status</th>
                                <th style="padding: 12px; color: #555;">Admin Feedback</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allTasks as $tTask): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px; white-space: nowrap;">
                                        <?= date('M d, H:i', strtotime($tTask['created_at'])) ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <strong><?= htmlspecialchars($tTask['full_name']) ?></strong><br>
                                        <small style="color: #777;"><?= htmlspecialchars($tTask['department']) ?></small>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="font-size: 0.8rem; padding: 2px 6px; background: #eee; border-radius: 4px;">
                                            <?= ucfirst($tTask['type']) ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?= nl2br(htmlspecialchars($tTask['description'])) ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <a href="view_task.php?id=<?= $tTask['id'] ?>" style="color: #007bff; text-decoration: none; font-weight: 500;">View</a>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span class="status-badge status-<?= $tTask['status'] ?>" style="
                                            <?php if($tTask['status']=='approved') echo 'background:#d1fae5; color:#065f46;'; 
                                            elseif($tTask['status']=='pending') echo 'background:#fef3c7; color:#92400e;';
                                            elseif($tTask['status']=='implemented') echo 'background:#dbeafe; color:#1e40af;';
                                            elseif($tTask['status']=='verified') echo 'background:#e0e7ff; color:#3730a3;';
                                            ?>">
                                            <?= ucfirst($tTask['status']) ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; color: #666; font-style: italic;">
                                        <?= $tTask['admin_feedback'] ? htmlspecialchars($tTask['admin_feedback']) : 'â€”' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

