<?php
// truck-invoice-layout-inner.php
// Branded Truck Layout matching the Spare Part Layout 3 visual style
// Expected variables: $invoice, $items, $company_settings, $currency, $is_print, $logoUrl
// $document_footer_closing: if false, hide shared closing line (e.g. non-last PDF page). Default true.

if (!isset($document_footer_closing)) {
    $document_footer_closing = true;
}

$layout = $company_settings['truck_layout'] ?? 1;
// layout 1 is now the high-fidelity Watermark Mode matching Spare Part Layout 3
$headerBg = ($layout == 1) ? '#003366' : '#dbd7d2'; 
$headerColor = ($layout == 1) ? '#fff' : '#000';

// Visual definitions
$tableBorder = 'none';
$thBorder = 'none';
$tdBorder = 'none';
$tdVerticalBorder = 'none';

$isRoadmaster = isRoadmaster();
$brandNavy = '#0D2A4A';
$brandYellow = '#000000';

// Sales orders are passed as $invoice from modules/sales/orders/view.php — align keys with invoice rows.
if (!isset($invoice['invoice_number']) && isset($invoice['order_number'])) {
    $invoice['invoice_number'] = $invoice['order_number'];
}
if (!isset($invoice['invoice_date']) || $invoice['invoice_date'] === '' || $invoice['invoice_date'] === null) {
    $rawDate = $invoice['quote_date'] ?? $invoice['order_date'] ?? $invoice['created_at'] ?? null;
    $invoice['invoice_date'] = ($rawDate !== null && (string) $rawDate !== '') ? $rawDate : date('Y-m-d');
}
if (!array_key_exists('tax_amount', $invoice) || $invoice['tax_amount'] === null || $invoice['tax_amount'] === '') {
    $invoice['tax_amount'] = 0.0;
}
if (!isset($currency) || $currency === '') {
    $currency = $invoice['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
}
if (!isset($logoUrl) || $logoUrl === '') {
    $brandingLogo = function_exists('getCompanySetting') ? trim((string)getCompanySetting('company_logo', '')) : '';
    if ($brandingLogo !== '') {
        $logoUrl = app_url('/' . ltrim($brandingLogo, '/'));
    } else {
        $logoFile = $company_settings['company_logo'] ?? 'Untitled.jpg';
        $logoUrl = function_exists('app_url') ? app_url('/assets/images/' . ltrim((string) $logoFile, '/')) : ('/assets/images/' . ltrim((string) $logoFile, '/'));
    }
}

if (!isset($signatureUrl) || $signatureUrl === '') {
    $signatureUrl = function_exists('sales_resolve_document_signature_url')
        ? sales_resolve_document_signature_url($invoice, function_exists('sales_pdo') ? sales_pdo() : null)
        : '';
}

$invoiceDateTs = strtotime((string) $invoice['invoice_date']);
if ($invoiceDateTs === false) {
    $invoiceDateTs = time();
}

if (!empty($invoice['due_date'])) {
    $invoiceDueDate = $invoice['due_date'];
} elseif (!empty($invoice['valid_until'])) {
    $invoiceDueDate = $invoice['valid_until'];
} else {
    $rawDue = $invoice['quote_date'] ?? $invoice['order_date'] ?? $invoice['created_at'] ?? null;
    $invoiceDueDate = ($rawDue !== null && $rawDue !== '') ? date('Y-m-d', strtotime($rawDue . ' + 30 days')) : null;
}
$invoiceDueDateTs = strtotime((string) $invoiceDueDate);
if ($invoiceDueDateTs === false) {
    $invoiceDueDateTs = $invoiceDateTs;
}

$roadmasterTruckItemImgSrc = static function (array $item): string {
    if (!empty($item['it_img_base64'])) {
        return (string) $item['it_img_base64'];
    }
    $pid = (int) ($item['product_id'] ?? 0);
    $img = $item['main_image'] ?? $item['image'] ?? '';
    if ($pid <= 0) {
        return '';
    }
    if (function_exists('sales_order_item_image_url')) {
        return sales_order_item_image_url($item, 'medium');
    }
    if (function_exists('sales_product_image_url')) {
        return sales_product_image_url($pid, (string) $img, 'medium');
    }
    $path = '/stock/product_image.php?product_id=' . $pid . '&size=medium&file=' . rawurlencode(basename((string) $img));
    return function_exists('app_url') ? app_url($path) : $path;
};

?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Roboto:wght@400;700&display=swap');

    .truck-sheet {
        background: white;
        padding: 40px;
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        position: relative;
        font-family: 'Montserrat', sans-serif;
        color: #000;
        box-sizing: border-box;
        border-top: none;
    }

    .pdf-mode .truck-sheet {
        width: 100% !important;
        padding: 10mm 15mm !important;
        min-height: auto !important;
    }

    .truck-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .truck-header-left {
        width: 60%;
    }

    .truck-logo-container {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        margin-bottom: 10px;
    }

    .truck-logo-img {
        max-height: 55px;
        margin-bottom: 2px;
    }

    .truck-company-name-main {
        font-size: 32px;
        font-weight: 800;
        color: <?= $isRoadmaster ? $brandNavy : '#0d2a4a' ?>;
        line-height: 1;
        margin: 0;
        letter-spacing: -0.5px;
    }


    .truck-company-name-sub {
        font-size: 20px;
        font-weight: 700;
        color: <?= $isRoadmaster ? $brandNavy : '#0d2a4a' ?>;
        margin-top: 2px;
        line-height: 1;
    }


    .truck-tagline {
        font-size: 9px;
        font-weight: 700;
        color: #666;
        text-transform: uppercase;
        margin-top: 4px;
        letter-spacing: 0.5px;
    }

    .truck-address-block {
        font-size: 11px;
        color: #000;
        margin-top: 15px;
        line-height: 1.4;
    }

    .truck-header-right {
        width: 35%;
        text-align: right;
    }

    .truck-doc-title {
        font-size: 28px;
        font-weight: 800;
        margin-bottom: 20px;
        text-transform: uppercase;
        color: #000;
    }

    .truck-meta-row {
        display: flex;
        justify-content: flex-end;
        font-size: 13px;
        margin-bottom: 5px;
        align-items: baseline;
    }

    .truck-meta-label {
        font-weight: 400;
        margin-right: 10px;
        width: 110px;
        text-align: right;
    }

    .truck-meta-value {
        font-weight: 700;
        min-width: 150px;
        text-align: left;
    }

    .truck-bill-bar {
        border-top: 2px solid <?= $isRoadmaster ? $brandYellow : '#eee' ?>;
        border-bottom: 2px solid <?= $isRoadmaster ? $brandYellow : '#eee' ?>;
        display: flex;
        padding: 10px 0;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 25px;
        align-items: center;
    }


    .truck-bill-to-label {
        margin-right: 10px;
        white-space: nowrap;
    }

    .truck-bill-to-value {
        width: 40%;
        margin-right: 20px;
    }

    .truck-tin-vrn {
        display: flex;
        gap: 30px;
        flex: 1;
    }

    .truck-table {
        width: 100%;
        border-collapse: collapse;
        border: <?= $tableBorder ?>;
        display: table !important;
        table-layout: fixed;
    }

    .truck-table thead {
        display: table-header-group !important;
    }

    .truck-table tbody {
        display: table-row-group !important;
    }

    .truck-table tbody tr {
        display: table-row !important;
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .truck-table th,
    .truck-table td {
        display: table-cell !important;
    }

    .truck-table th {
        background-color: <?= $headerBg ?>;
        color: <?= $headerColor ?>;
        border: <?= $thBorder ?>;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 800;
        text-align: center;
        text-transform: uppercase;
    }

    .truck-table td {
        border-right: none;
        border-left: none;
        border-bottom: 1px solid #eee;
        padding: 15px 10px;
        font-size: 13px;
        vertical-align: top;
        height: auto;
    }

    .truck-table td.truck-line-img-cell {
        vertical-align: middle;
    }

    .truck-table .truck-line-img {
        width: 150px;
        max-height: 150px;
        object-fit: contain;
        display: block;
        margin: 0 auto;
        border: 1px solid #e5e5e5;
        box-sizing: border-box;
        background: #fff;
    }

    .truck-table .truck-line-img-placeholder {
        width: 150px;
        height: 150px;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #ddd;
        box-sizing: border-box;
    }

    .truck-table tr:last-child td {
        border-bottom: none;
    }

    .truck-watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        opacity: 0.08;
        width: 400px;
        pointer-events: none;
        z-index: 0;
    }

    .truck-watermark img {
        width: 100%;
        height: auto;
        <?= $isRoadmaster ? 'filter: grayscale(100%) opacity(0.1);' : '' ?>
    }


    .truck-totals-container {
        display: flex;
        justify-content: flex-end;
    }

    .truck-totals-table {
        width: 350px;
        border-collapse: collapse;
        border: none;
        border-top: none;
    }

    .truck-totals-table td {
        padding: 8px 10px;
        font-size: 13px;
        border-right: none;
    }

    .truck-totals-table td:last-child {
        text-align: right;
        font-weight: 700;
        width: 130px;
        border-right: none;
    }

    .row-total {
        border-top: 2px solid <?= $isRoadmaster ? $brandYellow : '#eee' ?>;

        font-weight: 800 !important;
        color: <?= $isRoadmaster ? $brandNavy : '#0d2a4a' ?>;
    }


    .label-col {
        text-align: right;
        text-transform: uppercase;
        font-weight: 600;
    }

    /* Second Page Styles */
    .truck-sheet-second {
        background: white;
        padding: 40px;
        width: 210mm;
        min-height: 297mm;
        margin: 40px auto; /* Added margin for visual separation in browser */
        position: relative;
        font-family: 'Montserrat', sans-serif;
        color: #000;
        box-sizing: border-box;
        page-break-before: always;
        break-before: page;
        border: 1px solid #eee; /* Added border for browser visibility */
    }

    .pdf-mode .truck-sheet-second {
        width: 100% !important;
        padding: 10mm 15mm !important;
        min-height: auto !important;
        margin: 0 !important;
        border: none !important;
    }

    .truck-second-title {
        font-size: 22px;
        font-weight: 800;
        color: #0d2a4a;
        border-bottom: 2px solid #0d2a4a;
        padding-bottom: 10px;
        margin-bottom: 25px;
        text-transform: uppercase;
    }

    .truck-section-box {
        margin-bottom: 30px;
    }

    .truck-section-header {
        font-size: 14px;
        font-weight: 700;
        color: #003366;
        text-transform: uppercase;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .truck-section-header::after {
        content: "";
        flex: 1;
        height: 1px;
        background: #eee;
    }

    .truck-section-content {
        font-size: 12px;
        line-height: 1.8;
        color: #333;
        padding-left: 5px;
    }

    .truck-bottom-info {
        margin-top: 25px;
        font-size: 11px;
    }

    .truck-payment-heading {
        font-weight: 700;
        margin-bottom: 5px;
    }

    .truck-payment-details {
        margin-bottom: 15px;
        line-height: 1.6;
    }

    .truck-terms {
        margin-top: 20px;
        line-height: 1.4;
    }

    .truck-sig-container {
        text-align: right;
        width: 250px;
    }

    .truck-sig-image-wrapper {
        margin-bottom: 5px;
        min-height: 30px;
    }

    .truck-sig-image-wrapper img {
        max-height: 50px;
        object-fit: contain;
    }

    .truck-thank-you {
        text-align: center;
        margin-top: 30px;
        font-size: 14px;
    }

    .truck-thank-you strong {
        font-size: 16px;
        display: block;
        margin-bottom: 5px;
    }

    /* PDF pagination helpers: keep critical blocks together */
    .avoid-break {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .avoid-break * {
        break-inside: avoid;
        page-break-inside: avoid;
    }
</style>

<div id="invoice-sheet">
<div class="truck-sheet">
    <?php
    if (strpos($invoice['invoice_number'] ?? '', 'INV') !== false) {
        $docType = 'INVOICE';
    } else {
        $docType = 'QUOTATION';
    }
    $pick = static function (array $row, string $primary, string $fallback = ''): string {
        $a = trim((string) ($row[$primary] ?? ''));
        if ($a !== '') {
            return $a;
        }
        if ($fallback !== '') {
            return trim((string) ($row[$fallback] ?? ''));
        }
        return '';
    };

    $truckPay = $pick($company_settings, 'truck_payment_details', 'spare_payment_details');
    $truckTerms = $pick($company_settings, 'truck_terms', 'spare_terms');
    $truckValidity = $pick($company_settings, 'truck_validity', 'spare_validity');
    $truckReturn = $pick($company_settings, 'truck_return_policy', 'spare_return_policy');

    $truckRemarks = trim((string) ($company_settings['truck_remarks'] ?? ''));
    $invoiceRemarksGeneral = trim((string) ($company_settings['invoice_remarks'] ?? ''));
    $remarkParts = [];
    if ($truckRemarks !== '') {
        $remarkParts[] = $truckRemarks;
    }
    if ($invoiceRemarksGeneral !== '') {
        $remarkParts[] = $invoiceRemarksGeneral;
    }
    $invRemarks = implode("\n\n", $remarkParts);

    $bankDetails = trim((string) ($company_settings['bank_details'] ?? ''));
    $thanksSecond = $pick($company_settings, 'truck_thanks_note', 'spare_thanks_note');
    // Page 2: extended terms only (payment + bank appear on page 1)
    // Always show the Terms & remarks page for truck documents (signature lives on page 2).
    $showTruckTermsPage = true;
    ?>


    <div class="truck-header">
        <div class="truck-header-left">
            <div class="truck-logo-container">
                <h1 class="truck-company-name-main">ROADMASTER</h1>
                <div class="truck-company-name-sub">SPARES LIMITED</div>
                <div class="truck-tagline"><?= isRoadmaster() ? 'YOUR TRUCK SPARES PARTNER' : 'PARTS THAT LAST, BACKED BY MASTERS' ?></div>
            </div>
            <div class="truck-address-block">
                <?= nl2br(htmlspecialchars($company_settings['company_address'])) ?><br>
                Phone: <?= htmlspecialchars($company_settings['company_phone']) ?><br>
                Email: <?= htmlspecialchars($company_settings['company_email']) ?>
            </div>
        </div>
        <div class="truck-header-right">
            <div class="truck-doc-title"><?= $docType ?></div>
            <div class="truck-meta-row">
                <span class="truck-meta-label"><?= $docType ?> No.</span>
                <span class="truck-meta-value"><?= htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number'] ?? 'N/A') ?></span>
            </div>
            <div class="truck-meta-row">
                <span class="truck-meta-label">Invoice Date:</span>
                <span class="truck-meta-value"><?= date('d/m/Y', $invoiceDateTs) ?></span>
            </div>
            <div class="truck-meta-row">
                <span class="truck-meta-label">Due Date:</span>
                <span class="truck-meta-value"><?= $invoiceDueDate ? date('d/m/Y', strtotime($invoiceDueDate)) : '-' ?></span>
            </div>
            <div class="truck-meta-row">
                <span class="truck-meta-label">Salesperson:</span>
                <span class="truck-meta-value"><?= htmlspecialchars($invoice['salesperson'] ?? 'System Administrator') ?></span>
            </div>
        </div>
    </div>

    <div class="truck-bill-bar">
        <span class="truck-bill-to-label">BILL TO:</span>
        <span class="truck-bill-to-value"><?= htmlspecialchars($invoice['company_name']) ?></span>
        <div class="truck-tin-vrn">
            <span>TIN: <?= htmlspecialchars($invoice['tin'] ?? '----') ?></span>
            <span>VRN: <?= htmlspecialchars($invoice['vrn'] ?? '') ?></span>
        </div>
    </div>

    <table class="truck-table">
        <thead>
            <tr>
                <th style="width: 180px;">IMAGE</th>
                <th>DESCRIPTION</th>
                <th style="width: 60px;">QTY</th>
                <th style="width: 120px;">UNIT PRICE (INC)</th>
                <th style="width: 130px;">TOTAL (INC)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $idx => $it):
                $truckLineImg = $roadmasterTruckItemImgSrc($it);
                ?>
                <tr>
                    <td class="truck-line-img-cell" style="text-align: center;">
                        <?php if ($truckLineImg !== ''): ?>
                            <img class="truck-line-img" src="<?= htmlspecialchars($truckLineImg, ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars((string) ($it['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <div class="truck-line-img-placeholder" style="display: none;" role="presentation"></div>
                        <?php else: ?>
                            <div class="truck-line-img-placeholder" role="presentation"></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight: 700;"><?= htmlspecialchars($it['product_name'] ?? 'Truck') ?></div>
                        <div style="font-size: 11px; color: #333; margin-top: 4px; line-height: 1.4;">
                            <?= nl2br(htmlspecialchars($it['product_description'] ?? $it['notes'] ?? '')) ?>
                        </div>
                    </td>
                    <td style="text-align: center;"><?= number_format($it['quantity'], 0) ?></td>
                    <td style="text-align: right;"><?= number_format($it['unit_price'], 2) ?></td>
                    <td style="text-align: right;"><?= number_format($it['line_total'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="truck-totals-container avoid-break">
        <table class="truck-totals-table">
            <tr>
                <td class="label-col">Untaxed Amount:</td>
                <td><?= $currency ?> <?= number_format($invoice['total_amount'] - ($invoice['tax_amount'] ?? 0), 2) ?></td>
            </tr>
            <tr>
                <td class="label-col">Taxes:</td>
                <td><?= $currency ?> <?= number_format($invoice['tax_amount'] ?? 0, 2) ?></td>
            </tr>
            <tr class="row-total">
                <td class="label-col">Total:</td>
                <td><?= $currency ?> <?= number_format($invoice['total_amount'], 2) ?></td>
            </tr>
        </table>
    </div>

    <?php if ($truckPay !== '' || $bankDetails !== ''): ?>
    <div class="truck-page1-payment avoid-break" style="margin-top: 28px;">
        <?php if ($truckPay !== ''): ?>
        <div class="truck-section-box" style="margin-bottom: 20px;">
            <div class="truck-section-header">Payment instructions</div>
            <div class="truck-section-content"><?= nl2br(htmlspecialchars($truckPay)) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($bankDetails !== ''): ?>
        <div class="truck-section-box" style="margin-bottom: 0;">
            <div class="truck-section-header">Bank / payment details</div>
            <div class="truck-section-content"><?= nl2br(htmlspecialchars($bankDetails)) ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($showTruckTermsPage)): ?>
    <div class="truck-continued-hint" style="margin-top: 40px; text-align: center; color: #777; font-size: 10px;">
        Continued on next page — terms, remarks &amp; signature
    </div>
    <?php elseif (!empty($document_footer_closing)): ?>
    <?php include __DIR__ . '/../../includes/document-footer-message.php'; ?>

    <?php endif; ?>
</div>

<?php if (!empty($showTruckTermsPage)): ?>
<div class="html2pdf__page-break"></div>

<!-- Second page: terms, remarks, thanks (payment details are on page 1) -->
<div class="truck-sheet-second">
    <div class="truck-header">
        <div class="truck-header-left">
            <div class="truck-logo-container">
                <h1 class="truck-company-name-main">ROADMASTER</h1>
                <div class="truck-company-name-sub">SPARES LIMITED</div>
            </div>
        </div>
        <div class="truck-header-right">
            <div style="font-size: 14px; font-weight: 700; color: #666;">
                <?= $docType ?>: <?= htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number'] ?? 'N/A') ?>
            </div>
            <div style="font-size: 11px; color: #999;">Terms &amp; remarks</div>
        </div>
    </div>

    <div class="truck-second-title">Terms &amp; remarks</div>

    <?php if ($truckTerms !== ''): ?>
    <div class="truck-section-box">
        <div class="truck-section-header">Terms &amp; conditions</div>
        <div class="truck-section-content"><?= nl2br(htmlspecialchars($truckTerms)) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($truckValidity !== ''): ?>
    <div class="truck-section-box">
        <div class="truck-section-header">Quote / document validity</div>
        <div class="truck-section-content"><?= nl2br(htmlspecialchars($truckValidity)) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($invRemarks !== ''): ?>
    <div class="truck-section-box">
        <div class="truck-section-header">Remarks</div>
        <div class="truck-section-content"><?= nl2br(htmlspecialchars($invRemarks)) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($truckReturn !== ''): ?>
    <div class="truck-section-box">
        <div class="truck-section-header">Return policy</div>
        <div class="truck-section-content"><?= nl2br(htmlspecialchars($truckReturn)) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($thanksSecond !== ''): ?>
    <div class="truck-section-box">
        <div class="truck-section-header">Thank you</div>
        <div class="truck-section-content"><?= nl2br(htmlspecialchars($thanksSecond)) ?></div>
    </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: flex-end; margin-top: 40px;">
        <div class="truck-sig-container">
            <div class="truck-sig-image-wrapper">
                <?php if (!empty($signatureUrl)): ?>
                    <img src="<?= htmlspecialchars($signatureUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Signature">
                <?php endif; ?>
            </div>
            <div style="border-top: 1px solid #eee; padding-top: 5px; font-weight: 700; font-size: 12px; text-transform: uppercase;">
                Authorized Signature
            </div>
        </div>
    </div>

    <?php if (!empty($document_footer_closing)) { include __DIR__ . '/../../includes/document-footer-message.php'; } ?>

</div>
<?php endif; ?>

</div><!-- #invoice-sheet -->
