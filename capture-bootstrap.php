<?php
declare(strict_types=1);

/**
 * Localhost-only session bootstrap for marketing screenshots.
 * Never expose outside 127.0.0.1 / ::1.
 */

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$allowed = ['127.0.0.1', '::1'];
if (!in_array($remote, $allowed, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$email = 'admin@ultimatetrading.com';
$slug = 'ultimate';

$stmt = $pdo->prepare('SELECT id, username, email, full_name, role, department, company_id FROM users WHERE LOWER(email) = ? LIMIT 1');
$stmt->execute([strtolower($email)]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'User not found';
    exit;
}

@session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = (string) $user['username'];
$_SESSION['full_name'] = (string) ($user['full_name'] ?: 'System Admin');
$_SESSION['role'] = (string) $user['role'];
$_SESSION['department'] = (string) ($user['department'] ?? '');
$_SESSION['email'] = (string) $user['email'];
$_SESSION['company_id'] = (int) ($user['company_id'] ?: 1);
$_SESSION['company_slug'] = $slug;
$_SESSION['company_name'] = 'Demo Company';
$_SESSION['active_module'] = 'sales';

if (function_exists('applyWinningCompanySession')) {
    applyWinningCompanySession($slug, $pdo);
}
// Override display name so screenshots are not branded to a tenant.
$_SESSION['company_name'] = 'Demo Company';

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'user_id' => (int) $_SESSION['user_id'],
    'company_slug' => (string) $_SESSION['company_slug'],
    'session' => session_id(),
], JSON_UNESCAPED_SLASHES);
