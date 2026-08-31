<?php
// Local-only maintenance tool to reset an account password (default: admin)
// Security: Only accessible from localhost. Delete this file after use.

require_once __DIR__ . '/../includes/config.php';

// Allow only local access
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? 'admin');
    $new_password = $_POST['new_password'] ?? '';
    $activate = isset($_POST['activate']) ? 1 : 0;

    if ($username === '' || $new_password === '') {
        $error = 'Please provide both username/email and a new password.';
    } elseif (strlen($new_password) < 8) {
        $error = 'New password must be at least 8 characters.';
    } else {
        try {
            // Find by username OR email
            $stmt = $pdo->prepare('SELECT id, username, is_active FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'User not found.';
            } else {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                $up = $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
                $up->execute([$hash, $user['id']]);

                if ($activate) {
                    $act = $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?');
                    $act->execute([$user['id']]);
                }

                $pdo->commit();
                $message = 'Password updated for user "' . htmlspecialchars($user['username']) . '"' . ($activate ? ' and account activated.' : '.');
            }
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
            $error = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reset Admin Password (Local)</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 40px; }
    .card { max-width: 560px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
    h1 { font-size: 20px; margin: 0 0 12px; }
    .warn { background: #fff3cd; color: #8a6d3b; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 14px; }
    .ok { background: #d4edda; color: #155724; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 14px; }
    .err { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 14px; }
    label { display: block; margin: 10px 0 6px; font-weight: 600; }
    input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
    .row { margin-top: 12px; display: flex; align-items: center; gap: 8px; }
    button { margin-top: 14px; background: #f39c12; color: #fff; border: 0; padding: 10px 16px; border-radius: 6px; cursor: pointer; }
    .hint { font-size: 12px; color: #666; }
    .links { margin-top: 16px; font-size: 14px; }
    a { color: #f39c12; }
  </style>
  <meta http-equiv="cache-control" content="no-cache" />
  <meta http-equiv="pragma" content="no-cache" />
  <meta http-equiv="expires" content="0" />
</head>
<body>
  <div class="card">
    <h1>Reset Admin Password (Local-only)</h1>
    <div class="warn">Security: This tool only works from localhost. Delete this file after use: <code>scripts/reset_admin_password.php</code></div>

    <?php if ($message): ?>
      <div class="ok"><?= $message ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="err"><?= $error ?></div>
    <?php endif; ?>

    <form method="post">
      <label for="username">Username or Email</label>
      <input id="username" name="username" type="text" value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>" required />

      <label for="new_password">New password</label>
      <input id="new_password" name="new_password" type="password" placeholder="Enter new password (min 8 chars)" required />
      <div class="row">
        <label><input type="checkbox" name="activate" <?= isset($_POST['activate']) ? 'checked' : '' ?> /> Force activate this account</label>
      </div>
      <button type="submit">Update Password</button>
    </form>

    <div class="links">
      <div>Then sign in at: <a href="../login.php">Login</a></div>
    </div>
  </div>
</body>
</html>
