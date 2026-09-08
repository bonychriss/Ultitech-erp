<?php
/**
 * Safety proxy: /stock/delete.php ? modules/products/delete.php
 * Prevents tenant rewrite (/ultimate/stock/delete.php ? stock/delete.php ? root delete.php).
 */
require __DIR__ . '/modules/products/delete.php';
