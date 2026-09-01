<?php
/**
 * Export Roadmaster product image DB rows for live sync.
 * Usage: php scripts/export-roadmaster-image-sql.php > scripts/roadmaster-images.sql
 */
$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';

$out = [];
$out[] = '-- Roadmaster product image sync generated ' . date('c');
$out[] = 'START TRANSACTION;';

$products = $pdo->query('SELECT id, main_image FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($products as $p) {
    $id = (int) ($p['id'] ?? 0);
    $img = trim((string) ($p['main_image'] ?? ''));
    if ($id < 1) {
        continue;
    }
    if ($img === '') {
        $out[] = "UPDATE products SET main_image = NULL WHERE id = {$id};";
    } else {
        $out[] = 'UPDATE products SET main_image = ' . $pdo->quote($img) . " WHERE id = {$id};";
    }
}

try {
    $images = $pdo->query('SELECT product_id, image_name, is_primary, uploaded_by FROM product_images ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out[] = 'DELETE FROM product_images;';
    foreach ($images as $img) {
        $pid = (int) ($img['product_id'] ?? 0);
        if ($pid < 1) {
            continue;
        }
        $name = $pdo->quote((string) ($img['image_name'] ?? ''));
        $primary = (int) ($img['is_primary'] ?? 0);
        $uploadedBy = $img['uploaded_by'] !== null ? (int) $img['uploaded_by'] : 'NULL';
        $out[] = "INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES ({$pid}, {$name}, {$primary}, {$uploadedBy});";
    }
} catch (Throwable $e) {
    $out[] = '-- product_images table missing or error: ' . $e->getMessage();
}

$out[] = 'COMMIT;';
echo implode(PHP_EOL, $out) . PHP_EOL;
