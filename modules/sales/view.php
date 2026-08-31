<?php
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
if (!($salesDb instanceof PDO)) {
    http_response_code(500);
    die('Sales database connection is not available.');
}

$company_id = (int) (currentCompanyId() ?? 0);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    die('Invalid invoice id.');
}

try {
    ensureCustomerColumnsExist();
} catch (Throwable $e) {
    error_log('invoice view ensureCustomerColumnsExist: ' . $e->getMessage());
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
    error_log('invoice view customer columns: ' . $e->getMessage());
}

$invCols = [];
$soCols = [];
try {
    $invCols = $salesDb->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
}
try {
    $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
}

$hasOrderJoin = in_array('order_id', $invCols, true);
$soJoinSql = $hasOrderJoin ? ' LEFT JOIN sales_orders so ON i.order_id = so.id' : '';
$shippedSelect = ($hasOrderJoin && in_array('shipped_at', $soCols, true))
    ? 'so.shipped_at'
    : 'NULL AS shipped_at';

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
        {$cTinExpr}, {$cVrnExpr}, {$cTaxExpr}, {$salespersonSelect}, {$shippedSelect}
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
    error_log('invoice view load id=' . $id . ': ' . $e->getMessage());
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        http_response_code(500);
        die('Invoice query failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
}

if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

$signatureUrl = function_exists('sales_resolve_document_signature_url')
    ? sales_resolve_document_signature_url($invoice, $salesDb)
    : '';

$salesOrderCompanyScope = function_exists('salesScopedCompanyId') ? salesScopedCompanyId('sales_orders') : null;

// Handle Actions (Shipping)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ship') {
        try {
            // Strict Stock Check
            $stockCheck = checkStockAvailability($invoice['order_id']);
            if (!$stockCheck['valid']) {
                $error = "Cannot Ship: Insufficient Stock for " . implode(', ', $stockCheck['errors']);
                // Don't redirect yet, let it flow to display the error
            } else {
                // Deduct Stock
                deductStockForOrder($invoice['order_id']);
                
                // Update Order Status
                $shipSql = "UPDATE sales_orders SET status = 'shipped', shipped_at = NOW() WHERE id = ?";
                $shipParams = [$invoice['order_id']];
                if ($salesOrderCompanyScope !== null) {
                    $shipSql .= ' AND company_id = ?';
                    $shipParams[] = $salesOrderCompanyScope;
                }
                $salesDb->prepare($shipSql)->execute($shipParams);
                
                $_SESSION['success'] = "Order marked as shipped and stock deducted.";
                header("Location: view.php?id=" . $id);
                exit;
            }
        } catch (Exception $e) {
            $error = "Error updating status: " . $e->getMessage();
        }
    }
}

// Fetch Company Settings
// Some deployments do not have the sales_settings table yet, so fall back gracefully.
    $isRoadmaster = isRoadmaster();
    $brandNavy = '#0D2A4A';
    $brandYellow = '#000000';

try {
    if (function_exists('columnExists') && columnExists('sales_settings', 'company_id', $salesDb)) {
        $stmtSettings = $salesDb->prepare('SELECT * FROM sales_settings WHERE company_id = ? LIMIT 1');
        $stmtSettings->execute([$company_id]);
        $company_settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
    } else {
        $company_settings = $salesDb->query('SELECT * FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $company_settings = false;
}

if (!$company_settings) {
    $company_settings = [
        'company_name' => '',
        'company_address' => '',
        'company_logo' => 'Untitled.jpg',
        'default_currency' => 'TZS',
        'company_phone' => '',
        'company_email' => '',
        'company_tin' => '',
        'company_vat' => '',
        'bank_details' => '',
        'company_website' => '',
        'include_catalogue' => 0
    ];
}

// Dynamically override from control/global company settings table
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
    // Backward compatibility: older installs stored VRN in company_vat.
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

// Keep invoice header aligned with admin/company-settings.php values (companies table).
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

// Fetch Items (Using sales_order_items linked to the invoice's order_id)
// Keep this schema-tolerant because some deployments use `image` instead of `main_image`.
$productImageCol = null;
$extraCols = [];
$neededCols = ['vin', 'chassis_number', 'engine_number', 'truck_type', 'model_number', 'model_year', 'engine_model', 'transmission_model', 'item_type'];

try {
    $productCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('main_image', $productCols, true)) {
        $productImageCol = 'main_image';
    } elseif (in_array('image', $productCols, true)) {
        $productImageCol = 'image';
    }
    
    foreach ($neededCols as $col) {
        if (in_array($col, $productCols, true)) {
            $extraCols[] = "p.`$col`";
        } else {
            $extraCols[] = "NULL AS `$col`";
        }
    }
} catch (Exception $e) {
    $productImageCol = null;
    foreach ($neededCols as $col) {
        $extraCols[] = "NULL AS `$col`";
    }
}

$productImageSelect = $productImageCol ? "p.`$productImageCol` AS main_image" : "NULL AS main_image";
$extraColsSelect = !empty($extraCols) ? ", " . implode(', ', $extraCols) : "";

$items = [];
$orderId = (int) ($invoice['order_id'] ?? 0);
if ($orderId > 0) {
    try {
        $sqlItems = "SELECT soi.*, p.name as product_name, p.product_code, p.description AS product_description, $productImageSelect $extraColsSelect
             FROM sales_order_items soi
             LEFT JOIN products p ON soi.product_id = p.id
             WHERE soi.order_id = ?";
        $stmtItems = $salesDb->prepare($sqlItems);
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (function_exists('sales_enrich_order_items_images')) {
            $items = sales_enrich_order_items_images($items, $salesDb);
        }
    } catch (Throwable $e) {
        error_log('invoice view items id=' . $id . ': ' . $e->getMessage());
        $items = [];
    }
}

$isTruckOrder = false;
if (isRoadmaster()) {
    $storedTruck = isset($invoice['order_type']) && strtolower(trim((string) $invoice['order_type'])) === 'truck';
    if (!$storedTruck && !empty($invoice['order_id'])) {
        $otStmt = $salesDb->prepare('SELECT COALESCE(order_type, \'\') FROM sales_orders WHERE id = ? LIMIT 1');
        $otStmt->execute([(int) $invoice['order_id']]);
        $soOt = strtolower(trim((string) $otStmt->fetchColumn()));
        $storedTruck = ($soOt === 'truck');
    }
    $hasVehicleLine = false;
    foreach ($items as $it) {
        $ity = isset($it['item_type']) ? strtolower(trim((string) $it['item_type'])) : '';
        if ($ity === 'vehicle' || $ity === 'truck') {
            $hasVehicleLine = true;
            break;
        }
    }
    if ($storedTruck || $hasVehicleLine) {
        $invoice['order_type'] = 'truck';
    }
    $isTruckOrder = (isset($invoice['order_type']) && $invoice['order_type'] === 'truck');
}

// Company logo for invoice PDF/sheet (dynamic per tenant — not hardcoded)
$brandingLogoUrl = function_exists('getCompanyLogoUrl') ? getCompanyLogoUrl() : '';
if ($brandingLogoUrl === '' && !empty($company_settings['company_logo'])) {
    $logoRel = (string) $company_settings['company_logo'];
    $logoIsData = (function_exists('str_starts_with') && str_starts_with($logoRel, 'data:'))
        || (strncmp($logoRel, 'data:', 5) === 0);
    if (!preg_match('#^https?://#i', $logoRel) && !$logoIsData) {
        $logoStartsAssets = (function_exists('str_starts_with') && str_starts_with($logoRel, 'assets/'))
            || (strncmp($logoRel, 'assets/', 7) === 0);
        $logoRel = $logoStartsAssets ? $logoRel : 'assets/images/' . ltrim($logoRel, '/');
        $logoDisk = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($logoRel, '/'));
        if (is_file($logoDisk) && function_exists('app_url')) {
            $brandingLogoUrl = app_url('/' . ltrim($logoRel, '/'));
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <!-- Use system styles but add the specific ERP overrides -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/sales-mobile.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php echo sales_document_font_stylesheet_links($company_settings); ?>
    <style>
        /* Odoo-style CSS Variables & Layout from view-quote.php */
        :root {
            --odoo-brand: <?php echo $isTruckOrder ? '#1E272E' : '#714B67'; ?>;
            --odoo-brand-dark: <?php echo $isTruckOrder ? '#0F172A' : '#5b3c53'; ?>;
            --odoo-action: <?php echo $isTruckOrder ? '#FBC02D' : '#008784'; ?>;
            --odoo-gray: #f9f9f9;
            --odoo-border: #dee2e6;
        }

        <?php if ($isTruckOrder): ?>
        .o-table th { background-color: #FBC02D !important; color: #1E272E !important; font-weight: 400 !important; }
        .sheet-title { color: #1E272E !important; }
        .btn-primary-custom { background: #1E272E !important; border-color: #1E272E !important; }
        .pipeline-item.active { background: #FBC02D !important; color: #1E272E !important; border-color: #FBC02D !important; }
        .pipeline-item.done { color: #1E272E !important; }
        <?php endif; ?>


        body {
            background: #f0f2f5;
            font-family: <?php echo sales_document_font_family_css($company_settings); ?>;
            color: #374151;
            font-size: 0.9rem;
        }

        /* Layout overrides to fit within main-content if needed, 
           but here we are using the full layout style */
        
        /* Control Panel */
        .control-panel {
            background: #f8f9fa;
            border-bottom: 1px solid var(--odoo-border);
            padding: 10px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0;
            width: 100%;
        }

        .breadcrumb {
            font-size: 0.9rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 0;
        }

        .breadcrumb a { color: #4b5563; text-decoration: none; }
        .breadcrumb a:hover { color: var(--odoo-brand); }
        .breadcrumb .active { color: #111827; font-weight: 500; }

        /* Action Bar */
        .action-bar {
            background: #f8f9fa;
            border-bottom: 1px solid var(--odoo-border);
            padding: 12px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
        }

        .btn-group-custom { display: flex; gap: 6px; }

        .btn-custom {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.2s;
            border: 1px solid transparent;
            text-decoration: none;
            display: inline-block;
            line-height: 1.5;
        }

        .btn-primary-custom {
            background: var(--odoo-brand);
            color: white;
            border-color: var(--odoo-brand);
        }
        .btn-primary-custom:hover { background: var(--odoo-brand-dark); color: white; }

        .btn-secondary-custom {
            background: white;
            color: #374151;
            border-color: #d1d5db;
        }
        .btn-secondary-custom:hover { background: #f3f4f6; color: #374151; }

        .btn-action-custom {
            background: var(--odoo-action);
            color: white;
            border-color: var(--odoo-action);
        }
        .btn-action-custom:hover { opacity: 0.9; color: white; }

        /* Modern Pipeline Widget (Chevron style) */
        .pipeline-widget {
            display: flex;
            align-items: center;
            background: #fff;
            border: 1px solid var(--odoo-border);
            border-radius: 4px;
            overflow: hidden;
            margin-left: auto; /* Push to the right */
        }
        .pipeline-item {
            position: relative;
            padding: 4px 20px 4px 30px; /* Better padding for readability */
            font-size: 11px; /* Slightly larger */
            font-weight: 600;
            color: #666;
            background: #fdfdfd;
            text-transform: none; /* Sentence case is easier to read and fits better */
            letter-spacing: 0.2px;
            display: flex;
            align-items: center;
            white-space: nowrap;
        }
        .pipeline-item:first-child { padding-left: 15px; }
        .pipeline-item::after {
            content: "";
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%) rotate(45deg);
            width: 20px;
            height: 20px;
            background: #fdfdfd;
            border-right: 1px solid var(--odoo-border);
            border-top: 1px solid var(--odoo-border);
            z-index: 2;
        }
        .pipeline-item.active {
            background: var(--odoo-action);
            color: white;
        }
        .pipeline-item.active::after {
            background: var(--odoo-action);
        }
        .pipeline-item.done {
            background: #eef2ff;
            color: #4f46e5;
        }
        .pipeline-item.done::after {
            background: #eef2ff;
        }
        .pipeline-item:last-child::after { display: none; }

        .btn-group-custom { display: flex; gap: 8px; align-items: center; }
        .btn-divider { width: 1px; height: 24px; background: #ddd; margin: 0 4px; }

        .action-bar-mobile-only { display: none; }
        .invoice-actions-desktop-dropdown {
            position: relative;
            display: inline-block;
        }
        .invoice-actions-desktop-dropdown > summary.invoice-actions-desktop-summary {
            list-style: none;
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            display: inline-flex;
            align-items: center;
            line-height: 1.5;
            background: linear-gradient(180deg, #855a7a 0%, #714B67 48%, #5b3c53 100%);
            color: #fff !important;
            border: 1px solid #4a3045;
            font-weight: 600;
            letter-spacing: 0.02em;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12);
            transition: background 0.2s, box-shadow 0.2s, transform 0.15s;
        }
        .invoice-actions-desktop-dropdown > summary.invoice-actions-desktop-summary:hover {
            filter: brightness(1.06);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.15);
        }
        .invoice-actions-desktop-dropdown > summary.invoice-actions-desktop-summary .fa-bars {
            opacity: 0.95;
        }
        .invoice-actions-desktop-dropdown > summary.invoice-actions-desktop-summary .fa-caret-down {
            opacity: 0.88;
            font-size: 0.7em;
        }
        .invoice-actions-desktop-dropdown[open] > summary.invoice-actions-desktop-summary {
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.85), 0 0 0 4px rgba(113, 75, 103, 0.45);
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.85), 0 0 0 4px color-mix(in srgb, #714B67 48%, transparent);
        }
        .invoice-actions-desktop-dropdown > summary.invoice-actions-desktop-summary::-webkit-details-marker {
            display: none;
        }
        .invoice-actions-desktop-panel {
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 6px;
            min-width: 15rem;
            background: linear-gradient(165deg, #f8fafc 0%, #ffffff 55%);
            border: 1px solid var(--odoo-border);
            border: 1px solid color-mix(in srgb, var(--odoo-brand) 14%, var(--odoo-border));
            border-radius: 10px;
            box-shadow:
                0 4px 6px -1px rgba(15, 23, 42, 0.07),
                0 16px 40px -12px rgba(15, 23, 42, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            z-index: 1080;
            padding: 0.4rem;
            overflow: hidden;
        }
        .invoice-actions-desktop-item {
            display: flex;
            align-items: center;
            width: 100%;
            text-align: left;
            padding: 0.5rem 0.65rem 0.5rem 0.55rem;
            font-size: 0.8125rem;
            font-weight: 500;
            color: #1e293b;
            text-decoration: none;
            border: 0;
            background: transparent;
            cursor: pointer;
            gap: 0;
            text-transform: none;
            border-radius: 8px;
            transition: background 0.15s ease, color 0.15s ease, transform 0.12s ease;
        }
        .invoice-actions-desktop-item:hover {
            color: #0f172a;
        }
        .invoice-actions-desktop-item:focus-visible {
            outline: 2px solid color-mix(in srgb, var(--odoo-action) 55%, white);
            outline-offset: 1px;
        }
        .invoice-actions-desktop-item i.me-2 {
            width: 1.35rem;
            text-align: center;
            font-size: 0.95em;
            flex-shrink: 0;
        }
        .invoice-actions-desktop-item--payment i { color: #059669; }
        .invoice-actions-desktop-item--payment:hover {
            background: rgba(5, 150, 105, 0.1);
        }
        .invoice-actions-desktop-item--order i { color: #2563eb; }
        .invoice-actions-desktop-item--order:hover {
            background: rgba(37, 99, 235, 0.1);
        }
        .invoice-actions-desktop-item--products i { color: #7c3aed; }
        .invoice-actions-desktop-item--products:hover {
            background: rgba(124, 58, 237, 0.1);
        }
        .invoice-actions-desktop-item--delivery i { color: #d97706; }
        .invoice-actions-desktop-item--delivery:hover {
            background: rgba(217, 119, 6, 0.11);
        }
        .invoice-actions-desktop-item--pdf i { color: #e11d48; }
        .invoice-actions-desktop-item--pdf:hover {
            background: rgba(225, 29, 72, 0.08);
        }
        .invoice-actions-desktop-divider {
            margin: 0.3rem 0.35rem;
            border: 0;
            border-top: 1px solid #e2e8f0;
            border-top: 1px solid color-mix(in srgb, var(--odoo-brand) 12%, #e2e8f0);
            opacity: 1;
        }

        <?php if ($isTruckOrder): ?>
        .invoice-actions-desktop-panel {
            border-color: rgba(30, 39, 46, 0.18) !important;
            background: linear-gradient(165deg, #fffdf8 0%, #ffffff 50%) !important;
        }
        .invoice-actions-desktop-divider {
            border-top-color: rgba(30, 39, 46, 0.1) !important;
        }
        <?php endif; ?>

        .invoice-pdf-trigger-wrap {
            position: absolute !important;
            width: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: -1px !important;
            overflow: hidden !important;
            clip: rect(0, 0, 1px, 1px) !important;
            border: 0 !important;
        }

        .action-bar-dropdown-toggle {
            background: linear-gradient(180deg, #855a7a 0%, #714B67 48%, #5b3c53 100%) !important;
            border-color: #4a3045 !important;
            color: #fff !important;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.02em;
            padding: 6px 10px;
            border-radius: 5px;
            line-height: 1.25;
            min-height: 0;
        }
        .action-bar-dropdown-toggle:hover,
        .action-bar-dropdown-toggle:focus {
            filter: brightness(1.08);
            border-color: #3d2840 !important;
            color: #fff !important;
        }
        .order-actions-mobile {
            width: auto;
            max-width: 100%;
            position: relative;
            z-index: 200;
        }
        .order-actions-mobile > summary {
            list-style: none;
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            gap: 0.35rem;
            width: fit-content;
            max-width: 100%;
            box-sizing: border-box;
        }
        .order-actions-mobile > summary i {
            font-size: 0.85em;
            opacity: 0.95;
        }
        .order-actions-mobile > summary::-webkit-details-marker { display: none; }
        .order-actions-mobile-panel {
            margin-top: 6px;
            border: 1px solid var(--odoo-border);
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }
        .order-actions-mobile-item {
            display: flex;
            align-items: center;
            width: 100%;
            text-align: left;
            font-size: 0.95rem;
            padding: 0.65rem 1rem;
            border: 0;
            border-bottom: 1px solid #f3f4f6;
            background: #fff;
            color: #374151;
            text-decoration: none;
            gap: 0.35rem;
        }
        .order-actions-mobile-item:last-child { border-bottom: 0; }
        .order-actions-mobile-item:active { background: #f9fafb; }
        .order-actions-mobile-item i {
            width: 1.25rem;
            text-align: center;
            flex-shrink: 0;
        }
        a.order-actions-mobile-item { cursor: pointer; }

        /* Sheet Styles — width matches A4 so html2canvas PDF isn’t tiny in a corner */
        .sheet-container {
            width: 100%;
            max-width: 210mm;
            min-height: auto;
            padding: 5mm 5mm;
            margin: 0 auto;
            background: white;
            position: relative;
            box-sizing: border-box;
        }

        .sheet {
            background: white;
            position: relative;
        }

        .sheet-title { font-size: 20pt; font-weight: bold; color: #333; }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 32px;
        }

        .form-group {
            margin-bottom: 12px;
            display: flex;
        }

        .form-value {
            font-size: 0.95rem;
            color: #111827;
        }

        .o-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; table-layout: fixed; }
        .o-table th { 
            border-bottom: 2px solid #000; 
            text-transform: uppercase; 
            font-size: 9pt; 
            font-weight: 400;
            background-color: gold; 
            color: #000;
            padding: 10px 5px;
            vertical-align: middle;
        }
        .o-table td { padding: 10px 5px; border-bottom: 1px solid #eee; vertical-align: middle; }
        .o-table .num { text-align: right; }
        .o-table th.num { text-align: right; }
        .totals-area {
            display: flex;
            justify-content: flex-end;
            margin-top: 16px;
        }

        .totals-table td { padding: 4px 8px; }
        .totals-table .grand-total { font-weight: bold; border-top: 1px solid #000; }

        .o-totals-table {
            width: min(100%, 340px);
            border-collapse: collapse;
            font-size: 10.25pt;
            font-weight: 400;
        }
        .o-totals-table td {
            padding: 3px 6px;
            font-weight: 400;
            vertical-align: top;
            line-height: 1.35;
            color: #000;
        }
        .o-totals-table td:last-child {
            text-align: right;
            white-space: nowrap;
        }
        .o-totals-table .o-totals-muted td {
            color: #000;
        }
        .o-totals-table .o-totals-muted td:last-child {
            color: #000;
        }
        .o-totals-table .o-totals-grand td {
            color: #000;
            border-top: 1px solid #d1d5db;
            border-bottom: 1px solid #d1d5db;
            padding-top: 6px;
            padding-bottom: 6px;
        }
        .o-totals-table .o-totals-due td {
            color: #000;
            font-weight: 400;
            padding-top: 6px;
        }
        
        /* Paid watermark on invoice sheet */
        .sheet-container { overflow: hidden; }
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
            font-size: clamp(3.5rem, 14vw, 6.5rem);
            font-weight: 800;
            letter-spacing: 0.12em;
            line-height: 1;
            color: rgba(21, 128, 61, 0.22);
            border: none;
            border-radius: 0;
            padding: 0;
            background: none;
            box-shadow: none;
            text-transform: uppercase;
        }
        @media print {
            .invoice-paid-watermark span {
                color: rgba(21, 128, 61, 0.18);
            }
        }

        /* Mobile readability (keep structure, improve visibility) */
        @media (max-width: 768px) {
            body { font-size: 1rem; }

            .control-panel { padding: 10px 12px; }
            .breadcrumb { font-size: 1rem; flex-wrap: wrap; }

            .action-bar { padding: 10px 12px !important; overflow: visible; }
            .action-bar .header-inner { flex-direction: column; align-items: flex-start; overflow: visible; }
            .action-bar-desktop-only { display: none !important; }
            .action-bar-mobile-only {
                display: inline-block !important;
                width: auto;
                max-width: 100%;
                vertical-align: top;
            }
            .order-actions-mobile-panel {
                max-height: min(70vh, 420px);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                min-width: min(280px, calc(100vw - 32px));
            }

            /* Control row: breadcrumb + pipeline scroll */
            .control-panel {
                flex-wrap: wrap;
                align-items: flex-start;
                gap: 8px;
            }
            .pipeline-widget {
                margin-left: 0 !important;
                width: 100%;
                max-width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                flex-wrap: nowrap;
            }
            .pipeline-item { font-size: 10px; padding: 6px 14px 6px 22px; }

            /* Invoice sheet: same arrangement as desktop/PDF */
            .sheet-container {
                padding: 14px 12px !important;
                margin: 0 auto !important;
                max-width: 100% !important;
                background: #fff !important;
                min-height: auto !important;
            }
            .sheet { min-height: auto !important; }

            .quot-sheet-header {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: nowrap;
                justify-content: space-between !important;
                align-items: flex-start !important;
                gap: 10px !important;
                margin-bottom: 18px !important;
            }
            .quot-sheet-header .sheet-title {
                font-size: 1rem !important;
                line-height: 1.25;
                margin: 0 !important;
                flex: 1 1 auto;
                min-width: 0;
                font-weight: 700;
                color: #111;
            }
            .quot-sheet-header .quot-company-block {
                flex: 0 0 auto;
                max-width: 52%;
                width: auto !important;
                text-align: right !important;
            }
            .quot-sheet-header .quot-company-block img {
                max-height: 48px !important;
                margin-bottom: 6px !important;
            }
            .quot-sheet-header .quot-company-block > div:nth-child(2) {
                font-size: 0.8rem !important;
                font-weight: bold;
                text-transform: uppercase;
                color: #111 !important;
            }
            .quot-sheet-header .quot-company-block > div:nth-child(n+3) {
                font-size: 0.7rem !important;
                line-height: 1.35;
                color: #333 !important;
            }

            .form-grid { grid-template-columns: 1fr; gap: 16px; }
            .form-value { font-size: 0.85rem !important; }

            .date-info-bar {
                flex-wrap: nowrap !important;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #000 !important;
            }
            .date-info-bar > div {
                flex: 1 0 auto !important;
                min-width: 0;
                padding: 6px 6px !important;
                font-size: 0.75rem;
            }
            .date-info-bar > div:not(:last-child) { border-right: 1px solid #ccc !important; }

            .quot-table-scroll {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 12px -12px 0;
                padding: 0 12px 4px;
                width: calc(100% + 24px);
            }
            .quot-table-scroll::-webkit-scrollbar { height: 6px; }
            .quot-table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

            .notebook .o-table {
                table-layout: fixed !important;
                min-width: 640px;
                width: 100%;
                margin-bottom: 16px;
            }
            .notebook .o-table thead { display: table-header-group; }
            .notebook .o-table tbody { display: table-row-group; }
            .notebook .o-table tr { display: table-row; }
            .notebook .o-table th {
                background-color: #ffcc00 !important;
                color: #000 !important;
                font-size: 7.5pt !important;
                font-weight: 400 !important;
                padding: 8px 4px !important;
                border-bottom: 2px solid #000 !important;
                white-space: nowrap;
                text-transform: uppercase;
            }
            .notebook .o-table td {
                display: table-cell !important;
                font-size: 0.72rem !important;
                padding: 8px 4px !important;
                vertical-align: middle !important;
                border-bottom: 1px solid #eee !important;
                word-break: break-word;
            }
            .notebook .o-table td[data-label="Image"] img {
                width: 48px !important;
                height: 48px !important;
                object-fit: cover;
            }
            .notebook .o-table td[data-label="Image"] > div {
                width: 48px !important;
                height: 48px !important;
            }

            .totals-area {
                justify-content: flex-end !important;
                padding-right: 0;
            }
            .totals-table {
                width: auto !important;
                min-width: 220px;
                font-size: 0.85rem;
            }
            .totals-table td { padding: 4px 0 4px 12px !important; }

            .o-totals-table { font-size: 0.9rem !important; }

            .quot-bank-details {
                width: 100% !important;
                max-width: 100% !important;
                margin-top: 16px !important;
                font-size: 0.8rem;
            }
        }

        /* Print Overrides */
        @media print {
            body, .main-content { margin: 0; padding: 0; background: white; }
            .sheet-container { 
                width: 100%; 
                max-width: none;
                margin: 0; 
                box-shadow: none; 
                padding: 10mm; 
                min-height: auto;
            }
            .doc-terms-sheet {
                page-break-before: always;
                break-before: page;
                margin-top: 0 !important;
            }
            .no-print, .control-panel, .action-bar, header, .sidebar { display: none !important; }
            
            /* Force background graphics for logos/colors */
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Animated Download Button Overrides */
        .dl-button {
            position: relative;
            padding: 4px 15px;
            height: 32px;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 50px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s ease;
            overflow: hidden;
            font-size: 0.75rem;
            font-weight: 500;
            color: #374151;
            text-decoration: none;
            gap: 8px;
        }
        .dl-button:hover { background: #f3f4f6; }
        .dl-button:active { transform: scale(0.96); }
        .dl-button.success { background: #ffffff; cursor: default; border-color: #2ecc71; }
        .dl-button.loading { background: #f8f9fa; cursor: wait; }
        .dl-button .content { display: flex; align-items: center; gap: 8px; }
        .dl-button i { font-size: 14px; }
        .dl-button.success i { color: #2ecc71; }
        
        @keyframes dl-bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(2px); }
        }
        .dl-button .fa-cloud-arrow-down { animation: dl-bounce 2s infinite; }
        
        .restart-btn {
            font-size: 11px;
            color: #888;
            cursor: pointer;
            display: none;
            align-items: center;
            gap: 4px;
            margin-left: 5px;
        }
        .restart-btn.show { display: inline-flex; }
        .restart-btn:hover { color: var(--odoo-brand); }

        /* Product catalog grid styling */
        .product-grid-item {
            page-break-inside: avoid;
            break-inside: avoid;
        }
    </style>
</head>

<body>
    <?php include '../../../includes/header_employee.php'; ?>

    <div class="main-content p-0">
        <!-- 1. Control Panel -->
        <div class="control-panel" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 30px; background: #f8f9fa; border-bottom: 1px solid var(--odoo-border);">
            <div class="breadcrumb">
                <?php 
                $returnUrl = $_GET['return'] ?? null;
                if ($returnUrl): 
                    $decodedReturnUrl = urldecode($returnUrl);
                ?>
                    <a href="<?php echo htmlspecialchars($decodedReturnUrl); ?>" class="text-decoration-none" title="Back to Sales Report">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                    <span class="sep text-muted mx-2">/</span>
                <?php endif; ?>
                <a href="index.php">Invoices</a>
                <span class="sep text-muted mx-2">/</span>
                <span class="active"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
            </div>

            <!-- Status Pipeline (Far Top Right) -->
            <div class="pipeline-widget">
                <?php
                // Map current status to pipeline stages
                $stages = [
                    'draft' => ['label' => 'Draft', 'keys' => ['draft']], 
                    'sent'  => ['label' => 'Posted', 'keys' => ['sent', 'viewed', 'partial', 'overdue']], 
                    'paid'  => ['label' => 'Paid', 'keys' => ['paid']]
                ];
                
                $found_active = false;
                $current_status = $invoice['status'];

                foreach ($stages as $s_id => $s_data):
                    $is_active = in_array($current_status, $s_data['keys']);
                    $is_done = false;
                    if (!$is_active && !$found_active) $is_done = true;
                    if ($is_active) $found_active = true;

                    $class = $is_active ? 'active' : ($is_done ? 'done' : '');
                    ?>
                    <div class="pipeline-item <?php echo $class; ?>">
                        <?php echo $s_data['label']; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
        // Prepare Share Data
        $currency = $invoice['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
        
        // Generate One-Time Link
        $token = '';
        try {
            $token = generateShareToken('invoice', $invoice['id'], $_SESSION['user_id'] ?? null);
        } catch (Exception $e) {
            $token = 'error';
        }
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $salesDir = dirname(dirname($_SERVER['PHP_SELF']));
        $doc_link = $protocol . $_SERVER['HTTP_HOST'] . $salesDir . "/secure_download.php?token=" . $token;
        
        $senderName = $invoice['salesperson'] ?: 'Sales Team';
        
        $wa_body = "Dear " . ($invoice['contact_person'] ?: 'Customer') . ",\n\n";
        $wa_body .= "It was a pleasure speaking with you.\n";
        $wa_body .= "We have prepared a tailored Invoice for you based on our discussion. We are confident that this offer provides the best value and quality for your requirements.\n\n";
        $wa_body .= "Please find your Invoice #" . $invoice['invoice_number'] . " here:\n" . $doc_link . "\n\n";
        $wa_body .= "If you have any questions or would like to adjust any details, please let me know. I am happy to help!\n\n";
        $wa_body .= "Best regards,\n" . $senderName;

        $ordStatus = 'unknown';
        if (!empty($invoice['order_id'])) {
            $ordSql = 'SELECT status FROM sales_orders WHERE id = ?';
            $ordParams = [$invoice['order_id']];
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($ordSql, $ordParams, 'sales_orders');
            }
            $stmtOrd = $salesDb->prepare($ordSql);
            $stmtOrd->execute($ordParams);
            $linkedOrder = $stmtOrd->fetch();
            $ordStatus = $linkedOrder['status'] ?? 'unknown';
        }
        $invoiceShowShip = !empty($invoice['order_id']) && empty($invoice['shipped_at']) && !in_array($ordStatus, ['shipped', 'delivered', 'cancelled'], true);

        $invoiceReturnUrl = urlencode($_SERVER['PHP_SELF'] . '?id=' . $invoice['id']);
        $invoiceProductsUrl = !empty($items) ? '../products_view.php?invoice_id=' . $invoice['id'] . '&return=' . $invoiceReturnUrl : '';

        $invoiceSharePhone = preg_replace('/[^0-9]/', '', $invoice['phone'] ?? '');
        if (substr($invoiceSharePhone, 0, 1) === '0') {
            $invoiceSharePhone = '255' . substr($invoiceSharePhone, 1);
        } elseif (strlen($invoiceSharePhone) == 9) {
            $invoiceSharePhone = '255' . $invoiceSharePhone;
        }
        ?>

        <!-- 2. Action Bar with Buttons -->
        <div class="action-bar" style="background: #f8f9fa; padding: 12px 30px; border-bottom: 1px solid var(--odoo-border);">
            <div class="header-inner" style="display: flex; justify-content: flex-start; align-items: center; width: 100%;">
                <div class="btn-group-custom action-bar-desktop-only">
                    <!-- Primary Actions -->
                    <?php if ($invoice['status'] === 'draft'): ?>
                        <a href="edit.php?id=<?php echo $invoice['id']; ?>" class="btn-custom btn-primary-custom" style="background:#008784; border-color:#008784;">Edit Invoice</a>
                    <?php endif; ?>

                    <?php if ($invoiceShowShip): ?>
                        <form method="POST" class="d-inline" id="shipForm1">
                            <input type="hidden" name="action" value="ship">
                            <button type="button" onclick="confirmShipment('shipForm1')" class="btn-custom btn-primary-custom" style="background:#008784; border-color:#008784;">
                                <i class="fas fa-truck"></i> Mark Shipped
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($invoice['status'] === 'draft' || $invoiceShowShip): ?>
                    <div class="btn-divider"></div>
                    <?php endif; ?>

                    <?php
                    $invoiceActionsMenuHasLinks = ($invoice['status'] !== 'paid' && $invoice['status'] !== 'draft')
                        || !empty($invoice['order_id'])
                        || ($invoiceProductsUrl !== '');
                    ?>

                    <div class="d-inline-flex align-items-center gap-1">
                        <details class="invoice-actions-desktop-dropdown" id="invoiceActionsDesktop">
                            <summary class="btn-custom invoice-actions-desktop-summary">
                                <i class="fas fa-bars me-1"></i> Actions
                                <i class="fas fa-caret-down ms-1" aria-hidden="true"></i>
                            </summary>
                            <div class="invoice-actions-desktop-panel">
                                <?php if ($invoice['status'] !== 'paid' && $invoice['status'] !== 'draft'): 
                                    require_once __DIR__ . '/../../includes/revenue_sync.php';
                                    $revEntryId = syncInvoiceToRevenue($salesDb, (int)$invoice['id']);
                                    $paymentUrl = app_url('/revenue_record_payment.php?id=' . $revEntryId);
                                ?>
                                <a class="invoice-actions-desktop-item invoice-actions-desktop-item--payment" data-close-invoice-actions="1" href="<?php echo htmlspecialchars($paymentUrl); ?>">
                                    <i class="fas fa-money-bill-wave me-2"></i>Register Payment
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($invoice['order_id'])): ?>
                                <a class="invoice-actions-desktop-item invoice-actions-desktop-item--order" data-close-invoice-actions="1" href="../orders/view.php?id=<?php echo (int)$invoice['order_id']; ?>">
                                    <i class="fas fa-file-alt me-2"></i>View Order
                                </a>
                                <?php endif; ?>
                                <?php if ($invoiceProductsUrl !== ''): ?>
                                <a class="invoice-actions-desktop-item invoice-actions-desktop-item--products" data-close-invoice-actions="1" href="<?php echo htmlspecialchars($invoiceProductsUrl); ?>">
                                    <i class="fas fa-images me-2"></i>View Products
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($invoice['order_id'])): ?>
                                <a class="invoice-actions-desktop-item invoice-actions-desktop-item--delivery" data-close-invoice-actions="1" href="../orders/delivery_note.php?id=<?php echo (int)$invoice['order_id']; ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="fas fa-truck me-2"></i>Delivery Note
                                </a>
                                <?php endif; ?>
                                <?php if ($invoiceActionsMenuHasLinks): ?>
                                <hr class="invoice-actions-desktop-divider">
                                <?php endif; ?>
                                <button type="button" class="invoice-actions-desktop-item invoice-actions-desktop-item--pdf" id="invoicePdfDropdownTrigger" data-close-invoice-actions="1">
                                    <i class="fa-solid fa-cloud-arrow-down me-2"></i>Download PDF
                                </button>
                            </div>
                        </details>
                        <div class="restart-btn" id="restartBtn" title="Reset download">
                            <i class="fa-solid fa-arrow-rotate-left"></i>
                        </div>
                    </div>

                    <div class="invoice-pdf-trigger-wrap" aria-hidden="true">
                        <div class="dl-button" id="downloadBtn" role="presentation" tabindex="-1">
                            <div class="content">
                                <i class="fa-solid fa-cloud-arrow-down"></i>
                                <span class="text">Download PDF</span>
                            </div>
                        </div>
                    </div>

                    <div class="btn-divider"></div>

                    <!-- Share Actions -->
                    <?php if ($invoiceSharePhone): ?>
                        <a href="https://wa.me/<?php echo $invoiceSharePhone; ?>?text=<?php echo urlencode($wa_body ?? ''); ?>" target="_blank" class="btn-custom btn-secondary-custom text-success" title="WhatsApp">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($invoice['email'])): ?>
                        <a href="../send_doc.php?type=invoice&id=<?php echo $invoice['id']; ?>" class="btn-custom btn-secondary-custom text-primary" title="Email" onclick="confirmEmailSend(event, this.href, '<?php echo htmlspecialchars($invoice['email'], ENT_QUOTES); ?>');">
                            <i class="fas fa-envelope"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <details class="order-actions-mobile action-bar-mobile-only" id="invoiceActionsMobile">
                    <summary class="action-bar-dropdown-toggle d-inline-flex align-items-center justify-content-center rounded">
                        <i class="fas fa-bars"></i> Actions
                    </summary>
                    <div class="order-actions-mobile-panel">
                        <?php if ($invoice['status'] === 'draft'): ?>
                        <a class="order-actions-mobile-item fw-semibold" style="color:#008784;" data-close-invoice-actions="1" href="edit.php?id=<?php echo (int)$invoice['id']; ?>">
                            <i class="fas fa-edit"></i> Edit Invoice
                        </a>
                        <?php endif; ?>
                        <?php if ($invoice['status'] !== 'paid' && $invoice['status'] !== 'draft'): ?>
                        <a class="order-actions-mobile-item fw-semibold" style="color:#008784;" data-close-invoice-actions="1" href="<?php echo htmlspecialchars($paymentUrl ?? ''); ?>">
                            <i class="fas fa-money-bill-wave"></i> Register Payment
                        </a>
                        <?php endif; ?>
                        <?php if ($invoiceShowShip): ?>
                        <button type="button" class="order-actions-mobile-item fw-semibold" style="color:#008784;" data-close-invoice-actions="1" onclick="confirmShipment('shipForm1');">
                            <i class="fas fa-truck"></i> Mark Shipped
                        </button>
                        <?php endif; ?>

                        <?php if (!empty($invoice['order_id'])): ?>
                        <a class="order-actions-mobile-item" data-close-invoice-actions="1" href="../orders/view.php?id=<?php echo (int)$invoice['order_id']; ?>">
                            <i class="fas fa-file-alt"></i> View Order
                        </a>
                        <?php endif; ?>
                        <?php if ($invoiceProductsUrl !== ''): ?>
                        <a class="order-actions-mobile-item" data-close-invoice-actions="1" href="<?php echo htmlspecialchars($invoiceProductsUrl); ?>">
                            <i class="fas fa-images"></i> View Products
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($invoice['order_id'])): ?>
                        <a class="order-actions-mobile-item" data-close-invoice-actions="1" target="_blank" href="../orders/delivery_note.php?id=<?php echo (int)$invoice['order_id']; ?>">
                            <i class="fas fa-truck"></i> Delivery Note
                        </a>
                        <?php endif; ?>

                        <button type="button" class="order-actions-mobile-item" id="actionMobileDownloadPdf" data-close-invoice-actions="1">
                            <i class="fa-solid fa-cloud-arrow-down"></i> Download PDF
                        </button>

                        <?php if ($invoiceSharePhone): ?>
                        <a class="order-actions-mobile-item text-success" data-close-invoice-actions="1" target="_blank" href="https://wa.me/<?php echo $invoiceSharePhone; ?>?text=<?php echo urlencode($wa_body ?? ''); ?>">
                            <i class="fab fa-whatsapp"></i> WhatsApp
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($invoice['email'])): ?>
                        <a class="order-actions-mobile-item text-primary" href="../send_doc.php?type=invoice&id=<?php echo (int)$invoice['id']; ?>" onclick="confirmEmailSend(event, this.href, '<?php echo htmlspecialchars($invoice['email'], ENT_QUOTES); ?>');">
                            <i class="fas fa-envelope"></i> Email
                        </a>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        </div>

        <!-- 3. The Sheet -->
        <div id="invoice-content">
        <?php 
        $layoutPath = function_exists('sales_branded_document_layout_inner_path')
            ? sales_branded_document_layout_inner_path($isTruckOrder)
            : null;
        if ($layoutPath !== null && file_exists($layoutPath)) {
            include $layoutPath;
        } else {
            include __DIR__ . '/view_sheet_inner.php';
        }
        ?>
        </div> <!-- End #invoice-content -->
        
        <!-- Product Images and Descriptions Page -->
        <?php if (!empty($company_settings['include_catalogue']) && !empty($items)): ?>
            <div id="catalog-content" class="sheet-container" style="margin-top: 20px;">
                <div style="display: flex; flex-direction: column; min-height: 270mm;">
                    <!-- Header -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                        <h1 class="sheet-title" style="font-size: 18pt;">
                            Product Catalog
                            <small style="font-size: 10pt; font-weight: normal; color: #666;">- Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></small>
                        </h1>
                        <div style="text-align: right; width: 60%;">
                            <?php if ($brandingLogoUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($brandingLogoUrl); ?>" alt="Company Logo" style="max-height: 60px; margin-bottom: 8px;">
                            <?php endif; ?>
                            <div style="font-weight: bold; font-size: 0.9rem; color: #111; text-transform: uppercase;">
                                <?php echo htmlspecialchars($company_settings['company_name']); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Product Images and Descriptions - Large Size -->
                    <?php 
                    // Get absolute URL for images
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                    $host = $_SERVER['HTTP_HOST'];
                    $baseUrl = $protocol . $host;
                    
                    ?>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                        <thead>
                            <tr style="background-color: #008784; color: #ffffff;">
                                <th style="padding: 12px; text-align: left; width: 15%; border: 1px solid #008784; font-weight: normal;">Image</th>
                                <th style="padding: 12px; text-align: left; width: 55%; border: 1px solid #008784; font-weight: normal;">Product Details</th>
                                <th style="padding: 12px; text-align: right; width: 30%; border: 1px solid #008784; font-weight: normal;">Pricing</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr style="border-bottom: 1px solid #e0e0e0; page-break-inside: avoid;">
                                <!-- Image Column -->
                                <td style="padding: 15px; vertical-align: top; border: 1px solid #e0e0e0; text-align: center;">
                                    <?php if (!empty($item['product_id'])):
                                        $imagePath = function_exists('sales_order_item_image_url')
                                            ? sales_order_item_image_url($item, 'medium')
                                            : app_url('/stock/product_image.php?product_id=' . (int) $item['product_id'] . '&size=medium&file=' . rawurlencode((string) ($item['main_image'] ?? '')));
                                    ?>
                                        <img src="<?php echo htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8'); ?>" 
                                             alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                             style="width: 100px; height: 100px; object-fit: contain; border: 1px solid #eee; border-radius: 4px; padding: 2px;"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                        <div style="display: none; color: #ccc; font-size: 2rem;">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php else: ?>
                                        <div style="color: #ccc; font-size: 2rem; display: flex; align-items: center; justify-content: center; height: 100px; width: 100px; border: 1px solid #eee; border-radius: 4px; margin: 0 auto;">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Product Details Column -->
                                <td style="padding: 15px; vertical-align: top; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12pt; font-weight: normal; color: #111; margin-bottom: 5px;">
                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                    </div>
                                    <div style="font-size: 10pt; color: #444; line-height: 1.5;">
                                        <?php
                                        $description = trim((string)(!empty($item['description']) ? $item['description'] : ($item['product_description'] ?? '')));
                                        if ($description !== '' && !empty($item['product_code'])) {
                                            $description = preg_replace('/\s*\[' . preg_quote((string)$item['product_code'], '/') . '\]\s*$/u', '', $description);
                                        }
                                        echo nl2br(htmlspecialchars($description));
                                        ?>
                                    </div>
                                </td>
                                
                                <!-- Pricing Column -->
                                <td style="padding: 15px; vertical-align: top; text-align: right; border: 1px solid #e0e0e0; background: #fcfcfc;">
                                    <div style="margin-bottom: 5px;">
                                        <span style="color: #666; font-size: 9pt;">Quantity:</span><br>
                                        <strong style="font-size: 11pt;"><?php echo number_format($item['quantity']); ?></strong>
                                    </div>
                                    <div style="margin-bottom: 5px;">
                                        <span style="color: #666; font-size: 9pt;">Unit Price:</span><br>
                                        <strong style="font-size: 11pt;"><?php echo number_format($item['unit_price'], 2); ?> <?php echo $currency; ?></strong>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Footer Note -->
                    <div style="margin-top: auto; padding-top: 20px; text-align: center; color: #666; font-size: 8.5pt; border-top: 1px solid #eee;">
                        <p style="margin: 0;">For more information about these products, please contact us.</p>
                    </div>
                </div>
            </div>
            </div>
        <?php endif; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
             window.jsPDF = window.jspdf.jsPDF;

             // Check for status message
             const urlParams = new URLSearchParams(window.location.search);
             const msg = urlParams.get('msg');
             const type = urlParams.get('type') || 'success';
             
             if (msg) {
                 Swal.fire({
                     toast: true,
                     position: 'top-end',
                     icon: type === 'error' ? 'error' : 'success',
                     title: msg,
                     showConfirmButton: false,
                     timer: type === 'error' ? 5000 : 3000,
                     timerProgressBar: true
                 });
                 // Clean URL
                 const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + "?id=" + urlParams.get('id');
                 window.history.replaceState({path: newUrl}, '', newUrl);
             }
        });

        function invoicePdfJsPdfCtor() {
            return (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
        }

        /** Invoice sheet nodes to rasterize (page 1 + Terms & remarks page, etc.). */
        function invoicePdfCaptureElements() {
            const wrap = document.getElementById('invoice-content');
            if (!wrap) {
                return [];
            }
            const selectors = ['.sheet-container', '.spare-sheet', '.truck-sheet', '.truck-sheet-second', '.doc-terms-sheet'];
            const seen = new Set();
            const elements = [];

            selectors.forEach(function (selector) {
                wrap.querySelectorAll(selector).forEach(function (el) {
                    if (!seen.has(el)) {
                        seen.add(el);
                        elements.push(el);
                    }
                });
            });

            if (!elements.length) {
                elements.push(wrap);
            }

            return elements;
        }

        async function invoicePdfElementToCanvas(el) {
            if (!el) {
                throw new Error('Nothing to capture for PDF.');
            }
            el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            await new Promise(function (resolve) {
                requestAnimationFrame(function () { requestAnimationFrame(resolve); });
            });
            return html2canvas(el, {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor: '#ffffff',
                scrollX: 0,
                scrollY: -window.scrollY,
                windowWidth: el.scrollWidth,
                windowHeight: el.scrollHeight
            });
        }

        /** Place one tall canvas across A4 pages with margins (uses negative Y offset for continuation pages). */
        function appendRasterCanvasToPdf(doc, canvas, jpegQuality) {
            const marginMm = 8;
            const pageHmm = doc.internal.pageSize.getHeight();
            const pageWmm = doc.internal.pageSize.getWidth();
            const innerWmm = pageWmm - 2 * marginMm;
            const innerHmm = pageHmm - 2 * marginMm;

            const sliceHeightInPixels = (canvas.width * innerHmm) / innerWmm;
            const totalHeightInPixels = canvas.height;

            let sourceY = 0;
            let pageNum = 0;

            while (sourceY < totalHeightInPixels) {
                if (pageNum > 0) {
                    doc.addPage();
                }

                const currentSliceHeight = Math.min(sliceHeightInPixels, totalHeightInPixels - sourceY);

                const tempCanvas = document.createElement('canvas');
                tempCanvas.width = canvas.width;
                tempCanvas.height = currentSliceHeight;

                const tempCtx = tempCanvas.getContext('2d');
                tempCtx.fillStyle = '#ffffff';
                tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);

                tempCtx.drawImage(
                    canvas,
                    0, sourceY, canvas.width, currentSliceHeight,
                    0, 0, canvas.width, currentSliceHeight
                );

                const sliceDataUrl = tempCanvas.toDataURL('image/jpeg', jpegQuality);
                const destHeightMm = (currentSliceHeight * innerWmm) / canvas.width;
                
                doc.addImage(sliceDataUrl, 'JPEG', marginMm, marginMm, innerWmm, destHeightMm);

                sourceY += currentSliceHeight;
                pageNum++;
            }
        }

        async function appendInvoiceElementsToPdf(doc, elements, jpegQuality) {
            let hasPages = false;
            for (const el of elements) {
                if (!el) {
                    continue;
                }
                if (hasPages) {
                    doc.addPage();
                }
                const canvas = await invoicePdfElementToCanvas(el);
                appendRasterCanvasToPdf(doc, canvas, jpegQuality);
                hasPages = true;
            }
        }

        function confirmEmailSend(event, url, email) {
            event.preventDefault();
            Swal.fire({
                title: 'Send Invoice?',
                text: "Send this invoice to " + email + " with PDF attachment?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#008784',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, send it!'
            }).then((result) => {
                if (result.isConfirmed) {
                   generateAndSendPdf(url);
                }
            });
        }

        async function generateAndSendPdf(targetUrl) {
            Swal.fire({
                title: 'Generating PDF...',
                text: 'Please wait while we attach the invoice.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            try {
                const JsPDF = invoicePdfJsPdfCtor();
                const doc = new JsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });

                await appendInvoiceElementsToPdf(doc, invoicePdfCaptureElements(), 0.93);

                const catalogElement = document.getElementById('catalog-content');
                if (catalogElement) {
                    doc.addPage();
                    const catalogCanvas = await invoicePdfElementToCanvas(catalogElement);
                    appendRasterCanvasToPdf(doc, catalogCanvas, 0.93);
                }

                const pdfData = doc.output('datauristring');

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = targetUrl;
                
                const inputPdf = document.createElement('input');
                inputPdf.type = 'hidden';
                inputPdf.name = 'pdf_base64';
                inputPdf.value = pdfData;
                
                form.appendChild(inputPdf);
                document.body.appendChild(form);
                form.submit();

            } catch (err) {
                console.error(err);
                Swal.fire('Error', 'Failed to generate PDF.', 'error');
            }
        }

        // Animated Download Button Logic
        const dlBtn = document.getElementById('downloadBtn');
        const restartBtn = document.getElementById('restartBtn');
        const dlIcon = dlBtn ? dlBtn.querySelector('i') : null;
        const dlText = dlBtn ? dlBtn.querySelector('.text') : null;

        document.getElementById('invoicePdfDropdownTrigger')?.addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('downloadBtn')?.click();
        });

        document.getElementById('actionMobileDownloadPdf')?.addEventListener('click', () => {
            dlBtn?.click();
        });

        (function () {
            var det = document.getElementById('invoiceActionsMobile');
            if (!det) return;
            det.addEventListener('click', function (e) {
                var el = e.target.closest('[data-close-invoice-actions]');
                if (el && !e.target.closest('summary')) {
                    det.open = false;
                }
            });
        })();

        (function () {
            var det = document.getElementById('invoiceActionsDesktop');
            if (!det) return;
            det.addEventListener('click', function (e) {
                var el = e.target.closest('[data-close-invoice-actions]');
                if (el && !e.target.closest('summary')) {
                    det.open = false;
                }
            });
            document.addEventListener('click', function (e) {
                if (!det.open) return;
                if (!det.contains(e.target)) {
                    det.open = false;
                }
            });
        })();

        let isDownloading = false;

        if (dlBtn && dlIcon && dlText) {
        dlBtn.addEventListener('click', async () => {
            if (isDownloading || dlBtn.classList.contains('success')) return;

            isDownloading = true;
            dlBtn.classList.add('loading');
            
            // Start Animation
            dlIcon.className = "fa-solid fa-spinner fa-spin";
            let progress = 0;
            
            const progressInterval = setInterval(() => {
                progress += Math.floor(Math.random() * 5) + 1;
                if (progress > 95) progress = 95; // Wait for real PDF generation
                dlText.innerText = `${progress}% Prepared`;
            }, 100);

            try {
                const JsPDF = invoicePdfJsPdfCtor();
                const doc = new JsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });

                await appendInvoiceElementsToPdf(doc, invoicePdfCaptureElements(), 0.93);

                const catalogElement = document.getElementById('catalog-content');
                if (catalogElement) {
                    doc.addPage();
                    const catalogCanvas = await invoicePdfElementToCanvas(catalogElement);
                    appendRasterCanvasToPdf(doc, catalogCanvas, 0.93);
                }

                clearInterval(progressInterval);
                dlText.innerText = `100% Done`;
                
                // Trigger Download
                doc.save(`Invoice_<?php echo $invoice['invoice_number']; ?>.pdf`);

                // Finish State
                dlBtn.classList.remove('loading');
                dlBtn.classList.add('success');
                dlIcon.className = "fa-solid fa-circle-check";
                dlText.innerText = "Invoice Downloaded";
                restartBtn?.classList.add('show');
                isDownloading = false;

            } catch (err) {
                console.error(err);
                clearInterval(progressInterval);
                dlBtn.classList.remove('loading');
                dlIcon.className = "fa-solid fa-triangle-exclamation";
                dlText.innerText = "Error";
                isDownloading = false;
            }
        });

        restartBtn?.addEventListener('click', () => {
            dlBtn.classList.remove('success', 'loading');
            dlIcon.className = "fa-solid fa-cloud-arrow-down";
            dlText.innerText = "Download PDF";
            restartBtn.classList.remove('show');
        });
        }

        function confirmShipment(formId) {
            Swal.fire({
                title: 'Mark as Shipped?',
                text: "Are you sure you want to mark this as shipped? Stock will be deducted.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#008784',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Mark as Shipped'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(formId).submit();
                }
            });
        }
    </script>
    <?php
    $paymentSuccessLottie = __DIR__ . '/../payments/includes/payment-success-lottie.php';
    if (is_readable($paymentSuccessLottie)) {
        include $paymentSuccessLottie;
    }
    ?>
</body>
</html>

