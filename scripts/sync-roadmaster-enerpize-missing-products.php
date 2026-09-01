<?php
/**
 * Find Enerpize spare parts missing from Roadmaster stock and optionally import them.
 *
 * Usage:
 *   php scripts/sync-roadmaster-enerpize-missing-products.php --dry-run
 *   php scripts/sync-roadmaster-enerpize-missing-products.php --import
 *   php scripts/sync-roadmaster-enerpize-missing-products.php --import --limit=5
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['dry-run', 'import', 'limit:', 'min-score:', 'list-only']);
$dryRun = array_key_exists('dry-run', $opts) || !array_key_exists('import', $opts);
$listOnly = array_key_exists('list-only', $opts);
$doImport = array_key_exists('import', $opts) && !$dryRun && !$listOnly;
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;
$minScore = isset($opts['min-score']) ? max(50, (int) $opts['min-score']) : 72;

if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = file_exists(__DIR__ . '/../env.local.php') ? 'localhost' : 'ultitech.io';
}

$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/../stock/classes/ImageProcessor.php';
require_once __DIR__ . '/../stock/modules/products/import_helpers.php';
require_once __DIR__ . '/lib/roadmaster-enerpize-catalog.php';

function rm_sync_is_test_product(string $name, string $code): bool
{
    if (stripos($name, '__DUMMY__') === 0) {
        return true;
    }
    if (preg_match('/\b(test|sample|demo)\b/i', $name)) {
        return true;
    }
    if (preg_match('/^test/i', $code)) {
        return true;
    }

    return false;
}

function rm_sync_is_truck(array $product): bool
{
    $code = trim((string) ($product['product_code'] ?? ''));

    return str_starts_with($code, 'TRK-')
        || strtolower((string) ($product['item_type'] ?? '')) === 'vehicle'
        || trim((string) ($product['truck_type'] ?? '')) !== '';
}

function rm_sync_format_category_label(string $raw): string
{
    $raw = trim($raw, " \t,");
    if ($raw === '') {
        return 'Spare Parts';
    }
    $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    $raw = strtolower($raw);
    $parts = explode(' ', $raw);
    $parts = array_map(static function (string $word): string {
        if ($word === 'and' || $word === 'or' || $word === 'of') {
            return $word;
        }

        return ucfirst($word);
    }, $parts);

    return implode(' ', $parts);
}

function rm_sync_generate_product_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "PRD-{$year}-";
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(product_code, '-', -1) AS UNSIGNED)) FROM products WHERE product_code LIKE ?"
    );
    $stmt->execute([$prefix . '%']);
    $next = ((int) ($stmt->fetchColumn() ?: 0)) + 1;

    return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

function rm_sync_has_column(PDO $pdo, string $column): bool
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

function rm_sync_create_product(PDO $pdo, array $item): int
{
    $name = trim((string) ($item['name'] ?? ''));
    $description = trim((string) ($item['description'] ?? ''));
    if ($description === '') {
        $description = 'Roadmaster spare part sourced from Enerpize catalog.';
    }
    $categoryName = rm_sync_format_category_label((string) ($item['category'] ?? ''));
    $categoryId = stock_import_ensure_category($pdo, $categoryName);
    if ($categoryId === null) {
        throw new RuntimeException('Could not resolve category for ' . $name);
    }

    $brand = trim((string) ($item['brand'] ?? ''));
    $brandValue = $brand !== '' ? stock_import_ensure_brand($pdo, $brand, 'spare_part') : null;
    $price = (float) ($item['price'] ?? 0);
    $productCode = rm_sync_generate_product_code($pdo);

    $cols = ['product_code', 'name', 'description', 'category_id', 'supplier_id', 'unit_price'];
    $vals = [$productCode, $name, $description, $categoryId, null, $price];

    $optional = [
        'item_type' => 'spare_part',
        'buying_price' => 0.0,
        'wholesale_price' => null,
        'reorder_level' => 10,
        'current_stock' => 0,
        'currency' => 'TZS',
        'brand' => $brandValue,
        'unit_of_measure' => 'pcs',
        'part_condition' => 'new',
        'status' => 'active',
    ];

    foreach ($optional as $column => $value) {
        if (rm_sync_has_column($pdo, $column)) {
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

echo 'Roadmaster Enerpize missing-products sync';
echo $listOnly || $dryRun ? ' [DRY RUN / LIST ONLY]' : ' [IMPORT]';
echo PHP_EOL;
echo 'tenant_db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

$catalog = rm_en_load_catalog(true);
echo 'enerpize_catalog=' . count($catalog) . PHP_EOL;

$locals = [];
$rows = $pdo->query('SELECT id, product_code, name, item_type, truck_type FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    $code = trim((string) ($row['product_code'] ?? ''));
    if (rm_sync_is_test_product($name, $code) || rm_sync_is_truck($row)) {
        continue;
    }
    $locals[] = $row;
}
echo 'local_spares=' . count($locals) . PHP_EOL;

$matchedKeys = rm_en_matched_catalog_keys($catalog, $locals, $minScore);
$missing = [];
foreach ($catalog as $normalized => $item) {
    if (!isset($matchedKeys[$normalized])) {
        $missing[$normalized] = $item;
    }
}

echo 'missing_on_erp=' . count($missing) . PHP_EOL . PHP_EOL;

if ($missing === []) {
    echo "All Enerpize spare parts already exist in the system (by name match).\n";
    exit(0);
}

$listed = 0;
$imported = 0;
$failed = 0;

$ctx = stock_image_company_context();
$baseDir = stock_product_upload_base_dir((int) ($ctx['company_id'] ?? 2), (string) ($ctx['slug'] ?? 'roadmaster'));
$processor = new ImageProcessor($baseDir);
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ultitech-rm-enerpize-sync';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

foreach ($missing as $item) {
    if ($limit > 0 && $listed >= $limit) {
        break;
    }
    $listed++;

    echo 'MISSING: ' . $item['name'] . PHP_EOL;
    echo '  category: ' . ($item['category'] !== '' ? $item['category'] : '(none)') . PHP_EOL;
    echo '  price: ' . number_format((float) $item['price'], 0, '.', ',') . ' TZS' . PHP_EOL;
    if ($item['description'] !== '') {
        echo '  description: ' . mb_substr($item['description'], 0, 120) . (mb_strlen($item['description']) > 120 ? '...' : '') . PHP_EOL;
    }

    if (!$doImport) {
        continue;
    }

    try {
        $pdo->beginTransaction();
        $productId = rm_sync_create_product($pdo, $item);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo '  FAIL create: ' . $e->getMessage() . PHP_EOL;
        $failed++;
        continue;
    }

    $ext = str_contains(strtolower((string) $item['image']), '.png') ? 'png' : 'jpg';
    $tmpPath = $cacheDir . DIRECTORY_SEPARATOR . 'new_' . $productId . '.' . $ext;
    @unlink($tmpPath);

    if (!rm_en_download_file((string) $item['image'], $tmpPath)) {
        echo "  WARN created #{$productId} but image download failed\n";
        $imported++;
        continue;
    }

    try {
        $filename = rm_en_assign_product_image($pdo, $processor, $productId, $tmpPath);
        echo "  OK imported #{$productId} {$filename}\n";
        $imported++;
    } catch (Throwable $e) {
        echo '  WARN created #' . $productId . ' but image assign failed: ' . $e->getMessage() . PHP_EOL;
        $imported++;
    }

    @unlink($tmpPath);
    usleep(200000);
}

echo PHP_EOL . "done listed={$listed} imported={$imported} failed={$failed}" . PHP_EOL;
if ($dryRun && !$listOnly) {
    echo "Run with --import to add these products to stock.\n";
}
