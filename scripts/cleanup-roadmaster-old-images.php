<?php
/**
 * Remove old AI/generic product images from Roadmaster stock and purge orphan files.
 *
 * Usage:
 *   php scripts/cleanup-roadmaster-old-images.php --dry-run
 *   php scripts/cleanup-roadmaster-old-images.php
 *   php scripts/cleanup-roadmaster-old-images.php --trucks-only
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['dry-run', 'trucks-only', 'product-id:']);
$dryRun = array_key_exists('dry-run', $opts);
$trucksOnly = array_key_exists('trucks-only', $opts);
$onlyProductId = isset($opts['product-id']) ? (int) $opts['product-id'] : 0;

$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';

function rm_cl_is_old_ai_image(string $filename): bool
{
    $base = strtolower(pathinfo($filename, PATHINFO_FILENAME));

    return str_starts_with($base, '6a971');
}

function rm_cl_is_truck(array $row): bool
{
    return str_starts_with(trim((string) ($row['product_code'] ?? '')), 'TRK-');
}

function rm_cl_rrmdir(string $dir): void
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
            rm_cl_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function rm_cl_clear_product(PDO $pdo, string $baseDir, int $productId): void
{
    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('UPDATE products SET main_image = NULL WHERE id = ?')->execute([$productId]);
    rm_cl_rrmdir(rtrim($baseDir, '/\\') . '/products/' . $productId);
}

function rm_cl_collect_referenced_files(PDO $pdo): array
{
    $refs = [];
    $rows = $pdo->query('SELECT id, main_image FROM products')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $pid = (int) ($row['id'] ?? 0);
        $main = trim((string) ($row['main_image'] ?? ''));
        if ($pid > 0 && $main !== '') {
            $refs[$pid][$main] = true;
        }
    }

    try {
        $gallery = $pdo->query('SELECT product_id, image_name FROM product_images')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($gallery as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $name = trim((string) ($row['image_name'] ?? ''));
            if ($pid > 0 && $name !== '') {
                $refs[$pid][$name] = true;
            }
        }
    } catch (Throwable $e) {
    }

    return $refs;
}

$ctx = stock_image_company_context();
$baseDir = stock_product_upload_base_dir((int) ($ctx['company_id'] ?? 2), (string) ($ctx['slug'] ?? 'roadmaster'));
$productsDir = rtrim($baseDir, '/\\') . '/products';

echo 'Roadmaster old image cleanup' . ($dryRun ? ' [DRY RUN]' : '') . ($trucksOnly ? ' [TRUCKS ONLY]' : '') . PHP_EOL;
echo 'tenant_db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo 'products_dir=' . $productsDir . PHP_EOL;

$sql = 'SELECT id, product_code, name, main_image FROM products ORDER BY id';
if ($onlyProductId > 0) {
    $sql = 'SELECT id, product_code, name, main_image FROM products WHERE id = ' . $onlyProductId;
}
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cleared = 0;
$skipped = 0;

foreach ($rows as $row) {
    $productId = (int) ($row['id'] ?? 0);
    $code = trim((string) ($row['product_code'] ?? ''));
    $name = trim((string) ($row['name'] ?? ''));
    $mainImage = trim((string) ($row['main_image'] ?? ''));

    if ($productId < 1) {
        continue;
    }

    $isTruck = rm_cl_is_truck($row);
    $isOldAi = $mainImage !== '' && rm_cl_is_old_ai_image($mainImage);
    $shouldClear = false;
    $reason = '';

    if ($trucksOnly) {
        if ($isTruck && $mainImage !== '') {
            $shouldClear = true;
            $reason = 'truck image';
        }
    } else {
        if ($isOldAi) {
            $shouldClear = true;
            $reason = 'old AI image';
        } elseif ($isTruck && $mainImage !== '') {
            $shouldClear = true;
            $reason = 'truck AI image';
        } elseif (preg_match('/\btest\b/i', $name) && $mainImage !== '') {
            $shouldClear = true;
            $reason = 'test product image';
        }
    }

    if (!$shouldClear) {
        $skipped++;
        continue;
    }

    echo "CLEAR #{$productId} {$code} | {$name} [{$reason}]" . PHP_EOL;
    if (!$dryRun) {
        rm_cl_clear_product($pdo, $baseDir, $productId);
    }
    $cleared++;
}

$orphansRemoved = 0;
$refs = rm_cl_collect_referenced_files($pdo);

if (is_dir($productsDir)) {
    foreach (scandir($productsDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $productPath = $productsDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($productPath) || !ctype_digit($entry)) {
            continue;
        }
        $productId = (int) $entry;
        $referenced = $refs[$productId] ?? [];

        if ($referenced === []) {
            echo "ORPHAN dir products/{$productId}" . PHP_EOL;
            if (!$dryRun) {
                rm_cl_rrmdir($productPath);
            }
            $orphansRemoved++;
            continue;
        }

        foreach (['original', 'thumbnail', 'medium', 'large'] as $size) {
            $sizeDir = $productPath . DIRECTORY_SEPARATOR . $size;
            if (!is_dir($sizeDir)) {
                continue;
            }
            foreach (scandir($sizeDir) ?: [] as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $full = $sizeDir . DIRECTORY_SEPARATOR . $file;
                if (!is_file($full)) {
                    continue;
                }
                if (!isset($referenced[$file])) {
                    echo "ORPHAN file products/{$productId}/{$size}/{$file}" . PHP_EOL;
                    if (!$dryRun) {
                        @unlink($full);
                    }
                    $orphansRemoved++;
                }
            }
        }
    }
}

echo PHP_EOL . "done cleared={$cleared} skipped={$skipped} orphans_removed={$orphansRemoved}" . PHP_EOL;
