<?php
/**
 * Shared React shell for invoice create pages.
 *
 * Expects: $page_title, $employeeHeaderTitle, $invoicesHeadMarkup,
 *          $assets (from invoicesDeskLoadReactAssets), optional $invoicesPage.
 */
$invoicesPage = $invoicesPage ?? 'create';
$bodyExtraClass = $bodyExtraClass ?? 'page-inv-desk inv-dashboard-page';
$employeeHeaderExtraClass = $employeeHeaderExtraClass ?? 'employee-header--inv-desk';
$mainRootClass = ($invoicesPage === 'list') ? 'exp-desk-react-root' : 'inv-desk-react-root';
$bodyPageClass = ($invoicesPage === 'list') ? 'page-exp-desk exp-dashboard-page page-invoices-desk invoices-dashboard-page' : 'page-inv-desk inv-dashboard-page';
$GLOBALS['_erp_header_style_linked'] = true;?>
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
    <?= invoicesDeskShellHeadExtras() ?>
    <?= $invoicesHeadMarkup ?>
</head>
<body class="dashboard <?= htmlspecialchars($bodyPageClass, ENT_QUOTES, 'UTF-8') ?>">
<?php include __DIR__ . '/../../../../includes/header_employee.php'; ?>

<style>
body.page-inv-desk.dashboard .layout-main-wrapper,
body.page-exp-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-inv-desk.dashboard .layout-main-wrapper > .flex-grow-1,
body.page-exp-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-inv-desk,
body.page-inv-desk.dashboard,
body.page-inv-desk .layout-main-wrapper,
body.page-inv-desk .layout-main-wrapper > .flex-grow-1,
body.page-exp-desk,
body.page-exp-desk.dashboard,
body.page-exp-desk .layout-main-wrapper,
body.page-exp-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-inv-desk .employee-header.employee-header--inv-desk,
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
body.page-inv-desk .employee-header--inv-desk::after,
body.page-exp-desk .employee-header--exp-desk::after { display: none !important; }
body.page-inv-desk .employee-header--inv-desk .header-content,
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
body.page-inv-desk .employee-header--inv-desk .employee-header-page-heading,
body.page-exp-desk .employee-header--exp-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-inv-desk .employee-header--inv-desk .employee-header-page-title,
body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-inv-desk .employee-header--inv-desk .header-right.header-actions-tray,
body.page-exp-desk .employee-header--exp-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.inv-desk-react-root,
main.main-content.exp-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.inv-desk-react-root #root,
main.main-content.exp-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
    min-width: 0;
}
@media (max-width: 767.98px) {
    main.main-content.inv-desk-react-root,
    main.main-content.exp-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-inv-desk,
html[data-theme="dark"] body.page-exp-desk,
html[data-theme="dark"] body.page-inv-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-exp-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-inv-desk main.main-content.inv-desk-react-root,
html[data-theme="dark"] body.page-exp-desk main.main-content.exp-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-inv-desk .employee-header.employee-header--inv-desk,
html[data-theme="dark"] body.page-exp-desk .employee-header.employee-header--exp-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-inv-desk .employee-header--inv-desk .employee-header-page-title,
html[data-theme="dark"] body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>

<main class="main-content <?= htmlspecialchars($mainRootClass, ENT_QUOTES, 'UTF-8') ?>">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to use this page.</p>
        </div>
    </noscript>
    <div id="root"></div>
    <div id="inv-react-mount-fallback" hidden style="padding:1rem;margin:1rem 0;border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:10px;font-family:system-ui,sans-serif;">
        <strong>Invoice form did not load.</strong>
        <p style="margin:0.35rem 0 0;" id="inv-react-mount-fallback-msg">Check the browser console, or open create-init API / debug-create.php.</p>
        <p style="margin:0.5rem 0 0;font-size:0.875rem;">
            API: <code id="inv-react-mount-fallback-api"></code>
        </p>
    </div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    var api = (typeof window.__INVOICES_API_BASE__ === 'string') ? window.__INVOICES_API_BASE__ : '';
    var apiEl = document.getElementById('inv-react-mount-fallback-api');
    if (apiEl) apiEl.textContent = api || '(missing __INVOICES_API_BASE__)';
    window.addEventListener('error', function (ev) {
        var box = document.getElementById('inv-react-mount-fallback');
        var msg = document.getElementById('inv-react-mount-fallback-msg');
        if (!box) return;
        box.hidden = false;
        if (msg) msg.textContent = (ev && ev.message) ? ev.message : 'A JavaScript error prevented the form from loading.';
    });
    setTimeout(function () {
        var root = document.getElementById('root');
        var box = document.getElementById('inv-react-mount-fallback');
        if (!root || !box || !box.hidden) return;
        if (root.childElementCount === 0) {
            box.hidden = false;
            var msg = document.getElementById('inv-react-mount-fallback-msg');
            if (msg) {
                msg.textContent = 'React did not mount into #root within 4s. JS may have failed to load from ' +
                    <?= json_encode((string) ($assets['assetBase'] . $assets['jsFile']), JSON_UNESCAPED_SLASHES) ?> +
                    ' or create-init returned non-JSON.';
            }
        }
    }, 4000);
})();
</script>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
