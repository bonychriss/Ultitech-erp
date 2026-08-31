<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../includes/mailer.php';

function sendPOStatusEmail($purchase_id, $event, $pdo) {
    // 1. Fetch Full PO Details
    $stmt = $pdo->prepare("SELECT p.*, 
                           s.name as supplier_name, s.email as supplier_email, s.contact_person,
                           cs.company_name, cs.email as company_email
                           FROM purchases p 
                           JOIN suppliers s ON p.supplier_id = s.id
                           LEFT JOIN company_settings cs ON 1=1
                           WHERE p.id = ?");
    $stmt->execute([$purchase_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) return false;

    // 2. Prepare Variables
    $recipient = '';
    $subject = '';
    $body = '';
    $supplierName = $po['contact_person'] ?: $po['supplier_name'];
    $companyName = $po['company_name'];
    
    // Portal Link
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost'; 
    $portalUrl = "$protocol://$host/stock/modules/purchases/supplier_response.php?token=" . $po['public_token'];

    // 3. Define Templates
    switch ($event) {
        case 'request_quote':
            $recipient = $po['supplier_email'];
            $subject = "Quote Request: PO-{$po['purchase_no']}";
            $body = "Dear {$supplierName},<br><br>" .
                    "Please provide your best quote for the items in Purchase Order <strong>{$po['purchase_no']}</strong>.<br>" .
                    "You can view the items, enter your prices, and upload your invoice directly via our secure portal:<br><br>" .
                    "<a href='{$portalUrl}' style='padding: 10px 20px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 5px;'>View & Submit Quote</a><br><br>" .
                    "Or copy this link: {$portalUrl}<br><br>" .
                    "This link is valid for 7 days.<br><br>" .
                    "Regards,<br>{$companyName}";
            break;

        case 'quote_received':
            $recipient = $po['company_email']; // Send to Internal Procurement
            $subject = "Supplier Quote Received: PO-{$po['purchase_no']}";
            $body = "Hello Procurement Team,<br><br>" .
                    "Supplier <strong>{$po['supplier_name']}</strong> has submitted their quote for PO <strong>{$po['purchase_no']}</strong>.<br><br>" .
                    "Total Amount: " . number_format($po['total_amount'], 2) . "<br>" .
                    "Status: Supplier Responded<br><br>" .
                    "Please review and approve via the system.<br><br>" .
                    "Regards,<br>System Notification";
            break;

        case 'negotiation_requested':
            $recipient = $po['supplier_email'];
            $subject = "Action Required: Update Quote for PO-{$po['purchase_no']}";
            $body = "Dear {$supplierName},<br><br>" .
                    "We have reviewed your quote for PO <strong>{$po['purchase_no']}</strong> and have the following feedback/request:<br><br>" .
                    "<div style='background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffc107;'>" . nl2br(htmlspecialchars($po['negotiation_notes'])) . "</div><br>" .
                    "Please update your prices and re-submit your quote via the portal:<br><br>" .
                    "<a href='{$portalUrl}' style='padding: 10px 20px; background-color: #ffc107; color: black; text-decoration: none; border-radius: 5px;'>Update Quote</a><br><br>" .
                    "Regards,<br>{$companyName}";
            break;

        case 'approved':
            $recipient = $po['supplier_email'];
            $subject = "Order Confirmed: PO-{$po['purchase_no']}";
            $body = "Dear {$supplierName},<br><br>" .
                    "We are pleased to inform you that your quote for PO <strong>{$po['purchase_no']}</strong> has been <strong>APPROVED</strong>.<br><br>" .
                    "Please proceed with the order processing and delivery as per the agreed terms.<br><br>" .
                    "You can view the final confirmed PO here:<br>" .
                    "<a href='{$portalUrl}' style='color: #198754;'>View Confirmed PO</a><br><br>" .
                    "Regards,<br>{$companyName}";
            break;
            
        default:
            return false;
    }

    // 4. Send Email
    if (!empty($recipient)) {
        if (sendEmail($recipient, $subject, $body, true)) { // true = isHtml
            // Log it
            $stmtLog = $pdo->prepare("INSERT INTO email_logs (purchase_id, recipient_email, subject, sent_by, sent_at) VALUES (?, ?, ?, 0, NOW())");
            $stmtLog->execute([$purchase_id, $recipient, $subject]);
            
            // Update PO Logic if needed (e.g. emailed_at)
            if ($event !== 'quote_received') { // Don't update 'emailed_to' for internal notifications
                 $pdo->prepare("UPDATE purchases SET emailed_to=?, emailed_at=NOW() WHERE id=?")->execute([$recipient, $purchase_id]);
            }
            return true;
        }
    }
    
    return false;
}
?>
