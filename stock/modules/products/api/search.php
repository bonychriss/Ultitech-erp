<?php
/**
 * Product typeahead search � accurate ranked JSON list with images.
 * stock/modules/products/api/search.php?q=...
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/paths.php';
require_once __DIR__ . '/../includes/product_search.inc.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = stock_products_normalize_query(isset($_GET['q']) ? (string) $_GET['q'] : '');
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
if ($limit < 1) {
    $limit = 10;
}
if ($limit > 25) {
    $limit = 25;
}

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');

if ($q === '') {
    echo json_encode(['ok' => true, 'data' => [], 'q' => $q], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$hasProductImages = false;
$hasBrand = false;
try {
    $pdo->query('SELECT image_name FROM product_images LIMIT 1');
    $hasProductImages = true;
} catch (PDOException $e) {
}
try {
    $pdo->query('SELECT brand FROM products LIMIT 1');
    $hasBrand = true;
} catch (PDOException $e) {
}

$mainImageExpr = $hasProductImages
    ? "COALESCE(NULLIF(TRIM(p.main_image), ''), (
            SELECT pi.image_name
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_primary DESC, pi.id ASC
            LIMIT 1
       ))"
    : 'p.main_image';

[$whereSql, $whereParams] = stock_products_build_search_clause($q, 'p', $hasBrand);
[$orderSql, $orderParams] = stock_products_search_order_sql($q, 'p');

if ($whereSql === '') {
    echo json_encode(['ok' => true, 'data' => [], 'q' => $q], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "SELECT p.id, p.name, p.product_code, p.unit_price, p.currency,
               {$mainImageExpr} AS resolved_main_image
        FROM products p
        WHERE {$whereSql}
        ORDER BY {$orderSql}
        LIMIT {$limit}";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($whereParams, $orderParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Search failed', 'data' => []], JSON_UNESCAPED_SLASHES);
    exit;
}

$data = [];
foreach ($rows as $row) {
    $filename = trim((string) ($row['resolved_main_image'] ?? ''));
    $imageUrl = '';
    if ($filename !== '' && function_exists('stock_product_list_image_url')) {
        $imageUrl = stock_product_list_image_url((int) $row['id'], $filename, 'thumbnail', (string) $base);
    }
    $data[] = [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'product_code' => (string) ($row['product_code'] ?? ''),
        'unit_price' => (float) ($row['unit_price'] ?? 0),
        'currency' => (string) ($row['currency'] ?? 'USD'),
        'image_url' => $imageUrl,
        'score' => stock_products_score_row(
            $q,
            (string) ($row['name'] ?? ''),
            (string) ($row['product_code'] ?? '')
        ),
    ];
}

echo json_encode(['ok' => true, 'data' => $data, 'q' => $q], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
