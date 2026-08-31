<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../../includes/mailer.php';
requireLogin();

if (!isset($_GET['id'])) redirect('index.php');
$id = $_GET['id'];

// Fetch PO
$stmt = $pdo->prepare("SELECT p.*, 
                       s.name as supplier_name, s.contact_person, s.email as supplier_email, s.phone as supplier_phone, s.address as supplier_address
                       FROM purchases p 
                       JOIN suppliers s ON p.supplier_id = s.id 
                       WHERE p.id = ?");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) redirect('index.php');

// Ensure token exists (fallback for old records if migration missed any or new insert race condition)
if (empty($po['public_token'])) {
    $token = bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE purchases SET public_token = ? WHERE id = ?")->execute([$token, $id]);
    $po['public_token'] = $token;
}

// Fetch Company Settings
$stmtSettings = $pdo->query("SELECT * FROM company_settings LIMIT 1");
$company = $stmtSettings->fetch(PDO::FETCH_ASSOC);

// Fetch Current User Signature
$stmtUser = $pdo->prepare("SELECT full_name, signature_path FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$currentUser = $stmtUser->fetch();
$userSignature = $currentUser['signature_path'] ?? '';
$userFullName = $currentUser['full_name'] ?? '';

// Handle Email Sending
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_email') {
    $to = $_POST['recipient_email'];
    $subject = $_POST['subject'];
    $body = $_POST['message'];
    
    // Handle Attachments
    $attachments = [];
    if (isset($_POST['pdf_base64']) && !empty($_POST['pdf_base64'])) {
        // Remove data URI prefix if present
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

    if (sendEmail($to, $subject, $body, true, $attachments)) {
        // Log Email
        $stmtLog = $pdo->prepare("INSERT INTO email_logs (purchase_id, recipient_email, subject, sent_by) VALUES (?, ?, ?, ?)");
        $stmtLog->execute([$id, $to, $subject, $_SESSION['user_id']]);
        
        // Update PO status
        $pdo->prepare("UPDATE purchases SET emailed_to=?, emailed_at=NOW(), emailed_by=? WHERE id=?")->execute([$to, $_SESSION['user_id'], $id]);
        
        flash('success', 'Purchase Order sent successfully to ' . $to);
    } else {
        flash('error', 'Failed to send email.');
    }
    
    header("Location: view_po.php?id=$id");
    exit;
}

// Handle Shipment Tracking Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_tracking') {
    $tracking = $_POST['tracking_number'];
    $carrier = $_POST['carrier'];
    $arrival = $_POST['est_arrival_date'];
    
    $pdo->prepare("UPDATE purchases SET tracking_number=?, carrier=?, est_arrival_date=? WHERE id=?")
        ->execute([$tracking, $carrier, $arrival, $id]);
        
    flash('success', 'Shipment tracking updated successfully.');
    redirect("view_po.php?id=$id");
}

// Handle Accept Quote
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'accept_quote') {
    $pdo->prepare("UPDATE purchases SET status='Approved' WHERE id=?")->execute([$id]);
    
    require_once 'po_mailer.php';
    sendPOStatusEmail($id, 'approved', $pdo);
    
    flash('success', 'Supplier Quote Accepted! PO Approved.');
    redirect("view_po.php?id=$id&order_approved=true");
}

// Handle Request Negotiation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'request_negotiation') {
    $notes = trim($_POST['negotiation_notes']);
    $pdo->prepare("UPDATE purchases SET status = 'Negotiation Requested', negotiation_notes = ? WHERE id = ?")->execute([$notes, $id]);
    
    require_once 'po_mailer.php';
    sendPOStatusEmail($id, 'negotiation_requested', $pdo);
    
    flash('warning', 'Negotiation Requested. Email sent to supplier.');
    redirect("view_po.php?id=$id&negotiation_sent=true");
}

$page_title = 'Purchase Order #' . $po['purchase_no'];
include '../../includes/header.php';
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
    .table-po thead th { background-color: #f8f9fa !important; color: #000 !important; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; border-color: #dee2e6; padding: 8px 10px; }
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
    .action-icon.email { color: #0d6efd; }
    .action-icon.link { color: #0dcaf0; }
    .action-icon.back { color: #6c757d; }
</style>

<main class="main-content">
    <div class="stock-container">
        
        <!-- Actions Toolbar -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <a href="index.php" class="action-icon back shadow-sm" title="Back to List">
                <i class="fas fa-arrow-left"></i>
            </a>
            
            <div class="d-flex gap-3 align-items-center">
                <?php
                    // Clean phone number for WhatsApp
                    $wa_phone = preg_replace('/[^0-9]/', '', $po['supplier_phone']);
                    // Prepare Message
                    $rate = $company['exchange_rate'] ?? 1;
                    $currSymbol = getCurrencySymbol($company['currency'] ?? 'USD');
                    $displayTotal = number_format(convertCurrency($po['total_amount'], $rate), 2);
                    
                    // Removed Price from Message
                    $wa_msg = urlencode("Hi " . ($po['contact_person'] ?: 'Supplier') . ",\n\nPurchase Order: " . $po['purchase_no'] . "\n\nPlease see items required in the attached/link.\n\nRegards,\n" . $company['company_name']);
                ?>
                
                <?php if(!empty($wa_phone)): ?>
                    <a href="https://wa.me/<?php echo $wa_phone; ?>?text=<?php echo $wa_msg; ?>" target="_blank" class="action-icon whatsapp shadow-sm" title="WhatsApp Supplier">
                        <i class="fab fa-whatsapp fs-5"></i>
                    </a>
                <?php endif; ?>
                
                <a href="javascript:void(0)" onclick="copyPoLink()" class="action-icon link shadow-sm" title="Copy Portal Link">
                    <i class="fas fa-link"></i>
                </a>
                <a href="javascript:void(0)" onclick="window.print()" class="action-icon print shadow-sm" title="Print / Download PDF">
                    <i class="fas fa-print"></i>
                </a>
                <a href="javascript:void(0)" class="action-icon email shadow-sm" data-bs-toggle="modal" data-bs-target="#emailModal" title="Email Supplier">
                    <i class="fas fa-envelope"></i>
                </a>
            </div>
        </div>
        
        <?php flash('success'); flash('error'); ?>

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

        <!-- Shipment Tracking Enforcement -->
        <?php if($po['status'] == 'Approved' && empty($po['tracking_number'])): ?>
            <div class="alert alert-danger shadow mb-4 border-danger border-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="alert-heading fw-bold"><i class="fas fa-shipping-fast"></i> Shipment Tracking Required</h5>
                        <p class="mb-0">This order is Approved. Please enter the shipment tracking details to proceed.</p>
                    </div>
                    <button class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#trackingModal">Update Tracking</button>
                </div>
            </div>
        <?php elseif($po['status'] == 'Approved'): ?>
             <div class="alert alert-success shadow mb-4 border-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="alert-heading fw-bold"><i class="fas fa-truck"></i> In Transit</h5>
                        <p class="mb-0">
                            <strong>Carrier:</strong> <?php echo htmlspecialchars($po['carrier']); ?> | 
                            <strong>Tracking:</strong> <?php echo htmlspecialchars($po['tracking_number']); ?> | 
                            <strong>ETA:</strong> <?php echo htmlspecialchars($po['est_arrival_date']); ?>
                        </p>
                    </div>
                    <button class="btn btn-sm btn-outline-success fw-bold" data-bs-toggle="modal" data-bs-target="#trackingModal">Edit</button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Supplier Response Review Block -->
        <?php if($po['status'] == 'Supplier Responded'): ?>
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
                                        <td class="text-end fw-bold"><?php echo number_format($quoted, 2); ?></td>
                                        <td class="text-end text-muted"><?php echo $last > 0 ? number_format($last, 2) : 'N/A'; ?></td>
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
                        <?php if(!empty($po['invoice_attachment'])): ?>
                             <br><small><a href="<?php echo '../../' . htmlspecialchars($po['invoice_attachment']); ?>" target="_blank" class="text-primary fw-bold"><i class="fas fa-paperclip"></i> View Attached Invoice</a></small>
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

        <div class="card shadow rounded-0 p-5 po-doc" style="min-height: 1000px; max-width: 900px; margin: 0 auto;">
            
            <!-- HEADER -->
            <div class="row mb-5 border-bottom pb-4">
                <div class="col-6">
                     <img src="../../../assets/images/Untitled.jpg" alt="<?php echo htmlspecialchars($company['company_name']); ?>" class="company-logo">
                </div>
                <div class="col-6 text-end">
                    <h2 class="fw-bold text-uppercase mb-2 text-dark">Purchase Order</h2>
                    <h5 class="text-muted mb-0"><?php echo $po['purchase_no']; ?></h5>
                </div>
            </div>
            
            <!-- COMPANY & SUPPLIER BLOCK -->
            <div class="row mb-5">
                <div class="col-6">
                    <h6 class="text-uppercase text-muted fw-bold small ls-1 mb-3">From</h6>
                    <address class="mb-0 small">
                        <strong><?php echo htmlspecialchars($company['company_name']); ?></strong><br>
                        <?php echo nl2br(htmlspecialchars($company['address'])); ?><br>
                        <?php echo htmlspecialchars($company['city'] . ', ' . $company['country']); ?><br>
                        Phone: <?php echo htmlspecialchars($company['phone']); ?><br>
                        Email: <?php echo htmlspecialchars($company['email']); ?>
                    </address>
                </div>
                <div class="col-6 text-end">
                    <h6 class="text-uppercase text-muted fw-bold small ls-1 mb-3">To (Supplier)</h6>
                    <address class="mb-0 small">
                        <strong><?php echo htmlspecialchars($po['supplier_name']); ?></strong><br>
                        <?php echo htmlspecialchars($po['supplier_address']); ?><br>
                        Attn: <?php echo htmlspecialchars($po['contact_person']); ?><br>
                        Phone: <?php echo htmlspecialchars($po['supplier_phone']); ?><br>
                        Email: <?php echo htmlspecialchars($po['supplier_email']); ?>
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
                    <span class="fw-bold small"><?php echo htmlspecialchars($company['currency'] ?? 'USD'); ?></span>
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
                    // Fetch Items with Last Purchase Price
                    $stmtItems = $pdo->prepare("SELECT pi.*, 
                                                pr.name as product_name, pr.product_code, pr.description as product_desc, pr.main_image, pr.id as prod_id,
                                                (SELECT pi2.unit_price FROM purchase_items pi2 
                                                 JOIN purchases p2 ON pi2.purchase_id = p2.id 
                                                 WHERE pi2.product_id = pi.product_id 
                                                 AND p2.status = 'Approved' 
                                                 AND p2.id < ? 
                                                 ORDER BY p2.created_at DESC LIMIT 1) as last_price
                                                FROM purchase_items pi 
                                                LEFT JOIN products pr ON pi.product_id = pr.id 
                                                WHERE pi.purchase_id = ?");
                    $stmtItems->execute([$id, $id]);
                    $items = $stmtItems->fetchAll();

                    foreach($items as $item): 
                        $pName = $item['product_name'] ?? 'Unknown Item';
                        $pCode = $item['product_code'] ?? '';
                        
                        // Image Handling
                        $imgUrl = '../../../assets/images/no-image.png'; // Fallback
                        if (!empty($item['main_image'])) {
                            // Path relative to stock/modules/purchases/view_po.php -> stock/uploads
                            $checkPath = "../../uploads/products/" . $item['prod_id'] . "/medium/" . $item['main_image'];
                            if(file_exists(__DIR__ . '/' . $checkPath)) {
                                $imgUrl = $checkPath;
                            } else {
                                // Try without 'medium' folder if legacy
                                $checkPath2 = "../../uploads/products/" . $item['prod_id'] . "/" . $item['main_image'];
                                if(file_exists(__DIR__ . '/' . $checkPath2)) {
                                    $imgUrl = $checkPath2;
                                }
                            }
                        }
                    ?>
                    <tr>
                        <td class="text-center" style="width: 50px;">
                            <img src="<?php echo $imgUrl; ?>" alt="Img" style="width: 35px; height: 35px; object-fit: cover; border-radius: 3px;">
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($pName); ?></div>
                            <?php if(!empty($pCode)): ?><small class="text-muted d-block" style="font-size: 0.75rem;"><?php echo htmlspecialchars($pCode); ?></small><?php endif; ?>
                        </td>
                        <td class="text-center fw-bold"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td class="text-end price-col"><?php echo $currSymbol . number_format(convertCurrency($item['unit_price'], $rate), 2); ?></td>
                        <td class="text-end fw-bold price-col"><?php echo $currSymbol . number_format(convertCurrency($item['total_amount'], $rate), 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-end fw-bold price-col-label">Subtotal</td>
                        <td class="text-end fw-bold price-col"><?php echo $currSymbol . number_format(convertCurrency($po['subtotal'], $rate), 2); ?></td>
                    </tr>
                    <?php if($po['tax_amount'] > 0): ?>
                    <tr>
                        <td colspan="4" class="text-end price-col-label">Tax (<?php echo $po['tax_percentage']; ?>%)</td>
                        <td class="text-end price-col"><?php echo $currSymbol . number_format(convertCurrency($po['tax_amount'], $rate), 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td colspan="4" class="text-end fw-bold text-uppercase border-top-2 price-col-label">Grand Total</td>
                        <td class="text-end fw-bold border-top-2 bg-light price-col"><?php echo $currSymbol . number_format(convertCurrency($po['total_amount'], $rate), 2); ?></td>
                    </tr>
                </tfoot>
            </table>
            
            <!-- FOOTER Info -->
            <div class="row mt-5">
                <div class="col-12">
                    <h6 class="text-uppercase text-muted fw-bold small ls-1 border-bottom pb-2">Terms & Conditions</h6>
                    <p class="small text-muted" style="white-space: pre-line;"><?php echo htmlspecialchars(!empty($po['terms_conditions']) ? $po['terms_conditions'] : $company['terms_and_conditions']); ?></p>
                </div>
            </div>
            
            <!-- SIGNATURE -->
            <div class="row mt-5 pt-5">
                <div class="col-6">
                    <div class="position-relative border-top border-dark pt-2 w-75">
                         <?php if(!empty($userSignature)): ?>
                            <img src="../../../<?php echo htmlspecialchars($userSignature); ?>" alt="Signature" 
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
                <?php 
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                    $host = $_SERVER['HTTP_HOST'];
                    $portalUrl = "$protocol://$host/stock/modules/purchases/supplier_response.php?token=" . $po['public_token'];
                ?>
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($portalUrl); ?>" alt="QR" style="width: 80px;">
                <div class="mt-1 small text-muted">Or visit current URL</div>
            </div>
        </div>
        
        <?php if(!empty($po['invoice_attachment'])): ?>
        <div class="text-center mt-3 no-print">
            <div class="alert alert-success d-inline-block">
                <i class="fas fa-file-invoice"></i> Supplier Invoice Attached: 
                <a href="<?php echo '../../' . htmlspecialchars($po['invoice_attachment']); ?>" target="_blank" class="fw-bold text-success">View Invoice</a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if($po['emailed_at']): ?>
        <div class="text-center mt-3 text-muted no-print">
            <small><i class="fas fa-check-circle text-success"></i> Last emailed to <?php echo htmlspecialchars($po['emailed_to']); ?> on <?php echo $po['emailed_at']; ?></small>
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
</div>

<!-- Tracking Modal -->
<div class="modal fade" id="trackingModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content rounded-0">
             <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-shipping-fast me-2"></i>Update Shipment Tracking</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_tracking">
                    <p class="small text-muted">Please enter the details provided by the supplier / forwarder.</p>
                    <div class="mb-3">
                        <label class="form-label">Carrier / Forwarder</label>
                        <input type="text" name="carrier" class="form-control rounded-0" required value="<?php echo $po['carrier'] ?? ''; ?>" placeholder="e.g. DHL, Fedex, Forwarder Name">
                    </div>
                     <div class="mb-3">
                        <label class="form-label">Tracking Number / Ref</label>
                        <input type="text" name="tracking_number" class="form-control rounded-0" required value="<?php echo $po['tracking_number'] ?? ''; ?>">
                    </div>
                     <div class="mb-3">
                        <label class="form-label">Estimated Arrival</label>
                        <input type="date" name="est_arrival_date" class="form-control rounded-0" required value="<?php echo $po['est_arrival_date'] ?? ''; ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-0" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger rounded-0 fw-bold">Save Tracking</button>
                </div>
            </form>
        </div>
    </div>
</div>
</main>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-0">
             <div class="modal-header bg-light">
                <h5 class="modal-title">Send Purchase Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="send_email">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Recipient Email</label>
                            <input type="email" name="recipient_email" class="form-control rounded-0" value="<?php echo htmlspecialchars($po['supplier_email']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Subject</label>
                             <input type="text" name="subject" class="form-control rounded-0" value="Purchase Order <?php echo $po['purchase_no']; ?> from <?php echo htmlspecialchars($company['company_name']); ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message Template</label>
                        <textarea name="message" class="form-control rounded-0 font-monospace" rows="12" required>
Dear <?php echo htmlspecialchars($po['contact_person'] ?: 'Supplier'); ?>,

Please find attached Purchase Order <?php echo $po['purchase_no']; ?>.

To submit your quote, please click the link below to access our Supplier Portal:
<?php echo $portalUrl; ?>


Order Summary:
- PO Number: <?php echo $po['purchase_no']; ?>
- Date: <?php echo date('d M Y'); ?>

Please submit your Response and Invoice via the portal link above.

Best regards,

Procurement Team
<?php echo htmlspecialchars($company['company_name']); ?>
                        </textarea>
                        <div class="form-text">Review the email content before sending. The URL to the PO will be appended automatically if using digital delivery.</div>
                    </div>
                </div>
                <div class="modal-footer">
                     <button type="button" class="btn btn-secondary rounded-0" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-0"><i class="fas fa-paper-plane"></i> Send Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    window.jsPDF = window.jspdf.jsPDF;
    
    const emailForm = document.querySelector('#emailModal form');
    const sendBtn = emailForm.querySelector('button[type="submit"]');
    
    // Add hidden input for PDF
    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = 'pdf_base64';
    emailForm.appendChild(hiddenInput);
    
    emailForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Show loading state
        const originalBtnText = sendBtn.innerHTML;
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
        
        // Handle Price Hiding
        const hidePrices = document.getElementById('hidePrices').checked;
        const priceCols = document.querySelectorAll('.price-col');
        const priceLabels = document.querySelectorAll('.price-col-label'); // Footer labels
        
        if (hidePrices) {
            priceCols.forEach(el => el.style.display = 'none');
            // Adjust footer colspan if needed or just hide labels too? 
            // Better to hide the labels to keep alignment or just hide the whole row?
            // Actually, if we hide the last column, the colspan needs to extend? 
            // Simpler: Just hide the content or the cells. 
            // If we hide cells, the table structure might break if colspan isn't adjusted.
            // Let's hide the cells. The headers are 5 columns. We hide 2. Remaining 3.
            // Footer colspan=4 -> needs to be 2.
            
            // Fix: Just set visibility hidden? No, we want space gone.
            // Let's rely on simple display:none.
        }

        try {
            // Generate PDF from .po-doc element
            const element = document.querySelector('.po-doc');
            
            // Options for html2canvas
            const canvas = await html2canvas(element, {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor: '#ffffff'
            });
            
            // Restore Visibility Immediately after capture
            if (hidePrices) {
                priceCols.forEach(el => el.style.display = '');
            }
            
            const imgData = canvas.toDataURL('image/jpeg', 0.95); // JPEG slightly smaller than PNG
            const imgWidth = 210; // A4 mm
            const pageHeight = 295; 
            const imgHeight = canvas.height * imgWidth / canvas.width;
            
            const doc = new jsPDF('p', 'mm', 'a4');
            let heightLeft = imgHeight;
            let position = 0;
            
            doc.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
            heightLeft -= pageHeight;
            
            while (heightLeft >= 0) {
              position = heightLeft - imgHeight;
              doc.addPage();
              doc.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
              heightLeft -= pageHeight;
            }
            
            // Convert to Base64 (Data URI)
            const pdfData = doc.output('datauristring');
            hiddenInput.value = pdfData;
            
            // Now submit normally
            emailForm.submit();
            
        } catch (err) {
            console.error('PDF Generation Error:', err);
            alert('Error generating PDF attachment. Sending email without it?');
            sendBtn.innerHTML = originalBtnText;
            sendBtn.disabled = false;
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
    if (urlParams.has('order_approved')) {
        const trackingModalEl = document.getElementById('trackingModal');
        if (trackingModalEl) {
            const modal = new bootstrap.Modal(trackingModalEl);
            modal.show();
        }
    }
    
    // 2. Scroll to Negotiation Share
    if (urlParams.has('negotiation_sent')) {
        const shareAlert = document.querySelector('.alert-info');
        if (shareAlert) {
            shareAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
</script>
