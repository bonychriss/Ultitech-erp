<?php
// Load shared functions (starts session via config.php)
@require_once __DIR__ . '/includes/functions.php';

// Capture company slug before destroying session
$companySlug = trim((string)($_SESSION['company_slug'] ?? ''));

// Clear all session data
@session_unset();
$_SESSION = [];

// Delete the session cookie explicitly
if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	// Target both the custom name and a possible legacy PHPSESSID for safety
	$cookieNames = [session_name(), 'PHPSESSID'];
	foreach ($cookieNames as $cName) {
		setcookie($cName, '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
	}
	// Also clear the cookie probe if present so a fresh handshake occurs next visit
	setcookie('app_cookie_probe', '', time() - 42000, '/', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
}

// Destroy server-side session storage
@session_destroy();
@session_write_close();

// Regenerate a new session ID (without opening a writable session) to prevent fixation if browser keeps old ID cached
@session_start();
@session_regenerate_id(true);
@session_unset();
@session_write_close();

// Final redirect: honour ?next=, else company login, else main login
$next = trim((string) ($_GET['next'] ?? ''));
if ($next !== '' && function_exists('app_url')) {
    $next = ltrim(str_replace('\\', '/', $next), '/');
    if ($next !== '' && strpos($next, '..') === false && !preg_match('#^https?://#i', $next)) {
        $redirectUrl = app_url('/' . $next);
        header('Location: ' . $redirectUrl);
        exit;
    }
}

$redirectUrl = 'login.php';
if ($companySlug !== '') {
    $redirectUrl = company_url('login', $companySlug);
}

header('Location: ' . $redirectUrl);
exit; // no output after this point
?>