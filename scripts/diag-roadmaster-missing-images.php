<?php
$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';

function diag_is_test_product(string $name, string $code): bool
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

function diag_product_has_image(PDO $pdo, int $productId, string $mainImage): bool
{
    $ctx = stock_image_company_context();
    $slug = $ctx['slug'] ?? 'roadmaster';
    $companyId = (int) ($ctx['company_id'] ?? 2);

    if ($mainImage !== '') {
        $disk = stock_resolve_product_image_file($productId, 'thumbnail', $mainImage, $slug, $companyId);
        if ($disk !== null && is_file($disk)) {
            return true;
        }
    }

    try {
        $st = $pdo->prepare('SELECT image_name FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1');
        $st->execute([$productId]);
        $gallery = trim((string) $st->fetchColumn());
        if ($gallery !== '') {
            $disk = stock_resolve_product_image_file($productId, 'thumbnail', $gallery, $slug, $companyId);
            if ($disk !== null && is_file($disk)) {
                return true;
            }
        }
    } catch (Throwable $e) {
    }

    return false;
}

$rows = $pdo->query('SELECT id, product_code, name, main_image, item_type, truck_type, category_id FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$missing = [];
$test = 0;

foreach ($rows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    $code = trim((string) ($row['product_code'] ?? ''));
    if (diag_is_test_product($name, $code)) {
        $test++;
        continue;
    }
    if (!diag_product_has_image($pdo, (int) $row['id'], trim((string) ($row['main_image'] ?? '')))) {
        $missing[] = $row;
    }
}

echo 'tenant_db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo 'total_products=' . count($rows) . PHP_EOL;
echo 'test_skipped=' . $test . PHP_EOL;
echo 'missing_images=' . count($missing) . PHP_EOL;
foreach (array_slice($missing, 0, 25) as $m) {
    echo $m['id'] . "\t" . $m['product_code'] . "\t" . substr($m['name'], 0, 70) . PHP_EOL;
}
