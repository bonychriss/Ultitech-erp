<?php
// Force reset admin credentials (local only).
// Accepts optional GET params: username, email, password (plain). If omitted, defaults are used.
// DELETE THIS FILE AFTER USE.

require_once __DIR__ . '/../includes/config.php';

// Security: restrict to localhost
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Desired credentials (can be overridden via querystring)
$targetEmail = isset($_GET['email']) && $_GET['email'] !== '' ? $_GET['email'] : 'admin@ultimatetrading.com';
$targetUsername = isset($_GET['username']) && $_GET['username'] !== '' ? $_GET['username'] : 'admin';
$plain = isset($_GET['password']) && $_GET['password'] !== '' ? $_GET['password'] : 'admin123';

// Hash the provided plain password using PHP's default bcrypt
$targetHash = password_hash($plain, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    // Find admin user
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$targetUsername]);
    $user = $stmt->fetch();

    if (!$user) {
        echo '<p style="color:red">Admin user not found.</p>';
        $pdo->rollBack();
        exit;
    }

    // Update email + password hash directly
    $up = $pdo->prepare('UPDATE users SET email = ?, password = ?, updated_at = NOW(), is_active = 1 WHERE id = ?');
    $up->execute([$targetEmail, $targetHash, $user['id']]);

    $pdo->commit();

    echo '<h3>Admin credentials reset successfully.</h3>';
    echo '<ul>';
    echo '<li>Username: <strong>' . htmlspecialchars($targetUsername) . '</strong></li>';
    echo '<li>Email: <strong>' . htmlspecialchars($targetEmail) . '</strong></li>';
    echo '<li>Password (plain): <strong>' . htmlspecialchars($plain) . '</strong> (stored as fresh bcrypt hash)</li>';
    echo '</ul>';
    echo '<p><a href="../login.php">Go to Login</a></p>';
    echo '<p style="color:#b00">Delete <code>scripts/force_reset_admin.php</code> now for security.</p>';
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    echo '<p style="color:red">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
