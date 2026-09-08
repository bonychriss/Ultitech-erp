<?php

/**
 * Legacy sales dashboard entry — redirect to Laravel+React sales.php desk.
 */
require_once __DIR__ . '/../../../includes/functions.php';

$slug = '';
if (function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
if ($slug === '') {
    $slug = trim((string) ($_GET['company_slug'] ?? $_SESSION['company_slug'] ?? ''));
}

$query = $_GET;
unset($query['company_slug']);
$qs = http_build_query($query);
$target = $slug !== '' && function_exists('company_url')
    ? company_url('sales', $slug)
    : (function_exists('app_url') ? app_url('/sales.php') : '/sales.php');
if ($qs !== '') {
    $target .= (str_contains($target, '?') ? '&' : '?') . $qs;
}

header('Location: ' . $target, true, 302);
exit;
