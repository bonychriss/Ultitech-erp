
<?php
require_once '../../includes/functions.php';

global $pdo;
$id = $_GET['id'] ?? 0;

// Get invoice details
$stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.address as customer_address, c.email as customer_email, c.phone as customer_phone, c.tax_id as customer_tax_id, u.full_name as created_by_name 
                       FROM erp_invoices i 
                       JOIN erp_customers c ON i.customer_id = c.id 
                       LEFT JOIN users u ON i.created_by = u.id 
                       WHERE i.id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) die("Invoice not found");

// Fetch Bank Accounts for display
$bankAccounts = $pdo->query("SELECT * FROM erp_bank_accounts WHERE status = 'active'")->fetchAll();

// Get items
$stmt = $pdo->prepare("SELECT ii.*, p.name as product_name, p.description as product_desc FROM erp_invoice_items ii LEFT JOIN erp_products p ON ii.product_id = p.id WHERE ii.invoice_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --odoo-brand: #714B67;
            --odoo-brand-dark: #5b3c53;
            --odoo-action: #008784;
            --odoo-gray: #f9f9f9;
            --odoo-border: #dee2e6;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #374151; }
        
        .page-wrapper { margin-left: 220px; display: flex; flex-direction: column; min-height: 100vh; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0; } }

        /* Control Panel */
        .control-panel { background: white; border-bottom: 1px solid var(--odoo-border); padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; }
        .breadcrumb { font-size: 0.9rem; color: #6c757d; display: flex; align-items: center; gap: 8px; }
        .breadcrumb a { color: #4b5563; text-decoration: none; }
        .breadcrumb a:hover { color: var(--odoo-brand); }
        .breadcrumb .active { color: #111827; font-weight: 500; }

        /* Action Bar */
        .action-bar { background: white; border-bottom: 1px solid var(--odoo-border); padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .btn-group { display: flex; gap: 6px; }
        .btn { padding: 6px 12px; border-radius: 4px; font-size: 0.85rem; font-weight: 500; cursor: pointer; text-transform: uppercase; transition: all 0.2s; border: 1px solid transparent; }
        .btn-primary { background: var(--odoo-brand); color: white; border-color: var(--odoo-brand); }
        .btn-secondary { background: white; color: #374151; border-color: #d1d5db; }
        .btn-action { background: var(--odoo-action); color: white; border-color: var(--odoo-action); }

        /* Pipeline */
        .pipeline-widget { display: flex; border: 1px solid #ccc; border-radius: 3px; }
        .pipeline-item { padding: 6px 16px; font-size: 13px; font-weight: 600; color: #666; border-right: 1px solid #ccc; background: #f8f9fa; cursor: default; }
        .pipeline-item:last-child { border-right: none; }
        .pipeline-item.active { background: var(--odoo-action); color: white; }

        /* Sheet */
        .sheet-container { max-width: 960px; margin: 24px auto; width: 100%; padding: 0 16px; }
        .sheet { background: white; border: 1px solid #d1d5db; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 4px; min-height: 600px; padding: 32px 40px; position: relative; }
        .sheet-title { font-size: 2rem; font-weight: 400; color: var(--odoo-brand); marginBottom: 24px; }
        
        /* Grid & Tables */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 32px; }
        .form-group { margin-bottom: 12px; display: flex; }
        .form-label { flex: 0 0 100px; font-size: 0.9rem; font-weight: 600; color: #374151; }
        .form-value { font-size: 0.95rem; color: #111827; }
        
        .o-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .o-table th { text-align: left; padding: 8px; border-bottom: 2px solid #e5e7eb; font-size: 0.85rem; color: #4b5563; }
        .o-table td { padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 0.9rem; }
        .o-table .num { text-align: right; }
        
        .totals-area { display: flex; justify-content: flex-end; margin-top: 16px; }
        .totals-table td { padding: 4px 0; text-align: right; font-size: 0.9rem; }
        .grand-total { font-weight: 700; font-size: 1.1rem; color: #111827; border-top: 1px solid #000; padding-top: 8px !important; }
        
        /* Ribbon for Paid */
        .ribbon { position: absolute; right: 0; top: 0; width: 150px; height: 150px; overflow: hidden; pointer-events: none; }
        .ribbon span { position: absolute; display: block; width: 225px; padding: 10px 0; background-color: #28a745; box-shadow: 0 5px 10px rgba(0,0,0,.1); color: #fff; font: 700 18px/1 'Lato', sans-serif; text-shadow: 0 1px 1px rgba(0,0,0,.2); text-transform: uppercase; text-align: center; right: -55px; top: 30px; transform: rotate(45deg); }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<main class="page-wrapper">
    <!-- 1. Control Panel -->
    <div class="control-panel">
        <div class="breadcrumb">
            <a href="../index.php">Dashboard</a>
            <span class="sep">/</span>
            <a href="invoices.php">Invoices</a>
            <span class="sep">/</span>
            <span class="active"><?= htmlspecialchars($invoice['invoice_number']) ?></span>
        </div>
        <div class="search-box">
           <!-- Placeholder for search -->
        </div>
    </div>

    <!-- 2. Action Bar with Status Pipeline -->
    <div class="action-bar">
        <div class="btn-group">
            <?php if ($invoice['status'] === 'draft'): ?>
                <button class="btn btn-action" onclick="sendEmailInvoice(<?= $id ?>)">Send by Email</button>
                <button class="btn btn-primary" onclick="updateStatus(<?= $id ?>, 'posted')">Post</button>
            <?php elseif ($invoice['status'] === 'unpaid' || $invoice['status'] === 'posted'): ?>
                <button class="btn btn-primary" onclick="registerPayment(<?= $id ?>)">Register Payment</button>
            <?php endif; ?>
            
            <button class="btn btn-secondary" onclick="window.print()">Print</button>
        </div>

        <!-- Status Pipeline -->
        <div class="pipeline-widget">
            <?php 
                $stages = ['draft' => 'Draft', 'posted' => 'Posted', 'paid' => 'Paid'];
                // Simplified status mapping (unpaid -> posted)
                $currentStatus = ($invoice['status'] === 'unpaid') ? 'posted' : $invoice['status'];
                
                foreach($stages as $key => $label): 
                    $activeClass = ($currentStatus === $key) ? 'active' : '';
            ?>
            <div class="pipeline-item <?= $activeClass ?>">
                <?= $label ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 3. The Sheet -->
    <div class="sheet-container">
        <div class="sheet">
            <!-- Paid Ribbon -->
            <?php if ($currentStatus === 'paid'): ?>
            <div class="ribbon"><span>PAID</span></div>
            <?php endif; ?>

            <div class="sheet-header-title" style="display: flex; justify-content: space-between; align-items: flex-start;">
                <h1 class="sheet-title" style="margin: 0; color: #000; font-size: 2.5rem;">Invoice # <?= htmlspecialchars($invoice['invoice_number']) ?></h1>
                <div style="text-align: right;">
                    <img src="../../assets/images/Untitled.jpg" alt="Logo" style="height: 60px; margin-bottom: 10px;">
                    <div style="font-weight: bold; font-size: 1rem; color: #111; text-transform: uppercase;">Ultimate General Trading Company</div>
                    <div style="font-size: 0.9rem; color: #333;">Mikocheni B, Dar es salaam Tanzania</div>
                    <div style="font-size: 0.9rem; color: #333;">P.O.BOX 7800</div>
                </div>
            </div>

            <!-- Customer & Date Grid -->
             <!-- Added Sharp Gray Bar for Date/Salesperson to match image style -->
             <!-- Moved Date/Expiry Bar Below -->

            <div class="form-grid" style="margin-bottom: 20px;">
                <div class="left-col" style="width: 100%;">
                    <div class="form-group">
                        <!-- Removed 'Customer' label -->
                        <div class="form-value" style="font-size: 1rem; line-height: 1.6;">
                            <strong style="font-size: 1.1rem; color: #111;"><?= htmlspecialchars($invoice['customer_name']) ?></strong><br>
                            <?php if(!empty($invoice['customer_address'])): ?>
                                <span style="color: #555;"><?= nl2br(htmlspecialchars($invoice['customer_address'])) ?></span><br>
                            <?php endif; ?>
                            <?php if(!empty($invoice['customer_email'])): ?>
                                <span style="color: #666;"><?= htmlspecialchars($invoice['customer_email']) ?></span><br>
                            <?php endif; ?>
                            <?php if(!empty($invoice['customer_tax_id'])): ?>
                                <span style="color: #666;">Tax ID: <?= htmlspecialchars($invoice['customer_tax_id']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Right column removed as requested -->
            </div>

            <!-- Date/Expiry Bar Moved Here -->
            <div style="margin-top: 10px; margin-bottom: 30px; border: 1px solid #000; display: flex; text-align: left;">
                <div style="flex: 1; padding: 2px;">
                    <div style="font-weight: bold; font-size: 0.8rem;">Invoice Date</div>
                    <div style="font-size: 0.85rem;"><?= date('d/m/Y', strtotime($invoice['invoice_date'])) ?></div>
                </div>
                <div style="flex: 1; padding: 2px;">
                    <div style="font-weight: bold; font-size: 0.8rem;">Due Date</div>
                    <div style="font-size: 0.85rem;"><?= $invoice['due_date'] ? date('d/m/Y', strtotime($invoice['due_date'])) : '-' ?></div>
                </div>
                <div style="flex: 1; padding: 2px;">
                    <div style="font-weight: bold; font-size: 0.8rem;">Salesperson</div>
                    <div style="font-size: 0.85rem;">Ultimate General Trading</div>
                </div>
            </div>

            <!-- Order Lines -->
            <div class="notebook">
                 <!-- Header removed -->
                 <table class="o-table">
                     <thead>
                         <tr>
                             <th>Product</th>
                             <th>Description</th>
                             <th class="num">Quantity</th>
                             <th class="num">Unit Price</th>
                             <th class="num">Subtotal</th>
                         </tr>
                     </thead>
                     <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['product_id'] ?? 'Product') ?></td>
                            <td><?= htmlspecialchars($item['description']) ?></td>
                            <td class="num"><?= $item['quantity'] ?></td>
                            <td class="num"><?= number_format($item['unit_price'], 2) ?></td>
                            <td class="num"><?= number_format($item['total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                     </tbody>
                 </table>

                 <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                    <!-- Bank Details (Left) -->
                    <div style="flex: 1; padding-right: 20px;">
                        <?php if (!empty($bankAccounts)): ?>
                            <h4 style="font-size: 0.95rem; font-weight: 600; color: #111; margin-bottom: 8px; text-decoration: underline;">Bank Details</h4>
                            <div style="font-size: 0.85rem; color: #333; line-height: 1.5;">
                                <?php foreach ($bankAccounts as $acc): ?>
                                    <div style="margin-bottom: 8px;">
                                        <strong><?= htmlspecialchars($acc['bank_name']) ?></strong><br>
                                        Account Name: <?= htmlspecialchars($acc['account_name']) ?><br>
                                        Account No: <?= htmlspecialchars($acc['account_number']) ?><br>
                                        Branch: <?= htmlspecialchars($acc['branch']) ?> | Currency: <?= htmlspecialchars($acc['currency']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Totals (Right) -->
                     <div class="totals-area" style="width: 300px;">
                         <table class="totals-table">
                             <tr>
                                 <td>Untaxed Amount:</td>
                                 <td>TSh <?= number_format($invoice['subtotal'], 2) ?></td>
                             </tr>
                             <tr>
                                 <td>Taxes:</td>
                                 <td>TSh <?= number_format($invoice['tax_amount'], 2) ?></td>
                             </tr>
                             <tr>
                                 <td class="grand-total">Total:</td>
                                 <td class="grand-total">TSh <?= number_format($invoice['total'], 2) ?></td>
                             </tr>
                             <tr>
                                 <td style="padding-top: 8px;">Amount Due:</td>
                                 <td style="padding-top: 8px;">TSh <?= number_format($invoice['balance'], 2) ?></td>
                             </tr>
                         </table>
                     </div>
                 </div>
                 
                 <!-- Internal Notes -->
                 <?php if ($invoice['notes']): ?>
                 <div class="chatter">
                    <p class="form-label" style="margin-bottom:8px;">Terms & Notes</p>
                    <div class="note-box"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></div>
                 </div>
                 <?php endif; ?>

                 <!-- Activity Log removed as requested -->
            </div>
        </div>
    </div>
</main>

<script>
async function updateStatus(id, status) {
    if(!confirm('Are you sure you want to ' + (status === 'posted' ? 'post' : 'update') + ' this invoice?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('id', id);
        formData.append('status', status);
        
        const response = await fetch('../api/invoices.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            window.location.reload();
        } else {
            alert('Failed: ' + result.message);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function registerPayment(id) { 
    if (!confirm('Register full payment for this invoice?')) return; 
    try { 
        const formData = new FormData(); 
        formData.append('action', 'register_payment'); 
        formData.append('id', id); 
        const response = await fetch('../api/invoices.php', { method: 'POST', body: formData }); 
        const result = await response.json(); 
        if (result.success) { 
            alert('Payment Registered!'); 
            window.location.reload(); 
        } else { 
            alert('Failed: ' + result.message); 
        } 
    } catch (error) { 
        alert('Error: ' + error.message); 
    } 
}

async function sendEmailInvoice(id) {
    if (!confirm('Send this invoice by email?')) return;
    
    // Show loading state
    const btn = document.querySelector('.btn-action');
    const originalText = btn.innerText;
    btn.innerText = 'Sending...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_email');
        formData.append('id', id);
        
        const response = await fetch('../api/invoices.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            alert('Email sent successfully!');
            window.location.reload();
        } else {
            alert('Failed: ' + result.message);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    } finally {
        btn.innerText = originalText;
        btn.disabled = false;
    }
}
</script>
</body>
</html>
