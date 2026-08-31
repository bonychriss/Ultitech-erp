<?php
/**
 * React shell for customer catalogue (exp-desk layout).
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= customerCatalogueDeskShellHeadExtras() ?>
    <?= $catalogueHeadMarkup ?>
</head>
<body class="dashboard page-exp-desk exp-dashboard-page page-customer-catalogue-desk">

<?php include __DIR__ . '/../../../../includes/header_employee.php'; ?>

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
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
main.main-content.exp-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    background: #f8fafc !important;
}
main.main-content.exp-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    main.main-content.exp-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
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

/* Catalogue desk overrides (inline so they always apply above bundled CSS) */
body.page-customer-catalogue-desk .exp-desk-page-header.cc-page-header {
    display: grid !important;
    grid-template-columns: minmax(2.5rem, 1fr) minmax(16rem, 34rem) minmax(2.5rem, 1fr) !important;
    align-items: center;
    gap: 0.75rem 1rem;
}
body.page-customer-catalogue-desk .exp-desk-page-header.cc-page-header .exp-desk-page-header-search {
    grid-column: 2;
    width: 100%;
    max-width: 34rem;
    justify-self: center;
}
body.page-customer-catalogue-desk .exp-desk-page-header.cc-page-header .exp-desk-search-field,
body.page-customer-catalogue-desk .cc-search-mobile .exp-desk-search-field {
    border-radius: 9999px !important;
}
body.page-customer-catalogue-desk .exp-desk-page-header .cc-add-btn {
    border-radius: 9999px !important;
    padding-left: 1rem;
    padding-right: 1rem;
}
@media (max-width: 900px) {
    body.page-customer-catalogue-desk .exp-desk-page-header.cc-page-header {
        grid-template-columns: auto minmax(12rem, 1fr) auto !important;
    }
}
</style>

<main class="main-content exp-desk-react-root">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to browse the customer catalogue.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
