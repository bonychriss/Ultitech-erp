<?php
require_once 'includes/functions.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$userId = false;

if (!$token) {
    $error = 'Invalid reset link.';
} else {
    $userId = verifyResetToken($token);
    if (!$userId) {
        $error = 'This password reset link is invalid or has expired.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        if (resetUserPassword($userId, $password)) {
            $success = 'Your password has been reset successfully. You can now <a href="login.php">login</a>.';
            $userId = false; // Disable further submission
        } else {
            $error = 'Failed to reset password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - PAYMENT VOUCHER SYSTEM</title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&display=swap" rel="stylesheet">
    <style>
        .auth-wrap { max-width: 400px; padding: 20px; margin: 0 auto; margin-top: 50px; }
        .auth-card { padding: 30px; border-radius: 0; background: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-top: 4px solid #d42426; }
        .brand { text-align: center; margin-bottom: 20px; }
        .title { text-align: center; margin-bottom: 20px; color: #111; font-weight: 600; }
        .form-row { margin-bottom: 15px; }
        .label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: #374151; }
        .input { width: 100%; padding: 10px; border: 1px solid #d1d5db; font-family: inherit; font-size: 14px; }
        .btn { width: 100%; padding: 12px; background: #2b2f42; color: #fff; border: none; cursor: pointer; font-weight: 500; font-size: 14px; transition: background 0.2s; }
        .btn:hover { background: #1f2334; }
        .alert { padding: 12px; margin-bottom: 15px; font-size: 14px; }
        .alert.error { background: #fee2e2; color: #b91c1c; }
        .alert.success { background: #dcfce7; color: #15803d; }
        .links { margin-top: 20px; text-align: center; font-size: 13px; }
        .links a { color: #d42426; text-decoration: none; }
    </style>
</head>
<body>
    
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="brand">
                <img src="assets/images/Untitled.jpg" alt="Logo" style="height: 60px;">
                <h1 style="font-size: 18px; margin-top: 10px;">Set New Password</h1>
            </div>

            <?php if ($success): ?>
                <div class="alert success"><?= $success ?></div>
            <?php elseif ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($userId && !$success): ?>
            <form method="post">
                <div class="form-row">
                    <label class="label" for="password">New Password</label>
                    <input class="input" type="password" id="password" name="password" required minlength="6">
                </div>
                <div class="form-row">
                    <label class="label" for="confirm_password">Confirm Password</label>
                    <input class="input" type="password" id="confirm_password" name="confirm_password" required minlength="6">
                </div>

                <button type="submit" class="btn">Reset Password</button>
            </form>
            <?php endif; ?>

            <div class="links">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>

</body>
</html>
