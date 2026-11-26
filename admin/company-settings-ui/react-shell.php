<?php
/**
 * React shell for Company Settings.
 * Expects: $page_title, $employeeHeaderTitle, $assets, $cfgJson
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?><?php if (defined('COMPANY_NAME')): ?> - <?= htmlspecialchars(COMPANY_NAME); ?><?php endif; ?></title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= companySettingsUiShellHeadExtras() ?>
    <?php if (!empty($assets['cssFile'])): ?>
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <script>
      window.__COMPANY_SETTINGS_CFG__ = <?= $cfgJson ?>;
    </script>
</head>
<body class="dashboard page-exp-desk page-company-settings page-company-settings--shell">

<?php include dirname(__DIR__, 2) . '/includes/header_employee.php'; ?>

<style>
body.page-exp-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-exp-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-exp-desk,
body.page-exp-desk.dashboard,
body.page-exp-desk .layout-main-wrapper,
body.page-exp-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-exp-desk .employee-header.employee-header--exp-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
}
body.page-exp-desk .employee-header--exp-desk::after { display: none !important; }
body.page-exp-desk .employee-header--exp-desk .header-content {
    display: flex !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    width: 100%;
    background: transparent !important;
}
body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 400 !important;
    color: #0f172a !important;
}
/* Settings rail only — hide ERP sidebar + top header (same pattern as profile) */
body.page-company-settings--shell #native-sidebar,
body.page-company-settings--shell .sidebar-overlay,
body.page-company-settings--shell .employee-header-menu-btn,
body.page-company-settings--shell .employee-header,
body.page-company-settings--shell .mobile-footer,
body.page-company-settings--shell .erp-auto-breadcrumb,
body.page-company-settings--shell nav[aria-label="Breadcrumb"] {
    display: none !important;
}
body.page-company-settings--shell.sidebar-mobile-open {
    overflow: auto !important;
}
body.page-company-settings--shell .layout-main-wrapper,
body.page-company-settings--shell .layout-main-wrapper > .flex-grow-1 {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: none !important;
}
body.page-company-settings--shell,
body.page-company-settings--shell.dashboard {
    overflow-x: hidden;
}
body.page-company-settings--shell .layout-main-wrapper {
    overflow: visible !important;
}
main.main-content.exp-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 !important;
    overflow: visible !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.exp-desk-react-root #root {
    width: 100%;
    min-height: 100vh;
}
/* Beat ERP/Bootstrap button radius so Save actions stay pill-shaped */
html body.page-company-settings--shell .cs-btn-primary,
html body.page-company-settings--shell .cs-btn-danger,
html body.page-company-settings--shell .cs-btn-ghost,
html body.page-company-settings--shell button.cs-btn-primary,
html body.page-company-settings--shell button.cs-btn-danger,
html body.page-company-settings--shell button.cs-btn-ghost,
html body.page-company-settings--shell .cs-actions .cs-btn-primary,
html body.page-company-settings--shell .cs-card--profile .cs-btn-primary {
    border-radius: 9999px !important;
}
</style>

<main class="main-content exp-desk-react-root">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to view company settings.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>

</div>
</div>
</body>
</html>
