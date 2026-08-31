<?php
/**
 * AI settings moved to Platform Control Center (management.php?module=settings).
 */
require_once __DIR__ . '/../includes/config.php';

$params = array_merge($_GET ?: [], ['module' => 'settings']);
$target = function_exists('app_url')
    ? app_url('/admin/management.php?' . http_build_query($params))
    : 'management.php?' . http_build_query($params);

header('Location: ' . $target, true, 302);
exit;
