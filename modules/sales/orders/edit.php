<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . (function_exists('sales_module_url') ? sales_module_url('orders/create.php', ['module' => 'sales']) : 'create.php?module=sales'));
    exit;
}

if (function_exists('salesQuoteCreateUsesReactShell') && salesQuoteCreateUsesReactShell()) {
    require_once __DIR__ . '/includes/order-edit-lib.php';
    salesOrderEditRenderReactShell($id);
}

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;

// Fetch Order
$orderSql = "SELECT * FROM sales_orders WHERE id = ?";
$orderParams = [$id];
$orderScope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('sales_orders') : ['', []];
$orderSql .= $orderScope[0];
$orderParams = array_merge($orderParams, $orderScope[1]);
$stmt = $salesDb->prepare($orderSql);
$stmt->execute($orderParams);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found.");
}

// Allow editing only if draft or quotation
if (!in_array($order['status'], ['draft', 'quotation'])) {
    die("This order cannot be edited as it is already " . $order['status']);
}

// Fetch Items
$prodCols = [];
try {
    $prodCols = $salesDb->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
} catch (Throwable $e) {
    $prodCols = [];
}
$imgSelect = 'NULL AS main_image';
if (in_array('main_image', $prodCols, true) && in_array('image', $prodCols, true)) {
    $imgSelect = 'COALESCE(p.main_image, p.image) AS main_image';
} elseif (in_array('main_image', $prodCols, true)) {
    $imgSelect = 'p.main_image AS main_image';
} elseif (in_array('image', $prodCols, true)) {
    $imgSelect = 'p.image AS main_image';
}
$stmtItems = $salesDb->prepare("
    SELECT soi.*, p.name AS product_name, p.product_code, $imgSelect,
           (SELECT COALESCE(SUM(quantity), 0) FROM stock WHERE product_id = p.id) as stock_quantity,
           p.unit_price as list_price
    FROM sales_order_items soi
    LEFT JOIN products p ON soi.product_id = p.id
    WHERE soi.order_id = ?
");
$stmtItems->execute([$id]);
$orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Get available products (Same as create.php)
try {
    $products = $salesDb->query("
        SELECT p.id, p.product_code, p.name, p.description, p.unit_price as selling_price, $imgSelect,
               SUM(COALESCE(s.quantity, 0)) as stock_quantity
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id
        GROUP BY p.id, p.product_code, p.name, p.description, p.unit_price, main_image
        ORDER BY p.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
}

// Get customers
$customers = $salesDb->query("SELECT id, customer_code, company_name, contact_person FROM customers WHERE status = 'active' ORDER BY company_name")->fetchAll();

// Get users for salesperson
$users = $salesDb->query("SELECT id, username, full_name FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Handle Form Submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $salesDb->beginTransaction();
        
        // 1. Update Sales Order
        $update_sql = "UPDATE sales_orders SET 
            customer_id = ?, quote_date = ?, valid_until = ?, lead_time = ?,
            subtotal = ?, discount_amount = ?, tax_amount = ?, shipping_charges = ?,
            total_amount = ?, status = ?, created_by = ?, updated_at = NOW()
            WHERE id = ?";
        if (!empty($orderScope[0])) {
            $update_sql .= str_replace(' AND ', ' AND ', $orderScope[0]);
        }
            
        $stmt = $salesDb->prepare($update_sql);
        $updateParams = [
            !empty($_POST['customer_id']) ? $_POST['customer_id'] : null,
            $_POST['quote_date'],
            $_POST['valid_until'],
            $_POST['lead_time'] !== '' ? $_POST['lead_time'] : null,
            $_POST['subtotal'],
            $_POST['discount_amount'],
            $_POST['tax_amount'],
            $_POST['shipping_charges'],
            $_POST['total_amount'],
            $_POST['status'], // Allow status update (e.g. valid -> quotation)
            !empty($_POST['created_by']) ? $_POST['created_by'] : $_SESSION['user_id'],
            $id
        ];
        $updateParams = array_merge($updateParams, $orderScope[1]);
        $stmt->execute($updateParams);
        
        // 2. Replace Order Items (Delete all, then Insert new)
        $salesDb->prepare("DELETE FROM sales_order_items WHERE order_id = ?")->execute([$id]);
        
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $soiCols = [];
            try {
                $soiCols = $salesDb->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            } catch (Throwable $e) {
                $soiCols = [];
            }
            $hasItemCompanyId = in_array('company_id', $soiCols, true);
            $itemFields = ['order_id', 'product_id', 'quantity', 'unit_price', 'discount_percentage', 'line_total', 'description'];
            if ($hasItemCompanyId) {
                array_splice($itemFields, 1, 0, ['company_id']);
            }
            $item_sql = 'INSERT INTO sales_order_items (' . implode(', ', $itemFields) . ') VALUES (' . implode(', ', array_fill(0, count($itemFields), '?')) . ')';
            $stmt = $salesDb->prepare($item_sql);
            
            foreach ($_POST['items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0) {
                    $line_total = $item['quantity'] * $item['unit_price'];
                    $itemValues = [$id];
                    if ($hasItemCompanyId) {
                        $itemValues[] = (int) (currentCompanyId() ?? 0);
                    }
                    $itemValues = array_merge($itemValues, [
                        $item['product_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['discount'] ?? 0,
                        $line_total,
                        $item['description'] ?? ''
                    ]);
                    $stmt->execute($itemValues);
                }
            }
        }
        
        $salesDb->commit();
        $_SESSION['success'] = "Order updated successfully!";
        header("Location: view.php?id=" . $id);
        exit();
        
    } catch (Exception $e) {
        if ($salesDb->inTransaction()) {
            $salesDb->rollBack();
        }
        $_SESSION['error'] = "Error updating order: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Quotation - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="/assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        /* Reuse styles from create.php */
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; font-size: 0.85rem; }
        .card { border: none; box-shadow: 0 2px 6px rgba(0,0,0,0.02); border-radius: 4px; margin-bottom: 15px; }
        .card-header { background-color: #fff; border-bottom: 1px solid #f0f0f0; padding: 10px 15px; font-weight: 600; color: #495057; }
        .form-label { font-weight: 600; font-size: 0.8rem; color: #344767; margin-bottom: 4px; }
        .form-control, .form-select { border: 1px solid #e0e0e0; font-size: 0.85rem; padding: 4px 8px; }
        .form-control:focus { border-color: #4a90e2; box-shadow: 0 0 0 2px rgba(74,144,226,0.1); }
        .select2-container .select2-selection--single { height: 32px; border: 1px solid #e0e0e0; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 30px; padding-left: 8px; font-size: 0.85rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 30px; }
        .total-label { font-size: 0.85rem; color: #6c757d; }
        .total-value { font-size: 0.9rem; font-weight: 600; color: #344767; }
        .grand-total { font-size: 1.1rem; font-weight: 700; color: #344767; }
    </style>
</head>
<body>
    <?php include '../../../includes/header_employee.php'; ?>
    
    <div class="main-content">
        <form id="salesOrderForm" method="POST">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">Edit Quotation: <span class="text-primary"><?php echo $order['order_number']; ?></span></h4>
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">Back to View</a>
            </div>

            <div class="row mb-3">
                <!-- Customer Info -->
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-header">Customer Info</div>
                        <div class="card-body">
                            <label class="form-label">Customer *</label>
                            <select name="customer_id" id="customerSelect" class="form-select form-select-sm" required style="width: 100%;">
                                <option value="">Select Customer</option>
                                <?php foreach($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" <?php echo ($customer['id'] == $order['customer_id']) ? 'selected' : ''; ?>>
                                    <?php echo $customer['company_name']; ?> (<?php echo $customer['contact_person']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Order Details -->
                <div class="col-md-8">
                    <div class="card h-100">
                        <div class="card-header">Order Details</div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-2">
                                        <label class="form-label">Date *</label>
                                        <input type="date" name="quote_date" class="form-control form-control-sm" 
                                               value="<?php echo date('Y-m-d', strtotime($order['quote_date'])); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-2">
                                        <label class="form-label">Valid Until</label>
                                        <input type="date" name="valid_until" class="form-control form-control-sm" 
                                               value="<?php echo !empty($order['valid_until']) ? date('Y-m-d', strtotime($order['valid_until'])) : ''; ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-2">
                                        <label class="form-label">Lead Time (Days)</label>
                                        <input type="number" name="lead_time" class="form-control form-control-sm" 
                                               value="<?php echo htmlspecialchars((string)($order['lead_time'] ?? '')); ?>" placeholder="e.g., 10" min="0">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-2">
                                        <label class="form-label">Salesperson</label>
                                        <select name="created_by" class="form-select form-select-sm" style="width: 100%;">
                                            <option value="">Select Salesperson</option>
                                            <?php foreach($users as $u): ?>
                                            <option value="<?php echo $u['id']; ?>" <?php echo ($u['id'] == $order['created_by']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($u['full_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="card mb-3">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0" id="itemsTable">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width: 8%;" class="text-center">IMAGE</th>
                                    <th style="width: 35%; padding-left: 15px;">ITEM / DESCRIPTION</th>
                                    <th style="width: 12%;">QUANTITY</th>
                                    <th style="width: 15%;">UNIT PRICE</th>
                                    <th style="width: 12%;">DISC %</th>
                                    <th style="width: 16%;">TOTAL</th>
                                    <th style="width: 10%;" class="text-center">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody"></tbody>
                        </table>
                    </div>
                    <div class="border-top p-2">
                        <button type="button" class="btn btn-sm text-muted bg-transparent border-0" style="font-weight: 500; color: #6c757d !important;" onclick="addNewRow()">
                            <i class="fas fa-plus me-1"></i> Add Line Item
                        </button>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Notes -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">Notes / Terms</div>
                        <div class="card-body">
                            <textarea name="terms_conditions" class="form-control form-control-sm border-0" rows="3"><?php echo htmlspecialchars($order['terms_conditions'] ?? $order['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Totals -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="total-label">Subtotal:</span>
                                <span class="total-value" id="subtotalDisplay">0.00</span>
                                <input type="hidden" name="subtotal" id="subtotal" value="0.00">
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="total-label">Discount Amount:</span>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" 
                                       style="width: 90px;" id="discountAmount" name="discount_amount" 
                                       value="<?php echo $order['discount_amount']; ?>" oninput="calculateTotals()">
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="total-label">Tax (%):</span>
                                <div class="d-flex align-items-center gap-2">
                                     <!-- Back calc percentage or just field -->
                                    <input type="number" step="0.01" class="form-control form-control-sm text-end" 
                                           style="width: 60px;" id="taxPercentage" name="tax_percentage" 
                                           value="<?php echo ($order['subtotal'] > 0) ? round(($order['tax_amount'] / ($order['subtotal'] - $order['discount_amount'])) * 100, 2) : 0; ?>" 
                                           oninput="calculateTotals()">
                                    <span class="total-value" id="taxDisplay"><?php echo $order['tax_amount']; ?></span>
                                    <input type="hidden" name="tax_amount" id="taxAmount" value="<?php echo $order['tax_amount']; ?>">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="total-label">Shipping:</span>
                                <input type="number" step="0.01" class="form-control form-control-sm text-end" 
                                       style="width: 90px;" id="shippingCharges" name="shipping_charges" 
                                       value="<?php echo $order['shipping_charges']; ?>" oninput="calculateTotals()">
                            </div>
                            <div class="border-top pt-2 d-flex justify-content-between align-items-center">
                                <span class="grand-total">Total:</span>
                                <span class="grand-total" id="grandTotalDisplay"><?php echo $order['total_amount']; ?></span>
                                <input type="hidden" name="total_amount" id="grandTotal" value="<?php echo $order['total_amount']; ?>">
                            </div>
                            
                            <div class="d-grid gap-2 mt-3">
                                <button type="submit" name="status" value="<?php echo $order['status']; ?>" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        const products = <?php echo json_encode($products); ?>;
        // Existing items to populate
        const existingItems = <?php echo json_encode($orderItems); ?>;
        
        let rowCount = 0;
        
        $(document).ready(function() {
            $('#customerSelect').select2({ placeholder: "Select Customer", width: '100%' });
            
            if (existingItems && existingItems.length > 0) {
                existingItems.forEach(item => {
                    addNewRow(item);
                });
            } else {
                addNewRow();
            }
            
            calculateTotals();
        });
        
        window.addNewRow = function(data = null) {
            rowCount++;
            const pid = data ? data.product_id : '';
            const qty = data ? data.quantity : 1;
            const price = data ? parseFloat(data.unit_price).toFixed(2) : '0.00';
            const oldPrice = data ? parseFloat(data.list_price).toFixed(2) : '0.00'; // Original price reference
            const discount = data ? data.discount_percentage : 0;
            const desc = data ? data.description : '';
            const imgParams = data && data.main_image 
                ? `/stock/uploads/products/${pid}/medium/${data.main_image}` 
                : '/assets/images/placeholder.png'; // Handled by updateProductInfo usually, but pre-fill logic below
            
            const row = `
            <tr id="row${rowCount}">
                <td class="text-center align-middle">
                    <img src="${imgParams}" id="img${rowCount}" 
                         style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #eee;">
                </td>
                <td style="padding-left: 15px;">
                    <select name="items[${rowCount}][product_id]" class="form-select form-select-sm product-select mb-1" 
                            style="width: 100%;" onchange="updateProductInfo(${rowCount})" required>
                        <option value="">Search product...</option>
                        ${products.map(p => `
                            <option value="${p.id}" 
                                    ${p.id == pid ? 'selected' : ''}
                                    data-price="${p.selling_price || 0}" 
                                    data-code="${p.product_code || ''}"
                                    data-image="${p.main_image || ''}"
                                    data-desc="${(p.description || '').replace(/"/g, '&quot;')}">
                                ${p.name} (${p.product_code || 'N/A'}) - ${p.stock_quantity || 0} in stock
                            </option>`).join('')}
                    </select>
                    <textarea name="items[${rowCount}][description]" id="desc${rowCount}" 
                              class="form-control form-control-sm text-muted" 
                              placeholder="Description" rows="1" style="resize:none; font-size: 0.8rem;">${desc}</textarea>
                </td>
                <td>
                    <input type="number" name="items[${rowCount}][quantity]" class="form-control form-control-sm text-center quantity" 
                           value="${qty}" min="1" oninput="calculateRowTotal(${rowCount})" required>
                </td>
                <td>
                    <input type="number" name="items[${rowCount}][unit_price]" class="form-control form-control-sm text-end unit-price" 
                           value="${price}" step="0.01" id="unitPrice${rowCount}" oninput="calculateRowTotal(${rowCount})" required>
                </td>
                <td>
                    <input type="number" name="items[${rowCount}][discount]" class="form-control form-control-sm text-center discount" 
                           value="${discount}" min="0" max="100" oninput="calculateRowTotal(${rowCount})">
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm text-end row-total bg-light" id="rowTotal${rowCount}" value="0.00" readonly>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm text-danger bg-transparent border-0" onclick="removeRow(${rowCount})">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            </tr>`;
            
            $('#itemsBody').append(row);
            $(`#row${rowCount} .product-select`).select2({ placeholder: "Search product...", width: '100%' });
            
            // Calculate initial row total
            calculateRowTotal(rowCount);
            
            // If data provided (edit mode), maybe ensure image matches exactly if logic differs?
            // The select map puts 'selected' on the correct option, but updateProductInfo isn't called automatically on load.
            // But we hardcoded the src above for initial load.
        };
        
        window.removeRow = function(id) {
            if($('#itemsBody tr').length > 1 || rowCount > 1) { // Allow removing even if 1 left, logic? No, keep at least 1 or allow 0?
                $(`#row${id}`).remove();
                calculateTotals();
            }
        };

        window.updateProductInfo = function(id) {
            const select = $(`#row${id} .product-select`);
            const option = select.find(':selected');
            const price = parseFloat(option.data('price')) || 0;
            const desc = option.data('desc') || '';
            const img = option.data('image');

            // Update Image
            const imgPath = img ? `/stock/uploads/products/${select.val()}/medium/${img}` : '/assets/images/placeholder.png';
            $(`#img${id}`).attr('src', imgPath);
            
            $(`#unitPrice${id}`).val(price.toFixed(2));
            $(`#desc${id}`).val(desc);
            calculateRowTotal(id);
        };
        
        window.calculateRowTotal = function(id) {
            const qty = parseFloat($(`#row${id} .quantity`).val()) || 0;
            const price = parseFloat($(`#unitPrice${id}`).val()) || 0;
            const disc = parseFloat($(`#row${id} .discount`).val()) || 0;
            
            let total = qty * price;
            total = total - (total * (disc / 100));
            
            $(`#rowTotal${id}`).val(total.toFixed(2));
            calculateTotals();
        };
        
        window.calculateTotals = function() {
            let subtotal = 0;
            $('.row-total').each(function() { subtotal += parseFloat($(this).val()) || 0; });
            
            $('#subtotal').val(subtotal.toFixed(2));
            $('#subtotalDisplay').text(subtotal.toFixed(2));
            
            const discountAmt = parseFloat($('#discountAmount').val()) || 0;
            const afterDisc = Math.max(0, subtotal - discountAmt);
            
            const taxPct = parseFloat($('#taxPercentage').val()) || 0;
            const taxAmt = afterDisc * (taxPct / 100);
            
            $('#taxAmount').val(taxAmt.toFixed(2));
            $('#taxDisplay').text(taxAmt.toFixed(2));
            
            const shipping = parseFloat($('#shippingCharges').val()) || 0;
            const grandTotal = afterDisc + taxAmt + shipping;
            
            $('#grandTotal').val(grandTotal.toFixed(2));
            $('#grandTotalDisplay').text(grandTotal.toFixed(2));
        };
    </script>
</body>
</html>
