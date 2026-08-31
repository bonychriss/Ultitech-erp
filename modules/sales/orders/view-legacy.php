<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';

// require_once '../../../includes/auth.php';
// checkAuthentication('sales');
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;

// Fetch Company Settings (table may be missing on some deployments)
$isRoadmaster = isRoadmaster();
    $brandNavy = '#0D2A4A';
    $brandYellow = '#000000';

try {
    $company_id = (int) (currentCompanyId() ?? 0);
    if ($company_id > 0 && function_exists('columnExists') && columnExists('sales_settings', 'company_id', $salesDb)) {
        $stmtSettings = $salesDb->prepare("SELECT * FROM sales_settings WHERE company_id = ? LIMIT 1");
        $stmtSettings->execute([$company_id]);
        $company_settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
    } else {
        $company_settings = $salesDb->query("SELECT * FROM sales_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $company_settings = false;
}

if (!$company_settings) {
    $company_settings = [
        'company_name' => defined('COMPANY_NAME') ? COMPANY_NAME : '',
        'company_address' => defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : '',
        'company_logo' => 'Untitled.jpg',
        'default_currency' => 'TZS',
        'company_phone' => defined('COMPANY_PHONE') ? COMPANY_PHONE : '',
        'company_email' => defined('COMPANY_EMAIL') ? COMPANY_EMAIL : '',
        'company_tin' => '',
        'company_vat' => '',
        'bank_details' => '',
        'company_website' => '',
        'include_product_catalogue' => 0,
    ];
}

if ($isRoadmaster) {
    $roadmasterFooterDefaults = [
        'truck_payment_details' => 'Payment details',
        'truck_terms' => 'Terms and Conditions..',
        'truck_validity' => 'Invoice is valid for 10 days',
        'truck_thanks_note' => 'Thank you for your business',
        'truck_return_policy' => 'Return policy be: Only unused, undamaged, and originally packaged items are accepted.',
        'spare_payment_details' => 'Payment details',
        'spare_terms' => 'Terms and Conditions..',
        'spare_validity' => 'Invoice is valid for 10 days',
        'spare_thanks_note' => 'Thank you for your business',
        'spare_return_policy' => 'Return policy be: Only unused, undamaged, and originally packaged items are accepted.',
    ];
    foreach ($roadmasterFooterDefaults as $footerKey => $footerDefault) {
        if (!isset($company_settings[$footerKey]) || trim((string) $company_settings[$footerKey]) === '') {
            $company_settings[$footerKey] = $footerDefault;
        }
    }
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
if (($bankVal = getCompanySetting('bank_details')) && trim($bankVal) !== '') {
    $company_settings['bank_details'] = $bankVal;
}
if (($footerVal = getCompanySetting('document_footer_message')) && trim($footerVal) !== '') {
    $company_settings['document_footer_message'] = $footerVal;
}

// If sales/company KV settings are empty, fall back to the active company profile
// maintained by admin/company-settings.php (companies table).
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

    // Admin Company Settings is the source of truth for company identity/contact.
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

// Fetch Order with Customer and Salesperson (tolerate missing customer tin/vrn columns)
ensureCustomerColumnsExist();
$cTinExpr = 'NULL AS tin';
$cVrnExpr = 'NULL AS vrn';
try {
    $custCols = $salesDb->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (is_array($custCols)) {
        if (in_array('tin', $custCols, true)) {
            $cTinExpr = 'c.tin';
        }
        if (in_array('vrn', $custCols, true)) {
            $cVrnExpr = 'c.vrn';
        }
    }
} catch (Exception $e) {
    // keep NULL aliases
}

$order = null;
try {
    $sql = "SELECT so.*, c.company_name, c.contact_person, c.email, c.phone, c.address, $cTinExpr, $cVrnExpr, u.full_name AS salesperson 
            FROM sales_orders so 
            LEFT JOIN customers c ON so.customer_id = c.id 
            LEFT JOIN users u ON so.created_by = u.id
            WHERE so.id = ?";
    $params = [$id];
    $scope = salesCompanyScopeSql('sales_orders', 'so');
    $sql .= $scope[0];
    $params = array_merge($params, $scope[1]);
    $stmt = $salesDb->prepare($sql);
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('sales orders view.php order query: ' . $e->getMessage());
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        $dbName = '';
        try {
            $dbName = (string) $salesDb->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $ignored) {
        }
        die('Order query failed: ' . htmlspecialchars($e->getMessage()) . ' (database: ' . htmlspecialchars($dbName) . ')');
    }
}

if (!$order) {
    http_response_code(404);
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        $dbName = '';
        try {
            $dbName = (string) $salesDb->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $ignored) {
        }
        die('Sales Order not found (id=' . $id . ', database=' . htmlspecialchars($dbName) . ').');
    }
    die('Sales Order not found.');
}

$displayOrderNumber = (string) ($order['order_number'] ?? '');
$displayOrderNumber = preg_replace('/-OLD-\d+$/i', '', $displayOrderNumber) ?: $displayOrderNumber;

$linkedInvoice = function_exists('salesOrderViewFindLinkedInvoice')
    ? salesOrderViewFindLinkedInvoice($salesDb, $id)
    : ['id' => 0, 'invoice_number' => ''];
$linkedInvoiceId = (int) ($linkedInvoice['id'] ?? 0);
$linkedInvoiceNumber = trim((string) ($linkedInvoice['invoice_number'] ?? ''));
$hasLinkedInvoice = $linkedInvoiceId > 0;
$documentDisplayNumber = ($hasLinkedInvoice && $linkedInvoiceNumber !== '')
    ? $linkedInvoiceNumber
    : $displayOrderNumber;

$signatureUrl = function_exists('sales_resolve_document_signature_url')
    ? sales_resolve_document_signature_url($order, $salesDb)
    : '';

// Fetch Items â€” some DBs use `image` instead of `main_image`
$productImageCol = null;
$extraCols = [];
$neededCols = ['vin', 'chassis_number', 'engine_number', 'truck_type', 'model_number', 'model_year', 'engine_model', 'transmission_model', 'item_type', 'oem_number'];

try {
    $productCols = $salesDb->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
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

$sqlItems = "SELECT soi.*, p.name AS product_name, p.product_code, p.description AS product_description, $productImageSelect $extraColsSelect
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
    error_log('sales orders view.php items query: ' . $e->getMessage());
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        die('Items query failed: ' . htmlspecialchars($e->getMessage()));
    }
}

$isTruckOrder = false;
if (isRoadmaster()) {
    $storedTruck = isset($order['order_type']) && strtolower(trim((string) $order['order_type'])) === 'truck';
    $hasVehicleLine = false;
    foreach ($items as $it) {
        $ity = isset($it['item_type']) ? strtolower(trim((string) $it['item_type'])) : '';
        if ($ity === 'vehicle' || $ity === 'truck') {
            $hasVehicleLine = true;
            break;
        }
    }
    if ($storedTruck || $hasVehicleLine) {
        $order['order_type'] = 'truck';
    }
    $isTruckOrder = (isset($order['order_type']) && $order['order_type'] === 'truck');
}

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $newStatus = '';
    
    if ($action === 'confirm') $newStatus = 'confirmed';
    if ($action === 'cancel') $newStatus = 'cancelled';
    if ($action === 'invoice') $newStatus = 'invoiced';
    if ($action === 'ship') $newStatus = 'shipped';
    if ($action === 'sent') $newStatus = 'quotation';
    
    if ($newStatus) {
        // Handle Stock Changes
        if ($action === 'ship' && $order['status'] !== 'shipped' && $order['status'] !== 'delivered') {
             // Strict Stock Check
             $stockCheck = checkStockAvailability($id);
             if (!$stockCheck['valid']) {
                 $errorMsg = "Cannot Ship: Insufficient Stock for " . implode(', ', $stockCheck['errors']);
                 header("Location: view.php?id=$id&msg=" . urlencode($errorMsg) . "&type=error");
                 exit;
             }
             
             deductStockForOrder($id);
        }
        
        if ($action === 'cancel' && ($order['status'] === 'shipped' || $order['status'] === 'delivered')) {
             restoreStockForOrder($id);
        }

        if ($action === 'ship') {
            $updateSql = "UPDATE sales_orders SET status = ?, shipped_at = NOW() WHERE id = ?";
            $salesDb->prepare($updateSql)->execute([$newStatus, $id]);
        } else {
            $updateSql = "UPDATE sales_orders SET status = ? WHERE id = ?";
            $salesDb->prepare($updateSql)->execute([$newStatus, $id]);
        }
        
        header("Location: view.php?id=$id&msg=Status updated");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order <?php echo htmlspecialchars($displayOrderNumber); ?></title>
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
        .invoice-actions-desktop-item--danger i { color: #dc2626; }
        .invoice-actions-desktop-item--danger:hover {
            background: rgba(220, 38, 38, 0.08);
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
        
        /* Ribbon */
        .ribbon { right: -10px; top: -10px; }

        /* Mobile readability (keep structure, improve visibility) — aligned with invoice view */
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

            tr, table tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            thead {
                display: table-header-group !important;
            }
            .totals-area, .totals-table, .o-totals-table, .chatter, .note-box, .sheet-header-title, .form-grid, .date-info-bar, .quot-company-block {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
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
                    <a href="<?php echo htmlspecialchars($decodedReturnUrl); ?>" class="text-decoration-none" title="Back">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                    <span class="sep text-muted mx-2">/</span>
                <?php endif; ?>
                <a href="index.php">Orders</a>
                <span class="sep text-muted mx-2">/</span>
                <span class="active"><?php echo htmlspecialchars($documentDisplayNumber); ?></span>
            </div>

            <!-- Status Pipeline (Far Top Right) -->
            <div class="pipeline-widget">
                <?php
                $stages = [
                    'draft'     => ['label' => 'Quotation', 'keys' => ['draft']],
                    'sent'      => ['label' => 'Quotation Sent', 'keys' => ['quotation']],
                    'confirmed' => ['label' => 'Sales Order', 'keys' => ['confirmed', 'shipped', 'delivered']],
                    'invoiced'  => ['label' => 'Invoiced', 'keys' => ['invoiced', 'paid']]
                ];
                
                $found_active = false;
                $current_status = $order['status'];

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
        $currency = $order['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');

        $token = '';
        try {
            $token = generateShareToken('order', $order['id'], $_SESSION['user_id'] ?? null);
        } catch (Exception $e) {
            $token = 'error';
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $salesDir = dirname(dirname($_SERVER['PHP_SELF']));
        $doc_link = $protocol . $_SERVER['HTTP_HOST'] . $salesDir . "/secure_download.php?token=" . $token;

        $senderName = $order['salesperson'] ?: 'Sales Team';
        $wa_body = "Dear " . ($order['contact_person'] ?: 'Customer') . ",\n\n";
        $wa_body .= "We have prepared your order #" . $displayOrderNumber . ". ";
        $wa_body .= "You can view it here:\n" . $doc_link . "\n\n";
        $wa_body .= "Best regards,\n" . $senderName;

        $orderReturnUrl = urlencode($_SERVER['PHP_SELF'] . '?id=' . $order['id']);
        $orderProductsUrl = '../products_view.php?order_id=' . $order['id'] . '&return=' . $orderReturnUrl;
        $orderModule = isset($_GET['module']) ? trim((string) $_GET['module']) : 'sales';
        if ($orderModule === '') {
            $orderModule = 'sales';
        }
        $orderCreateInvoiceUrl = function_exists('sales_module_url')
            ? sales_module_url('invoices/create.php', ['order_id' => (int) $order['id'], 'module' => $orderModule])
            : ('../invoices/create.php?order_id=' . (int) $order['id'] . '&module=' . rawurlencode($orderModule));
        $orderStatusKey = strtolower(trim((string) ($order['status'] ?? '')));
        $orderCanCreateInvoice = $linkedInvoiceId <= 0
            && !in_array($orderStatusKey, ['cancelled', 'canceled', 'delivered', 'invoiced', 'paid'], true)
            && in_array($orderStatusKey, ['draft', 'quotation', 'sent', 'confirmed'], true);
        $orderShowInvoice = $orderCanCreateInvoice;

        $orderSharePhone = preg_replace('/[^0-9]/', '', $order['phone'] ?? '');
        if (substr($orderSharePhone, 0, 1) === '0') {
            $orderSharePhone = '255' . substr($orderSharePhone, 1);
        } elseif (strlen($orderSharePhone) == 9) {
            $orderSharePhone = '255' . $orderSharePhone;
        }

        $orderActionsMenuHasLinks = ($orderProductsUrl !== '')
            || !in_array($order['status'], ['cancelled', 'delivered', 'invoiced', 'paid'], true);
        ?>

        <!-- 2. Action Bar (same chrome as invoice view) -->
        <div class="action-bar" style="background: #f8f9fa; padding: 12px 30px; border-bottom: 1px solid var(--odoo-border);">
            <div class="header-inner" style="display: flex; justify-content: flex-start; align-items: center; width: 100%;">
                <div class="btn-group-custom action-bar-desktop-only">
                    <?php if ($order['status'] === 'draft'): ?>
                        <form method="POST" class="d-inline" id="formOrderMarkSent">
                            <button type="submit" name="action" value="sent" class="btn-custom btn-primary-custom" style="background:#008784; border-color:#008784;">Mark as Sent</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'draft' || $order['status'] === 'quotation'): ?>
                        <form method="POST" class="d-inline" id="formOrderConfirm">
                            <button type="submit" name="action" value="confirm" class="btn-custom btn-primary-custom" style="background:#008784; border-color:#008784;">Confirm Order</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'draft' || $order['status'] === 'quotation' || $order['status'] === 'confirmed'): ?>
                    <div class="btn-divider"></div>
                    <?php endif; ?>

                    <div class="d-inline-flex align-items-center gap-1">
                        <details class="invoice-actions-desktop-dropdown" id="invoiceActionsDesktop">
                            <summary class="btn-custom invoice-actions-desktop-summary">
                                <i class="fas fa-bars me-1"></i> Actions
                                <i class="fas fa-caret-down ms-1" aria-hidden="true"></i>
                            </summary>
                            <div class="invoice-actions-desktop-panel">
                                <a class="invoice-actions-desktop-item invoice-actions-desktop-item--order" data-close-invoice-actions="1" href="edit.php?id=<?php echo (int) $order['id']; ?>">
                                    <i class="fas fa-edit me-2"></i>Edit Order
                                </a>
                                <a class="invoice-actions-desktop-item invoice-actions-desktop-item--products" data-close-invoice-actions="1" href="<?php echo htmlspecialchars($orderProductsUrl); ?>">
                                    <i class="fas fa-images me-2"></i>View Products
                                </a>
                                <?php if ($orderShowInvoice): ?>
                                <a class="invoice-actions-desktop-item invoice-actions-desktop-item--payment" data-close-invoice-actions="1" href="<?php echo htmlspecialchars($orderCreateInvoiceUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-file-invoice-dollar me-2"></i>Invoice
                                </a>
                                <?php endif; ?>
                                <?php if (!in_array($order['status'], ['cancelled', 'delivered', 'invoiced', 'paid'], true)): ?>
                                <button type="button" class="invoice-actions-desktop-item invoice-actions-desktop-item--danger text-danger" data-close-invoice-actions="1" onclick="confirmCancel('cancelForm');">
                                    <i class="fas fa-times-circle me-2"></i>Cancel Order
                                </button>
                                <?php endif; ?>
                                <?php if ($orderActionsMenuHasLinks): ?>
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

                    <?php if ($orderSharePhone): ?>
                        <a href="https://wa.me/<?php echo $orderSharePhone; ?>?text=<?php echo urlencode($wa_body ?? ''); ?>" target="_blank" class="btn-custom btn-secondary-custom text-success" title="WhatsApp">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($order['email'])): ?>
                        <a href="../send_doc.php?type=order&id=<?php echo (int) $order['id']; ?>" class="btn-custom btn-secondary-custom text-primary" title="Email" onclick="confirmEmailSend(event, this.href, '<?php echo htmlspecialchars($order['email'], ENT_QUOTES); ?>');">
                            <i class="fas fa-envelope"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="d-none" id="cancelForm">
                    <input type="hidden" name="action" value="cancel">
                </form>

                <details class="order-actions-mobile action-bar-mobile-only" id="invoiceActionsMobile">
                    <summary class="action-bar-dropdown-toggle d-inline-flex align-items-center justify-content-center rounded">
                        <i class="fas fa-bars"></i> Actions
                    </summary>
                    <div class="order-actions-mobile-panel">
                        <?php if ($order['status'] === 'draft'): ?>
                        <button type="button" class="order-actions-mobile-item fw-semibold text-success" data-close-invoice-actions="1" onclick="document.getElementById('formOrderMarkSent').requestSubmit();">
                            <i class="fas fa-paper-plane"></i> Mark as Sent
                        </button>
                        <?php endif; ?>
                        <?php if ($order['status'] === 'draft' || $order['status'] === 'quotation'): ?>
                        <button type="button" class="order-actions-mobile-item fw-semibold" style="color:#008784;" data-close-invoice-actions="1" onclick="document.getElementById('formOrderConfirm').requestSubmit();">
                            <i class="fas fa-check-circle"></i> Confirm Order
                        </button>
                        <?php endif; ?>
                        <?php if ($orderShowInvoice): ?>
                        <a class="order-actions-mobile-item fw-semibold" style="color:#008784;" data-close-invoice-actions="1" href="<?php echo htmlspecialchars($orderCreateInvoiceUrl, ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fas fa-file-invoice-dollar"></i> Invoice
                        </a>
                        <?php endif; ?>

                        <a class="order-actions-mobile-item" data-close-invoice-actions="1" href="edit.php?id=<?php echo (int) $order['id']; ?>">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <button type="button" class="order-actions-mobile-item" id="actionMobileDownloadPdf" data-close-invoice-actions="1">
                            <i class="fa-solid fa-cloud-arrow-down"></i> Download PDF
                        </button>
                        <a class="order-actions-mobile-item" data-close-invoice-actions="1" href="<?php echo htmlspecialchars($orderProductsUrl); ?>">
                            <i class="fas fa-images"></i> View Products
                        </a>

                        <?php if ($orderSharePhone): ?>
                        <a class="order-actions-mobile-item text-success" data-close-invoice-actions="1" target="_blank" href="https://wa.me/<?php echo $orderSharePhone; ?>?text=<?php echo urlencode($wa_body ?? ''); ?>">
                            <i class="fab fa-whatsapp"></i> WhatsApp
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($order['email'])): ?>
                        <a class="order-actions-mobile-item text-primary" data-close-invoice-actions="1" href="../send_doc.php?type=order&id=<?php echo (int) $order['id']; ?>" onclick="confirmEmailSend(event, this.href, '<?php echo htmlspecialchars($order['email'], ENT_QUOTES); ?>');">
                            <i class="fas fa-envelope"></i> Email
                        </a>
                        <?php endif; ?>
                        <?php if (!in_array($order['status'], ['cancelled', 'delivered', 'invoiced', 'paid'], true)): ?>
                        <button type="button" class="order-actions-mobile-item text-danger" data-close-invoice-actions="1" onclick="confirmCancel('cancelForm');">
                            <i class="fas fa-times-circle"></i> Cancel Order
                        </button>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        </div>

        <!-- 3. The Sheet -->
        <div id="order-content">
        <?php 
        $layoutPath = function_exists('sales_branded_document_layout_inner_path')
            ? sales_branded_document_layout_inner_path($isTruckOrder)
            : null;
        if ($layoutPath !== null && file_exists($layoutPath)) {
            $invoice = $order;
            $currency = $order['currency'] ?? ($company_settings['default_currency'] ?? 'TZS');
            include $layoutPath;
        } else {
            $displayOrderNumber = $documentDisplayNumber;
            include __DIR__ . '/view_sheet_inner.php';
        }
        ?>
        </div> <!-- End #order-content -->
        
        <!-- Product Images and Descriptions Page -->
        <?php
        $orderShowCatalogue = !empty($items) && (
            !empty($company_settings['include_catalogue'])
            || (int) ($company_settings['include_product_catalogue'] ?? 0) === 1
        );
        ?>
        <?php if ($orderShowCatalogue): ?>
            <div id="catalog-content" class="sheet-container" style="margin-top: 20px;">
                <div style="display: flex; flex-direction: column; min-height: 270mm;">
                    <!-- Header -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                        <h1 class="sheet-title" style="font-size: 18pt;">
                            Product Catalog
                            <small style="font-size: 10pt; font-weight: normal; color: #666;">- Order #<?php echo htmlspecialchars($displayOrderNumber); ?></small>
                        </h1>
                        <div style="text-align: right; width: 60%;">
                            <?php 
                                $logoPath = !empty($company_settings['company_logo_url']) ? $company_settings['company_logo_url'] : '/assets/images/' . ($company_settings['company_logo'] ?: 'Untitled.jpg');
                            ?>
                            <img src="<?php echo $logoPath; ?>" alt="Company Logo" style="max-height: 60px; margin-bottom: 8px;" onerror="this.style.display='none'">
                            <div style="font-weight: bold; font-size: 0.9rem; color: #111; text-transform: uppercase;">
                                <?php echo htmlspecialchars($company_settings['company_name']); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Product Images and Descriptions - Large Size -->
                    <?php 
                    // Use app_url() so image paths respect the current APP_BASE_PATH.
                    ?>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                        <thead>
                            <tr style="background-color: #008784; color: #ffffff;">
                                <th style="padding: 12px; text-align: left; width: 15%; border: 1px solid #008784;">Image</th>
                                <th style="padding: 12px; text-align: left; width: 55%; border: 1px solid #008784;">Product Details</th>
                                <th style="padding: 12px; text-align: right; width: 30%; border: 1px solid #008784;">Pricing</th>
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
                                            : (function_exists('sales_product_image_url')
                                                ? sales_product_image_url((int) $item['product_id'], (string) ($item['main_image'] ?? ''), 'medium')
                                                : app_url('/stock/product_image.php?product_id=' . (int) $item['product_id'] . '&size=medium&file=' . rawurlencode((string) ($item['main_image'] ?? ''))));
                                    ?>
                                        <img src="<?php echo htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8'); ?>" 
                                             alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                             style="width: 100px; height: 100px; object-fit: contain; border: 1px solid #eee; border-radius: 4px; padding: 2px;"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                        <div style="display: none; color: #ccc; font-size: 2rem; display: flex; align-items: center; justify-content: center; height: 100px; width: 100px; border: 1px solid #eee; border-radius: 4px; margin: 0 auto;">
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
                                    <div style="font-size: 12pt; font-weight: bold; color: #111; margin-bottom: 5px;">
                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                    </div>
                                    <?php if (!empty($item['product_code'])): ?>
                                        <div style="font-size: 9pt; color: #666; margin-bottom: 8px; font-family: 'Courier New', monospace;">
                                            <?php echo htmlspecialchars($item['product_code']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div style="font-size: 10pt; color: #444; line-height: 1.5;">
                                        <?php 
                                        $description = !empty($item['description']) ? $item['description'] : ($item['product_description'] ?? '');
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
                 // Optional: Clean URL
                 const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + "?id=" + urlParams.get('id');
                 window.history.replaceState({path: newUrl}, '', newUrl);
             }
        });

        function orderPdfJsPdfCtor() {
            return (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
        }

        function orderPdfCaptureElements() {
            const wrap = document.getElementById('order-content');
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

        async function orderPdfElementToCanvas(el) {
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

        async function appendOrderElementsToPdf(doc, elements, jpegQuality) {
            let hasPages = false;
            for (const el of elements) {
                if (!el) {
                    continue;
                }
                if (hasPages) {
                    doc.addPage();
                }
                const canvas = await orderPdfElementToCanvas(el);
                appendRasterCanvasToPdf(doc, canvas, jpegQuality);
                hasPages = true;
            }
        }

        function confirmEmailSend(event, url, email) {
            event.preventDefault();
            Swal.fire({
                title: 'Send Email?',
                text: "Send this document to " + email + " via email?",
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
            // Show loading
            Swal.fire({
                title: 'Generating PDF...',
                text: 'Please wait while we prepare the attachment.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            try {
                const JsPDF = orderPdfJsPdfCtor();
                const doc = new JsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });

                await appendOrderElementsToPdf(doc, orderPdfCaptureElements(), 0.93);

                const catalogElement = document.getElementById('catalog-content');
                if (catalogElement) {
                    doc.addPage();
                    const catalogCanvas = await orderPdfElementToCanvas(catalogElement);
                    appendRasterCanvasToPdf(doc, catalogCanvas, 0.93);
                }

                const pdfData = doc.output('datauristring');

                // Create a temporary form to submit
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
                const JsPDF = orderPdfJsPdfCtor();
                const doc = new JsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });

                await appendOrderElementsToPdf(doc, orderPdfCaptureElements(), 0.93);

                const catalogElement = document.getElementById('catalog-content');
                if (catalogElement) {
                    doc.addPage();
                    const catalogCanvas = await orderPdfElementToCanvas(catalogElement);
                    appendRasterCanvasToPdf(doc, catalogCanvas, 0.93);
                }
                
                clearInterval(progressInterval);
                dlText.innerText = `100% Done`;
                
                // Trigger Download
                doc.save(`Order_<?php echo $displayOrderNumber; ?>.pdf`);

                // Finish State
                dlBtn.classList.remove('loading');
                dlBtn.classList.add('success');
                dlIcon.className = "fa-solid fa-circle-check";
                dlText.innerText = "Order Downloaded";
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

        function confirmCancel(formId) {
            Swal.fire({
                title: 'Cancel Order?',
                text: "Are you sure you want to cancel this order?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, Cancel it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(formId).submit();
                }
            });
        }
    </script>
</body>
</html>

