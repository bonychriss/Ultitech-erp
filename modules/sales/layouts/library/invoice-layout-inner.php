<?php
/**
 * Library invoice layouts (IDs 1115)  ports of sales/invoice-app React layouts.
 *
 * Expected: $invoice, $items, $company_settings, $currency, $logoUrl, $signatureUrl
 * Optional: $is_print, $document_footer_closing, $invoiceDualCurrencyCtx,
 *           $salesDocumentBrand, $isTruck, $libraryLayoutId
 */

if (!isset($document_footer_closing)) {
    $document_footer_closing = true;
}

$company_settings = is_array($company_settings ?? null) ? $company_settings : [];
$items = is_array($items ?? null) ? $items : [];
$invoice = is_array($invoice ?? null) ? $invoice : [];

// Align sales-order keys with invoice rows (same as spare-invoice-layout-inner.php).
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
    $brandingLogo = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_logo', '')) : '';
    if ($brandingLogo !== '') {
        $logoUrl = function_exists('app_url') ? app_url('/' . ltrim($brandingLogo, '/')) : ('/' . ltrim($brandingLogo, '/'));
    } else {
        $logoFile = $company_settings['company_logo'] ?? 'Untitled.jpg';
        $logoUrl = function_exists('app_url')
            ? app_url('/assets/images/' . ltrim((string) $logoFile, '/'))
            : ('/assets/images/' . ltrim((string) $logoFile, '/'));
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

if (!isset($isTruck)) {
    $isTruck = !empty($isTruckOrder) || !empty($GLOBALS['__sales_library_is_truck']);
}

if (!isset($libraryLayoutId)) {
    $isTruckLayout = !empty($isTruck);
    $libraryLayoutId = (int) ($isTruckLayout
        ? ($company_settings['truck_layout'] ?? 11)
        : ($company_settings['spare_part_layout'] ?? 11));
}
$libraryLayoutId = (int) $libraryLayoutId;
if ($libraryLayoutId < 11 || $libraryLayoutId > 15) {
    $libraryLayoutId = 11;
}

$isTruckLayout = !empty($isTruck);
$prefix = $isTruckLayout ? 'truck' : 'spare';

$h = static function ($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};

$libMoney = static function ($n) use ($currency, $h): string {
    return $h($currency) . ' ' . number_format((float) $n, 2);
};
if (isset($invoiceDualCurrencyCtx) && is_array($invoiceDualCurrencyCtx) && is_callable($invoiceDualCurrencyCtx['render'] ?? null)) {
    $dualRender = $invoiceDualCurrencyCtx['render'];
    $libMoney = static function ($n) use ($dualRender) {
        return (string) $dualRender((float) $n);
    };
}

$assetPath = '/modules/sales/layouts/library/assets/';
if (function_exists('sales_app_url')) {
    $assetBase = rtrim(sales_app_url($assetPath), '/') . '/';
} elseif (function_exists('app_url')) {
    $assetBase = rtrim(app_url($assetPath), '/') . '/';
} else {
    $assetBase = $assetPath;
}

$companyName = trim((string) ($company_settings['company_name'] ?? ''));
if ($companyName === '' && function_exists('getCompanySetting')) {
    $companyName = trim((string) getCompanySetting('company_name', ''));
}
if ($companyName === '') {
    $companyName = 'Company';
}
$tagline = trim((string) (
    $company_settings['tagline']
    ?? $company_settings['company_tagline']
    ?? $company_settings['spare_tagline']
    ?? ''
));
$officeAddress = trim((string) (
    $company_settings['company_address']
    ?? $company_settings['address']
    ?? ''
));
$phone = trim((string) (
    $company_settings['company_phone']
    ?? $company_settings['phone']
    ?? ''
));
$email = trim((string) (
    $company_settings['company_email']
    ?? $company_settings['email']
    ?? ''
));
$website = trim((string) (
    $company_settings['company_website']
    ?? $company_settings['website']
    ?? ''
));
$companyTin = trim((string) (
    $company_settings['company_tin']
    ?? $company_settings['tin']
    ?? ''
));
$companyVrn = trim((string) (
    $company_settings['company_vrn']
    ?? $company_settings['company_vat']
    ?? $company_settings['vrn']
    ?? ''
));
if ($companyTin === '' && function_exists('getCompanySetting')) {
    $companyTin = trim((string) getCompanySetting('company_tin', ''));
}
if ($companyVrn === '' && function_exists('getCompanySetting')) {
    $companyVrn = trim((string) getCompanySetting('company_vrn', ''));
    if ($companyVrn === '') {
        $companyVrn = trim((string) getCompanySetting('company_vat', ''));
    }
}

$customerName = trim((string) ($invoice['company_name'] ?? $invoice['customer_name'] ?? ''));
$customerTin = trim((string) ($invoice['tin'] ?? ''));
$customerVrn = trim((string) ($invoice['vrn'] ?? ''));
$customerPhone = trim((string) ($invoice['phone'] ?? $invoice['customer_phone'] ?? ''));
$customerEmail = trim((string) ($invoice['email'] ?? $invoice['customer_email'] ?? ''));

$customerAddressParts = [];
foreach (['billing_address', 'address', 'customer_address', 'shipping_address'] as $addrKey) {
    $v = trim((string) ($invoice[$addrKey] ?? ''));
    if ($v !== '') {
        $customerAddressParts[] = $v;
        break;
    }
}
$cityLine = trim(implode(', ', array_filter([
    trim((string) ($invoice['city'] ?? '')),
    trim((string) ($invoice['region'] ?? $invoice['state'] ?? '')),
    trim((string) ($invoice['country'] ?? '')),
])));
if ($cityLine !== '') {
    $customerAddressParts[] = $cityLine;
}
$customerAddress = implode("\n", $customerAddressParts);

$paymentDetails = function_exists('sales_document_payment_details')
    ? sales_document_payment_details($company_settings, $isTruckLayout)
    : trim((string) (
        $company_settings[$prefix . '_payment_details']
        ?? $company_settings['spare_payment_details']
        ?? $company_settings['bank_details']
        ?? ''
    ));
$terms = trim((string) (
    $company_settings[$prefix . '_terms']
    ?? $company_settings['spare_terms']
    ?? ''
));
if ($terms === '') {
    $terms = trim((string) ($company_settings['truck_terms'] ?? ''));
}
$thanks = trim((string) (
    $company_settings[$prefix . '_thanks_note']
    ?? $company_settings['spare_thanks_note']
    ?? $company_settings['truck_thanks_note']
    ?? ''
));
$note = trim((string) (
    $invoice['notes']
    ?? $invoice['remarks']
    ?? ''
));
$validity = trim((string) (
    $company_settings[$prefix . '_validity']
    ?? $company_settings['spare_validity']
    ?? $company_settings['truck_validity']
    ?? ''
));
$libMoveFooterToPage2 = function_exists('sales_document_has_settings_second_page')
    && sales_document_has_settings_second_page($company_settings);
if (!$libMoveFooterToPage2) {
    if ($thanks === '') {
        $thanks = 'Thank you for your business';
    }
    if ($validity !== '' && $terms !== '') {
        $terms = rtrim($terms) . "\n" . $validity;
    } elseif ($validity !== '' && $terms === '') {
        $terms = $validity;
    }
    $settingsRemarks = trim((string) ($company_settings['invoice_remarks'] ?? $company_settings['truck_remarks'] ?? ''));
    if ($note === '' && $settingsRemarks !== '') {
        $note = $settingsRemarks;
    }
} else {
    // Terms / validity / remarks / return / thanks render on page 2.
    $terms = '';
    $thanks = '';
}

$subtotal = (float) ($invoice['subtotal'] ?? 0);
$taxAmount = (float) ($invoice['tax_amount'] ?? 0);
$discountAmount = (float) ($invoice['discount_amount'] ?? 0);
$totalAmount = (float) ($invoice['total_amount'] ?? 0);
if ($subtotal <= 0 && $totalAmount > 0) {
    $subtotal = max(0, $totalAmount - $taxAmount + $discountAmount);
}
$balanceDue = array_key_exists('balance_due', $invoice)
    ? (float) $invoice['balance_due']
    : $totalAmount;

$taxRatePct = 0;
if ($subtotal > 0 && $taxAmount > 0) {
    $taxRatePct = (int) round(($taxAmount / $subtotal) * 100);
}
$discountRatePct = 0;
if ($subtotal > 0 && $discountAmount > 0) {
    $discountRatePct = (int) round(($discountAmount / $subtotal) * 100);
}

$docTitle = 'INVOICE';
$statusKey = strtolower(trim((string) ($invoice['status'] ?? '')));
$hasLinkedInvoice = !empty($invoice['linked_invoice_id']) || !empty($invoice['invoice_id']);
if (function_exists('sales_order_document_title_label') && ($statusKey !== '' || $hasLinkedInvoice)) {
    $docTitle = strtoupper(sales_order_document_title_label($statusKey, (bool) $hasLinkedInvoice));
} elseif (!empty($invoice['invoice_number']) && strpos((string) $invoice['invoice_number'], 'DN') !== false) {
    $docTitle = 'DELIVERY NOTE';
}

$brandCompact = preg_replace('/\s+/', '', $companyName) ?: 'C';
$brandInitial = strtoupper(function_exists('mb_substr') ? mb_substr($brandCompact, 0, 1) : substr($brandCompact, 0, 1));

$libItemImgSrc = static function (array $item): string {
    if (!empty($item['it_img_base64'])) {
        return (string) $item['it_img_base64'];
    }
    $pid = (int) ($item['product_id'] ?? 0);
    $img = $item['main_image'] ?? $item['image'] ?? '';
    if (function_exists('sales_order_item_image_url')) {
        $url = sales_order_item_image_url($item, 'medium');
        if ($url !== '') {
            return $url;
        }
    }
    if ($pid > 0 && function_exists('sales_product_image_url')) {
        return sales_product_image_url($pid, (string) $img, 'medium');
    }
    if ($pid <= 0) {
        return '';
    }
    $path = '/stock/product_image.php?product_id=' . $pid . '&size=medium&file=' . rawurlencode(basename((string) $img));
    return function_exists('app_url') ? app_url($path) : $path;
};

$libItems = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $name = trim((string) (
        $item['product_name']
        ?? $item['description']
        ?? $item['notes']
        ?? 'Item'
    ));
    $detail = trim((string) (
        $item['description']
        ?? $item['notes']
        ?? $item['oem_number']
        ?? $item['model_number']
        ?? ''
    ));
    if ($detail === $name) {
        $detail = trim((string) ($item['oem_number'] ?? $item['model_number'] ?? $item['notes'] ?? ''));
        if ($detail === $name) {
            $detail = '';
        }
    }
    $qty = (float) ($item['quantity'] ?? 0);
    $unit = (float) ($item['unit_price'] ?? 0);
    $line = isset($item['line_total']) ? (float) $item['line_total'] : ($qty * $unit);
    $libItems[] = [
        'name' => $name,
        'detail' => $detail,
        'quantity' => $qty,
        'unit_price' => $unit,
        'line_total' => $line,
        'image_url' => $libItemImgSrc($item),
        'raw' => $item,
    ];
}

$signerName = trim((string) (
    $invoice['salesperson']
    ?? $company_settings['authorized_signatory']
    ?? $company_settings['company_name']
    ?? ''
));
$signerRole = 'Authorized Signature';

$lib = [
    'layoutId' => $libraryLayoutId,
    'isTruck' => $isTruckLayout,
    'docTitle' => $docTitle,
    'brand' => $companyName,
    'brandInitial' => $brandInitial,
    'tagline' => $tagline,
    'logoUrl' => (string) $logoUrl,
    'signatureUrl' => (string) ($signatureUrl ?? ''),
    'officeAddress' => $officeAddress,
    'phone' => $phone,
    'email' => $email,
    'website' => $website,
    'companyTin' => $companyTin,
    'companyVrn' => $companyVrn,
    'customerName' => $customerName,
    'customerTin' => $customerTin,
    'customerVrn' => $customerVrn,
    'customerPhone' => $customerPhone,
    'customerEmail' => $customerEmail,
    'customerAddress' => $customerAddress,
    'invoiceNumber' => (string) ($invoice['invoice_number'] ?? ''),
    'invoiceDate' => date('d/m/Y', $invoiceDateTs),
    'invoiceDateLong' => date('F j, Y', $invoiceDateTs),
    'dueDate' => $invoiceDueDate ? date('d/m/Y', $invoiceDueDateTs) : '',
    'items' => $libItems,
    'subtotal' => $subtotal,
    'taxAmount' => $taxAmount,
    'discountAmount' => $discountAmount,
    'totalAmount' => $totalAmount,
    'balanceDue' => $balanceDue,
    'taxRatePct' => $taxRatePct,
    'discountRatePct' => $discountRatePct,
    'paymentDetails' => $paymentDetails,
    'terms' => $terms,
    'thanks' => $thanks,
    'note' => $note,
    'signerName' => $signerName,
    'signerRole' => $signerRole,
    'currency' => (string) $currency,
    'assetBase' => $assetBase,
    'documentFooterClosing' => !empty($document_footer_closing),
];

$variantMap = [
    11 => 'purple-classic.php',
    12 => 'purple-brand.php',
    13 => 'navy-orange.php',
    14 => 'pixel-mart.php',
    15 => 'yellow-bar.php',
];
$variantFile = $variantMap[$libraryLayoutId] ?? $variantMap[11];
$variantPath = __DIR__ . '/variants/' . $variantFile;

if (!is_file($variantPath)) {
    echo '<!-- library layout variant missing: ' . $h($variantFile) . ' -->';
    return;
}

include $variantPath;

if (!empty($libMoveFooterToPage2)) {
    $salesDocTermsNumber = (string) ($invoice['invoice_number'] ?? $invoice['order_number'] ?? '');
    $salesDocTermsTypeLabel = $docTitle;
    $salesDocTermsSignatureUrl = (string) ($signatureUrl ?? '');
    include __DIR__ . '/../../includes/document-settings-second-page.php';
}
