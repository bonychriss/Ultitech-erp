<?php
/**
 * ONE-TIME DATABASE UPDATE SCRIPT
 * Usage: Upload this file to your live site (ultimate.co.tz/) and visit it in your browser.
 * IMPORTANT: Delete this file immediately after the update is complete for security.
 */

require_once 'includes/functions.php';

// Security check: Only admins should be able to run this, or if you're not logged in, 
// you should be aware that anyone who knows this URL could run it.
// We'll require an active admin session to be safe.
requireAdmin();

$error = '';
$success = '';

try {
    // 1. Check current database
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    
    // 2. Perform updates
    // Using simple ALTER TABLE commands
    // We'll check if columns exist first to avoid errors if run multiple times
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $changes = [];
    
    if (!in_array('registration_token', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN registration_token VARCHAR(100) NULL");
        $changes[] = "Added 'registration_token' column.";
    }
    
    if (!in_array('token_expiry', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN token_expiry DATETIME NULL");
        $changes[] = "Added 'token_expiry' column.";
    }
    
    if (empty($changes)) {
        $success = "Database is already up to date! No changes were needed.";
    } else {
        $success = "Database updated successfully: <br>â€¢ " . implode("<br>â€¢ ", $changes);
    }
    
} catch (Exception $e) {
    $error = "Update Failed: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Update - Ultimate System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f7f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; font-family: sans-serif; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 100%; max-width: 500px; text-align: center; }
        .success { color: #2f855a; background: #f0fff4; padding: 20px; border-radius: 8px; border: 1px solid #c6f6d5; margin-bottom: 20px; }
        .error { color: #c53030; background: #fff5f5; padding: 20px; border-radius: 8px; border: 1px solid #feb2b2; margin-bottom: 20px; }
        .btn { display: inline-block; padding: 12px 24px; background: #2b2f42; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; margin-top: 20px; }
        .warning { color: #9a6e00; background: #fffaf0; padding: 10px; border-radius: 6px; font-size: 13px; margin-top: 20px; border: 1px solid #fbd38d; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Live Database Update</h2>
        <p style="color: #718096; margin-bottom: 30px;">Applying changes to <strong><?= htmlspecialchars($dbName) ?></strong></p>

        <?php if ($success): ?>
            <div class="success">
                <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="warning">
            <strong>Security Reminder:</strong> Please delete this file (<code>update_live_db.php</code>) from your server immediately after use.
        </div>

        <a href="admin/dashboard.php" class="btn">Return to Dashboard</a>
    </div>
</body>
</html>
