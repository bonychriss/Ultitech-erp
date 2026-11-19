<?php
/**
 * Shows which config root / build token this request is using.
 * https://ultitech.io/ultimate/debug_build_token.php?key=ultitech-dash-debug
 * https://ultitech.io/debug_build_token.php?key=ultitech-dash-debug
 */
declare(strict_types=1);
$appRoot = __DIR__;
if (strtolower(basename($appRoot)) === 'ultimate' && is_file(dirname($appRoot) . '/includes/config.php')) {
    // Prefer real app root when invoked from physical /ultimate/
    // but also report nested config if present.
}
require_once (is_file(__DIR__ . '/includes/config.php') ? __DIR__ : dirname(__DIR__)) . '/includes/config.php';

$keyOk = isset($_GET['key']) && hash_equals('ultitech-dash-debug', (string) $_GET['key']);
if (!$keyOk) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo "key required\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "SCRIPT_FILENAME=" . ($_SERVER['SCRIPT_FILENAME'] ?? '') . "\n";
echo "SCRIPT_NAME=" . ($_SERVER['SCRIPT_NAME'] ?? '') . "\n";
echo "__DIR__ of this script may differ from config.\n";
echo "config.php realpath includes dir via reflection:\n";
$rf = new ReflectionFunction('ultitechDeployBuildToken');
echo "token_fn_file=" . $rf->getFileName() . "\n";
echo "build_token=" . (function_exists('ultitechDeployBuildToken') ? ultitechDeployBuildToken() : 'n/a') . "\n";
echo "cookie_build=" . ($_COOKIE['ultitech_app_build'] ?? '') . "\n";
echo "match=" . ((($_COOKIE['ultitech_app_build'] ?? '') === (function_exists('ultitechDeployBuildToken') ? ultitechDeployBuildToken() : '')) ? 'YES' : 'NO') . "\n";
echo "session_id=" . session_id() . "\n";
echo "user_id=" . ($_SESSION['user_id'] ?? '') . "\n";
echo "company_slug=" . ($_SESSION['company_slug'] ?? '') . "\n";

$rootCandidates = [
    'public_html' => dirname(__DIR__) === __DIR__ ? __DIR__ : (str_ends_with(str_replace('\\', '/', __DIR__), '/ultimate') ? dirname(__DIR__) : dirname(__DIR__)),
];
// Better: derive from token function file
$configFile = $rf->getFileName();
echo "config_file={$configFile}\n";
echo "config_root=" . dirname(dirname($configFile)) . "\n";
