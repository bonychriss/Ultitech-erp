<?php
define('ERP_SKIP_SYSTEM_FONT_OB', true);
// Load shared bootstrap (starts session, sets cookie params, DB, etc.) before any output
require_once __DIR__ . '/includes/functions.php';

unset($_SESSION['login_pending_confirmation']);

// If already logged in, route out immediately (honour ?next= for admin tools)
if (isset($_SESSION['user_id'])) {
  $mustHaveCompany = function_exists('isCompanyScopingEnabled') ? isCompanyScopingEnabled() : false;
  $sessionCompanyId = (int) ($_SESSION['company_id'] ?? 0);
  if ($mustHaveCompany && $sessionCompanyId <= 0) {
    if (function_exists('clearAuthSession')) {
      clearAuthSession();
    } else {
      unset($_SESSION['user_id'], $_SESSION['company_id']);
    }
    header('Location: ' . (function_exists('company_login_url') && getRequestedCompanySlug() !== ''
        ? company_login_url(getRequestedCompanySlug()) . '?error=company_context'
        : (function_exists('app_url') ? app_url('/login.php?error=company_context') : 'login.php?error=company_context')));
    exit();
  }
  $nextAfterLogin = trim((string) ($_GET['next'] ?? ''));
  if ($nextAfterLogin !== '' && function_exists('resolvePostLoginRedirectUrl')) {
    $sessionSlug = function_exists('resolvePostLoginCompanySlug')
      ? resolvePostLoginCompanySlug(null)
      : '';
    header('Location: ' . resolvePostLoginRedirectUrl($sessionSlug, $nextAfterLogin));
    exit();
  }
  $sessionSlug = function_exists('resolvePostLoginCompanySlug')
    ? resolvePostLoginCompanySlug(null)
    : strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
  if ($sessionSlug !== '') {
    header('Location: ' . company_dashboard_url($sessionSlug));
  } else {
    header('Location: select-module.php');
  }
  exit();
}

$error = '';
$notice = '';
if (!empty($_SESSION['login_flash_error'])) {
    $error = (string) $_SESSION['login_flash_error'];
    unset($_SESSION['login_flash_error']);
}

if (isset($_GET['error'])) {
    $errCode = strtolower(trim((string) $_GET['error']));
    if ($errCode === 'company_context') {
        $error = 'Your session expired or workspace context was lost. Please sign in again.';
    }
}

$companySlug = getRequestedCompanySlug();
$selectedCompany = null;
$selectedCompanyLogo = '';
$selectedCompanyName = '';
if ($companySlug !== '') {
    $selectedCompany = findCompanyBySlug($companySlug);
    if (!$selectedCompany || strtolower((string) ($selectedCompany['status'] ?? 'inactive')) !== 'active') {
        http_response_code(404);
        $error = 'Company not found.';
    } else {
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

if (isset($_GET['registration'])) {
    if ($_GET['registration'] === 'disabled' || $_GET['registration'] === 'restricted') {
        $notice = 'Public registration is disabled. Please contact an administrator.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['user'] ?? $_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $submittedCompanySlug = strtolower(trim((string) ($_POST['company_slug'] ?? $companySlug)));

    if ($user === '' && $password === '') {
        // Ignore empty POST (browser prefetch, back/forward, or accidental resubmit).
    } else {
    $authOk = performIndexedLogin($user, $password, $submittedCompanySlug);
    $winningSlug = function_exists('resolvePostLoginCompanySlug') ? resolvePostLoginCompanySlug(null) : '';

    if ($authOk) {
        $remember = !empty($_POST['remember']);
        if ($remember && function_exists('issueRememberMeToken')) {
            issueRememberMeToken(
                (int) ($_SESSION['user_id'] ?? 0),
                (string) ($_SESSION['company_slug'] ?? $submittedCompanySlug),
                $user
            );
        } elseif (function_exists('clearRememberMeToken')) {
            clearRememberMeToken();
            if (function_exists('clearRememberMeLoginHint')) {
                clearRememberMeLoginHint();
            }
        }
        $resolvedSlug = $winningSlug !== '' ? $winningSlug : '';
        $nextAfterLogin = trim((string) ($_POST['next'] ?? $_GET['next'] ?? ''));
        $redirectAfterLogin = resolvePostLoginRedirectUrl($resolvedSlug, $nextAfterLogin);
        header('Location: ' . $redirectAfterLogin);
        exit;
    } else {
        $flashError = 'Invalid email or password.';
        if ($submittedCompanySlug !== '') {
            $flashError = 'Invalid email or password.';
        }
        $_SESSION['login_flash_error'] = $flashError;
        $redirectQuery = array();
        if (isset($_GET['next']) && trim((string) $_GET['next']) !== '') {
            $redirectQuery['next'] = trim((string) $_GET['next']);
        }
        if ($companySlug !== '') {
            $redirectUrl = company_login_url($companySlug);
            if (!empty($redirectQuery)) {
                $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?') . http_build_query($redirectQuery);
            }
        } else {
            $redirectUrl = function_exists('app_url') ? app_url('/login.php') : 'login.php';
            if (!empty($redirectQuery)) {
                $redirectUrl .= '?' . http_build_query($redirectQuery);
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
    }
}

require_once __DIR__ . '/login-ui/lib.php';

$loginIllustrationPath = __DIR__ . '/assets/images/signin-image.jpg';
$loginIllustrationVer = is_file($loginIllustrationPath) ? (int) filemtime($loginIllustrationPath) : time();
$loginIllustrationHref = function_exists('app_url')
    ? app_url('/assets/images/signin-image.jpg')
    : 'assets/images/signin-image.jpg';

$loginActionUrl = function_exists('app_url') ? app_url('/login.php') : 'login.php';
if ($companySlug !== '') {
    $loginActionUrl = company_login_url($companySlug);
}
if (isset($_GET['next']) && trim((string) $_GET['next']) !== '') {
    $loginActionUrl .= (strpos($loginActionUrl, '?') !== false ? '&' : '?')
        . 'next=' . rawurlencode(trim((string) $_GET['next']));
}

$loginTitle = $selectedCompanyName !== '' ? $selectedCompanyName : 'Sign up';

$assets = loginUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Login</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Login</h1>';
    echo '<p>The React login UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>login-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$loginConfig = [
    'title' => $loginTitle,
    'welcomeTitle' => 'Welcome',
    'subtitle' => 'Here you log in securely',
    'illustrationUrl' => $loginIllustrationHref . '?v=' . $loginIllustrationVer,
    'registerUrl' => company_url('register', $companySlug),
    'loginActionUrl' => $loginActionUrl,
    'companySlug' => $companySlug,
    'next' => isset($_GET['next']) ? trim((string) $_GET['next']) : '',
    'error' => $error,
    'notice' => $notice,
    'companyLogoUrl' => loginUiNormalizePublicUrl($selectedCompanyLogo),
    'rememberedUser' => function_exists('getRememberMeLoginHint') ? getRememberMeLoginHint() : '',
    'rememberChecked' => trim((string) ($_COOKIE[function_exists('erpRememberMeTokenCookieName') ? erpRememberMeTokenCookieName() : ''] ?? '')) !== ''
        || (function_exists('getRememberMeLoginHint') && getRememberMeLoginHint() !== ''),
];
?>
<!DOCTYPE html>
<html lang="en" class="page-login">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= h($loginTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__LOGIN_CFG__ = <?= json_encode($loginConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body class="page-login">
    <noscript><div style="padding:2rem;font-family:sans-serif;">JavaScript is required to sign in.</div></noscript>
    <div id="root"></div>
    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>