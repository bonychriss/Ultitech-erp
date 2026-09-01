<?php
/**
 * Export locally imported Enerpize products + image files for live deploy.
 *
 * Usage:
 *   php scripts/export-roadmaster-enerpize-bundle.php
 *   php scripts/export-roadmaster-enerpize-bundle.php --since-id=73
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['since-id:', 'output:']);
$sinceId = isset($opts['since-id']) ? max(1, (int) $opts['since-id']) : 0;
$outputDir = isset($opts['output'])
    ? (string) $opts['output']
    : __DIR__ . '/deploy/roadmaster-enerpize-bundle';

if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = file_exists(__DIR__ . '/../env.local.php') ? 'localhost' : 'ultitech.io';
}

$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';

$ctx = stock_image_company_context();
$companyId = (int) ($ctx['company_id'] ?? 2);
$baseDir = stock_product_upload_base_dir($companyId, (string) ($ctx['slug'] ?? 'roadmaster'));
$productsRoot = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'products';

if ($sinceId === 0) {
    $sinceId = (int) ($pdo->query(
        "SELECT MIN(id) FROM products WHERE description LIKE '%Enerpize catalog%'"
    )->fetchColumn() ?: 0);
}

$sql = 'SELECT p.id, p.product_code, p.name, p.description, p.unit_price, p.brand, p.main_image,
               c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.id >= ? AND p.main_image IS NOT NULL AND TRIM(p.main_image) <> \'\'
        ORDER BY p.id';
$stmt = $pdo->prepare($sql);
$stmt->execute([max(1, $sinceId)]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($rows === []) {
    fwrite(STDERR, "No products found to export (since-id={$sinceId}).\n");
    exit(1);
}

$imagesDir = $outputDir . DIRECTORY_SEPARATOR . 'images';
if (is_dir($outputDir)) {
    rm_bundle_rrmdir($outputDir);
}
mkdir($imagesDir, 0755, true);

$manifest = [
    'generated_at' => date('c'),
    'company_id' => $companyId,
    'since_id' => $sinceId,
    'products' => [],
];

$exported = 0;
foreach ($rows as $row) {
    $productId = (int) ($row['id'] ?? 0);
    $code = trim((string) ($row['product_code'] ?? ''));
    $imageName = trim((string) ($row['main_image'] ?? ''));
    if ($productId < 1 || $code === '' || $imageName === '') {
        continue;
    }

    $sourceDir = $productsRoot . DIRECTORY_SEPARATOR . $productId;
    $targetDir = $imagesDir . DIRECTORY_SEPARATOR . $code;
    if (!is_dir($sourceDir)) {
        fwrite(STDERR, "WARN missing image dir for #{$productId} {$code}\n");
        continue;
    }

    rm_bundle_copy_tree($sourceDir, $targetDir);
    $manifest['products'][] = [
        'product_code' => $code,
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'category_name' => (string) ($row['category_name'] ?? 'Spare Parts'),
        'unit_price' => (float) ($row['unit_price'] ?? 0),
        'brand' => (string) ($row['brand'] ?? ''),
        'main_image' => $imageName,
        'image_dir' => 'images/' . $code,
    ];
    $exported++;
}

file_put_contents(
    $outputDir . DIRECTORY_SEPARATOR . 'manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "Exported {$exported} products to {$outputDir}" . PHP_EOL;

function rm_bundle_copy_tree(string $source, string $dest): void
{
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    $items = scandir($source) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $from = $source . DIRECTORY_SEPARATOR . $item;
        $to = $dest . DIRECTORY_SEPARATOR . $item;
        if (is_dir($from)) {
            rm_bundle_copy_tree($from, $to);
        } elseif (is_file($from)) {
            copy($from, $to);
        }
    }
}

function rm_bundle_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_bundle_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
