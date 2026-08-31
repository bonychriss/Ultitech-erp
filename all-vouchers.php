<?php
/**
 * Legacy URL: /all-vouchers.php or /{company_slug}/all-vouchers.php
 * Forwards to the maintained admin vouchers page (correct include paths).
 */
define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$params = $_GET;
$slug = trim((string) ($params['company_slug'] ?? getRequestedCompanySlug()));
if ($slug !== '') {
    unset($params['company_slug']);
}

$target = $slug !== '' ? company_url('admin/all-vouchers.php', $slug) : app_url('/admin/all-vouchers.php');
$qs = http_build_query($params);
if ($qs !== '') {
    $target .= (str_contains($target, '?') ? '&' : '?') . $qs;
}

header('Location: ' . $target, true, 302);
exit;
