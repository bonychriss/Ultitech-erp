<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;
$id = $_GET['id'] ?? 0;

// Get invoice details
$stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.address as customer_address, c.email as customer_email, c.phone as customer_phone, u.full_name as created_by_name 
                       FROM erp_invoices i 
                       JOIN erp_customers c ON i.customer_id = c.id 
                       LEFT JOIN users u ON i.created_by = u.id 
                       WHERE i.id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die("Invoice not found");
}

// Get invoice items
$stmt = $pdo->prepare("SELECT * FROM erp_invoice_items WHERE invoice_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #525659; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; padding: 20px; }
        
        .toolbar { background: #323639; color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 4px 4px 0 0; margin-bottom: 20px; max-width: 800px; margin: 0 auto 20px auto; }
        
        .invoice-container { background: white; max-width: 800px; margin: 0 auto; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); min-height: 1000px; }
        
        .invoice-header { display: flex; justify-content: space-between; margin-bottom: 40px; }
        .company-info h2 { color: #1a73e8; margin-bottom: 10px; }
        .invoice-title { text-align: right; }
        .invoice-title h1 { color: #5f6368; font-weight: 300; font-size: 2.5rem; margin-bottom: 10px; }
        
        .invoice-details { display: flex; justify-content: space-between; margin-bottom: 40px; }
        .bill-to { flex: 1; }
        .invoice-meta { text-align: right; }
        
        .table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .table th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; font-size: 0.875rem; text-transform: uppercase; }
        .table td { padding: 12px; border-bottom: 1px solid #f1f3f4; }
        .text-right { text-align: right; }
        
        .totals { display: flex; justify-content: flex-end; }
        .totals-table { width: 300px; }
        .totals-table td { padding: 8px 0; }
        .totals-table .final { font-weight: 600; font-size: 1.1rem; border-top: 2px solid #e0e0e0; padding-top: 16px; margin-top: 8px; }
        
        .notes { margin-top: 40px; padding-top: 20px; border-top: 1px solid #f1f3f4; color: #5f6368; font-size: 0.875rem; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; color: white; background: #1a73e8; }
        .btn-secondary { background: #5f6368; }
        
        @media print {
            body { background: white; padding: 0; }
            .toolbar { display: none; }
            .invoice-container { box-shadow: none; padding: 0; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <a href="invoices.php" style="color: white; text-decoration: none;">â† Back to Invoices</a>
        </div>
        <div>
            <button class="btn btn-secondary" onclick="window.print()">Print / Save PDF</button>
            <a href="create-delivery.php?invoice_id=<?= $invoice['id'] ?>" class="btn">Create Delivery Note</a>
        </div>
    </div>
    
    <div class="invoice-container">
        <div class="invoice-header">
            <div class="company-info">
                <h2><?= COMPANY_NAME ?></h2>
                <p>123 Business Street</p>
                <p>Dar es Salaam, Tanzania</p>
                <p>Phone: +255 123 456 789</p>
            </div>
            <div class="invoice-title">
                <h1>INVOICE</h1>
                <p>#<?= htmlspecialchars($invoice['invoice_number']) ?></p>
            </div>
        </div>
        
        <div class="invoice-details">
            <div class="bill-to">
                <h3 style="font-size: 0.875rem; color: #5f6368; text-transform: uppercase; margin-bottom: 8px;">Bill To:</h3>
                <p><strong><?= htmlspecialchars($invoice['customer_name']) ?></strong></p>
                <p><?= nl2br(htmlspecialchars($invoice['customer_address'] ?? '')) ?></p>
                <p><?= htmlspecialchars($invoice['customer_email'] ?? '') ?></p>
                <p><?= htmlspecialchars($invoice['customer_phone'] ?? '') ?></p>
            </div>
            <div class="invoice-meta">
                <p><strong>Date:</strong> <?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></p>
                <p><strong>Due Date:</strong> <?= $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : '-' ?></p>
                <p><strong>Status:</strong> <?= ucfirst($invoice['status']) ?></p>
            </div>
        </div>
        
        <table class="table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['description']) ?></td>
                        <td class="text-right"><?= floatval($item['quantity']) ?></td>
                        <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                        <td class="text-right"><?= number_format($item['total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="totals">
            <table class="totals-table">
                <tr>
                    <td>Subtotal:</td>
                    <td class="text-right"><?= number_format($invoice['subtotal'], 2) ?></td>
                </tr>
                <?php if ($invoice['tax_amount'] > 0): ?>
                <tr>
                    <td>Tax (<?= floatval($invoice['tax_rate']) ?>%):</td>
                    <td class="text-right"><?= number_format($invoice['tax_amount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="final">Total:</td>
                    <td class="final text-right">TSh <?= number_format($invoice['total'], 2) ?></td>
                </tr>
            </table>
        </div>
        
        <?php if (!empty($invoice['notes'])): ?>
        <div class="notes">
            <strong>Notes:</strong><br>
            <?= nl2br(htmlspecialchars($invoice['notes'])) ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

