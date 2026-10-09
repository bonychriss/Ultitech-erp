<?php
/**
 * Cover Page module — React shell.
 * modules/cover-page/index.php
 */
require_once __DIR__ . '/includes/cover-page-lib.php';

coverPageRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'cover_page';
}

$page_title = 'Cover Page';
$employeeHeaderTitle = 'Cover Page';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--cover-page';
$bodyExtraClass = 'page-cover-page';

$assets = coverPageLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Cover Page</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Cover Page</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/cover-page/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$coverPagePdo = coverPageBootstrap();
$coverPageHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__COVER_PAGE_API__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__COVER_PAGE_BOOT__ = ' . json_encode(coverPageFetchPayload($coverPagePdo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>';

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
    <?= coverPageShellHeadExtras() ?>
    <?= $coverPageHeadMarkup ?>
</head>
<body class="dashboard page-cover-page">

<?php include __DIR__ . '/../../includes/header_employee.php'; ?>

<style>
body.page-cover-page.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-cover-page.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-cover-page,
body.page-cover-page.dashboard,
body.page-cover-page .layout-main-wrapper,
body.page-cover-page .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
html[data-theme="dark"] body.page-cover-page,
html[data-theme="dark"] body.page-cover-page.dashboard,
html[data-theme="dark"] body.page-cover-page .layout-main-wrapper,
html[data-theme="dark"] body.page-cover-page .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] main.main-content.cover-page-root {
    background: #0f172a !important;
}
body.page-cover-page .employee-header.employee-header--cover-page {
    background: transparent !important;
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
body.page-cover-page .employee-header--cover-page::after { display: none !important; }
body.page-cover-page .employee-header--cover-page .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-cover-page .employee-header--cover-page .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-cover-page .employee-header--cover-page .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 500 !important;
    letter-spacing: -0.02em;
}
body.page-cover-page .employee-header--cover-page .header-right.header-actions-tray {
    margin-left: auto !important;
}
main.main-content.cover-page-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.cover-page-root #root {
    width: 100%;
    margin: 0;
    min-height: 320px;
    min-width: 0;
}
@media (max-width: 767.98px) {
    body.page-cover-page .employee-header.employee-header--cover-page { padding: 0 0.75rem !important; }
    main.main-content.cover-page-root { padding: 0 0.75rem 1.5rem !important; }
}
</style>

<main class="main-content cover-page-root" role="main">
    <div id="root">
        <div style="padding:24px 0;color:#64748b;font-family:var(--erp-font-family,system-ui,sans-serif);">
            Loading cover page&hellip;
        </div>
    </div>
    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</main>

</body>
</html>
