<?php
/**
 * React shell for petty cash (same chrome as expenses desk).
 *
 * @var string $page_title
 * @var string $employeeHeaderTitle
 * @var string $pettyCashHeadMarkup
 * @var array $assets
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
    <?= pettyCashDeskShellHeadExtras() ?>
    <?= $pettyCashHeadMarkup ?>
</head>
<body class="dashboard page-exp-desk exp-dashboard-page">

<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>

<style>
body.page-exp-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-exp-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
/* Force ERP system font on all petty-cash UI (desktop + mobile) */
body.page-exp-desk,
body.page-exp-desk.dashboard,
body.page-exp-desk .layout-main-wrapper,
body.page-exp-desk .layout-main-wrapper > .flex-grow-1,
body.page-exp-desk .employee-header--exp-desk,
body.page-exp-desk .employee-header--exp-desk .employee-header-page-title,
body.page-exp-desk main.main-content.exp-desk-react-root,
body.page-exp-desk main.main-content.exp-desk-react-root #root,
body.page-exp-desk .exp-desk-page,
body.page-exp-desk .exp-desk-page *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path):not(circle):not(rect):not(line):not(polyline):not(polygon),
body.page-exp-desk .pc-voucher-view,
body.page-exp-desk .pc-voucher-view *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path):not(circle):not(rect):not(line):not(polyline):not(polygon),
body.page-exp-desk .pc-pcv-form,
body.page-exp-desk .pc-pcv-form *:not(svg):not(path):not(circle):not(rect):not(line):not(polyline):not(polygon),
body.page-exp-desk .exp-create-shell,
body.page-exp-desk .exp-create-shell *:not(svg):not(path):not(circle):not(rect):not(line):not(polyline):not(polygon),
body.page-exp-desk button,
body.page-exp-desk input,
body.page-exp-desk select,
body.page-exp-desk textarea,
body.page-exp-desk label,
body.page-exp-desk a,
body.page-exp-desk table,
body.page-exp-desk th,
body.page-exp-desk td {
    font-family: var(--erp-font-family, inherit) !important;
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
    align-items: stretch !important;
}
body.page-exp-desk .employee-header--exp-desk::after {
    display: none !important;
}
body.page-exp-desk .employee-header--exp-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-exp-desk .employee-header--exp-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-exp-desk .employee-header--exp-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.exp-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.exp-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
    min-width: 0;
}
html[data-theme="dark"] body.page-exp-desk,
html[data-theme="dark"] body.page-exp-desk main.main-content.exp-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-exp-desk .employee-header.employee-header--exp-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>

<main class="main-content exp-desk-react-root">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to use Petty Cash.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>

<?= function_exists('erp_get_system_font_body_override_html') ? erp_get_system_font_body_override_html() : '' ?>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
