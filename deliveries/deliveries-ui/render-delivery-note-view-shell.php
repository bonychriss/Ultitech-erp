<?php
/**
 * React shell for delivery note view (matches invoice view layout).
 *
 * Expects: $page_title, $employeeHeaderTitle, $dlvHeadMarkup, $deliveryNoteViewHeadExtras, $assets.
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
    <?= $deliveryNoteViewHeadExtras ?>
    <?= $dlvHeadMarkup ?>
</head>
<body class="dashboard page-delivery-note-view ov-page">

<?php
$deliveriesRoot = dirname(__DIR__);
require_once dirname($deliveriesRoot) . '/includes/header_employee.php';
?>

<style>
body.page-delivery-note-view,
body.page-delivery-note-view.dashboard {
    background: #f8fafc !important;
}
body.page-delivery-note-view .layout-main-wrapper,
body.page-delivery-note-view .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-delivery-note-view .layout-main-wrapper { align-items: stretch; }
body.page-delivery-note-view .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-delivery-note-view .employee-header.employee-header--delivery-note-view,
body.page-delivery-note-view.dashboard .employee-header.employee-header--delivery-note-view {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
}
body.page-delivery-note-view .employee-header--delivery-note-view .header-content {
    padding: 0.65rem 0 0.35rem !important;
    background: transparent !important;
    box-shadow: none !important;
}
body.page-delivery-note-view .employee-header--delivery-note-view .employee-header-page-title {
    font-size: clamp(1rem, 2vw, 1.25rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
body.page-delivery-note-view .employee-header--delivery-note-view .employee-header-page-title:empty,
body.page-delivery-note-view .employee-header--delivery-note-view .employee-header-page-heading:empty {
    display: none !important;
}
body.page-delivery-note-view .employee-header--delivery-note-view .employee-header-page-heading {
    min-height: 0;
}
main.main-content.dnv-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.dnv-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-width: 0;
}
@media print {
    @page {
        size: A4;
        margin: 8mm;
    }

    html body.page-delivery-note-view .employee-header,
    html body.page-delivery-note-view .sidebar,
    html body.page-delivery-note-view .sidebar-container,
    html body.page-delivery-note-view #sidebar,
    html body.page-delivery-note-view .mobile-footer,
    html body.page-delivery-note-view .chatbot-launcher,
    html body.page-delivery-note-view #chatbotLauncher,
    html body.page-delivery-note-view .ov-no-print,
    html body.page-delivery-note-view .no-print {
        display: none !important;
    }

    html body.page-delivery-note-view,
    html body.page-delivery-note-view .layout-main-wrapper,
    html body.page-delivery-note-view .layout-main-wrapper > .flex-grow-1,
    html body.page-delivery-note-view main.main-content.dnv-react-root,
    html body.page-delivery-note-view #root,
    html body.page-delivery-note-view .ov-page-root {
        display: block !important;
        position: static !important;
        height: auto !important;
        max-height: none !important;
        min-height: 0 !important;
        overflow: visible !important;
        background: #fff !important;
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
        transform: none !important;
    }

    html body.page-delivery-note-view main.main-content.dnv-react-root > #root {
        display: block !important;
    }

    html body.page-delivery-note-view .ov-document-wrap,
    html body.page-delivery-note-view #delivery-note-content,
    html body.page-delivery-note-view .ov-document-wrap .page-container {
        display: block !important;
        visibility: visible !important;
        overflow: visible !important;
        height: auto !important;
        max-height: none !important;
        min-height: auto !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
    }

    html body.page-delivery-note-view .ov-document-wrap {
        padding: 0 !important;
    }

    html body.page-delivery-note-view .items-table {
        width: 100% !important;
        table-layout: fixed !important;
    }

    html body.page-delivery-note-view .items-table thead {
        display: table-header-group !important;
    }

    html body.page-delivery-note-view .items-table tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    html body.page-delivery-note-view .items-table th {
        background: var(--dn-th-bg, #ffd700) !important;
        background-color: var(--dn-th-bg, #ffd700) !important;
        color: var(--dn-th-color, #000) !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
html[data-theme="dark"] body.page-delivery-note-view,
html[data-theme="dark"] body.page-delivery-note-view main.main-content.dnv-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-delivery-note-view .employee-header.employee-header--delivery-note-view {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-delivery-note-view .employee-header--delivery-note-view .employee-header-page-title {
    color: #f8fafc !important;
}
/* Hide legacy mobile action bar on desktop (prevents duplicate Download PDF) */
.ov-action-mobile {
    display: none !important;
}
@media (max-width: 768px) {
    .ov-action-mobile {
        display: block !important;
    }
}
</style>

<main class="main-content dnv-react-root p-0">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;margin:1rem;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to view this delivery note.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
<style id="dnv-react-font-isolation">
html body.page-delivery-note-view #root,
html body.page-delivery-note-view #root .ov-chrome,
html body.page-delivery-note-view #root .ov-chrome *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path) {
    font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
}
html body.page-delivery-note-view .ov-document-wrap,
html body.page-delivery-note-view .ov-document-wrap *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path),
html body.page-delivery-note-view #delivery-note-content,
html body.page-delivery-note-view #delivery-note-content *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path) {
    font-family: var(--ov-doc-font-stack, inherit) !important;
}
</style>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
