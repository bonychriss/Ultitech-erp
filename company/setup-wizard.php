<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
ensureMultiCompanyControlSchema();

$companyId = (int) (currentCompanyId() ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context missing.');
}

$target = app_url('/admin/company-settings.php?company_id=' . $companyId . '&step=1');
header('Location: ' . $target, true, 302);
exit;
