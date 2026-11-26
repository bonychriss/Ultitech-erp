<?php
/**
 * Legacy Settings Hub entry — redirects to Company Settings (main settings page).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$params = ['module' => 'settings'];
$companyId = (int) ($_GET['company_id'] ?? 0);
if ($companyId <= 0 && function_exists('currentCompanyId')) {
    $companyId = (int) (currentCompanyId() ?? 0);
}
if ($companyId <= 0) {
    $companyId = (int) ($_SESSION['active_company_id'] ?? 0);
}
$companySlug = trim((string) ($_GET['company_slug'] ?? ''));
if ($companySlug === '') {
    $companySlug = trim((string) ($_SESSION['company_slug'] ?? ''));
}
if ($companyId > 0) {
    $params['company_id'] = $companyId;
}
if ($companySlug !== '') {
    $params['company_slug'] = $companySlug;
}
if (!empty($_GET['tab'])) {
    $params['tab'] = (string) $_GET['tab'];
}

$qs = http_build_query($params);
$target = 'company-settings.php' . ($qs !== '' ? ('?' . $qs) : '');

if ($companySlug !== '' && function_exists('company_url')) {
    $target = company_url('admin/company-settings.php', $companySlug);
    $target .= (strpos($target, '?') !== false ? '&' : '?') . $qs;
} elseif (function_exists('app_url')) {
    $target = app_url('/admin/company-settings.php' . ($qs !== '' ? ('?' . $qs) : ''));
}

header('Location: ' . $target, true, 302);
exit;
