<?php
declare(strict_types=1);

define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/login-ui/lib.php';

// Completed signup POST resubmit (back/refresh) ? friendly expired page, not Chrome cache miss.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['trial_signup_done'])) {
    header('Location: ' . app_url('/page-expired.php'), true, 303);
    exit;
}

if (isLoggedIn()) {
    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));
    $dest = $slug !== '' ? company_url('select-module', $slug) : app_url('/select-module.php');
    header('Location: ' . $dest);
    exit;
}

$error = '';
$success = '';
$values = [
    'email' => '',
    'company_name' => '',
    'phone' => '',
    'country_code' => '+255',
];

if (!empty($_SESSION['trial_flash']) && is_array($_SESSION['trial_flash'])) {
    $flash = $_SESSION['trial_flash'];
    unset($_SESSION['trial_flash']);
    $error = (string) ($flash['error'] ?? '');
    $success = (string) ($flash['success'] ?? '');
    if (!empty($flash['values']) && is_array($flash['values'])) {
        $values = array_merge($values, $flash['values']);
    }
}

/**
 * Flash trial form state and redirect with 303 (avoids Confirm Form Resubmission).
 */
function freeTrialRedirectGet(string $error = '', string $success = '', array $values = []): void
{
    $_SESSION['trial_flash'] = [
        'error' => $error,
        'success' => $success,
        'values' => $values,
    ];
    header('Location: ' . app_url('/free-trial.php'), true, 303);
    exit;
}

/**
 * Local flag image URL for an ISO2 country code.
 */
function freeTrialFlagUrl(string $iso2): string
{
    $iso = strtolower(trim($iso2));
    if (!preg_match('/^[a-z]{2}$/', $iso)) {
        return '';
    }
    $relative = 'assets/images/flags/' . $iso . '.svg';
    $path = __DIR__ . '/' . $relative;
    if (!is_file($path)) {
        return '';
    }
    $ver = (int) filemtime($path);
    return app_url('/' . $relative) . '?v=' . $ver;
}

$countryCodeMeta = [
    '+255' => ['iso' => 'TZ', 'label' => 'TZ +255'],
    '+254' => ['iso' => 'KE', 'label' => 'KE +254'],
    '+256' => ['iso' => 'UG', 'label' => 'UG +256'],
    '+250' => ['iso' => 'RW', 'label' => 'RW +250'],
    '+257' => ['iso' => 'BI', 'label' => 'BI +257'],
    '+260' => ['iso' => 'ZM', 'label' => 'ZM +260'],
    '+263' => ['iso' => 'ZW', 'label' => 'ZW +263'],
    '+27' => ['iso' => 'ZA', 'label' => 'ZA +27'],
    '+234' => ['iso' => 'NG', 'label' => 'NG +234'],
    '+233' => ['iso' => 'GH', 'label' => 'GH +233'],
    '+971' => ['iso' => 'AE', 'label' => 'AE +971'],
    '+966' => ['iso' => 'SA', 'label' => 'SA +966'],
    '+91' => ['iso' => 'IN', 'label' => 'IN +91'],
    '+44' => ['iso' => 'GB', 'label' => 'UK +44'],
    '+1' => ['iso' => 'US', 'label' => 'US +1'],
    '+86' => ['iso' => 'CN', 'label' => 'CN +86'],
];

$countryCodes = [];
foreach ($countryCodeMeta as $dial => $meta) {
    $iso = (string) $meta['iso'];
    $flagUrl = freeTrialFlagUrl($iso);
    $countryCodes[$dial] = [
        'iso' => $iso,
        'flagUrl' => $flagUrl,
        'label' => (string) $meta['label'],
        'display' => (string) $meta['label'],
    ];
}

/**
 * Build a unique company slug from a name.
 */
function freeTrialMakeSlug(PDO $pdo, string $name): string
{
    $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-'));
    if ($base === '') {
        $base = 'company';
    }
    $slug = $base;
    $i = 2;
    $stmt = $pdo->prepare('SELECT id FROM companies WHERE company_slug = ? LIMIT 1');
    while (true) {
        $stmt->execute([$slug]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
        if ($i > 200) {
            return $base . '-' . bin2hex(random_bytes(2));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Browser back/refresh on a completed signup POST  show friendly expired page.
    if (!empty($_SESSION['trial_signup_done']) || isLoggedIn()) {
        header('Location: ' . app_url('/page-expired.php'), true, 303);
        exit;
    }

    $email = trim(strtolower((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $companyName = trim((string) ($_POST['company_name'] ?? ''));
    $countryCode = trim((string) ($_POST['country_code'] ?? '+255'));
    $phoneLocal = trim((string) ($_POST['phone'] ?? ''));
    $industryType = 'trading';
    $fullName = $companyName;

    $values = [
        'email' => $email,
        'company_name' => $companyName,
        'phone' => $phoneLocal,
        'country_code' => $countryCode,
    ];

    $phoneDigits = preg_replace('/[^\d]/', '', $phoneLocal) ?? '';
    if ($phoneDigits !== '' && str_starts_with($phoneDigits, '0')) {
        $phoneDigits = ltrim($phoneDigits, '0');
    }
    $phone = ($countryCode !== '' && $phoneDigits !== '')
        ? $countryCode . $phoneDigits
        : trim($countryCode . ' ' . $phoneLocal);

    if ($email === '' || $password === '' || $companyName === '' || $phoneLocal === '') {
        freeTrialRedirectGet('All fields are required.', '', $values);
    } elseif (!isset($countryCodes[$countryCode])) {
        freeTrialRedirectGet('Please choose a valid country code.', '', $values);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        freeTrialRedirectGet('Enter a valid email address.', '', $values);
    } elseif ($phoneDigits === '' || strlen($phoneDigits) < 6 || strlen($phoneDigits) > 15) {
        freeTrialRedirectGet('Enter a valid phone number.', '', $values);
    } elseif (strlen($password) < 8) {
        freeTrialRedirectGet('Password must be at least 8 characters.', '', $values);
    } elseif (function_exists('validateNewUserEmailForIndex') && ($emailErr = validateNewUserEmailForIndex($email)) !== null) {
        freeTrialRedirectGet($emailErr, '', $values);
    } else {
        try {
            ensureMultiCompanyControlSchema();
            global $control_pdo;
            $db = ($control_pdo instanceof PDO) ? $control_pdo : $pdo;
            if (!($db instanceof PDO)) {
                throw new RuntimeException('Database unavailable.');
            }

            $emailNorm = function_exists('normalizeLoginEmail') ? normalizeLoginEmail($email) : $email;
            $dup = $db->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = ? OR username = ? LIMIT 1');
            $dup->execute([$emailNorm, $emailNorm]);
            if ($dup->fetch()) {
                throw new RuntimeException('An account with this email already exists. Please sign in.');
            }

            // DDL (CREATE/ALTER) implicitly commits in MySQL  run it before the signup transaction.
            $balancesFunctions = __DIR__ . '/modules/balances/functions.php';
            if (is_file($balancesFunctions)) {
                require_once $balancesFunctions;
            }
            $glSetup = __DIR__ . '/includes/general_ledger_setup.php';
            if (is_file($glSetup)) {
                require_once $glSetup;
            }
            try {
                if (function_exists('ensureBalancesSchema')) {
                    global $pdo;
                    $previousPdo = $pdo;
                    $pdo = $db;
                    try {
                        ensureBalancesSchema();
                    } finally {
                        $pdo = $previousPdo;
                    }
                }
                if (function_exists('general_ledger_ensure_accounts_table')) {
                    general_ledger_ensure_accounts_table($db);
                }
                if (function_exists('general_ledger_ensure_journal_tables')) {
                    general_ledger_ensure_journal_tables($db);
                }
            } catch (Throwable $schemaEx) {
                error_log('free-trial schema ensure: ' . $schemaEx->getMessage());
            }

            // One shared empty trial ERP database for all free-trial companies (isolated by company_id).
            $trialDbName = null;
            if (function_exists('erp_ensure_shared_trial_database')) {
                $trialDbName = erp_ensure_shared_trial_database();
            }
            if ($trialDbName === null || $trialDbName === '') {
                throw new RuntimeException('Could not prepare the free-trial database. Please try again later.');
            }

            $db->beginTransaction();

            $companySlug = freeTrialMakeSlug($db, $companyName);
            $companyCols = $db->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $companyCols = array_map('strval', $companyCols);
            $coInsert = [];
            $coValues = [];
            $coMap = [
                'company_name' => $companyName,
                'subdomain' => $companySlug,
                'company_slug' => $companySlug,
                'status' => 'active',
                'timezone' => 'Africa/Dar_es_Salaam',
                'base_currency' => 'TZS',
                'industry_type' => $industryType,
                'db_name' => $trialDbName,
                'email' => $emailNorm,
                'phone' => $phone,
                'plan_status' => 'trial',
                'trial_started_at' => null,
                'trial_ends_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            if (function_exists('erp_trial_window_now')) {
                $trialWindow = erp_trial_window_now();
                $coMap['trial_started_at'] = $trialWindow['started'];
                $coMap['trial_ends_at'] = $trialWindow['ends'];
            } else {
                $coMap['trial_started_at'] = date('Y-m-d H:i:s');
                $coMap['trial_ends_at'] = date('Y-m-d H:i:s', time() + (14 * 86400));
            }
            foreach ($coMap as $col => $val) {
                if (!in_array($col, $companyCols, true)) {
                    continue;
                }
                $coInsert[] = $col;
                $coValues[] = $val;
            }
            if (!in_array('company_name', $coInsert, true)) {
                throw new RuntimeException('Companies table is missing required columns.');
            }
            $sqlCo = 'INSERT INTO companies (' . implode(', ', $coInsert) . ') VALUES (' . implode(', ', array_fill(0, count($coInsert), '?')) . ')';
            $db->prepare($sqlCo)->execute($coValues);
            $companyId = (int) $db->lastInsertId();
            if ($companyId <= 0) {
                throw new RuntimeException('Could not create company.');
            }

            if (tableExists('company_modules', $db)) {
                $defaultModules = [
                    ['payment_voucher', 'Payment Voucher'],
                    ['sales', 'Sales'],
                    ['stock', 'Stock'],
                    ['finance', 'Finance'],
                    ['accounting', 'Accounting'],
                    ['payroll', 'Payroll'],
                    ['attendance', 'Attendance'],
                    ['revenue', 'Revenue'],
                    ['logistics', 'Logistics'],
                ];
                $insModule = $db->prepare('INSERT INTO company_modules (company_id, module_key, module_name, enabled) VALUES (?, ?, ?, 1)');
                foreach ($defaultModules as $m) {
                    $insModule->execute([$companyId, $m[0], $m[1]]);
                }
            }

            $cols = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $username = $emailNorm;
            $insertCols = ['username', 'password', 'full_name', 'email'];
            $insertVals = [$username, $passwordHash, $fullName, $emailNorm];
            if (in_array('phone', $cols, true)) {
                $insertCols[] = 'phone';
                $insertVals[] = $phone;
            }
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
            if (in_array('created_at', $cols, true)) {
                $insertCols[] = 'created_at';
                $insertVals[] = date('Y-m-d H:i:s');
            }

            $sql = 'INSERT INTO users (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', array_fill(0, count($insertCols), '?')) . ')';
            $db->prepare($sql)->execute($insertVals);
            $userId = (int) $db->lastInsertId();
            if ($userId > 0 && function_exists('syncUserCompanyIndex')) {
                syncUserCompanyIndex($companyId, $userId);
            }

            if ($db->inTransaction()) {
                $db->commit();
            }

            // DM Sans is the post-registration UI default (personalization + all pages).
            try {
                if ($userId > 0 && function_exists('saveUserFontKey')) {
                    saveUserFontKey($userId, 'dm_sans');
                }
                if (function_exists('ensureSystemSettingsSchema') && function_exists('saveSystemFontKey')) {
                    ensureSystemSettingsSchema();
                    global $pdo, $control_pdo;
                    $fontPdo = ($control_pdo instanceof PDO) ? $control_pdo : (($pdo instanceof PDO) ? $pdo : $db);
                    if ($fontPdo instanceof PDO) {
                        $chk = $fontPdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_ui_font' LIMIT 1");
                        $chk->execute();
                        $stored = strtolower(trim((string) ($chk->fetchColumn() ?: '')));
                        if ($stored === '') {
                            saveSystemFontKey('dm_sans');
                        }
                    }
                }
            } catch (Throwable $fontEx) {
                error_log('free-trial font default: ' . $fontEx->getMessage());
            }

            try {
                if (function_exists('balances_ensure_default_accounts_for_company') && $companyId > 0) {
                    balances_ensure_default_accounts_for_company($db, $companyId);
                }
            } catch (Throwable $seedEx) {
                error_log('free-trial default accounts seed: ' . $seedEx->getMessage());
            }

            $loggedIn = false;
            if ($userId > 0 && function_exists('loginUserById')) {
                $loggedIn = loginUserById($userId, $companySlug);
            }
            if (!$loggedIn && $userId > 0) {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
                @session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $emailNorm;
                $_SESSION['full_name'] = $fullName;
                $_SESSION['email'] = $emailNorm;
                $_SESSION['role'] = 'company_admin';
                $_SESSION['department'] = 'Management';
                $_SESSION['company_id'] = $companyId;
                $_SESSION['company_name'] = $companyName;
                $_SESSION['company_slug'] = $companySlug;
                if (function_exists('applyWinningCompanySession')) {
                    applyWinningCompanySession($companySlug, $db);
                }
                $loggedIn = true;
            }

            if ($loggedIn) {
                $_SESSION['trial_signup_done'] = time();
                header('Location: ' . app_url('/trial-welcome.php'), true, 303);
                exit;
            }

            $_SESSION['trial_signup_done'] = time();
            $loginUrl = function_exists('company_login_url')
                ? company_login_url($companySlug)
                : app_url('/login.php');
            header('Location: ' . $loginUrl, true, 303);
            exit;
        } catch (Throwable $e) {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            $error = $e->getMessage();
            if (stripos($error, 'There is no active transaction') !== false) {
                $error = 'Could not finish signup. Please try again.';
            } elseif (stripos($error, 'already exists') === false && stripos($error, 'Duplicate') !== false) {
                $error = 'That company or email is already registered.';
            }
            freeTrialRedirectGet($error, '', $values);
        }
    }
}

$illustrationPath = __DIR__ . '/assets/images/signup-image.jpg';
if (!is_file($illustrationPath)) {
    $illustrationPath = __DIR__ . '/assets/images/signin-image.jpg';
}
$illustrationVer = is_file($illustrationPath) ? (int) filemtime($illustrationPath) : time();
$illustrationHref = app_url('/assets/images/' . basename($illustrationPath));

$laravelRoot = __DIR__ . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';
if (!is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

$GLOBALS['ERP_TRIAL_CONTEXT'] = [
    'title' => 'Start your free trial',
    'subtitle' => 'Create your company workspace - 14 days, all modules included.',
    'illustrationUrl' => $illustrationHref . '?v=' . $illustrationVer,
    'loginUrl' => app_url('/login.php'),
    'homeUrl' => app_url('/'),
    'trialActionUrl' => app_url('/free-trial.php'),
    'error' => $error,
    'success' => $success,
    'countryCodes' => $countryCodes,
    'values' => $values,
];
$GLOBALS['ERP_TRIAL_ROUTE'] = '/free-trial';

require $laravelRoot . '/bootstrap/erp-bridge.php';
