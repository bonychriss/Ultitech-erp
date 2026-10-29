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
        .fp-art svg { width: 100%; max-width: 420px; height: auto; }
        @media (max-width: 820px) {
            .fp-grid { grid-template-columns: 1fr; gap: 28px; }
            .fp-art { order: -1; }
            .fp-art svg { max-width: 280px; }
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
                <svg viewBox="0 0 420 340" xmlns="http://www.w3.org/2000/svg">
                    <path d="M150 40l8 5-8 5z" fill="none" stroke="#cbd5e1" stroke-width="1.5"/>
                    <path d="M20 110l9 4-8 6z" fill="none" stroke="#cbd5e1" stroke-width="1.5"/>
                    <path d="M395 160l8 6-9 3z" fill="none" stroke="#cbd5e1" stroke-width="1.5"/>
                    <path d="M360 300l10 3-7 7z" fill="none" stroke="#cbd5e1" stroke-width="1.5"/>
                    <ellipse cx="215" cy="305" rx="150" ry="10" fill="#f1f5f9"/>
                    <path d="M352 110c14 30 14 120 0 175h-18c-12-55-12-145 0-175z" fill="#eef2f6"/>
                    <rect x="336" y="270" width="30" height="35" rx="3" fill="#e2e8f0"/>
                    <rect x="90" y="170" width="240" height="8" rx="2" fill="#e5e9f0"/>
                    <rect x="300" y="178" width="8" height="125" fill="#edf0f5"/>
                    <rect x="110" y="178" width="8" height="125" fill="#edf0f5"/>
                    <rect x="98" y="128" width="62" height="40" rx="3" fill="#e5e9f0"/>
                    <rect x="122" y="168" width="14" height="3" fill="#d7dde6"/>
                    <rect x="190" y="230" width="70" height="8" rx="3" fill="#e2e8f0"/>
                    <rect x="222" y="238" width="6" height="60" fill="#e2e8f0"/>
                    <path d="M200 170c-6-28 14-52 42-50 24 2 40 22 36 48l-6 40c-2 10-10 16-20 16h-30c-12 0-20-8-21-20z" fill="#ffffff" stroke="#e5e7eb" stroke-width="2"/>
                    <path d="M270 140c18 8 30 22 30 34l-4 4c-12 0-22-10-34-20z" fill="#ffffff" stroke="#e5e7eb" stroke-width="2"/>
                    <path d="M296 176l10 6-6 6-10-4z" fill="#f4b8a4"/>
                    <path d="M212 112c-8-22 4-44 26-46 18-2 30 10 30 26 0 12-6 22-16 26-14 6-34 4-40-6z" fill="#f4b8a4"/>
                    <path d="M206 98c-6-24 10-46 34-46 22 0 36 16 32 36-14-10-34-12-52-4-6 4-10 10-14 14z" fill="#f2665a"/>
                    <path d="M252 120l12 10-8 6-10-10z" fill="#2563eb"/>
                    <path d="M206 228h64c10 0 16 8 14 18l-14 50h-18l8-42h-50c-12 0-18-12-12-22z" fill="#f2665a"/>
                    <path d="M210 232c-4 18-2 40 6 64h-18c-10-22-12-44-6-62z" fill="#ef5a4c"/>
                    <path d="M196 296h22l4 8h-28z" fill="#1d4ed8"/>
                    <path d="M250 296h22l4 8h-28z" fill="#1d4ed8"/>
                    <path d="M188 210c-6 20-4 46 6 70" fill="none" stroke="#f4b8a4" stroke-width="7" stroke-linecap="round"/>
                    <circle cx="160" cy="300" r="5" fill="none" stroke="#1f2a44" stroke-width="2"/>
                    <circle cx="173" cy="300" r="5" fill="none" stroke="#1f2a44" stroke-width="2"/>
                    <path d="M165 300h3M178 300c4 0 6 2 8 4" fill="none" stroke="#1f2a44" stroke-width="2"/>
                </svg>
            </div>
        </div>
    </main>
</body>
</html>
