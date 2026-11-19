<?php
/**
 * Probe: why select-module → module clicks bounce to login.
 * Open while logged in: /ultimate/debug_module_session.php
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
require dirname(__DIR__) . '/includes/config.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$sessionName = session_name();
$sid = session_id();
$cookieSid = (string) ($_COOKIE[$sessionName] ?? '');
$buildCookie = (string) ($_COOKIE['ultitech_app_build'] ?? '');
$buildNow = function_exists('ultitechDeployBuildToken') ? ultitechDeployBuildToken() : '';

$rows = [
    'time' => date('c'),
    'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'script_name' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
    'session_name' => $sessionName,
    'session_id' => $sid,
    'cookie_has_session' => $cookieSid !== '' ? 'YES' : 'NO',
    'cookie_session_matches' => ($cookieSid !== '' && $cookieSid === $sid) ? 'YES' : 'NO',
    'isLoggedIn' => function_exists('isLoggedIn') && isLoggedIn() ? 'YES' : 'NO',
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'username' => (string) ($_SESSION['username'] ?? ''),
    'role' => (string) ($_SESSION['role'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => (string) ($_SESSION['company_slug'] ?? ''),
    'requested_slug' => function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '',
    'build_cookie' => $buildCookie,
    'build_now' => $buildNow,
    'build_match' => ($buildCookie !== '' && $buildCookie === $buildNow) ? 'YES' : 'NO',
    'sample_voucher_url' => function_exists('company_url') ? company_url('admin/dashboard.php?module=voucher') : '',
    'sample_attendance_url' => function_exists('company_url') ? company_url('attendance') : '',
    'sample_accounting_url' => function_exists('company_url') ? company_url('accounting') : '',
];

foreach ($rows as $k => $v) {
    echo $k . "\t" . $v . PHP_EOL;
}

echo PHP_EOL . 'cookie_names' . "\t" . implode(', ', array_keys($_COOKIE)) . PHP_EOL;
