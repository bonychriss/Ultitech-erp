<?php
/**
 * Dynamic base paths for Stock module - no hardcoded /staff/.
 * Include this in any Stock page that needs $stockBasePath or $rootPath.
 */
if (isset($stockBasePath)) {
    if (!isset($rootPath)) {
        $rootPath = dirname(rtrim($stockBasePath, '/'));
        $rootPath = ($rootPath === '' || $rootPath === '/') ? '' : $rootPath . '/';
    }
    return;
}
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptName = str_replace('\\', '/', trim($scriptName, '/'));
$parts = $scriptName === '' ? [] : explode('/', $scriptName);
$stockIndex = array_search('stock', $parts);
if ($stockIndex !== false) {
    $stockBasePath = '/' . implode('/', array_slice($parts, 0, $stockIndex + 1)) . '/';
} else {
    $stockBasePath = 'stock/';
}
$rootPath = dirname(rtrim($stockBasePath, '/'));
$rootPath = ($rootPath === '' || $rootPath === '/') ? '' : $rootPath . '/';
