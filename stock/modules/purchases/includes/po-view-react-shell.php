<?php

declare(strict_types=1);

/**
 * React shell for purchase order view (matches sales order view layout).
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
<body class="dashboard page-order-view ov-page page-po-view">

<?php include dirname(__DIR__, 4) . '/includes/header_employee.php'; ?>

<style>
body.page-order-view,
body.page-order-view.dashboard,
body.page-po-view {
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
.ov-alert-banner { margin: 0.75rem 1.25rem 0; padding: 0.85rem 1rem; border-radius: 8px; background: #fff; border: 1px solid #e5e7eb; }
.ov-flash-warning { background: #fffbeb; border-color: #fcd34d; }
.ov-flash-success { background: #ecfdf5; border-color: #6ee7b7; }
.ov-supplier-review { margin: 0.75rem 1.25rem 0; padding: 1rem; border-radius: 8px; background: #fff; border: 2px solid #0d6efd; }
.ov-supplier-review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.ov-badge { background: #0d6efd; color: #fff; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 4px; }
@media print {
    body.page-order-view .employee-header,
    body.page-order-view .sidebar,
    .ov-no-print { display: none !important; }
}
</style>

<main class="main-content ov-react-root p-0">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;margin:1rem;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to view this purchase order.</p>
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
html body.page-order-view .ov-document-wrap *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path) {
    font-family: var(--ov-doc-font-stack, inherit) !important;
}
</style>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
