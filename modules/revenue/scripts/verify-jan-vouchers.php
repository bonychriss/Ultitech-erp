<?php
$_GET['company_slug'] = 'ultimate';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/ultimate/';
$_SERVER['SCRIPT_NAME'] = '/ultimate/x.php';
require dirname(__DIR__, 3) . '/includes/config.php';
$st = $pdo->prepare(
    "SELECT voucher_number, entry_date FROM revenue_entries
     WHERE entry_date >= ? AND entry_date < ?
     ORDER BY entry_date ASC, id ASC LIMIT 20"
);
$st->execute(['2026-01-01', '2026-02-01']);
foreach ($st as $r) {
    echo $r['voucher_number'] . "\t" . $r['entry_date'] . PHP_EOL;
}
