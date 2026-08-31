<?php
define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';

$companySlug = getRequestedCompanySlug();
$selectedCompany = null;
$selectedCompanyName = '';
$selectedCompanyLogo = '';

if ($companySlug !== '') {
    $selectedCompany = findCompanyBySlug($companySlug);
    if ($selectedCompany) {
        $selectedCompanyName = (string) ($selectedCompany['company_name'] ?? '');
        $logoCompanyId = (int) ($selectedCompany['id'] ?? 0);
        $selectedCompanyLogo = '';
        if ($logoCompanyId > 0 && function_exists('resolveCompanyBrandingLogoUrl')) {
            $selectedCompanyLogo = resolveCompanyBrandingLogoUrl($logoCompanyId);
        }
        if ($selectedCompanyLogo === '' && $logoCompanyId > 0 && function_exists('getCompanyLogoUrl')) {
            $selectedCompanyLogo = getCompanyLogoUrl($logoCompanyId);
        }
    }
}

if (!$selectedCompany) {
    $fallbackLogin = function_exists('app_url') ? app_url('/login.php') : 'login.php';
    if ($companySlug !== '' && function_exists('company_login_url')) {
        $fallbackLogin = company_login_url($companySlug);
    }
    header('Location: ' . $fallbackLogin);
    exit();
}

$error = '';
$success = '';

$registrationDepartments = ['Procurement', 'IT', 'Finance', 'Sales', 'Driver', 'Management', 'General'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $username = $fullName;
    $password = $_POST['password'] ?? '';
    $department = trim($_POST['department'] ?? '');

    $dbName = $selectedCompany['db_name'] ?? null;
    if ($dbName) {
        $pdo->query("USE `$dbName` ");
    }

    if ($fullName === '' || $email === '' || $password === '' || $department === '') {
        $error = 'All fields are required.';
    } elseif (!in_array($department, $registrationDepartments, true)) {
        $error = 'Please select a valid department.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = 'Username or Email already exists.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, department, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'employee', 1, NOW())");
                if ($stmt->execute([$username, $hashedPassword, $fullName, $email, $department])) {
                    $success = 'Registration successful! You can now log in.';
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/login-ui/lib.php';

$illustrationPath = __DIR__ . '/assets/images/signup-image.jpg';
if (!is_file($illustrationPath)) {
    $illustrationPath = __DIR__ . '/assets/images/signin-image.jpg';
}
$illustrationVer = is_file($illustrationPath) ? (int) filemtime($illustrationPath) : time();
$illustrationFile = basename($illustrationPath);
$illustrationHref = function_exists('app_url')
    ? app_url('/assets/images/' . $illustrationFile)
    : 'assets/images/' . $illustrationFile;

$registerActionUrl = function_exists('app_url') ? app_url('/register.php') : 'register.php';
if ($companySlug !== '') {
    $registerActionUrl = company_url('register', $companySlug);
}

$assets = loginUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Register</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Register</h1>';
    echo '<p>The React register UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>login-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$registerConfig = [
    'title' => 'Create account',
    'subtitle' => 'Join ' . $selectedCompanyName . ' workspace',
    'illustrationUrl' => $illustrationHref . '?v=' . $illustrationVer,
    'loginUrl' => company_url('login', $companySlug),
    'registerActionUrl' => $registerActionUrl,
    'companySlug' => $companySlug,
    'error' => $error,
    'success' => $success,
    'companyLogoUrl' => loginUiNormalizePublicUrl($selectedCompanyLogo),
    'departments' => $registrationDepartments,
    'values' => [
        'fullName' => trim((string) ($_POST['full_name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'department' => trim((string) ($_POST['department'] ?? '')),
    ],
];
?>
<!DOCTYPE html>
<html lang="en" class="page-register">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sign Up - <?= h($selectedCompanyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__REGISTER_CFG__ = <?= json_encode($registerConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body class="page-register">
    <noscript><div style="padding:2rem;font-family:sans-serif;">JavaScript is required to register.</div></noscript>
    <div id="root"></div>
    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
