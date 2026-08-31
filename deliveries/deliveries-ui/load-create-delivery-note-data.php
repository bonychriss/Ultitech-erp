<?php

declare(strict_types=1);

require_once __DIR__ . '/load-data.php';

$__salesFns = dirname(__DIR__, 2) . '/modules/sales/functions.php';
if (is_file($__salesFns)) {
    require_once $__salesFns;
}
unset($__salesFns);

/**
 * @return list<array{id:int,customer_name:string,contact_person:string,phone:string,address:string,city:string,country:string}>
 */
function deliveries_load_system_customers(): array
{
    $systemCustomers = [];
    try {
        $custDb = function_exists('sales_pdo') ? sales_pdo() : null;
        if (!$custDb instanceof PDO) {
            return [];
        }
        $custCols = [];
        try {
            $custCols = $custDb->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        if (empty($custCols)) {
            return [];
        }
        $nameExpr = in_array('company_name', $custCols, true) ? 'company_name' : (in_array('name', $custCols, true) ? 'name' : "''");
        $phoneExpr = in_array('phone', $custCols, true) ? 'phone' : "''";
        $contactExpr = in_array('contact_person', $custCols, true) ? 'contact_person' : "''";
        $addressExpr = in_array('address', $custCols, true) ? 'address' : "''";
        $cityExpr = in_array('city', $custCols, true) ? 'city' : "''";
        $countryExpr = in_array('country', $custCols, true) ? 'country' : "''";
        $sql = "
            SELECT id,
                   {$nameExpr} AS customer_name,
                   {$contactExpr} AS contact_person,
                   {$phoneExpr} AS phone,
                   {$addressExpr} AS address,
                   {$cityExpr} AS city,
                   {$countryExpr} AS country
            FROM customers
            ORDER BY {$nameExpr} ASC
            LIMIT 1000
        ";
        $stmtCust = $custDb->query($sql);
        $rows = $stmtCust ? ($stmtCust->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $systemCustomers[] = [
                'id' => (int) ($row['id'] ?? 0),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'contact_person' => (string) ($row['contact_person'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'address' => (string) ($row['address'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }

    return $systemCustomers;
}

/**
 * @return list<array{id:int,name:string,product_code:string,image_url:string}>
 */
function deliveries_load_catalogue_products(PDO $pdo): array
{
    $prodCols = [];
    try {
        $prodCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    if (empty($prodCols)) {
        return [];
    }
    if (in_array('main_image', $prodCols, true) && in_array('image', $prodCols, true)) {
        $imgSelect = "COALESCE(NULLIF(main_image, ''), NULLIF(image, '')) AS main_image";
    } elseif (in_array('main_image', $prodCols, true)) {
        $imgSelect = 'main_image';
    } elseif (in_array('image', $prodCols, true)) {
        $imgSelect = 'image AS main_image';
    } else {
        $imgSelect = 'NULL AS main_image';
    }

    try {
        $stmtProd = $pdo->query("SELECT id, name, product_code, {$imgSelect} FROM products ORDER BY name ASC");
        $products = $stmtProd ? ($stmtProd->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }

    $root = dirname(__DIR__, 2);
    $out = [];
    foreach ($products as $prod) {
        $pid = (int) ($prod['id'] ?? 0);
        $img = trim((string) ($prod['main_image'] ?? ''));
        $imageUrl = '';
        if ($pid > 0 && $img !== '') {
            if (function_exists('sales_load_stock_image_helpers')) {
                sales_load_stock_image_helpers();
            }
            if (function_exists('stock_product_list_image_url')) {
                $imageUrl = (string) stock_product_list_image_url($pid, $img, 'thumbnail');
            } elseif (function_exists('sales_product_image_url')) {
                $imageUrl = (string) sales_product_image_url($pid, $img, 'thumbnail');
            } else {
                $candidates = [
                    '/stock/uploads/products/' . $pid . '/thumbnail/' . $img,
                    '/stock/uploads/products/' . $pid . '/medium/' . $img,
                    '/stock/uploads/products/' . $pid . '/' . $img,
                    '/uploads/products/' . $pid . '/thumbnail/' . $img,
                    '/uploads/products/' . $pid . '/medium/' . $img,
                    '/uploads/products/' . $pid . '/' . $img,
                ];
                foreach ($candidates as $relPath) {
                    $abs = $root . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                    if (is_file($abs)) {
                        $imageUrl = function_exists('app_url') ? app_url(ltrim($relPath, '/')) : $relPath;
                        break;
                    }
                }
            }
        }
        $out[] = [
            'id' => $pid,
            'name' => (string) ($prod['name'] ?? ''),
            'product_code' => (string) ($prod['product_code'] ?? ''),
            'image_url' => $imageUrl,
        ];
    }

    return $out;
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_load_create_delivery_note_payload(PDO $pdo): array
{
    if (function_exists('ensureDeliveryNotesSchema')) {
        ensureDeliveryNotesSchema();
    }

    $returnPath = deliveries_module_url('deliveries/create_delivery_note.php');
    $cataloguePath = 'modules/sales/catalogue.php?doc=quote&return=' . rawurlencode($returnPath);
    $catalogueUrl = function_exists('app_url') ? app_url($cataloguePath) : ('/' . ltrim($cataloguePath, '/'));

    $placeholder = function_exists('app_url')
        ? app_url('assets/images/no-image.png')
        : '/assets/images/no-image.png';

    return [
        'ok' => true,
        'data' => [
            'customers' => deliveries_load_system_customers(),
            'products' => deliveries_load_catalogue_products($pdo),
            'defaultDate' => date('Y-m-d'),
            'productPlaceholder' => $placeholder,
            'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
            'urls' => [
                'dashboard' => deliveries_module_url('deliveries/index'),
                'deliveryNotes' => deliveries_module_url('deliveries/delivery_notes.php'),
                'catalogue' => $catalogueUrl,
            ],
            'moduleQuery' => deliveries_module_query(),
        ],
    ];
}
