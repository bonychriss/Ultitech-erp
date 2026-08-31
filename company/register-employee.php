<?php
require_once __DIR__ . '/../includes/functions.php';
ensureMultiCompanyControlSchema();

$message = '';
$error = '';
$inviteCode = trim((string) ($_GET['code'] ?? $_POST['invite_code'] ?? ''));
$companySlug = strtolower(trim((string) ($_GET['company_slug'] ?? $_POST['company_slug'] ?? getRequestedCompanySlug())));
$company = null;

if ($companySlug !== '' && columnExists('companies', 'company_slug')) {
    $stmtSlug = $pdo->prepare("SELECT id, company_name, company_slug, invite_code, employee_registration_mode, allow_employee_self_registration, require_admin_approval_for_new_users, status FROM companies WHERE company_slug = ? LIMIT 1");
    $stmtSlug->execute([$companySlug]);
    $company = $stmtSlug->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($company && $inviteCode !== '' && !hash_equals((string) ($company['invite_code'] ?? ''), $inviteCode)) {
        $company = null;
    }
} elseif ($inviteCode !== '') {
    $stmt = $pdo->prepare("SELECT id, company_name, company_slug, invite_code, employee_registration_mode, allow_employee_self_registration, require_admin_approval_for_new_users, status FROM companies WHERE invite_code = ? LIMIT 1");
    $stmt->execute([$inviteCode]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    if (!$company) {
        $error = 'Invalid invite code.';
    } elseif ((string) ($company['status'] ?? 'active') !== 'active') {
        $error = 'Company is not active.';
    } elseif ((int) ($company['allow_employee_self_registration'] ?? 0) !== 1) {
        $error = 'Self-registration is disabled for this company.';
    } elseif ($fullName === '' || $email === '' || $username === '' || $password === '') {
        $error = 'All required fields must be filled.';
    } elseif (($emailErr = validateNewUserEmailForIndex($email)) !== null) {
        $error = $emailErr;
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $dupStmt->execute([$username, $email]);
            if ((int) $dupStmt->fetchColumn() > 0) {
                throw new RuntimeException('Username or email already exists.');
            }
            $approvalRequired = (int) ($company['require_admin_approval_for_new_users'] ?? 1) === 1;
            $approvalStatus = $approvalRequired ? 'pending' : 'approved';
            $active = $approvalRequired ? 0 : 1;

            $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $insertCols = ['username', 'password', 'full_name', 'email'];
            $insertVals = [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email];
            if (in_array('role', $cols, true)) { $insertCols[] = 'role'; $insertVals[] = 'employee'; }
            if (in_array('department', $cols, true)) { $insertCols[] = 'department'; $insertVals[] = 'General'; }
            if (in_array('company_id', $cols, true)) { $insertCols[] = 'company_id'; $insertVals[] = (int) $company['id']; }
            if (in_array('is_active', $cols, true)) { $insertCols[] = 'is_active'; $insertVals[] = $active; }
            if (in_array('status', $cols, true)) { $insertCols[] = 'status'; $insertVals[] = $approvalRequired ? 'pending' : 'active'; }
            if (in_array('approval_status', $cols, true)) { $insertCols[] = 'approval_status'; $insertVals[] = $approvalStatus; }
            $sql = "INSERT INTO users (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', array_fill(0, count($insertCols), '?')) . ")";
            $pdo->prepare($sql)->execute($insertVals);
            $newUserId = (int) $pdo->lastInsertId();
            if ($newUserId > 0 && function_exists('syncUserCompanyIndex')) {
                syncUserCompanyIndex((int) $company['id'], $newUserId);
            }
            $message = $approvalRequired
                ? 'Registration submitted. Awaiting company admin approval.'
                : 'Registration successful. You can now login.';
        } catch (Throwable $e) {
            $error = 'Registration failed: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Registration</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <style>
    :root {
      --primary-color: #8f7ae5;
      --primary-hover: #7a64d7;
      --bg-color: #f3f4f6;
      --text-dark: #1f2937;
      --text-muted: #9ca3af;
      --input-border: #d1d5db;
      --danger: #dc2626;
      --success: #16a34a;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: 'Outfit', sans-serif;
      background: var(--bg-color);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      color: var(--text-dark);
    }

    .auth-container {
      width: 100%;
      max-width: 430px;
      background: #fff;
      border-radius: 30px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.1);
      overflow: hidden;
      padding-bottom: 28px;
    }

    .hero-image {
      width: 100%;
      height: 190px;
      object-fit: contain;
      background: #fdfdfd;
      padding-top: 26px;
      display: block;
    }

    .content-area { padding: 0 30px; text-align: center; }

    .h-title {
      font-size: 30px;
      font-weight: 700;
      line-height: 1.15;
      margin: 6px 0 8px 0;
    }

    .sub-title {
      font-size: 13px;
      color: var(--text-muted);
      margin-bottom: 16px;
    }

    .alert {
      border-radius: 12px;
      padding: 10px 12px;
      font-size: 13px;
      margin-bottom: 14px;
      text-align: left;
    }
    .alert-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-info { background: #e0ecff; color: #1d4ed8; border: 1px solid #bfdbfe; text-align: center; }

    .form-group { margin-bottom: 14px; text-align: left; }
    .form-label {
      display: block;
      margin: 0 0 6px 4px;
      font-size: 12px;
      color: #6b7280;
      font-weight: 500;
    }
    .input {
      width: 100%;
      border: 1.5px solid var(--input-border);
      border-radius: 50px;
      padding: 12px 16px;
      font-size: 14px;
      font-family: inherit;
      color: var(--text-dark);
      background: #fff;
      transition: border-color .2s, box-shadow .2s;
    }
    .input::placeholder { color: #c4c9d1; }
    .input:focus {
      outline: none;
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(143, 122, 229, 0.14);
    }
    .input[readonly] { background: #f8fafc; }

    .row-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .btn-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: 12px;
    }
    .btn {
      border: none;
      border-radius: 50px;
      padding: 12px 14px;
      cursor: pointer;
      font-size: 15px;
      font-weight: 600;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all .2s ease;
      font-family: inherit;
    }
    .btn-primary {
      background: var(--primary-color);
      color: #fff;
    }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-outline {
      border: 1px solid #d1d5db;
      color: #4b5563;
      background: #fff;
    }
    .btn-outline:hover { background: #f9fafb; }

    .help-text {
      margin-top: 10px;
      font-size: 12px;
      color: #64748b;
      text-align: left;
      padding: 0 4px;
    }
  </style>
</head>
<body>
  <div class="auth-container">
    <img src="<?= app_url('assets/images/login_hero.png?v=1.1') ?>" alt="Registration Illustration" class="hero-image">

    <div class="content-area">
      <h2 class="h-title"><?= $company ? htmlspecialchars((string) $company['company_name']) : 'User Registration' ?></h2>
      <p class="sub-title">Create your account to join the company workspace.</p>

      <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php if ($company): ?>
        <div class="alert alert-info">Company: <strong><?= htmlspecialchars((string) ($company['company_name'] ?? '')) ?></strong></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="company_slug" value="<?= htmlspecialchars($companySlug) ?>">

        <div class="form-group">
          <label class="form-label">Invite Code</label>
          <input class="input" name="invite_code" required value="<?= htmlspecialchars($inviteCode) ?>" placeholder="Enter invite code">
        </div>

        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input class="input" name="full_name" required placeholder="Your full name">
        </div>

        <div class="form-group">
          <label class="form-label">Email</label>
          <input class="input" type="email" name="email" required placeholder="you@company.com">
        </div>

        <div class="form-group">
          <label class="form-label">Username</label>
          <input class="input" name="username" required placeholder="Choose username">
        </div>

        <div class="row-2">
          <div class="form-group">
            <label class="form-label">Password</label>
            <input class="input" type="password" name="password" required placeholder="Min 8 characters">
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password</label>
            <input class="input" type="password" name="confirm_password" required placeholder="Re-enter password">
          </div>
        </div>

        <div class="btn-row">
          <a href="<?= htmlspecialchars(app_url('/login.php')) ?>" class="btn btn-outline">Back to Login</a>
          <button class="btn btn-primary" type="submit">Register</button>
        </div>
      </form>

      <div class="help-text">
        Use the registration link shared by your company admin.
      </div>
    </div>
  </div>
</body>
</html>
