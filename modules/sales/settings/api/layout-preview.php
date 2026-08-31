<?php

require_once __DIR__ . '/../includes/settings-lib.php';

settingsDeskRequireAccess();
settingsDeskBootstrap();

$type = strtolower(trim((string) ($_GET['type'] ?? 'truck')));
$layoutId = isset($_GET['layout']) ? (int) $_GET['layout'] : 0;

if (!in_array($type, ['truck', 'spare'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid layout type.';
    exit;
}

global $pdo;

$companyId = (int) (currentCompanyId() ?? 0);
$settings = sales_settings_fetch($pdo, $companyId);
$layoutField = $type === 'spare' ? 'spare_part_layout' : 'truck_layout';

if ($layoutId <= 0) {
    $layoutId = (int) ($settings[$layoutField] ?? 1);
}

$settings[$layoutField] = $layoutId;

$isTruck = $type === 'truck';
$layoutPath = function_exists('sales_branded_document_layout_inner_path')
    ? sales_branded_document_layout_inner_path($isTruck)
    : null;
if ($layoutPath === null && function_exists('isUltimate') && isUltimate()) {
    $layoutPath = function_exists('sales_standard_document_view_inner_path')
        ? sales_standard_document_view_inner_path('invoice')
        : null;
}

$fullView = isset($_GET['full']) && (string) $_GET['full'] === '1';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');

echo sales_settings_render_layout_preview_html($isTruck, $settings, $layoutPath, $fullView);
