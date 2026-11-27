<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/includes/orders-view-lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$embedMode = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
$docOnly = isset($_GET['doc_only']) && (string) $_GET['doc_only'] === '1';
$autoBrowserPrint = isset($_GET['autoprint']) && (string) $_GET['autoprint'] === '1';
// View in browser by default. Force-save only with autodownload=1 (or download=pdf/force).
$downloadParam = isset($_GET['download']) ? strtolower(trim((string) $_GET['download'])) : '';
$auto_print = !$embedMode && !$autoBrowserPrint && (
    isset($_GET['autodownload'])
    || in_array($downloadParam, ['pdf', 'force'], true)
);

if ($id <= 0) {
    http_response_code(400);
    die('Invalid sales order id.');
}

$salesDb = sales_pdo();

$loaded = salesOrderViewLoadOrder($salesDb, $id);
if ($loaded === null) {
    http_response_code(404);
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        $dbName = '';
        try {
            $dbName = (string) $salesDb->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
            $dbName = '';
        }
        die('Order not found (id=' . $id . ', sales DB=' . htmlspecialchars($dbName) . ').');
    }
    die('Order not found.');
}

$order = $loaded['order'];
$displayOrderNumber = $loaded['display_order_number'];
$signatureUrl = $loaded['signature_url'];
$items = salesOrderViewLoadItems($salesDb, $id);
$company_settings = salesOrderViewLoadCompanySettings($salesDb);
$isTruckOrder = salesOrderViewDetectTruckOrder($order, $items);
if ($isTruckOrder) {
    $order['order_type'] = 'truck';
}

$linkedInvoice = function_exists('salesOrderViewFindLinkedInvoice')
    ? salesOrderViewFindLinkedInvoice($salesDb, $id)
    : null;
$linkedInvoiceId = (int) ($linkedInvoice['id'] ?? 0);
$linkedInvoiceNumber = trim((string) ($linkedInvoice['invoice_number'] ?? ''));
$documentDisplayNumber = ($linkedInvoiceId > 0 && $linkedInvoiceNumber !== '')
    ? $linkedInvoiceNumber
    : $displayOrderNumber;

$orderStatusKey = strtolower(trim((string) ($order['status'] ?? '')));
$hasLinkedInvoice = $linkedInvoiceId > 0;
if (function_exists('sales_order_document_title_label')) {
    $doc_title = sales_order_document_title_label($orderStatusKey, $hasLinkedInvoice);
} else {
    $doc_title = in_array($orderStatusKey, ['draft', 'quotation', 'sent'], true) ? 'Quotation' : 'Sales Order';
}
$is_quote = ($doc_title === 'Quotation');
$filename_prefix = $is_quote ? 'Quotation_' : ($doc_title === 'Invoice' ? 'Invoice_' : 'Order_');

$linkedInvoiceStatus = strtolower(trim((string) ($linkedInvoice['status'] ?? '')));
$linkedBalanceDue = (float) ($linkedInvoice['balance_due'] ?? 0);
$documentIsPaid = $orderStatusKey === 'paid'
    || $linkedInvoiceStatus === 'paid'
    || ($hasLinkedInvoice && $linkedBalanceDue <= 0.009);

$documentHtml = salesOrderViewRenderDocumentHtml(
    $order,
    $items,
    $company_settings,
    $documentDisplayNumber,
    $isTruckOrder,
    $signatureUrl,
    $hasLinkedInvoice,
    $documentIsPaid
);

$showCatalogue = !$docOnly && (
    !empty($company_settings['include_catalogue'])
    || (int) ($company_settings['include_product_catalogue'] ?? 0) === 1
);
$catalogHtml = ($showCatalogue && !empty($items))
    ? salesOrderViewRenderCatalogHtml($order, $items, $company_settings, $displayOrderNumber)
    : '';

$fontLinks = function_exists('sales_document_font_stylesheet_links')
    ? sales_document_font_stylesheet_links($company_settings)
    : '';
$fontFamily = function_exists('sales_document_font_family_css')
    ? sales_document_font_family_css($company_settings)
    : "'Arima', Arial, sans-serif";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($doc_title . ' ' . $displayOrderNumber); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php echo $fontLinks; ?>
    <style>
        html, body {
            background: #525659;
            margin: 0;
            padding: 0;
            font-family: <?php echo $fontFamily; ?>;
            font-size: 8.75pt;
        }
        #pdf-content {
            max-width: 210mm;
            margin: 0 auto;
            width: 100%;
            box-sizing: border-box;
        }
        /* Outer page only — nested .sheet must stay 100% or content overflows and clips. */
        .sheet-container,
        .doc-sheet,
        .spare-sheet {
            width: 210mm;
            max-width: 100%;
            min-height: 270mm;
            padding: 10mm 15mm;
            margin: 10mm auto;
            background: white;
            box-shadow: 0 0 0.5cm rgba(0,0,0,0.1);
            position: relative;
            page-break-after: always;
            box-sizing: border-box;
            overflow: visible;
        }
        .sheet-container > .sheet,
        .doc-sheet > .sheet {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
            box-sizing: border-box !important;
        }
        .sheet-container:last-child,
        .doc-sheet:last-child,
        .spare-sheet:last-child {
            page-break-after: auto;
        }
        body.pdf-mode { background: white; }
        body.pdf-mode .sheet-container,
        body.pdf-mode .doc-sheet,
        body.pdf-mode .spare-sheet {
            box-shadow: none;
            margin: 0;
            border: none;
            width: 100% !important;
            max-width: 100% !important;
            min-height: auto !important;
            padding-top: 5mm !important;
            padding-bottom: 5mm !important;
            overflow: visible !important;
        }
        body.pdf-mode .no-print,
        body.pdf-mode #downloadBtn { display: none; }
        tr { page-break-inside: avoid; }
        .o-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .o-table th {
            border-bottom: 2px solid #000 !important;
            text-transform: uppercase;
            font-weight: 400;
            padding: 8px 5px;
            vertical-align: middle;
            word-break: break-word;
            overflow-wrap: anywhere;
            background-color: #ffd700 !important;
            background: #ffd700 !important;
            color: #000 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        .o-table td {
            padding: 8px 5px;
            border-bottom: 1px solid #eee !important;
            vertical-align: middle;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .o-table .num,
        .o-table th.num {
            text-align: right;
            white-space: nowrap;
            overflow: visible;
        }
        .o-totals-table,
        .totals-table {
            width: auto !important;
            min-width: 300px;
            max-width: 100%;
        }
        .o-totals-table td:last-child,
        .totals-table td:last-child {
            white-space: nowrap;
            overflow: visible;
            text-align: right;
        }
        .spare-table th,
        .truck-table th {
            /* Keep company layout theme colors (do not force gold). */
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        .quot-sheet-header {
            gap: 12px;
            min-width: 0;
        }
        .quot-sheet-header .sheet-title {
            flex: 1 1 auto;
            min-width: 0;
            padding-right: 8px;
        }
        .quot-company-block {
            flex: 0 1 48%;
            max-width: 48%;
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .quot-company-block img {
            max-width: 100%;
            height: auto;
        }
        .invoice-paid-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-32deg);
            z-index: 6;
            pointer-events: none;
            user-select: none;
        }
        .invoice-paid-watermark span {
            display: inline-block;
            font-size: 5.5rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            line-height: 1;
            color: rgba(21, 128, 61, 0.2);
            text-transform: uppercase;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        /* Embed / iframe preview: fit page to iframe width (no transform clipping). */
        body.embed-mode {
            overflow-x: hidden;
            overflow-y: auto;
            padding: 12px 8px 24px;
            box-sizing: border-box;
        }
        body.embed-mode #pdf-content {
            max-width: 100%;
            width: 100%;
        }
        body.embed-mode .sheet-container,
        body.embed-mode .doc-sheet,
        body.embed-mode .spare-sheet {
            width: 100% !important;
            max-width: 210mm !important;
            margin: 0 auto 12px !important;
            padding: 8mm !important;
        }
        @media print {
            body { background: white; }
            .sheet-container,
            .doc-sheet,
            .spare-sheet { width: 100%; max-width: 100%; margin: 0; box-shadow: none; padding: 0; }
            .no-print { display: none; }
            .o-table th,
            .spare-table th,
            .truck-table th {
                border-bottom: 2px solid #000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            .o-table th {
                background-color: #ffd700 !important;
                background: #ffd700 !important;
                color: #000 !important;
            }
            .o-table td {
                border-bottom: 1px solid #eee !important;
            }
        }
    </style>
</head>
<body class="<?php echo $embedMode ? 'embed-mode' : ''; ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        function notifySalesDocPdfStart() {
            var payload = { type: 'sales-doc-pdf-start' };
            try {
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage(payload, window.location.origin);
                }
            } catch (e) {}
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage(payload, window.location.origin);
                }
            } catch (e) {}
        }

        function notifySalesDocPdf(ok, error) {
            var payload = { type: 'sales-doc-pdf', ok: !!ok, error: error || '' };
            try {
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage(payload, window.location.origin);
                }
            } catch (e) {}
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage(payload, window.location.origin);
                }
            } catch (e) {}
        }

        function downloadPDF() {
            if (typeof html2pdf === 'undefined') {
                notifySalesDocPdf(false, 'PDF library failed to load.');
                return;
            }
            notifySalesDocPdfStart();
            document.body.classList.add('pdf-mode');

            const element = document.getElementById('pdf-content');
            if (!element) {
                notifySalesDocPdf(false, 'Document content not found.');
                return;
            }
            
            const opt = {
                margin: [0, 0, 0, 0],
                filename: <?php echo json_encode($filename_prefix . $displayOrderNumber . '.pdf'); ?>,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, scrollX: 0, scrollY: 0, windowWidth: 900 },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: { mode: ['css', 'legacy'] }
            };

            const btn = document.getElementById('downloadBtn');
            if (btn) btn.style.display = 'none';

            html2pdf().set(opt).from(element).save().then(function () {
                document.body.classList.remove('pdf-mode');
                if (btn) btn.style.display = 'block';
                notifySalesDocPdf(true, '');
            }).catch(function (err) {
                document.body.classList.remove('pdf-mode');
                if (btn) btn.style.display = 'block';
                notifySalesDocPdf(false, (err && err.message) ? err.message : 'PDF generation failed.');
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            <?php if ($auto_print): ?>
            downloadPDF();
            <?php elseif ($autoBrowserPrint): ?>
            window.setTimeout(function () {
                try { window.focus(); window.print(); } catch (e) {}
            }, 250);
            <?php endif; ?>
        });
    </script>
    
    <?php if (!$auto_print && !$autoBrowserPrint && empty($embedMode)): ?>
    <button id="downloadBtn" class="no-print" type="button" onclick="downloadPDF()" style="
        position: fixed; 
        top: 20px; 
        right: 20px; 
        z-index: 9999; 
        background: #008784; 
        color: #fff;
        border: none; 
        border-radius: 8px;
        padding: 10px 16px;
        font-weight: 600;
        cursor: pointer; 
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    ">
        <i class="fas fa-download"></i> Download PDF
    </button>
    <?php endif; ?>

    <div id="pdf-content">
        <?php echo $documentHtml; ?>
        <?php echo $catalogHtml; ?>
    </div>
</body>
</html>
