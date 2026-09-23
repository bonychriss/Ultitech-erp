<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Minimal PDO connect using .env or config
$configCandidates = [
    __DIR__ . '/includes/config.php',
    __DIR__ . '/config/database.php',
    __DIR__ . '/includes/db.php',
];
foreach ($configCandidates as $c) {
    if (is_file($c)) {
        echo "loading $c\n";
        require_once $c;
        break;
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Try common constants
    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $name = defined('DB_NAME') ? DB_NAME : '';
    $user = defined('DB_USER') ? DB_USER : 'root';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    echo "connecting {$user}@{$host}/{$name}\n";
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

echo 'db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'voucher_attachments count=' . (int)$pdo->query('SELECT COUNT(*) FROM voucher_attachments')->fetchColumn() . "\n";
$v = $pdo->prepare('SELECT id, voucher_no, supporting_documents, linked_stock_po_id FROM payment_vouchers WHERE id=?');
$v->execute([642]);
echo "voucher 642:\n";
var_export($v->fetch());
echo "\n";
$a = $pdo->prepare('SELECT * FROM voucher_attachments WHERE voucher_id=?');
$a->execute([642]);
echo "attachments for 642:\n";
var_export($a->fetchAll());
echo "\n";
$latest = $pdo->query('SELECT id, voucher_no, supporting_documents FROM payment_vouchers ORDER BY id DESC LIMIT 5')->fetchAll();
echo "latest:\n";
var_export($latest);
echo "\n";
$dirs = glob(__DIR__ . '/assets/uploads/vouchers/*', GLOB_ONLYDIR) ?: [];
rsort($dirs);
echo "dirs:\n";
foreach (array_slice($dirs, 0, 12) as $d) {
    $files = glob($d . '/*') ?: [];
    echo basename($d) . '=' . count($files) . "\n";
}
