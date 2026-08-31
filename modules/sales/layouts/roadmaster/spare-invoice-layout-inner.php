<?php
// spare-invoice-layout-inner.php
// High-fidelity redesign for Roadmaster Spares Limited
// Matches the provided reference image layout
// Expected variables: $invoice, $items, $company_settings, $currency, $is_print, $logoUrl, $signatureUrl
// $document_footer_closing: if false, hide shared closing line (e.g. non-last PDF page). Default true.

if (!isset($document_footer_closing)) {
    $document_footer_closing = true;
}

$layout = $company_settings['spare_part_layout'] ?? 1;
$headerBg = ($layout == 2 || $layout == 1) ? '#dbd7d2' : '#003366'; // Stone for Layout 1 & 2, Dark Blue for Layout 3
$headerColor = ($layout == 3) ? '#fff' : '#000'; // White text for Layout 3, Black for others

// Border definitions: Layout 1 is borderless (white/faint), others are boxed
$tableBorder = ($layout == 1) ? 'none' : '2px solid #000';
$thBorder = ($layout == 1) ? 'none' : '2px solid #000';
$tdBorder = ($layout == 1) ? 'none' : '1px solid #000';
$tdVerticalBorder = ($layout == 1) ? 'none' : '2px solid #000';
// Visible line between each product row (layout 2/3 had none before; layout 1 was too faint on white/PDF).
$rowSeparator = ($layout == 1) ? '1px solid #9e9e9e' : '1px solid #000';

$isRoadmaster = isRoadmaster();
$brandNavy = '#0D2A4A';
$brandYellow = '#000000';

$salesDocumentBrand = $salesDocumentBrand ?? null;
$docBrand = function_exists('sales_document_brand_profile')
    ? sales_document_brand_profile($company_settings, $salesDocumentBrand)
    : [
        'brand' => 'roadmaster',
        'main' => 'ROADMASTER',
        'sub' => 'SPARES LIMITED',
        'tagline' => 'YOUR TRUCK SPARES PARTNER',
        'navy' => $brandNavy,
        'accent' => $brandYellow,
        'grayscale_watermark' => true,
    ];
$isRoadmasterBrand = ($docBrand['brand'] ?? '') === 'roadmaster';
$docBrandNavy = (string) ($docBrand['navy'] ?? $brandNavy);
$docBrandAccent = (string) ($docBrand['accent'] ?? $brandYellow);

if (!$isRoadmasterBrand && (int) $layout === 3) {
    $headerBg = '#008784';
    $headerColor = '#fff';
}

$spareDualMoney = null;
$spareDualCurrencies = [];
if (isset($invoiceDualCurrencyCtx) && is_array($invoiceDualCurrencyCtx) && is_callable($invoiceDualCurrencyCtx['render'] ?? null)) {
    $spareDualMoney = $invoiceDualCurrencyCtx['render'];
    $spareDualCurrencies = $invoiceDualCurrencyCtx['display_currencies'];
}

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

/** Product thumbnail for line items: base64 if present, else products upload path (same as order catalogue). */
$roadmasterSpareItemImgSrc = static function (array $item): string {
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

$docFontStack = function_exists('sales_document_font_family_css')
    ? sales_document_font_family_css($company_settings ?? [])
    : "'Montserrat', sans-serif";
$docFontImport = function_exists('sales_document_font_import_css')
    ? sales_document_font_import_css($company_settings ?? [])
    : "@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Roboto:wght@400;700&display=swap');";

?>
<style>
    <?= $docFontImport ?>

    .spare-sheet {
        background: white;
        padding: 40px;
        width: 210mm;
        min-height: auto;
        margin: 0 auto;
        position: relative;
        font-family: <?= $docFontStack ?>;
        color: #000;
        box-sizing: border-box;
        border-top: none;
    }

    @media screen {
        .spare-sheet {
            min-height: 297mm;
        }
    }


    .pdf-mode .spare-sheet {
        width: 100% !important;
        padding: 10mm 15mm !important;
        min-height: auto !important;
    }

    @media print {
        .spare-sheet {
            min-height: auto !important;
            width: 210mm !important;
            max-width: 210mm !important;
            margin: 0 auto !important;
            padding: 8mm 10mm !important;
            font-size: 11pt !important;
        }
        .spare-company-name-main { font-size: 26pt !important; }
        .spare-company-name-sub { font-size: 16pt !important; }
        .spare-doc-title { font-size: 22pt !important; }
        .spare-address-block,
        .spare-meta-row,
        .spare-bill-bar,
        .spare-table th,
        .spare-table td,
        .spare-totals-table td,
        .spare-bottom-info,
        .spare-payment-details,
        .spare-terms { font-size: 10pt !important; }
    }

    body.pdf-mode .spare-sheet {
        font-size: 11pt !important;
    }
    body.pdf-mode .spare-table th,
    body.pdf-mode .spare-table td,
    body.pdf-mode .spare-address-block,
    body.pdf-mode .spare-meta-row { font-size: 10pt !important; }

    .spare-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    /* Left Side: Logo and Company Info */
    .spare-header-left {
        width: 60%;
    }

    .spare-logo-container {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        margin-bottom: 10px;
    }

    .spare-logo-img {
        max-height: 55px;
        margin-bottom: 2px;
    }

    .spare-company-name-main {
        font-size: 32px;
        font-weight: 800;
        color: <?= $docBrandNavy ?>;
        /* Navy Blue */
        line-height: 1;
        margin: 0;
        letter-spacing: -0.5px;
    }


    .spare-company-name-sub {
        font-size: 20px;
        font-weight: 700;
        color: #0d2a4a;
        margin-top: 2px;
        line-height: 1;
    }

    .spare-tagline {
        font-size: 9px;
        font-weight: 700;
        color: #666;
        text-transform: uppercase;
        margin-top: 4px;
        letter-spacing: 0.5px;
    }

    /* Watermark Styles (Layout 3) */
    .spare-watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        opacity: 0.06;
        width: 400px;
        height: 400px;
        pointer-events: none;
        z-index: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .spare-watermark img {
        width: 100%;
        height: auto;
        filter: grayscale(100%);
    }

    .spare-watermark.colored img {
        filter: none;
        opacity: 0.12;
    }

    /* Layout 2 specific adjustments */
    .spare-logo-img.large {
        max-height: 100px;
        margin-bottom: 5px;
    }

    .spare-address-block {
        font-size: 11px;
        color: #000;
        margin-top: 15px;
        line-height: 1.4;
    }

    /* Right Side: Document Title and Meta */
    .spare-header-right {
        width: 35%;
        text-align: right;
    }

    .spare-doc-title {
        font-size: 28px;
        font-weight: 800;
        margin-bottom: 20px;
        text-transform: uppercase;
        color: #000;
    }

    .spare-meta-row {
        display: flex;
        justify-content: flex-end;
        font-size: 13px;
        margin-bottom: 5px;
        align-items: baseline;
    }

    .spare-meta-label {
        font-weight: 400;
        margin-right: 10px;
        width: 110px;
        text-align: right;
    }

    .spare-meta-value {
        font-weight: 700;
        min-width: 150px;
        text-align: left;
    }

    /* Bill To Bar */
    .spare-bill-bar {
        border: 2px solid <?= $docBrandAccent ?>;
        display: flex;
        padding: 5px 10px;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 15px;
        align-items: center;
    }


    .spare-bill-to-label {
        margin-right: 10px;
        white-space: nowrap;
    }

    .spare-bill-to-value {
        width: 40%;
        margin-right: 20px;
    }

    .spare-tin-vrn {
        display: flex;
        gap: 30px;
        flex: 1;
    }

    /* Gold Table */
    .spare-table {
        width: 100%;
        border-collapse: collapse;
        border: <?= $tableBorder ?>;
        margin-bottom: 0px;
        display: table !important;
        table-layout: fixed;
    }

    .spare-table thead {
        display: table-header-group !important;
    }

    .spare-table tbody {
        display: table-row-group !important;
    }

    .spare-table tbody tr {
        display: table-row !important;
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .spare-table th {
        background-color: <?= $headerBg ?>;
        /* Gold Header */
        color: <?= $headerColor ?>;
        border: <?= $thBorder ?>;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 800;
        text-align: center;
        text-transform: uppercase;
    }

    .truck-dual-price {
        line-height: 1.35;
        text-align: right;
    }

    .truck-dual-price-line {
        font-size: 13px;
        font-weight: 400;
        color: #000;
    }

    .truck-dual-price-line + .truck-dual-price-line {
        margin-top: 2px;
    }

    .spare-table th,
    .spare-table td {
        display: table-cell !important;
    }

    .spare-table td {
        border-bottom: <?= $rowSeparator ?>;
        border-right: <?= $tdVerticalBorder ?>;
        border-left: <?= $tdVerticalBorder ?>;
        padding: 6px 10px;
        font-size: 13px;
        height: auto;
        min-height: 1.25em;
        vertical-align: middle;
    }

    .spare-table td.spare-line-img-cell {
        width: 95px;
        vertical-align: middle;
    }

    .spare-table .spare-line-img {
        width: 78px;
        height: 78px;
        object-fit: contain;
        background: #fff;
        display: block;
        margin: 0 auto;
        border: 1px solid #e5e5e5;
        box-sizing: border-box;
    }

    .spare-table .spare-line-img-placeholder {
        width: 78px;
        height: 78px;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #ddd;
        box-sizing: border-box;
    }

    .spare-table tr:last-child td {
        border-bottom: <?= ($layout == 1) ? 'none' : '2px solid #000' ?>;
    }

    .t-img {
        width: 60px;
        text-align: center;
    }

    .t-name {}

    .t-part {
        width: 140px;
    }

    .t-qty {
        width: 60px;
        text-align: center;
    }

    .t-price {
        width: 120px;
        text-align: right;
    }

    .t-total {
        width: 130px;
        text-align: right;
    }

    /* Totals */
    .spare-footer-area {
        margin-top: 0;
    }

    .spare-totals-container {
        display: flex;
        justify-content: flex-end;
        align-items: flex-start;
    }

    .spare-totals-table {
        width: 350px;
        border-collapse: collapse;
        border: <?= $tableBorder ?>;
        border-top: none;
    }

    .spare-totals-table td {
        padding: 5px 10px;
        font-size: 13px;
        border-right: <?= $tdVerticalBorder ?>;
    }

    .spare-totals-table td:last-child {
        text-align: right;
        font-weight: 700;
        width: 130px;
        border-right: none;
    }

    .row-total {
        border-top: 2px solid <?= $docBrandAccent ?>;

        font-weight: 800 !important;
        color: <?= $docBrandNavy ?>;
    }


    .spare-label-col {
        text-align: right;
        text-transform: uppercase;
        font-weight: 600;
    }

    /* Bottom Info */
    .spare-bottom-info {
        margin-top: 25px;
        font-size: 11px;
    }

    .spare-payment-heading {
        font-weight: 700;
        margin-bottom: 5px;
        color: #374151;
    }

    .spare-payment-details {
        font-weight: 400;
        margin-bottom: 15px;
        line-height: 1.6;
    }

    .spare-terms {
        margin-top: 20px;
        line-height: 1.4;
    }

    .spare-thank-you {
        text-align: center;
        margin-top: 30px;
        font-size: 14px;
    }

    .spare-thank-you strong {
        font-size: 16px;
        display: block;
        margin-bottom: 5px;
    }

    .spare-sig-container {
        text-align: right;
        width: 250px;
    }

    .spare-sig-image-wrapper {
        margin-bottom: 5px;
        min-height: 50px;
    }

    .spare-sig-image-wrapper img {
        max-height: 60px;
        object-fit: contain;
    }

    .spare-return-policy {
        font-size: 10px;
        color: #444;
        margin-top: 5px;
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

    <?php if ((int) $layout === 2): ?>
    /* Layout 2: totals should not be boxed */
    .spare-totals-table {
        border: none !important;
        border-top: none !important;
        /* Match the last two columns of the main table:
           Unit Price (120px) + Total (130px) = 250px */
        width: 250px !important;
    }
    .spare-totals-table td {
        border: none !important;
    }
    .spare-totals-table td:last-child {
        border-right: none !important;
        width: 130px !important; /* match TOTAL (INC) column */
    }
    .spare-totals-table .row-total {
        border-top: none !important;
    }
    .spare-totals-table tr.row-total td:first-child {
        width: 120px !important; /* match UNIT PRICE (INC) column */
    }
    /* Box only the amount cell (e.g. TZS 200,000.00) */
    .spare-totals-table tr.row-total td:last-child {
        border: 2px solid #000 !important;
        padding: 6px 12px !important;
    }

    /* Layout 2: line totals in the items table should not be bold */
    .spare-table tbody td:last-child {
        font-weight: 400 !important;
    }
    <?php endif; ?>

    <?php if ((int) $layout === 3): ?>
    /* Layout 3: remove totals box, keep box only on amount */
    .spare-totals-table {
        border: none !important;
        border-top: none !important;
    }
    .spare-totals-table td {
        border-right: none !important;
    }
    .spare-totals-table .row-total {
        border-top: none !important;
    }
    .spare-totals-table tr.row-total td:last-child {
        border: 2px solid #000 !important;
        padding: 6px 12px !important;
    }
    <?php endif; ?>

</style>

<div class="spare-sheet">
    <?php
    if (strpos($invoice['invoice_number'] ?? '', 'INV') !== false) {
        $docType = 'INVOICE';
    } elseif (strpos($invoice['invoice_number'] ?? '', 'DN') !== false) {
        $docType = 'DELIVERY NOTE';
    } else {
        $docType = 'QUOTATION';
    }

    if ($layout == 3 || $layout == 1): ?>
        <div class="spare-watermark <?= ($layout == 1) ? 'colored' : '' ?>">
            <img src="<?= $logoUrl ?>" alt="Watermark">
        </div>
    <?php endif; ?>
    <div class="spare-header">
        <div class="spare-header-left">
            <div class="spare-logo-container">
                <?php if ($layout != 3 && $layout != 1): ?>
                    <img src="<?= $logoUrl ?>" class="spare-logo-img <?= ($layout == 2) ? 'large' : '' ?>"
                        alt="Roadmaster Logo">
                <?php endif; ?>
                <?php if ($layout != 2): ?>
                    <h1 class="spare-company-name-main"><?= htmlspecialchars($docBrand['main']) ?></h1>
                    <?php if (!empty($docBrand['sub'])): ?>
                    <div class="spare-company-name-sub"><?= htmlspecialchars($docBrand['sub']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($docBrand['tagline'])): ?>
                    <div class="spare-tagline"><?= htmlspecialchars($docBrand['tagline']) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="spare-address-block">
                <?= nl2br(htmlspecialchars($company_settings['company_address'])) ?><br>
                Phone: <?= htmlspecialchars($company_settings['company_phone']) ?><br>
                Email: <?= htmlspecialchars($company_settings['company_email']) ?>
            </div>
        </div>
        <div class="spare-header-right">
            <div class="spare-doc-title"><?= $docType ?></div>
            <div class="spare-meta-row">
                <span class="spare-meta-label"><?= $docType ?> No.</span>
                <span class="spare-meta-value"><?= htmlspecialchars($invoice['invoice_number']) ?></span>
            </div>
            <div class="spare-meta-row">
                <span class="spare-meta-label">Invoice Date:</span>
                <span class="spare-meta-value"><?= date('d/m/Y', $invoiceDateTs) ?></span>
            </div>
            <div class="spare-meta-row">
                <span class="spare-meta-label">Due Date:</span>
                <span class="spare-meta-value"><?= $invoiceDueDate ? date('d/m/Y', strtotime($invoiceDueDate)) : '-' ?></span>
            </div>
            <div class="spare-meta-row">
                <span class="spare-meta-label">Salesperson:</span>
                <span class="spare-meta-value"><?= htmlspecialchars($invoice['salesperson'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <div class="spare-bill-bar">
        <span class="spare-bill-to-label">BILL TO:</span>
        <span class="spare-bill-to-value"><?= htmlspecialchars($invoice['company_name']) ?></span>
        <div class="spare-tin-vrn">
            <span>TIN: <?= htmlspecialchars($invoice['tin'] ?? '') ?></span>
            <span>VRN: <?= htmlspecialchars($invoice['vrn'] ?? '') ?></span>
        </div>
    </div>

    <table class="spare-table">
        <thead>
            <tr>
                <th style="width: 95px;">IMAGE</th>
                <th>PART NAME</th>
                <th style="width: 140px;">PART NUMBER</th>
                <th style="width: 60px;">QTY</th>
                <th style="width: 120px;">UNIT PRICE (INC)<?php if ($spareDualCurrencies !== []): ?><br><span style="font-size:9px;font-weight:600;"><?= htmlspecialchars(implode(' / ', $spareDualCurrencies)) ?></span><?php endif; ?></th>
                <th style="width: 130px;">TOTAL (INC)<?php if ($spareDualCurrencies !== []): ?><br><span style="font-size:9px;font-weight:600;"><?= htmlspecialchars(implode(' / ', $spareDualCurrencies)) ?></span><?php endif; ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item):
                $lineImgSrc = $roadmasterSpareItemImgSrc($item);
                ?>
                <tr>
                    <td class="spare-line-img-cell" style="text-align: center;">
                        <?php if ($lineImgSrc !== ''): ?>
                            <img class="spare-line-img" src="<?= htmlspecialchars($lineImgSrc, ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars((string) ($item['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <div class="spare-line-img-placeholder" style="display: none;" role="presentation"></div>
                        <?php else: ?>
                            <div class="spare-line-img-placeholder" role="presentation"></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(!empty($item['product_name']) ? $item['product_name'] : (!empty($item['description']) ? $item['description'] : (!empty($item['notes']) ? $item['notes'] : 'Item'))) ?>
                    </td>
                    <td style="text-align: center;">
                        <?= htmlspecialchars(!empty($item['oem_number']) ? $item['oem_number'] : (!empty($item['model_number']) ? $item['model_number'] : 'N/A')) ?></td>
                    <td style="text-align: center;"><?= number_format($item['quantity'], 0) ?></td>
                    <td style="text-align: right;"><?= $spareDualMoney ? ($spareDualMoney)((float) $item['unit_price']) : number_format($item['unit_price'], 2) ?></td>
                    <td style="text-align: right;"><?= $spareDualMoney ? ($spareDualMoney)((float) $item['line_total']) : number_format($item['line_total'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="spare-footer-area avoid-break">
        <div class="spare-totals-container">
            <table class="spare-totals-table">
                <?php if (!in_array((int) $layout, [1, 2, 3], true)): ?>
                    <tr>
                        <td class="spare-label-col">Untaxed Amount:</td>
                        <td><?php if ($spareDualMoney): ?><?= ($spareDualMoney)((float) $invoice['total_amount'] - (float) ($invoice['tax_amount'] ?? 0)) ?><?php else: ?><?= htmlspecialchars((string) $currency) ?> <?= number_format((float) $invoice['total_amount'] - (float) ($invoice['tax_amount'] ?? 0), 2) ?><?php endif; ?></td>
                    </tr>
                    <tr>
                        <td class="spare-label-col">Taxes:</td>
                        <td><?php if ($spareDualMoney): ?><?= ($spareDualMoney)((float) ($invoice['tax_amount'] ?? 0)) ?><?php else: ?><?= htmlspecialchars((string) $currency) ?> <?= number_format((float) ($invoice['tax_amount'] ?? 0), 2) ?><?php endif; ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="row-total">
                    <td class="spare-label-col">Total:</td>
                    <td><?php if ($spareDualMoney): ?><?= ($spareDualMoney)((float) ($invoice['total_amount'] ?? 0)) ?><?php else: ?><?= htmlspecialchars((string) $currency) ?> <?= number_format((float) ($invoice['total_amount'] ?? 0), 2) ?><?php endif; ?></td>
                </tr>
                <?php if (isset($invoice['balance_due'])): ?>
                <tr>
                    <td class="spare-label-col" style="color: red; padding-top: 10px;">Amount Due:</td>
                    <td style="color: red; padding-top: 10px;"><?= htmlspecialchars((string) $currency) ?> <?= number_format((float) $invoice['balance_due'], 2) ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="spare-bottom-info avoid-break">
        <div class="spare-payment-heading" style="color: #000;">Payment details</div>
        <div class="spare-payment-details">
            <?= nl2br(htmlspecialchars($company_settings['spare_payment_details'] ?? '')) ?>
        </div>

        <div class="spare-terms">
            <?= nl2br(htmlspecialchars($company_settings['spare_terms'] ?? '')) ?><br>
            <?= htmlspecialchars($company_settings['spare_validity'] ?? '') ?>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-top: -60px;">
            <div class="spare-sig-container">
                <div class="spare-sig-image-wrapper">
                    <?php if (!empty($signatureUrl)): ?>
                        <img src="<?= htmlspecialchars($signatureUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Signature">
                    <?php endif; ?>
                </div>
                <div
                    style="border-top: 1px solid #000; margin-top: 5px; padding-top: 5px; font-weight: 700; font-size: 12px; text-transform: uppercase;">
                    Authorized Signature
                </div>
            </div>
        </div>

        <div class="spare-thank-you">
            <strong style="color: #000;"><?= htmlspecialchars($company_settings['spare_thanks_note'] ?? ($isRoadmasterBrand ? 'Thank you for Choosing Roadmaster' : 'Thank you for your business')) ?></strong>
            <div class="spare-return-policy">
                <?= htmlspecialchars($company_settings['spare_return_policy'] ?? '') ?>
            </div>
        </div>

        <?php if (!empty($document_footer_closing)) { include __DIR__ . '/../../includes/document-footer-message.php'; } ?>


    </div>
</div>