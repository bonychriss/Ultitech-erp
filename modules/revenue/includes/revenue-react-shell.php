<?php
/**
 * Shared React shell for revenue desk.
 *
 * Expects: $page_title, $employeeHeaderTitle, $revenueHeadMarkup, $assets.
 */
$revenuePage = $revenuePage ?? 'list';
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= revenueDeskShellHeadExtras() ?>
    <?= $revenueHeadMarkup ?>
</head>
<body class="dashboard page-rev-desk rev-dashboard-page">

<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>

<style>
body.page-rev-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-rev-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-rev-desk,
body.page-rev-desk.dashboard,
body.page-rev-desk .layout-main-wrapper,
body.page-rev-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-rev-desk .employee-header.employee-header--rev-desk {
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
body.page-rev-desk .employee-header--rev-desk::after {
    display: none !important;
}
body.page-rev-desk .employee-header--rev-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-rev-desk .employee-header--rev-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-rev-desk .employee-header--rev-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-rev-desk .employee-header--rev-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.rev-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    background: #f8fafc !important;
}
main.main-content.rev-desk-react-root #root {
    width: 100%;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    main.main-content.rev-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-rev-desk,
html[data-theme="dark"] body.page-rev-desk main.main-content.rev-desk-react-root {
    background: #0f172a !important;
}
</style>

<main class="main-content rev-desk-react-root">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to use the Revenues page.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
