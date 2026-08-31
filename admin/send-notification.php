<?php
require_once '../includes/functions.php';
requireAdmin();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $audience = $_POST['audience'] ?? 'user';
    $type = $_POST['type'] ?? 'info';
    $target_user = (int)($_POST['target_user'] ?? 0);

    if (empty($title) || empty($message)) {
        $error = 'Title and message are required.';
    } else {
        try {
            $opts = [
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'audience' => $audience
            ];

            if ($audience === 'user' && $target_user > 0) {
                $opts['user_id'] = $target_user;
            }

            createNotification($opts);
            $success = 'Notification sent successfully!';
        } catch (Exception $e) {
            $error = 'Failed to send notification: ' . $e->getMessage();
        }
    }
}

// Get all users for the dropdown
$users = [];
try {
    $companySql = "";
    $companyParams = [];
    if (columnExists('users', 'company_id', $pdo)) {
        $companySql = " AND company_id = ?";
        $companyParams[] = (int) currentCompanyId();
    }
    $stmt = $pdo->prepare("SELECT id, full_name, department FROM users WHERE is_active = 1{$companySql} ORDER BY full_name");
    $stmt->execute($companyParams);
    $users = $stmt->fetchAll();
} catch (Exception $e) { /* ignore */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Notification - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .form-container { max-width: 600px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;
        }
        .preview-box {
            padding: 10px; border-radius: 4px; margin-top: 5px; font-size: 13px;
            display: flex; align-items: flex-start; gap: 8px;
        }
        .preview-info { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
        .preview-success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .preview-warning { background: #fef9c3; color: #ca8a04; border: 1px solid #fde047; }
        .preview-danger { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <?php require_once '../includes/header_admin.php'; ?>

    <main class="main-content">
        <div class="actions">
            <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>

        <div class="form-container">
            <h2>Send Notification</h2>
            
            <?php if ($success): ?>
                <div class="success-message"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" required placeholder="Notification Title">
                </div>

                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" rows="4" required placeholder="Notification content..."></textarea>
                </div>

                <div class="form-group">
                    <label for="type">Type (Sensitivity)</label>
                    <select id="type" name="type" onchange="updatePreview()">
                        <option value="info">Info (Blue)</option>
                        <option value="success">Success (Green)</option>
                        <option value="warning">Warning (Yellow)</option>
                        <option value="danger">Urgent/Sensitive (Red)</option>
                    </select>
                    <div id="typePreview" class="preview-box preview-info">
                        <span>â„¹ï¸</span>
                        <span>This is how the notification will appear.</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="audience">Audience</label>
                    <select id="audience" name="audience" onchange="toggleUserSelect()">
                        <option value="user">Specific User</option>
                        <option value="all">Broadcast (All Users)</option>
                        <option value="admin">All Admins</option>
                    </select>
                </div>

                <div class="form-group" id="userSelectGroup">
                    <label for="target_user">Target User</label>
                    <select id="target_user" name="target_user">
                        <option value="">-- Select User --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['department']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Send Notification</button>
            </form>
        </div>
    </main>

    <script>
        function toggleUserSelect() {
            const audience = document.getElementById('audience').value;
            const userGroup = document.getElementById('userSelectGroup');
            userGroup.style.display = (audience === 'user') ? 'block' : 'none';
        }

        function updatePreview() {
            const type = document.getElementById('type').value;
            const preview = document.getElementById('typePreview');
            
            preview.className = 'preview-box preview-' + type;
            
            let icon = 'â„¹ï¸';
            if (type === 'success') icon = 'âœ…';
            if (type === 'warning') icon = 'âš ï¸';
            if (type === 'danger') icon = 'ðŸš¨';
            
            preview.querySelector('span:first-child').textContent = icon;
        }
    </script>
</body>
</html>

