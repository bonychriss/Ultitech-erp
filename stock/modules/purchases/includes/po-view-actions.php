<?php

declare(strict_types=1);

require_once __DIR__ . '/po-view-lib.php';
require_once dirname(__DIR__, 4) . '/includes/mailer.php';

function poViewHandleRequestActions(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        return;
    }

    poViewDeskBootstrap();
    ensureStocksPurchaseOrdersWorkflowColumns($pdo);
    ensurePurchaseWorkflowSchema($pdo);

    $company_id = function_exists('stockPurchaseActiveCompanyId') ? stockPurchaseActiveCompanyId() : (int) (currentCompanyId() ?? 0);
    $po = stockPurchaseLoadPoForView($pdo, $id, $company_id);
    if (!$po) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $maxSize = ini_get('post_max_size');
        flash('error', "Submission failed! The file size exceeds the server limit of $maxSize.", 'error');
        redirect('view_po.php?id=' . $id);
    }

    $isLegacyPurchase = ($po['_po_table'] ?? 'stocks_purchase_orders') === 'purchases';

    if (isset($_GET['recalc_tax']) && empty($isLegacyPurchase)) {
        $may = function_exists('hasRole') ? (hasRole('admin') || hasRole('procurement')) : true;
        if ($may) {
            $taxPct = max(0, min(100, (float) ($_GET['recalc_tax'] ?? 0)));
            try {
                $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if (in_array('tax_percentage', $poCols, true) || in_array('tax_amount', $poCols, true)) {
                    $stmtSub = $pdo->prepare('SELECT COALESCE(SUM(COALESCE(qty_ordered,0) * COALESCE(unit_cost,0)), 0) FROM stocks_po_items WHERE po_id = ?');
                    $stmtSub->execute([$id]);
                    $sub = (float) ($stmtSub->fetchColumn() ?? 0);
                    $taxAmt = $sub * ($taxPct / 100.0);
                    $grand = $sub + $taxAmt;
                    $sets = [];
                    $vals = [];
                    if (in_array('tax_percentage', $poCols, true)) { $sets[] = 'tax_percentage = ?'; $vals[] = $taxPct; }
                    if (in_array('tax_amount', $poCols, true)) { $sets[] = 'tax_amount = ?'; $vals[] = $taxAmt; }
                    if (in_array('subtotal', $poCols, true)) { $sets[] = 'subtotal = ?'; $vals[] = $sub; }
                    if (in_array('total_amount', $poCols, true)) { $sets[] = 'total_amount = ?'; $vals[] = $grand; }
                    if (in_array('updated_at', $poCols, true)) { $sets[] = 'updated_at = NOW()'; }
                    if ($sets !== []) {
                        $vals[] = $id;
                        $pdo->prepare('UPDATE stocks_purchase_orders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
                    }
                }
            } catch (Throwable $e) {
            }
        }
        redirect('view_po.php?id=' . $id);
    }

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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'send_email') {
        $to = trim((string) ($_POST['recipient_email'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = (string) ($_POST['message'] ?? '');
        if ($to === '' || $subject === '') {
            flash('error', 'Recipient email and subject are required.', 'error');
            redirect('view_po.php?id=' . $id);
        }
        $attachments = [];
        if (!empty($_POST['pdf_base64'])) {
            $pdfData = (string) $_POST['pdf_base64'];
            if (strpos($pdfData, 'base64,') !== false) {
                $pdfData = explode('base64,', $pdfData)[1];
            }
            $attachments[] = [
                'content' => base64_decode($pdfData),
                'name' => 'Purchase_Order_' . ($po['purchase_no'] ?? $id) . '.pdf',
                'type' => 'application/pdf',
            ];
        }
        if (sendEmail($to, $subject, $body, true, $attachments, 'purchases')) {
            try {
                $stmtLog = $pdo->prepare('INSERT INTO email_logs (purchase_id, recipient_email, subject, sent_by) VALUES (?, ?, ?, ?)');
                $stmtLog->execute([$id, $to, $subject, $_SESSION['user_id'] ?? null]);
            } catch (PDOException $e) {
            }
            try {
                $pdo->prepare('UPDATE stocks_purchase_orders SET emailed_to = ?, emailed_at = NOW(), emailed_by = ? WHERE id = ?')
                    ->execute([$to, $_SESSION['user_id'] ?? null, $id]);
            } catch (Throwable $e) {
            }
            flash('success', 'Purchase Order sent successfully to ' . htmlspecialchars($to));
            redirect('view_po.php?id=' . $id . '&emailed=1');
        }
        flash('error', 'Failed to send email. Check SMTP settings or recipient address.', 'error');
        redirect('view_po.php?id=' . $id . '&email_failed=1');
    }

    if ($action === 'send_to_supplier') {
        if (!isSupplierLinkWorkflow($po['procurement_workflow'] ?? '') || ($po['status'] ?? '') !== PURCHASE_STATUS_DRAFT) {
            flash('success', 'Send to supplier is only available for draft supplier-link orders.', 'error');
            redirect('view_po.php?id=' . $id);
        }
        try {
            if (empty($po['public_token'])) {
                $tk = bin2hex(random_bytes(16));
                $pdo->prepare('UPDATE stocks_purchase_orders SET public_token = ? WHERE id = ?')->execute([$tk, $id]);
            }
            $pdo->prepare('UPDATE stocks_purchase_orders SET status = ?, sent_to_supplier_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute([PURCHASE_STATUS_PENDING_SUPPLIER, $id]);
            require_once dirname(__DIR__) . '/po_mailer.php';
            sendPOStatusEmail($id, 'request_quote', $pdo);
            flash('success', 'Secure link emailed to the supplier. Status is now Pending supplier.');
        } catch (Throwable $e) {
            flash('success', 'Could not send to supplier: ' . $e->getMessage(), 'error');
        }
        redirect('view_po.php?id=' . $id);
    }

    if ($action === 'accept_quote') {
        $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Approved', updated_at = NOW() WHERE id = ?")->execute([$id]);
        require_once dirname(__DIR__) . '/po_mailer.php';
        sendPOStatusEmail($id, 'approved', $pdo);
        flash('success', 'Supplier Quote Accepted! PO Approved.');
        redirect('view_po.php?id=' . $id . '&order_approved=true');
    }

    if ($action === 'request_negotiation') {
        $notes = trim((string) ($_POST['negotiation_notes'] ?? ''));
        $poCols = [];
        try {
            $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
        }
        if (in_array('updated_at', $poCols, true)) {
            $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Negotiation Requested', negotiation_notes = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$notes, $id]);
        } else {
            $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Negotiation Requested', negotiation_notes = ? WHERE id = ?")
                ->execute([$notes, $id]);
        }
        require_once dirname(__DIR__) . '/po_mailer.php';
        sendPOStatusEmail($id, 'negotiation_requested', $pdo);
        flash('success', 'Negotiation requested. Email sent to supplier.');
        redirect('view_po.php?id=' . $id . '&negotiation_sent=true');
    }

    if ($action === 'upload_invoice') {
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            $filename = (string) $_FILES['invoice_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $uploadDir = dirname(__DIR__) . '/../../uploads/invoices/' . $id . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $newFilename = 'invoice_' . date('Ymd_His') . '_internal.' . $ext;
                $dest = $uploadDir . $newFilename;
                if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $dest)) {
                    $dbPath = 'uploads/invoices/' . $id . '/' . $newFilename;
                    $poCols = [];
                    try {
                        $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    } catch (Throwable $e) {
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
        redirect('view_po.php?id=' . $id);
    }
}
