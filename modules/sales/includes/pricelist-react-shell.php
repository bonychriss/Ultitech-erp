<?php
/**
 * React shell for sales price list (exp-desk layout).
 */
$htmlLockClass = 'page-exp-desk-lock page-exp-desk-scroll';
$bodyDeskClass = 'dashboard page-exp-desk page-exp-desk--scroll exp-dashboard-page page-pricelist-desk';
$GLOBALS['_erp_header_style_linked'] = true;
?>
<!DOCTYPE html>
<html lang="en" class="<?= htmlspecialchars($htmlLockClass, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - ERP</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
        document.documentElement.classList.add('page-exp-desk-lock', 'page-exp-desk-scroll');
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= pricelistDeskShellHeadExtras() ?>
    <?= $pricelistHeadMarkup ?>
</head>
<body class="<?= htmlspecialchars($bodyDeskClass, ENT_QUOTES, 'UTF-8') ?>">

<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>

<style>
body.page-exp-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-exp-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    height: 100dvh;
    max-height: 100dvh;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
}
html.page-exp-desk-lock,
html.page-exp-desk-lock body,
body.page-exp-desk.page-exp-desk--scroll,
body.page-exp-desk.page-exp-desk--scroll.dashboard,
body.page-exp-desk.page-exp-desk--scroll .layout-main-wrapper,
body.page-exp-desk.page-exp-desk--scroll .layout-main-wrapper.min-vh-100 {
    height: 100dvh !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
}
body.page-exp-desk.page-exp-desk--scroll .sidebar-container {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    height: 100dvh !important;
    max-height: 100dvh !important;
    z-index: 1000 !important;
}
body.page-exp-desk.page-exp-desk--scroll .layout-main-wrapper > .flex-grow-1,
body.page-exp-desk.page-exp-desk--scroll .flex-grow-1.d-flex.flex-column {
    height: 100dvh !important;
    max-height: 100dvh !important;
    min-height: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    scrollbar-width: thin !important;
    -ms-overflow-style: auto !important;
}
body.page-exp-desk.page-exp-desk--scroll main.main-content.exp-desk-react-root,
body.page-exp-desk.page-exp-desk--scroll main.main-content.exp-desk-react-root #root,
body.page-exp-desk.page-exp-desk--scroll main.main-content.exp-desk-react-root .exp-desk-page {
    height: auto !important;
    max-height: none !important;
    overflow: visible !important;
    overscroll-behavior: auto !important;
}
body.page-exp-desk.page-exp-desk--scroll main.main-content.exp-desk-react-root {
    flex: 0 1 auto !important;
    padding-bottom: 2rem !important;
}
body.page-exp-desk.page-exp-desk--scroll .employee-header.employee-header--exp-desk {
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
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
    flex: 0 0 auto !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
    align-items: stretch !important;
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
body.page-exp-desk .employee-header--exp-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
body.page-exp-desk .employee-header--exp-desk .employee-header-page-subtitle {
    color: inherit !important;
}
body.page-exp-desk .employee-header--exp-desk .vv-breadcrumb,
body.page-exp-desk .employee-header--exp-desk .exp-desk-breadcrumb {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
    margin: 0;
    font-size: 0.82rem;
    line-height: 1.3;
}
body.page-exp-desk .employee-header--exp-desk .vv-breadcrumb-link {
    color: #2563eb !important;
    font-weight: 600;
    text-decoration: none !important;
}
body.page-exp-desk .employee-header--exp-desk .vv-breadcrumb-link:hover {
    color: #1d4ed8 !important;
    text-decoration: underline !important;
}
body.page-exp-desk .employee-header--exp-desk .vv-breadcrumb-sep {
    color: #94a3b8;
}
body.page-exp-desk .employee-header--exp-desk .vv-breadcrumb-current {
    color: #64748b;
    font-weight: 600;
}
body.page-exp-desk .employee-header--exp-desk .header-content,
body.page-exp-desk .employee-header--exp-desk .header-right.header-actions-tray,
body.page-exp-desk .employee-header--exp-desk .exp-desk-header-tools {
    position: static !important;
}
body.page-exp-desk .employee-header--exp-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.55rem !important;
    align-self: center;
    overflow: visible;
    flex-shrink: 0;
}
body.page-exp-desk .employee-header--exp-desk .exp-desk-header-tools {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.55rem;
    min-width: 0;
}
body.page-exp-desk .employee-header--exp-desk .exp-desk-header-tools:empty {
    display: none;
}
@media (max-width: 767.98px) {
    body.page-exp-desk .employee-header--exp-desk .exp-desk-header-tools {
        display: none !important;
    }
}
main.main-content.exp-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: visible !important;
    background: #f8fafc !important;
}
main.main-content.exp-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
    overflow: visible !important;
}
@media (max-width: 767.98px) {
    body.page-exp-desk {
        --header-height: 3rem;
    }
    body.page-exp-desk .employee-header--exp-desk .header-content {
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        justify-content: flex-start !important;
        gap: 0.5rem !important;
        min-height: 3rem !important;
        padding: 0.5rem 0 !important;
    }
    body.page-exp-desk .employee-header--exp-desk .header-left {
        position: static !important;
        flex: 0 0 auto;
        order: 1;
    }
    body.page-exp-desk .employee-header--exp-desk .employee-header-page-heading {
        order: 2;
        min-width: 0;
        margin-left: 0 !important;
        padding-left: 0 !important;
        padding-right: 0.25rem !important;
    }
    body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
        font-size: 1.25rem !important;
        line-height: 1.25 !important;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    body.page-exp-desk .employee-header--exp-desk .vv-breadcrumb,
    body.page-exp-desk .employee-header--exp-desk .exp-desk-breadcrumb {
        font-size: 0.875rem !important;
        gap: 0.3rem;
        line-height: 1.35;
    }
    body.page-exp-desk .employee-header--exp-desk .header-right.header-actions-tray {
        order: 3;
        flex: 0 0 auto;
        gap: 0.35rem !important;
        margin-left: auto !important;
        align-self: center !important;
    }
    main.main-content.exp-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-exp-desk,
html[data-theme="dark"] body.page-exp-desk main.main-content.exp-desk-react-root {
    background: #0b141a !important;
}
html[data-theme="dark"] body.page-exp-desk .employee-header.employee-header--exp-desk {
    background: #0b141a !important;
}
html[data-theme="dark"] body.page-exp-desk .employee-header--exp-desk .employee-header-page-title {
    color: #e9edef !important;
}
@media print {
    .pl-no-print { display: none !important; }
}
@media (max-width: 767.98px) {
    body.page-exp-desk.page-exp-desk--scroll .layout-main-wrapper > .flex-grow-1 {
        -webkit-overflow-scrolling: touch !important;
    }
}
</style>

<main class="main-content exp-desk-react-root">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to view the price list.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
