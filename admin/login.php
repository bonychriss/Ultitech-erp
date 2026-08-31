<?php
// Admin login page - redirects to main login
// This ensures /admin/login works as a URL
require_once '../includes/functions.php';

// If already logged in as admin, go to admin dashboard
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header('Location: dashboard.php');
    exit();
}

// If already logged in but not admin, go to module selection
if (isset($_SESSION['user_id'])) {
    header('Location: ../select-module.php');
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user']);
    $password = $_POST['password'];
    if (authenticate($user, $password)) {
        // Check if user is admin
        if ($_SESSION['role'] === 'admin') {
            header('Location: dashboard.php');
        } else {
            header('Location: ../select-module.php');
        }
        exit();
    } else {
        $error = 'Invalid credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Login - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/auth.css" />
    <style>
        /* Compact login card to match main login page */
        .auth-wrap {
            max-width: 380px;
            padding: 16px;
        }
        .auth-card {
            padding: 20px;
            border-radius: 0; /* sharp corners */
        }
        .brand h1 {
            font-size: 16px;
            margin: 0;
        }
        .brand h2 {
            font-size: 13px;
            margin: 4px 0 0;
        }
        .brand {
            margin-bottom: 12px;
        }
        h3.title {
            font-size: 16px;
            margin: 6px 0 12px;
        }
        .form-row {
            margin-bottom: 10px;
        }
        .input {
            padding: 10px 12px;
            font-size: 13px;
        }
        /* Happy New Year Features */
        .auth-card { border-top: 4px solid #F4B400; }
        .christmas-greeting { 
            color: #d97706; 
            font-weight: bold; 
            font-size: 14px; 
            margin-top: 8px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 6px;
            background: linear-gradient(to right, #F4B400, #FBBF24, #F4B400);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent; 
        }
        .snow-container { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; overflow: hidden; }
        .snowflake { position: absolute; top: -20px; color: #FCD34D; font-size: 1.5em; animation-name: fall, shake; animation-duration: 10s, 3s; animation-timing-function: linear, ease-in-out; animation-iteration-count: infinite, infinite; opacity: 0.6; }
        @keyframes fall { 0% { top: -10%; } 100% { top: 100%; } }
        @keyframes shake { 0% { transform: translateX(0px); } 50% { transform: translateX(40px); } 100% { transform: translateX(0px); } }
        .snowflake:nth-of-type(1) { left: 10%; animation-delay: 1s, 1s; content: 'âœ¨'; }
        .snowflake:nth-of-type(2) { left: 20%; animation-delay: 6s, .5s; content: 'ðŸŽ‰'; font-size: 1.2em; }
        .snowflake:nth-of-type(3) { left: 30%; animation-delay: 4s, 2s; content: 'âœ¨'; }
        .snowflake:nth-of-type(4) { left: 40%; animation-delay: 2s, 2s; content: 'âœ¨'; }
        .snowflake:nth-of-type(5) { left: 50%; animation-delay: 8s, 3s; content: 'ðŸŽ‰'; font-size: 1.2em;}
        .snowflake:nth-of-type(6) { left: 60%; animation-delay: 6s, 2s; content: 'âœ¨'; }
        .snowflake:nth-of-type(7) { left: 70%; animation-delay: 2.5s, 1s; content: 'âœ¨'; }
        .snowflake:nth-of-type(8) { left: 80%; animation-delay: 1s, 0s; content: 'ðŸŽ‰'; font-size: 1.2em;}
        .snowflake:nth-of-type(9) { left: 90%; animation-delay: 3s, 1.5s; content: 'âœ¨'; }
        
        .admin-badge {
            display: inline-block;
            background: #d42426;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
<div class="snow-container" aria-hidden="true">
  <div class="snowflake">âœ¨</div><div class="snowflake">ðŸŽ‰</div><div class="snowflake">âœ¨</div>
  <div class="snowflake">ðŸŽ‰</div><div class="snowflake">âœ¨</div><div class="snowflake">ðŸŽ‰</div>
  <div class="snowflake">âœ¨</div><div class="snowflake">ðŸŽ‰</div><div class="snowflake">âœ¨</div>
</div>

<div class="auth-wrap">
    <div class="auth-card" role="dialog" aria-labelledby="loginTitle">
        <div class="brand">
            <img src="../assets/images/Untitled.jpg" alt="Ultimate General Trading Logo" style="height:100px; width:auto; margin-bottom: 12px;" />
            <h1>ULTIMATE GENERAL TRADING</h1>
            <div class="christmas-greeting">ðŸŽ‰ Happy New Year! ðŸŽ‰</div>
            <div style="text-align: center; margin-top: 12px;">
                <span class="admin-badge">ðŸ” Admin Access</span>
            </div>
        </div>
        <h3 id="loginTitle" class="title">Administrator Sign In</h3>

        <?php if ($error): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-row">
                <input 
                    type="text" 
                    id="user" 
                    name="user" 
                    class="input" 
                    required 
                    autofocus 
                    placeholder="Username or Email"
                />
            </div>

            <div class="form-row">
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="input" 
                    required 
                    placeholder="Password"
                />
            </div>

            <button type="submit" class="btn">Sign In</button>
        </form>

        <div style="text-align: center; margin-top: 16px; font-size: 13px;">
            <a href="../login.php" style="color: #666; text-decoration: none;">â† Employee Login</a>
        </div>
    </div>
</div>

</body>
</html>
