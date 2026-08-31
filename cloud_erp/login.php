<?php
require_once __DIR__ . '/core/Auth.php';
use Core\Auth;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $pass = $_POST['password'] ?? '';
    if (Auth::login($email, $pass)) {
        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid email or password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Ultimate ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Inter', sans-serif; }
        .login-card { width: 100%; max-width: 400px; padding: 2rem; border-radius: 12px; border: 1px solid #e1e4e8; background: white; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .brand { font-weight: 700; color: #1e1e2d; font-size: 1.5rem; text-align: center; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">Ultimate ERP</div>
        <?php if($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label text-secondary small fw-bold">Email Address</label>
                <input type="email" name="email" class="form-control" required value="admin@clouderp.com">
                <div class="form-text">Default: admin@clouderp.com</div>
            </div>
            <div class="mb-4">
                <label class="form-label text-secondary small fw-bold">Password</label>
                <input type="password" name="password" class="form-control" required value="admin123">
                <div class="form-text">Default: admin123</div>
            </div>
            <button class="btn btn-primary w-100 fw-bold py-2">Sign In</button>
        </form>
    </div>
</body>
</html>
