<?php

declare(strict_types=1);

/**
 * Sales Reports list ? React shell.
 */
require_once __DIR__ . '/includes/sales-reports-lib.php';
require_once __DIR__ . '/includes/ui-lib.php';

salesReportsRequireAccess('view');
$pdo = salesReportsBootstrap();

$assets = salesReportsUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Sales Reports</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Sales Reports</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/sales-reports/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$initConfig = salesReportsUiBuildConfig($pdo, [
    'search' => $_GET['search'] ?? '',
    'status' => $_GET['status'] ?? '',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Reports - ERP</title>
    <?= salesReportsFontStylesheetTag() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__SALES_REPORTS_CFG__ = <?= json_encode($initConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        html:has(body.page-sales-reports),
        body.page-sales-reports {
            background: #f0f2f8 !important;
        }
        body.page-sales-reports {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
        }
        body.page-sales-reports header.employee-header {
            background: #f0f2f8 !important;
            box-shadow: none !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }
        html body .main-content.sales-reports-shell {
            padding: 0 1.25rem 2rem !important;
            max-width: none !important;
            width: 100% !important;
            background: #f0f2f8;
            min-height: calc(100vh - 80px);
        }
        html[data-theme="dark"]:has(body.page-sales-reports),
        html[data-theme="dark"] body.page-sales-reports {
            background: #0f172a !important;
        }
        html[data-theme="dark"] body.page-sales-reports header.employee-header {
            background: #0f172a !important;
            border-bottom-color: rgba(148, 163, 184, 0.12) !important;
        }
        html[data-theme="dark"] body .main-content.sales-reports-shell {
            background: #0f172a !important;
        }
    </style>
</head>
<body class="page-sales-reports sr-page">
<?php
$rootPath = '../../';
$hideHeaderCompanyBranding = true;
$employeeHeaderTitle = null;
$employeeHeaderSubtitle = null;
include dirname(__DIR__, 2) . '/includes/header_employee.php';
?>
<div class="main-content sales-reports-shell container-fluid sr-container" id="root"></div>
<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
