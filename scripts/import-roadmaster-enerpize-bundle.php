<?php
/**
 * Import Enerpize product bundle (manifest + local image files) on live server.
 * Run after rsync/scp of scripts/deploy/roadmaster-enerpize-bundle/.
 *
 * Usage:
 *   HTTP_HOST=ultitech.io php scripts/import-roadmaster-enerpize-bundle.php
 *   php scripts/import-roadmaster-enerpize-bundle.php --bundle=scripts/deploy/roadmaster-enerpize-bundle
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['bundle:', 'dry-run', 'images-only']);
$dryRun = array_key_exists('dry-run', $opts);
$imagesOnly = array_key_exists('images-only', $opts);
$bundleDir = isset($opts['bundle'])
    ? (string) $opts['bundle']
    : __DIR__ . '/deploy/roadmaster-enerpize-bundle';

if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = file_exists(__DIR__ . '/../env.local.php') ? 'localhost' : 'ultitech.io';
}

$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/../stock/modules/products/import_helpers.php';

$manifestFile = rtrim($bundleDir, '/\\') . DIRECTORY_SEPARATOR . 'manifest.json';
if (!is_file($manifestFile)) {
    fwrite(STDERR, "Missing manifest: {$manifestFile}\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestFile), true);
if (!is_array($manifest) || empty($manifest['products']) || !is_array($manifest['products'])) {
    fwrite(STDERR, "Invalid manifest.json\n");
    exit(1);
}

$ctx = stock_image_company_context();
$companyId = (int) ($ctx['company_id'] ?? 2);
$baseDir = stock_product_upload_base_dir($companyId, (string) ($ctx['slug'] ?? 'roadmaster'));
$productsRoot = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'products';

$created = 0;
$updated = 0;
$images = 0;
$skipped = 0;

foreach ($manifest['products'] as $item) {
    $code = trim((string) ($item['product_code'] ?? ''));
    $name = trim((string) ($item['name'] ?? ''));
    if ($code === '' || $name === '') {
        $skipped++;
        continue;
    }

    $productId = rm_bundle_find_product_id($pdo, $code, $name);
    if ($productId === null && !$imagesOnly) {
        if ($dryRun) {
            echo "WOULD CREATE {$code} {$name}\n";
            $created++;
            continue;
        }
        $productId = rm_bundle_create_product($pdo, $item);
        $created++;
        echo "CREATED #{$productId} {$code} {$name}\n";
    } elseif ($productId !== null) {
        if (!$imagesOnly && !$dryRun) {
            rm_bundle_update_product($pdo, $productId, $item);
        }
        $updated++;
    } elseif ($imagesOnly) {
        echo "SKIP no match for {$code} {$name}\n";
        $skipped++;
        continue;
    }

    if ($productId === null || $dryRun) {
        continue;
    }

    $imageDir = rtrim($bundleDir, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($item['image_dir'] ?? ''));
    $imageName = trim((string) ($item['main_image'] ?? ''));
    if ($imageDir === '' || $imageName === '' || !is_dir($imageDir)) {
        echo "WARN no image bundle for #{$productId} {$code}\n";
        continue;
    }

    if ($dryRun) {
        echo "WOULD COPY images for #{$productId} {$code}\n";
        $images++;
        continue;
    }

    $targetDir = $productsRoot . DIRECTORY_SEPARATOR . $productId;
    rm_bundle_copy_tree($imageDir, $targetDir);
    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare(
        'INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES (?, ?, 1, NULL)'
    )->execute([$productId, $imageName]);
    $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$imageName, $productId]);
    echo "IMAGES #{$productId} {$code} {$imageName}\n";
    $images++;
}

echo PHP_EOL . "done created={$created} updated={$updated} images={$images} skipped={$skipped}" . PHP_EOL;

function rm_bundle_find_product_id(PDO $pdo, string $code, string $name): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM products WHERE product_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $stmt = $pdo->prepare('SELECT id FROM products WHERE name = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$name]);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    return $id > 0 ? $id : null;
}

function rm_bundle_create_product(PDO $pdo, array $item): int
{
    $categoryName = trim((string) ($item['category_name'] ?? 'Spare Parts'));
    $categoryId = stock_import_ensure_category($pdo, $categoryName);
    if ($categoryId === null) {
        throw new RuntimeException('Could not resolve category for ' . ($item['name'] ?? ''));
    }

    $brand = trim((string) ($item['brand'] ?? ''));
    $brandValue = $brand !== '' ? stock_import_ensure_brand($pdo, $brand, 'spare_part') : null;

    $cols = ['product_code', 'name', 'description', 'category_id', 'supplier_id', 'unit_price'];
    $vals = [
        (string) ($item['product_code'] ?? ''),
        (string) ($item['name'] ?? ''),
        (string) ($item['description'] ?? ''),
        $categoryId,
        null,
        (float) ($item['unit_price'] ?? 0),
    ];

    $optional = [
        'item_type' => 'spare_part',
        'buying_price' => 0.0,
        'reorder_level' => 10,
        'current_stock' => 0,
        'currency' => 'TZS',
        'brand' => $brandValue,
        'unit_of_measure' => 'pcs',
        'part_condition' => 'new',
        'status' => 'active',
    ];

    foreach ($optional as $column => $value) {
        if (rm_bundle_has_column($pdo, $column)) {
            $cols[] = $column;
            $vals[] = $value;
        }
    }

    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $quoted = implode(', ', array_map(static fn ($c) => '`' . $c . '`', $cols));
    $pdo->prepare("INSERT INTO products ({$quoted}) VALUES ({$placeholders})")->execute($vals);
    $productId = (int) $pdo->lastInsertId();

    try {
        $pdo->prepare(
            'INSERT INTO stock (product_id, quantity, location) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        )->execute([$productId, 'Warehouse']);
    } catch (Throwable $e) {
    }

    return $productId;
}

function rm_bundle_update_product(PDO $pdo, int $productId, array $item): void
{
    $categoryName = trim((string) ($item['category_name'] ?? ''));
    $categoryId = $categoryName !== '' ? stock_import_ensure_category($pdo, $categoryName) : null;

    $sets = ['description = ?', 'unit_price = ?'];
    $vals = [(string) ($item['description'] ?? ''), (float) ($item['unit_price'] ?? 0)];
    if ($categoryId !== null) {
        $sets[] = 'category_id = ?';
        $vals[] = $categoryId;
    }
    $vals[] = $productId;
    $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

function rm_bundle_has_column(PDO $pdo, string $column): bool
{
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $cols = [];
        }
    }

    return in_array($column, $cols, true);
}

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
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0755, true);
            }
            copy($from, $to);
        }
    }
}
