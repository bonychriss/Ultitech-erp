<?php
/**
 * React shell for sales order view (full-width document layout).
 *
 * Expects: $page_title, $employeeHeaderTitle, $ordersHeadMarkup, $ordersViewHeadExtras, $assets.
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?= $ordersViewHeadExtras ?>
    <?= $ordersHeadMarkup ?>
</head>
<body class="dashboard page-order-view ov-page">

<?php include __DIR__ . '/../../../../includes/header_employee.php'; ?>

<style>
body.page-order-view,
body.page-order-view.dashboard {
    background: #f8fafc !important;
}
body.page-order-view .layout-main-wrapper,
body.page-order-view .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-order-view .layout-main-wrapper { align-items: stretch; }
body.page-order-view .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-order-view .employee-header.employee-header--order-view,
body.page-order-view.dashboard .employee-header.employee-header--order-view {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
}
body.page-order-view .employee-header--order-view .header-content {
    padding: 0.65rem 0 0.35rem !important;
    background: transparent !important;
    box-shadow: none !important;
}
body.page-order-view .employee-header--order-view .employee-header-page-title {
    font-size: clamp(1rem, 2vw, 1.25rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
body.page-order-view .employee-header--order-view .employee-header-page-title:empty,
body.page-order-view .employee-header--order-view .employee-header-page-heading:empty {
    display: none !important;
}
body.page-order-view .employee-header--order-view .employee-header-page-heading {
    min-height: 0;
}
main.main-content.ov-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.ov-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-width: 0;
}
@media print {
    body.page-order-view .employee-header,
    body.page-order-view .sidebar,
    .ov-no-print { display: none !important; }
}
html[data-theme="dark"] body.page-order-view,
html[data-theme="dark"] body.page-order-view main.main-content.ov-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-order-view .employee-header.employee-header--order-view {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-order-view .employee-header--order-view .employee-header-page-title {
    color: #f8fafc !important;
}
</style>

<main class="main-content ov-react-root p-0">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;margin:1rem;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to view this order.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
<style id="ov-react-font-isolation">
html body.page-order-view #root,
html body.page-order-view #root .ov-chrome,
html body.page-order-view #root .ov-chrome *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path) {
    font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
}
html body.page-order-view .ov-document-wrap,
html body.page-order-view .ov-document-wrap *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path),
html body.page-order-view #catalog-content,
html body.page-order-view #catalog-content *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path) {
    font-family: var(--ov-doc-font-stack, inherit) !important;
}
</style>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
