<?php
// Clear opcache
if (function_exists('opcache_reset')) { opcache_reset(); }

require_once '../../includes/functions.php';
requireLogin();
global $pdo;

if (!isset($_GET['id'])) die("Order ID required");
$id = $_GET['id'];

// Fetch Order Header
$stmt = $pdo->prepare("SELECT s.*, c.name as customer_name, c.email, c.phone, c.address 
                       FROM erp_sales_orders s 
                       JOIN erp_customers c ON s.customer_id = c.id 
                       WHERE s.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die("Order not found");

// Fetch Order Items
$stmt = $pdo->prepare("SELECT si.*, p.name as product_name, p.sku 
                       FROM erp_sales_order_items si 
                       JOIN erp_products p ON si.product_id = p.id 
                       WHERE si.order_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

// Fetch Related Deliveries
$stmt = $pdo->prepare("SELECT * FROM erp_delivery_notes WHERE order_id = ?");
$stmt->execute([$id]);
$deliveries = $stmt->fetchAll();

// Fetch Related Invoices (Simple check by matching common ref or just checking invoice status)
// For now relying on invoice_status column
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($order['order_number']) ?> - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        .page-wrapper { margin-left: 220px !important; padding: 30px; }
        
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .page-title { font-size: 1.8rem; font-weight: 700; color: #111827; margin-bottom: 4px; }
        .page-meta { color: #6b7280; font-size: 0.9rem; }
        
        .header-actions { display: flex; gap: 12px; }
        .btn { padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 0.95rem; cursor: pointer; border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .btn-primary { background: #2563eb; color: white; border-color: #2563eb; }
        .btn-secondary { background: white; color: #374151; border-color: #d1d5db; }
        .btn-success { background: #059669; color: white; border-color: #059669; }
        
        .card { background: white; border-radius: 12px; border: 1px solid #e5e7eb; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .section-title { font-size: 1.1rem; font-weight: 600; color: #111827; margin-bottom: 16px; border-bottom: 1px solid #f3f4f6; padding-bottom: 10px; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        .info-group { margin-bottom: 12px; }
        .info-label { font-size: 0.85rem; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }
        .info-value { font-size: 1rem; color: #111827; font-weight: 500; margin-top: 2px; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px; background: #f9fafb; font-weight: 600; color: #6b7280; border-bottom: 1px solid #e5e7eb; font-size: 0.9rem; }
        .table td { padding: 12px; border-bottom: 1px solid #f3f4f6; color: #374151; vertical-align: middle; }
        .table tr:last-child td { border-bottom: none; }
        
        .badge { display:inline-block; padding:4px 10px; border-radius:99px; font-size:0.75rem; font-weight:600; } 
        .badge-warning { background:#fef3c7; color:#d97706; } 
        .badge-success { background:#d1fae5; color:#059669; } 
        .badge-info { background:#dbeafe; color:#2563eb; }
        
        /* Modal */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; width: 500px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .modal-title { font-size: 1.25rem; font-weight: 600; margin-bottom: 16px; }
        .modal-footer { margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px; }
        
        .form-control { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; margin-bottom: 12px; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 0.9rem; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <div class="header">
        <div>
            <h1 class="page-title">Sales Order #<?= htmlspecialchars($order['order_number']) ?></h1>
            <div class="page-meta">
                Created on <?= date('M d, Y', strtotime($order['order_date'])) ?> • 
                <span class="badge badge-<?= $order['status'] === 'confirmed' ? 'success' : 'warning' ?>"><?= ucfirst($order['status']) ?></span>
            </div>
        </div>
        <div class="header-actions">
            <a href="sales-orders.php" class="btn btn-secondary">Back</a>
            
            <?php if ($order['invoice_status'] !== 'invoiced'): ?>
                <button onclick="createInvoice()" class="btn btn-secondary">
                    <i class="fas fa-file-invoice-dollar"></i> Create Invoice
                </button>
            <?php endif; ?>
            
            <?php if ($order['delivery_status'] !== 'delivered'): ?>
                <button onclick="openDeliveryModal()" class="btn btn-success">
                    <i class="fas fa-truck"></i> Create Delivery
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="grid-2">
            <div>
                <div class="section-title">Customer Details</div>
                <div class="info-group">
                    <div class="info-label">Name</div>
                    <div class="info-value"><?= htmlspecialchars($order['customer_name']) ?></div>
                </div>
                <div class="info-group">
                    <div class="info-label">Contact</div>
                    <div class="info-value"><?= htmlspecialchars($order['email']) ?> • <?= htmlspecialchars($order['phone']) ?></div>
                </div>
                <div class="info-group">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?= htmlspecialchars($order['address'] ?? '-') ?></div>
                </div>
            </div>
            <div>
                <div class="section-title">Order Info</div>
                <div class="info-group">
                    <div class="info-label">Delivery Date</div>
                    <div class="info-value"><?= $order['delivery_date'] ? date('M d, Y', strtotime($order['delivery_date'])) : 'Not Specified' ?></div>
                </div>
                <div class="info-group">
                    <div class="info-label">Delivery Status</div>
                    <div class="info-value">
                        <span class="badge badge-<?= $order['delivery_status'] === 'delivered' ? 'success' : 'warning' ?>">
                            <?= ucfirst($order['delivery_status']) ?>
                        </span>
                    </div>
                </div>
                 <div class="info-group">
                    <div class="info-label">Invoice Status</div>
                    <div class="info-value">
                        <?= ucwords(str_replace('_', ' ', $order['invoice_status'])) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="section-title">Order Items</div>
        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th style="text-align: right;">Quantity</th>
                    <th style="text-align: right;">Unit Price</th>
                    <th style="text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= htmlspecialchars($item['sku']) ?></td>
                        <td style="text-align: right;"><?= number_format($item['quantity'], 2) ?></td>
                        <td style="text-align: right;"><?= number_format($item['unit_price'], 2) ?></td>
                        <td style="text-align: right;"><?= number_format($item['total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="4" style="text-align: right; font-weight: 600; padding-top: 20px;">Subtotal:</td>
                    <td style="text-align: right; font-weight: 600; padding-top: 20px;"><?= number_format($order['subtotal'], 2) ?></td>
                </tr>
                <tr>
                    <td colspan="4" style="text-align: right; color: #6b7280;">Tax:</td>
                    <td style="text-align: right; color: #6b7280;"><?= number_format($order['tax_amount'], 2) ?></td>
                </tr>
                <tr>
                    <td colspan="4" style="text-align: right; font-size: 1.1rem; font-weight: 700;">Grand Total:</td>
                    <td style="text-align: right; font-size: 1.1rem; font-weight: 700; color: #2563eb;">TSh <?= number_format($order['total_amount'], 2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if (!empty($deliveries)): ?>
    <div class="card">
        <div class="section-title">Related Deliveries</div>
        <table class="table">
            <thead>
                <tr>
                    <th>DN #</th>
                    <th>Date</th>
                    <th>Driver</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <td><a href="delivery-notes.php" style="color:#2563eb; font-weight:500;"><?= htmlspecialchars($d['delivery_number']) ?></a></td>
                        <td><?= date('M d, Y', strtotime($d['delivery_date'])) ?></td>
                        <td><?= htmlspecialchars($d['driver_name']) ?></td>
                        <td><?= ucfirst($d['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<!-- Delivery Modal -->
<div id="deliveryModal" class="modal">
    <div class="modal-content">
        <div class="modal-title">Create Delivery Note</div>
        <form id="deliveryForm">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <input type="hidden" name="customer_id" value="<?= $order['customer_id'] ?>">
            
            <label class="form-label">Delivery Date</label>
            <input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            
            <label class="form-label">Driver Name (Optional)</label>
            <input type="text" name="driver_name" class="form-control" placeholder="e.g. John Doe">
            
            <label class="form-label">Vehicle Registration (Optional)</label>
            <input type="text" name="vehicle_reg" class="form-control" placeholder="e.g. T 123 ABC">
            
            <div style="max-height: 200px; overflow-y: auto; margin: 16px 0; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px;">
                <label class="form-label" style="margin-bottom: 8px;">Items to Deliver</label>
                <?php foreach ($items as $index => $item): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 0.9rem;"><?= htmlspecialchars($item['product_name']) ?></span>
                        <input type="hidden" name="items[<?= $index ?>][so_item_id]" value="<?= $item['id'] ?>">
                        <input type="hidden" name="items[<?= $index ?>][product_id]" value="<?= $item['product_id'] ?>">
                        <input type="number" name="items[<?= $index ?>][quantity]" value="<?= $item['quantity'] ?>" max="<?= $item['quantity'] ?>" min="0" step="0.01" style="width: 80px; padding: 4px;" class="form-control" style="margin: 0;">
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeliveryModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Delivery</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDeliveryModal() {
        document.getElementById('deliveryModal').classList.add('active');
    }
    
    function closeDeliveryModal() {
        document.getElementById('deliveryModal').classList.remove('active');
    }
    
    document.getElementById('deliveryForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        if (!confirm('Create delivery note and deduct stock?')) return;
        
        const btn = this.querySelector('button[type="submit"]');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.innerHTML = 'Creating...';
        
        try {
            const formData = new FormData(this);
            formData.append('action', 'create_from_so');
            
            const response = await fetch('../api/deliveries.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                alert('Delivery Note created successfully!');
                location.reload();
            } else {
                alert('Failed: ' + result.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        } catch (error) {
            alert('Error: ' + error.message);
            btn.disabled = false;
            btn.innerText = originalText;
        }
    });

    async function createInvoice() {
        if (!confirm('Generate Invoice for this order?')) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'convert_to_invoice');
            formData.append('id', <?= $id ?>);
            
            const response = await fetch('../api/sales_orders.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                window.location.href = '../sales/view-invoice.php?invoice_id=' + result.invoice_id; // Check actual invoice view path
            } else {
                alert('Failed: ' + result.message);
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }
</script>
</body>
</html>
