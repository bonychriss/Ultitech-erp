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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: 'Poppins', sans-serif; background: #fff; color: #1f2937; display: flex; flex-direction: column; }
        .fp-top { border-bottom: 1px solid #eef0f4; max-width: 1100px; width: 100%; margin: 0 auto; padding: 18px 24px; }
        .fp-top img { height: 48px; max-width: 200px; object-fit: contain; display: block; }
        .fp-main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 24px; }
        .fp-grid { width: 100%; max-width: 1000px; display: grid; grid-template-columns: 1fr 1fr; gap: 48px; align-items: center; }
        .fp-title { margin: 0 0 14px; font-size: 30px; font-weight: 600; color: #1f2937; }
        .fp-sub { margin: 0 0 26px; font-size: 15px; line-height: 1.7; color: #64748b; max-width: 360px; }
        .fp-form { display: flex; flex-direction: column; gap: 10px; max-width: 440px; }
        .fp-row { display: flex; gap: 10px; }
        .fp-input { flex: 1; min-width: 0; height: 44px; padding: 0 14px; border: 1px solid #e2e8f0; border-radius: 3px; font: inherit; font-size: 13px; color: #1f2937; outline: none; transition: border-color .15s; }
        .fp-form > .fp-input { flex: none; }
        .fp-form .fp-input { height: 44px !important; min-height: 44px; padding: 0 14px !important; }
        .fp-input::placeholder { color: #9ca3af; }
        .fp-input:focus { border-color: #1a88ff; }
        .fp-btn { height: 44px; padding: 0 26px; border: 0; border-radius: 3px; background: #1a88ff; color: #fff; font: inherit; font-size: 13px; cursor: pointer; white-space: nowrap; transition: background .15s; }
        .fp-btn:hover { background: #0b74e5; }
        .fp-alert { max-width: 440px; padding: 11px 14px; margin-bottom: 16px; border-radius: 4px; font-size: 13px; }
        .fp-alert.error { background: #fee2e2; color: #b91c1c; }
        .fp-alert.success { background: #dcfce7; color: #15803d; word-break: break-word; }
        .fp-back { display: inline-block; margin-top: 22px; font-size: 13px; color: #1a88ff; text-decoration: none; }
        .fp-back:hover { text-decoration: underline; }
        .fp-art { display: flex; justify-content: center; }
        .fp-art img { width: 100%; max-width: 480px; height: auto; display: block; }
        @media (max-width: 820px) {
            .fp-grid { grid-template-columns: 1fr; gap: 28px; }
            .fp-art { order: -1; }
            .fp-art img { max-width: 300px; }
            .fp-title { font-size: 24px; }
        }
        @media (max-width: 480px) { .fp-row { flex-direction: column; } .fp-btn { width: 100%; } }
    </style>
</head>
<body>
    <header class="fp-top">
        <?php $fpLogo = function_exists('getCompanyLogoUrl') ? getCompanyLogoUrl() : ''; ?>
        <?php if ($fpLogo !== ''): ?>
            <a href="login.php"><img src="<?= htmlspecialchars($fpLogo) ?>" alt="Logo"></a>
        <?php endif; ?>
    </header>

    <main class="fp-main">
        <div class="fp-grid">
            <section>
                <h1 class="fp-title">Forgot your password?</h1>
                <p class="fp-sub">Don't worry. We'll reset your password and send you a link to create a new one.</p>

                <?php if ($message): ?>
                    <div class="fp-alert success"><?= $message ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="fp-alert error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" class="fp-form">
                    <input class="fp-input" type="text" name="full_name" required placeholder="Your full name" aria-label="Full name" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                    <div class="fp-row">
                        <input class="fp-input" type="email" name="email" required placeholder="Your email" aria-label="Email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <button type="submit" class="fp-btn">Reset password</button>
                    </div>
                </form>

                <a href="login.php" class="fp-back">&larr; Back to Login</a>
            </section>

            <div class="fp-art" aria-hidden="true">
                <img src="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/images/forgot-password-illustration.jpg') : 'assets/images/forgot-password-illustration.jpg') ?>" alt="">
            </div>
        </div>
    </main>
</body>
</html>
