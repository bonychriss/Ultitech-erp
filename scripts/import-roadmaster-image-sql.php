<?php
/**
 * Import Roadmaster image SQL on server (run after rsync storage/tenant_2).
 * Usage: php scripts/import-roadmaster-image-sql.php
 */
$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';

$sqlFile = __DIR__ . '/roadmaster-images.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Missing {$sqlFile}\n");
    exit(1);
}

$sql = file_get_contents($sqlFile) ?: '';
$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: []));
$done = 0;
foreach ($statements as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    if (stripos($stmt, 'START TRANSACTION') === 0 || stripos($stmt, 'COMMIT') === 0) {
        continue;
    }
    $pdo->exec($stmt . ';');
    $done++;
}

echo "Imported {$done} statements into " . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
