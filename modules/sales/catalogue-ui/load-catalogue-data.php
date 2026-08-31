<?php

declare(strict_types=1);

/**
 * Resolve catalogue return URL from query string.
 */
function sales_catalogue_resolve_return_url(string $docType): string
{
    $returnUrl = trim((string) ($_GET['return'] ?? ''));
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

    return $returnUrl;
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function sales_load_catalogue_payload(PDO $pdo): array
{
    $docType = (string) ($_GET['doc'] ?? 'quote');
    if (!in_array($docType, ['quote', 'invoice', 'purchase'], true)) {
        $docType = 'quote';
    }
    $docLabel = ($docType === 'invoice') ? 'Invoice' : (($docType === 'purchase') ? 'Purchase Order' : 'Quotation');
    $addSelectedLabel = $docType === 'purchase' ? 'purchase order' : ($docType === 'invoice' ? 'invoice' : 'quotation');
    $returnUrl = sales_catalogue_resolve_return_url($docType);

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
                $prodCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
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
            $productSql .= '
                FROM products p';
            if (function_exists('columnExists') && columnExists('products', 'category_id', $salesDb) && tableExists('categories', $salesDb)) {
                $productSql .= ' LEFT JOIN categories c ON c.id = p.category_id';
            }
            $productSql .= '
                GROUP BY p.id, p.product_code, p.name, p.description, p.unit_price
                ORDER BY p.name';

            $products = $salesDb->query($productSql)->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $products = [];
    }

    $normalized = [];
    foreach ($products as $catalogueProduct) {
        $normalized[] = [
            'id' => (int) ($catalogueProduct['id'] ?? 0),
            'product_code' => (string) ($catalogueProduct['product_code'] ?? ''),
            'name' => (string) ($catalogueProduct['name'] ?? ''),
            'description' => (string) ($catalogueProduct['description'] ?? ''),
            'selling_price' => (float) ($catalogueProduct['selling_price'] ?? 0),
            'stock_quantity' => (float) ($catalogueProduct['stock_quantity'] ?? 0),
            'category_name' => (string) ($catalogueProduct['category_name'] ?? ''),
            'image_url' => function_exists('sales_product_image_url')
                ? sales_product_image_url(
                    (int) ($catalogueProduct['id'] ?? 0),
                    (string) ($catalogueProduct['main_image'] ?? ''),
                    'medium'
                )
                : '',
        ];
    }

    return [
        'ok' => true,
        'data' => [
            'products' => $normalized,
            'categories' => array_values($categories),
            'returnUrl' => $returnUrl,
            'docType' => $docType,
            'docLabel' => $docLabel,
            'addSelectedLabel' => $addSelectedLabel,
            'placeholderImage' => sales_app_url('stock/assets/images/no-image.png'),
            'storageKey' => $docType === 'purchase' ? 'purchase_catalogue_items' : 'sales_catalogue_items',
        ],
    ];
}
