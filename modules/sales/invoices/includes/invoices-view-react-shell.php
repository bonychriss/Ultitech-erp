<?php
/**
 * React shell for sales invoice view (full-width document layout).
 *
 * Expects: $page_title, $employeeHeaderTitle, $invoicesHeadMarkup, $invoicesViewHeadExtras, $assets.
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
    <?= $invoicesViewHeadExtras ?>
    <?= $invoicesHeadMarkup ?>
</head>
<body class="dashboard page-invoice-view ov-page">

<?php include __DIR__ . '/../../../../includes/header_employee.php'; ?>

<style>
body.page-invoice-view,
body.page-invoice-view.dashboard {
    background: #f8fafc !important;
}
body.page-invoice-view .layout-main-wrapper,
body.page-invoice-view .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-invoice-view .layout-main-wrapper { align-items: stretch; }
body.page-invoice-view .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-invoice-view .employee-header.employee-header--invoice-view,
body.page-invoice-view.dashboard .employee-header.employee-header--invoice-view {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
}
body.page-invoice-view .employee-header--invoice-view .header-content {
    padding: 0.65rem 0 0.35rem !important;
    background: transparent !important;
    box-shadow: none !important;
}
body.page-invoice-view .employee-header--invoice-view .employee-header-page-title {
    font-size: clamp(1rem, 2vw, 1.25rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
body.page-invoice-view .employee-header--invoice-view .employee-header-page-title:empty,
body.page-invoice-view .employee-header--invoice-view .employee-header-page-heading:empty {
    display: none !important;
}
body.page-invoice-view .employee-header--invoice-view .employee-header-page-heading {
    min-height: 0;
}
main.main-content.ov-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 !important;
    overflow-x: auto !important;
    overflow-y: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
body.page-invoice-view .ov-document-wrap .o-table th,
body.page-invoice-view .ov-document-wrap .spare-table th,
body.page-invoice-view .ov-document-wrap .truck-table th {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
}
body.page-invoice-view .ov-document-wrap .o-table th {
    background-color: #ffd700;
    background: #ffd700;
    color: #000;
}
main.main-content.ov-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-width: 0;
}
@media print {
    @page {
        size: A4;
        margin: 10mm;
    }

    html body.page-invoice-view #sidebar,
    html body.page-invoice-view .sidebar,
    html body.page-invoice-view .sidebar-container,
    html body.page-invoice-view .employee-header,
    html body.page-invoice-view .mobile-footer,
    html body.page-invoice-view .chatbot-launcher,
    html body.page-invoice-view #chatbotLauncher,
    html body.page-invoice-view .mobile-backdrop,
    html body.page-invoice-view .ov-no-print,
    html body.page-invoice-view .no-print,
    html body.page-invoice-view .ov-chrome,
    html body.page-invoice-view .inv-docs-card,
    html body.page-invoice-view .ov-payments-card,
    html body.page-invoice-view .ov-catalog-pane,
    html body.page-invoice-view #ov-invoice-print-frame {
        display: none !important;
        width: 0 !important;
        height: 0 !important;
        max-height: 0 !important;
        overflow: hidden !important;
        position: absolute !important;
        left: -9999px !important;
    }

    /* style.css hides .main-content > *:not(.voucher-container) — keep the React invoice root */
    html body.page-invoice-view main.main-content > #root,
    html body.page-invoice-view main.main-content.ov-react-root > #root {
        display: block !important;
    }

    html body.page-invoice-view,
    html body.page-invoice-view .layout-main-wrapper,
    html body.page-invoice-view .layout-main-wrapper > .flex-grow-1,
    html body.page-invoice-view main.main-content.ov-react-root,
    html body.page-invoice-view #root,
    html body.page-invoice-view .ov-page-root {
        display: block !important;
        background: #fff !important;
        overflow: visible !important;
        height: auto !important;
        max-height: none !important;
        min-height: 0 !important;
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
        position: static !important;
        left: auto !important;
        transform: none !important;
    }

    html body.page-invoice-view .ov-document-wrap {
        display: block !important;
        visibility: visible !important;
        padding: 0 !important;
        background: #fff !important;
        overflow: visible !important;
        width: 100% !important;
        max-width: none !important;
    }

    html body.page-invoice-view .ov-document-wrap #invoice-content,
    html body.page-invoice-view .ov-document-wrap #pdf-content,
    html body.page-invoice-view .ov-document-wrap .sheet-container,
    html body.page-invoice-view .ov-document-wrap .sheet-inner,
    html body.page-invoice-view .ov-document-wrap .spare-sheet,
    html body.page-invoice-view .ov-document-wrap .truck-sheet,
    html body.page-invoice-view .ov-document-wrap .truck-sheet-second,
    html body.page-invoice-view .ov-document-wrap .doc-terms-sheet {
        display: block !important;
        visibility: visible !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }

    html body.page-invoice-view .ov-document-wrap .sheet-container {
        box-shadow: none !important;
        margin: 0 !important;
        min-height: auto !important;
        padding: 0 !important;
    }

    html body.page-invoice-view .ov-document-wrap .o-table {
        width: 100% !important;
        table-layout: fixed !important;
    }

    html body.page-invoice-view .ov-document-wrap .o-table th,
    html body.page-invoice-view .ov-document-wrap .o-table td {
        word-break: normal !important;
        overflow-wrap: break-word !important;
        hyphens: manual !important;
        white-space: normal !important;
        line-height: 1.35 !important;
    }

    html body.page-invoice-view .ov-document-wrap .o-table th,
    html body.page-invoice-view .ov-document-wrap .spare-table th,
    html body.page-invoice-view .ov-document-wrap .truck-table th {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    html body.page-invoice-view .ov-document-wrap .o-table th {
        background-color: #ffd700 !important;
        background: #ffd700 !important;
        color: #000 !important;
    }
}
html[data-theme="dark"] body.page-invoice-view,
html[data-theme="dark"] body.page-invoice-view main.main-content.ov-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-invoice-view .employee-header.employee-header--invoice-view {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-invoice-view .employee-header--invoice-view .employee-header-page-title {
    color: #f8fafc !important;
}
</style>

<main class="main-content ov-react-root p-0">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;margin:1rem;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to view this invoice.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
<style id="ov-react-font-isolation">
html body.page-invoice-view #root,
html body.page-invoice-view #root .ov-chrome,
html body.page-invoice-view #root .ov-chrome *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path) {
    font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
}
html body.page-invoice-view .ov-document-wrap,
html body.page-invoice-view .ov-document-wrap *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path),
html body.page-invoice-view #catalog-content,
html body.page-invoice-view #catalog-content *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi):not(svg):not(path) {
    font-family: var(--ov-doc-font-stack, inherit) !important;
}
</style>
<style id="ov-invoice-print-fix">
@media print {
    html body.page-invoice-view .sidebar,
    html body.page-invoice-view .sidebar-container,
    html body.page-invoice-view #sidebar,
    html body.page-invoice-view .employee-header,
    html body.page-invoice-view .header,
    html body.page-invoice-view .mobile-footer,
    html body.page-invoice-view .ov-no-print,
    html body.page-invoice-view .no-print {
        display: none !important;
    }
    html body.page-invoice-view main.main-content > #root,
    html body.page-invoice-view #root,
    html body.page-invoice-view .ov-page-root,
    html body.page-invoice-view .ov-document-wrap,
    html body.page-invoice-view .ov-document-wrap #invoice-content,
    html body.page-invoice-view .spare-sheet,
    html body.page-invoice-view .truck-sheet,
    html body.page-invoice-view .truck-sheet-second {
        display: block !important;
        visibility: visible !important;
        overflow: visible !important;
        height: auto !important;
        max-height: none !important;
        width: 100% !important;
        max-width: 100% !important;
    }
}
</style>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
