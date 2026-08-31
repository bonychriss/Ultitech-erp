<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';

if (isLoggedIn()) {
    redirect('../../dashboard.php');
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = clean_input($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            redirect('../../dashboard.php');
        } else {
            $error = "Invalid username or password.";
        }
    }
}
$page_title = 'Login';
include '../../includes/header.php';
?>

<div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="card shadow-lg" style="width: 400px;">
        <div class="card-body p-5">
            <h3 class="text-center mb-4 text-primary-custom"><i class="fas fa-boxes"></i> Stock System</h3>
            <h5 class="text-center mb-4 text-muted">Login</h5>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary bg-primary-custom border-0">Sign In</button>
                </div>
            </form>
            <div class="mt-3 text-center">
                <small class="text-muted">Use <b>admin</b> / <b>password123</b> to login</small>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
