<?php
require_once __DIR__ . '/../includes/functions.php';

if (isset($_GET['debug']) && (string) $_GET['debug'] === '1') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
    register_shutdown_function(static function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            echo '<pre style="background:#fee2e2;padding:12px;margin:12px;border:1px solid #fca5a5">'
                . htmlspecialchars($err['message'] . ' in ' . $err['file'] . ':' . $err['line'], ENT_QUOTES, 'UTF-8')
                . '</pre>';
        }
    });
}

// Force no-cache to avoid stale HTML/JS on hosts with aggressive caching
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

// Ensure payment_vouchers can store linked sales order reference.
try {
    $pvColsInit = $pdo->query("SHOW COLUMNS FROM payment_vouchers")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('linked_sales_order_id', $pvColsInit, true)) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN linked_sales_order_id INT NULL");
        } catch (Throwable $e1) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD INDEX idx_pv_linked_sales_order_id (linked_sales_order_id)");
        } catch (Throwable $e2) { /* ignore */
        }
    }
    if (!in_array('linked_sales_order_ids', $pvColsInit, true)) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN linked_sales_order_ids TEXT NULL");
        } catch (Throwable $e3) { /* ignore */
        }
    }
} catch (Throwable $e0) { /* ignore */
}

$error = '';
$success = '';

/** @var array{title: string, message: string, variant: string}|null $voucherCreateSuccess */
$voucherCreateSuccess = null;
if (!empty($_SESSION['employee_voucher_create_success']) && is_array($_SESSION['employee_voucher_create_success'])) {
    $voucherCreateSuccess = $_SESSION['employee_voucher_create_success'];
    unset($_SESSION['employee_voucher_create_success']);
}

$voucherModuleQs = '';
if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
    $voucherModuleQs = '?module=' . rawurlencode((string) $_GET['module']);
}

// Fetch active users from tenant DB for approval dropdowns
$approvalUserLists = function_exists('fetchVoucherApprovalUsers')
    ? fetchVoucherApprovalUsers($pdo)
    : array('all' => array(), 'finance' => array());
$allUsers = $approvalUserLists['all'] ?? array();
$financeUsers = $approvalUserLists['finance'] ?? array();

// Prepare Payees
$payees = [];
try {
    $stmt = $pdo->query("SELECT id, name, type FROM payees WHERE is_active = 1 ORDER BY name ASC");
    $payees = $stmt->fetchAll();
} catch (Exception $e) { /* silent */
}

// Prepare Sales Orders (from Sales module) for optional voucher linking.
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

// Backend processing for new Payee via AJAX (for this page's own modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_create_payee') {
    // Simple permission check: any logged in user can add payee
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
        // Check duplicate
        $chk = $pdo->prepare("SELECT id FROM payees WHERE name = ?");
        $chk->execute([$name]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Payee already exists']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO payees (name, type, tin, contact_details, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$name, $type, $tin, $contact]);
        $newId = $pdo->lastInsertId();

        // Auto-link to Stocks Suppliers if type is Supplier
        if ($type === 'Supplier') {
            // Check if exists in stocks_suppliers
            $chk = $pdo->prepare("SELECT id FROM stocks_suppliers WHERE name = ?");
            $chk->execute([$name]);
            if (!$chk->fetchColumn()) {
                $pdo->prepare("INSERT INTO stocks_suppliers (name, contact_details) VALUES (?, ?)")
                    ->execute([$name, $contact]);
            }
        }

        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'type' => $type]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isDraft = isset($_POST['action']) && $_POST['action'] === 'draft';
    // Enable debug mode via URL parameter: ?debug=1 or via POST
    $debugMode = (isset($_GET['debug']) && $_GET['debug'] === '1') || (isset($_POST['debug']) && $_POST['debug'] === '1');
    // Track transaction state explicitly to avoid rollback errors
    $txStarted = false;
    $committed = false;
    if (function_exists('app_log')) {
        app_log('create-voucher: POST begin draft=' . ($isDraft ? '1' : '0') . ' keys=' . implode(',', array_keys($_POST)));
    }
    try {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        // Validate form data (relaxed when saving draft)
        $payee_name = isset($_POST['payee_name']) ? trim($_POST['payee_name']) : '';
        $payee_id = isset($_POST['payee_id']) && is_numeric($_POST['payee_id']) ? intval($_POST['payee_id']) : null;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $currency = $_POST['currency'] ?? 'TZS';
        $voucher_purpose = normalizePaymentVoucherPurpose($_POST['voucher_purpose'] ?? 'general');
        $linked_sales_order_ids_raw = trim((string) ($_POST['linked_sales_order_ids'] ?? ''));
        $linked_sales_order_ids = [];
        if ($linked_sales_order_ids_raw !== '') {
            $parts = preg_split('/\s*,\s*/', $linked_sales_order_ids_raw);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    $id = (int) $p;
                    if ($id > 0) {
                        $linked_sales_order_ids[$id] = $id;
                    }
                }
            }
        } elseif (isset($_POST['linked_sales_order_id']) && (int) $_POST['linked_sales_order_id'] > 0) {
            // Backward compatibility for previous single-select UI.
            $id = (int) $_POST['linked_sales_order_id'];
            $linked_sales_order_ids[$id] = $id;
        }
        $linked_sales_order_ids = array_values($linked_sales_order_ids);
        if (!empty($linked_sales_order_ids)) {
            try {
                $chkSoList = $pdo->prepare("SELECT id FROM sales_orders WHERE id = ?");
                $validIds = [];
                foreach ($linked_sales_order_ids as $sid) {
                    $chkSoList->execute([$sid]);
                    if ((int) $chkSoList->fetchColumn() > 0) {
                        $validIds[] = (int) $sid;
                    }
                }
                $linked_sales_order_ids = $validIds;
            } catch (Throwable $e) {
                $linked_sales_order_ids = [];
            }
        }
        $linked_sales_order_id = !empty($linked_sales_order_ids) ? (int) $linked_sales_order_ids[0] : 0;
        $supporting_documents = isset($_POST['supporting_documents']) ? intval($_POST['supporting_documents']) : 0;
        $applicant = trim((string) ($_POST['applicant'] ?? ''));
        $department_manager = trim((string) ($_POST['department_manager'] ?? ''));
        // Auto-fill Prepared By from current session user (ignore posted value to enforce policy)
        $prepared_by = trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
        $checked_by = isset($_POST['checked_by']) ? trim($_POST['checked_by']) : '';
        // General Manager is decided later upon approval; keep blank at creation time
        $general_manager = null; // store NULL in DB for now
        $date_created = isset($_POST['date_created']) && $_POST['date_created'] !== ''
            ? date('Y-m-d', strtotime($_POST['date_created']))
            : date('Y-m-d'); // default to today for drafts

        // Restriction Logic (Finance/Admin only)
        $is_restricted = 0;
        if ((isAdmin() || isFinance()) && isset($_POST['is_restricted'])) {
            $is_restricted = 1;
        }

        if (!$isDraft) {
            if (empty($payee_name) || empty($description) || empty($date_created)) {
                throw new Exception('Please fill in all required fields');
            }
            // Require approvals selections for full submission
            if ($applicant === '' || $department_manager === '' || $checked_by === '') {
                throw new Exception('Please select Applicant, Department Manager, and Checked By');
            }
            // Ensure prepared_by is not empty (should always be set from session, but check anyway)
            if (empty($prepared_by)) {
                throw new Exception('Prepared By information is missing. Please contact support.');
            }
        }

        $createdBy = function_exists('resolveVoucherSessionUserId')
            ? (int) resolveVoucherSessionUserId($pdo)
            : (int) ($_SESSION['user_id'] ?? 0);
        if (!$isDraft && $createdBy <= 0) {
            throw new Exception('Your user account could not be verified. Please log out and sign in again.');
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
        $arr_type = (isset($_POST['payment_type']) && is_array($_POST['payment_type'])) ? $_POST['payment_type'] : [];
        $arr_budget = (isset($_POST['budget_type']) && is_array($_POST['budget_type'])) ? $_POST['budget_type'] : [];
        $arr_name = (isset($_POST['name']) && is_array($_POST['name'])) ? $_POST['name'] : [];
        $arr_amount = (isset($_POST['amount']) && is_array($_POST['amount'])) ? $_POST['amount'] : [];
        $arr_desc = (isset($_POST['item_description']) && is_array($_POST['item_description'])) ? $_POST['item_description'] : [];
        $maxRows = max(count($arr_type), count($arr_budget), count($arr_name), count($arr_amount), count($arr_desc));
        if ($maxRows === 0 && $debugMode && function_exists('app_log')) {
            app_log('create-voucher: No item arrays present in POST');
        }
        // Determine a default payment type if none provided (helps hosts that strip [] params sometimes)
        $firstPostedType = '';
        if (!empty($arr_type)) {
            foreach ($arr_type as $t) {
                if (trim((string) $t) !== '') {
                    $firstPostedType = trim((string) $t);
                    break;
                }
            }
        }
        $fallbackType = $firstPostedType !== '' ? $firstPostedType : 'Cash Payment';
        for ($i = 0; $i < $maxRows; $i++) {
            $payment_type = isset($arr_type[$i]) ? trim((string) $arr_type[$i]) : $fallbackType;
            $budget_type = isset($arr_budget[$i]) ? trim((string) $arr_budget[$i]) : '';
            $name = isset($arr_name[$i]) ? trim((string) $arr_name[$i]) : '';
            $amount = isset($arr_amount[$i]) ? floatval($arr_amount[$i]) : 0.0;
            $item_description = isset($arr_desc[$i]) ? trim((string) $arr_desc[$i]) : '';

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
        if (function_exists('app_log')) {
            app_log('create-voucher: itemsValid=' . count($items) . ' total_amount=' . $total_amount);
        }

        // Generate voucher number
        $voucher_no = generateVoucherNumber();

        // Start transaction
        $pdo->beginTransaction();
        $txStarted = true;

        // Insert payment voucher with schema-aware columns
        $pvCols = [];
        try {
            $pvCols = $pdo->query("SHOW COLUMNS FROM payment_vouchers")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $eCols) {
            $pvCols = [];
        }

        $insertCols = [
            'voucher_no', 'payee_name', 'payee_id', 'description', 'currency', 'total_amount', 'supporting_documents',
            'applicant', 'department_manager', 'general_manager', 'created_by', 'date_created', 'prepared_by', 'status', 'is_restricted'
        ];
        $insertVals = [
            $voucher_no,
            ($payee_name !== '' ? $payee_name : '(Draft)'),
            $payee_id,
            $description,
            $currency,
            $total_amount,
            $supporting_documents,
            $applicant,
            $department_manager,
            $general_manager,
            $createdBy,
            $date_created,
            $prepared_by,
            STATUS_CONFIRMING,
            $is_restricted
        ];

        if (in_array('checked_by', $pvCols, true)) {
            $insertCols[] = 'checked_by';
            $insertVals[] = $checked_by;
        }
        appendPaymentVoucherPurposeToInsert($insertCols, $insertVals, $pdo, $voucher_purpose, $pvCols);
        if (in_array('linked_stock_po_id', $pvCols, true)) {
            $insertCols[] = 'linked_stock_po_id';
            $insertVals[] = null;
        }
        if (in_array('linked_sales_order_id', $pvCols, true)) {
            $insertCols[] = 'linked_sales_order_id';
            $insertVals[] = ($linked_sales_order_id > 0 ? $linked_sales_order_id : null);
        }
        if (in_array('linked_sales_order_ids', $pvCols, true)) {
            $insertCols[] = 'linked_sales_order_ids';
            $insertVals[] = !empty($linked_sales_order_ids) ? json_encode($linked_sales_order_ids) : null;
        }
        if (in_array('company_id', $pvCols, true)) {
            $insertCols[] = 'company_id';
            $insertVals[] = (int) currentCompanyId();
        }

        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $stmt = $pdo->prepare("INSERT INTO payment_vouchers (" . implode(', ', $insertCols) . ") VALUES ($placeholders)");
        $stmt->execute($insertVals);

        $voucher_id = $pdo->lastInsertId();
        if (function_exists('app_log')) {
            app_log('create-voucher: voucher_id=' . $voucher_id);
        }

        // Create approvals rows for required approvers (Applicant, Department Manager, Checked By)
        try {
            $apprCols = $pdo->query("SHOW COLUMNS FROM approvals")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $hasCompanyIdAppr = in_array('company_id', $apprCols, true);
            
            $colsAppr = ["voucher_id", "approver_id", "approver_name", "role", "status", "created_at"];
            $placeholdersAppr = ["?", "?", "?", "?", "'pending'", "NOW()"];
            if ($hasCompanyIdAppr) {
                $colsAppr[] = "company_id";
                $placeholdersAppr[] = "?";
            }
            
            $ins = $pdo->prepare("INSERT INTO approvals (" . implode(", ", $colsAppr) . ") VALUES (" . implode(", ", $placeholdersAppr) . ")");
            
            $roles = [
                ['role' => 'Applicant', 'name' => $applicant],
                ['role' => 'Department Manager', 'name' => $department_manager],
                ['role' => 'Checked By', 'name' => $checked_by]
            ];
            
            $cId = (int) currentCompanyId();
            
            foreach ($roles as $r) {
                $name = trim((string)($r['name'] ?? ''));
                if ($name === '') continue; // avoid NOT NULL violation on approver_name
                $approverId = function_exists('resolveVoucherUserIdByDisplayName')
                    ? resolveVoucherUserIdByDisplayName($pdo, $name)
                    : 0;
                if ($approverId <= 0) {
                    $approverId = null;
                }
                
                $vals = [$voucher_id, $approverId, $name, $r['role']];
                if ($hasCompanyIdAppr && $cId > 0) {
                    $vals[] = $cId;
                }
                $ins->execute($vals);
            }

            // Also support arbitrary additional approvers submitted as an array `approvals[]` (name or id)
            if (!empty($_POST['approvals']) && is_array($_POST['approvals'])) {
                foreach ($_POST['approvals'] as $ap) {
                    $ap = trim((string)$ap);
                    if ($ap === '') continue;
                    $approverId = null;
                    // if numeric id provided
                    if (ctype_digit($ap)) {
                        $approverId = (int)$ap;
                        $nameCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                        $hasNameCol = in_array('name', $nameCols, true);
                        $nameExpr = $hasNameCol
                            ? "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), NULLIF(TRIM(name), ''), username, ''))"
                            : "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), username, ''))";
                        $nameRow = $pdo->prepare('SELECT ' . $nameExpr . ' FROM users WHERE id = ? LIMIT 1');
                        $nameRow->execute([$approverId]);
                        $apprName = $nameRow->fetchColumn() ?: '';
                    } else {
                        $apprName = $ap;
                        $approverId = function_exists('resolveVoucherUserIdByDisplayName')
                            ? resolveVoucherUserIdByDisplayName($pdo, $ap)
                            : 0;
                        if ($approverId <= 0) {
                            $approverId = null;
                        }
                    }
                    // avoid duplicates: check existing approvals for this voucher and approver_name/id
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM approvals WHERE voucher_id = ? AND (approver_id = ? OR approver_name = ?) ");
                    $chk->execute([$voucher_id, $approverId, $apprName]);
                    if ((int)$chk->fetchColumn() > 0) continue;
                    if ($apprName === '' || $apprName === null) continue; // Final safety for NOT NULL
                    
                    $vals = [$voucher_id, $approverId, $apprName, 'Approver'];
                    if ($hasCompanyIdAppr && $cId > 0) $vals[] = $cId;
                    $ins->execute($vals);
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: approvals table might not exist yet in older environments; log and continue
            error_log('Failed to insert approvals for voucher ' . $voucher_id . ': ' . $e->getMessage());
        }

        if (function_exists('syncVoucherApprovalAssignees')) {
            syncVoucherApprovalAssignees($pdo, (int) $voucher_id, array(
                'Applicant' => $applicant,
                'Department Manager' => $department_manager,
                'Checked By' => $checked_by,
            ));
        }

        // Insert voucher items (if any)
        if (!empty($items)) {
            $itemCols = $pdo->query("SHOW COLUMNS FROM voucher_items")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $hasCompanyIdItem = in_array('company_id', $itemCols, true);
            
            $colsItem = ["voucher_id", "payment_type", "budget_type", "name", "amount", "description"];
            $placeholdersItem = ["?", "?", "?", "?", "?", "?"];
            if ($hasCompanyIdItem) {
                $colsItem[] = "company_id";
                $placeholdersItem[] = "?";
            }

            $stmt = $pdo->prepare("
                INSERT INTO voucher_items (" . implode(", ", $colsItem) . ") 
                VALUES (" . implode(", ", $placeholdersItem) . ")
            ");
            
            $cId = (int) currentCompanyId();
            
            foreach ($items as $item) {
                $vals = [
                    $voucher_id,
                    $item['payment_type'],
                    $item['budget_type'],
                    $item['name'],
                    $item['amount'],
                    $item['description']
                ];
                if ($hasCompanyIdItem) $vals[] = $cId;
                $stmt->execute($vals);
                if (function_exists('app_log')) {
                    app_log('create-voucher: item inserted payment_type=' . $item['payment_type'] . ' amount=' . $item['amount']);
                }
            }
        }

        // Handle file uploads for supporting documents
        $uploadedCount = 0;
        if (!empty($_FILES['supporting_files']) && isset($_FILES['supporting_files']['name']) && is_array($_FILES['supporting_files']['name'])) {
            ensureVoucherAttachmentsSchema();
            $baseDir = ensureVoucherUploadsDir();
            $voucherDir = $baseDir . DIRECTORY_SEPARATOR . $voucher_id;
            if (!is_dir($voucherDir)) {
                @mkdir($voucherDir, 0775, true);
            }
            if (is_dir($voucherDir) && !is_writable($voucherDir)) {
                @chmod($voucherDir, 0775);
            }

            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
            $maxSize = 10 * 1024 * 1024; // 10MB per file
            $names = $_FILES['supporting_files']['name'];
            $tmps = $_FILES['supporting_files']['tmp_name'];
            $types = $_FILES['supporting_files']['type'];
            $sizes = $_FILES['supporting_files']['size'];
            $errs = $_FILES['supporting_files']['error'];
            $count = count($names);
            for ($i = 0; $i < $count; $i++) {
                if (!isset($names[$i]) || $errs[$i] !== UPLOAD_ERR_OK)
                    continue;
                $orig = $names[$i];
                $size = (int) ($sizes[$i] ?? 0);
                $mime = (string) ($types[$i] ?? 'application/octet-stream');
                $tmp = $tmps[$i];
                if ($size <= 0 || $size > $maxSize)
                    continue;
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true))
                    continue;
                // Build a safe unique file name
                $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
                if ($safeBase === '') {
                    $safeBase = 'file';
                }
                $unique = $safeBase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $destAbs = $voucherDir . DIRECTORY_SEPARATOR . $unique;
                $destRel = 'assets/uploads/vouchers/' . $voucher_id . '/' . $unique; // relative path for web
                if (@move_uploaded_file($tmp, $destAbs)) {
                    // record row
                    addVoucherAttachment($voucher_id, $destRel, $orig, $mime, $size, $createdBy);
                    $uploadedCount++;
                    if (function_exists('app_log')) {
                        app_log('create-voucher: file uploaded original=' . $orig . ' stored=' . $destRel . ' size=' . $size);
                    }
                }
            }
            // If any files uploaded, update the numeric count field to reflect reality
            if ($uploadedCount > 0) {
                try {
                    $up = $pdo->prepare("UPDATE payment_vouchers SET supporting_documents = ? WHERE id = ?");
                    $up->execute([$uploadedCount, $voucher_id]);
                } catch (Exception $e) { /* ignore */
                }
            }
        }
        if (function_exists('app_log')) {
            app_log('create-voucher: uploadedCount=' . $uploadedCount);
        }

        // Log the creation (catch any logging errors separately)
        try {
            logVoucherAction($voucher_id, $createdBy, 'created');
        } catch (Exception $e) {
            // Log failed but voucher was created - continue
            error_log("Voucher log failed: " . $e->getMessage());
        }

        if (function_exists('app_log')) {
            app_log('create-voucher: about to commit');
        }
        // Commit transaction (only if still active)
        if ($txStarted && $pdo->inTransaction()) {
            $pdo->commit();
            $committed = true;
            if (function_exists('app_log')) {
                app_log('create-voucher: committed successfully');
            }
        }

        // Post-commit non-critical actions (should not affect success shown to user)
        try {
            notifyAdminsNewVoucher($voucher_id);
        } catch (Throwable $e2) {
            error_log('notifyAdminsNewVoucher failed: ' . $e2->getMessage());
        }
        // Notify selected Finance user (Checked By)
        try {
            notifyCheckedByAssignee($voucher_id);
        } catch (Throwable $e3) {
            error_log('notifyCheckedByAssignee failed: ' . $e3->getMessage());
        }

        // Safe redirect
        if ($isDraft) {
            $redirectUrl = rtrim(APP_BASE_PATH, '/') . '/employee/edit-voucher.php?id=' . $voucher_id . '&draft=1';
        } else {
            // Send the user back to their vouchers list and highlight the new one.
            $_SESSION['success_msg'] = 'Voucher ' . $voucher_no . ' created successfully.';
            $qsParts = [];
            if (isset($_GET['module']) && (string) $_GET['module'] !== '') {
                $qsParts[] = 'module=' . rawurlencode((string) $_GET['module']);
            }
            $qsParts[] = 'created=' . (int) $voucher_id;
            $redirectUrl = 'my-vouchers.php?' . implode('&', $qsParts);
        }
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
            exit();
        } else {
            // Headers already sent (e.g., due to BOM or stray output). Avoid inline JS (blocked by CSP) and use meta refresh.
            if (function_exists('app_log')) {
                app_log('create-voucher: headers already sent, using meta refresh to ' . $redirectUrl);
            }
            $safe = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $safe . '"><title>Redirecting...</title></head><body>Redirecting... <a href="' . $safe . '">Continue</a></body></html>';
            exit();
        }

    } catch (Exception $e) {
        if (function_exists('app_log')) {
            app_log("Voucher creation failed: " . $e->getMessage());
            app_log($e->getTraceAsString());
        }
        // Roll back only if we actually started and it's still active
        if ($txStarted && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rbEx) {
                error_log('Rollback failed (maybe already done): ' . $rbEx->getMessage());
            }
        }
        // Show more specific error messages for common issues
        $errorMsg = $e->getMessage();
        $userFriendlyMsg = 'An error occurred while creating the voucher. Please review your entries and try again.';

        // Provide more specific error messages for common issues
        if (strpos($errorMsg, 'SQLSTATE') !== false) {
            $userFriendlyMsg = function_exists('voucherWorkflowFriendlyError')
                ? voucherWorkflowFriendlyError($errorMsg)
                : 'Database constraint error. Please check that all required fields are filled correctly.';
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
        error_log('Voucher creation failed: ' . $errorMsg);
        error_log($e->getTraceAsString());
        if (function_exists('app_log')) {
            app_log('Voucher creation failed: ' . $errorMsg . ' TRACE: ' . $e->getTraceAsString());
        }
    }
}

// -------------------------------------------------------------------------
// Render the React front-end shell (replaces the legacy voucher-form-page.php).
// All POST handling above is preserved and reused by the React form, which
// submits multipart/form-data back to this same page.
// -------------------------------------------------------------------------
require_once __DIR__ . '/create-voucher-ui/lib.php';

$assets = createVoucherUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Create Voucher</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Create Voucher</h1>';
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

$cvPostUrl = 'create-voucher.php';
if (!empty($_SERVER['QUERY_STRING'])) {
    $cvPostUrl .= '?' . $_SERVER['QUERY_STRING'];
}

$createVoucherConfig = [
    'postUrl' => $cvPostUrl,
    'cancelUrl' => 'my-vouchers.php' . $voucherModuleQs,
    'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'voucher',
    'preparedBy' => trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')),
    'today' => date('Y-m-d'),
    'canRestrict' => (isAdmin() || isFinance()),
    'currencies' => ['TZS', 'USD', 'CNY'],
    'purposes' => [
        ['value' => 'general', 'label' => 'General Payment'],
        ['value' => 'stock_purchase', 'label' => 'Stock Purchase'],
    ],
    'paymentTypes' => ['Bank Transfer', 'Cash Payment', 'Cheque', 'Mobile Payment'],
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
    'flash' => $voucherCreateSuccess,
    'error' => $error,
];

$page_title = 'Create Voucher';
$employeeHeaderTitle = '';
$hideHeaderCompanyBranding = true;
$GLOBALS['_erp_header_style_linked'] = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Voucher</title>
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
        window.__CV_CFG__ = <?= json_encode($createVoucherConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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
            <div class="alert alert-warning">JavaScript is required to create a voucher.</div>
        </noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
