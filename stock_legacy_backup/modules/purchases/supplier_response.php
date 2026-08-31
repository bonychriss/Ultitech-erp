<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Secure Public Portal - No Login Required (relies on Token)
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';

// 1. Validate Token
$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("Invalid Access: Token missing.");
}

ensurePurchaseWorkflowSchema($pdo);
$stockPoProbe = loadStockPoByPublicToken($pdo, trim((string) $token));
if ($stockPoProbe !== null) {
    require __DIR__ . '/supplier_response_stocks.php';
    exit;
}

// --- Legacy purchases.* portal (older deployments) ---
// Handle Rating AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'rate_service') {
    $rating = (int)$_POST['rating'];
    $comment = trim($_POST['comment'] ?? '');
    
    // Get PO ID from token (re-query minimal)
    $stmtR = $pdo->prepare("SELECT id FROM purchases WHERE public_token = ?");
    $stmtR->execute([$token]);
    $poR = $stmtR->fetch();
    
    if ($poR && $rating >= 1 && $rating <= 5) {
        $pdo->prepare("INSERT INTO supplier_ratings (purchase_id, rating, comment) VALUES (?, ?, ?)")
            ->execute([$poR['id'], $rating, $comment]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

$stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name, s.address as supplier_address, s.phone as supplier_phone, s.email as supplier_email, s.contact_person,
                       cs.company_name, cs.address as company_address, cs.phone as company_phone, cs.email as company_email, cs.currency, cs.terms_and_conditions, cs.exchange_rate,
                       cs.city, cs.country, cs.default_payment_terms
                       FROM purchases p 
                       JOIN suppliers s ON p.supplier_id = s.id
                       LEFT JOIN company_settings cs ON 1=1
                       WHERE p.public_token = ? LIMIT 1");
$stmt->execute([$token]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    die("Invalid Access: Order not found.");
}

// Check Expiry
if (!empty($po['token_expiry']) && strtotime($po['token_expiry']) < time()) {
    die("Access Denied: This quote request link has expired.");
}

// 2. Handle Form Submission
$success_msg = '';
$error_msg = '';

// STRICT STATE LOGIC
// 'Pending' = Original State
// 'Negotiation Requested' = Opened for corrections
// 'Supplier Responded' = Locked (Under Review)
// 'Approved' = Locked (Final)
$isWritable = in_array($po['status'], ['Pending', 'Negotiation Requested']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // strict check: prevent re-submission unless in negotiation
    if (!$isWritable) {
        die("Error: This quote has already been submitted and is closed for editing.");
    }
    
    // QUICK ACCEPT LOGIC
    if (isset($_POST['action']) && $_POST['action'] === 'quick_accept') {
        // Just flip status, no price changes
         $pdo->prepare("UPDATE purchases SET status = 'Supplier Responded', supplier_responded_at = NOW() WHERE id = ?")
             ->execute([$po['id']]);

         // Send Notification
         require_once 'po_mailer.php';
         sendPOStatusEmail($po['id'], 'quote_received', $pdo);
         
         // Redirect to avoid resubmission and show success state
         header("Location: ?token=" . $token . "&quick_accepted=true");
         exit;
    }

    // A. Handle Price Updates
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        $hasErrors = false;
        
        foreach ($_POST['items'] as $item_id => $data) {
            $price = floatval($data['price']);
            
            // Validation: Price must be > 0
            if ($price <= 0) {
                $error_msg = "Error: All items must have a valid price greater than 0.00";
                $hasErrors = true;
                break; // Stop processing
            }
            
            // Audit Trail: Get Old Price
            $stmtOld = $pdo->prepare("SELECT unit_price, product_id FROM purchase_items WHERE id = ?");
            $stmtOld->execute([$item_id]);
            $oldItem = $stmtOld->fetch();
            
            // Only update/log if changed
            if ($oldItem && abs($oldItem['unit_price'] - $price) > 0.001) {
                // Log to Audit
                // Log to Audit
                $prodId = $oldItem['product_id'] ?? 0;
                
                // DEBUG BLOCK START
                try {
                    $stmtAudit = $pdo->prepare("INSERT INTO price_audit_trail (purchase_id, product_id, old_price, new_price, changed_by, reason) VALUES (?, ?, ?, ?, 'supplier', 'Quote Submission')");
                    $stmtAudit->execute([$po['id'], $prodId, $oldItem['unit_price'], $price]);
                } catch (PDOException $e) {
                    // Log details
                    $debugInfo = "SQL ERROR: " . $e->getMessage() . "\n";
                    $debugInfo .= "Values: PO_ID=" . $po['id'] . ", ProdID=" . var_export($prodId, true) . ", OldPrice=" . $oldItem['unit_price'] . ", NewPrice=" . $price . "\n";
                    $debugInfo .= "OldItem Raw: " . print_r($oldItem, true) . "\n";
                    
                    // Inspect Schema
                    try {
                        $stmtDesc = $pdo->query("DESCRIBE price_audit_trail");
                        $cols = $stmtDesc->fetchAll(PDO::FETCH_ASSOC);
                        $debugInfo .= "Schema: " . print_r($cols, true) . "\n";
                    } catch (Exception $ex) { $debugInfo .= "Schema Check Failed: " . $ex->getMessage(); }
                    
                    file_put_contents('debug_db_error.txt', $debugInfo, FILE_APPEND);
                    die("<h3>DEBUG ERROR</h3><pre>" . htmlspecialchars($debugInfo) . "</pre>");
                }
                // DEBUG BLOCK END
                
                // Update items
                $stmtUpdate = $pdo->prepare("UPDATE purchase_items SET unit_price = ?, total_amount = (quantity * ?) WHERE id = ? AND purchase_id = ?");
                $stmtUpdate->execute([$price, $price, $item_id, $po['id']]);
            }
        }
        
        if (!$hasErrors) { // Only recalc totals if no errors
        
        // Recalculate totals
        $itemsCheck = $pdo->prepare("SELECT SUM(total_amount) as subtotal FROM purchase_items WHERE purchase_id = ?");
        $itemsCheck->execute([$po['id']]);
        $totals = $itemsCheck->fetch();
        $newSubtotal = $totals['subtotal'];
        $tax = $newSubtotal * ($po['tax_percentage'] / 100);
        $total = $newSubtotal + $tax;
        
        // Get Language/Currency
        $suppLang = $_POST['supplier_language'] ?? 'en';
        $suppCurr = $_POST['supplier_currency'] ?? 'USD';

        $pdo->prepare("UPDATE purchases SET subtotal = ?, tax_amount = ?, total_amount = ?, supplier_language = ?, supplier_currency = ? WHERE id = ?")
            ->execute([$newSubtotal, $tax, $total, $suppLang, $suppCurr, $po['id']]);
            
        // Removed early redirect to allow invoice processing below
        // header("Location: supplier_rating.php?token=" . $token);
        // exit;
        } // End !$hasErrors check
    }

    // B. Handle Invoice Upload (Required)
    if (isset($_FILES['invoice']) && $_FILES['invoice']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $filename = $_FILES['invoice']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
             // Create Upload Dir
             $uploadDir = '../../uploads/invoices/' . $po['id'] . '/';
             if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
             
             $newFilename = 'invoice_' . date('Ymd_His') . '.' . $ext;
             $dest = $uploadDir . $newFilename;
             
             if (move_uploaded_file($_FILES['invoice']['tmp_name'], $dest)) {
                 $dbPath = "uploads/invoices/" . $po['id'] . "/" . $newFilename;
                 
                 $pdo->prepare("UPDATE purchases SET invoice_attachment = ? WHERE id = ?")
                     ->execute([$dbPath, $po['id']]);
                 
                 // Update local $po array to reflect change immediately
                 $po['invoice_attachment'] = $dbPath;
                 
             } else {
                 $error_msg = "Failed to upload file. Check permissions.";
             }
        } else {
            $error_msg = "Invalid file type. Only PDF and Images allowed.";
        }
    } elseif (empty($po['invoice_attachment']) && !$hasErrors) {
         // If no new file, but already exists? User logic might require re-upload if prices change?
         // User prompt said "Required".
         // Let's rely on client-side required for new uploads, or allow if existing.
         if (empty($po['invoice_attachment'])) {
            $error_msg = "Please upload the commercial invoice.";
         }
    }

    // C. Finalize Submission (Update Status & Notify) checks
    if (empty($error_msg) && !$hasErrors) {
        // Only update status if all good
        // CHANGED: Back to Manual Approval workflow
        $pdo->prepare("UPDATE purchases SET status = 'Supplier Responded', supplier_responded_at = NOW() WHERE id = ?")
            ->execute([$po['id']]);

        // Send Notification
        require_once 'po_mailer.php';
        sendPOStatusEmail($po['id'], 'quote_received', $pdo);

        $success_msg = "Response submitted successfully! The buyer has been notified.";
        
        // Refresh PO data for the view
        $stmt->execute([$token]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Fetch Items
$stmtItems = $pdo->prepare("SELECT pi.id as item_id, pi.quantity, pi.unit_price, pi.total_amount, pi.product_id,
                            pr.name as product_name, pr.product_code, pr.description as product_desc, pr.image as main_image, pr.id as prod_id
                            FROM purchase_items pi
                            LEFT JOIN products pr ON pi.product_id = pr.id
                            WHERE pi.purchase_id = ?");
$stmtItems->execute([$po['id']]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
$currencySymbol = ($po['currency'] ?? 'USD') == 'TZS' ? 'TSh' : '$';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote Submission | <?php echo $po['purchase_no']; ?></title>
    <meta name="app-version" content="FIX_V2_APPLIED">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .po-doc { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; color: #333; }
        .table-po thead th { background-color: #f8f9fa !important; color: #000 !important; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; border-color: #dee2e6; padding: 8px 10px; }
        .table-po tbody td { border-color: #dee2e6; vertical-align: middle; font-size: 0.85rem; padding: 6px 10px; }
        .table-po tfoot td, .table-po tfoot tr { border: 0px none !important; border-top: 0px none !important; border-bottom: 0px none !important; box-shadow: none !important; }
        .company-logo { max-height: 80px; }
        .locale-switcher { position: absolute; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000; }
        .price-input { min-width: 100px; }

        /* Mobile Optimization */
        @media (max-width: 768px) {
            .locale-switcher { position: static; justify-content: flex-end; padding: 10px; }
            .po-doc { padding: 15px; }
            
            /* Force table to be block-level for card layout */
            .table-po, .table-po tbody, .table-po tr, .table-po td {
                display: block;
                width: 100%;
            }
            .table-po thead { display: none; } /* Hide headers */
            
            .table-po tr {
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                padding: 10px;
                background: #fff;
            }
            
            .table-po td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border: none;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .table-po td:last-child { border-bottom: none; }
            
            /* Label pseudo-element */
            .table-po td::before {
                content: attr(data-label);
                font-weight: bold;
                text-transform: uppercase;
                font-size: 0.75rem;
                color: #666;
                margin-right: 15px;
            }

            /* Specific adjustments */
            .table-po td img { width: 60px !important; height: 60px !important; }
            .price-input { max-width: 120px; }
        }
    </style>
</head>
<body>
    
    <?php if (!in_array($po['status'], ['Supplier Responded', 'Approved'])): ?>
    <!-- Controls -->
    <div class="locale-switcher">
         <div class="dropdown">
            <button class="btn btn-sm btn-light border dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-globe me-1"></i> <span id="currentLang">English</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="#" onclick="changeLanguage('en')">English</a></li>
                <li><a class="dropdown-item" href="#" onclick="changeLanguage('es')">Español</a></li>
                <li><a class="dropdown-item" href="#" onclick="changeLanguage('fr')">Français</a></li>
                <li><a class="dropdown-item" href="#" onclick="changeLanguage('zh')">中文</a></li>
                <li><a class="dropdown-item" href="#" onclick="changeLanguage('ar')">العربية</a></li>
            </ul>
        </div>
        <div class="dropdown">
            <button class="btn btn-sm btn-light border dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-coins me-1"></i> <span id="currentCurr">USD</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="#" onclick="changeCurrency('USD')">USD ($)</a></li>
                <li><a class="dropdown-item" href="#" onclick="changeCurrency('EUR')">EUR (€)</a></li>
                <li><a class="dropdown-item" href="#" onclick="changeCurrency('GBP')">GBP (£)</a></li>
                <li><a class="dropdown-item" href="#" onclick="changeCurrency('CNY')">CNY (¥)</a></li>
                <li><a class="dropdown-item" href="#" onclick="changeCurrency('TZS')">TZS (TSh)</a></li>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="container py-5">
        
        <!-- Success Message handled via Toast -->

        <?php if(!empty($error_msg)): ?>
            <div class="alert alert-danger text-center mb-4 shadow rounded-3">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
            </div>
        <?php endif; ?>

        <!-- Instructions -->
        <?php if ($isWritable): ?>
            <div class="alert alert-info border-0 shadow-sm rounded-3 mb-4">
                <h5 class="alert-heading fw-bold" data-translate="instructions"><i class="fas fa-info-circle me-2"></i>Instructions</h5>
                <ol class="mb-0 ps-3 small">
                    <li data-translate="instruction_1">Review the requested items and quantities below.</li>
                    <li data-translate="instruction_2p">Enter your Unit Price for each item (Must be > 0.00).</li>
                    <li data-translate="instruction_3">Upload your official Commercial Invoice (Required).</li>
                    <li data-translate="instruction_5">Click Submit Response to finalize your quote.</li>
                </ol>
            </div>
        <?php endif; // End isWritable check ?>



        <!-- RATING FORM (Show ONLY if just responded or Approved) -->
        <?php if(in_array($po['status'], ['Supplier Responded', 'Approved'])): ?>
            <div class="card border-0 shadow-sm mb-4 mx-auto" style="max-width: 900px; background: #e8f5e9;">
                <div class="card-body text-center p-4">
                    <h5 class="fw-bold mb-3">How was your experience using our Portal?</h5>
                    <form id="ratingForm" class="rating-form">
                        <input type="hidden" name="action" value="rate_service">
                        <div class="stars mb-3" style="font-size: 1.5rem; color: #ffc107; cursor: pointer;">
                            <i class="far fa-star" data-val="1"></i>
                            <i class="far fa-star" data-val="2"></i>
                            <i class="far fa-star" data-val="3"></i>
                            <i class="far fa-star" data-val="4"></i>
                            <i class="far fa-star" data-val="5"></i>
                        </div>
                        <input type="hidden" name="rating" id="ratingVal" required>
                        <textarea name="comment" class="form-control mb-3" placeholder="Any comments or suggestions? (Optional)" rows="2"></textarea>
                        <button type="submit" class="btn btn-success btn-sm rounded-pill px-4">Submit Feedback</button>
                    </form>
                    <div id="ratingSuccess" class="d-none">
                        <h5 class="text-success"><i class="fas fa-heart"></i> Thank you for your feedback!</h5>
                    </div>
                </div>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const stars = document.querySelectorAll('.stars i');
                const ratingInput = document.getElementById('ratingVal');
                
                stars.forEach(star => {
                    star.addEventListener('click', function() {
                        const val = this.getAttribute('data-val');
                        ratingInput.value = val;
                        updateStars(val);
                    });
                });
                
                function updateStars(val) {
                    stars.forEach(s => {
                         if(s.getAttribute('data-val') <= val) {
                             s.classList.remove('far');
                             s.classList.add('fas');
                         } else {
                             s.classList.remove('fas');
                             s.classList.add('far');
                         }
                    });
                }
                
                document.getElementById('ratingForm').addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(d => {
                        if(d.status === 'success') {
                            document.getElementById('ratingForm').classList.add('d-none');
                            document.getElementById('ratingSuccess').classList.remove('d-none');
                        }
                    });
                });
            });
            </script>
        <?php endif; ?>

        <?php if($po['status'] == 'Negotiation Requested'): ?>
            <div class="alert alert-warning text-center my-3 shadow-sm mx-auto" style="max-width: 900px;">
                <h4 class="alert-heading fw-bold"><i class="fas fa-comments"></i> Negotiation Requested</h4>
                <div class="bg-white p-3 rounded text-start mx-auto border-start border-4 border-warning" style="max-width: 800px;">
                    <?php echo nl2br(htmlspecialchars($po['negotiation_notes'])); ?>
                </div>
                <hr>
                <p class="mb-0 fw-bold">Please update your prices below and re-submit.</p>
            </div>
        <?php endif; ?>

        <!-- ONE-CLICK ACCEPT UI (Only if Writable) -->
        <?php if ($isWritable): ?>
            <div class="alert alert-primary border-0 shadow-sm rounded-3 mb-4 mx-auto" style="max-width: 900px;">
                 <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                     <div>
                         <h5 class="fw-bold mb-1"><i class="fas fa-check-circle me-2"></i>Quick Accept</h5>
                         <p class="mb-0 small">Accept this PO exactly as requested. No need to update prices or upload invoice now.</p>
                     </div>
                     <form method="POST" class="m-0">
                         <input type="hidden" name="action" value="quick_accept">
                         <button type="submit" class="btn btn-primary fw-bold px-4 py-2" onclick="return confirm('Accept this PO immediately?')">
                             Accept Order Now
                         </button>
                     </form>
                 </div>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <!-- Hidden Fields for Preferences -->
            <input type="hidden" name="supplier_language" id="hw_lang" value="en">
            <input type="hidden" name="supplier_currency" id="hw_curr" value="<?php echo $po['supplier_currency'] ?? 'USD'; ?>">
            
            <div class="card rounded-0 po-doc mx-auto" style="max-width: 900px;">
                
                <!-- HEADER -->
                <div class="row mb-5 border-bottom pb-4">
                    <div class="col-6">
                         <img src="../../../assets/images/Untitled.jpg" alt="Logo" class="company-logo mb-2">
                    </div>
                    <div class="col-6 text-end">
                        <h2 class="fw-bold text-uppercase mb-2 text-dark" data-translate="purchase_order">Purchase Order</h2>
                        <h5 class="text-muted mb-0"><?php echo $po['purchase_no']; ?></h5>
                         <!-- Status Badge -->
                         <div class="mt-2">
                            <?php if ($po['status'] == 'Approved'): ?>
                                <span class="badge bg-success fs-6">APPROVED</span>
                            <?php elseif ($po['status'] == 'Supplier Responded'): ?>
                                <span class="badge bg-info text-dark fs-6">UNDER REVIEW</span>
                            <?php elseif ($po['status'] == 'Negotiation Requested'): ?>
                                <span class="badge bg-warning text-dark fs-6">CHANGES REQUESTED</span>
                            <?php else: ?>
                                <span class="badge bg-secondary fs-6">PENDING</span>
                            <?php endif; ?>
                         </div>
                    </div>
                </div>
                
                <!-- ADDRESS BLOCK -->
                <div class="row mb-5">
                    <div class="col-6">
                        <h6 class="text-uppercase text-muted fw-bold small ls-1 mb-3" data-translate="from_company">From (Buyer)</h6>
                        <address class="mb-0 small">
                             <strong><?php echo htmlspecialchars($po['company_name']); ?></strong><br>
                            <?php echo nl2br(htmlspecialchars($po['company_address'])); ?><br>
                            <?php echo htmlspecialchars(($po['city'] ?? '') . ', ' . ($po['country'] ?? '')); ?><br>
                            Phone: <?php echo htmlspecialchars($po['company_phone']); ?><br>
                            Email: <?php echo htmlspecialchars($po['company_email']); ?>
                        </address>
                    </div>
                    <div class="col-6 text-end">
                        <h6 class="text-uppercase text-muted fw-bold small ls-1 mb-3" data-translate="to_supplier">To (Supplier)</h6>
                        <address class="mb-0 small">
                            <strong><?php echo htmlspecialchars($po['supplier_name']); ?></strong><br>
                            Attn: <?php echo htmlspecialchars($po['contact_person']); ?><br>
                            Phone: <?php echo htmlspecialchars($po['supplier_phone']); ?><br>
                            Email: <?php echo htmlspecialchars($po['supplier_email']); ?>
                        </address>
                    </div>
                </div>
                
                <!-- META INFO -->
                <div class="row mb-3 bg-light p-2 mx-0 rounded-1 border">
                    <div class="col-md-3">
                        <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.7rem;" data-translate="po_date">PO Date</small>
                        <span class="fw-bold small"><?php echo date('M d, Y', strtotime($po['created_at'])); ?></span>
                    </div>
                    <div class="col-md-3">
                        <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.7rem;" data-translate="payment_terms">Payment Terms</small>
                        <span class="fw-bold small"><?php echo htmlspecialchars($po['default_payment_terms'] ?? 'Net 30'); ?></span>
                    </div>
                    <div class="col-md-3">
                        <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.7rem;" data-translate="delivery_terms">Expected Delivery</small>
                        <span class="fw-bold small"><?php echo date('M d, Y', strtotime($po['created_at'] . ' +30 days')); ?></span>
                    </div>
                    <div class="col-md-3 text-end">
                        <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.7rem;" data-translate="currency">Currency</small>
                        <span class="fw-bold small badge bg-primary" id="displayMetaCurr"><?php echo htmlspecialchars($po['currency'] ?? 'USD'); ?></span>
                    </div>
                </div>

                <!-- ITEMS TABLE -->
                <h5 class="fw-bold mb-3 mt-4" data-translate="items_to_quote">Order Items</h5>
                <div class="table-responsive mb-4">
                     <table class="table table-bordered table-po mb-0">
                        <thead>
                            <tr>
                                <th style="width: 10%;">Image</th>
                                <th style="width: 45%" data-translate="product">Description</th>
                                <th class="text-center" style="width: 10%" data-translate="quantity">Qty</th>
                                <th class="text-end" style="width: 20%" data-translate="your_price">Unit Price</th>
                                <th class="text-end" style="width: 15%" data-translate="line_total">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $index => $item): 
                                // Determine display rate
                                $activeCurrency = $po['supplier_currency'] ?? ($po['currency'] ?? 'USD');
                                $exchangeRate = ($activeCurrency == 'TZS') ? ($po['exchange_rate'] ?: 1) : 1;
                                
                                $currentPrice = $item['unit_price'] * $exchangeRate;
                                // Image Handling
                                $imgUrl = '../../../assets/images/no-image.png';
                                if (!empty($item['main_image'])) {
                                    $checkPath = "../../uploads/products/" . $item['prod_id'] . "/medium/" . $item['main_image'];
                                    if(file_exists(__DIR__ . '/' . $checkPath)) {
                                        $imgUrl = $checkPath;
                                    } else {
                                        $checkPath2 = "../../uploads/products/" . $item['prod_id'] . "/" . $item['main_image'];
                                        if(file_exists(__DIR__ . '/' . $checkPath2)) {
                                            $imgUrl = $checkPath2;
                                        }
                                    }
                                }
                            ?>
                            <tr>
                                <td class="text-center" style="width: 50px;" data-label="Image">
                                     <img src="<?php echo $imgUrl; ?>" alt="Img" style="width: 60px; height: 60px; object-fit: cover; border-radius: 3px;">
                                </td>
                                <td data-label="Description">
                                    <span class="fw-bold text-dark"><?php echo htmlspecialchars($item['product_name']); ?></span>
                                    <?php if(!empty($item['product_code'])): ?><small class="text-muted d-block" style="font-size: 0.75rem;"><?php echo htmlspecialchars($item['product_code']); ?></small><?php endif; ?>
                                    <?php if(!empty($item['product_desc'])): ?>
                                        <div class="small text-muted mt-1"><?php echo htmlspecialchars($item['product_desc']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center fw-bold" data-label="Quantity">
                                    <span class="qty-badge" data-value="<?php echo $item['quantity']; ?>"><?php echo number_format($item['quantity'], 2); ?></span>
                                </td>
                                 <td class="text-end" data-label="Unit Price">
                                    <?php if (!$isWritable): ?>
                                        <!-- Read Only Mode -->
                                        <span class="fw-bold text-dark">
                                            <span class="dynamic-symbol"><?php echo $currencySymbol; ?></span>
                                            <span class="price-text" data-val="<?php echo $currentPrice; ?>"><?php echo number_format($currentPrice, 2); ?></span>
                                        </span>
                                    <?php else: ?>
                                        <!-- Edit Mode -->
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-white border-end-0 dynamic-symbol">$</span>
                                            <input type="number" step="0.01" min="0.01" name="items[<?php echo $item['item_id']; ?>][price]" 
                                                   class="form-control border-start-0 text-end fw-bold price-input" 
                                                   value="<?php echo ($po['status'] !== 'Pending' && $currentPrice > 0) ? $currentPrice : ''; ?>" 
                                                   data-qty="<?php echo $item['quantity']; ?>" 
                                                   required>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold row-total" data-label="Total">
                                    <?php echo number_format($item['quantity'] * $currentPrice, 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end fw-bold text-uppercase border-top-2" data-translate="grand_total">Total Amount</td>
                                <td class="text-end fw-bold border-top-2 bg-light fs-6">
                                    <span class="me-1 dynamic-symbol">$</span><span id="grand_total"><?php echo number_format($po['total_amount'], 2); ?></span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                 <!-- TERMS & CONDITIONS -->
                <div class="row mt-5">
                    <div class="col-12">
                        <h6 class="text-uppercase text-muted fw-bold small ls-1 border-bottom pb-2">Terms & Conditions</h6>
                        <p class="small text-muted" style="white-space: pre-line;"><?php echo htmlspecialchars(!empty($po['terms_conditions']) ? $po['terms_conditions'] : $po['terms_and_conditions']); ?></p>
                        <p class="small text-muted fst-italic mt-2" data-translate="quote_validity">Note: This quote is valid for 30 days.</p>
                    </div>
                </div>

                <?php if ($isWritable): ?>
                    <!-- UPLOAD INVOICE (Optional in this flow if Quick Accept used, but shown for detailed edits) -->
                     <div class="row align-items-center bg-light p-4 rounded mb-2 border mx-0 mt-4">
                        <div class="col-md-7">
                            <label class="form-label fw-bold mb-0 text-dark"><i class="fas fa-cloud-upload-alt me-2"></i> <span data-translate="upload_commercial_invoice">Upload Official Invoice</span></label>
                            <div class="form-text mt-0" data-translate="invoice_help">Optional if Prices match. Required for Custom Quotes.</div>
                        </div>
                        <div class="col-md-5">
                             <input type="file" name="invoice" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                             <?php if(!empty($po['invoice_attachment'])): ?>
                                <div class="mt-2 small text-success"><i class="fas fa-check"></i> <span data-translate="file_uploaded">File Uploaded</span></div>
                             <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- MAIN SUBMIT BUTTON -->
                    <div class="text-center mt-5">
                        <p class="small text-muted mb-2">Detailed Response (Prices Changed / Invoice Attached)</p>
                         <button type="submit" class="btn btn-success btn-lg px-5 rounded-0 shadow hover-lift fw-bold text-uppercase ls-1">
                            <i class="fas fa-paper-plane me-2"></i> <span data-translate="submit_quote">Submit Changes</span>
                         </button>
                    </div>
                <?php endif; ?>
                
                 <div class="text-center mt-5 text-muted small">
                    &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($po['company_name']); ?>. <span data-translate="all_rights_reserved">All Rights Reserved.</span>
                </div>
                
            </div>
        </form>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global Toast Definition
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });

        // 1. Success Message (Submission just happened)
        <?php if(!empty($success_msg)): ?>
            Toast.fire({
                icon: 'success',
                title: '<?php echo addslashes($success_msg); ?>'
            });
        
        // 2. Read-Only State (Already submitted) - Show info toast if not just submitted
        <?php elseif (!$isWritable): ?>
            Toast.fire({
                icon: 'info', // Changed to info to distinguish from "Just Success"
                title: 'Response Submitted',
                text: 'You have successfully submitted your quote. The buyer is currently reviewing it.'
            });
        <?php endif; ?>
    </script>
    <script>
    let translations = {};
    let activeCurrency = '<?php echo $po['supplier_currency'] ?? ($po['currency'] ?? 'USD'); ?>';
    let exchangeRates = { 'USD': 1 };
    
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('.price-input');
        
        inputs.forEach(input => {
            input.addEventListener('input', calculateTotals);
        });
        
        // Initial set
        changeCurrency(activeCurrency);
        // Load translations (default en)
        fetchTranslations('en');
        // Load Rates
        loadExchangeRates();
    });

    function changeLanguage(lang) {
        document.getElementById('currentLang').textContent = lang.toUpperCase();
        document.getElementById('hw_lang').value = lang;
        if(lang == 'ar') document.body.dir = 'rtl'; else document.body.dir = 'ltr';
        
        fetchTranslations(lang);
    }
    
    function fetchTranslations(lang) {
        fetch(`supplier_api.php?action=get_translations&lang=${lang}`)
            .then(r => r.json())
            .then(data => {
                translations = data;
                applyTranslations();
            });
    }
    
    function applyTranslations() {
        document.querySelectorAll('[data-translate]').forEach(el => {
            const key = el.getAttribute('data-translate');
            if(translations[key]) el.textContent = translations[key];
        });
    }

    function changeCurrency(curr) {
        activeCurrency = curr;
        document.getElementById('currentCurr').textContent = curr;
        document.getElementById('displayMetaCurr').textContent = curr;
        document.getElementById('hw_curr').value = curr;
        
        const symbol = getSymbol(curr);
        document.querySelectorAll('.dynamic-symbol').forEach(span => span.textContent = symbol);
        
        calculateTotals();
    }
    
    function getSymbol(curr) {
         const symbols = { 'USD': '$', 'EUR': '€', 'GBP': '£', 'CNY': '¥', 'TZS': 'TSh', 'AED': 'د.إ' };
         return symbols[curr] || curr;
    }
    
    function loadExchangeRates() {
         fetch('https://api.exchangerate-api.com/v4/latest/USD')
            .then(r => r.json())
            .then(d => {
                exchangeRates = d.rates;
                exchangeRates['TZS'] = 2600; 
            })
            .catch(e => console.error(e));
    }

    function calculateTotals() {
        let grandTotal = 0;
        
        document.querySelectorAll('tbody tr').forEach(row => {
             const qty = parseFloat(row.querySelector('.qty-badge').getAttribute('data-value'));
             const priceInput = row.querySelector('.price-input');
             const priceText = row.querySelector('.price-text');
             let price = 0;
             
             if (priceInput) {
                 price = parseFloat(priceInput.value);
             } else if (priceText) {
                 price = parseFloat(priceText.getAttribute('data-val'));
             }
             
             if (!isNaN(price) && price >= 0) {
                 const total = qty * price;
                 grandTotal += total;
                 row.querySelector('.row-total').innerText = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
             }
        });
        
        document.getElementById('grand_total').innerText = grandTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    </script>
</body>
</html>
