<?php
if (!defined('PO_VIEW_ROUTER')) {
// session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';
require_once __DIR__ . '/../../../includes/mailer.php';

requireLogin();

$company_id = function_exists('stockPurchaseActiveCompanyId') ? stockPurchaseActiveCompanyId() : (int) (currentCompanyId() ?? 0);

// --- DEBUG LOGGING ---
$logFile = __DIR__ . '/view_po_debug.log';
$debugMsg = "[" . date('Y-m-d H:i:s') . "] Request: " . $_SERVER['REQUEST_METHOD'] . "\n";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debugMsg .= "Action: " . ($_POST['action'] ?? 'None') . "\n";
    $debugMsg .= "POST Keys: " . implode(', ', array_keys($_POST)) . "\n";
    if (isset($_POST['pdf_base64'])) {
        $debugMsg .= "PDF Base64 Length: " . strlen($_POST['pdf_base64']) . "\n";
    }
}
$debugMsg .= "Creating/Updating log file...\n--------------------------\n";
file_put_contents($logFile, $debugMsg, FILE_APPEND);
// ---------------------

// Add GLOBAL JS ERROR HANDLER
?>
<script>
window.onerror = function(message, source, lineno, colno, error) {
    alert('JS Error: ' + message + '\nAt: ' + source + ':' + lineno);
    return false;
};
window.addEventListener('unhandledrejection', function(event) {
    alert('Unhandled Promise Rejection: ' + event.reason);
});
</script>
<?php

// --- DEBUG LOGGING ---
$logFile = __DIR__ . '/view_po_debug.log';
$debugMsg = "[" . date('Y-m-d H:i:s') . "] Request: " . $_SERVER['REQUEST_METHOD'] . "\n";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debugMsg .= "Action: " . ($_POST['action'] ?? 'None') . "\n";
    $debugMsg .= "POST Keys: " . implode(', ', array_keys($_POST)) . "\n";
    if (isset($_POST['pdf_base64'])) {
        $debugMsg .= "PDF Base64 Length: " . strlen($_POST['pdf_base64']) . "\n";
    }
}
$debugMsg .= "Creating/Updating log file...\n--------------------------\n";
file_put_contents($logFile, $debugMsg, FILE_APPEND);
// ---------------------

if (!isset($_GET['id'])) {
    redirect('index.php');
}
$id = (int) $_GET['id'];
if ($id <= 0) {
    redirect('index.php');
}

// Check for POST Max Size Violation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $maxSize = ini_get('post_max_size');
    flash('error', "Submission failed! The file size exceeds the server limit of $maxSize. Try sending without the PDF attachment.", 'error');
    redirect('view_po.php?id=' . $id);
}
} else {
    if (!isset($id) || (int) $id <= 0) {
        $id = poViewParseId($_GET);
    }
    if (!isset($company_id)) {
        $company_id = function_exists('stockPurchaseActiveCompanyId') ? stockPurchaseActiveCompanyId() : (int) (currentCompanyId() ?? 0);
    }
}

ensureStocksPurchaseOrdersWorkflowColumns($pdo);
ensurePurchaseWorkflowSchema($pdo);

// Products image columns differ across installs (image vs main_image).
$productCols = [];
try {
    $productCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $productCols = [];
}
$productImageCol = null;
if (in_array('image', $productCols, true)) {
    $productImageCol = 'image';
} elseif (in_array('main_image', $productCols, true)) {
    $productImageCol = 'main_image';
}

/**
 * Resolve a product image URL (works under /stock/ and /ultimate/stock/ aliases).
 */
function resolveProductImageUrl(int $productId, string $imageValue): ?string
{
    if ($productId <= 0) {
        return null;
    }

    $imageValue = trim($imageValue);
    if ($imageValue !== '' && preg_match('~^https?://~i', $imageValue)) {
        return $imageValue;
    }

    if (function_exists('stock_product_list_image_url')) {
        global $stockBasePath;
        $url = stock_product_list_image_url($productId, $imageValue, 'medium', (string) ($stockBasePath ?? ''));
        if ($url !== '') {
            return $url;
        }
    }

    $params = ['product_id' => $productId, 'size' => 'medium'];
    if ($imageValue !== '') {
        $params['file'] = basename(str_replace('\\', '/', $imageValue));
    }
    $query = http_build_query($params);
    global $stockBasePath;
    if (!empty($stockBasePath)) {
        return rtrim((string) $stockBasePath, '/') . '/product_image.php?' . $query;
    }

    return function_exists('app_url')
        ? app_url('stock/product_image.php?' . $query)
        : '/stock/product_image.php?' . $query;
}

$isLegacyPurchase = false;
$po = stockPurchaseLoadPoForView($pdo, $id, $company_id);

if (!$po) {
    http_response_code(404);
    $page_title = 'Purchase Order not found';
    include __DIR__ . '/../../includes/header.php';
    ?>
    <main class="main-content">
        <div class="stock-container">
            <div class="alert alert-warning shadow-sm">
                <strong>Purchase Order not found.</strong><br>
                It may have been deleted or you may not have access. (ID: <?php echo (int) $id; ?>)
            </div>
            <a class="btn btn-secondary" href="index.php"><i class="fas fa-arrow-left me-2"></i>Back to purchase orders</a>
        </div>
    </main>
    <?php
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$isLegacyPurchase = ($po['_po_table'] ?? 'stocks_purchase_orders') === 'purchases';

if (!defined('PO_VIEW_ROUTER')) {
// One-time fix for older POs: recalculate and persist tax/totals.
// Usage: view_po.php?id=6&recalc_tax=18
if (isset($_GET['recalc_tax']) && empty($isLegacyPurchase)) {
    $may = function_exists('hasRole') ? (hasRole('admin') || hasRole('procurement')) : true;
    if ($may) {
        $taxPct = (float) ($_GET['recalc_tax'] ?? 0);
        if ($taxPct < 0) $taxPct = 0;
        if ($taxPct > 100) $taxPct = 100;
        try {
            $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $canTaxPct = in_array('tax_percentage', $poCols, true);
            $canTaxAmt = in_array('tax_amount', $poCols, true);
            $canSubtotal = in_array('subtotal', $poCols, true);
            $canTotal = in_array('total_amount', $poCols, true);

            if ($canTaxPct || $canTaxAmt || $canSubtotal || $canTotal) {
                // Subtotal from line items (stored in base currency, typically USD).
                $stmtSub = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(qty_ordered,0) * COALESCE(unit_cost,0)), 0) FROM stocks_po_items WHERE po_id = ?");
                $stmtSub->execute([$id]);
                $sub = (float) ($stmtSub->fetchColumn() ?? 0);
                $taxAmt = $sub * ($taxPct / 100.0);
                $grand = $sub + $taxAmt;

                $sets = [];
                $vals = [];
                if ($canTaxPct) { $sets[] = 'tax_percentage = ?'; $vals[] = $taxPct; }
                if ($canTaxAmt) { $sets[] = 'tax_amount = ?'; $vals[] = $taxAmt; }
                if ($canSubtotal) { $sets[] = 'subtotal = ?'; $vals[] = $sub; }
                if ($canTotal) { $sets[] = 'total_amount = ?'; $vals[] = $grand; }
                if (in_array('updated_at', $poCols, true)) { $sets[] = 'updated_at = NOW()'; }

                if (!empty($sets)) {
                    $vals[] = $id;
                    $pdo->prepare("UPDATE stocks_purchase_orders SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
                }
            }
        } catch (Throwable $e) {
            // ignore; fallback display still works
        }
    }
    // Always redirect to clean URL after applying.
    redirect('view_po.php?id=' . $id);
}

// Repair supplier when it no longer matches the linked payment voucher payee.
// Usage: view_po.php?id=75&sync_supplier=1
if (isset($_GET['sync_supplier'])) {
    $may = function_exists('hasRole') ? (hasRole('admin') || hasRole('procurement')) : true;
    if ($may && function_exists('stockPurchaseSyncPoSupplierFromVouchers')) {
        $sync = stockPurchaseSyncPoSupplierFromVouchers($pdo, $id, $company_id);
        if (!empty($sync['changed'])) {
            flash('success', (string) ($sync['message'] ?? 'Supplier updated.'));
        } elseif (!($sync['ok'] ?? false)) {
            flash('success', (string) ($sync['message'] ?? 'Could not update supplier.'), 'error');
        } else {
            flash('success', (string) ($sync['message'] ?? 'Supplier already matches the voucher.'));
        }
    }
    redirect('view_po.php?id=' . $id);
}
}

if (empty($po['public_token'])) {
    $token = bin2hex(random_bytes(16));
    try {
        $pdo->prepare('UPDATE stocks_purchase_orders SET public_token = ? WHERE id = ?')->execute([$token, $id]);
        $po['public_token'] = $token;
    } catch (Throwable $e) {
        $po['public_token'] = $token;
    }
}

$company = resolveStockPurchaseCompanyProfile($pdo, $company_id);
$poCompanyId = resolveStockPurchaseCompanyIdForProfile($company_id);
$companyName = trim((string) ($company['company_name'] ?? ''));
if ($companyName === '') {
    $companyName = 'Company';
}

// Fetch Current User Signature
$userSignature = '';
$userFullName = '';
try {
    $stmtUser = $pdo->prepare('SELECT full_name, signature_path FROM users WHERE id = ?');
    $stmtUser->execute([$_SESSION['user_id'] ?? 0]);
    $currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($currentUser) {
        $userSignature = $currentUser['signature_path'] ?? '';
        $userFullName = $currentUser['full_name'] ?? '';
    }
} catch (Throwable $e) {
}

require_once __DIR__ . '/../../config/paths.php';

$companyLogoUrl = function_exists('getCompanyLogoUrl')
    ? (string) getCompanyLogoUrl($poCompanyId > 0 ? $poCompanyId : null)
    : '';
if ($companyLogoUrl === '' && function_exists('getCompanySetting')) {
    $companyLogoUrl = mediaUrlFromPath(getCompanySetting('company_logo', ''));
}
if ($companyLogoUrl === '' && !empty($company['logo'])) {
    $companyLogoUrl = mediaUrlFromPath((string) $company['logo']);
}
if ($companyLogoUrl === '') {
    $companyLogoUrl = app_url('/assets/images/Untitled.jpg');
}

$userSignatureUrl = !empty($userSignature) ? mediaUrlFromPath($userSignature) : '';
$noImageUrl = !empty($stockBasePath)
    ? rtrim((string) $stockBasePath, '/') . '/assets/images/no-image.png'
    : (function_exists('app_url') ? app_url('/stock/assets/images/no-image.png') : '/stock/assets/images/no-image.png');

$invoiceViewUrl = '';
$invoiceDownloadUrl = '';
if (!empty($po['invoice_attachment'])) {
    $invoiceViewUrl = 'download_invoice.php?id=' . $id;
    $invoiceDownloadUrl = 'download_invoice.php?id=' . $id . '&download=1';
}

$supplierVoucherMismatch = null;
$canSyncSupplierFromVoucher = function_exists('hasRole') ? (hasRole('admin') || hasRole('procurement')) : true;
if (function_exists('stockPurchaseDetectSupplierVoucherMismatch')) {
    $supplierVoucherMismatch = stockPurchaseDetectSupplierVoucherMismatch($po, $company_id, $pdo);
}

// Fetch Email History (if email_logs table exists)
$emailHistory = [];
try {
    $stmtEmails = $pdo->prepare("SELECT el.*, u.full_name as sent_by_name FROM email_logs el LEFT JOIN users u ON el.sent_by = u.id WHERE el.purchase_id = ? ORDER BY el.sent_at DESC");
    $stmtEmails->execute([$id]);
    $emailHistory = $stmtEmails->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table may not exist
}

// Fetch attachments (if stocks_purchase_attachments exists)
$poAttachments = [];
try {
    $hasAttach = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_purchase_attachments'")->fetchColumn();
} catch (Throwable $e) {
    $hasAttach = false;
}
if (!empty($hasAttach)) {
    try {
        $stmtA = $pdo->prepare("SELECT * FROM stocks_purchase_attachments WHERE purchase_id = ? ORDER BY id DESC");
        $stmtA->execute([$id]);
        $poAttachments = $stmtA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $poAttachments = [];
    }
}

// Line items (stock PO) + optional last approved unit cost per catalogue item
$items = [];
$linesSubtotalUsd = 0.0;
try {
    if (!empty($isLegacyPurchase)) {
        $stmtItems = $pdo->prepare(
            'SELECT
                pi.id,
                pi.product_id AS product_id,
                pi.quantity AS quantity,
                pi.unit_price AS unit_price,
                (pi.quantity * pi.unit_price) AS total_amount,
                pr.name AS product_name,
                pr.product_code AS product_code,
                pr.description AS product_desc,
                ' . ($productImageCol ? ('pr.`' . $productImageCol . '`') : 'NULL') . ' AS product_image,
                NULL AS last_price
            FROM purchase_items pi
            LEFT JOIN products pr ON pr.id = pi.product_id
            WHERE pi.purchase_id = ?
            ORDER BY pi.id ASC'
        );
        $stmtItems->execute([$id]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($items as $row) {
            $linesSubtotalUsd += (float) ($row['total_amount'] ?? 0);
        }
    } else {
        $stmtItems = $pdo->prepare(
            'SELECT pi.id, pi.item_id AS product_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price,
                (pi.qty_ordered * pi.unit_cost) AS total_amount,
                si.name AS product_name, si.sku AS product_code, si.description AS product_desc,
                ' . ($productImageCol ? ("pimg.`$productImageCol` AS product_image, pimg.id AS image_product_id,") : "NULL AS product_image, NULL AS image_product_id,") . '
                (SELECT pi2.unit_cost FROM stocks_po_items pi2
                    INNER JOIN stocks_purchase_orders p2 ON pi2.po_id = p2.id
                    WHERE pi2.item_id = pi.item_id AND p2.status = \'Approved\' AND p2.id < ?
                    ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1) AS last_price
             FROM stocks_po_items pi
             INNER JOIN stocks_items si ON si.id = pi.item_id
             ' . ($productImageCol ? "
             LEFT JOIN products pimg
               ON (LOWER(TRIM(pimg.name)) = LOWER(TRIM(si.name)))
               OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(pimg.product_code)) = LOWER(TRIM(si.sku)))
             " : "") . '
             WHERE pi.po_id = ?
             ORDER BY pi.id ASC'
        );
        $stmtItems->execute([$id, $id]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($items as $row) {
            $linesSubtotalUsd += (float) ($row['total_amount'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $items = [];
}

$po['subtotal'] = $linesSubtotalUsd;
$po['discount_percentage'] = isset($po['discount_percentage']) ? (float) $po['discount_percentage'] : 0.0;
$po['discount_amount'] = isset($po['discount_amount']) ? (float) $po['discount_amount'] : 0.0;
if ($po['discount_amount'] <= 0 && $po['discount_percentage'] > 0 && $linesSubtotalUsd > 0) {
    $po['discount_amount'] = $linesSubtotalUsd * ($po['discount_percentage'] / 100.0);
}
$po['net_subtotal'] = max(0, $linesSubtotalUsd - $po['discount_amount']);
$po['tax_amount'] = isset($po['tax_amount']) ? (float) $po['tax_amount'] : 0.0;
$po['tax_percentage'] = isset($po['tax_percentage']) ? (float) $po['tax_percentage'] : 0.0;
if ($po['tax_amount'] <= 0 && $po['tax_percentage'] > 0) {
    $po['tax_amount'] = $po['net_subtotal'] * ($po['tax_percentage'] / 100.0);
}
if ($po['tax_percentage'] <= 0 && $po['tax_amount'] > 0 && $po['net_subtotal'] > 0) {
    $po['tax_percentage'] = round(($po['tax_amount'] / $po['net_subtotal']) * 100, 4);
}
$storedTotalUsd = isset($po['total_amount']) ? (float) $po['total_amount'] : 0.0;
$po['total_amount'] = $storedTotalUsd > 0 ? $storedTotalUsd : ($po['net_subtotal'] + $po['tax_amount']);

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$portalPath = (!empty($stockBasePath) ? rtrim((string) $stockBasePath, '/') : '/stock')
    . '/modules/purchases/supplier_response.php?token='
    . rawurlencode((string) ($po['public_token'] ?? ''));
$portalUrl = $protocol . '://' . $host . $portalPath;

$defaultCurrencyCode = strtoupper(trim((string) ($company['currency'] ?? 'USD')));
if ($defaultCurrencyCode === '') {
    $defaultCurrencyCode = 'USD';
}
$poCurrencyCode = strtoupper(trim((string) ($po['currency'] ?? '')));
if ($poCurrencyCode === '') {
    $poCurrencyCode = $defaultCurrencyCode;
}
$rate = (float) ($po['exchange_rate'] ?? 0);
if ($rate <= 0) {
    $rate = (float) ($company['exchange_rate'] ?? 1);
}
if ($rate <= 0) {
    $rate = 1.0;
}
$currSymbol = getCurrencySymbol($poCurrencyCode);
$poUsesNativeCurrencyStorage = function_exists('stock_po_uses_native_currency_storage')
    ? stock_po_uses_native_currency_storage($poCurrencyCode, $rate)
    : ($poCurrencyCode === 'TZS' && $rate <= 1.01);
$formatPoMoney = static function (float $storedAmount) use ($rate, $currSymbol, $poCurrencyCode, $poUsesNativeCurrencyStorage): string {
    $display = function_exists('stock_po_amount_to_display')
        ? stock_po_amount_to_display($storedAmount, $poCurrencyCode, $rate)
        : ($poUsesNativeCurrencyStorage ? $storedAmount : convertCurrency($storedAmount, $rate));

    return $currSymbol . number_format($display, 2);
};
$formatPoLineMoney = static function (float $storedAmount) use ($rate, $currSymbol, $poCurrencyCode, $poUsesNativeCurrencyStorage): string {
    $display = function_exists('stock_po_amount_to_display')
        ? stock_po_amount_to_display($storedAmount, $poCurrencyCode, $rate)
        : ($poUsesNativeCurrencyStorage ? $storedAmount : convertCurrency($storedAmount, $rate));

    return $currSymbol . number_format($display, 2);
};

if (!defined('PO_VIEW_ROUTER')) {
// Handle Email Sending
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_email') {
    $to = trim($_POST['recipient_email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body = $_POST['message'] ?? '';
    
    if (empty($to) || empty($subject)) {
        flash('error', 'Recipient email and subject are required.', 'error');
        header("Location: view_po.php?id=$id");
        exit;
    }
    
    // Handle Attachments
    $attachments = [];
    if (isset($_POST['pdf_base64']) && !empty($_POST['pdf_base64'])) {
        $pdfData = $_POST['pdf_base64'];
        if (strpos($pdfData, 'base64,') !== false) {
            $pdfData = explode('base64,', $pdfData)[1];
        }
        $attachments[] = [
            'content' => base64_decode($pdfData),
            'name' => 'Purchase_Order_' . $po['purchase_no'] . '.pdf',
            'type' => 'application/pdf'
        ];
    }

    if (sendEmail($to, $subject, $body, true, $attachments, 'purchases')) {
        try {
            $stmtLog = $pdo->prepare("INSERT INTO email_logs (purchase_id, recipient_email, subject, sent_by) VALUES (?, ?, ?, ?)");
            $stmtLog->execute([$id, $to, $subject, $_SESSION['user_id']]);
        } catch (PDOException $e) {
            // Log table may not exist; email was still sent
        }
        try {
            $pdo->prepare('UPDATE stocks_purchase_orders SET emailed_to = ?, emailed_at = NOW(), emailed_by = ? WHERE id = ?')->execute([$to, $_SESSION['user_id'] ?? null, $id]);
        } catch (Throwable $e) {
        }
        flash('success', 'Purchase Order sent successfully to ' . htmlspecialchars($to));
        header("Location: view_po.php?id=$id&emailed=1");
    } else {
        flash('error', 'Failed to send email. Check SMTP settings or recipient address.', 'error');
        header("Location: view_po.php?id=$id&email_failed=1");
    }
    exit;
}

// Supplier-link workflow: release Draft â†’ Pending Supplier + email portal link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_supplier') {
    if (!isSupplierLinkWorkflow($po['procurement_workflow'] ?? '') || ($po['status'] ?? '') !== PURCHASE_STATUS_DRAFT) {
        flash('success', 'Send to supplier is only available for draft supplier-link orders.', 'error');
        redirect('view_po.php?id=' . $id);
    }
    try {
        if (empty($po['public_token'])) {
            $tk = bin2hex(random_bytes(16));
            $pdo->prepare('UPDATE stocks_purchase_orders SET public_token = ? WHERE id = ?')->execute([$tk, $id]);
            $po['public_token'] = $tk;
        }
        $pdo->prepare('UPDATE stocks_purchase_orders SET status = ?, sent_to_supplier_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([PURCHASE_STATUS_PENDING_SUPPLIER, $id]);
        require_once 'po_mailer.php';
        sendPOStatusEmail($id, 'request_quote', $pdo);
        flash('success', 'Secure link emailed to the supplier. Status is now Pending supplier.');
    } catch (Throwable $e) {
        flash('success', 'Could not send to supplier: ' . $e->getMessage(), 'error');
    }
    redirect('view_po.php?id=' . $id);
}

// Handle Accept Quote
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'accept_quote') {
    $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Approved', updated_at = NOW() WHERE id = ?")->execute([$id]);
    
    require_once 'po_mailer.php';
    sendPOStatusEmail($id, 'approved', $pdo);
    
    flash('success', 'Supplier Quote Accepted! PO Approved.');
    redirect("view_po.php?id=$id&order_approved=true");
}

// Handle Request Negotiation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'request_negotiation') {
    $notes = trim($_POST['negotiation_notes']);
    $poCols = [];
    try {
        $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $poCols = [];
    }
    if (in_array('updated_at', $poCols, true)) {
        $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Negotiation Requested', negotiation_notes = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$notes, $id]);
    } else {
        $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Negotiation Requested', negotiation_notes = ? WHERE id = ?")
            ->execute([$notes, $id]);
    }
    
    require_once 'po_mailer.php';
    sendPOStatusEmail($id, 'negotiation_requested', $pdo);
    
    flash('success', 'Negotiation requested. Email sent to supplier.');
    redirect("view_po.php?id=$id&negotiation_sent=true");
}

// Handle Internal Invoice Upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'upload_invoice') {
    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $filename = $_FILES['invoice_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
             $uploadDir = '../../uploads/invoices/' . $id . '/';
             if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
             
             $newFilename = 'invoice_' . date('Ymd_His') . '_internal.' . $ext;
             $dest = $uploadDir . $newFilename;
             
             if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $dest)) {
                 $dbPath = "uploads/invoices/" . $id . "/" . $newFilename;
                 
                 $poCols = [];
                 try {
                     $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                 } catch (Throwable $e) {
                     $poCols = [];
                 }
                 if (in_array('updated_at', $poCols, true)) {
                     $pdo->prepare('UPDATE stocks_purchase_orders SET invoice_attachment = ?, updated_at = NOW() WHERE id = ?')
                         ->execute([$dbPath, $id]);
                 } else {
                     $pdo->prepare('UPDATE stocks_purchase_orders SET invoice_attachment = ? WHERE id = ?')
                         ->execute([$dbPath, $id]);
                 }
                 
                 flash('success', 'Invoice uploaded successfully.');
             } else {
                 flash('error', 'Failed to move uploaded file.');
             }
        } else {
            flash('error', 'Invalid file type. Only PDF and Images allowed.');
        }
    } else {
        flash('error', 'No file uploaded or upload error.');
    }
    redirect("view_po.php?id=$id");
}
}

$page_title = 'Purchase Order #' . $po['purchase_no'];
include __DIR__ . '/../../includes/header.php';
?>

<style>
    @media print {
        .no-print { display: none !important; }
        .stock-container { width: 100% !important; max-width: 100% !important; margin: 0; padding: 0; }
        body { background: white; -webkit-print-color-adjust: exact; }
        .card { border: none !important; shadow: none !important; }
        .main-content { margin: 0; padding: 0; }
        footer { display: none; }
    }
    .po-doc { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; color: #333; }
    .table-po thead th { background-color: #fde047 !important; color: #1a1a1a !important; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; border-color: #eab308; padding: 8px 10px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .table-po tbody td { border-color: #dee2e6; vertical-align: middle; font-size: 0.85rem; padding: 6px 10px; }
    .table-po tfoot td, .table-po tfoot tr { border: 0px none !important; border-top: 0px none !important; border-bottom: 0px none !important; box-shadow: none !important; }
    .company-logo { max-height: 80px; }
    .action-icon {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s;
        text-decoration: none !important;
        background: #fff;
        border: 1px solid #dee2e6;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .action-icon:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        background-color: #f8f9fa;
    }
    .action-icon.whatsapp { color: #25D366; }
    .action-icon.print { color: #333; }
    .action-icon.pdf-download { color: #dc3545; }
    .action-icon.email { color: #0d6efd; }
    .action-icon.link { color: #0dcaf0; }
    .action-icon.back { color: #6c757d; }
    .action-icon.approve { color: #198754; }
    .action-icon.upload { color: #fd7e14; }
    /* Email Modal */
    .email-modal-icon {
        width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #e7f1ff;
        color: #0d6efd;
        border-radius: 10px;
    }
    #emailModal .form-control-lg { font-size: 1rem; }
</style>

<main class="main-content">
    <div class="stock-container">
        
        <?php 
        flash('success'); 
        flash('error'); 
        if (isset($_GET['emailed']) && $_GET['emailed'] == '1'): 
            $emailedTo = $po['emailed_to'] ?? 'recipient';
        ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>Purchase Order sent successfully to <?php echo htmlspecialchars($emailedTo); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <script>if (window.history.replaceState) window.history.replaceState({}, '', 'view_po.php?id=<?php echo (int)$id; ?>');</script>
        <?php 
        elseif (isset($_GET['email_failed']) && $_GET['email_failed'] == '1'): 
        ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>Failed to send email. Check SMTP settings or recipient address.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <script>if (window.history.replaceState) window.history.replaceState({}, '', 'view_po.php?id=<?php echo (int)$id; ?>');</script>
        <?php endif; ?>

        <?php if ($supplierVoucherMismatch && $canSyncSupplierFromVoucher): ?>
            <div class="alert alert-warning shadow-sm mb-4 no-print d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <strong><i class="fas fa-exclamation-triangle me-2"></i>Supplier mismatch</strong>
                    <span class="d-block small mb-0 mt-1">
                        This PO shows <strong><?php echo htmlspecialchars($supplierVoucherMismatch['supplier_name']); ?></strong>,
                        but the linked payment voucher payee is <strong><?php echo htmlspecialchars($supplierVoucherMismatch['payee_name']); ?></strong>.
                    </span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="view_po.php?id=<?php echo (int) $id; ?>&sync_supplier=1" class="btn btn-warning fw-bold"
                       onclick="return confirm('Update this PO supplier to match the linked voucher payee?');">
                        <i class="fas fa-sync me-2"></i>Fix supplier from voucher
                    </a>
                    <a href="edit.php?id=<?php echo (int) $id; ?>" class="btn btn-outline-secondary">Edit PO</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isSupplierLinkWorkflow($po['procurement_workflow'] ?? '') && ($po['status'] ?? '') === PURCHASE_STATUS_DRAFT): ?>
            <div class="alert alert-secondary border shadow-sm mb-4 no-print d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <strong><i class="fas fa-file-alt me-2"></i>Draft (supplier link workflow)</strong>
                    <span class="d-block small text-muted mb-0">Review lines and supplier, then send the secure portal link. The supplier cannot open the link until you release this PO.</span>
                </div>
                <form method="post" class="m-0" onsubmit="return confirm('Email the supplier the quote request with portal link?');">
                    <input type="hidden" name="action" value="send_to_supplier">
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="fas fa-paper-plane me-2"></i>Send to supplier
                    </button>
                </form>
            </div>
        <?php endif; ?>
        
        <!-- Actions Toolbar -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <a href="index.php" class="action-icon back shadow-sm" title="Back to List">
                <i class="fas fa-arrow-left"></i>
            </a>
            
            <div class="d-flex gap-3 align-items-center">
                <?php
                    $hideApprove = ($po['status'] === 'Approved')
                        || in_array($po['status'] ?? '', [PURCHASE_STATUS_DRAFT, PURCHASE_STATUS_PENDING_SUPPLIER], true);
                ?>
                <?php if (!$hideApprove): ?>
                    <a href="javascript:void(0)" class="action-icon approve shadow-sm" title="Approve PO" onclick="confirmApprove(<?php echo $id; ?>)">
                        <i class="fas fa-check"></i>
                    </a>
                <?php endif; ?>
                <?php
                    // Clean phone number for WhatsApp
                    $wa_phone = preg_replace('/[^0-9]/', '', (string) ($po['supplier_phone'] ?? ''));
                    // Removed Price from Message
                    $wa_msg = urlencode("Hi " . (($po['contact_person'] ?? '') ?: 'Supplier') . ",\n\nPurchase Order: " . $po['purchase_no'] . "\n\nPlease see items required in the attached/link.\n\nRegards,\n" . $companyName);
                ?>
                
                <?php if(!empty($wa_phone)): ?>
                    <a href="https://wa.me/<?php echo $wa_phone; ?>?text=<?php echo $wa_msg; ?>" target="_blank" class="action-icon whatsapp shadow-sm" title="WhatsApp Supplier">
                        <i class="fab fa-whatsapp fs-5"></i>
                    </a>
                <?php endif; ?>
                
                <a href="javascript:void(0)" onclick="copyPoLink()" class="action-icon link shadow-sm" title="Copy Portal Link">
                    <i class="fas fa-link"></i>
                </a>
                
                 <!-- Shortcut: Attach Invoice -->
                <a href="javascript:void(0)" class="action-icon upload shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadModal" title="Attach Invoice (Internal)">
                    <i class="fas fa-file-upload"></i>
                </a>

                <a href="javascript:void(0)" onclick="window.print()" class="action-icon print shadow-sm" title="Print">
                    <i class="fas fa-print"></i>
                </a>
                <a href="javascript:void(0)" id="poDownloadPdfBtn" class="action-icon pdf-download shadow-sm" title="Download PDF" onclick="downloadPoPdf()">
                    <i class="fas fa-file-pdf"></i>
                </a>
                <?php
                    $viewPoWf = $po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
                    $canEditPoView = function_exists('purchaseOrderAllEditAccessStatuses')
                        && in_array($po['status'] ?? '', purchaseOrderAllEditAccessStatuses($viewPoWf), true);
                    $poPricesLockedView = function_exists('arePurchaseOrderPricesLocked')
                        && arePurchaseOrderPricesLocked($po['status'] ?? '');
                ?>
                <?php if ($canEditPoView): ?>
                    <a href="edit.php?id=<?php echo (int) $id; ?>" class="action-icon shadow-sm" title="<?php echo $poPricesLockedView ? 'Edit PO (prices locked)' : 'Edit lines'; ?>" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;">
                        <i class="fas fa-edit"></i>
                    </a>
                <?php endif; ?>
                <a href="javascript:void(0)" class="action-icon email shadow-sm" data-bs-toggle="modal" data-bs-target="#emailModal" title="Email Supplier">
                    <i class="fas fa-envelope"></i>
                </a>
            </div>
        </div>

        <!-- Negotiation Share Alert -->
        <?php if(isset($_GET['negotiation_sent'])): ?>
            <div class="alert alert-info shadow-sm text-center mb-4">
                <h4><i class="fas fa-paper-plane"></i> Negotiation Requested!</h4>
                <p>Please share the link with the supplier to speed up the process.</p>
                <div class="d-flex justify-content-center gap-2">
                     <?php if(!empty($wa_phone)): ?>
                        <a href="https://wa.me/<?php echo $wa_phone; ?>?text=<?php echo $wa_msg; ?>" target="_blank" class="btn btn-success"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                    <?php endif; ?>
                    <button onclick="copyPoLink()" class="btn btn-primary"><i class="fas fa-link"></i> Copy Link</button>
                    <button onclick="window.location.href='view_po.php?id=<?php echo $id; ?>'" class="btn btn-secondary">Done</button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Shipment Linkage Block -->
        <?php
            $linkedShipment = false;
            try {
                $stmtLink = $pdo->prepare(
                    'SELECT id, shipment_number, status, tracking_number, eta FROM shipments
                     WHERE stocks_po_id = ? OR purchase_id = ?
                     ORDER BY id DESC LIMIT 1'
                );
                $stmtLink->execute([$id, $id]);
                $linkedShipment = $stmtLink->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                try {
                    $stmtLink = $pdo->prepare(
                        'SELECT id, shipment_number, status, tracking_number, eta FROM shipments
                         WHERE purchase_id = ?
                         ORDER BY id DESC LIMIT 1'
                    );
                    $stmtLink->execute([$id]);
                    $linkedShipment = $stmtLink->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e2) {
                    $linkedShipment = false;
                }
            }
        ?>

        <?php if($po['status'] == 'Approved' && !$linkedShipment): ?>
            <div class="alert alert-warning shadow mb-4 border-warning border-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="alert-heading fw-bold"><i class="fas fa-boxes"></i> Arrange Shipment</h5>
                        <p class="mb-0">This order is Approved. Please create a shipment record to track its delivery.</p>
                    </div>
                    <a href="../shipments/create.php?purchase_id=<?php echo $id; ?>" class="btn btn-warning fw-bold"><i class="fas fa-shipping-fast"></i> Create Shipment</a>
                </div>
            </div>
        <?php elseif($linkedShipment): ?>
             <div class="alert alert-success shadow mb-4 border-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="alert-heading fw-bold"><i class="fas fa-truck"></i> Shipment Linked: <?php echo htmlspecialchars($linkedShipment['shipment_number']); ?></h5>
                        <p class="mb-0">
                            <strong>Status:</strong> <?php echo strtoupper($linkedShipment['status']); ?> | 
                            <strong>Tracking:</strong> <?php echo htmlspecialchars($linkedShipment['tracking_number'] ?? 'NA'); ?> | 
                            <strong>ETA:</strong> <?php echo htmlspecialchars($linkedShipment['eta'] ?? 'NA'); ?>
                        </p>
                    </div>
                    <a href="../shipments/view.php?id=<?php echo $linkedShipment['id']; ?>" class="btn btn-sm btn-outline-success fw-bold">View Shipment</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Supplier Response Review Block -->
        <?php if (in_array($po['status'] ?? '', purchaseAwaitingApprovalStatuses(), true)): ?>
        <div class="card border-primary border-2 shadow-sm mb-4" style="max-width: 900px; margin: 0 auto;">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-exclamation-circle me-2"></i>Supplier Has Responded</h5>
                <span class="badge bg-white text-primary">Action Required</span>
            </div>
            <div class="card-body">
                <div class="row align-items-center mb-3">
                    <div class="col-md-12">
                        <p class="mb-2">The supplier has submitted their quote. Review the comparison below:</p>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Quoted Price</th>
                                        <th class="text-end">Last Price</th>
                                        <th class="text-center">Variance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $hasHighVariance = false;
                                    foreach($items as $item): 
                                        $quoted = $item['unit_price'];
                                        $last = $item['last_price'] ?? 0;
                                        $variance = 0;
                                        $varianceClass = 'text-muted';
                                        $varianceIcon = '<i class="fas fa-minus"></i>';
                                        
                                        if ($last > 0) {
                                            $variance = (($quoted - $last) / $last) * 100;
                                            if ($variance > 10) {
                                                $varianceClass = 'text-danger fw-bold';
                                                $varianceIcon = '<i class="fas fa-arrow-up"></i>';
                                                $hasHighVariance = true;
                                            } elseif ($variance < -10) {
                                                $varianceClass = 'text-success fw-bold';
                                                $varianceIcon = '<i class="fas fa-arrow-down"></i>';
                                            } elseif ($variance > 0) {
                                                $varianceClass = 'text-warning';
                                                $varianceIcon = '<i class="fas fa-arrow-up small"></i>';
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                                        <td class="text-end fw-bold"><?php echo $formatPoMoney((float) $quoted); ?></td>
                                        <td class="text-end text-muted"><?php echo $last > 0 ? $formatPoMoney((float) $last) : 'N/A'; ?></td>
                                        <td class="text-center <?php echo $varianceClass; ?>">
                                            <?php echo $varianceIcon; ?> <?php echo $last > 0 ? number_format(abs($variance), 1) . '%' : '-'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if($hasHighVariance): ?>
                            <div class="alert alert-warning mt-2 mb-0 small py-2">
                                <i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> Some items have a price increase of >10% compared to last purchase.
                            </div>
                        <?php endif; ?>
                        
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <small class="text-muted"><i class="fas fa-clock"></i> Responded: <?php echo $po['supplier_responded_at'] ? date('M d, H:i', strtotime($po['supplier_responded_at'])) : 'Unknown'; ?></small>
                        <?php if ($invoiceViewUrl !== ''): ?>
                             <br><small>
                                <a href="<?php echo htmlspecialchars($invoiceViewUrl); ?>" target="_blank" rel="noopener" class="text-primary fw-bold"><i class="fas fa-paperclip"></i> View Attached Invoice</a>
                                <span class="text-muted mx-1">|</span>
                                <a href="<?php echo htmlspecialchars($invoiceDownloadUrl); ?>" class="text-primary fw-bold"><i class="fas fa-download"></i> Download</a>
                             </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-end">
                        <div class="d-grid gap-2">
                            <form method="POST" onsubmit="return confirm('Are you sure you want to ACCEPT this quote? matches the invoice?');">
                                <input type="hidden" name="action" value="accept_quote">
                                <button type="submit" class="btn btn-success w-100 fw-bold"><i class="fas fa-check me-2"></i> Accept Quote</button>
                            </form>
                            <button type="button" class="btn btn-warning w-100 fw-bold" data-bs-toggle="modal" data-bs-target="#negotiationModal">
                                <i class="fas fa-comments me-2"></i> Negotiate
                            </button>
                            <a href="edit.php?id=<?php echo $po['id']; ?>" class="btn btn-secondary w-100 fw-bold"><i class="fas fa-edit me-2"></i> Adjust / Edit</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow rounded-0 p-5 po-doc" style="max-width: 900px; margin: 0 auto;">
            
            <!-- HEADER -->
            <div class="row mb-5 border-bottom pb-4">
                <div class="col-6">
                     <img src="<?php echo htmlspecialchars($companyLogoUrl); ?>" alt="<?php echo htmlspecialchars($companyName); ?>" class="company-logo"
                          onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(app_url('/assets/images/Untitled.jpg')); ?>';">
                </div>
                <div class="col-6 text-end">
                    <h2 class="fw-bold text-uppercase mb-2 text-dark">Purchase Order</h2>
                    <h5 class="text-muted mb-0"><?php echo $po['purchase_no']; ?></h5>
                </div>
            </div>
            
            <!-- COMPANY & SUPPLIER BLOCK -->
            <div class="row mb-5">
                <div class="col-6">
                    <address class="mb-0 small">
                        <?php
                            $fromCompanyName = trim((string) ($company['company_name'] ?? ''));
                            $fromAddress = trim((string) ($company['address'] ?? ''));
                            $fromLocation = trim((string) ($company['company_location'] ?? $company['city'] ?? ''));
                            $fromCountry = trim((string) ($company['country'] ?? ''));
                            $fromPhone = trim((string) ($company['phone'] ?? ''));
                            $fromEmail = trim((string) ($company['email'] ?? ''));
                            $showLocation = $fromLocation !== ''
                                && strcasecmp($fromLocation, $fromAddress) !== 0;
                            $showCountry = $fromCountry !== ''
                                && stripos($fromAddress, $fromCountry) === false
                                && stripos($fromLocation, $fromCountry) === false;
                        ?>
                        <strong><?php echo htmlspecialchars($fromCompanyName !== '' ? $fromCompanyName : '—'); ?></strong><br>
                        <?php if ($fromAddress !== ''): ?>
                            <?php echo nl2br(htmlspecialchars($fromAddress)); ?><br>
                        <?php endif; ?>
                        <?php if ($showLocation): ?>
                            <?php echo nl2br(htmlspecialchars($fromLocation)); ?><br>
                        <?php endif; ?>
                        <?php if ($showCountry): ?>
                            <?php echo htmlspecialchars($fromCountry); ?><br>
                        <?php endif; ?>
                        <?php if ($fromPhone !== ''): ?>
                            Phone: <?php echo htmlspecialchars($fromPhone); ?><br>
                        <?php endif; ?>
                        <?php if ($fromEmail !== ''): ?>
                            Email: <?php echo htmlspecialchars($fromEmail); ?>
                        <?php endif; ?>
                    </address>
                </div>
                <div class="col-6 text-end">
                    <address class="mb-0 small">
                        <?php
                            $supplierDisplayName = trim((string) ($po['supplier_name'] ?? ''));
                            $supplierAddress = trim((string) ($po['supplier_address'] ?? ''));
                            $supplierContact = trim((string) ($po['contact_person'] ?? ''));
                            $supplierPhone = trim((string) ($po['supplier_phone'] ?? ''));
                            $supplierEmail = trim((string) ($po['supplier_email'] ?? ''));
                            $hasSupplierDetails = $supplierDisplayName !== ''
                                || $supplierContact !== ''
                                || $supplierPhone !== ''
                                || $supplierEmail !== ''
                                || $supplierAddress !== '';
                        ?>
                        <?php if ($hasSupplierDetails): ?>
                            <?php if ($supplierDisplayName !== ''): ?>
                                <strong><?php echo htmlspecialchars($supplierDisplayName); ?></strong><br>
                            <?php endif; ?>
                            <?php if ($supplierContact !== ''): ?>
                                <?php echo htmlspecialchars($supplierContact); ?><br>
                            <?php endif; ?>
                            <?php if ($supplierPhone !== ''): ?>
                                Phone: <?php echo htmlspecialchars($supplierPhone); ?><br>
                            <?php endif; ?>
                            <?php if ($supplierEmail !== ''): ?>
                                Email: <?php echo htmlspecialchars($supplierEmail); ?><br>
                            <?php endif; ?>
                            <?php if ($supplierAddress !== ''): ?>
                                <?php echo nl2br(htmlspecialchars($supplierAddress)); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <strong>—</strong>
                        <?php endif; ?>
                    </address>
                </div>
            </div>
            
            <!-- META INFO -->
            <div class="row mb-3 bg-light p-2 mx-0 rounded-1 border">
                <div class="col-md-3">
                    <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.7rem;">PO Date</small>
                    <span class="fw-bold small"><?php echo date('M d, Y', strtotime($po['created_at'])); ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.7rem;">Payment Terms</small>
                    <span class="fw-bold small"><?php echo htmlspecialchars($company['default_payment_terms'] ?? 'Net 30'); ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.7rem;">Expected Delivery</small>
                    <span class="fw-bold small"><?php echo date('M d, Y', strtotime($po['created_at'] . ' +30 days')); ?></span>
                </div>
                <div class="col-md-3 text-end">
                    <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.7rem;">Currency</small>
                    <span class="fw-bold small"><?php echo htmlspecialchars($poCurrencyCode); ?></span>
                </div>
            </div>
            
            <!-- ITEMS TABLE -->
            <table class="table table-bordered table-po mb-0">
                <thead>
                    <tr>
                        <th style="width: 10%;">Image</th>
                        <th style="width: 45%;">Description</th>
                        <th class="text-center" style="width: 10%;">Qty</th>
                        <th class="text-end price-col" style="width: 15%;">Unit Price</th>
                        <th class="text-end price-col" style="width: 20%;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($items as $item):
                        $pName = $item['product_name'] ?? 'Unknown Item';
                        $pCode = $item['product_code'] ?? '';
                        $placeholderImg = $noImageUrl;
                        $imgUrl = $placeholderImg;
                        $imgFile = (string)($item['product_image'] ?? '');
                        $imgPid = !empty($isLegacyPurchase) ? (int)($item['product_id'] ?? 0) : (int)($item['image_product_id'] ?? 0);

                        $resolved = resolveProductImageUrl($imgPid, $imgFile);
                        if ($resolved) {
                            $imgUrl = $resolved;
                        }
                    ?>
                    <tr>
                        <td class="text-center" style="width: 50px;">
                            <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                                 alt="Img"
                                 style="width: 60px; height: 60px; object-fit: cover; border-radius: 3px;"
                                 onerror="this.src='<?php echo $placeholderImg; ?>'; this.onerror=null;">
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($pName); ?></div>
                            <?php if(!empty($pCode)): ?><small class="text-muted d-block" style="font-size: 0.75rem;"><?php echo htmlspecialchars($pCode); ?></small><?php endif; ?>
                        </td>
                        <td class="text-center fw-bold"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td class="text-end price-col"><?php echo $formatPoLineMoney((float) $item['unit_price']); ?></td>
                        <td class="text-end fw-bold price-col"><?php echo $formatPoLineMoney((float) $item['total_amount']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-end fw-bold price-col-label">Subtotal</td>
                        <td class="text-end fw-bold price-col"><?php echo $formatPoMoney((float) $po['subtotal']); ?></td>
                    </tr>
                    <?php if (($po['discount_amount'] ?? 0) > 0): ?>
                    <tr>
                        <td colspan="4" class="text-end price-col-label">Discount<?php echo ($po['discount_percentage'] ?? 0) > 0 ? ' (' . htmlspecialchars((string) $po['discount_percentage']) . '%)' : ''; ?></td>
                        <td class="text-end price-col">-<?php echo $formatPoMoney((float) $po['discount_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if(($po['tax_amount'] > 0) || ($po['tax_percentage'] > 0)): ?>
                    <tr>
                        <td colspan="4" class="text-end price-col-label">Tax (<?php echo htmlspecialchars((string)($po['tax_percentage'] ?? '0')); ?>%)</td>
                        <td class="text-end price-col"><?php echo $formatPoMoney((float) $po['tax_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td colspan="4" class="text-end fw-bold text-uppercase border-top-2 price-col-label">Grand Total</td>
                        <td class="text-end fw-bold border-top-2 bg-light price-col"><?php echo $formatPoMoney((float) $po['total_amount']); ?></td>
                    </tr>
                </tfoot>
            </table>
            
            <!-- FOOTER Info -->
            <div class="row mt-5">
                <div class="col-12">
                    <h6 class="text-uppercase text-muted fw-bold small ls-1 border-bottom pb-2">Terms & Conditions</h6>
                    <p class="small text-muted" style="white-space: pre-line;"><?php echo htmlspecialchars(!empty($po['terms_conditions']) ? $po['terms_conditions'] : ($company['terms_and_conditions'] ?? '')); ?></p>
                </div>
            </div>
            
            <!-- SIGNATURE -->
            <div class="row mt-5 pt-5">
                <div class="col-6">
                    <div class="position-relative border-top border-dark pt-2 w-75">
                         <?php if ($userSignatureUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($userSignatureUrl); ?>" alt="Signature" 
                                 style="position: absolute; top: -55px; left: 15px; max-height: 70px; mix-blend-mode: multiply;">
                         <?php endif; ?>
                         <p class="mb-0 fw-bold">Authorized Signature</p>
                         <small class="text-muted"><?php echo htmlspecialchars($userFullName ?: 'System User'); ?></small><br>
                         <small class="text-muted">Date: <?php echo date('M d, Y'); ?></small>
                    </div>
                </div>
            </div>
            
            <!-- QR CODE Placeholder -->
             <div class="text-end mt-4 no-print">
                <small class="text-muted text-uppercase d-block mb-1">Scan to Upload Invoice</small>
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($portalUrl); ?>" alt="QR" style="width: 80px;">
                <div class="mt-1 small text-muted">Or visit current URL</div>
            </div>
        </div>
        
        <?php if ($invoiceViewUrl !== ''): ?>
        <div class="text-center mt-3 no-print">
            <div class="alert alert-success d-inline-block">
                <i class="fas fa-file-invoice"></i> Supplier Invoice Attached:
                <a href="<?php echo htmlspecialchars($invoiceViewUrl); ?>" target="_blank" rel="noopener" class="fw-bold text-success">View Invoice</a>
                <span class="text-muted mx-2">|</span>
                <a href="<?php echo htmlspecialchars($invoiceDownloadUrl); ?>" class="fw-bold text-success"><i class="fas fa-download me-1"></i>Download Invoice</a>
            </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Email History -->
        <?php if (!empty($emailHistory) || $po['emailed_at']): ?>
        <div class="card mt-4 no-print">
            <div class="card-header bg-light py-2">
                <h6 class="mb-0"><i class="fas fa-envelope me-2"></i>Email History</h6>
            </div>
            <div class="card-body py-3">
                <?php if (!empty($emailHistory)): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($emailHistory as $log): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <div>
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong><?php echo htmlspecialchars($log['recipient_email']); ?></strong>
                                <span class="text-muted small">â€” <?php echo htmlspecialchars($log['subject']); ?></span>
                            </div>
                            <small class="text-muted">
                                <?php echo $log['sent_at'] ? date('M d, Y H:i', strtotime($log['sent_at'])) : 'â€”'; ?>
                                <?php if (!empty($log['sent_by_name'])): ?>
                                    <br><span class="text-muted">by <?php echo htmlspecialchars($log['sent_by_name']); ?></span>
                                <?php endif; ?>
                            </small>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($po['emailed_at']): ?>
                    <p class="mb-0 text-muted small">
                        <i class="fas fa-check-circle text-success"></i> Last emailed to <?php echo htmlspecialchars($po['emailed_to']); ?> on <?php echo date('M d, Y H:i', strtotime($po['emailed_at'])); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($poAttachments)): ?>
        <div class="card mt-3 no-print">
            <div class="card-header bg-light py-2">
                <h6 class="mb-0"><i class="fas fa-paperclip me-2"></i>Attached documents</h6>
            </div>
            <div class="card-body py-3">
                <ul class="list-group list-group-flush">
                    <?php foreach ($poAttachments as $att): ?>
                        <?php
                            $name = (string) ($att['file_name'] ?? 'Document');
                            $path = (string) ($att['file_path'] ?? '');
                            $size = (int) ($att['file_size'] ?? 0);
                            $type = (string) ($att['file_type'] ?? '');
                            $href = $path !== '' ? ('../../' . ltrim($path, '/')) : '';
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate">
                                    <i class="fas fa-file-alt text-muted me-2"></i><?php echo htmlspecialchars($name); ?>
                                </div>
                                <div class="text-muted small">
                                    <?php if ($type !== ''): ?><?php echo htmlspecialchars($type); ?><?php endif; ?>
                                    <?php if ($type !== '' && $size > 0): ?> â€” <?php endif; ?>
                                    <?php if ($size > 0): ?><?php echo number_format($size / 1024, 1); ?> KB<?php endif; ?>
                                </div>
                            </div>
                            <?php if ($href !== ''): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($href); ?>" target="_blank">
                                    Open
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">â€”</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        
    </div>

<!-- Negotiation Modal -->
<div class="modal fade" id="negotiationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content rounded-0">
             <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-comments me-2"></i>Request Negotiation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="request_negotiation">
                    <div class="alert alert-info small">
                        This will change the status to <strong>Negotiation Requested</strong>. The supplier will see your notes when they revisit the portal.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Negotiation Notes / Counter Offer</label>
                        <textarea name="negotiation_notes" class="form-control rounded-0" rows="5" placeholder="e.g. Can you provide a 5% discount if we increase quantity?" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                     <button type="button" class="btn btn-secondary rounded-0" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning rounded-0 fw-bold"><i class="fas fa-paper-plane"></i> Send Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

</main>

<!-- Email Modal - Redesigned: Input email, click Send -->
<?php 
$emailSubject = "Purchase Order " . $po['purchase_no'] . " â€“ " . htmlspecialchars($companyName) . " â€“ Action Required";
$emailBody = "Dear Supplier,\n\n"
    . "Thank you for your continued partnership. We are pleased to share our Purchase Order for your review and quotation.\n\n"
    . "Please find attached Purchase Order " . $po['purchase_no'] . ".\n\n"
    . "To submit your quote and invoice, kindly access our Supplier Portal using the link below:\n"
    . $portalUrl . "\n\n"
    . "Order Summary:\n"
    . "â€¢ PO Number: " . $po['purchase_no'] . "\n"
    . "â€¢ Date: " . date('d M Y') . "\n\n"
    . "We look forward to receiving your response promptly. Should you have any questions, please do not hesitate to reach out.\n\n"
    . "Kind regards,\n\n"
    . "Procurement Team\n"
    . htmlspecialchars($companyName);
?>
<div class="modal fade" id="emailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3 border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title d-flex align-items-center">
                    <span class="email-modal-icon me-2"><i class="fas fa-envelope"></i></span>
                    Send Purchase Order
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="emailSendForm">
                <input type="hidden" name="action" value="send_email">
                <input type="hidden" name="subject" value="<?php echo htmlspecialchars($emailSubject); ?>">
                <textarea name="message" class="d-none"><?php echo htmlspecialchars($emailBody); ?></textarea>
                <div class="modal-body pt-2">
                    <div class="mb-4">
                        <label class="form-label fw-semibold text-muted small text-uppercase">Recipient Email</label>
                        <input type="email" name="recipient_email" id="recipientEmailInput" 
                               class="form-control form-control-lg rounded-3 border-2" 
                               placeholder="Enter email address" 
                               value="<?php echo htmlspecialchars($po['supplier_email']); ?>" 
                               required 
                               autofocus>
                        <?php if (!empty($po['supplier_email'])): ?>
                        <small class="text-muted">Pre-filled with supplier email. Edit if needed.</small>
                        <?php endif; ?>
                    </div>
                    <div class="email-options border-top pt-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="attach_pdf" checked>
                            <label class="form-check-label" for="attach_pdf">Attach PDF document</label>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="hidePrices" checked>
                            <label class="form-check-label" for="hidePrices">Hide prices in PDF (quantity only)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-semibold" id="emailSendBtn">
                        <i class="fas fa-paper-plane me-2"></i>Send
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Upload Invoice Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content rounded-0">
             <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-file-upload me-2"></i>Attach Supplier Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="upload_invoice">
                    <div class="alert alert-info small">
                        Use this to manually attach an invoice received via WhatsApp or Email. It will replace any existing attachment.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Invoice File (PDF/Image)</label>
                        <input type="file" name="invoice_file" class="form-control rounded-0" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>
                <div class="modal-footer">
                     <button type="button" class="btn btn-secondary rounded-0" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning rounded-0 fw-bold"><i class="fas fa-upload"></i> Upload Invoice</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
const PO_PDF_FILENAME = <?php echo json_encode(
    'Purchase_Order_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($po['purchase_no'] ?? ('PO-' . $id)))
); ?>;

window.jsPDF = window.jspdf.jsPDF;

/**
 * Render .po-doc to a jsPDF document (shared by email attach + download).
 * @param {boolean} hidePrices
 * @param {number} timeoutMs
 * @returns {Promise<import('jspdf').jsPDF>}
 */
async function generatePoPdfDocument(hidePrices, timeoutMs) {
    const element = document.querySelector('.po-doc');
    if (!element) {
        throw new Error('Purchase order document not found on this page.');
    }

    const priceElements = document.querySelectorAll('.price-col, .price-col-label');
    if (hidePrices) {
        priceElements.forEach(function (el) { el.style.display = 'none'; });
    }

    const prevMinHeight = element.style.minHeight;
    const prevHeight = element.style.height;
    element.style.minHeight = '0';
    element.style.height = 'auto';

    try {
        const canvasPromise = html2canvas(element, {
            scale: 1.5,
            useCORS: true,
            allowTaint: true,
            logging: false,
            backgroundColor: '#ffffff',
            height: element.scrollHeight,
            windowHeight: element.scrollHeight
        });
        const timeoutPromise = new Promise(function (_, reject) {
            setTimeout(function () {
                reject(new Error('PDF generation timed out. Try again or use Print.'));
            }, timeoutMs);
        });
        const canvas = await Promise.race([canvasPromise, timeoutPromise]);

        const imgData = canvas.toDataURL('image/jpeg', 0.92);
        const doc = new jsPDF('p', 'mm', 'a4');
        const pdfWidth = doc.internal.pageSize.getWidth();
        const pdfHeight = doc.internal.pageSize.getHeight();
        const imgHeight = (canvas.height * pdfWidth) / canvas.width;

        if (imgHeight <= pdfHeight) {
            doc.addImage(imgData, 'JPEG', 0, 0, pdfWidth, imgHeight);
        } else {
            let heightLeft = imgHeight;
            let position = 0;
            doc.addImage(imgData, 'JPEG', 0, position, pdfWidth, imgHeight);
            heightLeft -= pdfHeight;
            while (heightLeft > 0.5) {
                position = heightLeft - imgHeight;
                doc.addPage();
                doc.addImage(imgData, 'JPEG', 0, position, pdfWidth, imgHeight);
                heightLeft -= pdfHeight;
            }
        }

        return doc;
    } finally {
        element.style.minHeight = prevMinHeight;
        element.style.height = prevHeight;
        if (hidePrices) {
            priceElements.forEach(function (el) { el.style.display = ''; });
        }
    }
}

async function downloadPoPdf() {
    const btn = document.getElementById('poDownloadPdfBtn');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.style.pointerEvents = 'none';
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }

    try {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Preparing PDF…',
                allowOutsideClick: false,
                didOpen: function () { Swal.showLoading(); }
            });
        }

        const doc = await generatePoPdfDocument(false, 20000);
        doc.save(PO_PDF_FILENAME + '.pdf');

        if (typeof Swal !== 'undefined') {
            Swal.close();
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'PDF downloaded',
                showConfirmButton: false,
                timer: 2200
            });
        }
    } catch (err) {
        console.error('PO PDF download error:', err);
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: 'Could not create PDF',
                text: err.message || 'Please try Print, or email the PO with PDF attached.'
            });
        } else {
            alert('Could not create PDF: ' + (err.message || 'Unknown error'));
        }
    } finally {
        if (btn) {
            btn.innerHTML = originalHtml;
            btn.style.pointerEvents = '';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const emailForm = document.getElementById('emailSendForm');
    const sendBtn = document.getElementById('emailSendBtn');
    if (!emailForm || !sendBtn) {
        return;
    }

    document.getElementById('emailModal').addEventListener('shown.bs.modal', function() {
        document.getElementById('recipientEmailInput').focus();
    });

    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = 'pdf_base64';
    emailForm.appendChild(hiddenInput);

    emailForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const attachPdf = document.getElementById('attach_pdf').checked;
        if (!attachPdf) {
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending (No PDF)...';
            emailForm.submit();
            return;
        }

        const originalBtnText = sendBtn.innerHTML;
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF (Please wait)...';

        const hidePrices = document.getElementById('hidePrices') ? document.getElementById('hidePrices').checked : false;

        try {
            const doc = await generatePoPdfDocument(hidePrices, 15000);
            hiddenInput.value = doc.output('datauristring');

            Swal.fire({
                title: 'Sending Email...',
                html: 'Please wait while we send the Purchase Order with the attached PDF.<br><b>Do not close this window.</b>',
                allowOutsideClick: false,
                didOpen: function () {
                    Swal.showLoading();
                }
            });

            emailForm.submit();
        } catch (err) {
            console.error('PDF Generation Error:', err);
            Swal.fire({
                title: 'PDF Generation Failed',
                text: (err.message || 'Unknown error') + '. Do you want to send the email WITHOUT the attachment?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Send Anyway',
                cancelButtonText: 'Cancel'
            }).then(function (result) {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Sending Email...',
                        text: 'Sending without attachment...',
                        allowOutsideClick: false,
                        didOpen: function () {
                            Swal.showLoading();
                        }
                    });
                    emailForm.submit();
                } else {
                    sendBtn.innerHTML = originalBtnText;
                    sendBtn.disabled = false;
                }
            });
        }
    });
});

function copyPoLink() {
    // Copy Portal URL for Supplier
    const portalUrl = "<?php echo $portalUrl; ?>";
    
    navigator.clipboard.writeText(portalUrl).then(function() {
        // Create simple toast/alert
        const btn = document.querySelector('a[onclick="copyPoLink()"]');
        const originalHtml = btn.innerHTML;
        
        btn.innerHTML = '<i class="fas fa-check"></i>';
        btn.classList.remove('link');
        btn.classList.add('whatsapp'); // Green color for success
        
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.classList.remove('whatsapp');
            btn.classList.add('link');
        }, 2000);
    }, function(err) {
        console.error('Async: Could not copy text: ', err);
        alert('Failed to copy link');
    });
}
</script>
<script>
    // Workflow Auto-Triggers
    const urlParams = new URLSearchParams(window.location.search);
    
    // 1. Force Tracking Modal on Approval

    
    // 2. Scroll to Negotiation Share
    if (urlParams.has('negotiation_sent')) {
        const shareAlert = document.querySelector('.alert-info');
        if (shareAlert) {
            shareAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function confirmApprove(id) {
        Swal.fire({
            title: 'Approve Purchase Order?',
            text: "Are you sure you want to Approve this PO? This will lock the order and allow shipment creation.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, Approve it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'approve.php?id=' + id;
            }
        })
    }
</script>
