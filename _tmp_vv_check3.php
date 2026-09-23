<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();
require __DIR__ . '/includes/config.php';
ob_end_clean();
global $pdo;
echo 'db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo 'pv_count=' . (int)$pdo->query('SELECT COUNT(*) FROM payment_vouchers')->fetchColumn() . PHP_EOL;
echo 'att_count=' . (int)$pdo->query('SELECT COUNT(*) FROM voucher_attachments')->fetchColumn() . PHP_EOL;
$dir642 = __DIR__ . '/assets/uploads/vouchers/642';
echo 'dir642_exists=' . (is_dir($dir642) ? 'yes' : 'no') . PHP_EOL;
if (is_dir($dir642)) {
    foreach (scandir($dir642) as $f) {
        if ($f === '.' || $f === '..') continue;
        echo "  file: $f\n";
    }
}
// any dirs with files
$dirs = glob(__DIR__ . '/assets/uploads/vouchers/*', GLOB_ONLYDIR) ?: [];
$withFiles = [];
foreach ($dirs as $d) {
    $files = array_values(array_filter(scandir($d) ?: [], fn($f) => $f !== '.' && $f !== '..'));
    if ($files) $withFiles[basename($d)] = $files;
}
echo 'dirs_with_files=' . count($withFiles) . PHP_EOL;
foreach (array_slice($withFiles, 0, 8, true) as $id => $files) {
    echo "voucher {$id}: " . implode(', ', array_slice($files, 0, 5)) . PHP_EOL;
}
