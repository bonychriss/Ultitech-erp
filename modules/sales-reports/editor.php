<?php
require_once __DIR__ . '/includes/sales-reports-lib.php';
require_once __DIR__ . '/includes/ui-lib.php';

$id = (int) ($_GET['id'] ?? 0);
$isNew = isset($_GET['new']) && (string) $_GET['new'] === '1';

if ($isNew) {
    salesReportsRequireAccess('create');
} else {
    salesReportsRequireAccess('view');
    if ($id <= 0) {
        header('Location: ' . salesReportsUrl('editor.php', ['new' => '1']));
        exit;
    }
}

$pdo = salesReportsBootstrap();

$assets = salesReportsUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Sales Report Editor</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Report Editor</h1>';
    echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>modules/sales-reports/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$initConfig = ['mode' => 'editor', 'isNew' => $isNew, 'module' => 'analytics'];
if (!$isNew) {
    $initConfig = salesReportsUiBuildEditorShellConfig($pdo, $id);
    if (!$initConfig) {
        header('Location: ' . salesReportsUrl('index.php', ['error' => 'not_found']));
        exit;
    }
} else {
    require_once __DIR__ . '/includes/report-engine.php';

    $user = [
        'name' => (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''),
        'department' => (string) ($_SESSION['department'] ?? 'Sales'),
    ];
    $reportDomain = reportEngineNormalizeDomain($_GET['report_domain'] ?? 'sales');
    $startDate = trim((string) ($_GET['start_date'] ?? ''));
    $endDate = trim((string) ($_GET['end_date'] ?? ''));
    $hasValidDates = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
        && strtotime($startDate) <= strtotime($endDate);

    $initConfig['urls'] = [
        'apiBase' => salesReportsUiPublicUrl('api/'),
        'list' => salesReportsUrl('index.php'),
        'editor' => salesReportsUrl('editor.php'),
    ];
    $initConfig['user'] = $user;
    $initConfig['reportPeriodOptions'] = salesReportsPeriodOptions($user);
    $initConfig['reportDomains'] = array_values(reportEngineDomains());

    if ($reportDomain !== 'sales' && $hasValidDates) {
        $domainMeta = reportEngineDomains()[$reportDomain] ?? reportEngineDomains()['sales'];
        $initConfig['selectedPeriod'] = $reportDomain;
        $initConfig['defaults'] = [
            'report_domain' => $reportDomain,
            'report_name' => salesReportsFormatCoverPeriod($startDate, $endDate) . ' ' . ($domainMeta['label'] ?? 'Report'),
            'report_type' => 'management',
            'template_key' => 'standard',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'prepared_by' => $user['name'],
            'department' => $user['department'] !== '' ? $user['department'] : ($domainMeta['department_default'] ?? 'Sales'),
            'filters' => [],
        ];
    } else {
        $period = strtolower(trim((string) ($_GET['period'] ?? '')));
        if (!in_array($period, ['monthly', 'quarterly', 'annual'], true)) {
            $period = '';
        }
        $initConfig['selectedPeriod'] = $period !== '' ? $period : null;
        if ($period !== '') {
            $customStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : null;
            $customEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ? $endDate : null;
            $initConfig['defaults'] = salesReportsPeriodDefaults($period, $user, $customStart, $customEnd);
            $initConfig['defaults']['report_domain'] = 'sales';
        }
    }
}

$pageTitle = $isNew ? 'New Sales Report' : ((string) ($initConfig['report']['report_name'] ?? 'Sales Report'));
$rootPath = '../../';
$hideHeaderCompanyBranding = true;
$employeeHeaderTitle = null;
$employeeHeaderSubtitle = null;
$employeeHeaderExtraClass = 'employee-header--editor-compact';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Editor</title>
    <?= salesReportsFontStylesheetTag() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__SALES_REPORTS_CFG__ = <?= json_encode($initConfig, salesReportsUiJsonFlags()) ?>;
    </script>
    <style>
        html:has(body.page-sales-reports-editor),
        body.page-sales-reports-editor {
            background: #f0f2f8 !important;
            font-family: 'DM Sans', sans-serif;
            overflow: hidden;
            height: 100%;
        }
        body.page-sales-reports-editor #erp-chatbot-root,
        body.page-sales-reports-editor .mobile-bottom-nav,
        body.page-sales-reports-editor #themeToggleBtn,
        body.page-sales-reports-editor .notif,
        body.page-sales-reports-editor #notif-backdrop {
            display: none !important;
        }
        @media (min-width: 992px) {
            body.page-sales-reports-editor header.employee-header.employee-header--editor-compact {
                display: none !important;
            }
        }
        body.page-sales-reports-editor .layout-main-wrapper {
            height: 100vh;
            min-height: 100vh;
            overflow: hidden;
        }
        body.page-sales-reports-editor .layout-main-wrapper > .flex-grow-1 {
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
            overflow: hidden;
        }
        body.page-sales-reports-editor header.employee-header.employee-header--editor-compact {
            background: #fff !important;
            box-shadow: none !important;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
            min-height: 52px;
            position: relative;
            z-index: 30;
        }
        body.page-sales-reports-editor header.employee-header .header-content {
            min-height: 52px;
            padding: 8px 16px !important;
            display: flex;
            align-items: center;
            width: 100%;
        }
        body.page-sales-reports-editor header.employee-header .header-right {
            margin-left: auto !important;
            flex-shrink: 0;
            gap: 12px !important;
        }
        body.page-sales-reports-editor .main-content.sales-reports-editor-shell {
            padding: 0 !important;
            margin: 0 !important;
            max-width: none !important;
            width: 100% !important;
            background: #f0f2f8;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 1;
        }
        body.page-sales-reports-editor #root {
            flex: 1 1 auto;
            min-height: 0;
            height: 100%;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
    </style>
</head>
<body class="page-sales-reports-editor sr-page sr-word-app">
<?php include dirname(__DIR__, 2) . '/includes/header_employee.php'; ?>
<div class="main-content sales-reports-editor-shell" id="root"></div>
<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
