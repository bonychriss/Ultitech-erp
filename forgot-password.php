<?php
require_once 'includes/functions.php';
require_once 'includes/mailer.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    
    if (empty($email) || empty($fullName)) {
        $error = 'Please enter both your email and full name.';
    } else {
        // Verify identity first
        global $pdo;
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND full_name = ?");
        $stmt->execute([$email, $fullName]);
        $userExists = $stmt->fetch();

        if ($userExists) {
            $token = generatePasswordResetToken($email);
            
            if ($token) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $resetLink = $protocol . $domain . app_url("/reset-password.php?token=$token");
                
                $subject = "Password Reset Request";
                $body = "<p>Hello " . htmlspecialchars($fullName) . ",</p><p>You requested a password reset. Click the link below to set a new password:</p><p><a href='$resetLink'>$resetLink</a></p><p>Link expires in 1 hour.</p>";
                
                if (sendEmail($email, $subject, $body)) {
                    $message = "A reset link has been sent to your email address.";
                } else {
                    $error = "Failed to send email. Please check system logs or contact admin.";
                }
            } else {
                $error = "System error generating token.";
            }
        } else {
            // Security: In a public system we'd be vague, but for internal staff ERP, clarity helps reduce support tickets
            $error = "The provided Name and Email do not match our records.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - PAYMENT VOUCHER SYSTEM</title>
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
        .alert.success { background: #dcfce7; color: #15803d; word-break: break-all; }
        .links { margin-top: 20px; text-align: center; font-size: 13px; }
        .links a { color: #d42426; text-decoration: none; }
    </style>
</head>
<body>
    
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="brand">
                <img src="assets/images/Untitled.jpg" alt="Logo" style="height: 60px;">
                <h1 style="font-size: 18px; margin-top: 10px;">Reset Password</h1>
            </div>

            <?php if ($message): ?>
                <div class="alert success"><?= $message ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="form-row">
                    <label class="label" for="full_name">Full Name</label>
                    <input class="input" type="text" id="full_name" name="full_name" required placeholder="John Doe">
                </div>
                <div class="form-row">
                    <label class="label" for="email">Email Address</label>
                    <input class="input" type="email" id="email" name="email" required placeholder="Enter your email address">
                </div>

                <button type="submit" class="btn">Send Reset Link</button>
            </form>

            <div class="links">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
