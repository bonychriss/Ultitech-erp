<?php
/**
 * Shared layout helpers for analytics module.
 */

function analytics_bootstrap(): array
{
    require_once __DIR__ . '/../../../includes/functions.php';
    requireLogin();

    if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
        $_GET['module'] = 'analytics';
    }

    $wmHelpers = __DIR__ . '/../../../todo/includes/weekly_mission_helpers.php';
    if (is_file($wmHelpers)) {
        require_once $wmHelpers;
        if (function_exists('wm_ensure_tables')) {
            wm_ensure_tables($GLOBALS['pdo']);
        }
    }

    require_once __DIR__ . '/analytics_helpers.php';

    global $pdo;
    $filters = analytics_parse_filters();
    $departments = analytics_get_departments($pdo);
    $employees = analytics_get_employees($pdo, $filters['department']);
    $missionCategories = analytics_mission_categories($pdo);

    return compact('pdo', 'filters', 'departments', 'employees', 'missionCategories');
}

function analytics_export_url(string $section): string
{
    $query = $_GET;
    $query['section'] = $section;
    $query['module'] = 'analytics';
    return 'api/export.php?' . http_build_query($query);
}

function analytics_nav(string $active): void
{
    $tabs = [
        'index' => ['label' => 'Overview', 'file' => 'index.php'],
        'sales_reports' => ['label' => 'Sales Reports', 'file' => '../sales-reports/index.php'],
        'performance' => ['label' => 'Weekly Missions', 'file' => 'performance.php'],
        'sales' => ['label' => 'Sales & Revenue', 'file' => 'sales.php'],
        'finance' => ['label' => 'Finance & Expenses', 'file' => 'finance.php'],
    ];
    echo '<nav class="da-nav">';
    foreach ($tabs as $key => $tab) {
        $class = $key === $active ? 'active' : '';
        echo '<a class="' . $class . '" href="' . htmlspecialchars($tab['file']) . '?module=analytics">' . htmlspecialchars($tab['label']) . '</a>';
    }
    echo '</nav>';
}

function analytics_page_start(string $title, string $subtitle, string $activeTab, bool $showNav = true, bool $showModulesBtn = true): void
{
    $cssUrl = function_exists('app_url') ? app_url('/assets/css/analytics.css') : '../../assets/css/analytics.css';
    $rootPath = '../../';
    $logoBase = '../../';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderTitle = $title;
    $employeeHeaderSubtitle = $subtitle;
    $employeeHeaderRightHtml = '';
    if ($showModulesBtn) {
        $modulesUrl = htmlspecialchars(function_exists('company_url') ? company_url('select-module') : '../../select-module.php');
        $employeeHeaderRightHtml = '<a href="' . $modulesUrl . '" class="da-btn da-btn-secondary analytics-modules-btn"><i class="bi bi-grid"></i> Modules</a>';
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - ERP Analytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($cssUrl) ?>?v=<?= time() ?>">
    <style>
        body.da-page .employee-header .header-content {
            display: flex !important;
            align-items: center !important;
            flex-wrap: nowrap !important;
            width: 100% !important;
            gap: 12px 16px;
        }
        body.da-page .employee-header .header-left {
            flex: 0 0 auto;
        }
        body.da-page .employee-header-page-heading {
            flex: 1 1 auto;
            min-width: 0;
            margin-left: 0 !important;
        }
        body.da-page .employee-header .header-right.header-actions-tray {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            margin-left: auto !important;
            flex: 0 0 auto !important;
            gap: 12px;
        }
        body.da-page .employee-header .notif {
            margin-right: 0;
        }
        body.da-page .analytics-modules-btn {
            height: 40px;
        }
        body.da-page .da-shell {
            padding: 0 20px 24px;
        }
        @media (max-width: 767.98px) {
            body.da-page .employee-header .header-content {
                flex-wrap: wrap !important;
            }
            body.da-page .employee-header .header-right.header-actions-tray {
                width: 100%;
                justify-content: flex-end !important;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="da-page">
<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>
<main class="da-shell">
    <?php if ($showNav) {
        analytics_nav($activeTab);
    } ?>
<?php
}

function analytics_page_end(): void
{
    ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}

function analytics_status_badge(string $status): string
{
    $map = [
        'Completed' => 'green',
        'approved' => 'green',
        'paid' => 'green',
        'In Progress' => 'blue',
        'Pending' => 'amber',
        'Delayed' => 'red',
        'cancelled' => 'red',
        'draft' => 'gray',
    ];
    $tone = $map[$status] ?? 'gray';
    return '<span class="da-badge da-badge--' . $tone . '">' . htmlspecialchars($status) . '</span>';
}

function analytics_render_chart_script(string $canvasId, array $config): void
{
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    echo '<script>(function(){var el=document.getElementById(' . json_encode($canvasId) . ');if(!el)return;new Chart(el.getContext("2d"),' . $json . ');})();</script>';
}
