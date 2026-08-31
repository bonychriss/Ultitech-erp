<?php
session_start();

// If already logged in, route to the most relevant page
if (isset($_SESSION['user_id'])) {
  if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: admin/dashboard.php');
  } else {
    header('Location: employee/create-voucher.php');
  }
  exit();
}

require_once 'includes/config.php';

$error = '';

// If no users exist yet, enforce the admin setup flow instead of public registration
try {
  $stmt = $pdo->query('SELECT COUNT(*) AS c FROM users');
  $row = $stmt->fetch();
  if (((int)($row['c'] ?? 0)) === 0) {
    header('Location: setup_admin.php');
    exit();
  }
} catch (Throwable $e) {
  // ignore and proceed to regular register page
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $department = $_POST['department'] ?? '';

    if ($full_name === '' || $email === '' || $password === '' || $department === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
        try {
            // Unique checks
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$full_name, $email]);
            $exists = $stmt->fetch();
            if ($exists) {
                $error = 'Full name or email already exists.';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, email, role, department, created_at) VALUES (?, ?, ?, ?, "employee", ?, NOW())');
                if ($stmt->execute([$full_name, $hashed, $full_name, $email, $department])) {
                    // Auto-login and redirect to dashboard with welcome message
                    $newId = $pdo->lastInsertId();
                    $_SESSION['user_id'] = $newId;
                    $_SESSION['username'] = $full_name;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['role'] = 'employee';
                    $_SESSION['department'] = $department;
                    header('Location: employee/dashboard.php?welcome=1&name=' . urlencode($full_name));
                    exit();
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        } catch (PDOException $e) {
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
  <title>Register - PAYMENT VOUCHER SYSTEM</title>
  <link rel="stylesheet" href="assets/css/auth.css" />
  <style>
    /* Compact register card to fit fully on screen */
    .auth-wrap {
      max-width: 380px; /* Reduced from 420px */
      padding: 16px; /* Reduced from 24px */
    }
    .auth-card {
      padding: 20px; /* Reduced from 24px */
       border-radius: 0; /* sharp corners */
       /* Additional styles can be added here */
       box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); /* Optional shadow */
    }
    .brand h1 {
      font-size: 16px; /* Reduced from 18px */
      margin: 0;
    }
    .brand h2 {
      font-size: 13px; /* Reduced from 14px */
      margin: 4px 0 0; /* Reduced from 6px */
    }
    .brand {
      margin-bottom: 12px; /* Reduced from 16px */
    }
    h3.title {
      font-size: 16px; /* Reduced from 18px */
      margin: 6px 0 12px; /* Reduced spacing */
    }
    .form-row {
      margin-bottom: 10px; /* Reduced from 12px */
    }
    .label {
      font-size: 12px; /* Reduced from 13px */
      margin-bottom: 4px; /* Reduced from 6px */
    }
    .input, .select {
      padding: 10px 12px; /* Reduced from 12px */
      font-size: 13px; /* Reduced from 14px */
    }
    .btn {
      padding: 10px 12px; /* Reduced from 12px 14px */
      font-size: 14px;
    }
    .helper {
      font-size: 11px; /* Reduced from 12px */
      margin-top: 8px; /* Reduced from 10px */
    }
    .alert {
      padding: 8px 10px; /* Reduced from 10px 12px */
      font-size: 12px; /* Reduced from 13px */
      margin-bottom: 8px; /* Add some spacing */
    }
    .brand {
      margin-bottom: 8px; /* Further reduced */
    }
  </style>
</head>
<body>
  <div class="auth-wrap">
    <div class="auth-card" role="dialog" aria-labelledby="registerTitle">
  <div class="brand">
  <img src="assets/images/Untitled.jpg" alt="Payment Voucher System Logo" style="height:100px; width:auto; margin-bottom: 8px;" />
    <h1>PAYMENT VOUCHER SYSTEM</h1>
  </div>
      <h3 id="registerTitle" class="title">Create your account</h3>

      <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="on" id="regForm">
        <div class="form-row">
          <label class="label" for="full_name">Full name</label>
          <input class="input" id="full_name" name="full_name" type="text" required value="<?= isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : '' ?>" />
        </div>
        <div class="form-row">
          <label class="label" for="email">Email</label>
          <input class="input" id="email" name="email" type="email" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" />
        </div>
        <div class="form-row">
          <label class="label" for="department">Department</label>
          <select class="input" id="department" name="department" required>
            <?php $dept = $_POST['department'] ?? ''; ?>
            <option value="" disabled <?= $dept === '' ? 'selected' : '' ?>>Select department</option>
            <option value="Procurement" <?= $dept === 'Procurement' ? 'selected' : '' ?>>Procurement</option>
            <option value="IT" <?= $dept === 'IT' ? 'selected' : '' ?>>IT</option>
            <option value="Finance" <?= $dept === 'Finance' ? 'selected' : '' ?>>Finance</option>
            <option value="Sales" <?= $dept === 'Sales' ? 'selected' : '' ?>>Sales</option>
          </select>
        </div>
        <div class="form-row">
          <label class="label" for="password">Password</label>
          <input class="input" id="password" name="password" type="password" required />
        </div>
        <div class="form-row" style="margin-top:12px;">
          <button class="btn" type="submit">Register</button>
        </div>
      </form>
      <div class="helper">Already registered? <a href="login.php">Sign in</a></div>
    </div>
  </div>

  <script>
    // Quick keyboard shortcut: press "L" to go to Login
    document.addEventListener('keydown', (e) => {
      if ((e.key === 'l' || e.key === 'L')) {
        const tag = (document.activeElement && document.activeElement.tagName) || '';
        // Only trigger when not typing in an input/textarea/select
        if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) {
          window.location.href = 'login.php';
        }
      }
    });

    // Basic client check for password length
    const form = document.getElementById('regForm');
    form?.addEventListener('submit', (e) => {
      const p = document.getElementById('password').value;
      if (p.length < 6) {
        e.preventDefault();
        alert('Password must be at least 6 characters.');
        return;
      }
    });
  </script>
</body>
</html>