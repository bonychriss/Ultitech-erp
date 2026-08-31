<?php
require_once 'includes/functions.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$user = null;

if (empty($token)) {
    $error = "Invalid registration link.";
} else {
    // Validate token and expiry
    $stmt = $pdo->prepare("SELECT * FROM users WHERE registration_token = ? AND token_expiry > NOW() AND is_active = 0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = "This registration link is invalid or has expired. Please contact your administrator.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    if ($token !== ($_POST['token'] ?? '')) {
        $error = "Security token mismatch.";
    } else {
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            try {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, registration_token = NULL, token_expiry = NULL, is_active = 1 WHERE id = ?");
                if ($stmt->execute([$hashed, $user['id']])) {
                    $cid = (int) ($user['company_id'] ?? (currentCompanyId() ?? ($_SESSION['company_id'] ?? 0)));
                    if ($cid > 0) {
                        syncUserCompanyIndex($cid, (int) $user['id']);
                    }
                    $success = "Registration complete! You can now log in with your new password.";
                } else {
                    $error = "Failed to complete registration. Please try again.";
                }
            } catch (Exception $e) {
                error_log("Finish Registration Error: " . $e->getMessage());
                $error = "A system error occurred. Please try again later.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Registration - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body.auth-bg {
            background: #f4f7f6;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .auth-card {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        .auth-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .auth-header h2 {
            margin: 0;
            color: #2b2f42;
            font-size: 24px;
        }
        .auth-header p {
            color: #718096;
            margin-top: 8px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 16px;
            box-sizing: border-box;
        }
        .form-group input:focus {
            border-color: #4299e1;
            outline: none;
        }
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #2b2f42;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit:hover {
            background: #1a1e2e;
        }
        .alert-error {
            background: #fff5f5;
            color: #c53030;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #feb2b2;
        }
    </style>
</head>
<body class="auth-bg">
    <div class="auth-card">
        <div class="auth-header">
            <h2>Complete Registration</h2>
            <?php if ($user): ?>
                <p>Hello <strong><?= htmlspecialchars($user['full_name']) ?></strong><?php
                    $regDept = trim((string) ($user['department'] ?? ''));
                    if ($regDept !== '') {
                        echo ', <span style="color:#4a5568;">assigned to <strong>' . htmlspecialchars($regDept) . '</strong></span>';
                    }
                ?>. Please set a password for your account.</p>
            <?php else: ?>
                <p>Secure account activation</p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert-error"><?= $error ?></div>
        <?php endif; ?>

        <?php if ($user && !$success): ?>
            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required minlength="6" placeholder="At least 6 characters">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6" placeholder="Re-enter password">
                </div>

                <button type="submit" class="btn-submit">Set Password & Activate</button>
            </form>
        <?php elseif ($success): ?>
            <div style="text-align: center;">
                <p style="color: #2f855a; font-weight: 600;"><?= $success ?></p>
                <a href="login.php" class="btn-submit" style="display: block; text-decoration: none; margin-top: 20px;">Go to Login</a>
            </div>
        <?php else: ?>
            <div style="text-align: center; margin-top: 20px;">
                <a href="login.php" style="color: #718096; text-decoration: none;">Back to Login</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });

        <?php if ($success): ?>
            Toast.fire({
                icon: 'success',
                title: 'Registration complete!'
            });
        <?php endif; ?>
    </script>
</body>
</html>
