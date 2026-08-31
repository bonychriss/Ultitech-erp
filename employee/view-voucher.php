<?php
/**
 * Employee route — same React voucher view as /view-voucher.php
 */
if (!defined('APP_BASE_PATH')) {
    $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $base = '';
    if ($docRoot !== '' && strncmp($appRoot, $docRoot, strlen($docRoot)) === 0) {
        $base = trim(substr($appRoot, strlen($docRoot)), '/');
    }
    define('APP_BASE_PATH', $base);
}

require dirname(__DIR__) . '/view-voucher.php';
