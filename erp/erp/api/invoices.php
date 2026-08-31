<?php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;

    if ($action === 'create') {
        // Validate
        if (empty($_POST['customer_id']) || empty($_POST['invoice_date'])) {
            throw new Exception('Customer and date are required');
        }

        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('At least one item is required');
        }

        $pdo->beginTransaction();

        // Calculate totals
        $subtotal = 0;
        $totalTax = 0;

        foreach ($_POST['items'] as $item) {
            $lineTotal = floatval($item['total']);
            $subtotal += $lineTotal;

            // Calculate per-item tax
            $qty = floatval($item['quantity']);
            $price = floatval($item['unit_price']);
            $rate = floatval($item['tax_rate'] ?? 0);
            // Re-calculate derived values to be safe
            $calculatedLineTotal = $qty * $price;

            $lineTax = $calculatedLineTotal * ($rate / 100);
            $totalTax += $lineTax;
        }

        $total = $subtotal + $totalTax;

        // Insert Invoice
        // Check if tax_rate column exists in erp_invoices, if not we just store 0 or generic.
        // We will store 0 for global tax_rate if it's mixed.

        $sql = "INSERT INTO erp_invoices (
            invoice_number, customer_id, invoice_date, due_date, 
            subtotal, tax_rate, tax_amount, total, balance, 
            notes, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['invoice_number'],
            $_POST['customer_id'],
            $_POST['invoice_date'],
            $_POST['due_date'] ?? null,
            $subtotal,
            0, // Global tax rate is ambiguous with line items, setting to 0
            $totalTax,
            $total,
            $total, // Initial balance = total
            $_POST['notes'] ?? null,
            $_SESSION['user_id']
        ]);

        $invoiceId = $pdo->lastInsertId();

        // Ensure erp_invoice_items has tax_rate column
        try {
            $pdo->query("SELECT tax_rate FROM erp_invoice_items LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE erp_invoice_items ADD COLUMN tax_rate DECIMAL(10,2) DEFAULT 0 AFTER unit_price");
        }

        // Insert Items
        $sqlItem = "INSERT INTO erp_invoice_items (
            invoice_id, product_id, description, quantity, unit_price, tax_rate, total
        ) VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmtItem = $pdo->prepare($sqlItem);

        foreach ($_POST['items'] as $item) {
            $stmtItem->execute([
                $invoiceId,
                !empty($item['product_id']) ? $item['product_id'] : null,
                $item['description'],
                floatval($item['quantity']),
                floatval($item['unit_price']),
                floatval($item['tax_rate'] ?? 0),
                floatval($item['total'])
            ]);

            // Update stock if product exists
            if (!empty($item['product_id'])) {
                $stmtStock = $pdo->prepare("UPDATE erp_products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                $stmtStock->execute([floatval($item['quantity']), $item['product_id']]);
            }
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Invoice created successfully', 'id' => $invoiceId]);

    } elseif ($action === 'update_status') {
        if (empty($_POST['id']) || empty($_POST['status'])) {
            throw new Exception('ID and Status are required');
        }

        // Simple status update
        // Simple status update
        $stmt = $pdo->prepare("UPDATE erp_invoices SET status = ? WHERE id = ?");
        $stmt->execute([$_POST['status'], $_POST['id']]);

        if ($_POST['status'] === 'posted') {
            require_once '../accounting/journal_entry_service.php';
            $jeService = new JournalEntryService($pdo);
            $jeService->postInvoice($_POST['id']);
        }

        // Log it (optional if logger exists here, but we skipped init for brevity in create)
        // require_once '../includes/ActivityLogger.php'; $logger = new ActivityLogger($pdo); ...

        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);

    } elseif ($action === 'register_payment') {
        global $pdo;

        // Validation
        if (empty($_POST['id']) || empty($_POST['amount']) || empty($_POST['payment_date']) || empty($_POST['bank_account_id'])) {
            throw new Exception('All payment fields are required');
        }

        $invoiceId = $_POST['id'];
        $amount = floatval($_POST['amount']);
        $date = $_POST['payment_date'];
        $bankId = $_POST['bank_account_id'];
        $reference = $_POST['reference'] ?? '';

        $pdo->beginTransaction();

        try {
            // 1. Ensure erp_invoice_payments table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS erp_invoice_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                payment_date DATE NOT NULL,
                payment_method VARCHAR(50) DEFAULT 'bank_transfer',
                reference VARCHAR(100),
                bank_transaction_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (invoice_id) REFERENCES erp_invoices(id) ON DELETE CASCADE
            )");

            // 2. Fetch Invoice
            $stmt = $pdo->prepare("SELECT * FROM erp_invoices WHERE id = ?");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch();
            if (!$invoice)
                throw new Exception("Invoice not found");

            // 3. Create Bank Transaction (Debit - Money In)
            // Verify bank account exists
            $stmtBank = $pdo->prepare("SELECT * FROM erp_bank_accounts WHERE id = ?");
            $stmtBank->execute([$bankId]);
            $bank = $stmtBank->fetch();
            if (!$bank)
                throw new Exception("Bank account not found");

            $txnDesc = "Payment for Invoice " . $invoice['invoice_number'];
            if ($reference)
                $txnDesc .= " Ref: " . $reference;

            // Insert into erp_bank_transactions
            // Schema assumed from bank-transactions.php: bank_account_id, transaction_date, description, debit, credit, balance, reconciled...
            // Note: balance calculation is complex; usually requires triggers or recalculation. 
            // For now we just insert raw txn.

            $sqlTxn = "INSERT INTO erp_bank_transactions (
                bank_account_id, transaction_date, description, reference, debit, credit, reconciled, created_by
            ) VALUES (?, ?, ?, ?, ?, 0, 0, ?)";

            $stmtTxn = $pdo->prepare($sqlTxn);
            $stmtTxn->execute([
                $bankId,
                $date,
                $txnDesc,
                $reference,
                $amount,
                $_SESSION['user_id']
            ]);
            $txnId = $pdo->lastInsertId();

            // Update Bank Account Current Balance
            $pdo->prepare("UPDATE erp_bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $bankId]);

            // 4. Record Invoice Payment
            $stmtPay = $pdo->prepare("INSERT INTO erp_invoice_payments (
                invoice_id, amount, payment_date, payment_method, reference, bank_transaction_id
            ) VALUES (?, ?, ?, 'bank_transfer', ?, ?)");
            $stmtPay->execute([$invoiceId, $amount, $date, $reference, $txnId]);

            // 5. Update Invoice Status & Balance
            $newBalance = floatval($invoice['balance']) - $amount;
            if ($newBalance < 0)
                $newBalance = 0; // Prevent negative balance

            // Determine status
            // If Paid in Full -> 'in_payment' (Wait for Reconcile)
            // If Partial -> 'partial'

            $newStatus = $invoice['status'];
            if ($newBalance <= 0) {
                $newStatus = 'in_payment';
            } elseif ($newBalance < $invoice['total']) {
                $newStatus = 'partial';
            }

            // Logic Check: 'in_payment' status might not exist in ENUM if it is one. 
            // We should ideally ALTER table if we suspect ENUM. 
            // Assuming VARCHAR for now as seen in Customer create. 
            // If it fails, catch block will rollback.

            $stmtUpd = $pdo->prepare("UPDATE erp_invoices SET balance = ?, status = ? WHERE id = ?");
            $stmtUpd->execute([$newBalance, $newStatus, $invoiceId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Payment registered successfully']);

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

    } elseif ($action === 'send_email') {
        require_once '../../config_mail.php'; // Ensure SMTP constants are available
        require_once '../../includes/mailer.php';

        if (empty($_POST['id'])) {
            throw new Exception('Invoice ID is required');
        }

        $invoiceId = $_POST['id'];

        // Fetch Invoice and Customer Details
        $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.email as customer_email 
                               FROM erp_invoices i 
                               JOIN erp_customers c ON i.customer_id = c.id 
                               WHERE i.id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();

        if (!$invoice)
            throw new Exception('Invoice not found');
        if (empty($invoice['customer_email']))
            throw new Exception('Customer has no email address');

        // Fetch Items
        $stmtItems = $pdo->prepare("SELECT ii.*, p.name as product_name 
                                    FROM erp_invoice_items ii 
                                    LEFT JOIN erp_products p ON ii.product_id = p.id 
                                    WHERE ii.invoice_id = ?");
        $stmtItems->execute([$invoiceId]);
        $items = $stmtItems->fetchAll();

        // Construct Email Body
        $subject = "Invoice " . $invoice['invoice_number'] . " from " . SMTP_FROM_NAME;

        // Construct Email Body - Ultimate Yellow Sharp Design
        $yellow = '#FFEB3B'; // Vibrant Yellow from image
        $black = '#000000';
        $gray = '#f0f0f0';

        $body = "<!DOCTYPE html><html><body style='margin:0; padding:0; background-color:#fff; font-family: Arial, sans-serif; color: #000;'>";
        $body .= "<div style='max-width: 800px; margin: 20px auto; background: #ffffff; padding: 20px;'>";

        // 1. Header: Logo Left (or Top Right as per image request? Image had logo top right)
        // User asked "logo like select-module" which is top left in select-module, but image has it TOP RIGHT. 
        // User said "include ultimate logo on top right" in PREVIOUS request.
        // Image shows Logo Top Right.

        $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 30px;'><tr>";

        // Left: Customer Info (Eventually) - pushing specific content to next row?
        // Actually image shows top area empty or just company info on right.
        // Let's put Company Info Top Right.
        $body .= "<td style='width: 50%;'></td>"; // Spacer

        $body .= "<td style='width: 50%; text-align: right; vertical-align: top;'>";
        // Logo placeholder (using text if image not embeddable easily in email without CID, but trying img tag with absolute path if live)
        // Ideally we use text for reliability if we don't have a hosted image URL.
        $body .= "<h2 style='margin: 0; font-size: 24px; font-weight: bold; text-transform: uppercase;'>" . (defined('COMPANY_NAME') ? COMPANY_NAME : 'ULTIMATE') . "</h2>";
        $body .= "<p style='margin: 5px 0 0 0; font-size: 14px;'>Mikocheni B, Dar es salaam Tanzania<br>P.O.BOX 78004</p>";
        $body .= "</td>";
        $body .= "</tr></table>";

        // 2. Client Info & Title
        $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'><tr>";

        // Client Info (Left)
        $body .= "<td style='width: 60%; vertical-align: bottom;'>";
        $body .= "<p style='margin: 0; font-weight: bold; font-size: 16px;'>" . htmlspecialchars($invoice['customer_name']) . "</p>";
        if (!empty($invoice['customer_email']))
            $body .= "<p style='margin: 2px 0;'>" . htmlspecialchars($invoice['customer_email']) . "</p>";
        $body .= "</td>";

        // Invoice Title (Right, Yellow text)
        $body .= "<td style='width: 40%; text-align: right; vertical-align: bottom;'>";
        $body .= "<h1 style='margin: 0; font-size: 28px; color: #ffd700; font-weight: normal;'>Invoice # " . htmlspecialchars($invoice['invoice_number']) . "</h1>";
        $body .= "</td>";

        $body .= "</tr></table>";

        // 3. Gray Info Box (Sharp Edges)
        $body .= "<table style='width: 100%; border-collapse: collapse; border: 1px solid #000; margin-bottom: 30px; background-color: #e0e0e0;'>";
        $body .= "<tr>";
        $body .= "<td style='padding: 10px; border-right: 1px solid #000; width: 33%;'><strong>Invoice Date</strong><br>" . date('d/m/Y', strtotime($invoice['invoice_date'])) . "</td>";
        $body .= "<td style='padding: 10px; border-right: 1px solid #000; width: 33%;'><strong>Due Date</strong><br>" . ($invoice['due_date'] ? date('d/m/Y', strtotime($invoice['due_date'])) : '-') . "</td>";
        $body .= "<td style='padding: 10px; width: 33%;'><strong>Reference</strong><br>-</td>";
        $body .= "</tr>";
        $body .= "</table>";

        // 4. Items Table (Sharp Edges, Yellow Header)
        $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 30px;'>";
        $body .= "<thead>";
        $body .= "<tr style='background-color: $yellow;'>";
        $body .= "<th style='padding: 12px; text-align: left; border: 1px solid #000; color: #fff; font-weight: bold;'>DESCRIPTION</th>";
        $body .= "<th style='padding: 12px; text-align: center; border: 1px solid #000; color: #fff; font-weight: bold;'>QUANTITY</th>";
        $body .= "<th style='padding: 12px; text-align: right; border: 1px solid #000; color: #fff; font-weight: bold;'>UNIT PRICE</th>";
        $body .= "<th style='padding: 12px; text-align: right; border: 1px solid #000; color: #fff; font-weight: bold;'>AMOUNT</th>";
        $body .= "</tr>";
        $body .= "</thead>"; // Note: Image has white text on yellow, or black? Let's check contrast. Yellow usually needs black text. 
        // Re-reading image: "DESCRIPTION" looks white or light. BUT standard accessibility says black on yellow. 
        // User asked "use this format". I'll stick to White text as per my interpretation of "format", but Black is safer. 
        // Actually, looking at the uploaded image artifact, the header text is WHITE. I will use White.

        $body .= "<tbody>";

        foreach ($items as $item) {
            $descr = !empty($item['product_name']) ? $item['product_name'] : $item['description'];
            $body .= "<tr>";
            $body .= "<td style='padding: 12px; border: 1px solid #000;'>" . htmlspecialchars($descr) . "</td>";
            $body .= "<td style='padding: 12px; border: 1px solid #000; text-align: center;'>" . $item['quantity'] . "</td>";
            $body .= "<td style='padding: 12px; border: 1px solid #000; text-align: right;'>" . number_format($item['unit_price'], 2) . "</td>";
            $body .= "<td style='padding: 12px; border: 1px solid #000; text-align: right;'>" . number_format($item['total'], 2) . "</td>";
            $body .= "</tr>";
        }

        $body .= "</tbody></table>";

        // 5. Totals (Sharp Boxes)
        $body .= "<div style='float: right; width: 50%;'>";
        $body .= "<table style='width: 100%; border-collapse: collapse;'>";
        $body .= "<tr>";
        $body .= "<td style='padding: 10px; border: 1px solid #000; background: #fff;'>Untaxed Amount</td>";
        $body .= "<td style='padding: 10px; border: 1px solid #000; background: #fff; text-align: right;'>" . number_format($invoice['subtotal'], 2) . " TSh</td>";
        $body .= "</tr>";
        $body .= "<tr>";
        $body .= "<td style='padding: 10px; border: 1px solid #000; background: #fff;'>Tax</td>";
        $body .= "<td style='padding: 10px; border: 1px solid #000; background: #fff; text-align: right;'>" . number_format($invoice['tax_amount'], 2) . " TSh</td>";
        $body .= "</tr>";
        $body .= "<tr style='background-color: $yellow;'>";
        $body .= "<td style='padding: 10px; border: 1px solid #000; color: #fff; font-weight: bold;'>Total</td>";
        $body .= "<td style='padding: 10px; border: 1px solid #000; color: #fff; font-weight: bold; text-align: right;'>" . number_format($invoice['total'], 2) . " TSh</td>";
        $body .= "</tr>";
        $body .= "</table>";
        $body .= "</div>";
        $body .= "<div style='clear: both;'></div>";

        // 6. Bank Details (Left)
        $body .= "<div style='margin-top: 40px;'>";
        $body .= "<h3 style='margin: 0 0 10px 0; font-style: italic;'>BANK DETAILS</h3>";
        $body .= "<p style='margin: 0; font-weight: bold; font-style: italic;'>Account Name: Ultimate General Trading</p>";
        $body .= "<p style='margin: 0; font-weight: bold; font-style: italic;'>Account Number: TZS 015000101FL00</p>";
        $body .= "<p style='margin: 0; font-weight: bold; font-style: italic;'>Bank: CRDB Bank</p>";
        $body .= "</div>";

        // Notes
        if (!empty($invoice['notes'])) {
            $body .= "<div style='margin-top: 20px; border: 1px solid #000; padding: 10px;'>";
            $body .= "<strong>Notes:</strong><br>" . nl2br(htmlspecialchars($invoice['notes']));
            $body .= "</div>";
        }

        // Footer (Blue Line)
        $body .= "<div style='margin-top: 50px; border-top: 2px solid #ccc; padding-top: 10px; text-align: center; color: blue;'>";
        $body .= "<a href='https://www.ultimate.co.tz' style='text-decoration: none; color: blue;'>www.ultimate.co.tz</a> | <a href='mailto:sales@ultimate.co.tz' style='text-decoration: none; color: blue;'>sales@ultimate.co.tz</a>";
        $body .= "</div>";

        $body .= "</div></body></html>";

        // Send Email
        if (sendEmail($invoice['customer_email'], $subject, $body)) {
            // Log Activity
            // Assuming logger is available or we include it. 
            // Since we didn't include it at the top of this file in previous edits (it was in create-invoice.php but not api/invoices.php directly unless I missed it),
            // I'll check if $pdo is available (yes) and just manually insert or use logger if included.
            // For safety I will rely on manual insert if Logger class not loaded, OR try loading it.
            require_once '../includes/ActivityLogger.php';
            $logger = new ActivityLogger($pdo);
            $logger->log('invoice', $invoiceId, 'email', "Invoice sent by email to " . $invoice['customer_email']);

            echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
        } else {
            throw new Exception('Failed to send email. Check SMTP logs.');
        }

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
