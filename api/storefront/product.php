<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    if (!function_exists('sales_product_image_url')) {
        $salesFn = dirname(__DIR__, 2) . '/modules/sales/functions.php';
        if (is_file($salesFn)) {
            require_once $salesFn;
        }
    }
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        storefrontApiJson(['success' => false, 'error' => 'id required'], 400);
    }
    $pdo = storefrontApiPdo();
    $products = storefrontApiFetchProducts($pdo, $id);
    if ($products === []) {
        storefrontApiJson(['success' => false, 'error' => 'Product not found'], 404);
    }
    storefrontApiJson([
        'success' => true,
        'product' => $products[0],
    ]);
} catch (Throwable $e) {
    storefrontApiJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
}
