<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once './functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) $_SESSION['user_id'] = 1;

$returnUrl = $_GET['return'] ?? '';
$docType = $_GET['doc'] ?? 'quote';
$docLabel = ($docType === 'invoice') ? 'Invoice' : (($docType === 'purchase') ? 'Purchase Order' : 'Quotation');
if ($returnUrl === '') {
    $returnUrl = ($docType === 'invoice')
        ? sales_module_url('invoices/create.php')
        : sales_module_url('orders/create.php', ['mode' => 'new']);
}
if (strpos($returnUrl, '://') === false) {
    if ($returnUrl !== '' && $returnUrl[0] !== '/') {
        $returnUrl = '/' . $returnUrl;
    }
    $returnUrl = str_replace('/staff/', '/', $returnUrl);
    $base = defined('APP_BASE_PATH') ? rtrim((string) APP_BASE_PATH, '/') : '';
    if ($base !== '' && $returnUrl !== '' && strpos($returnUrl, $base . '/') !== 0 && $returnUrl !== $base) {
        $returnUrl = $base . $returnUrl;
    }
}

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
$products = [];

$categories = [];
try {
    if (($salesDb instanceof PDO) && function_exists('tableExists') && tableExists('categories', $salesDb)) {
        $categories = $salesDb->query("
            SELECT DISTINCT TRIM(c.name) AS name
            FROM categories c
            INNER JOIN products p ON p.category_id = c.id
            WHERE TRIM(COALESCE(c.name, '')) <> ''
            ORDER BY c.name
        ")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) {
    $categories = [];
}

try {
    if ($salesDb instanceof PDO) {
        $prodCols = [];
        try {
            $prodCols = $salesDb->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            $prodCols = [];
        }

        $imgSelect = 'NULL AS main_image';
        if (in_array('main_image', $prodCols, true) && in_array('image', $prodCols, true)) {
            $imgSelect = 'COALESCE(p.main_image, p.image) AS main_image';
        } elseif (in_array('main_image', $prodCols, true)) {
            $imgSelect = 'p.main_image AS main_image';
        } elseif (in_array('image', $prodCols, true)) {
            $imgSelect = 'p.image AS main_image';
        }

        $productSql = "
            SELECT p.id, p.product_code, p.name, p.description, p.unit_price AS selling_price,
                   $imgSelect,
                   COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) AS stock_quantity";
        if (function_exists('columnExists') && columnExists('products', 'category_id', $salesDb) && tableExists('categories', $salesDb)) {
            $productSql .= ", COALESCE(MAX(c.name), '') AS category_name";
        } else {
            $productSql .= ", '' AS category_name";
        }
        $productSql .= "
            FROM products p";
        if (function_exists('columnExists') && columnExists('products', 'category_id', $salesDb) && tableExists('categories', $salesDb)) {
            $productSql .= " LEFT JOIN categories c ON c.id = p.category_id";
        }
        $productSql .= "
            GROUP BY p.id, p.product_code, p.name, p.description, p.unit_price
            ORDER BY p.name";

        $products = $salesDb->query($productSql)->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $products = [];
}

foreach ($products as &$catalogueProduct) {
    $catalogueProduct['image_url'] = sales_product_image_url(
        (int) ($catalogueProduct['id'] ?? 0),
        (string) ($catalogueProduct['main_image'] ?? ''),
        'medium'
    );
}
unset($catalogueProduct);

$placeholderImage = sales_app_url('stock/assets/images/no-image.png');

require __DIR__ . '/partials/catalogue-ui.php';
