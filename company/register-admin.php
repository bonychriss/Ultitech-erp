<?php
require_once __DIR__ . '/../includes/functions.php';
ensureMultiCompanyControlSchema();

global $control_pdo;
$usersPdo = ($control_pdo instanceof PDO) ? $control_pdo : $pdo;

$message = '';
$error = '';
$inviteCode = trim((string) ($_GET['code'] ?? $_POST['invite_code'] ?? ''));
$companySlug = strtolower(trim((string) ($_GET['company_slug'] ?? $_POST['company_slug'] ?? getRequestedCompanySlug())));
$company = null;

if ($companySlug !== '') {
    $company = findCompanyBySlug($companySlug);
    if ($company && $inviteCode !== '' && !hash_equals((string) ($company['invite_code'] ?? ''), $inviteCode)) {
        $company = null;
    }
} elseif ($inviteCode !== '' && $control_pdo instanceof PDO) {
    $stmt = $control_pdo->prepare('SELECT * FROM companies WHERE invite_code = ? LIMIT 1');
    $stmt->execute([$inviteCode]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($company) {
        $companySlug = strtolower(trim((string) ($company['company_slug'] ?? '')));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if (!$company) {
        $error = 'Invalid or expired invite link. Ask your company owner for a new admin join link.';
    } elseif ((string) ($company['status'] ?? 'active') !== 'active') {
        $error = 'This company is not active.';
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
            $emailNorm = normalizeLoginEmail($email);
            $dupStmt = $usersPdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR LOWER(TRIM(email)) = ?');
            $dupStmt->execute([$username, $emailNorm]);
            if ((int) $dupStmt->fetchColumn() > 0) {
                throw new RuntimeException('Username or email already exists.');
            }

            $companyId = (int) ($company['id'] ?? 0);
            $cols = $usersPdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $insertCols = ['username', 'password', 'full_name', 'email'];
            $insertVals = [$username, $passwordHash, $fullName, $emailNorm];
            if (in_array('role', $cols, true)) {
                $insertCols[] = 'role';
                $insertVals[] = 'company_admin';
            }
            if (in_array('department', $cols, true)) {
                $insertCols[] = 'department';
                $insertVals[] = 'Management';
            }
            if (in_array('company_id', $cols, true)) {
                $insertCols[] = 'company_id';
                $insertVals[] = $companyId;
            }
            if (in_array('phone', $cols, true) && $phone !== '') {
                $insertCols[] = 'phone';
                $insertVals[] = $phone;
            }
            if (in_array('is_active', $cols, true)) {
                $insertCols[] = 'is_active';
                $insertVals[] = 1;
            }
            if (in_array('status', $cols, true)) {
                $insertCols[] = 'status';
                $insertVals[] = 'active';
            }
            if (in_array('approval_status', $cols, true)) {
                $insertCols[] = 'approval_status';
                $insertVals[] = 'approved';
            }
            $sql = 'INSERT INTO users (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', array_fill(0, count($insertCols), '?')) . ')';
            $usersPdo->prepare($sql)->execute($insertVals);
            $newUserId = (int) $usersPdo->lastInsertId();
            if ($newUserId > 0 && function_exists('syncUserCompanyIndex')) {
                syncUserCompanyIndex($companyId, $newUserId);
            }
            $message = 'Registration successful. You can sign in as a company administrator.';
        } catch (Throwable $e) {
            $error = 'Registration failed: ' . $e->getMessage();
        }
    }
}

$loginUrl = app_url('/login.php');
if ($company && trim((string) ($company['company_slug'] ?? '')) !== '') {
    $loginUrl = company_login_url((string) $company['company_slug']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Company Administrator Registration</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <style>
    :root {
      --primary-color: #6f45ff;
      --primary-hover: #5c59f0;
      --bg-color: #f3f4f6;
      --text-dark: #1f2937;
      --text-muted: #9ca3af;
      --input-border: #d1d5db;
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
    .h-title { font-size: 28px; font-weight: 700; line-height: 1.15; margin: 6px 0 8px 0; }
    .sub-title { font-size: 13px; color: var(--text-muted); margin-bottom: 16px; }
    .alert {
      border-radius: 12px;
      padding: 10px 12px;
      font-size: 13px;
      margin-bottom: 14px;
      text-align: left;
    }
    .alert-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-info { background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe; text-align: center; }
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
    }
    .input:focus {
      outline: none;
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(111, 69, 255, 0.14);
    }
    .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .btn-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
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
      font-family: inherit;
    }
    .btn-primary { background: linear-gradient(135deg, #6f45ff 0%, #5c59f0 100%); color: #fff; }
    .btn-outline { border: 1px solid #d1d5db; color: #4b5563; background: #fff; }
    .help-text { margin-top: 10px; font-size: 12px; color: #64748b; text-align: left; padding: 0 4px; }
  </style>
</head>
<body>
  <div class="auth-container">
    <img src="<?= app_url('assets/images/login_hero.png?v=1.1') ?>" alt="" class="hero-image">

    <div class="content-area">
      <h2 class="h-title"><?= $company ? htmlspecialchars((string) $company['company_name']) : 'Company Admin' ?></h2>
      <p class="sub-title">Register as a company administrator for this workspace.</p>

      <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php if ($company): ?>
        <div class="alert alert-info">You are joining <strong><?= htmlspecialchars((string) ($company['company_name'] ?? '')) ?></strong> as an administrator.</div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="company_slug" value="<?= htmlspecialchars($companySlug) ?>">

        <div class="form-group">
          <label class="form-label">Invite code</label>
          <input class="input" name="invite_code" required value="<?= htmlspecialchars($inviteCode) ?>" placeholder="From your admin join link"<?= $company ? ' readonly' : '' ?>>
        </div>

        <div class="form-group">
          <label class="form-label">Full name</label>
          <input class="input" name="full_name" required placeholder="Your full name">
        </div>

        <div class="form-group">
          <label class="form-label">Email</label>
          <input class="input" type="email" name="email" required placeholder="you@company.com">
        </div>

        <div class="form-group">
          <label class="form-label">Username</label>
          <input class="input" name="username" required placeholder="Choose a username">
        </div>

        <div class="form-group">
          <label class="form-label">Phone <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
          <input class="input" name="phone" placeholder="Phone number">
        </div>

        <div class="row-2">
          <div class="form-group">
            <label class="form-label">Password</label>
            <input class="input" type="password" name="password" required placeholder="Min 8 characters">
          </div>
          <div class="form-group">
            <label class="form-label">Confirm password</label>
            <input class="input" type="password" name="confirm_password" required placeholder="Re-enter password">
          </div>
        </div>

        <div class="btn-row">
          <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-outline">Back to login</a>
          <button class="btn btn-primary" type="submit">Register as admin</button>
        </div>
      </form>

      <div class="help-text">
        Use only the join link shared by an existing company administrator. Your account is active immediately after registration.
      </div>
    </div>
  </div>
</body>
</html>
