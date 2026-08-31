<?php
// Robust logout: ensure we target the same named session used across the app (set in includes/config.php)
// and fully purge server + client state before redirecting to the login screen.

// Load config to guarantee session name (PVSSESSID) and start the session if not started.
// This avoids edge cases where a default PHPSESSID cookie would be cleared while PVSSESSID persists.
@require_once __DIR__ . '/includes/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
	// If for some reason the session did not start in config (unlikely), start it now with the custom name.
	@session_name('PVSSESSID');
	@session_start();
}

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

// Final redirect: go straight to login page (avoid index auto-routing back into app)
header('Location: index.php');
exit; // no output after this point
?>