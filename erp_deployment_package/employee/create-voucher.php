<?php
// Force no-cache to avoid stale HTML/JS on hosts with aggressive caching (InfinityFree)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

ob_start();
require_once '../includes/functions.php';
requireLogin();

$error = '';
$success = '';

// Fetch active users to populate dropdowns
try {
    $usersStmt = $pdo->query("SELECT full_name, department, role FROM users WHERE is_active = 1 ORDER BY full_name");
    $allUsersRaw = $usersStmt->fetchAll();
    // Filter out admin users for approval dropdowns
    $allUsers = array_values(array_filter($allUsersRaw, function($u){ return isset($u['role']) ? $u['role'] !== ROLE_ADMIN : true; }));
    // Build Finance-only list for Checked By
    $financeUsers = array_values(array_filter($allUsers, function($u){
        return isset($u['department']) && strcasecmp(trim($u['department']), 'Finance') === 0;
    }));
} catch (Exception $e) {
    $allUsers = [];
    $financeUsers = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isDraft = isset($_POST['action']) && $_POST['action'] === 'draft';
    // Enable debug mode via URL parameter: ?debug=1 or via POST
    $debugMode = (isset($_GET['debug']) && $_GET['debug'] === '1') || (isset($_POST['debug']) && $_POST['debug'] === '1');
    // Track transaction state explicitly to avoid rollback errors
    $txStarted = false;
    $committed = false;
    if (function_exists('app_log')) { app_log('create-voucher: POST begin draft=' . ($isDraft?'1':'0') . ' keys=' . implode(',', array_keys($_POST))); }
    try {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
    // Validate form data (relaxed when saving draft)
    $payee_name = isset($_POST['payee_name']) ? trim($_POST['payee_name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $currency = $_POST['currency'] ?? 'TZS';
    $supporting_documents = intval($_POST['supporting_documents']);
    // Applicant: accept posted selection; fallback to current user (full name preferred)
    $applicant = isset($_POST['applicant'])
        ? trim($_POST['applicant'])
        : trim($_SESSION['full_name'] ?? ($_SESSION['username'] ?? ''));
    $department_manager = isset($_POST['department_manager']) ? trim($_POST['department_manager']) : '';
    // Auto-fill Prepared By from current session user (ignore posted value to enforce policy)
    $prepared_by = trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
    $checked_by = isset($_POST['checked_by']) ? trim($_POST['checked_by']) : '';
    // General Manager is decided later upon approval; keep blank at creation time
    $general_manager = null; // store NULL in DB for now
        $date_created = isset($_POST['date_created']) && $_POST['date_created'] !== ''
            ? date('Y-m-d', strtotime($_POST['date_created']))
            : date('Y-m-d'); // default to today for drafts

        if (!$isDraft) {
            if (empty($payee_name) || empty($description) || empty($date_created)) {
                throw new Exception('Please fill in all required fields');
            }
            // Require approvals selections for full submission
            if ($department_manager === '' || $checked_by === '') {
                throw new Exception('Please select Department Manager and Checked By');
            }
            // Ensure prepared_by is not empty (should always be set from session, but check anyway)
            if (empty($prepared_by)) {
                throw new Exception('Prepared By information is missing. Please contact support.');
            }
        }
        
        // Validate voucher items
        $items = [];
        $total_amount = 0;
        
        // Debug: Log what we received
        if (function_exists('app_log')) { 
            app_log('create-voucher: POST payment_type=' . (isset($_POST['payment_type']) ? (is_array($_POST['payment_type']) ? 'array(' . count($_POST['payment_type']) . ')' : 'not-array:' . $_POST['payment_type']) : 'not-set'));
            app_log('create-voucher: POST keys=' . implode(',', array_keys($_POST)));
        }
        
        // Build items gracefully even if some arrays are missing; we'll validate after
        $arr_type   = (isset($_POST['payment_type']) && is_array($_POST['payment_type'])) ? $_POST['payment_type'] : [];
        $arr_budget = (isset($_POST['budget_type']) && is_array($_POST['budget_type'])) ? $_POST['budget_type'] : [];
        $arr_name   = (isset($_POST['name']) && is_array($_POST['name'])) ? $_POST['name'] : [];
        $arr_amount = (isset($_POST['amount']) && is_array($_POST['amount'])) ? $_POST['amount'] : [];
        $arr_desc   = (isset($_POST['item_description']) && is_array($_POST['item_description'])) ? $_POST['item_description'] : [];
        $maxRows = max(count($arr_type), count($arr_budget), count($arr_name), count($arr_amount), count($arr_desc));
        if ($maxRows === 0 && $debugMode && function_exists('app_log')) { app_log('create-voucher: No item arrays present in POST'); }
        // Determine a default payment type if none provided (helps hosts that strip [] params sometimes)
        $firstPostedType = '';
        if (!empty($arr_type)) {
            foreach ($arr_type as $t) { if (trim((string)$t) !== '') { $firstPostedType = trim((string)$t); break; } }
        }
        $fallbackType = $firstPostedType !== '' ? $firstPostedType : 'Cash Payment';
        for ($i = 0; $i < $maxRows; $i++) {
            $payment_type = isset($arr_type[$i]) ? trim((string)$arr_type[$i]) : $fallbackType;
            $budget_type = isset($arr_budget[$i]) ? trim((string)$arr_budget[$i]) : '';
            $name = isset($arr_name[$i]) ? trim((string)$arr_name[$i]) : '';
            $amount = isset($arr_amount[$i]) ? floatval($arr_amount[$i]) : 0.0;
            $item_description = isset($arr_desc[$i]) ? trim((string)$arr_desc[$i]) : '';

            if ($payment_type && $budget_type && $name && $amount > 0) {
                $items[] = [
                    'payment_type' => $payment_type,
                    'budget_type' => $budget_type,
                    'name' => $name,
                    'amount' => $amount,
                    'description' => $item_description
                ];
                $total_amount += $amount;
            }
        }
        if (!$isDraft && empty($items)) {
            throw new Exception('Please add at least one valid payment item');
        }
        if (function_exists('app_log')) { app_log('create-voucher: itemsValid=' . count($items) . ' total_amount=' . $total_amount); }
        
        // Generate voucher number
        $voucher_no = generateVoucherNumber();
        
    // Start transaction
    $pdo->beginTransaction();
    $txStarted = true;
        
        // Insert payment voucher
            // Insert payment voucher (single attempt with fallback for checked_by column)
            $insertSuccess = false;
            try {
                // Try insert with checked_by
                $stmt = $pdo->prepare("
                    INSERT INTO payment_vouchers 
                    (voucher_no, payee_name, description, currency, total_amount, supporting_documents, 
                     applicant, department_manager, general_manager, created_by, date_created, prepared_by, checked_by, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $voucher_no, ($payee_name !== '' ? $payee_name : '(Draft)'), $description, $currency, $total_amount,
                    $supporting_documents, $applicant, $department_manager, $general_manager,
                    $_SESSION['user_id'], $date_created, $prepared_by, $checked_by, STATUS_PENDING
                ]);
                $insertSuccess = true;
                if (function_exists('app_log')) { app_log('create-voucher: inserted voucher with checked_by'); }
            } catch (Exception $e) {
                // Check if error is due to missing checked_by column
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, 'checked_by') !== false || strpos($errorMsg, 'Unknown column') !== false) {
                    // Fallback for environments where the column was not added
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO payment_vouchers 
                            (voucher_no, payee_name, description, currency, total_amount, supporting_documents, 
                             applicant, department_manager, general_manager, created_by, date_created, prepared_by, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $voucher_no, ($payee_name !== '' ? $payee_name : '(Draft)'), $description, $currency, $total_amount,
                            $supporting_documents, $applicant, $department_manager, $general_manager,
                            $_SESSION['user_id'], $date_created, $prepared_by, STATUS_PENDING
                        ]);
                        $insertSuccess = true;
                        if (function_exists('app_log')) { app_log('create-voucher: inserted voucher without checked_by column (fallback)'); }
                    } catch (Exception $e2) {
                        // Both attempts failed, throw the original error
                        throw new Exception('Failed to create voucher: ' . $e2->getMessage() . ' (Original: ' . $errorMsg . ')');
                    }
                } else {
                    // Different error, re-throw it
                    throw $e;
                }
            }
            
            if (!$insertSuccess) {
                throw new Exception('Failed to insert voucher into database');
            }

        $voucher_id = $pdo->lastInsertId();
        if (function_exists('app_log')) { app_log('create-voucher: voucher_id=' . $voucher_id); }
        
        // Insert voucher items (if any)
        if (!empty($items)) {
            $stmt = $pdo->prepare("
                INSERT INTO voucher_items (voucher_id, payment_type, budget_type, name, amount, description) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $stmt->execute([
                    $voucher_id,
                    $item['payment_type'],
                    $item['budget_type'], 
                    $item['name'],
                    $item['amount'],
                    $item['description']
                ]);
                if (function_exists('app_log')) { app_log('create-voucher: item inserted payment_type=' . $item['payment_type'] . ' amount=' . $item['amount']); }
            }
        }
        
        // Handle file uploads for supporting documents
        $uploadedCount = 0;
        if (!empty($_FILES['supporting_files']) && isset($_FILES['supporting_files']['name']) && is_array($_FILES['supporting_files']['name'])) {
            ensureVoucherAttachmentsSchema();
            $baseDir = ensureVoucherUploadsDir();
            $voucherDir = $baseDir . DIRECTORY_SEPARATOR . $voucher_id;
            if (!is_dir($voucherDir)) { @mkdir($voucherDir, 0775, true); }
            if (is_dir($voucherDir) && !is_writable($voucherDir)) { @chmod($voucherDir, 0775); }

            $allowedExt = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx'];
            $maxSize = 10 * 1024 * 1024; // 10MB per file
            $names = $_FILES['supporting_files']['name'];
            $tmps  = $_FILES['supporting_files']['tmp_name'];
            $types = $_FILES['supporting_files']['type'];
            $sizes = $_FILES['supporting_files']['size'];
            $errs  = $_FILES['supporting_files']['error'];
            $count = count($names);
            for ($i = 0; $i < $count; $i++) {
                if (!isset($names[$i]) || $errs[$i] !== UPLOAD_ERR_OK) continue;
                $orig = $names[$i];
                $size = (int)($sizes[$i] ?? 0);
                $mime = (string)($types[$i] ?? 'application/octet-stream');
                $tmp  = $tmps[$i];
                if ($size <= 0 || $size > $maxSize) continue;
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) continue;
                // Build a safe unique file name
                $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
                if ($safeBase === '') { $safeBase = 'file'; }
                $unique = $safeBase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $destAbs = $voucherDir . DIRECTORY_SEPARATOR . $unique;
                $destRel = 'assets/uploads/vouchers/' . $voucher_id . '/' . $unique; // relative path for web
                if (@move_uploaded_file($tmp, $destAbs)) {
                    // record row
                    addVoucherAttachment($voucher_id, $destRel, $orig, $mime, $size, (int)$_SESSION['user_id']);
                    $uploadedCount++;
                    if (function_exists('app_log')) { app_log('create-voucher: file uploaded original=' . $orig . ' stored=' . $destRel . ' size=' . $size); }
                }
            }
            // If any files uploaded, update the numeric count field to reflect reality
            if ($uploadedCount > 0) {
                try {
                    $up = $pdo->prepare("UPDATE payment_vouchers SET supporting_documents = ? WHERE id = ?");
                    $up->execute([$uploadedCount, $voucher_id]);
                } catch (Exception $e) { /* ignore */ }
            }
        }
        if (function_exists('app_log')) { app_log('create-voucher: uploadedCount=' . $uploadedCount); }

        // Log the creation (catch any logging errors separately)
        try {
            logVoucherAction($voucher_id, $_SESSION['user_id'], 'created');
        } catch (Exception $e) {
            // Log failed but voucher was created - continue
            error_log("Voucher log failed: " . $e->getMessage());
        }
        
        // Commit transaction (only if still active)
        if ($txStarted && $pdo->inTransaction()) {
            $pdo->commit();
            $committed = true;
        }

    // Post-commit non-critical actions (should not affect success shown to user)
    try { notifyAdminsNewVoucher($voucher_id); } catch (Throwable $e2) { error_log('notifyAdminsNewVoucher failed: '.$e2->getMessage()); }
    // Notify selected Finance user (Checked By)
    try { notifyCheckedByAssignee($voucher_id); } catch (Throwable $e3) { error_log('notifyCheckedByAssignee failed: '.$e3->getMessage()); }

        // Safe redirect
        if ($isDraft) {
            $redirectUrl = rtrim(APP_BASE_PATH, '/') . '/employee/edit-voucher.php?id=' . $voucher_id . '&draft=1';
        } else {
            $redirectUrl = 'dashboard.php?msg=created';
        }
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
            exit();
        } else {
            // Headers already sent (e.g., due to BOM or stray output). Avoid inline JS (blocked by CSP) and use meta refresh.
            if (function_exists('app_log')) { app_log('create-voucher: headers already sent, using meta refresh to ' . $redirectUrl); }
            $safe = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $safe . '"><title>Redirecting...</title></head><body>Redirecting... <a href="' . $safe . '">Continue</a></body></html>';
            exit();
        }
        
    } catch (Exception $e) {
        // Roll back only if we actually started and it's still active
        if ($txStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $rbEx) { error_log('Rollback failed (maybe already done): '.$rbEx->getMessage()); }
        }
        // Show more specific error messages for common issues
        $errorMsg = $e->getMessage();
        $userFriendlyMsg = 'An error occurred while creating the voucher. Please review your entries and try again.';
        
        // Provide more specific error messages for common issues
        if (strpos($errorMsg, 'SQLSTATE') !== false) {
            if (strpos($errorMsg, 'Integrity constraint') !== false) {
                $userFriendlyMsg = 'Database constraint error. Please check that all required fields are filled correctly.';
            } elseif (strpos($errorMsg, 'Column') !== false && strpos($errorMsg, 'cannot be null') !== false) {
                $userFriendlyMsg = 'Missing required information. Please fill in all required fields.';
            } else {
                $userFriendlyMsg = 'Database error occurred. Please try again or contact support.';
            }
        } elseif (strpos($errorMsg, 'Please') !== false || strpos($errorMsg, 'required') !== false) {
            // Use the validation error message directly
            $userFriendlyMsg = $errorMsg;
        }
        
        // In debug mode, show full error; otherwise show user-friendly message
        // Also show detailed error if it's a validation error (contains "Please")
        if ($debugMode || strpos($errorMsg, 'Please') !== false) {
            $error = $errorMsg;
        } else {
            $error = $userFriendlyMsg;
        }
        error_log('Voucher creation failed: '.$errorMsg);
        error_log($e->getTraceAsString());
        if (function_exists('app_log')) { app_log('Voucher creation failed: '.$errorMsg.' TRACE: '.$e->getTraceAsString()); }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Payment Voucher - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Compact page-scoped tweaks for Create Voucher */
        body.dashboard .main-content { padding: 18px 16px; }
        body.dashboard .actions { margin-bottom: 18px; }
    body.dashboard .actions .btn { padding: 8px 14px; font-size: 13px; border-radius: 0; }

        body.dashboard .form-container {
            padding: 18px;
            border-radius: 0;
            max-width: 900px;
            margin: 0 auto;
            background: transparent;
            box-shadow: none;
        }
        body.dashboard .form-container h2 { font-size: 17px; margin-bottom: 12px; }

        /* Form density */
        body.dashboard .form-row { gap: 14px; }
        body.dashboard .form-group { margin-bottom: 14px; }
        body.dashboard .form-group label { font-size: 13px; margin-bottom: 4px; }
        body.dashboard .form-group input,
        body.dashboard .form-group select,
        body.dashboard .form-group textarea { padding: 10px 12px; font-size: 14px; }
        body.dashboard .form-group textarea { min-height: 110px; }

        /* Items section */
        body.dashboard .voucher-items { margin: 18px 0; }
        body.dashboard .voucher-items h3 { font-size: 16px; margin-bottom: 8px; }
    body.dashboard .add-item { padding: 8px 12px; font-size: 13px; border-radius: 0; margin-bottom: 10px; }
        body.dashboard .voucher-item { 
            gap: 12px; 
            margin-bottom: 10px; 
            padding: 12px; 
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr 1fr 32px; /* five fields + icon */
            align-items: start; 
        }
        /* Make remove button an icon-only control */
        body.dashboard .voucher-item .remove-item { 
            padding: 0 !important; 
            width: 28px !important; 
            height: 28px !important; 
            background: transparent !important; 
            color: inherit; 
            justify-self: end;
        }

        /* Totals */
        body.dashboard .total-amount { font-size: 17px; margin: 14px 0; padding: 12px; }

        /* Submit button */
    body.dashboard button.btn[type="submit"] { padding: 10px 16px; font-size: 14px; border-radius: 0; }

        /* Slightly smaller company logo in header on this page */
        body.dashboard .company-logo-img { height: 62px; }

        @media (max-width: 640px) {
            body.dashboard .form-row { grid-template-columns: 1fr !important; gap: 10px; }
            body.dashboard .voucher-item { grid-template-columns: 1fr !important; }
        }

        /* Step headings and helper text */
        .step-title { 
            font-size: 15px; 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: .02em; 
            color: #374151; 
            margin: 14px 0 8px; 
        }
        .help-text { font-size: 12px; color: #6b7280; margin-top: 4px; }
    .voucher-items-header { display:grid; grid-template-columns:1fr 1fr 1fr 1fr 1fr 32px; gap:12px; font-weight:600; font-size:12px; padding:4px 12px 8px; border-bottom:1px solid #e5e7eb; margin-bottom:4px; }
    .voucher-item.no-label label { display:none !important; }
    /* Force-hide any legacy labels within voucher item rows */
    .voucher-items .voucher-item label { display:none !important; }
        
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content">
        <div class="actions" style="display:flex; align-items:center; gap:8px;">
            <a href="dashboard.php" class="icon-link icon-neutral" title="Back" aria-label="Back">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <!-- Save as Draft (top) -->
            <button type="button" class="icon-link icon-neutral" title="Save as Draft" aria-label="Save as Draft" onclick="saveAsDraft()">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M17 3v4H7V3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M7 13h10v6H7z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>

        <div class="form-container">
            <h2>Create New Payment Voucher</h2>
            
            <?php if ($error): ?>
                <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <?= htmlspecialchars($success) ?>
                    <a href="dashboard.php" style="color: white; text-decoration: underline;">Go to Dashboard</a>
                </div>
            <?php endif; ?>

            <form method="POST" id="voucherForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create" />
                <div class="step-title">Voucher details</div>
                <div class="form-row">
                    <div class="form-group">
                            <label for="payee_name">Payee Name *</label>
                        <input type="text" id="payee_name" name="payee_name" required value="">
                    </div>
                    <div class="form-group">
                        <label for="date_created">Date *</label>
                        <input type="date" id="date_created" name="date_created" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="currency">Currency</label>
                        <select id="currency" name="currency">
                            <option value="TZS" <?= (!isset($_POST['currency']) || $_POST['currency'] === 'TZS') ? 'selected' : '' ?>>TZS</option>
                            <option value="USD" <?= (isset($_POST['currency']) && $_POST['currency'] === 'USD') ? 'selected' : '' ?>>USD</option>
                            <option value="CNY" <?= (isset($_POST['currency']) && $_POST['currency'] === 'CNY') ? 'selected' : '' ?>>CNY</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="supporting_documents">Supporting Documents (Qty.)</label>
                        <input type="number" id="supporting_documents" name="supporting_documents" min="0" placeholder="e.g. 6" value="">
                        <div class="help-text">Number of attachments (invoices, receipts, etc.).</div>
                    </div>
                </div>

                <div class="step-title">Payment details</div>
                <div class="voucher-items">
                    <h3>Payment Details</h3>
                    <div class="help-text">Select payment and budget types, enter amounts. Name is populated from Payee Name.</div>
                    <button type="button" class="add-item" onclick="addVoucherItem()">Add Item</button>
                    <div id="voucher-items-header" class="voucher-items-header">
                        <div>Payment Type</div>
                        <div>Budget Type</div>
                        <div>Name</div>
                        <div>Amount</div>
                        <div>Item Description</div>
                        <div></div>
                    </div>
                    <div id="voucher-items-container">
                        <!-- Items will be added here dynamically -->
                        <!-- Server-side fallback (in case JS is blocked): one minimal row so POST contains arrays -->
                        <div class="voucher-item no-label server-fallback" style="display:none">
                            <div class="form-group no-label">
                                <select aria-label="Payment Type" name="payment_type[]">
                                    <option value="">Select Type</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Cash Payment">Cash Payment</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Mobile Payment">Mobile Payment</option>
                                </select>
                            </div>
                            <div class="form-group no-label">
                                <select aria-label="Budget Type" name="budget_type[]">
                                    <option value="">Select Budget</option>
                                    <option value="Operational Expenses">Operational Expenses</option>
                                    <option value="Procurement &amp; Supplies">Procurement &amp; Supplies</option>
                                    <option value="Employee Costs">Employee Costs</option>
                                    <option value="Sales &amp; Marketing">Sales &amp; Marketing</option>
                                    <option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>
                                    <option value="Administration &amp; Management">Administration &amp; Management</option>
                                    <option value="Projects &amp; Capital Expenditure (CAPEX)">Projects &amp; Capital Expenditure (CAPEX)</option>
                                    <option value="Financial Obligations">Financial Obligations</option>
                                    <option value="Tax &amp; Compliance">Tax &amp; Compliance</option>
                                    <option value="Others / Miscellaneous">Others / Miscellaneous</option>
                                </select>
                            </div>
                            <div class="form-group no-label">
                                <input aria-label="Name" type="text" name="name[]" value="" placeholder="Payee" readonly>
                            </div>
                            <div class="form-group no-label">
                                <input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="form-group no-label">
                                <input aria-label="Item Description" type="text" name="item_description[]" placeholder="Description">
                            </div>
                            <div></div>
                        </div>
                        <noscript>
                            <style>.server-fallback{display:grid !important;}</style>
                            <div style="font-size:12px;color:#b45309;margin-top:4px;">JavaScript is disabled or blocked. Please fill this single item and submit.</div>
                        </noscript>
                    </div>
                    
                    <div class="total-amount">
                        Total Amount: <span id="currency-symbol">TZS</span> <span id="total-amount">0.00</span>
                    </div>
                </div>

                
                    <div class="step-title">Description / Justification</div>
                    <div class="form-group">
                        <label for="description">Description *</label>
                        <textarea id="description" name="description" required 
                                  placeholder="What is this payment for? Include brief purpose and key items."></textarea>
                    </div>

                <div class="step-title">Supporting documents</div>
                <div class="form-group">
                    <label for="supporting_files">Upload files (images, PDF, Office docs)</label>
                    <input type="file" id="supporting_files" name="supporting_files[]" multiple 
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,image/*,application/pdf">
                    <div class="help-text">You can attach invoices, receipts, quotations, etc. Max ~10MB per file.</div>
                </div>

                <div class="step-title">Approvals</div>
                <!-- Row 1: Applicant | Department Manager -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="applicant">Applicant</label>
                        <?php $selectedApplicant = ''; ?>
                        <select id="applicant" name="applicant" required>
                            <option value="">â€” Select user â€”</option>
                            <?php foreach ($allUsers as $u): $name = trim($u['full_name']); ?>
                                <option value="<?= htmlspecialchars($name) ?>" <?= ($selectedApplicant === $name ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="department_manager">Department Manager</label>
                        <?php $selectedDeptMgr = ''; ?>
                        <select id="department_manager" name="department_manager" required>
                            <option value="">â€” Select user â€”</option>
                            <?php foreach ($allUsers as $u): $name = $u['full_name']; ?>
                                <option value="<?= htmlspecialchars($name) ?>" <?= ($selectedDeptMgr === $name ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Row 2: (Prepared By hidden) | Checked By -->
                <div class="form-row">
                    <input type="hidden" id="prepared_by" name="prepared_by" value="<?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>">
                    <div class="form-group" style="flex:1;">
                        <label for="checked_by">Checked By</label>
                        <?php $selectedChecked = ''; ?>
                        <select id="checked_by" name="checked_by" required>
                            <option value="">â€” Select user â€”</option>
                            <?php foreach (($financeUsers ?? []) as $u): $name = $u['full_name']; ?>
                                <option value="<?= htmlspecialchars($name) ?>" <?= ($selectedChecked === $name ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Only Finance department users can be selected for Checked By.</div>
                    </div>
                </div>

                <!-- General Manager hidden to reduce confusion; set during approval -->
                <input type="hidden" id="general_manager" name="general_manager" value="">

                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="submit" class="btn">Create Voucher</button>
                    <button type="button" class="icon-link icon-neutral" title="Save as Draft" aria-label="Save as Draft" onclick="saveAsDraft()">
                        <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M17 3v4H7V3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M7 13h10v6H7z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <span class="help-text">Use the disk icon to save an incomplete voucher as Draft.</span>
                </div>
            </form>
        </div>
    </main>

                <script src="../assets/js/voucher-v5.v10.js?v=10" onerror="this.dataset.err=1"></script>
                     <script>
                     // Hard kill any residual lock icons from cached legacy scripts
                     (function(){
                         function removeLocks(){ document.querySelectorAll('.lock-icon').forEach(el=>el.remove()); }
                         document.addEventListener('DOMContentLoaded', removeLocks);
                         var mo = new MutationObserver(removeLocks); mo.observe(document.documentElement,{childList:true,subtree:true});
                     })();
                     </script>
                <script>
                    // Robust fallback: if voucher-v4.js didn't load (404/cache/CDN) or addVoucherItem is undefined,
                    // try loading the legacy voucher.js; as a last resort, define a minimal inline addVoucherItem.
                    (function(){
                        function loadScript(src, cb){
                            try {
                                var s = document.createElement('script');
                                s.src = src;
                                s.onload = function(){ try { cb && cb(null); } catch(_){} };
                                s.onerror = function(){ try { cb && cb(new Error('load-failed')); } catch(_){} };
                                document.head.appendChild(s);
                            } catch(e){ try { cb && cb(e); } catch(_){} }
                        }
                        function defineMinimalVoucherFns(){
                            if (typeof window.removeVoucherItem !== 'function') {
                                window.removeVoucherItem = function(id){ var el=document.getElementById(id); if(el){ el.remove(); } };
                            }
                            if (typeof window.calculateTotal !== 'function') {
                                window.calculateTotal = function(){
                                    var total = 0;
                                    document.querySelectorAll('input[name="amount[]"]').forEach(function(inp){
                                        var v = parseFloat(inp.value||'0'); if(!isNaN(v)) total += v;
                                    });
                                    var t = document.getElementById('total-amount'); if(t){ t.textContent = total.toFixed(2); }
                                };
                            }
                            if (typeof window.addVoucherItem !== 'function') {
                                window.addVoucherItem = function(){
                                    try {
                                        var c = document.getElementById('voucher-items-container'); if(!c) return;
                                        var payee = (document.getElementById('payee_name')||{}).value||'';
                                        var idx = (c.children.length||0)+1;
                                        var div = document.createElement('div');
                                        div.className = 'voucher-item no-label';
                                        div.id = 'item-'+idx;
                                        div.innerHTML = '\n        <div class="form-group no-label">\n            <select aria-label="Payment Type" name="payment_type[]" required>\n                <option value="">Select Type</option>\n                <option value="Bank Transfer">Bank Transfer</option>\n                <option value="Cash Payment">Cash Payment</option>\n                <option value="Cheque">Cheque</option>\n                <option value="Mobile Payment">Mobile Payment</option>\n            </select>\n        </div>\n        <div class="form-group no-label">\n            <select aria-label="Budget Type" name="budget_type[]" required>\n                <option value="">Select Budget</option>\n                <option value="Operational Expenses">Operational Expenses</option>\n                <option value="Procurement &amp; Supplies">Procurement &amp; Supplies</option>\n                <option value="Employee Costs">Employee Costs</option>\n                <option value="Sales &amp; Marketing">Sales &amp; Marketing</option>\n                <option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>\n                <option value="Administration &amp; Management">Administration &amp; Management</option>\n                <option value="Projects &amp; Capital Expenditure (CAPEX)">Projects &amp; Capital Expenditure (CAPEX)</option>\n                <option value="Financial Obligations">Financial Obligations</option>\n                <option value="Tax &amp; Compliance">Tax &amp; Compliance</option>\n                <option value="Others / Miscellaneous">Others / Miscellaneous</option>\n            </select>\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Name" type="text" name="name[]" required placeholder="e.g. NAFIS" value="'+ (payee||'') +'" readonly>\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" required value="" oninput="calculateTotal()" placeholder="0.00">\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Item Description" type="text" name="item_description[]" placeholder="e.g. Masks, Reflector" value="">\n        </div>\n        <button type="button" class="icon-btn icon-danger remove-item" title="Delete item" aria-label="Delete item" onclick="removeVoucherItem(\'item-'+idx+'\')" style="justify-self:end; align-self:center;">\n            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">\n                <polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n            </svg>\n        </button>';
                                        c.appendChild(div);
                                        if (typeof window.calculateTotal === 'function') { window.calculateTotal(); }
                                    } catch(e) { /* silent */ }
                                };
                            }
                        }
                        function ensureVoucher(){
                            try {
                                if (typeof window.addVoucherItem === 'function') return; // all good
                                // Try fallback to legacy file
                                loadScript('../assets/js/voucher.js?v=1004', function(){ // updated budgets list; v1004 bust
                                    if (typeof window.addVoucherItem !== 'function') {
                                        defineMinimalVoucherFns();
                                    }
                                });
                            } catch(e) { defineMinimalVoucherFns(); }
                        }
                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', ensureVoucher);
                        } else { ensureVoucher(); }
                    })();
                </script>
                <script>
                // Defensive enhancer: guarantee Add Item always works even if primary script failed mid-file
                document.addEventListener('DOMContentLoaded', function(){
                    var addBtn = document.querySelector('.add-item');
                    if(!addBtn) return;
                    // If the onclick handler failed because addVoucherItem not defined, attach a safe listener
                    addBtn.addEventListener('click', function(ev){
                        if (typeof window.addVoucherItem === 'function') return; // native handler will run
                        console.warn('[voucher] addVoucherItem missing at click time; defining minimal fallback');
                        try {
                            // Define a minimal label-free fallback then execute
                            window.addVoucherItem = function(){
                                var c = document.getElementById('voucher-items-container'); if(!c) return;
                                var payee = (document.getElementById('payee_name')||{}).value||'';
                                var idx = (c.children.length||0)+1;
                                var div = document.createElement('div');
                                div.className = 'voucher-item no-label';
                                div.id = 'item-'+idx;
                                div.innerHTML = '\n        <div class="form-group no-label">\n            <select aria-label="Payment Type" name="payment_type[]" required>\n                <option value="">Select Type</option>\n                <option value="Bank Transfer">Bank Transfer</option>\n                <option value="Cash Payment">Cash Payment</option>\n                <option value="Cheque">Cheque</option>\n                <option value="Mobile Payment">Mobile Payment</option>\n            </select>\n        </div>\n        <div class="form-group no-label">\n            <select aria-label="Budget Type" name="budget_type[]" required>\n                <option value="">Select Budget</option>\n                <option value="Operational Expenses">Operational Expenses</option>\n                <option value="Procurement &amp; Supplies">Procurement &amp; Supplies</option>\n                <option value="Employee Costs">Employee Costs</option>\n                <option value="Sales &amp; Marketing">Sales &amp; Marketing</option>\n                <option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>\n                <option value="Administration &amp; Management">Administration &amp; Management</option>\n                <option value="Projects &amp; Capital Expenditure (CAPEX)">Projects &amp; Capital Expenditure (CAPEX)</option>\n                <option value="Financial Obligations">Financial Obligations</option>\n                <option value="Tax &amp; Compliance">Tax &amp; Compliance</option>\n                <option value="Others / Miscellaneous">Others / Miscellaneous</option>\n            </select>\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Name" type="text" name="name[]" required placeholder="e.g. NAFIS" value="'+payee+'" readonly>\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" required value="" oninput="calculateTotal()" placeholder="0.00">\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Item Description" type="text" name="item_description[]" placeholder="e.g. Masks, Reflector" value="">\n        </div>\n        <button type="button" class="icon-btn icon-danger remove-item" title="Delete item" aria-label="Delete item" onclick="removeVoucherItem(\'item-'+idx+'\')" style="justify-self:end; align-self:center;">\n            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">\n                <polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n            </svg>\n        </button>';
                                c.appendChild(div);
                                if (typeof window.calculateTotal === 'function') { window.calculateTotal(); }
                            };
                            // Execute immediately after defining
                            window.addVoucherItem();
                        } catch(e){ console.error(e); }
                    }, { once: true }); // only run this shim once
                });
                </script>
        <script>
            // Fallback label fix in case a cached voucher.js still renders the old text
            (function(){
                function fixLabels(){
                    try {
                        var labels = document.querySelectorAll('.voucher-item label');
                        labels.forEach(function(l){
                            if (!l) return;
                            var t = (l.textContent || '').trim().toLowerCase();
                            if (t === 'name/description') { l.textContent = 'Name'; }
                        });
                    } catch(e) { /* ignore */ }
                }
                document.addEventListener('DOMContentLoaded', function(){
                    fixLabels();
                    setTimeout(fixLabels, 100); // after initial addVoucherItem()
                    // Also patch when user adds new items
                    var addBtn = document.querySelector('.add-item');
                    if (addBtn) {
                        addBtn.addEventListener('click', function(){ setTimeout(fixLabels, 50); });
                    }
                });
            })();
        </script>
    <script>
        function saveAsDraft(){
            try {
                // Manual draft: just persist to localStorage (no server round-trip) and show toast
                if (typeof window.saveDraft === 'function') { window.saveDraft(false); }
            } catch(e) { console && console.error && console.error(e); }
        }

        // Client-side validation and confirmation for full submission
        document.addEventListener('DOMContentLoaded', function(){
            var form = document.getElementById('voucherForm');
            if (!form) return;
            form.addEventListener('submit', function(e){
                try {
                    var actionField = form.querySelector('input[name="action"]');
                    var actionVal = actionField ? actionField.value : 'create';
                    if (actionVal === 'draft') { return; }

                    var missing = [];
                    var payee = (document.getElementById('payee_name') || {}).value || '';
                    var dateC = (document.getElementById('date_created') || {}).value || '';
                    var desc = (document.getElementById('description') || {}).value || '';
                    var deptMgr = (document.getElementById('department_manager') || {}).value || '';
                    var preparedBy = (document.getElementById('prepared_by') || {}).value || '';
                    var checkedBy = (document.getElementById('checked_by') || {}).value || '';

                    if (!payee.trim()) missing.push('Payee Name');
                    if (!dateC.trim()) missing.push('Date');
                    if (!desc.trim()) missing.push('Description');
                    if (!deptMgr.trim()) missing.push('Department Manager');
                    if (!preparedBy.trim()) missing.push('Prepared By');
                    if (!checkedBy.trim()) missing.push('Checked By');

                    // Validate at least one valid item
                    var types = Array.from(form.querySelectorAll('select[name="payment_type[]"], input[name="payment_type[]"]'));
                    var budgets = Array.from(form.querySelectorAll('select[name="budget_type[]"], input[name="budget_type[]"]'));
                    var names = Array.from(form.querySelectorAll('input[name="name[]"]'));
                    var amounts = Array.from(form.querySelectorAll('input[name="amount[]"]'));
                    var validCount = 0;
                    var maxLen = Math.max(types.length, budgets.length, names.length, amounts.length);
                    for (var i=0; i<maxLen; i++) {
                        var t = (types[i] && types[i].value || '').trim();
                        var b = (budgets[i] && budgets[i].value || '').trim();
                        var n = (names[i] && names[i].value || '').trim();
                        var a = parseFloat((amounts[i] && amounts[i].value || '0').replace(/,/g, ''));
                        if (t && b && n && !isNaN(a) && a > 0) { validCount++; }
                    }
                    if (validCount === 0) missing.push('At least one payment item');

                    if (missing.length > 0) {
                        e.preventDefault();
                        alert('Please complete the following before submitting:\n\n- ' + missing.join('\n- '));
                        return;
                    }

                    // Confirm summary
                    var currency = (document.getElementById('currency') || {}).value || '';
                    var totalText = (document.getElementById('total-amount') || {}).textContent || '';
                    var msg = 'Please confirm all details are correct before submitting.\n\n' +
                              'Payee: ' + payee + '\n' +
                              'Total: ' + (currency || '') + ' ' + totalText + '\n\n' +
                              'Submit this voucher?';
                    if (!confirm(msg)) {
                        e.preventDefault();
                        return;
                    }
                } catch(err) {
                    // On error, allow native required validations to run
                }
            });
        });
    </script>
    <?php require_once __DIR__ . '/../includes/mobile_footer.php'; ?>
</body>
</html>
