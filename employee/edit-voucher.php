<?php
require_once __DIR__ . '/../includes/functions.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

requireLogin();
if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

// Determine correct dashboard based on user role
$dashboardUrl = ($_SESSION['role'] === 'admin') ? '../admin/dashboard.php' : 'dashboard.php';

if (!isset($_GET['id'])) {
    header('Location: ' . $dashboardUrl);
    exit();
}

$voucher_id = intval($_GET['id']);

// Check if user can edit this voucher (full or limited classification edit)
if (!canEditVoucher($voucher_id, $_SESSION['user_id']) && !canLimitedEditApprovedVoucher($voucher_id, $_SESSION['user_id'])) {
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

$vfLimitedEditMode = voucherUsesLimitedClassificationEditMode($voucher, $voucher_id, (int) $_SESSION['user_id']);

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

$approvalUserLists = function_exists('fetchVoucherApprovalUsers')
    ? fetchVoucherApprovalUsers($pdo)
    : array('all' => array(), 'finance' => array());
$allUsers = $approvalUserLists['all'] ?? array();
$financeUsers = $approvalUserLists['finance'] ?? array();

$voucherModuleQs = '';
if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
    $voucherModuleQs = '?module=' . rawurlencode((string) $_GET['module']);
}

// Prepare Payees (match create-voucher)
$payees = [];
try {
    $stmt = $pdo->query("SELECT id, name, type FROM payees WHERE is_active = 1 ORDER BY name ASC");
    $payees = $stmt->fetchAll();
} catch (Exception $e) { /* silent */ }

// Prepare Sales Orders for optional linking
$salesOrders = [];
try {
    $salesOrders = $pdo->query("
        SELECT
            so.id,
            so.order_number,
            so.status,
            so.created_at,
            COALESCE(c.company_name, c.contact_person, 'Unknown Customer') AS customer_name,
            COALESCE(u.full_name, 'Unassigned') AS salesperson_name
        FROM sales_orders so
        LEFT JOIN customers c ON c.id = so.customer_id
        LEFT JOIN users u ON u.id = so.created_by
        ORDER BY so.created_at DESC, so.id DESC
        LIMIT 500
    ")->fetchAll() ?: [];
} catch (Throwable $e) {
    $salesOrders = [];
}

// Purchase orders for Stock Purchase linking (match create-voucher).
$purchaseOrders = [];
try {
    if (function_exists('tableExists') && tableExists('stocks_purchase_orders', $pdo)) {
        $hasSupplierJoin = function_exists('tableExists') && tableExists('stocks_suppliers', $pdo);
        $supplierSelect = $hasSupplierJoin
            ? "COALESCE(ss.name, CONCAT('Supplier #', po.supplier_id)) AS supplier_name"
            : "CONCAT('Supplier #', po.supplier_id) AS supplier_name";
        $join = $hasSupplierJoin ? 'LEFT JOIN stocks_suppliers ss ON ss.id = po.supplier_id' : '';
        $purchaseOrders = $pdo->query("
            SELECT
                po.id,
                po.po_number,
                po.status,
                po.created_at,
                {$supplierSelect}
            FROM stocks_purchase_orders po
            {$join}
            ORDER BY
                CASE
                    WHEN LOWER(TRIM(COALESCE(po.status, ''))) IN ('received', 'closed', 'cancelled', 'canceled', 'completed') THEN 1
                    ELSE 0
                END ASC,
                po.created_at DESC,
                po.id DESC
            LIMIT 2000
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $purchaseOrders = [];
}

// Ensure already-linked POs appear in the picker even if outside the recent LIMIT window.
if (!empty($voucher) && function_exists('parseLinkedStockPoIdsFromVoucher') && function_exists('fetchStockPurchaseOrderById')) {
    $ensurePoIds = parseLinkedStockPoIdsFromVoucher($voucher);
    if ($ensurePoIds !== []) {
        $have = [];
        foreach ($purchaseOrders as $poRow) {
            $have[(int) ($poRow['id'] ?? 0)] = true;
        }
        foreach ($ensurePoIds as $ensureId) {
            $ensureId = (int) $ensureId;
            if ($ensureId <= 0 || isset($have[$ensureId])) {
                continue;
            }
            $extraPo = fetchStockPurchaseOrderById($pdo, $ensureId, false);
            if (!$extraPo) {
                continue;
            }
            $purchaseOrders[] = [
                'id' => (int) ($extraPo['id'] ?? $ensureId),
                'po_number' => (string) ($extraPo['po_number'] ?? ('PO-' . $ensureId)),
                'status' => (string) ($extraPo['status'] ?? ''),
                'created_at' => (string) ($extraPo['created_at'] ?? ''),
                'supplier_name' => (string) ($extraPo['supplier_name'] ?? ''),
            ];
            $have[$ensureId] = true;
        }
    }
}

// Backend processing for new Payee via AJAX (match create-voucher)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_create_payee') {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? 'Other');
    $tin = trim($_POST['tin'] ?? '');
    $contact = trim($_POST['contact'] ?? '');

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Payee Name is required']);
        exit;
    }

    try {
        $chk = $pdo->prepare('SELECT id FROM payees WHERE name = ?');
        $chk->execute([$name]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Payee already exists']);
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO payees (name, type, tin, contact_details, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$name, $type, $tin, $contact]);
        $newId = $pdo->lastInsertId();

        if ($type === 'Supplier') {
            $chk = $pdo->prepare('SELECT id FROM stocks_suppliers WHERE name = ?');
            $chk->execute([$name]);
            if (!$chk->fetchColumn()) {
                $pdo->prepare('INSERT INTO stocks_suppliers (name, contact_details) VALUES (?, ?)')
                    ->execute([$name, $contact]);
            }
        }

        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'type' => $type]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limited_classification_update']) && (int) $_POST['limited_classification_update'] === 1) {
    if (!$vfLimitedEditMode) {
        $error = 'Limited classification edit is not available for this voucher.';
    } else {
        $linked_sales_order_ids_raw = trim((string) ($_POST['linked_sales_order_ids'] ?? ''));
        $linked_sales_order_ids = [];
        if ($linked_sales_order_ids_raw !== '') {
            foreach (preg_split('/\s*,\s*/', $linked_sales_order_ids_raw) as $p) {
                $id = (int) $p;
                if ($id > 0) {
                    $linked_sales_order_ids[$id] = $id;
                }
            }
        } elseif (isset($_POST['linked_sales_order_id']) && (int) $_POST['linked_sales_order_id'] > 0) {
            $linked_sales_order_ids[(int) $_POST['linked_sales_order_id']] = (int) $_POST['linked_sales_order_id'];
        }
        $linked_sales_order_ids = array_values($linked_sales_order_ids);
        $result = saveApprovedVoucherLimitedClassification(
            $pdo,
            $voucher_id,
            (int) $_SESSION['user_id'],
            (string) ($_POST['voucher_purpose'] ?? 'general'),
            $linked_sales_order_ids
        );
        if ($result['ok']) {
            $redirectUrl = 'view-voucher.php?id=' . $voucher_id;
            if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
                $redirectUrl .= '&module=' . rawurlencode((string) $_GET['module']);
            }
            header('Location: ' . $redirectUrl);
            exit();
        }
        $error = $result['message'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['limited_classification_update']) && (!isset($_POST['action']) || (string) $_POST['action'] !== 'ajax_create_payee')) {
    // Track transaction state to avoid rollback errors and improve diagnostics
    $txStarted = false;
    try {
        // Validate form data
        $payee_name = trim($_POST['payee_name'] ?? '');
        $payee_id = isset($_POST['payee_id']) && is_numeric($_POST['payee_id']) ? (int) $_POST['payee_id'] : null;
        if ($payee_name === '' && $payee_id > 0) {
            $payeeLookup = $pdo->prepare('SELECT name FROM payees WHERE id = ?');
            $payeeLookup->execute([$payee_id]);
            $payee_name = trim((string) $payeeLookup->fetchColumn());
        }
        $voucher_purpose = normalizePaymentVoucherPurpose($_POST['voucher_purpose'] ?? 'general');
        $linked_sales_order_ids_raw = trim((string) ($_POST['linked_sales_order_ids'] ?? ''));
        $linked_sales_order_ids = [];
        if ($linked_sales_order_ids_raw !== '') {
            foreach (preg_split('/\s*,\s*/', $linked_sales_order_ids_raw) as $p) {
                $id = (int) $p;
                if ($id > 0) {
                    $linked_sales_order_ids[$id] = $id;
                }
            }
        } elseif (isset($_POST['linked_sales_order_id']) && (int) $_POST['linked_sales_order_id'] > 0) {
            $linked_sales_order_ids[(int) $_POST['linked_sales_order_id']] = (int) $_POST['linked_sales_order_id'];
        }
        $linked_sales_order_ids = array_values($linked_sales_order_ids);
        $linked_sales_order_id = !empty($linked_sales_order_ids) ? (int) $linked_sales_order_ids[0] : 0;

        $linked_stock_po_ids_raw = trim((string) ($_POST['linked_stock_po_ids'] ?? ''));
        $linked_stock_po_ids = [];
        if ($linked_stock_po_ids_raw !== '') {
            foreach (preg_split('/\s*,\s*/', $linked_stock_po_ids_raw) as $p) {
                $id = (int) $p;
                if ($id > 0) {
                    $linked_stock_po_ids[$id] = $id;
                }
            }
        } elseif (isset($_POST['linked_stock_po_id']) && (int) $_POST['linked_stock_po_id'] > 0) {
            $linked_stock_po_ids[(int) $_POST['linked_stock_po_id']] = (int) $_POST['linked_stock_po_id'];
        }
        $linked_stock_po_ids = array_values($linked_stock_po_ids);
        if (!empty($linked_stock_po_ids) && function_exists('fetchStockPurchaseOrderById')) {
            $validPoIds = [];
            foreach ($linked_stock_po_ids as $poIdCheck) {
                $poRow = fetchStockPurchaseOrderById($pdo, (int) $poIdCheck, false);
                if ($poRow) {
                    $validPoIds[] = (int) $poIdCheck;
                }
            }
            $linked_stock_po_ids = $validPoIds;
        }
        if ($voucher_purpose !== 'stock_purchase') {
            $linked_stock_po_ids = [];
        }
        $linked_stock_po_id = !empty($linked_stock_po_ids) ? (int) $linked_stock_po_ids[0] : 0;

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

        // Restriction Logic (Finance/Admin only)
        // If user is not authorized to change it, preserve existing value
        $is_restricted = isset($voucher['is_restricted']) ? (int)$voucher['is_restricted'] : 0;
        if (isAdmin() || (function_exists('isFinance') && isFinance())) {
            $is_restricted = isset($_POST['is_restricted']) ? 1 : 0;
        }
        
        if (empty($payee_name) || empty($description) || empty($date_created)) {
            throw new Exception('Please fill in all required fields');
        }
        if ($applicant === '' || $department_manager === '' || $checked_by === '') {
            throw new Exception('Please select Applicant, Department Manager, and Checked By');
        }
        if ($voucher_purpose === 'stock_purchase') {
            if ($linked_stock_po_id <= 0 || empty($linked_stock_po_ids)) {
                throw new Exception('Please select at least one Purchase Order for Stock Purchase vouchers');
            }
        }
        // General payment vouchers require at least one supporting file (existing or new)
        if (function_exists('paymentVoucherRequiresSupportingFiles')
            && paymentVoucherRequiresSupportingFiles($voucher_purpose)) {
            $existingAttCount = 0;
            try {
                $attCnt = $pdo->prepare('SELECT COUNT(*) FROM voucher_attachments WHERE voucher_id = ?');
                $attCnt->execute([(int) $voucher_id]);
                $existingAttCount = (int) $attCnt->fetchColumn();
            } catch (Throwable $eAtt) {
                $existingAttCount = is_array($attachments ?? null) ? count($attachments) : 0;
            }
            $queuedFiles = function_exists('countQueuedSupportingFileUploads')
                ? countQueuedSupportingFileUploads($_FILES['supporting_files'] ?? null)
                : 0;
            if (($existingAttCount + $queuedFiles) < 1) {
                throw new Exception('Please upload at least one supporting file for General Payment vouchers');
            }
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
                        general_manager = ?, date_created = ?, prepared_by = ?, checked_by = ?, is_restricted = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $payee_name, $description, $currency, $total_amount,
                    $supporting_documents, $applicant, $department_manager, $general_manager,
                    $date_created, $prepared_by, $checked_by, $is_restricted, $voucher_id
                ]);
            } catch (Exception $e) {
                $stmt = $pdo->prepare("
                    UPDATE payment_vouchers 
                    SET payee_name = ?, description = ?, currency = ?, total_amount = ?, 
                        supporting_documents = ?, applicant = ?, department_manager = ?, 
                        general_manager = ?, date_created = ?, prepared_by = ?, is_restricted = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $payee_name, $description, $currency, $total_amount,
                    $supporting_documents, $applicant, $department_manager, $general_manager,
                    $date_created, $prepared_by, $is_restricted, $voucher_id
                ]);
            }

            try {
                $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $extraSets = [];
                $extraVals = [];
                $purposeFragments = buildPaymentVoucherPurposeUpdateFragments($voucher_purpose, $pvCols);
                foreach ($purposeFragments['sets'] as $setFragment) {
                    $extraSets[] = $setFragment;
                }
                foreach ($purposeFragments['vals'] as $valFragment) {
                    $extraVals[] = $valFragment;
                }
                if (in_array('linked_sales_order_id', $pvCols, true)) {
                    $extraSets[] = 'linked_sales_order_id = ?';
                    $extraVals[] = ($linked_sales_order_id > 0 ? $linked_sales_order_id : null);
                }
                if (in_array('linked_sales_order_ids', $pvCols, true)) {
                    $extraSets[] = 'linked_sales_order_ids = ?';
                    $extraVals[] = !empty($linked_sales_order_ids) ? json_encode($linked_sales_order_ids) : null;
                }
                if (in_array('linked_stock_po_id', $pvCols, true)) {
                    $extraSets[] = 'linked_stock_po_id = ?';
                    $extraVals[] = ($linked_stock_po_id > 0 ? $linked_stock_po_id : null);
                }
                if (in_array('linked_stock_po_ids', $pvCols, true)) {
                    $extraSets[] = 'linked_stock_po_ids = ?';
                    $extraVals[] = !empty($linked_stock_po_ids) ? json_encode(array_values($linked_stock_po_ids)) : null;
                }
                if (in_array('payee_id', $pvCols, true)) {
                    $extraSets[] = 'payee_id = ?';
                    $extraVals[] = ($payee_id > 0 ? $payee_id : null);
                }
                if (!empty($extraSets)) {
                    $extraVals[] = $voucher_id;
                    $pdo->prepare('UPDATE payment_vouchers SET ' . implode(', ', $extraSets) . ' WHERE id = ?')->execute($extraVals);
                }
            } catch (Throwable $eExtra) { /* ignore optional columns */ }

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
            } else {
                // Keep supporting_documents aligned with real rows even when no new files arrived.
                try {
                    $attCountStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM voucher_attachments WHERE voucher_id = ?');
                    $attCountStmt->execute([$voucher_id]);
                    $realCount = (int) ($attCountStmt->fetchColumn() ?: 0);
                    $up = $pdo->prepare('UPDATE payment_vouchers SET supporting_documents = ? WHERE id = ?');
                    $up->execute([$realCount, $voucher_id]);
                } catch (Throwable $e) { /* ignore */ }
            }
        }

        if (function_exists('syncVoucherApprovalAssignees')) {
            syncVoucherApprovalAssignees($pdo, $voucher_id, array(
                'Applicant' => $applicant,
                'Department Manager' => $department_manager,
                'Checked By' => $checked_by,
            ));
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
        if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
            $redirectUrl .= '&module=' . rawurlencode((string) $_GET['module']);
        }
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
        $error = 'Failed to update voucher: ' . $e->getMessage();
        error_log('Voucher update failed: '.$e->getMessage());
        error_log($e->getTraceAsString());
        if (function_exists('app_log')) { app_log('Voucher update failed for ID '.$voucher_id.': '.$e->getMessage().' TRACE: '.$e->getTraceAsString()); }
    }
}

$vfMode = 'edit';
$vfVoucher = $voucher;
$vfVoucherId = $voucher_id;
$vfExistingItems = $existing_items;
$vfAttachments = $attachments;
$vfIsDraftView = $isDraftView;

// -------------------------------------------------------------------------
// React shell (same UI as create-voucher.php). POST handlers above are kept.
// -------------------------------------------------------------------------
require_once __DIR__ . '/create-voucher-ui/lib.php';

$assets = createVoucherUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Edit Voucher</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Edit Voucher</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>employee/create-voucher-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$mapUserNames = static function (array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $name = trim((string) ($r['full_name'] ?? ''));
        if ($name !== '') {
            $out[] = ['full_name' => $name];
        }
    }
    return $out;
};

$mapPayees = static function (array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'name' => (string) ($r['name'] ?? ''),
            'type' => (string) ($r['type'] ?? ''),
        ];
    }
    return $out;
};

$mapSalesOrders = static function (array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'order_number' => (string) ($r['order_number'] ?? ''),
            'customer_name' => (string) ($r['customer_name'] ?? ''),
            'salesperson_name' => (string) ($r['salesperson_name'] ?? ''),
            'status' => (string) ($r['status'] ?? ''),
        ];
    }
    return $out;
};

$mapPurchaseOrders = static function (array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'po_number' => (string) ($r['po_number'] ?? ''),
            'supplier_name' => (string) ($r['supplier_name'] ?? ''),
            'status' => (string) ($r['status'] ?? ''),
        ];
    }
    return $out;
};

$payeeName = trim((string) ($voucher['payee_name'] ?? ''));
$selectedPayeeId = 0;
if ($payeeName !== '') {
    foreach ($payees as $p) {
        if (isset($p['name']) && strcasecmp(trim((string) $p['name']), $payeeName) === 0) {
            $selectedPayeeId = (int) ($p['id'] ?? 0);
            break;
        }
    }
}

$linkedSoIds = [];
$rawSo = trim((string) ($voucher['linked_sales_order_ids'] ?? ''));
if ($rawSo !== '') {
    $decoded = json_decode($rawSo, true);
    if (is_array($decoded)) {
        foreach ($decoded as $sid) {
            $sid = (int) $sid;
            if ($sid > 0) {
                $linkedSoIds[$sid] = $sid;
            }
        }
    } else {
        foreach (preg_split('/\s*,\s*/', $rawSo) as $sid) {
            $sid = (int) $sid;
            if ($sid > 0) {
                $linkedSoIds[$sid] = $sid;
            }
        }
    }
}
if (empty($linkedSoIds) && !empty($voucher['linked_sales_order_id'])) {
    $sid = (int) $voucher['linked_sales_order_id'];
    if ($sid > 0) {
        $linkedSoIds[$sid] = $sid;
    }
}
$linkedSoIds = array_values($linkedSoIds);

$linkedPoIds = [];
if (function_exists('parseLinkedStockPoIdsFromVoucher')) {
    $linkedPoIds = parseLinkedStockPoIdsFromVoucher($voucher);
} else {
    $rawPo = trim((string) ($voucher['linked_stock_po_ids'] ?? ''));
    if ($rawPo !== '') {
        $decodedPo = json_decode($rawPo, true);
        if (is_array($decodedPo)) {
            foreach ($decodedPo as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $linkedPoIds[$pid] = $pid;
                }
            }
        } else {
            foreach (preg_split('/\s*,\s*/', $rawPo) as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $linkedPoIds[$pid] = $pid;
                }
            }
        }
    }
    if (empty($linkedPoIds) && !empty($voucher['linked_stock_po_id'])) {
        $pid = (int) $voucher['linked_stock_po_id'];
        if ($pid > 0) {
            $linkedPoIds[$pid] = $pid;
        }
    }
    $linkedPoIds = array_values($linkedPoIds);
}

$initialItems = [];
foreach ($existing_items as $row) {
    $initialItems[] = [
        'payment_type' => (string) ($row['payment_type'] ?? ''),
        'budget_type' => (string) ($row['budget_type'] ?? ''),
        'amount' => (string) ($row['amount'] ?? ''),
        'item_description' => (string) ($row['description'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

$initialAttachments = [];
foreach ($attachments as $att) {
    $initialAttachments[] = [
        'id' => (int) ($att['id'] ?? 0),
        'file_path' => (string) ($att['file_path'] ?? ''),
        'original_name' => (string) ($att['original_name'] ?? 'file'),
        'mime_type' => (string) ($att['mime_type'] ?? ''),
        'size_bytes' => (int) ($att['size_bytes'] ?? 0),
    ];
}

$purpose = function_exists('resolvePaymentVoucherPurposeFromRow')
    ? resolvePaymentVoucherPurposeFromRow($voucher)
    : (string) ($voucher['voucher_purpose'] ?? 'general');

$cvPostUrl = 'edit-voucher.php?id=' . (int) $voucher_id;
if (!empty($_SERVER['QUERY_STRING'])) {
    // Preserve module + id from the current request.
    $cvPostUrl = 'edit-voucher.php?' . $_SERVER['QUERY_STRING'];
}

$viewUrl = 'view-voucher.php?id=' . (int) $voucher_id;
if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
    $viewUrl .= '&module=' . rawurlencode((string) $_GET['module']);
}

$deleteAttachmentUrl = function_exists('app_url') ? app_url('/delete_attachment.php') : '../delete_attachment.php';
$proxyPdfUrl = function_exists('app_url') ? app_url('/proxy_pdf.php') : '../proxy_pdf.php';

$editVoucherConfig = [
    'mode' => 'edit',
    'postUrl' => $cvPostUrl,
    'cancelUrl' => $viewUrl,
    'viewUrl' => $viewUrl,
    'deleteAttachmentUrl' => $deleteAttachmentUrl,
    'proxyPdfUrl' => $proxyPdfUrl,
    'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'voucher',
    'preparedBy' => trim((string) ($voucher['prepared_by'] ?? ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''))),
    'today' => date('Y-m-d'),
    'canRestrict' => (isAdmin() || isFinance()),
    'limitedEdit' => !empty($vfLimitedEditMode),
    'voucherId' => (int) $voucher_id,
    'voucherNo' => (string) ($voucher['voucher_no'] ?? ''),
    'statusLabel' => ucfirst((string) ($voucher['status'] ?? 'pending')),
    'currencies' => ['TZS', 'USD', 'CNY'],
    'purposes' => [
        ['value' => 'general', 'label' => 'General Payment'],
        ['value' => 'stock_purchase', 'label' => 'Stock Purchase'],
    ],
    'paymentTypes' => ['Bank Transfer', 'Cash Payment', 'Cheque', 'Mobile Payment', 'Credit'],
    'budgetTypes' => [
        'Operational Expenses',
        'Procurement & Supplies',
        'Employee Costs',
        'Sales & Marketing',
        'Logistics & Delivery',
        'Administration & Management',
        'Projects & Capital Expenditure (CAPEX)',
        'Financial Obligations',
        'Tax & Compliance',
        'Others / Miscellaneous',
    ],
    'payees' => $mapPayees(is_array($payees) ? $payees : []),
    'users' => $mapUserNames(is_array($allUsers) ? $allUsers : []),
    'financeUsers' => $mapUserNames(is_array($financeUsers) ? $financeUsers : []),
    'salesOrders' => $mapSalesOrders(is_array($salesOrders) ? $salesOrders : []),
    'purchaseOrders' => $mapPurchaseOrders(is_array($purchaseOrders) ? $purchaseOrders : []),
    'poDocumentUrl' => function_exists('app_url')
        ? app_url('/employee/create-voucher-ui/po-document.php')
        : 'create-voucher-ui/po-document.php',
    'poViewBaseUrl' => function_exists('app_url')
        ? app_url('/stock/modules/purchases/view_po.php')
        : '/stock/modules/purchases/view_po.php',
    'initial' => [
        'payee_id' => $selectedPayeeId,
        'payee_name' => $payeeName,
        'currency' => (string) ($voucher['currency'] ?? 'TZS'),
        'date_created' => (string) ($voucher['date_created'] ?? date('Y-m-d')),
        'purpose' => $purpose,
        'is_restricted' => !empty($voucher['is_restricted']),
        'description' => (string) ($voucher['description'] ?? ''),
        'applicant' => trim((string) ($voucher['applicant'] ?? '')),
        'department_manager' => trim((string) ($voucher['department_manager'] ?? '')),
        'checked_by' => trim((string) ($voucher['checked_by'] ?? '')),
        'prepared_by' => trim((string) ($voucher['prepared_by'] ?? '')),
        'general_manager' => trim((string) ($voucher['general_manager'] ?? '')),
        'linked_sales_order_ids' => $linkedSoIds,
        'linked_stock_po_ids' => $linkedPoIds,
        'linked_stock_po_id' => !empty($linkedPoIds) ? (int) $linkedPoIds[0] : 0,
        'items' => $initialItems,
    ],
    'attachments' => $initialAttachments,
    'flash' => null,
    'error' => $error,
];

$page_title = 'Edit Voucher';
$employeeHeaderTitle = '';
$hideHeaderCompanyBranding = true;
$GLOBALS['_erp_header_style_linked'] = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Voucher<?= $voucher['voucher_no'] ? ' - ' . htmlspecialchars((string) $voucher['voucher_no'], ENT_QUOTES, 'UTF-8') : '' ?></title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__CV_CFG__ = <?= json_encode($editVoucherConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        :root { --bg-body: #f8fafc; }
        body.dashboard { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
        .main-content.create-voucher-react-root {
            width: 100% !important;
            max-width: none !important;
            padding: 0.5rem 1.25rem 2.5rem !important;
            box-sizing: border-box;
            background: #f8fafc !important;
        }
        .main-content.create-voucher-react-root #root { width: 100%; max-width: none; margin: 0; }
        @media (max-width: 1024px) { .main-content.create-voucher-react-root { padding: 1rem 0.875rem 1.5rem !important; } }
        @media (max-width: 767.98px) { .main-content.create-voucher-react-root { padding: 0.875rem 0.75rem 1.5rem !important; } }
        body.dashboard .header,
        body.dashboard .employee-header {
            background: #f8fafc !important;
            border: none !important;
            box-shadow: none !important;
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content create-voucher-react-root">
        <noscript>
            <div class="alert alert-warning">JavaScript is required to edit a voucher.</div>
        </noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
