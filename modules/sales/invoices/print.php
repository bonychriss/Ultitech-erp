<?php
/**
 * Invoice print desk — uses each company's chosen document layout
 * (same resolver as invoice view / quotations / orders).
 */
@ini_set('display_errors', '0');
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/includes/invoices-view-lib.php';
require_once __DIR__ . '/../orders/includes/orders-view-lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$embedMode = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
$invoiceOnly = isset($_GET['invoice_only']) && (string) $_GET['invoice_only'] === '1';
$autoBrowserPrint = isset($_GET['autoprint']) && (string) $_GET['autoprint'] === '1';
$downloadParam = isset($_GET['download']) ? strtolower(trim((string) $_GET['download'])) : '';
$auto_print = !$embedMode && !$autoBrowserPrint && (
    isset($_GET['autodownload'])
    || isset($_GET['download'])
    || in_array($downloadParam, ['pdf', 'force', '1'], true)
);

if ($id <= 0) {
    http_response_code(400);
    die('Invalid invoice id.');
}

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
if (!($salesDb instanceof PDO)) {
    http_response_code(500);
    die('Sales database connection is not available.');
}

try {
    if (function_exists('ensureCustomerColumnsExist')) {
        ensureCustomerColumnsExist();
    }
} catch (Throwable $e) {
    error_log('invoice print ensureCustomerColumnsExist: ' . $e->getMessage());
}

$loaded = salesInvoiceViewLoadInvoice($salesDb, $id);
if ($loaded === null) {
    http_response_code(404);
    die('Invoice not found.');
}

$invoice = $loaded['invoice'];
$displayInvoiceNumber = $loaded['display_invoice_number'];
$signatureUrl = $loaded['signature_url'];

$orderId = (int) ($invoice['order_id'] ?? 0);
$items = $orderId > 0 ? salesOrderViewLoadItems($salesDb, $orderId) : [];

$company_settings = salesOrderViewLoadCompanySettings($salesDb);
$isTruckOrder = salesInvoiceViewDetectTruckOrder($invoice, $items);
if ($isTruckOrder) {
    $invoice['order_type'] = 'truck';
}

if ($invoiceOnly) {
    $company_settings['include_catalogue'] = 0;
    $company_settings['include_product_catalogue'] = 0;
}

$invoiceDualCurrencyCtx = null;
if ($isTruckOrder && function_exists('isRoadmaster') && isRoadmaster() && function_exists('sales_invoice_dual_currency_context')) {
    $invoiceDualCurrencyCtx = sales_invoice_dual_currency_context($invoice, $items, true);
}

$brandingLogoUrl = salesInvoiceViewResolveBrandingLogoUrl($company_settings);
$documentHtml = salesInvoiceViewRenderDocumentHtml(
    $invoice,
    $items,
    $company_settings,
    $isTruckOrder,
    $signatureUrl,
    $invoiceDualCurrencyCtx,
    $brandingLogoUrl
);

$showCatalogue = !$invoiceOnly && (
    !empty($company_settings['include_catalogue'])
    || (int) ($company_settings['include_product_catalogue'] ?? 0) === 1
);
$catalogHtml = ($showCatalogue && !empty($items))
    ? salesInvoiceViewRenderCatalogHtml($invoice, $items, $company_settings, $displayInvoiceNumber, $brandingLogoUrl)
    : '';

$fontLinks = function_exists('sales_document_font_stylesheet_links')
    ? sales_document_font_stylesheet_links($company_settings)
    : '';
$fontFamily = function_exists('sales_document_font_family_css')
    ? sales_document_font_family_css($company_settings)
    : "'Arima', Arial, sans-serif";
$filename = 'Invoice_' . preg_replace('/[^\w.\-]+/', '_', $displayInvoiceNumber) . '.pdf';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars('Invoice ' . $displayInvoiceNumber); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php echo $fontLinks; ?>
    <style>
        body {
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
        .quot-company-block {
            flex: 0 1 48%;
            max-width: 48%;
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
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
                filename: <?php echo json_encode($filename); ?>,
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
