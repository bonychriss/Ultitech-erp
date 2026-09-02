<?php
$slug = $argv[1] ?? 'roadmaster';
$vid = isset($argv[2]) ? (int) $argv[2] : 80;
$_GET = ['company_slug' => $slug];
$_SERVER['REQUEST_URI'] = "/{$slug}";
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require __DIR__ . '/../includes/config.php';
voucher_bootstrap_operational_pdo();

echo "COLUMNS:\n";
print_r($pdo->query('SHOW COLUMNS FROM approvals')->fetchAll(PDO::FETCH_ASSOC));
echo "SAMPLE voucher {$vid}:\n";
$st = $pdo->prepare('SELECT * FROM approvals WHERE voucher_id = ? LIMIT 5');
$st->execute([$vid]);
print_r($st->fetchAll(PDO::FETCH_ASSOC));
echo "COUNT for voucher {$vid}: ";
$st = $pdo->prepare('SELECT COUNT(*) FROM approvals WHERE voucher_id = ?');
$st->execute([$vid]);
echo $st->fetchColumn() . "\n";
