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
if (function_exists('ensureVoucherStockPurchaseSchema')) {
    ensureVoucherStockPurchaseSchema();
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
    if (!in_array('linked_stock_po_ids', $pvColsInit, true)) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN linked_stock_po_ids TEXT NULL");
        } catch (Throwable $ePoIds) { /* ignore */
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

// Purchase orders for Stock Purchase linking (all POs; newest / current first).
$purchaseOrders = [];
try {
    if (function_exists('tableExists') && tableExists('stocks_purchase_orders', $pdo)) {
        $hasSupplierJoin = function_exists('tableExists') && tableExists('stocks_suppliers', $pdo);
        $supplierSelect = $hasSupplierJoin
            ? "COALESCE(ss.name, CONCAT('Supplier #', po.supplier_id)) AS supplier_name"
            : "CONCAT('Supplier #', po.supplier_id) AS supplier_name";
        $join = $hasSupplierJoin ? 'LEFT JOIN stocks_suppliers ss ON ss.id = po.supplier_id' : '';
        // Current (not fully closed/received) first, then newest created.
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

// Inline PO preview for the create-voucher picker (no redirect to stock module).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_view_po') {
    header('Content-Type: application/json; charset=utf-8');
    $poId = isset($_POST['po_id']) && is_numeric($_POST['po_id']) ? (int) $_POST['po_id'] : 0;
    if ($poId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid purchase order.']);
        exit;
    }
    try {
        if (!function_exists('fetchStockPurchaseOrderById')) {
            echo json_encode(['success' => false, 'message' => 'Purchase order lookup unavailable.']);
            exit;
        }
        $po = fetchStockPurchaseOrderById($pdo, $poId, true);
        if (!$po) {
            echo json_encode(['success' => false, 'message' => 'Purchase order not found.']);
            exit;
        }
        $poTable = (string) ($po['_po_table'] ?? 'stocks_purchase_orders');
        $isLegacy = ($poTable === 'purchases');
        $items = [];
        try {
            if ($isLegacy && function_exists('tableExists') && tableExists('purchase_items', $pdo)) {
                $hasProducts = tableExists('products', $pdo);
                $sql = $hasProducts
                    ? 'SELECT pi.id, pi.product_id, pi.quantity, pi.unit_price,
                              (pi.quantity * pi.unit_price) AS line_total,
                              COALESCE(pr.name, CONCAT(\'Product #\', pi.product_id)) AS product_name,
                              COALESCE(pr.product_code, \'\') AS product_code
                       FROM purchase_items pi
                       LEFT JOIN products pr ON pr.id = pi.product_id
                       WHERE pi.purchase_id = ?
                       ORDER BY pi.id ASC'
                    : 'SELECT pi.id, pi.product_id, pi.quantity, pi.unit_price,
                              (pi.quantity * pi.unit_price) AS line_total,
                              CONCAT(\'Product #\', pi.product_id) AS product_name,
                              \'\' AS product_code
                       FROM purchase_items pi
                       WHERE pi.purchase_id = ?
                       ORDER BY pi.id ASC';
                $stmtItems = $pdo->prepare($sql);
                $stmtItems->execute([$poId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } elseif (function_exists('tableExists') && tableExists('stocks_po_items', $pdo)) {
                $hasStockItems = tableExists('stocks_items', $pdo);
                $sql = $hasStockItems
                    ? 'SELECT pi.id, pi.item_id AS product_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price,
                              (pi.qty_ordered * pi.unit_cost) AS line_total,
                              COALESCE(si.name, CONCAT(\'Item #\', pi.item_id)) AS product_name,
                              COALESCE(si.sku, \'\') AS product_code
                       FROM stocks_po_items pi
                       LEFT JOIN stocks_items si ON si.id = pi.item_id
                       WHERE pi.po_id = ?
                       ORDER BY pi.id ASC'
                    : 'SELECT pi.id, pi.item_id AS product_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price,
                              (pi.qty_ordered * pi.unit_cost) AS line_total,
                              CONCAT(\'Item #\', pi.item_id) AS product_name,
                              \'\' AS product_code
                       FROM stocks_po_items pi
                       WHERE pi.po_id = ?
                       ORDER BY pi.id ASC';
                $stmtItems = $pdo->prepare($sql);
                $stmtItems->execute([$poId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $eItems) {
            $items = [];
        }

        $currency = (string) ($po['currency'] ?? 'TZS');
        $exchangeRate = (float) ($po['exchange_rate'] ?? 1);
        if ($exchangeRate <= 0) {
            $exchangeRate = 1.0;
        }
        $mappedItems = [];
        $subtotal = 0.0;
        foreach ($items as $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            $unit = (float) ($row['unit_price'] ?? 0);
            // stocks unit_cost is typically base/USD; convert for display when rate present
            if (!$isLegacy && $exchangeRate != 1.0) {
                $unit = $unit * $exchangeRate;
            }
            $lineTotal = isset($row['line_total']) ? (float) $row['line_total'] : ($qty * $unit);
            if (!$isLegacy && $exchangeRate != 1.0 && isset($row['line_total'])) {
                $lineTotal = (float) $row['line_total'] * $exchangeRate;
            }
            $subtotal += $lineTotal;
            $mappedItems[] = [
                'id' => (int) ($row['id'] ?? 0),
                'product_name' => (string) ($row['product_name'] ?? 'Item'),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
            ];
        }

        $total = isset($po['total_amount']) ? (float) $po['total_amount'] : $subtotal;
        if ($total <= 0) {
            $total = $subtotal;
        }

        echo json_encode([
            'success' => true,
            'po' => [
                'id' => $poId,
                'po_number' => (string) ($po['po_number'] ?? $po['purchase_no'] ?? ('PO-' . $poId)),
                'supplier_name' => (string) ($po['supplier_name'] ?? ''),
                'status' => (string) ($po['status'] ?? ''),
                'purchase_type' => (string) ($po['purchase_type'] ?? 'domestic'),
                'currency' => $currency,
                'created_at' => (string) ($po['created_at'] ?? ''),
                'supplier_invoice_no' => (string) ($po['supplier_invoice_no'] ?? ''),
                'notes' => (string) ($po['notes'] ?? $po['remarks'] ?? ''),
                'subtotal' => $subtotal,
                'total_amount' => $total,
                'items' => $mappedItems,
            ],
        ]);
    } catch (Throwable $e) {
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
        $linked_stock_po_ids_raw = trim((string) ($_POST['linked_stock_po_ids'] ?? ''));
        $linked_stock_po_ids = [];
        if ($linked_stock_po_ids_raw !== '') {
            $parts = preg_split('/\s*,\s*/', $linked_stock_po_ids_raw);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    $id = (int) $p;
                    if ($id > 0) {
                        $linked_stock_po_ids[$id] = $id;
                    }
                }
            }
        } elseif (isset($_POST['linked_stock_po_id']) && is_numeric($_POST['linked_stock_po_id'])) {
            $id = (int) $_POST['linked_stock_po_id'];
            if ($id > 0) {
                $linked_stock_po_ids[$id] = $id;
            }
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
        $linked_stock_po_id = !empty($linked_stock_po_ids) ? (int) $linked_stock_po_ids[0] : 0;
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
            // Stock Purchase vouchers require a linked Purchase Order from the system
            if ($voucher_purpose === 'stock_purchase') {
                if ($linked_stock_po_id <= 0 || empty($linked_stock_po_ids)) {
                    throw new Exception('Please select at least one Purchase Order for Stock Purchase vouchers');
                }
            } else {
                $linked_stock_po_id = 0;
                $linked_stock_po_ids = [];
            }
        } elseif ($voucher_purpose !== 'stock_purchase') {
            $linked_stock_po_id = 0;
            $linked_stock_po_ids = [];
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
            $insertVals[] = ($linked_stock_po_id > 0 ? $linked_stock_po_id : null);
        }
        if (in_array('linked_stock_po_ids', $pvCols, true)) {
            $insertCols[] = 'linked_stock_po_ids';
            $insertVals[] = !empty($linked_stock_po_ids) ? json_encode(array_values($linked_stock_po_ids)) : null;
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
        $uploadOneFile = static function (
            string $orig,
            string $tmp,
            string $mime,
            int $size,
            array $allowedExt,
            int $maxSize,
            string $voucherDir,
            int $voucherId,
            int $createdBy,
            string $namePrefix = ''
        ) use (&$uploadedCount): bool {
            if ($size <= 0 || $size > $maxSize) {
                return false;
            }
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                return false;
            }
            $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
            if ($safeBase === '') {
                $safeBase = 'file';
            }
            $unique = $safeBase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $destAbs = $voucherDir . DIRECTORY_SEPARATOR . $unique;
            $destRel = 'assets/uploads/vouchers/' . $voucherId . '/' . $unique;
            if (!@move_uploaded_file($tmp, $destAbs)) {
                return false;
            }
            $storedName = $namePrefix !== '' ? ($namePrefix . ltrim($orig)) : $orig;
            addVoucherAttachment($voucherId, $destRel, $storedName, $mime, $size, $createdBy);
            $uploadedCount++;
            if (function_exists('app_log')) {
                app_log('create-voucher: file uploaded original=' . $storedName . ' stored=' . $destRel . ' size=' . $size);
            }
            return true;
        };

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'webp', 'bmp'];
        $maxSize = 10 * 1024 * 1024; // 10MB per file
        $needsUploadDir = (!empty($_FILES['supporting_files']['name']) && is_array($_FILES['supporting_files']['name']));

        if ($needsUploadDir) {
            ensureVoucherAttachmentsSchema();
            $baseDir = ensureVoucherUploadsDir();
            $voucherDir = $baseDir . DIRECTORY_SEPARATOR . $voucher_id;
            if (!is_dir($voucherDir)) {
                @mkdir($voucherDir, 0775, true);
            }
            if (is_dir($voucherDir) && !is_writable($voucherDir)) {
                @chmod($voucherDir, 0775);
            }

            if (!empty($_FILES['supporting_files']) && isset($_FILES['supporting_files']['name']) && is_array($_FILES['supporting_files']['name'])) {
                $names = $_FILES['supporting_files']['name'];
                $tmps = $_FILES['supporting_files']['tmp_name'];
                $types = $_FILES['supporting_files']['type'];
                $sizes = $_FILES['supporting_files']['size'];
                $errs = $_FILES['supporting_files']['error'];
                $count = count($names);
                for ($i = 0; $i < $count; $i++) {
                    if (!isset($names[$i]) || $errs[$i] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $uploadOneFile(
                        (string) $names[$i],
                        (string) $tmps[$i],
                        (string) ($types[$i] ?? 'application/octet-stream'),
                        (int) ($sizes[$i] ?? 0),
                        $allowedExt,
                        $maxSize,
                        $voucherDir,
                        (int) $voucher_id,
                        $createdBy
                    );
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

        // Bidirectional link: PO(s) → voucher
        if (!empty($linked_stock_po_ids) && !$isDraft) {
            try {
                if (function_exists('tableExists') && tableExists('stocks_purchase_orders', $pdo)) {
                    $poColsLink = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    $hasPvId = in_array('payment_voucher_id', $poColsLink, true);
                    $hasPvIds = in_array('payment_voucher_ids', $poColsLink, true);
                    foreach ($linked_stock_po_ids as $poLinkId) {
                        $poLinkId = (int) $poLinkId;
                        if ($poLinkId <= 0) {
                            continue;
                        }
                        if ($hasPvId) {
                            $pdo->prepare('UPDATE stocks_purchase_orders SET payment_voucher_id = ? WHERE id = ?')
                                ->execute([(int) $voucher_id, $poLinkId]);
                        }
                        if ($hasPvIds) {
                            $curRow = $pdo->prepare('SELECT payment_voucher_ids FROM stocks_purchase_orders WHERE id = ? LIMIT 1');
                            $curRow->execute([$poLinkId]);
                            $rawIds = (string) ($curRow->fetchColumn() ?: '');
                            $idsMap = [];
                            if ($rawIds !== '' && function_exists('parseStockPurchasePoLinkedVoucherIds')) {
                                foreach (parseStockPurchasePoLinkedVoucherIds(['payment_voucher_ids' => $rawIds]) as $vid) {
                                    $idsMap[(int) $vid] = (int) $vid;
                                }
                            } elseif ($rawIds !== '') {
                                $decoded = json_decode($rawIds, true);
                                if (is_array($decoded)) {
                                    foreach ($decoded as $vid) {
                                        $vid = (int) $vid;
                                        if ($vid > 0) {
                                            $idsMap[$vid] = $vid;
                                        }
                                    }
                                }
                            }
                            $idsMap[(int) $voucher_id] = (int) $voucher_id;
                            $pdo->prepare('UPDATE stocks_purchase_orders SET payment_voucher_ids = ? WHERE id = ?')
                                ->execute([json_encode(array_values($idsMap)), $poLinkId]);
                        }
                    }
                }
            } catch (Throwable $ePoLink) {
                error_log('create-voucher PO link failed: ' . $ePoLink->getMessage());
            }
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
        // Best-effort WhatsApp Cloud API notify (Kapso/Meta) when enabled
        if (!$isDraft && function_exists('maybeAutoSendVoucherWhatsApp')) {
            try {
                maybeAutoSendVoucherWhatsApp((int) $voucher_id, (string) ($_SESSION['full_name'] ?? ''));
            } catch (Throwable $eWa) {
                error_log('maybeAutoSendVoucherWhatsApp failed: ' . $eWa->getMessage());
            }
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
// Render via erp-laravel (Domains/Voucher + React create-voucher-ui).
// POST create/draft/payee stays above; form still posts back to this URL.
// -------------------------------------------------------------------------
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'voucher';
}
$_SESSION['active_module'] = 'voucher';

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
$backUrl = $slug !== ''
    ? company_url('select-module', $slug)
    : (function_exists('app_url') ? app_url('/select-module.php') : '/select-module.php');

$publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . (string) ($_SERVER['REQUEST_URI'] ?? '/employee/create-voucher.php');
$publicUrl = strtok($publicUrl, '?') ?: $publicUrl;

$dbName = '';
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $dbName = '';
}

$GLOBALS['ERP_VOUCHER_CONTEXT'] = [
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'voucher_url' => $publicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
    'is_admin' => function_exists('isAdmin') && isAdmin(),
    'is_finance' => function_exists('isFinance') && isFinance(),
    'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'voucher',
    'client_cfg_data' => [
        'payees' => is_array($payees) ? $payees : [],
        'users' => is_array($allUsers) ? $allUsers : [],
        'financeUsers' => is_array($financeUsers) ? $financeUsers : [],
        'salesOrders' => is_array($salesOrders) ? $salesOrders : [],
        'purchaseOrders' => is_array($purchaseOrders) ? $purchaseOrders : [],
        'flash' => $voucherCreateSuccess,
        'error' => $error,
        'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'voucher',
    ],
];

$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_VOUCHER_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = $GLOBALS['ERP_VOUCHER_CONTEXT']['module'];
$GLOBALS['ERP_ROUTE'] = '/voucher/create';
$GLOBALS['ERP_VOUCHER_ROUTE'] = '/voucher/create';

$laravelRoot = dirname(__DIR__) . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';
$laravelEnv = $laravelRoot . '/.env';
$laravelEnvExample = $laravelRoot . '/.env.example';
if (!is_file($laravelEnv) && is_file($laravelEnvExample)) {
    @copy($laravelEnvExample, $laravelEnv);
}

if (!is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
