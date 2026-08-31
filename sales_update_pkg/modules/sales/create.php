<?php
// modules/sales/invoices/create.php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/revenue_ledger.php';
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);
$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
$error = null;

$doc = strtolower(trim((string)($_GET['doc'] ?? 'invoice')));
if (in_array($doc, ['quote', 'quotation'], true)) {
    header('Location: /modules/sales/orders/create.php?mode=new');
    exit;
}

$order_id = $_GET['order_id'] ?? 0;

// === MODE 1: Convert Existing Order ===
if ($order_id && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $soColsMode1 = [];
        $invColsMode1 = [];
        try {
            $soColsMode1 = $pdo->query("SHOW COLUMNS FROM sales_orders")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            $soColsMode1 = [];
        }
        try {
            $invColsMode1 = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            $invColsMode1 = [];
        }
        $hasSoCompanyId = in_array('company_id', $soColsMode1, true);
        $hasSoShippedAt = in_array('shipped_at', $soColsMode1, true);
        $hasInvCompanyId = in_array('company_id', $invColsMode1, true);

        // 1. Fetch Order Details
        $orderSql = "SELECT * FROM sales_orders WHERE id = ?";
        $orderParams = [$order_id];
        if ($hasSoCompanyId && $company_id > 0) {
            $orderSql .= " AND company_id = ?";
            $orderParams[] = $company_id;
        }
        $stmt = $pdo->prepare($orderSql);
        $stmt->execute($orderParams);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            die("Order not found.");
        }

        $resolvedCustomerId = (int) ($order['customer_id'] ?? 0);
        if ($resolvedCustomerId <= 0 && isset($order['customer']) && is_numeric($order['customer'])) {
            $resolvedCustomerId = (int) $order['customer'];
        }
        if ($resolvedCustomerId <= 0) {
            // Legacy/migrated orders may miss customer_id; pick one active customer as fallback.
            $customerColsMode1 = [];
            try {
                $customerColsMode1 = $salesDb->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            } catch (Throwable $e) {
                $customerColsMode1 = [];
            }
            $fallbackCustomerSql = "SELECT id FROM customers WHERE status = 'active'";
            $fallbackCustomerParams = [];
            if ($company_id > 0 && in_array('company_id', $customerColsMode1, true)) {
                $fallbackCustomerSql .= " AND company_id = ?";
                $fallbackCustomerParams[] = $company_id;
            }
            $fallbackCustomerSql .= " ORDER BY id ASC LIMIT 1";
            $stmtFallbackCustomer = $salesDb->prepare($fallbackCustomerSql);
            $stmtFallbackCustomer->execute($fallbackCustomerParams);
            $resolvedCustomerId = (int) ($stmtFallbackCustomer->fetchColumn() ?: 0);
        }
        if ($resolvedCustomerId <= 0) {
            die("Error creating invoice: No customer found. Please assign a customer to this order first.");
        }
        
        // Check if invoice already exists
        $invoiceCheckSql = "SELECT id FROM invoices WHERE order_id = ?";
        $invoiceCheckParams = [$order_id];
        if ($hasInvCompanyId && $company_id > 0) {
            $invoiceCheckSql .= " AND company_id = ?";
            $invoiceCheckParams[] = $company_id;
        }
        $stmtCheck = $pdo->prepare($invoiceCheckSql);
        $stmtCheck->execute($invoiceCheckParams);
        $existing = $stmtCheck->fetch();
        
        if($existing) {
            header("Location: view.php?id=" . $existing['id']);
            exit;
        }

        // 2. Generate Invoice Number
        if (function_exists('nextDocumentNumber')) {
            $invoice_number = nextDocumentNumber('invoice', 'INV');
        } else {
            $stmtCount = $pdo->query("SELECT MAX(id) FROM invoices");
            $lastId = $stmtCount->fetchColumn() ?: 0;
            $nextId = $lastId + 1;
            $invoice_number = 'INV-' . date('Y') . '-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
        }

        // 3. Create Invoice Record (schema-safe for installs without invoices.company_id)
        $invoiceFields = [
            'invoice_number',
            'order_id',
            'customer_id',
            'invoice_date',
            'due_date',
            'subtotal',
            'discount_amount',
            'tax_amount',
            'shipping_charges',
            'total_amount',
            'status',
            'created_by',
        ];
        $invoiceValueSql = [
            '?', '?', '?', 'CURDATE()', 'DATE_ADD(CURDATE(), INTERVAL 30 DAY)', '?', '?', '?', '?', '?', "'sent'", '?',
        ];
        $invoiceParams = [
            $invoice_number,
            $order['id'],
            $resolvedCustomerId,
            $order['subtotal'],
            $order['discount_amount'],
            $order['tax_amount'],
            $order['shipping_charges'] ?? 0.00,
            $order['total_amount'],
            $_SESSION['user_id'],
        ];
        if ($hasInvCompanyId) {
            $invoiceFields[] = 'company_id';
            $invoiceValueSql[] = '?';
            $invoiceParams[] = $company_id;
        }
        $sql = "INSERT INTO invoices (" . implode(', ', $invoiceFields) . ") VALUES (" . implode(', ', $invoiceValueSql) . ")";
        $stmtInsert = $pdo->prepare($sql);
        $stmtInsert->execute($invoiceParams);
        
        $invoice_id = $pdo->lastInsertId();

        // 4. Update Order Status & Deduct Stock
        $orderUpdateSql = "UPDATE sales_orders SET status = 'invoiced'";
        if ($hasSoShippedAt) {
            $orderUpdateSql .= ", shipped_at = NOW()";
        }
        $orderUpdateSql .= " WHERE id = ?";
        $orderUpdateParams = [$order_id];
        if ($hasSoCompanyId && $company_id > 0) {
            $orderUpdateSql .= " AND company_id = ?";
            $orderUpdateParams[] = $company_id;
        }
        $stmtUpdate = $pdo->prepare($orderUpdateSql);
        $stmtUpdate->execute($orderUpdateParams);
        
        // Auto-deduct Stock
        deductStockForOrder($order_id);

        // Sync to Revenue ledger (Option A)
        syncInvoiceToRevenueLedger($pdo, (int)$invoice_id, (int)($_SESSION['user_id'] ?? 0) ?: null);

        header("Location: view.php?id=" . $invoice_id . "&msg=created");
        exit;

    } catch (PDOException $e) {
        die("Error creating invoice: " . $e->getMessage());
    }
}

// === MODE 2: Direct Creation (Form Handling) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ensureCustomerColumnsExist();
        $pdo->beginTransaction();
        
        // 1. Create Underlying Sales Order (Status: Invoiced)
        // We reuse nextOrderNumber logic but perhaps we don't display it prominently if it's a direct invoice.
        // But system requires it.
        $nextNum = getNextOrderNumber();
        $order_number = 'SO-' . date('Y') . '-' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
        
        // Schema-safe insert for sales_orders (some installs don't have lead_time)
        $soCols = [];
        try {
            $soCols = $pdo->query("SHOW COLUMNS FROM sales_orders")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            $soCols = [];
        }
        $hasLeadTime = in_array('lead_time', $soCols, true);
        $hasShippedAt = in_array('shipped_at', $soCols, true);
        $hasOrderType = in_array('order_type', $soCols, true);
        $hasSoCompanyId = in_array('company_id', $soCols, true);
        $hasCurrency = in_array('currency', $soCols, true);

        $orderFields = [
            'order_number',
            'customer_id',
            'quote_date',
            'valid_until',
        ];
        $orderValues = [
            $order_number,
            $_POST['customer_id'],
            $_POST['invoice_date'], // Use invoice date as quote date
            $_POST['due_date'],     // Use due date as valid until
        ];
        if ($hasOrderType) {
            $orderFields[] = 'order_type';
            $orderValues[] = $_POST['order_type'] ?? 'spare';
        }

        if ($hasLeadTime) {
            $orderFields[] = 'lead_time';
            $orderValues[] = ($_POST['lead_time'] ?? null) !== '' ? ($_POST['lead_time'] ?? null) : null;
        }
        $orderFields = array_merge($orderFields, [
            'subtotal',
            'discount_amount',
            'tax_amount',
            'shipping_charges',
            'total_amount',
            'status',
        ]);
        $orderValues = array_merge($orderValues, [
            $_POST['subtotal'],
            $_POST['discount_amount'],
            $_POST['tax_amount'],
            $_POST['shipping_charges'],
            $_POST['total_amount'],
            'invoiced',
        ]);
        if ($hasCurrency) {
            $orderFields[] = 'currency';
            $orderValues[] = 'TZS';
        }
        $orderFields[] = 'created_by';
        $orderValues[] = $_SESSION['user_id'];
        if ($hasSoCompanyId) {
            $orderFields[] = 'company_id';
            $orderValues[] = $company_id;
        }

        $valuesSqlParts = array_fill(0, count($orderValues), '?');
        if ($hasShippedAt) {
            $orderFields[] = 'shipped_at';
            $valuesSqlParts[] = 'NOW()';
        }
        $sql = "INSERT INTO sales_orders (" . implode(', ', $orderFields) . ") VALUES (" . implode(', ', $valuesSqlParts) . ")";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($orderValues);
        
        $order_id = $pdo->lastInsertId();
        
        // 2. Add Order Items (schema-safe: some installs don't have description)
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $soiCols = [];
            try {
                $soiCols = $pdo->query("SHOW COLUMNS FROM sales_order_items")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            } catch (Throwable $e) {
                $soiCols = [];
            }
            $hasDesc = in_array('description', $soiCols, true);
            $hasItemCompanyId = in_array('company_id', $soiCols, true);

            $itemFields = [
                'order_id',
                'product_id',
                'quantity',
                'unit_price',
                'discount_percentage',
                'line_total',
            ];
            if ($hasItemCompanyId) {
                array_splice($itemFields, 1, 0, ['company_id']);
            }
            if ($hasDesc) {
                $itemFields[] = 'description';
            }
            $itemSql = "INSERT INTO sales_order_items (" . implode(', ', $itemFields) . ") VALUES (" . implode(', ', array_fill(0, count($itemFields), '?')) . ")";
            $stmtItem = $pdo->prepare($itemSql);

            foreach ($_POST['items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0) {
                    $qty = (float) ($item['quantity'] ?? 0);
                    $unit = (float) ($item['unit_price'] ?? 0);
                    $disc = (float) ($item['discount'] ?? 0);
                    $line_total = $qty * $unit;
                    $values = [$order_id];
                    if ($hasItemCompanyId) {
                        $values[] = $company_id;
                    }
                    $values = array_merge($values, [
                        $item['product_id'],
                        $qty,
                        $unit,
                        $disc,
                        $line_total,
                    ]);
                    if ($hasDesc) {
                        $values[] = $item['description'] ?? '';
                    }
                    $stmtItem->execute($values);
                }
            }
        }
        
        // 3. Create Invoice
        if (function_exists('nextDocumentNumber')) {
            $invoice_number = nextDocumentNumber('invoice', 'INV');
        } else {
            $stmtCountInv = $pdo->query("SELECT MAX(id) FROM invoices");
            $lastIdInv = $stmtCountInv->fetchColumn() ?: 0;
            $nextIdInv = $lastIdInv + 1;
            $invoice_number = 'INV-' . date('Y') . '-' . str_pad($nextIdInv, 4, '0', STR_PAD_LEFT);
        }

        $invCols = [];
        try {
            $invCols = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            $invCols = [];
        }
        $hasInvCompanyId = in_array('company_id', $invCols, true);
        $hasInvOrderType = in_array('order_type', $invCols, true);

        $invoiceFields = [
            'invoice_number',
            'order_id',
            'customer_id',
            'invoice_date',
            'due_date',
            'subtotal',
            'discount_amount',
            'tax_amount',
            'shipping_charges',
            'total_amount',
            'status',
            'created_by',
        ];
        $invoiceValueSql = [
            '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', "'sent'", '?',
        ];
        $invoiceParams = [
            $invoice_number,
            $order_id,
            $_POST['customer_id'],
            $_POST['invoice_date'],
            $_POST['due_date'],
            $_POST['subtotal'],
            $_POST['discount_amount'],
            $_POST['tax_amount'],
            $_POST['shipping_charges'],
            $_POST['total_amount'],
            $_SESSION['user_id'],
        ];
        if ($hasInvOrderType) {
            $invoiceFields[] = 'order_type';
            $invoiceValueSql[] = '?';
            $invoiceParams[] = $_POST['order_type'] ?? 'spare';
        }
        if ($hasInvCompanyId) {
            $invoiceFields[] = 'company_id';
            $invoiceValueSql[] = '?';
            $invoiceParams[] = $company_id;
        }
        $inv_sql = "INSERT INTO invoices (" . implode(', ', $invoiceFields) . ") VALUES (" . implode(', ', $invoiceValueSql) . ")";

        $stmtInsertInv = $pdo->prepare($inv_sql);
        $stmtInsertInv->execute($invoiceParams);

        
        $invoice_id = $pdo->lastInsertId();
        
        // Auto-deduct Stock
        deductStockForOrder($order_id);

        $pdo->commit();

        // Sync after commit so ledger write is not tied to the sales transaction (avoids silent rollback edge cases).
        syncInvoiceToRevenueLedger($pdo, (int)$invoice_id, (int)($_SESSION['user_id'] ?? 0) ?: null);

        header("Location: view.php?id=" . $invoice_id . "&msg=created");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error creating invoice: " . $e->getMessage();
    }
}

// === MODE 3: Direct Creation Form (View) ===
// Fetch necessary data for form
$products = [];
try {
    $prodCols = [];
    try {
        $prodCols = $salesDb->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $prodCols = [];
    }

    // Prefer main_image but fall back to image.
    $imgSelect = 'NULL AS main_image';
    if (in_array('main_image', $prodCols, true) && in_array('image', $prodCols, true)) {
        $imgSelect = 'COALESCE(p.main_image, p.image) AS main_image';
    } elseif (in_array('main_image', $prodCols, true)) {
        $imgSelect = 'p.main_image AS main_image';
    } elseif (in_array('image', $prodCols, true)) {
        $imgSelect = 'p.image AS main_image';
    }

    $itemTypeSelect = in_array('item_type', $prodCols, true) ? 'p.item_type' : "'' AS item_type";
    $hasProductCompanyId = in_array('company_id', $prodCols, true);
    $whereCompanySql = ($company_id > 0 && $hasProductCompanyId) ? (' WHERE p.company_id = ' . (int) $company_id . ' ') : ' ';

    // Keep product loading schema-safe: some installs miss company_id in one or more tables.
    $products = $salesDb->query("
        SELECT p.id, p.product_code, p.name, p.description, p.unit_price as selling_price, $itemTypeSelect, $imgSelect,
               (
                   COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) -
                   COALESCE((
                       SELECT SUM(soi.quantity)
                       FROM sales_order_items soi
                       JOIN sales_orders so ON soi.order_id = so.id
                       WHERE soi.product_id = p.id
                       AND so.status IN ('confirmed', 'invoiced', 'paid')
                       AND so.status NOT IN ('shipped', 'delivered', 'cancelled')
                       AND (so.shipped_at IS NULL OR so.shipped_at = '0000-00-00 00:00:00')
                   ), 0)
               ) as stock_quantity
        FROM products p
        " . $whereCompanySql . "
        ORDER BY p.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: if company-scoped query returns nothing, retry without company filter.
    if ($products === [] && $whereCompanySql !== ' ') {
        $products = $salesDb->query("
            SELECT p.id, p.product_code, p.name, p.description, p.unit_price as selling_price, $itemTypeSelect, $imgSelect,
                   (
                       COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) -
                       COALESCE((
                           SELECT SUM(soi.quantity)
                           FROM sales_order_items soi
                           JOIN sales_orders so ON soi.order_id = so.id
                           WHERE soi.product_id = p.id
                           AND so.status IN ('confirmed', 'invoiced', 'paid')
                           AND so.status NOT IN ('shipped', 'delivered', 'cancelled')
                           AND (so.shipped_at IS NULL OR so.shipped_at = '0000-00-00 00:00:00')
                       ), 0)
                   ) as stock_quantity
            FROM products p
            ORDER BY p.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $products = [];
}

$customers = [];
try {
    $customerCols = [];
    try {
        $customerCols = $salesDb->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $customerCols = [];
    }

    $customerSql = "SELECT id, customer_code, company_name, contact_person, phone, email FROM customers WHERE status = 'active'";
    $customerParams = [];
    if ($company_id > 0 && in_array('company_id', $customerCols, true)) {
        $customerSql .= " AND company_id = ?";
        $customerParams[] = $company_id;
    }
    $customerSql .= " ORDER BY company_name";

    $stmtCustomers = $salesDb->prepare($customerSql);
    $stmtCustomers->execute($customerParams);
    $customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($customers === []) {
        $stmtCustomers = $salesDb->prepare("SELECT id, customer_code, company_name, contact_person, phone, email FROM customers WHERE status = 'active' ORDER BY company_name");
        $stmtCustomers->execute();
        $customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $customers = [];
}

$nextInvoiceNumber = '';
try {
    $yr = (int) date('Y');
    $fallbackPrefix = 'INV/' . $yr . '/';
    if ($company_id > 0 && function_exists('tableExists') && tableExists('document_sequences')) {
        $stmt = $pdo->prepare('SELECT prefix, next_number, padding FROM document_sequences WHERE company_id = ? AND document_type = ? AND year = ? LIMIT 1');
        $stmt->execute([$company_id, 'invoice', $yr]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $p = (string) ($row['prefix'] ?? $fallbackPrefix);
            $num = (int) ($row['next_number'] ?? 1);
            $pad = max(1, (int) ($row['padding'] ?? 3));
            $nextInvoiceNumber = $p . str_pad((string) $num, $pad, '0', STR_PAD_LEFT);
        } else {
            $nextInvoiceNumber = $fallbackPrefix . str_pad('1', 3, '0', STR_PAD_LEFT);
        }
    } else {
        $stmtCountInv = $pdo->query('SELECT MAX(id) FROM invoices');
        $lastIdInv = (int) ($stmtCountInv->fetchColumn() ?: 0);
        $nextInvoiceNumber = 'INV-' . date('Y') . '-' . str_pad((string) ($lastIdInv + 1), 4, '0', STR_PAD_LEFT);
    }
} catch (Throwable $e) {
    $nextInvoiceNumber = '';
}
$catalogueUrl = sales_catalogue_url('invoice');
$customerCatalogueUrl = sales_customer_catalogue_url('invoice', sales_module_url('invoices/create.php'));
$predefinedType = $_GET['type'] ?? 'spare';
$companyTaxMode = trim((string) getCompanySetting('tax_calculation_mode', 'exclusive'));
if (!in_array($companyTaxMode, ['exclusive', 'inclusive'], true)) {
    $companyTaxMode = 'exclusive';
}

require __DIR__ . '/partials/create-invoice-view.php';
