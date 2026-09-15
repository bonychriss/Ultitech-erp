<?php
$_SERVER['REQUEST_URI'] = '/roadmaster/stock/products';
$_GET['company_slug'] = 'roadmaster';
@session_start();
$_SESSION['company_slug'] = 'roadmaster';
$_SESSION['company_id'] = 2;
require 'c:/xampp/htdocs/public_html/stock/config/database.php';
require_once 'c:/xampp/htdocs/public_html/stock/config/functions.php';
$on = 0;
$off = 0;
foreach ($pdo->query('SELECT id, name, product_code FROM products') as $r) {
    $w = stock_product_is_on_web((int) $r['id'], $r['product_code'], $r['name']);
    if ($w) {
        $on++;
    } else {
        $off++;
        if ($off <= 8) {
            echo 'OFF #' . $r['id'] . ' ' . $r['name'] . PHP_EOL;
        }
    }
}
echo "total on={$on} off={$off}" . PHP_EOL;
