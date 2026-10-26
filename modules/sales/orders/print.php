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
// View in browser by default. Force-save only with autodownload=1 (or download=pdf/force).
$downloadParam = isset($_GET['download']) ? strtolower(trim((string) $_GET['download'])) : '';
$auto_print = !$embedMode && (
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

$documentHtml = salesOrderViewRenderDocumentHtml(
    $order,
    $items,
    $company_settings,
    $documentDisplayNumber,
    $isTruckOrder,
    $signatureUrl,
    $hasLinkedInvoice
);

$showCatalogue = !empty($company_settings['include_catalogue'])
    || (int) ($company_settings['include_product_catalogue'] ?? 0) === 1;
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
        }
        .sheet-container,
        .sheet,
        .doc-sheet {
            width: 210mm;
            min-height: 270mm;
            padding: 10mm 15mm;
            margin: 10mm auto;
            background: white;
            box-shadow: 0 0 0.5cm rgba(0,0,0,0.1);
            position: relative;
            page-break-after: always;
            box-sizing: border-box;
        }
        .sheet-container:last-child,
        .sheet:last-child,
        .doc-sheet:last-child {
            page-break-after: auto;
        }
        body.pdf-mode { background: white; }
        body.pdf-mode .sheet-container,
        body.pdf-mode .sheet,
        body.pdf-mode .doc-sheet {
            box-shadow: none;
            margin: 0;
            border: none;
            width: 100% !important;
            min-height: auto !important;
            padding-top: 5mm !important;
            padding-bottom: 5mm !important;
        }
        body.pdf-mode .no-print,
        body.pdf-mode #downloadBtn { display: none; }
        tr { page-break-inside: avoid; }
        @media print {
            body { background: white; }
            .sheet-container, .sheet, .doc-sheet { width: 100%; margin: 0; box-shadow: none; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
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
                html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
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
            <?php endif; ?>
        });
    </script>

    <?php if (!$auto_print && empty($embedMode)): ?>
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
