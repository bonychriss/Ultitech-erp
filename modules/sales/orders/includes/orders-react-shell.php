<?php
/**
 * Shared React shell for quotations / sales orders lists (matches expenses desk shell).
 *
 * Expects: $page_title, $employeeHeaderTitle, $ordersHeadMarkup, $assets.
 * Optional: $ordersPage ('sales_orders' | 'list' | 'quote_requests').
 */
$ordersPage = $ordersPage ?? 'list';
$ordersListLock = ($ordersPage === 'sales_orders');
$htmlLockClass = $ordersListLock ? 'page-orders-desk-lock' : '';
$GLOBALS['_erp_header_style_linked'] = true;
?>
<!DOCTYPE html>
<html lang="en"<?= $htmlLockClass !== '' ? ' class="' . htmlspecialchars($htmlLockClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - ERP</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
        <?php if ($ordersListLock): ?>
        document.documentElement.classList.add('page-orders-desk-lock');
        <?php endif; ?>
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= ordersDeskShellHeadExtras() ?>
    <?= $ordersHeadMarkup ?>
</head>
<body class="dashboard page-exp-desk exp-dashboard-page page-orders-desk orders-dashboard-page">

<?php include __DIR__ . '/../../../../includes/header_employee.php'; ?>

<style>
<?php if ($ordersListLock): ?>
/* Match invoices/revenue desk: no outer page scrollbar; only the orders table scrolls. */
html.page-orders-desk-lock,
html.page-orders-desk-lock body,
body.page-orders-desk,
body.page-orders-desk.dashboard {
    height: 100dvh !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    overscroll-behavior: none !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
    scrollbar-gutter: auto !important;
}
html.page-orders-desk-lock::-webkit-scrollbar,
html.page-orders-desk-lock body::-webkit-scrollbar,
body.page-orders-desk .layout-main-wrapper::-webkit-scrollbar,
body.page-orders-desk .flex-grow-1::-webkit-scrollbar,
main.main-content.exp-desk-react-root::-webkit-scrollbar,
main.main-content.exp-desk-react-root #root::-webkit-scrollbar,
main.main-content.exp-desk-react-root .exp-desk-page::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
body.page-orders-desk .layout-main-wrapper,
body.page-orders-desk .layout-main-wrapper.min-vh-100 {
    align-items: stretch !important;
    height: 100dvh !important;
    max-height: 100dvh !important;
    min-height: 0 !important;
    overflow: hidden !important;
    overscroll-behavior: none !important;
    scrollbar-width: none !important;
}
body.page-orders-desk .layout-main-wrapper > .flex-grow-1,
body.page-orders-desk .flex-grow-1.d-flex.flex-column {
    min-height: 0 !important;
    height: 100% !important;
    max-height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    overscroll-behavior: none !important;
    scrollbar-width: none !important;
}
body.page-orders-desk .employee-header.employee-header--exp-desk,
body.page-orders-desk .employee-header {
    flex: 0 0 auto !important;
    position: relative !important;
}
main.main-content.exp-desk-react-root {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 1rem !important;
    overflow: hidden !important;
    overscroll-behavior: none !important;
    background: #f8fafc !important;
    display: flex !important;
    flex-direction: column !important;
    scrollbar-width: none !important;
    box-sizing: border-box;
}
main.main-content.exp-desk-react-root #root,
main.main-content.exp-desk-react-root .exp-desk-page {
    width: 100%;
    flex: 1 1 auto;
    min-height: 0 !important;
    max-height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    overscroll-behavior: none !important;
}
@media (max-width: 767.98px) {
    main.main-content.exp-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
<?php else: ?>
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
<?php endif; ?>
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
    align-self: flex-start;
    overflow: visible;
    flex-shrink: 0;
}
@media (max-width: 1280px) {
    main.main-content.exp-desk-react-root {
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }
}
@media (max-width: 1024px) {
    main.main-content.exp-desk-react-root {
        padding-left: 0.875rem !important;
        padding-right: 0.875rem !important;
    }
}
@media (max-width: 767.98px) {
    body.page-exp-desk {
        --header-height: 3rem;
    }
    body.page-exp-desk .employee-header.employee-header--exp-desk {
        padding: 0 0.75rem !important;
    }
    body.page-exp-desk .employee-header--exp-desk .header-content {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 0.5rem !important;
        min-height: 3rem !important;
        padding: 0.5rem 0 !important;
    }
    main.main-content.exp-desk-react-root {
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }
}
html[data-theme="dark"] body.page-exp-desk,
html[data-theme="dark"] body.page-exp-desk .layout-main-wrapper,
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
            <p style="margin:0.35rem 0 0;">Enable JavaScript to view this page.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
