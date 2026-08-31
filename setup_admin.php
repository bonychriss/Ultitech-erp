<?php
// One-time admin setup page. Only accessible when there are zero users.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// If someone is already logged in, send them away
if (isset($_SESSION['user_id'])) {
    $dest = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')
        ? 'admin/dashboard.php'
        : 'employee/dashboard.php';
    header('Location: ' . $dest);
    exit();
}

// If users already exist, do not allow admin setup here
try {
    $stmt = $pdo->query('SELECT COUNT(*) AS c FROM users');
    $row = $stmt->fetch();
    if (((int)($row['c'] ?? 0)) > 0) {
        header('Location: login.php');
        exit();
    }
} catch (Throwable $e) {
    // On DB error, fallback to login to avoid exposing setup
    header('Location: login.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($full_name === '' || $email === '' || $username === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long for admin.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Ensure still zero users and uniqueness
            $pdo->beginTransaction();
            $stmt = $pdo->query('SELECT COUNT(*) AS c FROM users FOR UPDATE');
            $row = $stmt->fetch();
            if (((int)($row['c'] ?? 0)) > 0) {
                $pdo->rollBack();
                header('Location: login.php');
                exit();
            }

            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                $pdo->rollBack();
                $error = 'Username or email already exists.';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $companyId = (int) (defaultCompanyId() ?? 0);
                if ($companyId <= 0 && tableExists('companies')) {
                    $pdo->exec("INSERT INTO companies (company_name, legal_name, status, timezone, base_currency) VALUES ('Ultimate General Trading', 'Ultimate General Trading', 'active', 'Africa/Dar_es_Salaam', 'TZS')");
                    $companyId = (int) $pdo->lastInsertId();
                }
                $hasUserCompany = columnExists('users', 'company_id');
                $insertSql = $hasUserCompany
                    ? 'INSERT INTO users (username, password, full_name, email, role, department, company_id, created_at) VALUES (?, ?, ?, ?, "admin", "Management", ?, NOW())'
                    : 'INSERT INTO users (username, password, full_name, email, role, department, created_at) VALUES (?, ?, ?, ?, "admin", "Management", NOW())';
                $ins = $pdo->prepare($insertSql);
                $insertParams = $hasUserCompany
                    ? [$username, $hashed, $full_name, $email, ($companyId > 0 ? $companyId : null)]
                    : [$username, $hashed, $full_name, $email];
                if ($ins->execute($insertParams)) {
                    $newId = $pdo->lastInsertId();
                    $pdo->commit();
                    // Auto-login admin
                    $_SESSION['user_id'] = $newId;
                    $_SESSION['username'] = $username;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['role'] = 'admin';
                    $_SESSION['department'] = 'Management';
                    if ($companyId > 0) {
                        $_SESSION['company_id'] = $companyId;
                    }
                    header('Location: admin/dashboard.php');
                    exit();
                } else {
                    $pdo->rollBack();
                    $error = 'Failed to create admin. Please try again.';
                }
            }
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
            $error = 'Database error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Initial Admin Setup - Ultimate General Trading</title>
  <link rel="stylesheet" href="assets/css/auth.css" />
  <meta http-equiv="cache-control" content="no-cache" />
  <meta http-equiv="pragma" content="no-cache" />
  <meta http-equiv="expires" content="0" />
  <style>
    .note { font-size: 12px; color: #555; margin-top: 4px; }
  </style>
  </head>
<body>
  <div class="auth-wrap">
    <div class="auth-card" role="dialog" aria-labelledby="setupTitle">
      <div class="brand">
        <h1>ULTIMATE GENERAL TRADING</h1>
        <h2>Payment Voucher System</h2>
      </div>
      <h3 id="setupTitle" class="title">Create the first admin</h3>
      <p class="helper">This step appears only once when no users exist.</p>

      <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="on" id="adminSetupForm">
        <div class="form-row">
          <label class="label" for="full_name">Full name</label>
          <input class="input" id="full_name" name="full_name" type="text" required value="<?= isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : '' ?>" />
        </div>
        <div class="form-row">
          <label class="label" for="email">Email</label>
          <input class="input" id="email" name="email" type="email" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" />
        </div>
        <div class="form-row">
          <label class="label" for="username">Username</label>
          <input class="input" id="username" name="username" type="text" required value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>" />
        </div>
        <div class="form-row">
          <label class="label" for="password">Password</label>
          <input class="input" id="password" name="password" type="password" required />
          <div class="note">Minimum 8 characters.</div>
        </div>
        <div class="form-row">
          <label class="label" for="confirm_password">Confirm password</label>
          <input class="input" id="confirm_password" name="confirm_password" type="password" required />
        </div>
        <div class="form-row" style="margin-top:12px;">
          <button class="btn" type="submit">Create admin and continue</button>
        </div>
      </form>

      <div class="helper">If you already have users, go to <a href="login.php">Login</a>.</div>
    </div>
  </div>

  <script>
    // Auto-suggest username from full name if empty
    const full = document.getElementById('full_name');
    const user = document.getElementById('username');
    full?.addEventListener('input', () => {
      if (!user.value) {
        const sugest = full.value.toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9]/g, '');
        user.value = sugest.slice(0, 20);
      }
    });
    const form = document.getElementById('adminSetupForm');
    form?.addEventListener('submit', (e) => {
      const p = document.getElementById('password').value;
      const c = document.getElementById('confirm_password').value;
      if (p.length < 8) {
        e.preventDefault();
        alert('Admin password must be at least 8 characters.');
        return;
      }
      if (p !== c) {
        e.preventDefault();
        alert('Passwords do not match.');
        return;
      }
    });
  </script>
</body>
</html>
