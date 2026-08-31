<?php
// Force no-cache to avoid stale HTML/JS on hosts with aggressive caching (InfinityFree)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once '../includes/functions.php';
requireLogin();

// Determine correct dashboard based on user role
$dashboardUrl = ($_SESSION['role'] === 'admin') ? '../admin/dashboard.php' : 'dashboard.php';

if (!isset($_GET['id'])) {
    header('Location: ' . $dashboardUrl);
    exit();
}

$voucher_id = intval($_GET['id']);

// Check if user can edit this voucher
if (!canEditVoucher($voucher_id, $_SESSION['user_id'])) {
    header('Location: ' . $dashboardUrl);
    exit();
}

// Get voucher details
$stmt = $pdo->prepare("SELECT * FROM payment_vouchers WHERE id = ?");
$stmt->execute([$voucher_id]);
$voucher = $stmt->fetch();

if (!$voucher) {
    header('Location: ' . $dashboardUrl);
    exit();
}

// Get voucher items
$stmt = $pdo->prepare("SELECT * FROM voucher_items WHERE voucher_id = ? ORDER BY id");
$stmt->execute([$voucher_id]);
$existing_items = $stmt->fetchAll();
$attachments = getVoucherAttachments($voucher_id);

$error = '';
$success = '';

// Draft detection (matches listing logic) for compact display
$isPaidFlag = isset($voucher['is_paid']) && (int)$voucher['is_paid'] === 1;
$isDraftDerived = !$isPaidFlag
    && isset($voucher['status']) && strtolower($voucher['status']) === STATUS_PENDING
    && (
        empty(trim((string)$voucher['payee_name']))
        || (float)$voucher['total_amount'] <= 0
        || count($existing_items) === 0
    );
$isDraftView = $isDraftDerived || (isset($_GET['draft']) && $_GET['draft'] == '1');

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
    // Track transaction state to avoid rollback errors and improve diagnostics
    $txStarted = false;
    try {
        // Validate form data
        $payee_name = trim($_POST['payee_name']);
        $description = trim($_POST['description']);
        $currency = $_POST['currency'];
        $supporting_documents = intval($_POST['supporting_documents']);
        $applicant = trim($_POST['applicant']);
    $department_manager = trim($_POST['department_manager']);
    $prepared_by = trim($_POST['prepared_by']);
    $checked_by = isset($_POST['checked_by']) ? trim($_POST['checked_by']) : '';
    // Preserve existing General Manager value; it will be set on approval
    $general_manager = isset($voucher['general_manager']) ? $voucher['general_manager'] : null;
        $date_created = $_POST['date_created'];
        
        if (empty($payee_name) || empty($description) || empty($date_created)) {
            throw new Exception('Please fill in all required fields');
        }
        
        // Validate voucher items
        $items = [];
        $total_amount = 0;
        
        if (!isset($_POST['payment_type']) || !is_array($_POST['payment_type'])) {
            throw new Exception('Please add at least one payment item');
        }
        
        for ($i = 0; $i < count($_POST['payment_type']); $i++) {
            $payment_type = trim($_POST['payment_type'][$i]);
            $budget_type = trim($_POST['budget_type'][$i]);
            $name = trim($_POST['name'][$i]);
            $amount = floatval($_POST['amount'][$i]);
            $item_description = trim($_POST['item_description'][$i]);
            
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
        
        if (empty($items)) {
            throw new Exception('Please add at least one valid payment item');
        }
        
    // Start transaction
    $pdo->beginTransaction();
    $txStarted = true;
        
            // Update payment voucher (attempt with checked_by, fallback without)
            try {
                $stmt = $pdo->prepare("
                    UPDATE payment_vouchers 
                    SET payee_name = ?, description = ?, currency = ?, total_amount = ?, 
                        supporting_documents = ?, applicant = ?, department_manager = ?, 
                        general_manager = ?, date_created = ?, prepared_by = ?, checked_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $payee_name, $description, $currency, $total_amount,
                    $supporting_documents, $applicant, $department_manager, $general_manager,
                    $date_created, $prepared_by, $checked_by, $voucher_id
                ]);
            } catch (Exception $e) {
                $stmt = $pdo->prepare("
                    UPDATE payment_vouchers 
                    SET payee_name = ?, description = ?, currency = ?, total_amount = ?, 
                        supporting_documents = ?, applicant = ?, department_manager = ?, 
                        general_manager = ?, date_created = ?, prepared_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $payee_name, $description, $currency, $total_amount,
                    $supporting_documents, $applicant, $department_manager, $general_manager,
                    $date_created, $prepared_by, $voucher_id
                ]);
            }

            // Delete existing items
            $stmt = $pdo->prepare("DELETE FROM voucher_items WHERE voucher_id = ?");
            $stmt->execute([$voucher_id]);

            // Prepare insert for new items
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
        }
        
        // Handle newly uploaded supporting files (additive; existing retained)
        $newUploads = 0;
        if (!empty($_FILES['supporting_files']) && isset($_FILES['supporting_files']['name']) && is_array($_FILES['supporting_files']['name'])) {
            ensureVoucherAttachmentsSchema();
            $baseDir = ensureVoucherUploadsDir();
            $voucherDir = $baseDir . DIRECTORY_SEPARATOR . $voucher_id;
            if (!is_dir($voucherDir)) { @mkdir($voucherDir, 0775, true); }
            if (is_dir($voucherDir) && !is_writable($voucherDir)) { @chmod($voucherDir, 0775); }
            // Allowed types mirror create page
            $allowedExt = ['pdf','jpg','jpeg','png','gif','doc','docx','xls','xlsx'];
            // Dynamic max: min(upload_max_filesize, post_max_size)
            $toBytes = function($val){ $val=trim((string)$val); if($val==='') return 0; $u=strtolower(substr($val,-1)); $n=(float)$val; switch($u){ case 'g': $n*=1024; case 'm': $n*=1024; case 'k': $n*=1024; } return (int)round($n); };
            $maxServer = min(max(1,$toBytes(ini_get('upload_max_filesize') ?: '10M')), max(1,$toBytes(ini_get('post_max_size') ?: '10M')));
            $names = $_FILES['supporting_files']['name'];
            $tmps  = $_FILES['supporting_files']['tmp_name'];
            $types = $_FILES['supporting_files']['type'];
            $sizes = $_FILES['supporting_files']['size'];
            $errs  = $_FILES['supporting_files']['error'];
            $count = count($names);
            for ($i=0; $i<$count; $i++) {
                if (!isset($names[$i]) || $errs[$i] !== UPLOAD_ERR_OK) continue;
                $orig = $names[$i];
                $size = (int)($sizes[$i] ?? 0);
                $mime = (string)($types[$i] ?? 'application/octet-stream');
                $tmp  = $tmps[$i];
                if ($size <= 0 || $size > $maxServer) continue;
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) continue;
                $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
                if ($safeBase === '') { $safeBase = 'file'; }
                $unique = $safeBase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $destAbs = $voucherDir . DIRECTORY_SEPARATOR . $unique;
                $destRel = 'assets/uploads/vouchers/' . $voucher_id . '/' . $unique;
                if (@move_uploaded_file($tmp, $destAbs)) {
                    addVoucherAttachment($voucher_id, $destRel, $orig, $mime, $size, (int)$_SESSION['user_id']);
                    $newUploads++;
                }
            }
            if ($newUploads > 0) {
                try {
                    // Recompute total attachments count from DB for accuracy
                    $attCountStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM voucher_attachments WHERE voucher_id = ?');
                    $attCountStmt->execute([$voucher_id]);
                    $realCount = (int)($attCountStmt->fetch()['c'] ?? $newUploads);
                    $up = $pdo->prepare('UPDATE payment_vouchers SET supporting_documents = ? WHERE id = ?');
                    $up->execute([$realCount, $voucher_id]);
                } catch (Throwable $e) { /* ignore */ }
            }
        }

        // Log the modification (include note if attachments added)
        $logAction = 'modified' . ($newUploads>0 ? ' +attachments(' . $newUploads . ')' : '');
        logVoucherAction($voucher_id, $_SESSION['user_id'], $logAction);

        // Commit transaction
        if ($txStarted && $pdo->inTransaction()) { $pdo->commit(); }

        // If Checked By changed to a non-empty value, notify the assignee
        try {
            $prevChecked = trim((string)($voucher['checked_by'] ?? ''));
            $newChecked  = trim((string)$checked_by);
            if ($newChecked !== '' && strcasecmp($prevChecked, $newChecked) !== 0) {
                notifyCheckedByAssignee($voucher_id);
            }
        } catch (Throwable $eN) { error_log('notifyCheckedByAssignee (edit) failed: '.$eN->getMessage()); }

        // Redirect to the view page for this voucher
        $redirectUrl = 'view-voucher.php?id=' . (int)$voucher_id . '&updated=1';
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
            exit();
        } else {
            // Avoid inline JS (blocked by CSP); use meta refresh fallback and log the incident
            if (function_exists('app_log')) { app_log('edit-voucher: headers already sent, using meta refresh to ' . $redirectUrl); }
            $safe = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $safe . '"><title>Redirecting...</title></head><body>Redirecting... <a href="' . $safe . '">Continue</a></body></html>';
            exit();
        }
        
    } catch (Exception $e) {
        // Roll back safely if transaction is active
        if ($txStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $rbEx) { error_log('edit-voucher rollback failed: '.$rbEx->getMessage()); }
        }
        $error = 'Failed to update voucher. Please review your entries and try again.';
        error_log('Voucher update failed: '.$e->getMessage());
        error_log($e->getTraceAsString());
        if (function_exists('app_log')) { app_log('Voucher update failed for ID '.$voucher_id.': '.$e->getMessage().' TRACE: '.$e->getTraceAsString()); }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Payment Voucher - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <?php 
        // Determine if current checked_by belongs to Finance (for potential future dynamic styling)
        $selectedChecked = trim((string)($voucher['checked_by'] ?? '')); 
        $isSelectedFinance = false;
        foreach (($financeUsers ?? []) as $fu) { if (isset($fu['full_name']) && $fu['full_name'] === $selectedChecked) { $isSelectedFinance = true; break; } }
    ?>
    <style>
        .voucher-items .voucher-item { 
            display:grid; 
            grid-template-columns: 1fr 1fr 1fr 1fr 1fr 32px; 
            gap:12px; align-items:start; 
        }
        .voucher-items-header { display:grid; grid-template-columns:1fr 1fr 1fr 1fr 1fr 32px; gap:12px; font-weight:600; font-size:11px; padding:4px 8px 6px; border-bottom:1px solid #d1d5db; margin-bottom:4px; }
        .voucher-item.no-label label { display:none !important; }
        /* Force-hide any legacy labels within voucher item rows */
        .voucher-items .voucher-item label { display:none !important; }
        /* Compact input sizing specifically for edit page */
        body.dashboard .form-container .form-group input,
        body.dashboard .form-container .form-group select,
        body.dashboard .form-container .form-group textarea { padding:6px 8px; font-size:12px; line-height:1.25; }
        body.dashboard .form-container .form-group textarea { min-height:90px; }
        .voucher-items .voucher-item .form-group label { font-size:11px; margin-bottom:3px; }
        .voucher-items .voucher-item .form-group select,
        .voucher-items .voucher-item .form-group input { padding:6px 8px; font-size:12px; }
        .voucher-items .total-amount { padding:8px 10px; font-size:13px; }
        .voucher-items .add-item { padding:6px 10px !important; font-size:12px !important; }
    </style>
</head>
<body class="dashboard <?= $isDraftView ? 'draft-mode' : '' ?>">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content <?= $isDraftView ? 'draft-compact' : '' ?>">
        <div class="actions" style="width:100%;display:flex;align-items:center;justify-content:space-between;max-width:<?= $isDraftView ? '760px':'100%' ?>;margin:0 auto 8px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <a href="<?= isAdmin() ? '../admin/dashboard.php' : 'dashboard.php' ?>" class="icon-link icon-neutral" title="Back" aria-label="Back" style="margin:0;">
                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <a href="view-voucher.php?id=<?= $voucher_id ?>" class="btn btn-secondary" style="padding:6px 12px;<?= $isDraftView ? 'font-size:12px;' : '' ?>">View Voucher</a>
            </div>
        </div>

        <div class="form-container <?= $isDraftView ? 'draft-compact-container' : '' ?>">
            <h2 style="<?= $isDraftView ? 'font-size:15px;margin-bottom:10px;' : '' ?>">Edit Payment Voucher - <?= htmlspecialchars($voucher['voucher_no']) ?><?= $isDraftDerived ? ' <span style="color:#b45309;font-weight:600;">(Draft)</span>' : '' ?> <small style="color:#666;font-size:11px;">(ID: <?= $voucher_id ?>)</small></h2>
            
            <?php if ($error): ?>
                <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" id="voucherForm" enctype="multipart/form-data">
                <div class="form-row <?= $isDraftView ? 'draft-row' : '' ?>">
                    <div class="form-group">
                        <label for="payee_name">Payee Name *</label>
                        <input type="text" id="payee_name" name="payee_name" required 
                               value="<?= htmlspecialchars($voucher['payee_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_created">Date *</label>
                        <input type="date" id="date_created" name="date_created" required 
                               value="<?= $voucher['date_created'] ?>">
                    </div>
                </div>

                <div class="form-group <?= $isDraftView ? 'draft-group' : '' ?>">
                    <label for="description">Description *</label>
                    <textarea id="description" name="description" required><?= htmlspecialchars($voucher['description']) ?></textarea>
                </div>

                <div class="form-row <?= $isDraftView ? 'draft-row' : '' ?>">
                    <div class="form-group">
                        <label for="currency">Currency</label>
                        <select id="currency" name="currency">
                            <option value="TZS" <?= $voucher['currency'] === 'TZS' ? 'selected' : '' ?>>TZS</option>
                            <option value="USD" <?= $voucher['currency'] === 'USD' ? 'selected' : '' ?>>USD</option>
                            <option value="CNY" <?= $voucher['currency'] === 'CNY' ? 'selected' : '' ?>>CNY</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="supporting_documents">Supporting Documents (Qty.)</label>
                        <input type="number" id="supporting_documents" name="supporting_documents" min="0" 
                               value="<?= $voucher['supporting_documents'] ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-top:10px;">
                    <label>Existing attachments (<?= count($attachments) ?>)</label>
                    <?php if (!empty($attachments)): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                        <?php foreach ($attachments as $att): 
                            $rel = '../' . ltrim($att['file_path'], '/');
                            $name = $att['original_name'];
                        ?>
                        <a href="<?= htmlspecialchars($rel) ?>" target="_blank" class="btn btn-secondary" style="padding:6px 10px; font-size:12px;">View: <?= htmlspecialchars($name) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                        <div style="font-size:12px;color:#6b7280;">No attachments yet.</div>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="margin-top:6px;">
                    <label for="supporting_files">Add more supporting documents</label>
                    <input type="file" id="supporting_files" name="supporting_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,image/*,application/pdf">
                    <div style="font-size:11px;color:#6b7280;margin-top:4px;">You can upload additional files (PDF, images, Office). Each up to current server limit.</div>
                </div>

                <div class="voucher-items <?= $isDraftView ? 'draft-items' : '' ?>">
                    <h3 style="<?= $isDraftView ? 'font-size:14px;margin-bottom:6px;' : '' ?>">Payment Details</h3>
                    <button type="button" class="add-item" onclick="addVoucherItem()" style="<?= $isDraftView ? 'padding:4px 8px;font-size:11px;' : '' ?>">Add Item</button>
                    <div id="voucher-items-header" class="voucher-items-header">
                        <div>Payment Type</div>
                        <div>Budget Type</div>
                        <div>Name</div>
                        <div>Amount</div>
                        <div>Item Description</div>
                        <div></div>
                    </div>
                    <div id="voucher-items-container">
                        <!-- Existing items will be loaded here -->
                    </div>
                    
                    <div class="total-amount" style="<?= $isDraftView ? 'padding:8px;font-size:13px;margin:10px 0;' : '' ?>">
                        Total: <span id="currency-symbol"><?= htmlspecialchars($voucher['currency']) ?></span> 
                        <span id="total-amount"><?= number_format($voucher['total_amount'], 2) ?></span>
                    </div>
                </div>

                <div class="form-row <?= $isDraftView ? 'draft-row' : '' ?>">
                    <div class="form-group">
                        <label for="applicant">Applicant</label>
                            <?php $selectedApplicant = trim((string)($voucher['applicant'] ?: $_SESSION['full_name'])); ?>
                            <select id="applicant" name="applicant">
                                <option value="">â€” Select user â€”</option>
                                <?php foreach ($allUsers as $u): $name = $u['full_name']; ?>
                                    <option value="<?= htmlspecialchars($name) ?>" <?= ($selectedApplicant === $name ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    </div>
                    <div class="form-group">
                        <label for="department_manager">Department Manager</label>
                            <?php $selectedDeptMgr = trim((string)($voucher['department_manager'] ?? '')); ?>
                            <select id="department_manager" name="department_manager">
                                <option value="">â€” Select user â€”</option>
                                <?php 
                                    foreach ($allUsers as $u): $name = $u['full_name']; 
                                ?>
                                    <option value="<?= htmlspecialchars($name) ?>" <?= ($selectedDeptMgr === $name ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    </div>
                </div>

                <input type="hidden" id="prepared_by" name="prepared_by" value="<?= htmlspecialchars(trim((string)($voucher['prepared_by'] ?: ($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')))) ?>">
                
                <div class="form-group <?= $isDraftView ? 'draft-group' : '' ?>">
                    <label for="checked_by">Checked By</label>
                    <?php 
                        $selectedChecked = trim((string)($voucher['checked_by'] ?? '')); 
                        // Determine if the current checked_by is a Finance user
                        $isSelectedFinance = false;
                        foreach (($financeUsers ?? []) as $fu) { if (isset($fu['full_name']) && $fu['full_name'] === $selectedChecked) { $isSelectedFinance = true; break; } }
                    ?>
                    <select id="checked_by" name="checked_by">
                        <option value="">â€” Select user â€”</option>
                        <?php if ($selectedChecked && !$isSelectedFinance): ?>
                            <!-- Preserve existing non-Finance value as a selectable legacy option -->
                            <option value="<?= htmlspecialchars($selectedChecked) ?>" selected>(Current) <?= htmlspecialchars($selectedChecked) ?> â€” non-Finance</option>
                        <?php endif; ?>
                        <?php foreach (($financeUsers ?? []) as $u): $name = $u['full_name']; ?>
                            <option value="<?= htmlspecialchars($name) ?>" <?= ($isSelectedFinance && $selectedChecked === $name ? 'selected' : '') ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px;color:#6b7280;margin-top:4px;">Only Finance department users can be selected for Checked By. Existing non-Finance value is shown as legacy.</div>
                </div>

                <input type="hidden" id="general_manager" name="general_manager" value="<?= htmlspecialchars(trim((string)($voucher['general_manager'] ?? ''))) ?>">

                <button type="submit" class="btn" style="<?= $isDraftView ? 'padding:8px 12px;font-size:13px;' : '' ?>">Update Voucher</button>
            </form>
        </div>
    </main>

    <script src="../assets/js/voucher-v5.v10.js?v=10" onerror="this.dataset.err=1"></script>
        <script>
            // Fallback for edit page as well
            (function(){
                function loadScript(src, cb){
                    var s=document.createElement('script'); s.src=src; s.onload=function(){cb&&cb();}; s.onerror=function(){cb&&cb(true);}; document.head.appendChild(s);
                }
                function minimal(){
                    if(typeof window.removeVoucherItem!=='function') window.removeVoucherItem=function(id){var el=document.getElementById(id); if(el){el.remove(); updateTotal();}};
                    function updateTotal(){ if(!document.getElementById('total-amount')) return; var t=0; document.querySelectorAll('input[name="amount[]"]').forEach(function(i){var v=parseFloat(i.value)||0; t+=v;}); document.getElementById('total-amount').textContent=t.toFixed(2);}        
                    if(typeof window.calculateTotal!=='function') window.calculateTotal=updateTotal;
                    if(typeof window.addVoucherItem!=='function') window.addVoucherItem=function(data){
                        var c=document.getElementById('voucher-items-container'); if(!c) return; var idx=(c.children.length||0)+1; var payee=(document.getElementById('payee_name')||{}).value||''; var div=document.createElement('div'); div.className='voucher-item no-label'; div.id='item-'+idx; div.innerHTML='\n <div class="form-group no-label"><select aria-label="Payment Type" name="payment_type[]" required><option value="">Select Type</option><option>Bank Transfer</option><option>Cash Payment</option><option>Cheque</option><option>Mobile Payment</option></select></div>\n <div class="form-group no-label"><select aria-label="Budget Type" name="budget_type[]" required><option value="">Select Budget</option><option>Operational Expenses</option><option>Procurement &amp; Supplies</option><option>Employee Costs</option><option>Sales &amp; Marketing</option><option>Logistics &amp; Delivery</option><option>Administration &amp; Management</option><option>Projects &amp; Capital Expenditure (CAPEX)</option><option>Financial Obligations</option><option>Tax &amp; Compliance</option><option>Others / Miscellaneous</option></select></div>\n <div class="form-group no-label"><input aria-label="Name" type="text" name="name[]" required readonly value="'+payee+'"></div>\n <div class="form-group no-label"><input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" required oninput="calculateTotal()"></div>\n <div class="form-group no-label"><input aria-label="Item Description" type="text" name="item_description[]"></div>\n <button type="button" class="icon-btn icon-danger remove-item" onclick="removeVoucherItem(\'item-'+idx+'\')" style="justify-self:end;align-self:center;">âœ•</button>';
                        c.appendChild(div); updateTotal(); };
                }
                function ensure(){ if(typeof window.addVoucherItem==='function') return; loadScript('../assets/js/voucher.js?v=1004', function(err){ if(typeof window.addVoucherItem!=='function'){ minimal(); }}); }
                if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', ensure);} else { ensure(); }
            })();
        </script>
        <script>
            // Hard kill any residual lock icons from cached legacy scripts on edit page
            (function(){
               function removeLocks(){ document.querySelectorAll('.lock-icon').forEach(el=>el.remove()); }
               document.addEventListener('DOMContentLoaded', removeLocks);
               var mo = new MutationObserver(removeLocks); mo.observe(document.documentElement,{childList:true,subtree:true});
            })();
        </script>
        <script>
            // Defensive enhancer: ensure Add Item works even if primary script isn't ready
            document.addEventListener('DOMContentLoaded', function(){
                var addBtn = document.querySelector('.add-item');
                if(!addBtn) return;
                addBtn.addEventListener('click', function(){
                    if (typeof window.addVoucherItem === 'function') return; // primary handler available
                    console.warn('[voucher-edit] addVoucherItem missing at click; defining minimal fallback');
                    try {
                        window.addVoucherItem = function(data){
                            var c=document.getElementById('voucher-items-container'); if(!c) return; var idx=(c.children.length||0)+1; var payee=(document.getElementById('payee_name')||{}).value||''; var div=document.createElement('div');
                            div.className='voucher-item no-label'; div.id='item-'+idx;
                            div.innerHTML='\n <div class="form-group no-label"><select aria-label="Payment Type" name="payment_type[]" required><option value="">Select Type</option><option>Bank Transfer</option><option>Cash Payment</option><option>Cheque</option><option>Mobile Payment</option></select></div>\n <div class="form-group no-label"><select aria-label="Budget Type" name="budget_type[]" required><option value="">Select Budget</option><option>Operational Expenses</option><option>Procurement &amp; Supplies</option><option>Employee Costs</option><option>Sales &amp; Marketing</option><option>Logistics &amp; Delivery</option><option>Administration &amp; Management</option><option>Projects &amp; Capital Expenditure (CAPEX)</option><option>Financial Obligations</option><option>Tax &amp; Compliance</option><option>Others / Miscellaneous</option></select></div>\n <div class="form-group no-label"><input aria-label="Name" type="text" name="name[]" required readonly value="'+payee+'"></div>\n <div class="form-group no-label"><input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" required oninput="calculateTotal()"></div>\n <div class="form-group no-label"><input aria-label="Item Description" type="text" name="item_description[]"></div>\n <button type="button" class="icon-btn icon-danger remove-item" onclick="removeVoucherItem(\'item-'+idx+'\')" style="justify-self:end;align-self:center;">âœ•</button>';
                            c.appendChild(div);
                            if (typeof window.calculateTotal === 'function') { window.calculateTotal(); }
                        };
                        window.addVoucherItem();
                    } catch(e) { console.error(e); }
                }, { once: true });
            });
        </script>
    <?php if ($isDraftView): ?>
    <style>
        /* Draft compact styling */
        body.dashboard.draft-mode .main-content.draft-compact { padding:14px 12px; }
        body.dashboard.draft-mode .draft-compact-container { padding:14px; max-width:760px; }
        body.dashboard.draft-mode .draft-compact-container h2 { font-size:15px; }
        body.dashboard.draft-mode .draft-row { gap:10px; }
        body.dashboard.draft-mode .draft-group label { font-size:12px; margin-bottom:3px; }
        body.dashboard.draft-mode .draft-group input,
        body.dashboard.draft-mode .draft-group select,
        body.dashboard.draft-mode .draft-group textarea { padding:8px 10px; font-size:12px; }
        body.dashboard.draft-mode .draft-items h3 { font-size:14px; }
        body.dashboard.draft-mode .voucher-items .add-item { padding:4px 8px; font-size:11px; }
        body.dashboard.draft-mode .voucher-items .voucher-item { grid-template-columns: 1fr 1fr 1fr 1fr 1fr 28px; gap:10px; padding:10px; }
        body.dashboard.draft-mode .voucher-items .voucher-item input,
        body.dashboard.draft-mode .voucher-items .voucher-item select,
        body.dashboard.draft-mode .voucher-items .voucher-item textarea { padding:6px 8px; font-size:12px; }
        body.dashboard.draft-mode .total-amount { font-size:13px; }
        body.dashboard.draft-mode .form-container { box-shadow:none; background:transparent; }
        /* Centering adjustments */
        body.dashboard.draft-mode .main-content.draft-compact {
            display:flex;
            flex-direction:column;
            align-items:center;
        }
        body.dashboard.draft-mode .draft-compact-container {
            width:100%;
            margin:0 auto;
        }
        /* Optional tighter max-width when centered */
        @media (min-width:900px){
            body.dashboard.draft-mode .draft-compact-container { max-width:760px; }
        }
        /* Vertically center when viewport is tall */
        @media (min-height:700px){
            body.dashboard.draft-mode .main-content.draft-compact { justify-content:flex-start; }
        }
        @media (max-width:640px){
            body.dashboard.draft-mode .draft-compact-container { padding:12px; }
        }
    </style>
    <?php endif; ?>
    <script>
        // Load existing items when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const existingItems = <?= json_encode($existing_items) ?>;
            const container = document.getElementById('voucher-items-container');
            container.innerHTML = ''; // Clear any existing items
            
            // Load existing items
            existingItems.forEach(function(item) {
                addVoucherItem(item);
            });
            
            // If no items, add one empty item
            if (existingItems.length === 0) {
                addVoucherItem();
            }
            
            calculateTotal();
            updateCurrencySymbol();
        });
    </script>
</body>
</html>
