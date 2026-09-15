<?php
/**
 * One-time: enlarge mail body columns.
 *
 * Upload to: public_html/staff/mail/frontend/web/alter-bodies.php
 * Open:     https://ultimate.co.tz/staff/mail/frontend/web/alter-bodies.php?key=mail-setup-2026
 * DELETE this file after it succeeds.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

if (($_GET['key'] ?? '') !== 'mail-setup-2026') {
    http_response_code(403);
    echo "Forbidden. Add ?key=mail-setup-2026 to the URL.\n";
    exit;
}

$root = dirname(__DIR__, 2);
$mainLocal = $root . '/common/config/main-local.php';

if (!is_file($mainLocal)) {
    http_response_code(500);
    echo "Missing config: {$mainLocal}\n";
    exit(1);
}

$cfg = require $mainLocal;
$db = $cfg['components']['db'] ?? null;
if (!is_array($db)) {
    http_response_code(500);
    echo "No db config in main-local.php\n";
    exit(1);
}

$dsn = (string) ($db['dsn'] ?? '');
$user = (string) ($db['username'] ?? '');
$pass = (string) ($db['password'] ?? '');

echo "DSN: {$dsn}\n";
echo "User: {$user}\n\n";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $sqls = [
        'ALTER TABLE `mail_message` MODIFY `body_text` LONGTEXT NULL',
        'ALTER TABLE `mail_message` MODIFY `body_html` LONGTEXT NULL',
    ];

    foreach ($sqls as $sql) {
        echo "Running: {$sql}\n";
        $pdo->exec($sql);
        echo "  OK\n";
    }

    echo "\nColumn types now:\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM `mail_message` WHERE Field IN ('body_text','body_html')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo '  ' . $row['Field'] . ' => ' . $row['Type'] . "\n";
    }

    echo "\nDONE. Delete alter-bodies.php from the server.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
