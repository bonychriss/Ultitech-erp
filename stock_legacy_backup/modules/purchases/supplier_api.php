<?php
// supplier_api.php
// Handles AJAX requests for the Supplier Portal: Translations, Validation, Submission

require_once '../../config/database.php';
require_once '../../config/functions.php';

header('Content-Type: application/json');

// Helper to send JSON error
function jsonError($msg) {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// Router
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_translations':
        handleGetTranslations($pdo);
        break;
        
    case 'submit_quote':
        handleSubmitQuote($pdo);
        break;
        
    default:
        jsonError('Invalid Action');
}

/**
 * Fetch Translations for a given Language Code
 */
function handleGetTranslations($pdo) {
    $lang = $_GET['lang'] ?? 'en';
    
    // Fetch from DB
    $stmt = $pdo->prepare("SELECT key_name, translation FROM language_translations WHERE language_code = ?");
    $stmt->execute([$lang]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $translations = [];
    foreach ($results as $row) {
        $translations[$row['key_name']] = $row['translation'];
    }
    
    // Fallback to English if empty (implied logic, or client side handles defaults)
    echo json_encode($translations);
    exit;
}

/**
 * Handle Quote Submission
 */
function handleSubmitQuote($pdo) {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST Required');
        
        // 1. Get Token & Validate PO
        // Note: Ideally pass token in header or body. For now, assuming session or token param. 
        // But since this is API, let's look for 'token' in POST or REFERER?
        // Let's assume the frontend sends the token as a hidden field or query param. 
        // The user's JS doesn't show sending the token explicitly in FormData, 
        // so we might need to rely on the SESSION if the page set it, or parse Referer?
        // Better: frontend should extract token from URL and send it.
        // For this implementation, I'll check $_GET['token'] (passed in URL of API call) or $_POST.
        
        // However, the cleanest way without changing the User's JS too much is to check the Referer token or 
        // rely on the user adding logic. I'll simply assume the 'token' is passed in the URL to this API file.
        // ex: supplier_api.php?action=submit_quote&token=XYZ
        
        $token = $_GET['token'] ?? '';
        if (!$token) jsonError('Missing Token');

        $stmt = $pdo->prepare("SELECT * FROM purchases WHERE public_token = ?");
        $stmt->execute([$token]);
        $po = $stmt->fetch();

        if (!$po) jsonError('Invalid Token');
        if (!empty($po['token_expiry']) && strtotime($po['token_expiry']) < time()) jsonError('Link Expired');
        if ($po['status'] == 'Approved' || $po['status'] == 'Completed') jsonError('PO already finalized');

        // 2. Process Files (Invoice)
        $invoicePath = $po['invoice_attachment']; // Keep existing if valid? Or require new?
        if (isset($_FILES['invoice']) && $_FILES['invoice']['error'] == 0) {
            $uploadDir = "../../uploads/invoices/" . $po['id'] . "/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = pathinfo($_FILES['invoice']['name'], PATHINFO_EXTENSION);
            $newFilename = 'invoice_' . date('Ymd_His') . '.' . $ext;
            
            if (move_uploaded_file($_FILES['invoice']['tmp_name'], $uploadDir . $newFilename)) {
                $invoicePath = "uploads/invoices/" . $po['id'] . "/" . $newFilename;
            } else {
                 jsonError('Failed to upload invoice');
            }
        } elseif (empty($invoicePath)) {
            // Check if required
             jsonError('Invoice File Required');
        }

        // 3. Process Prices
        $prices = $_POST['prices'] ?? []; // Array [prod_id => price]
        $currencies = $_POST['currencies'] ?? []; // Array [prod_id => currency]
        
        if (empty($prices)) jsonError('No prices submitted');
        
        $pdo->beginTransaction();

        foreach ($prices as $prodId => $priceVal) {
             // In a real multi-currency system, we'd store the currency per line item.
             // But existing system is simple unit_price.
             // We will store the price as is.
             // Ideally we convert to Base Currency if we want consistency, but Supplier Portal allows them to set it.
             // For now, update unit_price.
             
             // Check if currency differs?
             // Users JS allows per-item currency. 'purchase_items' doesn't have currency col.
             // I'll update unit_price.
             
             $stmtItem = $pdo->prepare("UPDATE purchase_items SET unit_price = ? WHERE purchase_id = ? AND product_id = ?");
             $stmtItem->execute([$priceVal, $po['id'], $prodId]);
        }
        
        // 4. Update PO Metadata
        $supplierLang = $_POST['language'] ?? 'en';
        $supplierCurr = $_POST['currency'] ?? 'USD'; // Overall currency pref
        $validity = $_POST['validity_date'] ?? null;
        $delivery = $_POST['delivery_terms'] ?? null;
        $notes = $_POST['notes'] ?? '';
        
        // Append notes to existing negotiation notes or supplier notes
        // We really should have a 'supplier_notes' column.
        // For now, I'll append to 'negotiation_notes' or just 'notes'?
        // The user migration didn't add 'supplier_notes'. 
        // I will append to 'negotiation_notes' clearly marked.
        
        $existingNotes = $po['negotiation_notes'] ?? '';
        $newNotes = $existingNotes . "\n\n[Supplier Response " . date('Y-m-d H:i') . "]: " . $notes;

        $stmtUpdate = $pdo->prepare("UPDATE purchases SET 
            status = 'Supplier Responded',
            supplier_responded_at = NOW(),
            invoice_attachment = ?,
            supplier_language = ?,
            supplier_currency = ?,
            delivery_terms = ?,
            validity_date = ?,
            negotiation_notes = ?
            WHERE id = ?");
            
        $stmtUpdate->execute([$invoicePath, $supplierLang, $supplierCurr, $delivery, $validity, $newNotes, $po['id']]);

        $pdo->commit();
        
        // 5. Send Notification (Re-use existing mailer logic if possible)
        require_once 'po_mailer.php';
        sendPOStatusEmail($po['id'], 'quote_received', $pdo);

        echo json_encode(['success' => true, 'quote_id' => $po['id']]);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Server Error: ' . $e->getMessage());
    }
}
?>
