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
$tableBorder = 'none';
$thBorder = 'none';
$tdBorder = 'none';
$tdVerticalBorder = 'none';

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

if (!$isRoadmasterBrand && (int) $layout === 1) {
    $headerBg = '#008784';
    $headerColor = '#fff';
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

// Invoices link via order_id; quotations are sales_orders passed as $invoice (use id).
$truckSourceOrderId = (int) ($invoice['order_id'] ?? $invoice['id'] ?? 0);

if (isset($invoiceDualCurrencyCtx) && is_array($invoiceDualCurrencyCtx) && is_callable($invoiceDualCurrencyCtx['render'] ?? null)) {
    $truckDisplayCurrencies = $invoiceDualCurrencyCtx['display_currencies'];
    $truckAmountCurrency = $invoiceDualCurrencyCtx['amount_currency'];
    $truckCurrencyRates = $invoiceDualCurrencyCtx['rates'];
    $truckRenderDualMoney = $invoiceDualCurrencyCtx['render'];
    $soCurrencyRow = [
        'currency' => $invoice['currency'] ?? $invoice['order_currency'] ?? '',
        'exchange_rate' => $invoice['exchange_rate'] ?? $invoice['order_exchange_rate'] ?? 0,
        'display_currencies' => $invoice['display_currencies'] ?? $invoice['order_display_currencies'] ?? '',
        'currency_rates' => $invoice['currency_rates'] ?? $invoice['order_currency_rates'] ?? '',
        'subtotal' => $invoice['subtotal'] ?? 0,
        'tax_amount' => $invoice['tax_amount'] ?? 0,
        'total_amount' => $invoice['total_amount'] ?? 0,
    ];
    goto truck_currency_context_ready;
}

// Prefer linked sales order currency (invoices table may not have a currency column).
$truckDisplayCurrencies = [];
$soCurrencyRow = [];
if ($truckSourceOrderId > 0) {
    $salesDbForCurrency = function_exists('sales_pdo') ? sales_pdo() : null;
    if ($salesDbForCurrency instanceof PDO) {
        try {
            $soCurCols = $salesDbForCurrency->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $selectCols = ['currency'];
            if (in_array('exchange_rate', $soCurCols, true)) {
                $selectCols[] = 'exchange_rate';
            }
            if (in_array('display_currencies', $soCurCols, true)) {
                $selectCols[] = 'display_currencies';
            }
            if (in_array('currency_rates', $soCurCols, true)) {
                $selectCols[] = 'currency_rates';
            }
            foreach (['subtotal', 'tax_amount', 'total_amount'] as $totalCol) {
                if (in_array($totalCol, $soCurCols, true)) {
                    $selectCols[] = $totalCol;
                }
            }
            $curStmt = $salesDbForCurrency->prepare('SELECT ' . implode(', ', $selectCols) . ' FROM sales_orders WHERE id = ? LIMIT 1');
            $curStmt->execute([$truckSourceOrderId]);
            $soCurrencyRow = $curStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $soCurrency = trim((string) ($soCurrencyRow['currency'] ?? ''));
            if ($soCurrency !== '') {
                $currency = $soCurrency;
                $invoice['currency'] = $soCurrency;
            }
            if (!empty($soCurrencyRow['display_currencies'])) {
                $decodedDisplay = json_decode((string) $soCurrencyRow['display_currencies'], true);
                if (is_array($decodedDisplay)) {
                    foreach ($decodedDisplay as $displayCode) {
                        $displayCode = strtoupper(trim((string) $displayCode));
                        if ($displayCode !== '' && !in_array($displayCode, $truckDisplayCurrencies, true)) {
                            $truckDisplayCurrencies[] = $displayCode;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

$invoiceCurrencyMerge = [
    'currency' => $invoice['currency'] ?? $invoice['order_currency'] ?? null,
    'exchange_rate' => $invoice['exchange_rate'] ?? $invoice['order_exchange_rate'] ?? null,
    'display_currencies' => $invoice['display_currencies'] ?? $invoice['order_display_currencies'] ?? null,
    'currency_rates' => $invoice['currency_rates'] ?? $invoice['order_currency_rates'] ?? null,
    'subtotal' => $invoice['subtotal'] ?? null,
    'tax_amount' => $invoice['tax_amount'] ?? null,
    'total_amount' => $invoice['total_amount'] ?? null,
];
foreach ($invoiceCurrencyMerge as $mergeKey => $mergeValue) {
    if (($soCurrencyRow[$mergeKey] ?? '') === '' && $mergeValue !== null && $mergeValue !== '') {
        $soCurrencyRow[$mergeKey] = $mergeValue;
    }
}

// When viewing a quotation, currency fields may already be on $invoice (sales_orders row).
if ($soCurrencyRow === [] && $truckSourceOrderId > 0 && (int) ($invoice['id'] ?? 0) === $truckSourceOrderId) {
    foreach (['currency', 'exchange_rate', 'display_currencies', 'currency_rates', 'subtotal', 'tax_amount', 'total_amount'] as $orderField) {
        if (array_key_exists($orderField, $invoice) && $invoice[$orderField] !== null && $invoice[$orderField] !== '') {
            $soCurrencyRow[$orderField] = $invoice[$orderField];
        }
    }
}

if ($truckDisplayCurrencies === [] && !empty($soCurrencyRow['display_currencies'])) {
    $decodedDisplay = json_decode((string) $soCurrencyRow['display_currencies'], true);
    if (is_array($decodedDisplay)) {
        foreach ($decodedDisplay as $displayCode) {
            $displayCode = strtoupper(trim((string) $displayCode));
            if ($displayCode !== '' && !in_array($displayCode, $truckDisplayCurrencies, true)) {
                $truckDisplayCurrencies[] = $displayCode;
            }
        }
    }
} elseif ($truckDisplayCurrencies === [] && !empty($invoice['display_currencies'])) {
    $decodedDisplay = json_decode((string) $invoice['display_currencies'], true);
    if (is_array($decodedDisplay)) {
        foreach ($decodedDisplay as $displayCode) {
            $displayCode = strtoupper(trim((string) $displayCode));
            if ($displayCode !== '' && !in_array($displayCode, $truckDisplayCurrencies, true)) {
                $truckDisplayCurrencies[] = $displayCode;
            }
        }
    }
}

if (!isset($currency) || trim((string) $currency) === '') {
    $currency = $invoice['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
}

$docCurrency = strtoupper(trim((string) $currency));
if ($docCurrency === '') {
    $docCurrency = 'TZS';
}

$truckHasSavedMultiCurrency = false;
$rawDisplayJson = (string) ($soCurrencyRow['display_currencies'] ?? $invoice['display_currencies'] ?? '');
if ($rawDisplayJson !== '') {
    $decodedSavedDisplay = json_decode($rawDisplayJson, true);
    $truckHasSavedMultiCurrency = is_array($decodedSavedDisplay) && $decodedSavedDisplay !== [];
}

// Line amounts are stored in the billing currency on new invoices; legacy rows may still hold TZS figures while currency=USD.
$truckAmountCurrency = $docCurrency;
$hasStoredRatesJson = !empty($soCurrencyRow['currency_rates']) || !empty($invoice['currency_rates']);
if (!$truckHasSavedMultiCurrency && $docCurrency !== 'TZS') {
    $maxLineAmount = 0.0;
    foreach ($items as $lineItem) {
        $maxLineAmount = max(
            $maxLineAmount,
            (float) ($lineItem['line_total'] ?? 0),
            (float) ($lineItem['unit_price'] ?? 0)
        );
    }
    $legacyStoredRate = (float) ($soCurrencyRow['exchange_rate'] ?? $invoice['exchange_rate'] ?? 0);
    $ratesLookLegacy = !$hasStoredRatesJson && $legacyStoredRate <= 1.01;
    if ($maxLineAmount >= 100000 && $ratesLookLegacy) {
        $truckAmountCurrency = 'TZS';
    }
}

if ($truckDisplayCurrencies === []) {
    $fallbackDisplay = [$docCurrency, $truckAmountCurrency, 'USD', 'TZS'];
    $truckDisplayCurrencies = [];
    foreach ($fallbackDisplay as $displayCode) {
        $displayCode = strtoupper(trim((string) $displayCode));
        if ($displayCode !== '' && !in_array($displayCode, $truckDisplayCurrencies, true)) {
            $truckDisplayCurrencies[] = $displayCode;
        }
    }
}

// Legacy fallback: show USD + TZS when no display currencies were saved on the order.
if ($isRoadmasterBrand && !$truckHasSavedMultiCurrency) {
    $truckDisplayCurrencies = array_values(array_unique(array_merge(['USD', 'TZS'], $truckDisplayCurrencies)));
} elseif (!$truckHasSavedMultiCurrency && in_array('USD', $truckDisplayCurrencies, true) === false && ($docCurrency === 'USD' || $truckAmountCurrency === 'TZS')) {
    array_splice($truckDisplayCurrencies, 1, 0, ['USD']);
    $truckDisplayCurrencies = array_values(array_unique($truckDisplayCurrencies));
}

if (function_exists('sales_order_display_currencies_ordered')) {
    $truckDisplayCurrencies = sales_order_display_currencies_ordered($truckDisplayCurrencies, $docCurrency);
}

$truckCurrencyRates = function_exists('sales_resolve_currency_rates')
    ? sales_resolve_currency_rates($soCurrencyRow, $truckDisplayCurrencies)
    : ['TZS' => 1.0];

if (
    in_array('USD', $truckDisplayCurrencies, true)
    && (float) ($truckCurrencyRates['USD'] ?? 0) <= 1.01
) {
    $billingCode = strtoupper(trim((string) ($soCurrencyRow['currency'] ?? $invoice['currency'] ?? '')));
    $storedExchangeRate = (float) ($soCurrencyRow['exchange_rate'] ?? $invoice['exchange_rate'] ?? 0);
    if ($billingCode === 'USD' && $storedExchangeRate > 1.01) {
        $truckCurrencyRates['USD'] = $storedExchangeRate;
    }
    if ((float) ($truckCurrencyRates['USD'] ?? 0) <= 1.01 && !empty($soCurrencyRow['currency_rates'])) {
        $decodedRates = json_decode((string) $soCurrencyRow['currency_rates'], true);
        if (is_array($decodedRates) && (float) ($decodedRates['USD'] ?? 0) > 1.01) {
            $truckCurrencyRates['USD'] = (float) $decodedRates['USD'];
        }
    }
    if ((float) ($truckCurrencyRates['USD'] ?? 0) <= 1.01 && function_exists('sales_invoice_bot_exchange_rates')) {
        $botUsdRates = sales_invoice_bot_exchange_rates(['USD']);
        if ((float) ($botUsdRates['USD'] ?? 0) > 1.01) {
            $truckCurrencyRates['USD'] = (float) $botUsdRates['USD'];
        }
    }
}

$truckAmountToTzs = static function (float $amount, string $fromCurrency) use ($truckCurrencyRates): float {
    $fromCurrency = strtoupper(trim($fromCurrency));
    if ($fromCurrency === 'TZS') {
        return $amount;
    }
    $rate = (float) ($truckCurrencyRates[$fromCurrency] ?? 0.0);
    return $rate > 0 ? $amount * $rate : $amount;
};

$truckAmountFromTzs = static function (float $tzsAmount, string $toCurrency) use ($truckCurrencyRates): float {
    $toCurrency = strtoupper(trim($toCurrency));
    if ($toCurrency === 'TZS') {
        return $tzsAmount;
    }
    $rate = (float) ($truckCurrencyRates[$toCurrency] ?? 0.0);
    return $rate > 0 ? $tzsAmount / $rate : $tzsAmount;
};

$truckConvertAmount = static function (float $amount, string $fromCurrency, string $toCurrency) use ($truckAmountToTzs, $truckAmountFromTzs): float {
    $fromCurrency = strtoupper(trim($fromCurrency));
    $toCurrency = strtoupper(trim($toCurrency));
    if ($fromCurrency === $toCurrency) {
        return $amount;
    }
    return $truckAmountFromTzs($truckAmountToTzs($amount, $fromCurrency), $toCurrency);
};

$truckRenderDualMoney = static function (float $amount) use ($truckAmountCurrency, $truckDisplayCurrencies, $truckConvertAmount): string {
    $lines = [];
    foreach ($truckDisplayCurrencies as $displayCode) {
        $converted = $truckConvertAmount($amount, $truckAmountCurrency, $displayCode);
        $lines[] = '<div class="truck-dual-price-line">' . htmlspecialchars($displayCode) . ' ' . number_format($converted, 2) . '</div>';
    }
    return '<div class="truck-dual-price">' . implode('', $lines) . '</div>';
};

truck_currency_context_ready:

$truckResolvedTax = (float) ($invoice['tax_amount'] ?? 0);
$truckResolvedGrandTotal = (float) ($invoice['total_amount'] ?? 0);

if ($truckResolvedGrandTotal <= 0 && !empty($soCurrencyRow['total_amount'])) {
    $truckResolvedGrandTotal = (float) $soCurrencyRow['total_amount'];
    $truckResolvedTax = (float) ($soCurrencyRow['tax_amount'] ?? 0);
}

if ($truckResolvedGrandTotal <= 0) {
    $linesSubtotal = 0.0;
    $linesTax = 0.0;
    foreach ($items as $lineItem) {
        $lineTotal = (float) ($lineItem['line_total'] ?? 0);
        if ($lineTotal <= 0) {
            $lineTotal = (float) ($lineItem['unit_price'] ?? 0) * (float) ($lineItem['quantity'] ?? 1);
        }
        $linesSubtotal += $lineTotal;
        $linesTax += (float) ($lineItem['tax_amount'] ?? 0);
    }
    if ($linesSubtotal > 0) {
        $truckResolvedGrandTotal = $linesSubtotal;
        if ($linesTax > 0) {
            $truckResolvedTax = $linesTax;
        }
    }
}

$truckResolvedUntaxed = $truckResolvedGrandTotal - $truckResolvedTax;
if ($truckResolvedUntaxed < 0) {
    $truckResolvedUntaxed = $truckResolvedGrandTotal;
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

$docFontStack = function_exists('sales_document_font_family_css')
    ? sales_document_font_family_css($company_settings ?? [])
    : "'Montserrat', sans-serif";
$docFontImport = function_exists('sales_document_font_import_css')
    ? sales_document_font_import_css($company_settings ?? [])
    : "@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Roboto:wght@400;700&display=swap');";

?>
<style>
    <?= $docFontImport ?>

    .truck-sheet {
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
        .truck-sheet {
            min-height: 297mm;
        }
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
        color: <?= $docBrandNavy ?>;
        line-height: 1;
        margin: 0;
        letter-spacing: -0.5px;
    }


    .truck-company-name-sub {
        font-size: 20px;
        font-weight: 700;
        color: <?= $docBrandNavy ?>;
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
        border-top: 2px solid <?= $docBrandAccent ?>;
        border-bottom: 2px solid <?= $docBrandAccent ?>;
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
        <?= !empty($docBrand['grayscale_watermark']) ? 'filter: grayscale(100%) opacity(0.1);' : '' ?>
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
        width: 150px;
        border-right: none;
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

    .truck-totals-table td:last-child .truck-dual-price-line,
    .row-total .truck-dual-price-line {
        font-weight: 400 !important;
    }

    .truck-dual-price-line + .truck-dual-price-line {
        margin-top: 2px;
    }

    .row-total {
        border-top: 2px solid <?= $docBrandAccent ?>;

        font-weight: 800 !important;
        color: <?= $docBrandNavy ?>;
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
        min-height: auto;
        margin: 40px auto; /* Added margin for visual separation in browser */
        position: relative;
        font-family: <?= $docFontStack ?>;
        color: #000;
        box-sizing: border-box;
        page-break-before: always;
        break-before: page;
        border: 1px solid #eee; /* Added border for browser visibility */
    }

    @media screen {
        .truck-sheet-second {
            min-height: 297mm;
        }
    }

    .pdf-mode .truck-sheet-second {
        width: 100% !important;
        padding: 10mm 15mm !important;
        min-height: auto !important;
        margin: 0 !important;
        border: none !important;
    }

    @media print {
        .truck-sheet,
        .truck-sheet-second {
            min-height: auto !important;
            width: 210mm !important;
            max-width: 210mm !important;
            margin: 0 auto !important;
            padding: 8mm 10mm !important;
            border: none !important;
            font-size: 11pt !important;
        }
        .truck-company-name-main { font-size: 26pt !important; }
        .truck-company-name-sub { font-size: 16pt !important; }
        .truck-doc-title { font-size: 22pt !important; }
        .truck-address-block,
        .truck-meta-row,
        .truck-bill-bar,
        .truck-section-content,
        .truck-payment-details,
        .truck-terms,
        .truck-bottom-info { font-size: 10pt !important; }
        .truck-table th { font-size: 9pt !important; }
        .truck-table td { font-size: 10pt !important; }
        .truck-table td div { font-size: 10pt !important; }
        .truck-dual-price-line { font-size: 10pt !important; }
        .truck-totals-table td { font-size: 10pt !important; }
        .truck-continued-hint {
            display: none !important;
        }
        .html2pdf__page-break {
            display: none !important;
            height: 0 !important;
            page-break-after: avoid !important;
            break-after: avoid !important;
        }
    }

    body.pdf-mode .truck-sheet,
    body.pdf-mode .truck-sheet-second {
        font-size: 11pt !important;
    }
    body.pdf-mode .truck-table th { font-size: 9pt !important; }
    body.pdf-mode .truck-table td { font-size: 10pt !important; }
    body.pdf-mode .truck-dual-price-line { font-size: 10pt !important; }
    body.pdf-mode .truck-address-block,
    body.pdf-mode .truck-meta-row,
    body.pdf-mode .truck-bill-bar,
    body.pdf-mode .truck-section-content { font-size: 10pt !important; }

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

<div id="invoice-sheet" data-truck-layout="2026-06-26">
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
                <h1 class="truck-company-name-main"><?= htmlspecialchars($docBrand['main']) ?></h1>
                <?php if (!empty($docBrand['sub'])): ?>
                <div class="truck-company-name-sub"><?= htmlspecialchars($docBrand['sub']) ?></div>
                <?php endif; ?>
                <?php if (!empty($docBrand['tagline'])): ?>
                <div class="truck-tagline"><?= htmlspecialchars($docBrand['tagline']) ?></div>
                <?php endif; ?>
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
                <th style="width: 145px;">UNIT PRICE (INC)<br><span style="font-size:9px;font-weight:600;"><?= htmlspecialchars(implode(' / ', $truckDisplayCurrencies)) ?></span></th>
                <th style="width: 145px;">TOTAL (INC)<br><span style="font-size:9px;font-weight:600;"><?= htmlspecialchars(implode(' / ', $truckDisplayCurrencies)) ?></span></th>
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
                    <td style="text-align: right;"><?= $truckRenderDualMoney((float) $it['unit_price']) ?></td>
                    <td style="text-align: right;"><?= $truckRenderDualMoney((float) $it['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="truck-totals-container avoid-break">
        <div>
        <table class="truck-totals-table">
            <tr>
                <td class="label-col">Untaxed Amount:</td>
                <td><?= $truckRenderDualMoney($truckResolvedUntaxed) ?></td>
            </tr>
            <tr>
                <td class="label-col">Taxes:</td>
                <td><?= $truckRenderDualMoney($truckResolvedTax) ?></td>
            </tr>
            <tr class="row-total">
                <td class="label-col">Total:</td>
                <td><?= $truckRenderDualMoney($truckResolvedGrandTotal) ?></td>
            </tr>
        </table>
        </div>
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
                <h1 class="truck-company-name-main"><?= htmlspecialchars($docBrand['main']) ?></h1>
                <?php if (!empty($docBrand['sub'])): ?>
                <div class="truck-company-name-sub"><?= htmlspecialchars($docBrand['sub']) ?></div>
                <?php endif; ?>
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
