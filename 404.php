<?php

require_once __DIR__ . '/includes/error_page_helpers.php';

$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
    $homeUrl = '/select-module.php';
} else {
    $homeUrl = rtrim($scriptDir, '/') . '/select-module.php';
}

if (is_file(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php';
    if (function_exists('app_url')) {
        $homeUrl = app_url('/select-module.php');
    }
}

$safeHome = htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8');

render404Page([
    'title' => 'Oops! Page not found',
    'message' => 'The page you are looking for might have been removed, renamed, or is temporarily unavailable.',
    'actionsHtml' => '<a class="error404-btn error404-btn-primary" href="' . $safeHome . '">Back to Home</a>',
]);
