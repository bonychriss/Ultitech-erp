<?php
/**
 * Shows which DB user Yii will use. Delete after fixing.
 * https://ultimate.co.tz/staff/mail/frontend/web/db-check.php
 */
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$mainLocal = dirname(__DIR__, 2) . '/common/config/main-local.php';
echo "Config file: {$mainLocal}\n";
echo "Exists: " . (is_file($mainLocal) ? 'yes' : 'NO') . "\n";
echo "Writable: " . (is_writable($mainLocal) ? 'yes' : 'no') . "\n";
echo "File mtime: " . (is_file($mainLocal) ? date('c', filemtime($mainLocal)) : '-') . "\n\n";

if (!is_file($mainLocal)) {
    exit(1);
}

$cfg = require $mainLocal;
$db = $cfg['components']['db'] ?? [];
echo "dsn: " . ($db['dsn'] ?? '(missing)') . "\n";
echo "username: " . ($db['username'] ?? '(missing)') . "\n";
echo "password set: " . ((($db['password'] ?? '') !== '') ? 'yes (' . strlen((string) $db['password']) . ' chars)' : 'NO / empty') . "\n\n";

echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? '') . "\n";
echo "__DIR__ parent chain detects live if path contains public_html or home/ultimate\n";
echo "This config __DIR__ would be: " . dirname($mainLocal) . "\n\n";

try {
    $pdo = new PDO(
        (string) ($db['dsn'] ?? ''),
        (string) ($db['username'] ?? ''),
        (string) ($db['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Connection: OK — database=" . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "Connection: FAIL — " . $e->getMessage() . "\n";
}
