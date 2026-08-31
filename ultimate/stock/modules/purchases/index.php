<?php
/**
 * Alias entry for Purchase Orders list under /ultimate/stock/.
 */
$__sn = '/' . ltrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (strpos($__sn, '/ultimate/stock/') !== false) {
    $__p = strpos($__sn, '/ultimate/stock/');
    $stockBasePath = '/' . ltrim(substr($__sn, 0, $__p) . '/stock/', '/');
    if (substr($stockBasePath, -1) !== '/') {
        $stockBasePath .= '/';
    }
}

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'purchases' . DIRECTORY_SEPARATOR . 'index.php';
