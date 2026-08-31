<?php
/**
 * Shared React shell for CRM module (products-desk layout).
 *
 * Expects: $page_title, $employeeHeaderTitle, $crmHeadMarkup, $assets
 */
$GLOBALS['_erp_header_style_linked'] = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - ERP</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= crmDeskShellHeadExtras() ?>
    <?= $crmHeadMarkup ?>
</head>
<body class="dashboard page-crm-desk">

<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>

<style>
body.page-crm-desk,
body.page-crm-desk.dashboard,
body.page-crm-desk .layout-main-wrapper,
body.page-crm-desk .layout-main-wrapper > .flex-grow-1,
body.page-crm-desk .employee-header--crm-desk,
body.page-crm-desk .employee-header--crm-desk .employee-header-page-title,
body.page-crm-desk main.main-content.crm-desk-react-root,
body.page-crm-desk main.main-content.crm-desk-react-root #root,
body.page-crm-desk .crm-desk-page,
body.page-crm-desk .crm-desk-page *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path):not(circle):not(rect):not(line):not(polyline):not(polygon),
body.page-crm-desk button,
body.page-crm-desk input,
body.page-crm-desk select,
body.page-crm-desk textarea,
body.page-crm-desk label,
body.page-crm-desk a,
body.page-crm-desk table,
body.page-crm-desk th,
body.page-crm-desk td {
    font-family: var(--erp-font-family, inherit) !important;
}
body.page-crm-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-crm-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-crm-desk,
body.page-crm-desk.dashboard,
body.page-crm-desk .layout-main-wrapper,
body.page-crm-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-crm-desk .employee-header.employee-header--crm-desk {
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
    align-items: stretch !important;
}
body.page-crm-desk .employee-header--crm-desk::after {
    display: none !important;
}
body.page-crm-desk .employee-header--crm-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-crm-desk .employee-header--crm-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-crm-desk .employee-header--crm-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
main.main-content.crm-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.crm-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
    min-width: 0;
}
@media (max-width: 1024px) {
    main.main-content.crm-desk-react-root {
        padding: 0 0.875rem 1.5rem !important;
    }
}
@media (max-width: 767.98px) {
    body.page-crm-desk .employee-header.employee-header--crm-desk {
        padding: 0 0.75rem !important;
    }
    body.page-crm-desk .employee-header--crm-desk .employee-header-page-title {
        font-size: 1rem !important;
    }
    main.main-content.crm-desk-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }
}
html[data-theme="dark"] body.page-crm-desk,
html[data-theme="dark"] body.page-crm-desk.dashboard,
html[data-theme="dark"] body.page-crm-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-crm-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-crm-desk main.main-content.crm-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-crm-desk .employee-header.employee-header--crm-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-crm-desk .employee-header--crm-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>

<main class="main-content crm-desk-react-root" role="main">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to use CRM.</p>
        </div>
    </noscript>
    <div id="root">
        <div style="padding:24px 0;color:#64748b;font-family:var(--erp-font-family,inherit);">
            Loading CRM...
        </div>
    </div>
    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</main>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
