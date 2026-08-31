<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$auto_print = isset($_GET['download']);

if ($id <= 0) {
    http_response_code(400);
    die('Invalid sales order id.');
}

// Sales data may live on control DB when tenant DB (e.g. /ultimate/) has no sales_orders table
$salesDb = sales_pdo();

// Fetch Order with Customer (schema-safe for older tenant DBs)
ensureCustomerColumnsExist();
$customerTaxExpr = 'NULL AS customer_tax_id';
$customerTinExpr = 'NULL AS tin';
$customerVrnExpr = 'NULL AS vrn';
try {
    $custCols = $salesDb->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (in_array('tax_number', $custCols, true)) {
        $customerTaxExpr = 'c.tax_number AS customer_tax_id';
    }
    if (in_array('tin', $custCols, true)) {
        $customerTinExpr = 'c.tin';
    }
    if (in_array('vrn', $custCols, true)) {
        $customerVrnExpr = 'c.vrn';
    }
} catch (Throwable $e) {
    // use NULL fallbacks above
}

$order = null;
try {
    $sql = 'SELECT so.*, c.company_name, c.contact_person, c.email, c.phone, c.address, '
        . $customerTinExpr . ', ' . $customerVrnExpr . ', ' . $customerTaxExpr . ', u.full_name AS salesperson '
        . 'FROM sales_orders so '
        . 'LEFT JOIN customers c ON so.customer_id = c.id '
        . 'LEFT JOIN users u ON so.created_by = u.id '
        . 'WHERE so.id = ?';
    $params = [$id];
    $scope = salesCompanyScopeSql('sales_orders', 'so');
    $sql .= $scope[0];
    $params = array_merge($params, $scope[1]);
    $stmt = $salesDb->prepare($sql);
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        die('Order query failed: ' . htmlspecialchars($e->getMessage()));
    }
    error_log('sales order print.php order query: ' . $e->getMessage());
}

if (!$order) {
    http_response_code(404);
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        die('Order not found (id=' . $id . ', sales DB=' . htmlspecialchars((string) $salesDb->query('SELECT DATABASE()')->fetchColumn()) . ').');
    }
    die('Order not found.');
}

// Determine Title and Filename Prefix
$is_quote = ($order['status'] === 'draft' || $order['status'] === 'quotation');
$doc_title = $is_quote ? 'Quotation' : 'Sales Order';
$filename_prefix = $is_quote ? 'Quotation_' : 'Order_';

// Fetch Company Settings
$company_settings = null;
try {
    $company_settings = $salesDb->query("SELECT * FROM sales_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $company_settings = null;
}
if (!$company_settings) {
    $company_settings = [
        'company_name' => 'Ultimate General Trading Company',
        'company_address' => 'Mikocheni B, Dar es salaam Tanzania',
        'company_logo' => 'Untitled.jpg',
        'bank_details' => '',
        'document_footer_message' => '',
    ];
}

$company_settings['company_logo_url'] = getCompanyLogoUrl();
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
$taxCalculationMode = trim((string) getCompanySetting('tax_calculation_mode', 'exclusive'));
if (!in_array($taxCalculationMode, ['exclusive', 'inclusive'], true)) {
    $taxCalculationMode = 'exclusive';
}

// Fetch Items
$productCols = [];
try {
    $productCols = $salesDb->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $productCols = [];
}
$hasMainImageCol = in_array('main_image', $productCols, true);
$hasImageCol = in_array('image', $productCols, true);
if ($hasMainImageCol && $hasImageCol) {
    $productImageSelect = "COALESCE(NULLIF(p.main_image, ''), NULLIF(p.image, '')) AS main_image";
} elseif ($hasMainImageCol) {
    $productImageSelect = "p.main_image AS main_image";
} elseif ($hasImageCol) {
    $productImageSelect = "p.image AS main_image";
} else {
    $productImageSelect = "NULL AS main_image";
}

$productCodeSelect = 'NULL AS product_code';
try {
    $productColsForCode = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (in_array('product_code', $productColsForCode, true)) {
        $productCodeSelect = 'p.product_code';
    }
} catch (Throwable $e) {
}

$lineTotalSelect = 'soi.line_total';
try {
    $soiCols = $salesDb->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('line_total', $soiCols, true)) {
        $lineTotalSelect = '(soi.quantity * soi.unit_price) AS line_total';
    }
} catch (Throwable $e) {
    $lineTotalSelect = '(soi.quantity * soi.unit_price) AS line_total';
}

$sqlItems = "SELECT soi.*, p.name AS product_name, p.description AS product_description, {$productCodeSelect}, {$productImageSelect}, {$lineTotalSelect}
             FROM sales_order_items soi 
             LEFT JOIN products p ON soi.product_id = p.id 
             WHERE soi.order_id = ?";
$items = [];
try {
    $stmtItems = $salesDb->prepare($sqlItems);
    $stmtItems->execute([$id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('sales_enrich_order_items_images')) {
        $items = sales_enrich_order_items_images($items, $salesDb);
    }
} catch (Throwable $e) {
    error_log('sales order print.php items query: ' . $e->getMessage());
    $items = [];
}

// Determine currency: Order currency > Settings currency > Default TZS
$currency = $order['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
$displayOrderNumber = (string) ($order['order_number'] ?? '');
$displayOrderNumber = preg_replace('/-OLD-\d+$/i', '', $displayOrderNumber) ?: $displayOrderNumber;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $doc_title . ' ' . htmlspecialchars($displayOrderNumber); ?></title>
    <!-- Use system styles but add the specific ERP overrides -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="/assets/css/arima-local.css" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=arima:400,500,600,700">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Arima:wght@400;500;600;700&display=swap">
    <style>
        body {
            background: #525659;
            margin: 0;
            padding: 0;
            font-family: 'Arima', Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 8.75pt;
        }

        .sheet-container {
            width: 210mm;
            min-height: 270mm; /* Reduced to prevent browser margin overflow */
            padding: 10mm 15mm;
            margin: 10mm auto;
            background: white;
            box-shadow: 0 0 0.5cm rgba(0,0,0,0.1);
            position: relative;
            font-family: inherit;
            /* Page break handling for html2pdf/print */
            page-break-after: always;
        }
        
        /* Remove page break from the last container */
        .sheet-container:last-child {
            page-break-after: auto; 
        }

        .sheet-title { font-size: 14pt; font-weight: bold; color: #333; margin: 0; }
        
        /* Fast/Compact Table */
        .o-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; table-layout: fixed; }
        .o-table th { 
            border-bottom: 2px solid #000; 
            text-transform: uppercase; 
            font-size: 8.25pt; 
            background-color: gold; 
            color: #000;
            padding: 4px 5px;
            vertical-align: middle;
        }
        .o-table td { padding: 3px 5px; border-bottom: 1px solid #eee; vertical-align: middle; font-size: 8.75pt; }
        .o-table .num { text-align: right; }
        .o-table th.num { text-align: right; }
        
        /* Smaller Images */
        .o-table td img { width: 60px !important; height: 60px !important; }
        
        .totals-table td { padding: 2px 8px; font-size: 8.75pt; }
        
        .ribbon { right: -10px; top: -10px; position: absolute; font-weight: bold; color: green; border: 2px solid green; padding: 5px 10px; transform: rotate(15deg); }

        /* PDF Generation Mode */
        /* PDF Generation Mode */
        body.pdf-mode { background: white; }
        body.pdf-mode .sheet-container { 
            box-shadow: none; 
            margin: 0; 
            border: none;
            width: 100% !important; /* Ensure it takes full width of pdf context */
            min-height: auto !important; /* Let content dictate height to avoid forcing overflow */
            padding-top: 5mm !important; /* Reduce padding to ensure fit */
            padding-bottom: 5mm !important; 
        }
        body.pdf-mode .no-print { display: none; }
        body.pdf-mode #downloadBtn { display: none; }
        
        /* Prevent table row breaking */
        tr { page-break-inside: avoid; }

        @media print {
            body { background: white; }
            .sheet-container { width: 100%; margin: 0; box-shadow: none; padding: 0mm; }
            .no-print { display: none; }
        }
    </style>
</head>
<body <?php if(!$auto_print) echo ''; ?>>
    <!-- Include html2pdf.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        function downloadPDF() {
            // Add styling class for clean PDF
            document.body.classList.add('pdf-mode');

            // Select the container that holds all pages
            const element = document.getElementById('pdf-content');
            
            const opt = {
                margin:       [0, 0, 0, 0], // Explicit zero margins
                filename:     '<?php echo $filename_prefix . $displayOrderNumber; ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, scrollY: 0 },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak:    { mode: ['css', 'legacy'] }
            };

            // Hide the download button during generation (redundant via CSS but safe)
            const btn = document.getElementById('downloadBtn');
            if(btn) btn.style.display = 'none';

            html2pdf().set(opt).from(element).save().then(function(){
                // Restore state
                document.body.classList.remove('pdf-mode');
                if(btn) btn.style.display = 'block';
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if($auto_print): ?>
            downloadPDF();
            <?php endif; ?>
        });
    </script>
    
    <?php if(!$auto_print): ?>
    <button id="downloadBtn" onclick="downloadPDF()" style="
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
    $first_page_limit = 8;
    $subsequent_page_limit = 12; // Can hold more since no customer details block potentially? Or keep same for consistency.
    
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
        <?php $serialNumber = 1; ?>
        <?php foreach ($chunks as $page_idx => $page_items): ?>
            <?php $is_last_page = ($page_idx === $total_pages - 1); ?>
            
            <div class="sheet-container">
                
                <div style="display: flex; flex-direction: column; min-height: 270mm;">
                    <!-- Header (Repeated on every page for professional look) -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                        <h1 class="sheet-title">
                            <?php echo $doc_title; ?> # <?php echo htmlspecialchars($displayOrderNumber); ?>
                            <?php if($total_pages > 1): ?>
                                <small style="font-size: 10pt; font-weight: normal; color: #666;">(Page <?php echo $page_idx + 1; ?> of <?php echo $total_pages; ?>)</small>
                            <?php endif; ?>
                        </h1>
                        <div style="text-align: right; width: 60%;">
                            <?php
                                $logoUrl = '';
                                if (function_exists('getCompanyLogoUrl')) {
                                    $logoUrl = getCompanyLogoUrl();
                                }
                                if ($logoUrl === '') {
                                    $logoFile = trim((string) ($company_settings['company_logo'] ?? 'Untitled.jpg'));
                                    if ($logoFile !== '' && strpos($logoFile, 'assets/') !== 0) {
                                        $logoFile = 'assets/images/' . ltrim($logoFile, '/');
                                    }
                                    $logoUrl = function_exists('mediaUrlFromPath')
                                        ? mediaUrlFromPath($logoFile)
                                        : sales_app_url('/' . ltrim($logoFile, '/'));
                                }
                                if ($logoUrl === '') {
                                    $logoUrl = sales_app_url('/assets/images/Untitled.jpg');
                                }
                            ?>
                            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Company Logo" style="max-height: 80px; margin-bottom: 10px;">
                            
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
                        </div>
                    </div>

                    <!-- Customer Details & Date Info (Only on Page 1) -->
                    <?php if ($page_idx === 0): ?>
                        <div style="margin-bottom: 20px;">
                            <strong style="font-size: 1.1rem; color: #111;"><?php echo htmlspecialchars((string) ($order['company_name'] ?? $order['contact_person'] ?? '-')); ?></strong><br>
                            <?php
                                $receiverAddress = trim((string) ($order['address'] ?? ''));
                                if ($receiverAddress === '') {
                                    $receiverAddress = trim((string) ($order['company_address'] ?? ''));
                                }
                            ?>
                            <?php if ($receiverAddress !== ''): ?>
                                <span style="color: #000;"><?php echo nl2br(htmlspecialchars($receiverAddress)); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($order['email'])): ?>
                                <span style="color: #000;"><?php echo htmlspecialchars($order['email']); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($order['phone'])): ?>
                                <span style="color: #000;"><?php echo htmlspecialchars($order['phone']); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($order['tin'])): ?>
                                <span style="color: #000;">TIN: <?php echo htmlspecialchars($order['tin']); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($order['vrn'])): ?>
                                <span style="color: #000;">VRN: <?php echo htmlspecialchars($order['vrn']); ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Date Info Bar -->
                        <div style="margin-top: 10px; margin-bottom: 10px; border: 1px solid #000; display: flex; text-align: left;">
                            <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                                <div style="font-weight: bold; font-size: 0.8rem;">Invoice Date</div>
                                <div style="font-size: 0.85rem;"><?php
                                    $invoiceDateRaw = $order['quote_date'] ?? ($order['created_at'] ?? '');
                                    echo $invoiceDateRaw !== '' ? date('d/m/Y', strtotime((string) $invoiceDateRaw)) : '-';
                                ?></div>
                            </div>
                            <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                                <div style="font-weight: bold; font-size: 0.8rem;">Due Date</div>
                                <div style="font-size: 0.85rem;">
                                    <?php echo !empty($order['valid_until']) ? date('d/m/Y', strtotime($order['valid_until'])) : '-'; ?>
                                </div>
                            </div>
                            <?php if (!empty($order['lead_time'])): ?>
                            <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                                <div style="font-weight: bold; font-size: 0.8rem;">Lead Time</div>
                                <div style="font-size: 0.85rem;"><?php echo htmlspecialchars((string)$order['lead_time']) . ' Days'; ?></div>
                            </div>
                            <?php endif; ?>
                            <div style="flex: 1; padding: 4px 8px;">
                                <div style="font-weight: bold; font-size: 0.8rem;">Salesperson</div>
                                <div style="font-size: 0.85rem;"><?php echo htmlspecialchars($order['salesperson'] ?? 'System Admin'); ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                         <!-- Minimal Spacer for subsequent pages -->
                         <div style="border-bottom: 2px solid #eee; margin-bottom: 15px; padding-bottom: 5px;">
                            <strong>Continuation - Page <?php echo $page_idx + 1; ?></strong>
                         </div>
                    <?php endif; ?>
                    

                    <!-- Items Table -->
                    <table class="o-table">
                        <thead>
                            <tr>
                                <th class="num" style="width: 6%;">S/N</th>
                                <th style="width: 10%;">Image</th>
                                <th style="width: 16%;">Product</th>
                                <th style="width: 25%;">Description</th>
                                <th class="num" style="width: 10%;">Quantity</th>
                                <th class="num" style="width: 16.5%;">Unit Price</th>
                                <th class="num" style="width: 16.5%;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($page_items as $item): ?>
                                <tr>
                                    <td class="num" style="padding: 4px 6px;"><?php echo $serialNumber++; ?></td>
                                    <td style="padding: 4px 6px;">
                                        <?php 
                                            $pid = (int)($item['product_id'] ?? 0);
                                            $img = $item['main_image'] ?? '';
                                            if ($pid > 0):
                                                $imgUrl = function_exists('sales_order_item_image_url')
                                                    ? sales_order_item_image_url($item, 'medium')
                                                    : sales_product_image_url($pid, (string) $img, 'medium');
                                        ?>
                                            <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                                                    alt="Product" 
                                                    style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px;"
                                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div style="display: none; width: 60px; height: 60px; background: #eee; border-radius: 4px; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                        <?php else: ?>
                                            <div style="width: 60px; height: 60px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 4px 6px;"><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td style="padding: 4px 6px;">
                                        <?php 
                                        echo htmlspecialchars($item['description'] ?? ''); 
                                        if(!empty($item['product_code'])) echo ' <small class="text-muted">[' . htmlspecialchars($item['product_code']) . ']</small>';
                                        ?>
                                    </td>
                                    <td class="num" style="padding: 4px 6px;"><?php echo $item['quantity']; ?></td>
                                    <td class="num" style="padding: 4px 6px;"><?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td class="num" style="padding: 4px 6px;"><?php echo number_format((float) ($item['line_total'] ?? ((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0))), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($is_last_page): ?>
                        <div class="totals-area" style="display: flex; justify-content: flex-end; margin-top: 5px;">
                            <table class="totals-table">
                                <tr>
                                    <td>Untaxed Amount:</td>
                                    <td><?php echo $currency . ' ' . number_format($order['subtotal'], 2); ?></td>
                                </tr>
                                <?php if ($order['discount_amount'] > 0): ?>
                                <tr>
                                    <td>Discount:</td>
                                    <td>-<?php echo $currency . ' ' . number_format($order['discount_amount'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($taxCalculationMode === 'exclusive' && (float) ($order['tax_amount'] ?? 0) > 0): ?>
                                <tr>
                                    <td>Taxes:</td>
                                    <td><?php echo $currency . ' ' . number_format((float) $order['tax_amount'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($order['shipping_charges'] > 0): ?>
                                <tr>
                                    <td>Shipping:</td>
                                    <td><?php echo $currency . ' ' . number_format($order['shipping_charges'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="grand-total">Total:</td>
                                    <td class="grand-total"><?php echo $currency . ' ' . number_format($order['total_amount'], 2); ?></td>
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

                        <?php if (!empty($order['notes']) || !empty($order['terms_conditions'])): ?>
                            <div style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 10px;">
                                <strong>Terms & Notes:</strong><br>
                                <?php 
                                if (!empty($order['notes'])) echo nl2br(htmlspecialchars($order['notes'])) . "<br>";
                                if (!empty($order['terms_conditions'])) echo nl2br(htmlspecialchars($order['terms_conditions']));
                                ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Footer -->
                    <div style="margin-top: auto; padding-top: 20px; width: 100%;">
                        <?php include __DIR__ . '/../includes/document-footer-message.php'; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Product Catalog (Optional) -->
        <?php if (!empty($items) && ($company_settings['include_product_catalogue'] ?? 0) == 1): ?>
            <?php 
            $cat_chunks = array_chunk($items, 4); // Show 4 products per page in catalog
            foreach ($cat_chunks as $cat_idx => $cat_items):
            ?>
            <div class="sheet-container">
                <div style="display: flex; flex-direction: column; min-height: 270mm;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                        <h1 class="sheet-title" style="font-size: 18pt;">
                            Product Catalog
                            <small style="font-size: 10pt; font-weight: normal; color: #666;">- Order #<?php echo htmlspecialchars($displayOrderNumber); ?></small>
                        </h1>
                        <div style="text-align: right; width: 60%;">
                            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Company Logo" style="max-height: 60px; margin-bottom: 8px;">
                            <div style="font-weight: bold; font-size: 0.9rem; color: #111; text-transform: uppercase;">
                                <?php echo htmlspecialchars($company_settings['company_name']); ?>
                            </div>
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                        <thead>
                            <tr style="background-color: #008784; color: #ffffff;">
                                <th style="padding: 12px; text-align: left; width: 15%; border: 1px solid #008784;">Image</th>
                                <th style="padding: 12px; text-align: left; width: 55%; border: 1px solid #008784;">Product Details</th>
                                <th style="padding: 12px; text-align: right; width: 30%; border: 1px solid #008784;">Pricing</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cat_items as $item): ?>
                            <tr style="border-bottom: 1px solid #e0e0e0; page-break-inside: avoid;">
                                <td style="padding: 15px; vertical-align: top; border: 1px solid #e0e0e0; text-align: center;">
                                    <?php 
                                        $pid = (int)($item['product_id'] ?? 0);
                                        $img = $item['main_image'] ?? '';
                                        if ($pid > 0):
                                            $imgUrl = function_exists('sales_order_item_image_url')
                                                ? sales_order_item_image_url($item, 'medium')
                                                : sales_product_image_url($pid, (string) $img, 'medium');
                                    ?>
                                        <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                             style="width: 100px; height: 100px; object-fit: contain; border: 1px solid #eee; border-radius: 4px; padding: 2px;">
                                        <div style="display: none; color: #ccc; font-size: 2rem; display: flex; align-items: center; justify-content: center; height: 100px; width: 100px; border: 1px solid #eee; border-radius: 4px; margin: 0 auto;">
                                             <i class="fas fa-image"></i>
                                        </div>
                                    <?php else: ?>
                                        <div style="color: #ccc; font-size: 2rem; display: flex; align-items: center; justify-content: center; height: 100px; width: 100px; border: 1px solid #eee; border-radius: 4px; margin: 0 auto;">
                                             <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 15px; vertical-align: top; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12pt; font-weight: bold; color: #111;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    <div style="font-size: 10pt; color: #444; line-height: 1.5; margin-top: 5px;">
                                        <?php echo nl2br(htmlspecialchars($item['description'] ?? '')); ?>
                                    </div>
                                </td>
                                <td style="padding: 15px; vertical-align: top; text-align: right; border: 1px solid #e0e0e0; background: #fcfcfc;">
                                    <div>Qty: <strong><?php echo number_format($item['quantity']); ?></strong></div>
                                    <div>Rate: <strong><?php echo number_format($item['unit_price'], 2); ?></strong></div>
                                    <div style="border-top:1px solid #ddd; margin-top:5px; padding-top:5px; font-weight:bold;">
                                        Total: <?php echo number_format($item['line_total'], 2); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>

