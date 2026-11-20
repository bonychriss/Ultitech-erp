<?php
@ini_set('display_errors', '0');
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
if (!($salesDb instanceof PDO)) {
    http_response_code(500);
    die('Sales database connection is not available.');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$auto_print = isset($_GET['download']);
$embedMode = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
$autoBrowserPrint = !$auto_print && isset($_GET['autoprint']) && (string) $_GET['autoprint'] === '1';
$invoiceOnly = isset($_GET['invoice_only']) && (string) $_GET['invoice_only'] === '1';

try {
    ensureCustomerColumnsExist();
} catch (Throwable $e) {
    error_log('invoice print ensureCustomerColumnsExist: ' . $e->getMessage());
}

$cTinExpr = 'NULL AS tin';
$cVrnExpr = 'NULL AS vrn';
$cTaxExpr = 'NULL AS customer_tax_id';
try {
    $custCols = $salesDb->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (in_array('tin', $custCols, true)) {
        $cTinExpr = 'c.tin';
    }
    if (in_array('vrn', $custCols, true)) {
        $cVrnExpr = 'c.vrn';
    }
    if (in_array('tax_number', $custCols, true)) {
        $cTaxExpr = 'c.tax_number AS customer_tax_id';
    }
} catch (Throwable $e) {
}

$invCols = $salesDb->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$soCols = [];
try {
    $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
}
$hasOrderJoin = in_array('order_id', $invCols, true);
$soJoinSql = $hasOrderJoin ? ' LEFT JOIN sales_orders so ON i.order_id = so.id' : '';
$userJoinSql = '';
$salespersonSelect = "'' AS salesperson";
$hasUsers = function_exists('sales_connection_has_table')
    ? sales_connection_has_table($salesDb, 'users')
    : (function_exists('tableExists') && tableExists('users', $salesDb));
if ($hasUsers) {
    $userRef = null;
    if (in_array('created_by', $invCols, true) && in_array('created_by', $soCols, true) && $hasOrderJoin) {
        $userRef = 'COALESCE(i.created_by, so.created_by)';
    } elseif (in_array('created_by', $invCols, true)) {
        $userRef = 'i.created_by';
    } elseif (in_array('created_by', $soCols, true) && $hasOrderJoin) {
        $userRef = 'so.created_by';
    }
    if ($userRef !== null) {
        $userJoinSql = " LEFT JOIN users u ON {$userRef} = u.id";
        $salespersonSelect = 'u.full_name AS salesperson';
    }
}

$invoice = null;
try {
    $sql = "SELECT i.*, c.company_name, c.contact_person, c.email, c.phone, c.address,
        {$cTinExpr}, {$cVrnExpr}, {$cTaxExpr}, {$salespersonSelect}
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id{$soJoinSql}{$userJoinSql}
        WHERE i.id = ?";
    $invoiceParams = [$id];
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($sql, $invoiceParams, 'invoices', 'i');
    }
    $stmt = $salesDb->prepare($sql);
    $stmt->execute($invoiceParams);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('invoice print load: ' . $e->getMessage());
}

if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

// Fetch Company Settings (fallback safely if table is missing)
$company_settings = [];
try {
    $company_settings = $salesDb->query('SELECT * FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $company_settings = [];
}

$company_settings = array_merge([
    'company_name' => '',
    'company_address' => '',
    'company_logo' => 'Untitled.jpg',
    'company_phone' => '',
    'company_email' => '',
    'default_currency' => 'TZS',
    'bank_details' => '',
    'company_website' => '',
    'include_catalogue' => 0,
], $company_settings);

if ($invoiceOnly) {
    // Popup / direct print: invoice sheets only (no catalogue pages).
    $company_settings['include_catalogue'] = 0;
}

if (($nameVal = getCompanySetting('company_name')) && trim($nameVal) !== '') {
    $company_settings['company_name'] = $nameVal;
}
if (($addrVal = getCompanySetting('company_address')) && trim($addrVal) !== '') {
    $company_settings['company_address'] = $addrVal;
}
if (($phoneVal = getCompanySetting('company_phone')) && trim($phoneVal) !== '') {
    $company_settings['company_phone'] = $phoneVal;
}
if (($emailVal = getCompanySetting('company_email')) && trim($emailVal) !== '') {
    $company_settings['company_email'] = $emailVal;
}
if (($tinVal = getCompanySetting('company_tin')) && trim($tinVal) !== '') {
    $company_settings['company_tin'] = $tinVal;
}
if (($vrnVal = getCompanySetting('company_vrn')) && trim($vrnVal) !== '') {
    $company_settings['company_vrn'] = $vrnVal;
} elseif (($vatVal = getCompanySetting('company_vat')) && trim($vatVal) !== '') {
    $company_settings['company_vrn'] = $vatVal;
}
if (($locVal = getCompanySetting('company_location')) && trim($locVal) !== '') {
    $company_settings['company_location'] = $locVal;
}
if (($bankVal = getCompanySetting('bank_details')) && trim($bankVal) !== '') {
    $company_settings['bank_details'] = $bankVal;
}
if (($footerVal = getCompanySetting('document_footer_message')) && trim($footerVal) !== '') {
    $company_settings['document_footer_message'] = $footerVal;
}
if (($logoVal = getCompanySetting('company_logo')) && trim($logoVal) !== '') {
    $company_settings['company_logo'] = trim($logoVal);
}

// Keep invoice print header aligned with admin/company-settings.php values.
$companyProfile = null;
if (function_exists('getCurrentCompany')) {
    $companyProfile = getCurrentCompany();
}
if ((!is_array($companyProfile) || empty($companyProfile)) && function_exists('getRequestedCompany')) {
    $companyProfile = getRequestedCompany();
}
if (is_array($companyProfile) && !empty($companyProfile)) {
    $profileName = trim((string) ($companyProfile['company_name'] ?? ''));
    $profileAddress = trim((string) ($companyProfile['address'] ?? ''));
    $profilePhone = trim((string) ($companyProfile['phone'] ?? ''));
    $profileEmail = trim((string) ($companyProfile['email'] ?? ''));

    if ($profileName !== '') {
        $company_settings['company_name'] = $profileName;
    }
    if ($profileAddress !== '') {
        $company_settings['company_address'] = $profileAddress;
    }
    if ($profilePhone !== '') {
        $company_settings['company_phone'] = $profilePhone;
    }
    if ($profileEmail !== '') {
        $company_settings['company_email'] = $profileEmail;
    }
}

// Company logo URL (uploads, company settings, or sales_settings — same as invoice view).
$printCompanyId = (int) (currentCompanyId() ?? 0);
if ($printCompanyId <= 0 && is_array($companyProfile) && !empty($companyProfile['id'])) {
    $printCompanyId = (int) $companyProfile['id'];
}
if ($printCompanyId <= 0 && function_exists('getRequestedCompany')) {
    $reqCo = getRequestedCompany();
    if (is_array($reqCo) && !empty($reqCo['id'])) {
        $printCompanyId = (int) $reqCo['id'];
    }
}
$company_settings['company_logo_url'] = function_exists('getCompanyLogoUrl')
    ? getCompanyLogoUrl($printCompanyId > 0 ? $printCompanyId : null)
    : '';
$invoiceLogoUrl = trim((string) ($company_settings['company_logo_url'] ?? ''));
if ($invoiceLogoUrl === '' && !empty($company_settings['company_logo'])) {
    $logoRel = (string) $company_settings['company_logo'];
    $logoIsData = (function_exists('str_starts_with') && str_starts_with($logoRel, 'data:'))
        || (strncmp($logoRel, 'data:', 5) === 0);
    if (preg_match('#^https?://#i', $logoRel) || $logoIsData) {
        $invoiceLogoUrl = $logoRel;
    } else {
        $logoStartsAssets = (function_exists('str_starts_with') && str_starts_with($logoRel, 'assets/'))
            || (strncmp($logoRel, 'assets/', 7) === 0);
        $logoRel = $logoStartsAssets ? $logoRel : 'assets/images/' . ltrim($logoRel, '/');
        $logoDisk = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($logoRel, '/'));
        if (is_file($logoDisk) && function_exists('app_url')) {
            $invoiceLogoUrl = app_url('/' . ltrim($logoRel, '/'));
        } elseif (is_file($logoDisk)) {
            $invoiceLogoUrl = '/' . ltrim($logoRel, '/');
        }
    }
}
if ($invoiceLogoUrl === '' && function_exists('app_url')) {
    $invoiceLogoUrl = app_url('/assets/images/' . ltrim((string) ($company_settings['company_logo'] ?: 'Untitled.jpg'), '/'));
}

// Fetch Items (support databases where products.main_image may not exist)
$productImageExpr = 'p.image AS main_image';
try {
    $productCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (in_array('main_image', $productCols, true) && in_array('image', $productCols, true)) {
        $productImageExpr = 'COALESCE(p.main_image, p.image) AS main_image';
    } elseif (in_array('main_image', $productCols, true)) {
        $productImageExpr = 'p.main_image AS main_image';
    } elseif (in_array('image', $productCols, true)) {
        $productImageExpr = 'p.image AS main_image';
    } else {
        $productImageExpr = "'' AS main_image";
    }
} catch (Throwable $e) {
    $productImageExpr = 'p.image AS main_image';
}

$sqlItems = "SELECT soi.*, p.name as product_name, p.product_code, p.description AS product_description, {$productImageExpr}
             FROM sales_order_items soi
             LEFT JOIN products p ON soi.product_id = p.id
             WHERE soi.order_id = ?";
$items = [];
$orderId = (int) ($invoice['order_id'] ?? 0);
if ($orderId > 0) {
    $stmtItems = $salesDb->prepare($sqlItems);
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (function_exists('sales_enrich_order_items_images')) {
        $items = sales_enrich_order_items_images($items, $salesDb);
    }
}

// Determine currency: Invoice currency > Settings currency > Default TZS
$currency = $invoice['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <!-- Use system styles but add the specific ERP overrides -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php echo sales_document_font_stylesheet_links($company_settings); ?>
    <style>
        body {
            background: #525659;
            margin: 0;
            padding: 0;
            font-family: <?php echo sales_document_font_family_css($company_settings); ?>;
            font-size: 9.5pt; /* Balanced size */
        }

        .sheet-container {
            width: 210mm;
            min-height: auto;
            padding: 8mm 12mm 10mm;
            margin: 4mm auto;
            background: white;
            box-shadow: 0 0 0.35cm rgba(0,0,0,0.12);
            position: relative;
            page-break-after: always;
            break-after: page;
            box-sizing: border-box;
        }

        .sheet-container:last-child {
            page-break-after: auto;
            break-after: auto;
            margin-bottom: 4mm;
        }

        /* Screen preview: compact gaps between pages; fill sheet without huge empty tails */
        @media screen {
            body {
                padding: 4mm 0;
            }
            .sheet-container {
                min-height: 270mm;
                margin: 3mm auto;
            }
            .sheet-inner {
                display: flex;
                flex-direction: column;
                min-height: 252mm;
            }
            .sheet-page-footer,
            .document-footer-wrap {
                margin-top: auto;
            }
        }

        .sheet-inner {
            width: 100%;
        }

        .sheet-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .sheet-header--continued {
            margin-bottom: 8px;
        }

        .sheet-page-footer {
            padding-top: 6px;
            margin-top: 8px;
            border-top: none;
            text-align: center;
            font-size: 9pt;
            color: #64748b;
            width: 100%;
        }

        .document-footer-wrap {
            padding-top: 8px;
            width: 100%;
        }

        .avoid-break,
        .document-footer-closing {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* Print: one physical page per logical sheet */
        @media print {
            @page {
                size: A4;
                margin: 10mm;
            }
            html, body {
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
            #pdf-content {
                margin: 0 !important;
                padding: 0 !important;
            }
            .sheet-container {
                width: 100% !important;
                max-width: 100% !important;
                min-height: auto !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                page-break-after: always;
                break-after: page;
                page-break-inside: avoid;
                break-inside: avoid-page;
            }
            .sheet-container:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .sheet-inner {
                display: block !important;
                min-height: 0 !important;
                height: auto !important;
            }
            .o-table td img {
                width: 40px !important;
                height: 40px !important;
            }
            .o-table td,
            .o-table th {
                padding-top: 2px !important;
                padding-bottom: 2px !important;
            }
            .document-footer-wrap {
                margin-top: 8px !important;
                padding-top: 6px !important;
                page-break-before: avoid;
                break-before: avoid;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .document-footer-closing {
                margin-top: 4px !important;
            }
            .sheet-page-footer {
                margin-top: 8px !important;
                padding-top: 6px !important;
                border-top: none !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .sheet-header {
                margin-bottom: 8px !important;
            }
            .o-table {
                margin-bottom: 6px !important;
            }
            .o-table th {
                background-color: #ffd700 !important;
                background: #ffd700 !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            .o-table td {
                padding: 2px 4px !important;
            }
            .o-table td img {
                width: 48px !important;
                height: 48px !important;
            }
            /* Keep logos / header fills / paid watermark colors */
            img, .invoice-paid-watermark span {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }

        .sheet-title { font-size: 16pt; font-weight: bold; color: #333; margin: 0; }
        
        /* Fast/Compact Table */
        .o-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; table-layout: fixed; }
        .o-table th { 
            border-bottom: 2px solid #000; 
            text-transform: uppercase; 
            font-size: 9pt; 
            font-weight: 400;
            background-color: #ffd700 !important; /* gold — match on-screen invoice */
            background: #ffd700 !important;
            color: #000 !important;
            padding: 4px 5px;
            vertical-align: middle;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        .o-table td {
            padding: 3px 5px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            font-size: 9.5pt;
            word-break: normal;
            overflow-wrap: break-word;
            hyphens: manual;
            white-space: normal;
            line-height: 1.35;
        }
        .o-table .num { text-align: right; }
        .o-table th.num { text-align: right; }
        
        /* Smaller Images */
        .o-table td img { width: 60px !important; height: 60px !important; }
        
        .totals-table td {
            padding: 2px 8px;
            font-size: 9.25pt;
            font-weight: 400;
        }
        .totals-table .grand-total {
            font-weight: 400 !important;
            border-top: 1px solid #ccc;
            font-size: 9.25pt !important;
        }
        .totals-area .totals-table tr:last-child td {
            font-weight: 400;
            font-size: 9.25pt;
            color: #b91c1c;
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
            border: none;
            background: none;
            text-transform: uppercase;
        }
        .sheet-container { position: relative; overflow: hidden; }

        /* PDF Generation Mode — match print: one sheet = one A4 page */
        body.pdf-mode { background: white; }
        body.pdf-mode .sheet-container { 
            box-shadow: none; 
            margin: 0; 
            border: none;
            width: 210mm !important;
            max-width: 210mm !important;
            min-height: auto !important;
            padding: 0 !important;
            overflow: visible !important;
            page-break-after: always;
            break-after: page;
        }
        body.pdf-mode .sheet-container:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        body.pdf-mode .sheet-inner {
            min-height: 0 !important;
            display: block !important;
        }
        body.pdf-mode .o-table td img {
            width: 40px !important;
            height: 40px !important;
        }
        body.pdf-mode .document-footer-wrap {
            margin-top: 10px !important;
        }
        body.pdf-mode .no-print { display: none; }
        body.pdf-mode #downloadBtn { display: none; }
        
        /* Prevent table row breaking */
        tr { page-break-inside: avoid; break-inside: avoid; }

        @media print {
            body { background: white; }
            .sheet-container { overflow: visible !important; }
            .no-print, #downloadBtn { display: none !important; }
        }

        <?php if ($embedMode || $invoiceOnly): ?>
        /* Embedded in invoice-view popup: invoice paper only */
        #downloadBtn,
        .no-print {
            display: none !important;
        }
        body {
            background: #525659 !important;
        }
        <?php endif; ?>
    </style>
</head>
<body class="<?php echo trim(($embedMode ? 'embed-mode' : '') . ($invoiceOnly ? ' invoice-only' : '')); ?>">
    <!-- Include html2pdf.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        function downloadPDF() {
            if (typeof html2pdf === 'undefined') {
                notifyDeliveryDocPdf(false, 'PDF library failed to load.');
                return;
            }
            notifyDeliveryDocPdfStart();
            // Add styling class for clean PDF
            document.body.classList.add('pdf-mode');

            // Select the container that holds all pages
            const element = document.getElementById('pdf-content');
            if (!element) {
                notifyDeliveryDocPdf(false, 'Invoice content not found.');
                return;
            }
            
            const opt = {
                margin:       [10, 10, 10, 10], // Match @page print margins
                filename:     'Invoice_<?php echo $invoice['invoice_number']; ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, scrollY: 0 },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak:    { mode: ['css'], after: '.sheet-container' }
            };

            // Hide the download button during generation (redundant via CSS but safe)
            const btn = document.getElementById('downloadBtn');
            if(btn) btn.style.display = 'none';

            html2pdf().set(opt).from(element).save().then(function(){
                // Restore state
                document.body.classList.remove('pdf-mode');
                if(btn) btn.style.display = 'block';
                notifyDeliveryDocPdf(true, '');
            }).catch(function(err){
                document.body.classList.remove('pdf-mode');
                if(btn) btn.style.display = 'block';
                notifyDeliveryDocPdf(false, (err && err.message) ? err.message : 'PDF generation failed.');
            });
        }

        function notifyDeliveryDocPdfStart() {
            var payload = { type: 'delivery-doc-pdf-start' };
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

        function notifyDeliveryDocPdf(ok, error) {
            var payload = { type: 'delivery-doc-pdf', ok: !!ok, error: error || '' };
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

        document.addEventListener('DOMContentLoaded', function() {
            <?php if($auto_print): ?>
            setTimeout(function() {
                if (typeof html2pdf === 'undefined') {
                    notifyDeliveryDocPdf(false, 'PDF library failed to load.');
                    return;
                }
                downloadPDF();
            }, 1500);
            <?php elseif ($autoBrowserPrint): ?>
            setTimeout(function() {
                try {
                    window.focus();
                    window.print();
                } catch (e) {}
            }, 400);
            window.addEventListener('afterprint', function() {
                try {
                    if (window.opener) {
                        window.close();
                    }
                } catch (e) {}
            });
            <?php endif; ?>
        });
    </script>
    
    <?php if (!$auto_print && !$embedMode && !$invoiceOnly): ?>
    <button id="downloadBtn" class="no-print" onclick="downloadPDF()" style="
        position: fixed; 
        top: 20px; 
        right: 20px; 
        z-index: 9999; 
        padding: 10px 20px; 
        background: #008784; 
        color: white; 
        border: none; 
        border-radius: 4px; 
        cursor: pointer; 
        font-family: inherit; 
        font-weight: bold;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    ">
        <i class="fas fa-download"></i> Download PDF
    </button>
    <?php endif; ?>

    <?php
    // Pagination Logic
    // Keep each logical sheet within one printed A4 page (header + photos + wrapped descriptions).
    $first_page_limit = 6;
    $subsequent_page_limit = 9;
    
    $chunks = [];
    if (count($items) <= $first_page_limit) {
        $chunks[] = $items;
    } else {
        $chunks[] = array_slice($items, 0, $first_page_limit);
        $remaining = array_slice($items, $first_page_limit);
        if (!empty($remaining)) {
            $chunks = array_merge($chunks, array_chunk($remaining, $subsequent_page_limit));
        }
    }
    
    $total_pages = count($chunks);
    ?>

    <div id="pdf-content">
        <?php foreach ($chunks as $page_idx => $page_items): ?>
            <?php $is_last_page = ($page_idx === $total_pages - 1); ?>
            
            <div class="sheet-container">
                <!-- Paid Ribbon (Only on Page 1 or All Pages? Usually Page 1 is enough, but consistent is fine) -->
                <?php if ($invoice['status'] === 'paid' && $page_idx === 0): ?>
                <div class="invoice-paid-watermark" aria-hidden="true"><span>PAID</span></div>
                <?php endif; ?>

                <div class="sheet-inner">
                    <!-- Header (page numbers are at the bottom) -->
                    <div class="sheet-header<?php echo $page_idx === 0 ? '' : ' sheet-header--continued'; ?>">
                        <h1 class="sheet-title">
                            Invoice # <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                        </h1>
                        <div style="text-align: right; width: 60%;">
                            <?php if ($invoiceLogoUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($invoiceLogoUrl); ?>" alt="Company Logo" style="max-height: <?php echo $page_idx === 0 ? '80px' : '44px'; ?>; max-width: 220px; object-fit: contain; margin-bottom: <?php echo $page_idx === 0 ? '10px' : '0'; ?>;" onerror="this.style.display='none'">
                            <?php endif; ?>

                            <?php if ($page_idx === 0): ?>
                            <div style="font-weight: bold; font-size: 1rem; color: #111; text-transform: uppercase;">
                                <?php echo htmlspecialchars($company_settings['company_name']); ?>
                            </div>
                            <div style="font-size: 0.9rem; color: #000; white-space: pre-line;"><?php echo htmlspecialchars($company_settings['company_address']); ?></div>
                            
                            <?php if(!empty($company_settings['company_phone'])): ?>
                                <div style="font-size: 0.9rem; color: #000;">Phone: <?php echo htmlspecialchars($company_settings['company_phone']); ?></div>
                            <?php endif; ?>
                            <?php if(!empty($company_settings['company_email'])): ?>
                                <div style="font-size: 0.9rem; color: #000;">Email: <?php echo htmlspecialchars($company_settings['company_email']); ?></div>
                            <?php endif; ?>
                            <?php if(!empty($company_settings['company_tin'])): ?>
                                <div style="font-size: 0.9rem; color: #000;">TIN: <?php echo htmlspecialchars($company_settings['company_tin']); ?></div>
                            <?php endif; ?>
                            <?php if(!empty($company_settings['company_vrn'])): ?>
                                <div style="font-size: 0.9rem; color: #000;">VRN: <?php echo htmlspecialchars($company_settings['company_vrn']); ?></div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Customer Details (Page 1 Only) -->
                    <?php if ($page_idx === 0): ?>
                        <div style="margin-bottom: 20px;">
                            <strong style="font-size: 1.1rem; color: #111;"><?php echo htmlspecialchars((string) ($invoice['company_name'] ?? $invoice['contact_person'] ?? '-')); ?></strong><br>
                            <?php
                                $receiverAddress = trim((string) ($invoice['address'] ?? ''));
                                if ($receiverAddress === '') {
                                    $receiverAddress = trim((string) ($invoice['company_address'] ?? ''));
                                }
                            ?>
                            <?php if ($receiverAddress !== ''): ?>
                                <span style="color: #000;"><?php echo nl2br(htmlspecialchars($receiverAddress)); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['email'])): ?>
                                <span style="color: #000;"><?php echo htmlspecialchars($invoice['email']); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['phone'])): ?>
                                <span style="color: #000;"><?php echo htmlspecialchars($invoice['phone']); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['tin'])): ?>
                                <span style="color: #000;">TIN: <?php echo htmlspecialchars($invoice['tin']); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['vrn'])): ?>
                                <span style="color: #000;">VRN: <?php echo htmlspecialchars($invoice['vrn']); ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Date Info Bar -->
                        <div style="margin-top: 10px; margin-bottom: 10px; border: 1px solid #000; display: flex; text-align: left;">
                            <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                                <div style="font-weight: bold; font-size: 0.8rem;">Invoice Date</div>
                                <div style="font-size: 0.85rem;"><?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></div>
                            </div>
                            <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                                <div style="font-weight: bold; font-size: 0.8rem;">Due Date</div>
                                <div style="font-size: 0.85rem;">
                                    <?php echo !empty($invoice['due_date']) ? date('d/m/Y', strtotime($invoice['due_date'])) : '-'; ?>
                                </div>
                            </div>
                            <div style="flex: 1; padding: 4px 8px;">
                                <div style="font-weight: bold; font-size: 0.8rem;">Salesperson</div>
                                <div style="font-size: 0.85rem;"><?php echo htmlspecialchars($invoice['salesperson'] ?? '-'); ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Continuation pages: no top page label (number is in footer) -->
                    <?php endif; ?>

                    <!-- Items Table -->
                    <table class="o-table">
                        <colgroup>
                            <col style="width: 64px;">
                            <col style="width: 24%;">
                            <col style="width: 38%;">
                            <col style="width: 12%;">
                            <col style="width: 16%;">
                        </colgroup>
                        <thead>
                            <tr style="background-color: #ffd700;">
                                <th style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Photo</th>
                                <th style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Product</th>
                                <th style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Description</th>
                                <th class="num" style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Quantity</th>
                                <th class="num" style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Unit Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($page_items as $item): ?>
                                <tr>
                                    <td style="padding: 3px 5px;">
                                        <?php 
                                            $pid = (int)($item['product_id'] ?? 0);
                                            $img = $item['main_image'] ?? '';
                                            $thumb = $page_idx === 0 ? 44 : 40;
                                            if ($pid > 0):
                                                $imgUrl = function_exists('sales_order_item_image_url')
                                                    ? sales_order_item_image_url($item, 'medium')
                                                    : app_url('/stock/product_image.php?product_id=' . $pid . '&size=medium&file=' . rawurlencode((string) $img));
                                        ?>
                                            <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                                                    alt="Product" 
                                                    style="width: <?php echo (int) $thumb; ?>px; height: <?php echo (int) $thumb; ?>px; object-fit: cover; border-radius: 4px;"
                                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div style="display: none; width: <?php echo (int) $thumb; ?>px; height: <?php echo (int) $thumb; ?>px; background: #eee; border-radius: 4px; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                        <?php else: ?>
                                            <div style="width: <?php echo (int) $thumb; ?>px; height: <?php echo (int) $thumb; ?>px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 3px 5px;"><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td style="padding: 3px 5px;">
                                        <?php
                                        $desc = trim((string)($item['description'] ?? ''));
                                        $desc = str_replace(["\xC2\xAD", "\u{00AD}", '&shy;'], '', $desc);
                                        if ($desc !== '' && !empty($item['product_code'])) {
                                            $desc = preg_replace('/\s*\[' . preg_quote((string)$item['product_code'], '/') . '\]\s*$/u', '', $desc);
                                        }
                                        echo htmlspecialchars($desc);
                                        ?>
                                    </td>
                                    <td class="num" style="padding: 4px 6px;"><?php echo $item['quantity']; ?></td>
                                    <td class="num" style="padding: 4px 6px;"><?php echo number_format($item['unit_price'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($is_last_page): ?>
                        <div class="totals-area" style="display: flex; justify-content: flex-end; margin-top: 5px;">
                            <table class="totals-table">
                                <tr>
                                    <td>Untaxed Amount:</td>
                                    <td><?php echo $currency . ' ' . number_format($invoice['subtotal'], 2); ?></td>
                                </tr>
                                <?php if ($invoice['discount_amount'] > 0): ?>
                                <tr>
                                    <td>Discount:</td>
                                    <td>-<?php echo $currency . ' ' . number_format($invoice['discount_amount'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td>Taxes:</td>
                                    <td><?php echo $currency . ' ' . number_format($invoice['tax_amount'], 2); ?></td>
                                </tr>
                                <?php if ($invoice['shipping_charges'] > 0): ?>
                                <tr>
                                    <td>Shipping:</td>
                                    <td><?php echo $currency . ' ' . number_format($invoice['shipping_charges'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="grand-total">Total:</td>
                                    <td class="grand-total"><?php echo $currency . ' ' . number_format($invoice['total_amount'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 6px;">Amount Due:</td>
                                    <td style="padding-top: 6px; font-weight: 400;"><?php echo $currency . ' ' . number_format($invoice['balance_due'], 2); ?></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Bank Details (Bottom Left) -->
                        <?php if (!empty($company_settings['bank_details'])): ?>
                            <div style="margin-top: 20px; text-align: left; width: 60%;">
                                <div style="font-weight: bold; margin-bottom: 4px; border-bottom: 1px solid #eee; display: inline-block;">Bank Transfer Details</div>
                                <div style="white-space: pre-wrap; color: #555; font-size: 0.85rem; line-height: 1.4;"><?php echo htmlspecialchars($company_settings['bank_details']); ?></div>
                                <?php if (!empty($company_settings['company_website'])): ?>
                                    <div style="margin-top: 8px; font-size: 0.85rem;">
                                        Visit our website at <a href="<?php echo htmlspecialchars($company_settings['company_website']); ?>" target="_blank" style="text-decoration: none; color: #008784; font-weight: bold;"><?php echo htmlspecialchars($company_settings['company_website']); ?></a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($invoice['notes'])): ?>
                            <div style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 10px;">
                                <strong>Terms & Notes:</strong><br>
                                <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($is_last_page): ?>
                    <!-- Closing message stays with last-page content (no forced blank page) -->
                    <div class="document-footer-wrap avoid-break">
                        <?php include __DIR__ . '/../includes/document-footer-message.php'; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($total_pages > 1): ?>
                    <div class="sheet-page-footer avoid-break">
                        Page <?php echo (int) ($page_idx + 1); ?> of <?php echo (int) $total_pages; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php 
        // CATALOGUE SECTION (skipped when invoice_only=1)
        if (!$invoiceOnly && !empty($company_settings['include_catalogue'])) {
            $catalogue_items = [];
            foreach ($items as $item) {
                if (!empty($item['main_image'])) {
                    $catalogue_items[$item['product_id']] = $item; // unique by product
                }
            }
            
            if (!empty($catalogue_items)) {
                $catalogue_items = array_values($catalogue_items);
                $cat_chunks = array_chunk($catalogue_items, 6); // 6 items per page (2 columns x 3 rows)
                
                foreach ($cat_chunks as $cat_idx => $cat_page_items) {
                    ?>
                    <div class="sheet-container">
                        <!-- Header -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                            <h1 class="sheet-title" style="font-size: 20pt; color: #008784;">Product Catalogue <small style="font-size: 10pt; font-weight: normal; color: #666;">(<?php echo $cat_idx + 1; ?> of <?php echo count($cat_chunks); ?>)</small></h1>
                            <div style="text-align: right;">
                                <?php if ($invoiceLogoUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($invoiceLogoUrl); ?>" alt="Company Logo" style="max-height: 50px; max-width: 180px; object-fit: contain;" onerror="this.style.display='none'">
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Grid -->
                        <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                            <?php foreach ($cat_page_items as $c_item): ?>
                                <div style="width: calc(50% - 10px); display: flex; flex-direction: column; align-items: center; text-align: center; margin-bottom: 20px; page-break-inside: avoid;">
                                    <?php 
                                        $pid = (int)($c_item['product_id'] ?? 0);
                                        $img = $c_item['main_image'] ?? '';
                                        if ($pid > 0):
                                            $imgUrl = function_exists('sales_order_item_image_url')
                                                ? sales_order_item_image_url($c_item, 'medium')
                                                : app_url('/stock/product_image.php?product_id=' . $pid . '&size=medium&file=' . rawurlencode((string) $img));
                                    ?>
                                        <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                                             alt="Product" 
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                             style="max-width: 100%; height: 250px; object-fit: contain; margin-bottom: 15px; border: 1px solid #eee; padding: 10px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                        <div style="display: none; width: 100%; height: 250px; background: #eee; border-radius: 8px; align-items: center; justify-content: center; font-size: 14px; color: #aaa; margin-bottom: 15px;">No Image</div>
                                    <?php else: ?>
                                        <div style="width: 100%; height: 250px; background: #eee; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #aaa; margin-bottom: 15px;">No Image</div>
                                    <?php endif; ?>
                                    <h4 style="font-size: 12pt; margin: 0 0 5px 0; color: #111; font-weight: normal;"><?php echo htmlspecialchars($c_item['product_name']); ?></h4>
                                    <?php
                                    $catDesc = trim((string)($c_item['description'] ?? ''));
                                    if ($catDesc !== '' && !empty($c_item['product_code'])) {
                                        $catDesc = preg_replace('/\s*\[' . preg_quote((string)$c_item['product_code'], '/') . '\]\s*$/u', '', $catDesc);
                                    }
                                    ?>
                                    <div style="font-size: 9pt; color: #444; text-align: center; width: 100%; line-height: 1.4;"><?php echo nl2br(htmlspecialchars($catDesc)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php
                }
            }
        }
        ?>
    </div>
</body>
</html>

