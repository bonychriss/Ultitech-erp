<?php
/**
 * Alias entry under /roadmaster/stock/ — same report as main stock; assets from /stock/.
 */
$__sn = '/' . ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (str_contains($__sn, '/roadmaster/stock/')) {
    $__p = strpos($__sn, '/roadmaster/stock/');
    $stockBasePath = '/' . ltrim(substr($__sn, 0, $__p) . '/stock/', '/');
    if (substr($stockBasePath, -1) !== '/') {
        $stockBasePath .= '/';
    }
}

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'stock.php';
