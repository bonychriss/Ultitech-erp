<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle ?? 'ERP' }} - ERP</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    @if (!empty($headMarkup))
        {!! $headMarkup !!}
    @endif
    @php
        if (function_exists('erp_get_nav_back_script_html')) {
            echo erp_get_nav_back_script_html();
        } else {
            $navBackPartial = rtrim((string) config('erp.app_root'), '\\/') . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'nav-back-script.php';
            if (is_file($navBackPartial)) {
                include $navBackPartial;
            }
        }
    @endphp
</head>
@php
    $bodyClass = trim((string) ($bodyClass ?? 'page-exp-desk exp-dashboard-page'));
    $mainRootClass = trim((string) ($mainRootClass ?? 'exp-desk-react-root'));
@endphp
<body class="dashboard {{ $bodyClass }}">
@php
    $GLOBALS['_erp_header_style_linked'] = true;
    $headerPath = rtrim((string) config('erp.app_root'), '\\/') . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'header_employee.php';
    if (is_file($headerPath)) {
        include $headerPath;
    }
@endphp
<style>
body.page-exp-desk.dashboard .layout-main-wrapper,
body.page-inv-desk.dashboard .layout-main-wrapper,
body.page-order-view .layout-main-wrapper,
body.page-invoice-view .layout-main-wrapper { align-items: stretch; }
body.page-exp-desk.dashboard .layout-main-wrapper > .flex-grow-1,
body.page-inv-desk.dashboard .layout-main-wrapper > .flex-grow-1,
body.page-order-view .layout-main-wrapper > .flex-grow-1,
body.page-invoice-view .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-exp-desk,
body.page-exp-desk.dashboard,
body.page-exp-desk .layout-main-wrapper,
body.page-exp-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}
body.page-inv-desk,
body.page-inv-desk.dashboard,
body.page-inv-desk .layout-main-wrapper,
body.page-inv-desk .layout-main-wrapper > .flex-grow-1,
body.page-order-view,
body.page-order-view.dashboard,
body.page-order-view .layout-main-wrapper,
body.page-order-view .layout-main-wrapper > .flex-grow-1,
body.page-invoice-view,
body.page-invoice-view.dashboard,
body.page-invoice-view .layout-main-wrapper,
body.page-invoice-view .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-exp-desk .employee-header.employee-header--exp-desk,
body.page-inv-desk .employee-header.employee-header--inv-desk,
body.page-order-view .employee-header.employee-header--order-view,
body.page-invoice-view .employee-header.employee-header--invoice-view {
    background: inherit !important;
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
body.page-exp-desk .employee-header--exp-desk::after,
body.page-inv-desk .employee-header--inv-desk::after { display: none !important; }
body.page-exp-desk .employee-header--exp-desk .header-content,
body.page-inv-desk .employee-header--inv-desk .header-content,
body.page-order-view .employee-header--order-view .header-content,
body.page-invoice-view .employee-header--invoice-view .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-exp-desk .employee-header--exp-desk .employee-header-page-title,
body.page-inv-desk .employee-header--inv-desk .employee-header-page-title {
    font-family: 'DM Sans', var(--erp-font-family, system-ui, sans-serif) !important;
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
body.page-order-view .employee-header--order-view .employee-header-page-title:empty,
body.page-invoice-view .employee-header--invoice-view .employee-header-page-title:empty,
body.page-order-view .employee-header--order-view .employee-header-page-heading:empty,
body.page-invoice-view .employee-header--invoice-view .employee-header-page-heading:empty {
    display: none !important;
}
main.main-content.exp-desk-react-root,
main.main-content.inv-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    background: inherit !important;
}
main.main-content.ov-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: auto !important;
    background: #f8fafc !important;
}
main.main-content.exp-desk-react-root #root,
main.main-content.inv-desk-react-root #root,
main.main-content.ov-react-root #root,
main.main-content.cashbook-react-root #root {
    width: 100%;
    min-height: 40vh;
}
@media print {
    body.page-order-view .employee-header,
    body.page-invoice-view .employee-header,
    body.page-order-view .sidebar,
    body.page-invoice-view .sidebar,
    .ov-no-print { display: none !important; }
}
</style>
<main class="main-content {{ $mainRootClass }}">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to use this page.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>
@if (!empty($footerScripts))
    {!! $footerScripts !!}
@endif
</body>
</html>
