<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../../includes/mailer.php';

function tableExists(PDO $pdo, string $table): bool {
    try {
        return (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Load PO + supplier for notification emails (stocks_purchase_orders).
 */
function fetchStockPoForMail(PDO $pdo, int $purchaseId): ?array {
    $hasLegacySuppliers = tableExists($pdo, 'suppliers');
    $supplierNameExpr = $hasLegacySuppliers
        ? 'COALESCE(ss.name, ls.name, CONCAT(\'Supplier #\', p.supplier_id))'
        : 'COALESCE(ss.name, CONCAT(\'Supplier #\', p.supplier_id))';
    $supplierEmailExpr = $hasLegacySuppliers ? 'COALESCE(ss.email, ls.email)' : 'ss.email';
    $contactExpr = 'COALESCE(NULLIF(ss.contact_person, \'\'), \'\')';

    $sql = "SELECT p.*,
        {$supplierNameExpr} AS supplier_name,
        {$supplierEmailExpr} AS supplier_email,
        {$contactExpr} AS contact_person
        FROM stocks_purchase_orders p
        LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id";
    if ($hasLegacySuppliers) {
        $sql .= ' LEFT JOIN suppliers ls ON p.supplier_id = ls.id';
    }
    $sql .= ' WHERE p.id = ? LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$purchaseId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    if (!$po) {
        return null;
    }

    $po['purchase_no'] = $po['po_number'] ?? $po['purchase_no'] ?? ('PO-' . $purchaseId);

    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(qty_ordered * unit_cost), 0) FROM stocks_po_items WHERE po_id = ?');
    $sumStmt->execute([$purchaseId]);
    $po['total_amount'] = (float) $sumStmt->fetchColumn();

    $settings = getCompanySettings($pdo);
    $po['company_name'] = $settings['company_name'] ?? 'Company';
    $po['company_email'] = $settings['email'] ?? '';

    return $po;
}

function sendPOStatusEmail($purchase_id, $event, $pdo) {
    $purchase_id = (int) $purchase_id;
    $po = fetchStockPoForMail($pdo, $purchase_id);
    if (!$po) {
        return false;
    }

    $recipient = '';
    $subject = '';
    $body = '';
    $supplierName = !empty($po['contact_person']) ? $po['contact_person'] : ($po['supplier_name'] ?? 'Supplier');
    $companyName = $po['company_name'];

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $token = rawurlencode((string) ($po['public_token'] ?? ''));
    $portalUrl = "{$protocol}://{$host}/stock/modules/purchases/supplier_response.php?token={$token}";

    switch ($event) {
        case 'request_quote':
            $recipient = $po['supplier_email'] ?? '';
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
            $recipient = $po['company_email'];
            $subject = "Supplier Quote Received: PO-{$po['purchase_no']}";
            $body = 'Hello Procurement Team,<br><br>' .
                    "Supplier <strong>{$po['supplier_name']}</strong> has submitted their quote for PO <strong>{$po['purchase_no']}</strong>.<br><br>" .
                    'Total Amount: ' . number_format($po['total_amount'], 2) . '<br>' .
                    'Status: Supplier Responded<br><br>' .
                    'Please review and approve via the system.<br><br>' .
                    'Regards,<br>System Notification';
            break;

        case 'negotiation_requested':
            $recipient = $po['supplier_email'] ?? '';
            $subject = "Action Required: Update Quote for PO-{$po['purchase_no']}";
            $notes = htmlspecialchars((string) ($po['negotiation_notes'] ?? ''), ENT_QUOTES, 'UTF-8');
            $body = "Dear {$supplierName},<br><br>" .
                    "We have reviewed your quote for PO <strong>{$po['purchase_no']}</strong> and have the following feedback/request:<br><br>" .
                    "<div style='background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffc107;'>" . nl2br($notes) . '</div><br>' .
                    "Please update your prices and re-submit your quote via the portal:<br><br>" .
                    "<a href='{$portalUrl}' style='padding: 10px 20px; background-color: #ffc107; color: black; text-decoration: none; border-radius: 5px;'>Update Quote</a><br><br>" .
                    "Regards,<br>{$companyName}";
            break;

        case 'approved':
            $recipient = $po['supplier_email'] ?? '';
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

    if (!empty($recipient) && sendEmail($recipient, $subject, $body, true)) {
        try {
            $stmtLog = $pdo->prepare('INSERT INTO email_logs (purchase_id, recipient_email, subject, sent_by, sent_at) VALUES (?, ?, ?, 0, NOW())');
            $stmtLog->execute([$purchase_id, $recipient, $subject]);
        } catch (Throwable $e) {
        }

        if ($event !== 'quote_received') {
            try {
                $pdo->prepare('UPDATE stocks_purchase_orders SET emailed_to = ?, emailed_at = NOW() WHERE id = ?')->execute([$recipient, $purchase_id]);
            } catch (Throwable $e) {
            }
        }

        return true;
    }

    return false;
}
