<?php
// Load shared bootstrap (starts session, sets cookie params, DB, etc.) before any output
require_once __DIR__ . '/includes/functions.php';

// If already logged in, route out immediately
if (isset($_SESSION['user_id'])) {
  header('Location: select-module.php');
  exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = trim($_POST['user']);
  $password = $_POST['password'];
  if (authenticate($user, $password)) {
    // Always redirect to module selection page
    header('Location: select-module.php');
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
  <title>Login - PAYMENT VOUCHER SYSTEM</title>
    <link rel="stylesheet" href="assets/css/auth.css" />
    <style>
        /* Compact login card to match register page */
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
        /* Christmas Features */
        .auth-card { border-top: 4px solid #d42426; }
        .christmas-greeting { color: #d42426; font-weight: bold; font-size: 14px; margin-top: 8px; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .snow-container { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; overflow: hidden; }
        .snowflake { position: absolute; top: -20px; color: #cbd5e1; font-size: 1.5em; animation-name: fall, shake; animation-duration: 10s, 3s; animation-timing-function: linear, ease-in-out; animation-iteration-count: infinite, infinite; opacity: 0.8; }
        @keyframes fall { 0% { top: -10%; } 100% { top: 100%; } }
        @keyframes shake { 0% { transform: translateX(0px); } 50% { transform: translateX(40px); } 100% { transform: translateX(0px); } }
        .snowflake:nth-of-type(1) { left: 10%; animation-delay: 1s, 1s; }
        .snowflake:nth-of-type(2) { left: 20%; animation-delay: 6s, .5s; }
        .snowflake:nth-of-type(3) { left: 30%; animation-delay: 4s, 2s; }
        .snowflake:nth-of-type(4) { left: 40%; animation-delay: 2s, 2s; }
        .snowflake:nth-of-type(5) { left: 50%; animation-delay: 8s, 3s; }
        .snowflake:nth-of-type(6) { left: 60%; animation-delay: 6s, 2s; }
        .snowflake:nth-of-type(7) { left: 70%; animation-delay: 2.5s, 1s; }
        .snowflake:nth-of-type(8) { left: 80%; animation-delay: 1s, 0s; }
        .snowflake:nth-of-type(9) { left: 90%; animation-delay: 3s, 1.5s; }
    </style>
    </head>
<body>
<div class="snow-container" aria-hidden="true">
  <div class="snowflake">❅</div><div class="snowflake">❆</div><div class="snowflake">❅</div>
  <div class="snowflake">❆</div><div class="snowflake">❅</div><div class="snowflake">❆</div>
  <div class="snowflake">❅</div><div class="snowflake">❆</div><div class="snowflake">❅</div>
</div>

<div class="auth-wrap">
  <div class="auth-card" role="dialog" aria-labelledby="loginTitle">
    <div class="brand">
  <img src="assets/images/Untitled.jpg" alt="Payment Voucher System Logo" style="height:100px; width:auto; margin-bottom: 12px;" />
      <h1>PAYMENT VOUCHER SYSTEM</h1>
      <div class="christmas-greeting">🎄 Merry Christmas! 🎄</div>
    </div>
    <h3 id="loginTitle" class="title">Sign in</h3>

    <?php if ($error): ?>
      <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <div class="form-row">
  <label class="label" for="user">Username or Email</label>
        <input class="input" id="user" name="user" type="text" required autofocus />
      </div>
      <div class="form-row">
        <label class="label" for="password">Password</label>
        <input class="input" id="password" name="password" type="password" required />
      </div>
      <div class="form-row" style="margin-top:12px;">
        <button class="btn" type="submit">Login</button>
      </div>
    </form>
    <div class="helper">New here? <a href="register.php">Create an account</a></div>
  </div>
</div>

</body>
</html>