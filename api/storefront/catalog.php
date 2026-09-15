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
    $pdo = storefrontApiPdo();
    $products = storefrontApiFetchProducts($pdo);
    $categories = [];
    foreach ($products as $p) {
        $cat = trim((string) ($p['category'] ?? ''));
        if ($cat !== '' && !in_array($cat, $categories, true)) {
            $categories[] = $cat;
        }
    }
    sort($categories);
    storefrontApiJson([
        'success' => true,
        'synced_at' => date('c'),
        'count' => count($products),
        'categories' => $categories,
        'products' => $products,
    ]);
} catch (Throwable $e) {
    storefrontApiJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
}
