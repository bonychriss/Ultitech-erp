<?php

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';

// Legacy direct URL → sales.php short desk (Laravel Blade shell).
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (
    empty($GLOBALS['ERP_SALES_CONTEXT'])
    && empty($GLOBALS['ERP_CONTEXT'])
    && (!isset($_GET['desk']) || trim((string) $_GET['desk']) === '')
    && in_array($method, ['GET', 'HEAD'], true)
    && function_exists('sales_module_url')
) {
    $legacyQuery = $_GET;
    unset($legacyQuery['company_slug'], $legacyQuery['desk']);
    if (!isset($legacyQuery['module']) || (string) $legacyQuery['module'] === '') {
        $legacyQuery['module'] = 'sales';
    }
    $target = sales_module_url('settings/index.php', $legacyQuery);
    if (is_string($target) && $target !== '' && !preg_match('#/modules/sales/settings/index\.php(?:$|\?)#i', $target)) {
        header('Location: ' . $target, true, 302);
        exit;
    }
}

require_once __DIR__ . '/includes/settings-lib.php';
settingsDeskRequireAccess();
salesSettingsRenderReactShell();
