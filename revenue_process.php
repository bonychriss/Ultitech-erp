<?php
require_once 'includes/functions.php';
require_once 'includes/revenue_ledger.php';
require_once 'includes/accounting_service.php';
require_once 'includes/revenue_account_helpers.php';
require_once 'includes/accounting_settings.php';
require_once 'modules/balances/functions.php';
require_once 'includes/invoice_gl_posting.php';
requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}

// Live-schema compatibility for account columns
$revenueEntryAccountCol = resolveExistingColumn('revenue_entries', 'account_id', ['bank_account_id', 'gl_account_id', 'financial_account_id']);
$revenueCollectionAccountCol = resolveExistingColumn('revenue_collections', 'account_id', ['bank_account_id', 'gl_account_id', 'financial_account_id']);
revenue_ensure_account_schema($pdo);

// 1. CREATE REVENUE ENTRY
if (isset($_POST['action']) && $_POST['action'] === 'create_entry') {
    $createModule = trim((string) ($_POST['module'] ?? 'revenue'));
    if ($createModule === '') {
        $createModule = 'revenue';
    }
    $entry_date = $_POST['entry_date'];
    $customer_name = trim($_POST['customer_name']);
    $narration = trim($_POST['narration']);
    $payment_mode = $_POST['payment_mode'];
    $amount_total_raw = floatval($_POST['amount_exclusive']); // Note: Field name matches 'Amount' from form
    $tax_treatment = $_POST['tax_treatment'] ?? 'Exclusive';
    $vat_rate_raw = floatval($_POST['vat_rate'] ?? 18);
    
    // 1. Precise Financial Calculation (Server-side mirror of UI logic)
    $amount_exclusive = 0;
    $vat_amount = 0;
    $amount_total = 0;

    if ($tax_treatment === 'Inclusive') {
        $amount_total = round($amount_total_raw, 2);
        $vat_amount = round($amount_total * ($vat_rate_raw / (100 + $vat_rate_raw)), 2);
        $amount_exclusive = round($amount_total - $vat_amount, 2);
    } elseif ($tax_treatment === 'Exclusive') {
        $amount_exclusive = round($amount_total_raw, 2);
        $vat_amount = round($amount_exclusive * ($vat_rate_raw / 100), 2);
        $amount_total = round($amount_exclusive + $vat_amount, 2);
    } else {
        // Non-Taxable
        $amount_exclusive = round($amount_total_raw, 2);
        $vat_amount = 0;
        $amount_total = $amount_exclusive;
    }
    
    // Status Logic
    $total_paid = 0;
    $payment_status = 'Unpaid';
    $immediatePaymentModes = ['Cash', 'Bank', 'Mobile'];
    if (in_array($payment_mode, $immediatePaymentModes, true)) {
        $total_paid = $amount_total;
        $payment_status = 'Paid';
    }
    
    // File Upload (Mandatory)
    $attachment = "";
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === 0) {
        $target_dir = "uploads/revenue/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $file_ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        $file_name = "REV_" . time() . "." . $file_ext;
        $target_file = $target_dir . $file_name;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file)) {
            $attachment = $target_file;
        }
    } else {
        header('Location: revenue_create.php?module=' . urlencode($createModule) . '&error=' . urlencode('Attachment is required'));
        exit;
    }
    
    $voucher_number = generateRevenueVoucherNumber($pdo);

    $allowedCurrencies = array_keys([
        'TZS' => 1, 'USD' => 1, 'EUR' => 1, 'GBP' => 1, 'KES' => 1, 'UGX' => 1,
        'RWF' => 1, 'ZAR' => 1, 'AED' => 1, 'SAR' => 1, 'INR' => 1, 'CNY' => 1,
        'JPY' => 1, 'CHF' => 1, 'CAD' => 1, 'AUD' => 1, 'NGN' => 1,
    ]);
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'TZS')));
    if (!in_array($currency, $allowedCurrencies, true)) {
        $currency = 'TZS';
    }

    $exchangeRate = (float) ($_POST['exchange_rate'] ?? 1);
    if ($currency === 'TZS') {
        $exchangeRate = 1.0;
    } elseif ($exchangeRate <= 0) {
        header('Location: revenue_create.php?module=' . urlencode($createModule) . '&error=' . urlencode('Enter a valid exchange rate greater than zero.'));
        exit;
    }

    $revenueCategoryId = (int) ($_POST['revenue_category_id'] ?? 0);
    $revenueAccountId = (int) ($_POST['revenue_account_id'] ?? 0);
    $resolvedRevenueAccounts = revenue_resolve_posted_entry_accounts($pdo, $revenueCategoryId, $revenueAccountId);
    if ($resolvedRevenueAccounts === null) {
        header('Location: revenue_create.php?module=' . urlencode($createModule) . '&error=' . urlencode('Please select a valid revenue category and account.'));
        exit;
    }
    $revenueGlAccountId = $resolvedRevenueAccounts['account_id'];
    $revenueGlCategoryId = $resolvedRevenueAccounts['category_id'];

    if (in_array($payment_mode, $immediatePaymentModes, true) && !empty($_POST['account_id'])) {
        $depositAccountId = (int) $_POST['account_id'];
        $bucketByMode = [
            'Bank' => 'bank',
            'Cash' => 'cash',
            'Mobile' => 'mobile',
        ];
        $bucketMessages = [
            'Bank' => 'Bank Transfer requires a bank account in Deposit To.',
            'Cash' => 'Cash payment requires a cash account in Deposit To.',
            'Mobile' => 'Mobile payment requires a mobile money account in Deposit To.',
        ];
        $expectedBucket = $bucketByMode[$payment_mode] ?? '';
        $accStmt = $pdo->prepare("SELECT type FROM financial_accounts WHERE id = ? AND status = 'active' LIMIT 1");
        $accStmt->execute([$depositAccountId]);
        $accType = (string) $accStmt->fetchColumn();
        if ($accType === '' || !function_exists('balancesAccountLiquidityBucket')) {
            header('Location: revenue_create.php?module=' . urlencode($createModule) . '&error=' . urlencode('Please select a valid deposit account.'));
            exit;
        }
        $actualBucket = balancesAccountLiquidityBucket($accType);
        if ($expectedBucket === '' || $actualBucket !== $expectedBucket) {
            $msg = $bucketMessages[$payment_mode] ?? 'Please select a valid deposit account for this payment method.';
            header('Location: revenue_create.php?module=' . urlencode($createModule) . '&error=' . urlencode($msg));
            exit;
        }
    }
    
    // Updated Insert with approval_status
    try {
        $pdo->beginTransaction();

        $hasRevenueAccountCol = columnExists('revenue_entries', 'revenue_account_id', $pdo);
        $hasRevenueCategoryCol = columnExists('revenue_entries', 'revenue_category_id', $pdo);
        $hasCurrencyCol = columnExists('revenue_entries', 'currency', $pdo);
        $hasExchangeRateCol = columnExists('revenue_entries', 'exchange_rate', $pdo);

        $insertCols = [
            'voucher_number', 'entry_date', 'customer_name', 'narration', 'payment_mode',
            'amount_exclusive', 'vat_amount', 'amount_total', 'total_paid', 'payment_status',
            'approval_status', 'attachment',
        ];
        $insertVals = [
            $voucher_number, $entry_date, $customer_name, $narration, $payment_mode,
            $amount_exclusive, $vat_amount, $amount_total, $total_paid, $payment_status,
            'Pending', $attachment,
        ];

        if ($revenueEntryAccountCol) {
            $insertCols[] = $revenueEntryAccountCol;
            $insertVals[] = $_POST['account_id'] ?? null;
        }
        if ($hasRevenueCategoryCol) {
            $insertCols[] = 'revenue_category_id';
            $insertVals[] = $revenueGlCategoryId;
        }
        if ($hasRevenueAccountCol) {
            $insertCols[] = 'revenue_account_id';
            $insertVals[] = $revenueGlAccountId;
        }
        if ($hasCurrencyCol) {
            $insertCols[] = 'currency';
            $insertVals[] = $currency;
        }
        if ($hasExchangeRateCol) {
            $insertCols[] = 'exchange_rate';
            $insertVals[] = round($exchangeRate, 6);
        }

        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $stmt = $pdo->prepare(
            'INSERT INTO revenue_entries (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute($insertVals);
        $entryId = $pdo->lastInsertId();

        // --- GENERAL LEDGER: revenue recognition + immediate payment ---
        try {
            invoice_gl_post_revenue_recognition(
                $pdo,
                (int) $entryId,
                $voucher_number,
                $entry_date,
                $customer_name,
                $narration,
                $amount_total,
                $amount_exclusive,
                $vat_amount,
                $revenueGlAccountId ?: null
            );

            if (in_array($payment_mode, $immediatePaymentModes, true) && !empty($_POST['account_id'])) {
                invoice_gl_post_revenue_payment(
                    $pdo,
                    (int) $entryId,
                    $voucher_number,
                    $entry_date,
                    $amount_total,
                    (int) $_POST['account_id']
                );
            }
        } catch (Throwable $eAcc) {
            error_log('Revenue entry GL posting failed: ' . $eAcc->getMessage());
            throw $eAcc;
        }

        // If paid immediately, record in legacy Balances table for compatibility
        if (in_array($payment_mode, $immediatePaymentModes, true) && !empty($_POST['account_id'])) {
            $accountId = (int)$_POST['account_id'];
            $description = "Revenue: $voucher_number - $customer_name ($narration)";
            recordTransaction($accountId, 'credit', $amount_total, $description, 'revenue_entry', $entryId, $entry_date);
        }

        $pdo->commit();
        header('Location: revenue_create.php?module=' . urlencode($createModule) . '&success=' . urlencode('Entry created successfully (' . $voucher_number . ')'));
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: revenue_entries.php?module=revenue&error=Database Error: " . $e->getMessage());
        exit;
    }
}

// 1b. REGISTER REVENUE ENTRY (Finance): payment + deposit account + receipt — posts entry_id (revenue_entries.id)
if (isset($_POST['action']) && $_POST['action'] === 'register_entry') {
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    $paymentType = trim((string) ($_POST['payment_type'] ?? ''));
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $amountReceived = (float) ($_POST['amount_received'] ?? 0);

    $allowedPayment = ['Cash', 'Bank', 'Account Receivable'];
    if ($entryId <= 0) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Invalid entry. Use Register from a revenue row.'));
        exit;
    }
    if (!in_array($paymentType, $allowedPayment, true)) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Please select a payment type.'));
        exit;
    }
    if ($amountReceived <= 0) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Enter a valid amount received.'));
        exit;
    }
    if (($paymentType === 'Cash' || $paymentType === 'Bank') && $accountId <= 0) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Select a deposit account for Cash or Bank payments.'));
        exit;
    }

    $attachment = '';
    if (!isset($_FILES['receipt']) || !is_uploaded_file($_FILES['receipt']['tmp_name'] ?? '')) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Upload a receipt or proof of payment (JPG, PNG, or PDF, max 5MB).'));
        exit;
    }
    $f = $_FILES['receipt'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('File upload failed.'));
        exit;
    }
    if (($f['size'] ?? 0) > 5 * 1024 * 1024) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Receipt must be 5MB or smaller.'));
        exit;
    }
    $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Receipt must be JPG, PNG, or PDF.'));
        exit;
    }
    $targetDir = 'uploads/revenue/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $safeName = 'REGENT_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $f['name']);
    $targetFile = $targetDir . $safeName;
    if (!move_uploaded_file($f['tmp_name'], $targetFile)) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Could not save the uploaded file.'));
        exit;
    }
    $attachment = $targetFile;

    ensureRevenueLedgerSchema($pdo);
    ensureRevenueSourceInvoiceSchema($pdo);

    try {
        $st = $pdo->prepare("
            SELECT
                re.*,
                i.invoice_number,
                i.invoice_date,
                LOWER(TRIM(COALESCE(i.status, ''))) AS inv_status
            FROM revenue_entries re
            LEFT JOIN invoices i ON i.id = re.source_invoice_id
            WHERE re.id = ?
            LIMIT 1
        ");
        $st->execute([$entryId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Revenue entry not found.'));
            exit;
        }

        $apStat = strtolower((string) ($row['approval_status'] ?? ''));
        if ($apStat === 'voided' || $apStat === 'void') {
            header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Voided entries cannot be registered.'));
            exit;
        }
        if (($row['approval_status'] ?? '') !== 'Pending') {
            header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Only pending entries can be registered this way.'));
            exit;
        }

        $invoiceId = (int) ($row['source_invoice_id'] ?? 0);
        $invStatus = (string) ($row['inv_status'] ?? '');
        if ($invoiceId > 0 && ($invStatus === 'cancelled' || $invStatus === 'canceled')) {
            header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Cancelled invoices cannot be registered.'));
            exit;
        }

        $amountTotal = (float) ($row['amount_total'] ?? 0);
        $entryPaidBefore = (float) ($row['total_paid'] ?? 0);
        $voucherNumber = (string) ($row['voucher_number'] ?? '');
        $customerName = trim((string) ($row['customer_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Entry #' . $entryId;
        }

        $remaining = max(0, $amountTotal - $entryPaidBefore);
        if ($remaining <= 0.02) {
            header('Location: revenue_entries.php?module=revenue&success=' . urlencode('This entry has no remaining balance to register.'));
            exit;
        }
        if ($amountReceived > $remaining + 0.02) {
            header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Amount cannot exceed the remaining balance on this voucher.'));
            exit;
        }

        $newTotalPaid = min($amountTotal, $entryPaidBefore + $amountReceived);
        if ($newTotalPaid >= $amountTotal - 0.02) {
            $paymentStatus = 'Paid';
        } elseif ($newTotalPaid > 0.01) {
            $paymentStatus = 'Partial';
        } else {
            $paymentStatus = 'Unpaid';
        }

        $entryDate = $row['entry_date'] ?: date('Y-m-d');
        if (!empty($row['invoice_date'])) {
            $entryDate = $row['invoice_date'];
        }

        $pdo->beginTransaction();

        if ($revenueCollectionAccountCol) {
            $accCol = $revenueCollectionAccountCol;
            $pdo->prepare("INSERT INTO revenue_collections (entry_id, collection_date, amount_collected, {$accCol}) VALUES (?, ?, ?, ?)")
                ->execute([$entryId, $entryDate, $amountReceived, ($paymentType === 'Cash' || $paymentType === 'Bank') ? $accountId : null]);
        } else {
            $pdo->prepare('INSERT INTO revenue_collections (entry_id, collection_date, amount_collected) VALUES (?, ?, ?)')
                ->execute([$entryId, $entryDate, $amountReceived]);
        }
        $collectionId = (int) $pdo->lastInsertId();

        if ($revenueEntryAccountCol && ($paymentType === 'Cash' || $paymentType === 'Bank')) {
            $ac = $revenueEntryAccountCol;
            $pdo->prepare("UPDATE revenue_entries SET total_paid = ?, payment_status = ?, payment_mode = ?, attachment = ?, {$ac} = ? WHERE id = ?")
                ->execute([$newTotalPaid, $paymentStatus, $paymentType, $attachment, $accountId, $entryId]);
        } else {
            $pdo->prepare('UPDATE revenue_entries SET total_paid = ?, payment_status = ?, payment_mode = ?, attachment = ? WHERE id = ?')
                ->execute([$newTotalPaid, $paymentStatus, $paymentType, $attachment, $entryId]);
        }

        $invCols = invoiceTableColumns($pdo);
        if ($invoiceId > 0 && in_array('amount_paid', $invCols, true) && in_array('status', $invCols, true)) {
            $invPayStatus = $paymentStatus === 'Paid' ? 'paid' : ($paymentStatus === 'Partial' ? 'partial' : 'sent');
            $pdo->prepare('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?')
                ->execute([$newTotalPaid, $invPayStatus, $invoiceId]);
        }

        if (($paymentType === 'Cash' || $paymentType === 'Bank') && $accountId > 0 && $amountReceived > 0) {
            $invLabel = !empty($row['invoice_number']) ? " — Invoice {$row['invoice_number']}" : '';
            $description = "Revenue collection: {$voucherNumber}{$invLabel} ({$customerName})";
            recordTransaction($accountId, 'credit', $amountReceived, $description, 'revenue_collection', $entryId, $entryDate);

            invoice_gl_post_revenue_recognition(
                $pdo,
                $entryId,
                $voucherNumber,
                $entryDate,
                $customerName,
                (string) ($row['narration'] ?? ''),
                $amountTotal,
                (float) ($row['amount_exclusive'] ?? max(0, $amountTotal - (float) ($row['vat_amount'] ?? 0))),
                (float) ($row['vat_amount'] ?? 0),
                isset($row['revenue_account_id']) ? (int) $row['revenue_account_id'] : null
            );
            invoice_gl_post_revenue_payment(
                $pdo,
                $entryId,
                $voucherNumber,
                $entryDate,
                $amountReceived,
                $accountId,
                $collectionId
            );

            if ($invoiceId > 0) {
                invoice_gl_ensure_invoice_recognition($pdo, $invoiceId);
            }
        }

        if ($invoiceId > 0) {
            $pdo->prepare('UPDATE revenue_ledger SET revenue_entry_id = ? WHERE source_type = ? AND source_id = ? LIMIT 1')
                ->execute([$entryId, 'invoice', $invoiceId]);
        }

        $pdo->commit();

        if ($invoiceId > 0) {
            syncInvoiceToRevenueLedger($pdo, $invoiceId, (int) ($_SESSION['user_id'] ?? 0) ?: null);
        }

        header('Location: revenue_entries.php?module=revenue&success=' . urlencode('Payment recorded (' . $voucherNumber . ').'));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('revenue register_entry: ' . $e->getMessage());
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Registration could not be completed. Please try again.'));
        exit;
    }
}

// 2. RECEIVE DEBT PAYMENT
if (isset($_POST['action']) && $_POST['action'] === 'collect_payment') {
    $entry_id = intval($_POST['entry_id']);
    $collection_date = $_POST['collection_date'];
    $amount_collected = floatval($_POST['amount_collected']);
    
    try {
        $pdo->beginTransaction();
        
        // Insert collection record
        if ($revenueCollectionAccountCol) {
            $stmt = $pdo->prepare("INSERT INTO revenue_collections (entry_id, collection_date, amount_collected, {$revenueCollectionAccountCol}) VALUES (?, ?, ?, ?)");
            $stmt->execute([$entry_id, $collection_date, $amount_collected, $_POST['account_id'] ?? null]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO revenue_collections (entry_id, collection_date, amount_collected) VALUES (?, ?, ?)");
            $stmt->execute([$entry_id, $collection_date, $amount_collected]);
        }
        $collectionId = (int) $pdo->lastInsertId();
        
        // Get entry details
        $stmt = $pdo->prepare("SELECT voucher_number, customer_name, narration, amount_total, amount_exclusive, vat_amount, total_paid FROM revenue_entries WHERE id = ?");
        $stmt->execute([$entry_id]);
        $entry = $stmt->fetch();
        
        if ($entry) {
            $new_total_paid = $entry['total_paid'] + $amount_collected;
            $new_status = 'Partial';
            
            if ($new_total_paid >= $entry['amount_total']) {
                $new_status = 'Paid';
            }
            
            $stmt = $pdo->prepare("UPDATE revenue_entries SET total_paid = ?, payment_status = ? WHERE id = ?");
            $stmt->execute([$new_total_paid, $new_status, $entry_id]);

            // Record in Balances
                if (!empty($_POST['account_id'])) {
                    $accountId = (int)$_POST['account_id'];
                    $description = "Debt Payment: {$entry['voucher_number']} - {$entry['customer_name']} ({$entry['narration']})";
                    recordTransaction($accountId, 'credit', $amount_collected, $description, 'revenue_collection', $entry_id, $collection_date);

                    try {
                        invoice_gl_post_revenue_recognition(
                            $pdo,
                            (int) $entry_id,
                            (string) $entry['voucher_number'],
                            $collection_date,
                            (string) $entry['customer_name'],
                            (string) ($entry['narration'] ?? ''),
                            (float) ($entry['amount_total'] ?? 0),
                            (float) ($entry['amount_exclusive'] ?? max(0, (float) ($entry['amount_total'] ?? 0) - (float) ($entry['vat_amount'] ?? 0))),
                            (float) ($entry['vat_amount'] ?? 0)
                        );
                        invoice_gl_post_revenue_payment(
                            $pdo,
                            (int) $entry_id,
                            (string) $entry['voucher_number'],
                            $collection_date,
                            $amount_collected,
                            $accountId,
                            $collectionId
                        );
                    } catch (Throwable $eAcc) {
                        error_log('Revenue collection GL posting failed: ' . $eAcc->getMessage());
                        throw $eAcc;
                    }
                }
            }
        
        $pdo->commit();
        header("Location: revenue_entries.php?module=revenue&success=Payment recorded successfully");
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: revenue_entries.php?module=revenue&error=Database Error: " . $e->getMessage());
        exit;
    }
}

// 5. UPDATE ENTRY
if (isset($_POST['action']) && $_POST['action'] === 'update_entry') {
    $id = intval($_POST['id']);
    
    // Check Status first
    $stmt = $pdo->prepare('SELECT approval_status, attachment, source_invoice_id FROM revenue_entries WHERE id = ?');
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    
    if (!$entry) {
        header("Location: revenue_entries.php?module=revenue&error=Entry not found");
        exit;
    }

    if (!empty($entry['source_invoice_id']) && (int) $entry['source_invoice_id'] > 0) {
        header('Location: revenue_entries.php?module=revenue&error=' . urlencode('Sales invoices cannot be edited here. Update them from Sales.'));
        exit;
    }

    $entry_date = $_POST['entry_date'];
    $voucher_number = $_POST['voucher_number'];
    $customer_name = $_POST['customer_name'];
    $narration = $_POST['narration'];
    $amount_exclusive = floatval($_POST['amount_exclusive']);
    $vat_amount = $amount_exclusive * 0.18;
    $amount_total = $amount_exclusive + $vat_amount;
    
    // Handle File Upload
    $attachmentPath = $entry['attachment'];
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $uploadDir = 'uploads/revenue/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $fileName = time() . '_' . basename($_FILES['attachment']['name']);
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetPath)) {
            $attachmentPath = $targetPath;
        }
    }
    
    try {
        // Check if ratified - only Admin can update ratified entries
        if ($entry['approval_status'] === 'Ratified' && !isAdmin()) {
            header("Location: revenue_entries.php?module=revenue&error=Access Denied: Entry is locked.");
            exit;
        }

        $sql = "UPDATE revenue_entries SET 
                entry_date = ?, voucher_number = ?, customer_name = ?, narration = ?, 
                amount_exclusive = ?, vat_amount = ?, amount_total = ?, attachment = ?
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $entry_date, $voucher_number, $customer_name, $narration, 
            $amount_exclusive, $vat_amount, $amount_total, $attachmentPath, $id
        ]);
        
        header("Location: revenue_entries.php?module=revenue&success=Entry updated successfully");
        exit;
    } catch (PDOException $e) {
        header("Location: revenue_edit.php?id=$id&error=" . urlencode($e->getMessage()));
        exit;
    }
}

// 6. RATIFY ENTRY (Admin Only)
if (isset($_POST['action']) && $_POST['action'] === 'ratify_entry') {
    if (!isAdmin()) {
        header("Location: revenue_entries.php?module=revenue&error=Unauthorized action");
        exit;
    }
    
    $id = intval($_POST['entry_id']);
    try {
        $stmt = $pdo->prepare("UPDATE revenue_entries SET approval_status = 'Ratified' WHERE id = ?");
        $stmt->execute([$id]);

        // --- UNIFIED LEDGER: POST TO GENERAL LEDGER ---
        try {
            // Fetch entry details for posting
            $revCols = ['voucher_number', 'entry_date', 'customer_name', 'amount_exclusive', 'vat_amount', 'amount_total'];
            if (columnExists('revenue_entries', 'revenue_account_id', $pdo)) {
                $revCols[] = 'revenue_account_id';
            }
            $stmt = $pdo->prepare('SELECT ' . implode(', ', $revCols) . ' FROM revenue_entries WHERE id = ?');
            $stmt->execute([$id]);
            $rev = $stmt->fetch();

            if ($rev) {
                $accSvc = new AccountingService($pdo);
                $arAccId = $accSvc->getAccountIdByCode('1010');   // Accounts Receivable
                $revAccId = !empty($rev['revenue_account_id'])
                    ? (int) $rev['revenue_account_id']
                    : (accounting_resolve_default_sales_revenue_gl_account_id($pdo) ?: 0);
                $vatAccId = $accSvc->getAccountIdByCode('2020');  // VAT Payable
                
                if ($arAccId && $revAccId && $vatAccId) {
                    $accSvc->postEntry(
                        $rev['entry_date'],
                        $rev['voucher_number'],
                        "Revenue Ratification: {$rev['voucher_number']} - {$rev['customer_name']}",
                        [
                            ['account_id' => $arAccId, 'debit' => $rev['amount_total'], 'credit' => 0],
                            ['account_id' => $revAccId, 'debit' => 0, 'credit' => $rev['amount_exclusive']],
                            ['account_id' => $vatAccId, 'debit' => 0, 'credit' => $rev['vat_amount']]
                        ]
                    );
                }
            }
        } catch (Exception $eAcc) {
            error_log("Ledger Posting Failed for Revenue Ratification $id: " . $eAcc->getMessage());
        }
        // --- END UNIFIED LEDGER ---

        header("Location: revenue_entries.php?module=revenue&success=Entry ratified and locked.");
        exit;
    } catch (PDOException $e) {
        header("Location: revenue_entries.php?module=revenue&error=" . urlencode($e->getMessage()));
        exit;
    }
}

// 7. DELETE ENTRY
if (isset($_POST['action']) && $_POST['action'] === 'delete_entry') {
    $id = intval($_POST['entry_id']);
    
    // Check if entry exists and status
    $stmt = $pdo->prepare("SELECT approval_status FROM revenue_entries WHERE id = ?");
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    
    if (!$entry) {
        header("Location: revenue_entries.php?module=revenue&error=Entry not found");
        exit;
    }
    
    // Only Admin can delete ratified entries. Finance can only delete Pending.
    if ($entry['approval_status'] === 'Ratified' && !isAdmin()) {
        header("Location: revenue_entries.php?module=revenue&error=Cannot delete ratified entry.");
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Delete related collections if any
        $stmt = $pdo->prepare("DELETE FROM revenue_collections WHERE entry_id = ?");
        $stmt->execute([$id]);
        
        // Delete entry
        $stmt = $pdo->prepare("DELETE FROM revenue_entries WHERE id = ?");
        $stmt->execute([$id]);
        
        $pdo->commit();
        header("Location: revenue_entries.php?module=revenue&success=Entry deleted successfully");
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: revenue_entries.php?module=revenue&error=" . urlencode($e->getMessage()));
        exit;
    }
}
?>
