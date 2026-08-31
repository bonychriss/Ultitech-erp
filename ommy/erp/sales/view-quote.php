<?php require_once '../../includes/functions.php';  global $pdo; $id = $_GET['id'] ?? 0; $stmt = $pdo->prepare("SELECT q.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone, c.address as customer_address, c.tax_id as customer_tax_id FROM erp_quotes q JOIN erp_customers c ON q.customer_id = c.id WHERE q.id = ?"); $stmt->execute([$id]); $quote = $stmt->fetch(); if (!$quote) die("Quote not found"); $items = $pdo->prepare("SELECT qi.*, p.name as product_name FROM erp_quote_items qi JOIN erp_products p ON qi.product_id = p.id WHERE qi.quote_id = ?"); $items->execute([$id]); $items = $items->fetchAll();
// Fetch Bank Accounts for display
$bankAccounts = $pdo->query("SELECT * FROM erp_bank_accounts WHERE status = 'active'")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quote <?= htmlspecialchars($quote['quote_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Odoo-style CSS Variables */
        :root {
            --odoo-brand: #714B67;
            --odoo-brand-dark: #5b3c53;
            --odoo-action: #008784; /* Teal for primary actions */
            --odoo-gray: #f9f9f9;
            --odoo-border: #dee2e6;
            --status-draft: #e9ecef;
            --status-sent: #fff3cd;
            --status-sale: #d1e7dd;
            --status-done: #d1e7dd;
            --status-cancel: #f8d7da;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #374151; }
        
        /* Layout Framework */
        .page-wrapper {
            margin-left: 220px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0; } }

        /* Control Panel (Breadcrumbs + Search) */
        .control-panel {
            background: white;
            border-bottom: 1px solid var(--odoo-border);
            padding: 10px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .breadcrumb { font-size: 0.9rem; color: #6c757d; display: flex; align-items: center; gap: 8px; }
        .breadcrumb a { color: #4b5563; text-decoration: none; }
        .breadcrumb a:hover { color: var(--odoo-brand); }
        .breadcrumb .active { color: #111827; font-weight: 500; }

        /* Action Bar (Buttons + Status Pipeline) */
        .action-bar {
            background: white;
            border-bottom: 1px solid var(--odoo-border);
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .btn-group { display: flex; gap: 6px; }
        .btn { 
            padding: 6px 12px; 
            border-radius: 4px; 
            font-size: 0.85rem; 
            font-weight: 500; 
            cursor: pointer; 
            text-transform: uppercase; 
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .btn-primary { background: var(--odoo-brand); color: white; border-color: var(--odoo-brand); }
        .btn-primary:hover { background: var(--odoo-brand-dark); }
        
        .btn-secondary { background: white; color: #374151; border-color: #d1d5db; }
        .btn-secondary:hover { background: #f3f4f6; }

        .btn-action { background: var(--odoo-action); color: white; border-color: var(--odoo-action); }
        .btn-action:hover { opacity: 0.9; }

        /* Odoo-style Status Pipeline */
        .status-pipeline {
            display: flex;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            overflow: hidden;
            height: 32px;
        }
        .status-step {
            padding: 0 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #6b7280;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            position: relative;
            border-right: 1px solid white;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 50%, calc(100% - 10px) 100%, 0 100%);
            margin-right: -10px; /* Overlap for arrow effect */
            padding-right: 20px; /* Space for the arrow tip */
            z-index: 1;
        }
        
        /* First item needs straight left edge */
        .status-step:first-child { clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 50%, calc(100% - 10px) 100%, 0 100%); }
        /* Last item needs straight right edge - wait, Odoo uses arrows for all but last usually */
        
        /* Fix for CSS clip-path overlapping: simplistic approach using extensive borders is harder, 
           so we use a cleaner background color switch mechanism simpler than full arrows if CSS is tricky without images/complex pseudo.
           Let's use a simpler visual style closer to Odoo 14+ specific pipeline.
        */
        
        .pipeline-widget {
             display: flex;
             border: 1px solid #ccc;
             border-radius: 3px;
        }
        .pipeline-item {
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            border-right: 1px solid #ccc;
            background: #f8f9fa;
            position: relative;
            cursor: default;
        }
        .pipeline-item:last-child { border-right: none; }
        .pipeline-item.active {
            background: var(--odoo-action); /* Active Status Color */
            color: white;
        }
        .pipeline-item.completed {
            color: var(--odoo-action);
            background: white;
            border-top: 3px solid var(--odoo-action);
            padding-top: 3px; /* Adjust for border */
        }


        /* The Sheet (Paper) */
        .sheet-container {
            max-width: 960px;
            margin: 24px auto;
            width: 100%;
            padding: 0 16px;
        }
        .sheet {
            background: white;
            border: 1px solid #d1d5db;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-radius: 4px;
            min-height: 600px;
            padding: 32px 40px;
            position: relative;
        }
        
        .sheet-header-title {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .sheet-title { font-size: 2rem; font-weight: 400; color: var(--odoo-brand); }
        
        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 32px;
        }
        .form-group { margin-bottom: 12px; display: flex; }
        .form-label { flex: 0 0 100px; font-size: 0.9rem; font-weight: 600; color: #374151; padding-top: 2px; }
        .form-value { font-size: 0.95rem; color: #111827; }
        .form-value a { color: var(--odoo-brand); text-decoration: none; }

        /* Tables */
        .o-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .o-table th { text-align: left; padding: 8px; border-bottom: 2px solid #e5e7eb;font-size: 0.85rem; color: #4b5563; }
        .o-table td { padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 0.9rem; }
        .o-table .num { text-align: right; }
        
        .totals-area {
            display: flex;
            justify-content: flex-end;
            margin-top: 16px;
        }
        .totals-table { width: 300px; }
        .totals-table td { padding: 4px 0; text-align: right; font-size: 0.9rem; }
        .grand-total { font-weight: 700; font-size: 1.1rem; color: #111827; border-top: 1px solid #000; padding-top: 8px !important; }

        /* Chatter / Notes */
        .chatter { border-top: 1px solid #e5e7eb; margin-top: 32px; padding-top: 16px; }
        .note-box { background: #fff3cd; border: 1px solid #ffeeba; padding: 12px; border-radius: 4px; font-size: 0.9rem; color: #856404; }

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
            <a href="quotes.php">Quotations</a>
            <span class="sep">/</span>
            <span class="active"><?= htmlspecialchars($quote['quote_number']) ?></span>
        </div>
        <div class="search-box">
           <!-- Placeholder for search -->
        </div>
    </div>

    <!-- 2. Action Bar with Status Pipeline -->
    <div class="action-bar">
        <div class="btn-group">
            <!-- Logic for Buttons based on Status -->
            <?php if ($quote['status'] === 'draft'): ?>
                <button onclick="sendEmailQuote(<?= $id ?>)" class="btn btn-action">Send by Email</button>
                <button onclick="updateStatus(<?= $id ?>, 'accepted')" class="btn btn-primary">Confirm</button>
            <?php elseif ($quote['status'] === 'sent'): ?>
                <button onclick="updateStatus(<?= $id ?>, 'accepted')" class="btn btn-primary">Confirm Sale</button>
                <button class="btn btn-secondary">Resend Email</button>
            <?php elseif ($quote['status'] === 'accepted'): ?>
                <button onclick="convertToInvoice(<?= $id ?>)" class="btn btn-primary">Create Invoice</button>
                <button class="btn btn-secondary" onclick="updateStatus(<?= $id ?>, 'sent')">Set to Quotation</button>
            <?php endif; ?>
            
            <button class="btn btn-secondary" onclick="window.print()">Print</button>
            <button class="btn btn-secondary" onclick="alert('TODO: Cancel Implementation')">Cancel</button>
        </div>

        <!-- Status Pipeline -->
        <div class="pipeline-widget">
            <?php 
                $stages = ['draft' => 'Quotation', 'sent' => 'Quotation Sent', 'accepted' => 'Sales Order', 'converted' => 'Invoiced'];
                $currentFound = false;
                foreach($stages as $key => $label): 
                    $activeClass = ($quote['status'] === $key) ? 'active' : '';
                    // Simple logic: if we passed the status, it's 'completed' (in a real advanced logic we'd check timestamps)
                    // For now, let's just highlight the CURRENT one.
                    
                    // Actually, Odoo highlights current. Previous are clickable usually.
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
            <div class="sheet-header-title" style="display: flex; justify-content: space-between; align-items: flex-start;">
                <h1 class="sheet-title" style="margin: 0; color: #000; font-size: 2.5rem;">Quotation # <?= htmlspecialchars($quote['quote_number']) ?></h1>
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
                    <!-- Customer Details Only -->

                    <div class="form-group">
                        <!-- Removed 'Customer' label -->
                        <div class="form-value" style="font-size: 1rem; line-height: 1.6;">
                            <strong style="font-size: 1.1rem; color: #111;"><?= htmlspecialchars($quote['customer_name']) ?></strong><br>
                            <?php if(!empty($quote['customer_address'])): ?>
                                <span style="color: #555;"><?= nl2br(htmlspecialchars($quote['customer_address'])) ?></span><br>
                            <?php endif; ?>
                            <?php if(!empty($quote['customer_email'])): ?>
                                <span style="color: #666;"><?= htmlspecialchars($quote['customer_email']) ?></span><br>
                            <?php endif; ?>
                            <?php if(!empty($quote['customer_tax_id'])): ?>
                                <span style="color: #666;">Tax ID: <?= htmlspecialchars($quote['customer_tax_id']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Right column removed as requested -->
            </div>

            <!-- Date/Expiry Bar Moved Here -->
             <div style="margin-top: 10px; margin-bottom: 30px; border: 1px solid #000; display: flex; text-align: left;">
                <div style="flex: 1; padding: 2px;">
                    <div style="font-weight: bold; font-size: 0.8rem;">Quotation Date</div>
                    <div style="font-size: 0.85rem;"><?= date('d/m/Y', strtotime($quote['date'])) ?></div>
                </div>
                <div style="flex: 1; padding: 2px;">
                    <div style="font-weight: bold; font-size: 0.8rem;">Expiration</div>
                    <div style="font-size: 0.85rem;"><?= $quote['expiry_date'] ? date('d/m/Y', strtotime($quote['expiry_date'])) : '-' ?></div>
                </div>
                <div style="flex: 1; padding: 2px;">
                    <div style="font-weight: bold; font-size: 0.8rem;">Salesperson</div>
                    <div style="font-size: 0.85rem;">Ultimate General Trading</div>
                </div>
            </div>

            <!-- Order Lines -->
            <div class="notebook">
                 <!-- Tabs could go here -->
                  <!-- Tabs could go here -->
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
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= htmlspecialchars($item['description'] ?? $item['product_name']) ?></td>
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
                                 <td>TSh <?= number_format($quote['subtotal'], 2) ?></td>
                             </tr>
                             <tr>
                                 <td>Taxes:</td>
                                 <td>TSh <?= number_format($quote['tax_amount'], 2) ?></td>
                             </tr>
                             <tr>
                                 <td class="grand-total">Total:</td>
                                 <td class="grand-total">TSh <?= number_format($quote['total_amount'], 2) ?></td>
                             </tr>
                         </table>
                     </div>
                 </div>
                 
                 <!-- Internal Notes -->
                 <?php if ($quote['notes']): ?>
                 <div class="chatter">
                    <p class="form-label" style="margin-bottom:8px;">Terms & Conditions / Notes</p>
                    <div class="note-box"><?= nl2br(htmlspecialchars($quote['notes'])) ?></div>
                 </div>
                 <?php endif; ?>

                 <!-- Activity Log removed as requested -->
            </div>
        </div>
    </div>
</main>

<script>
async function updateStatus(id, status) {
    if(!confirm('Change status to ' + status + '?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('id', id);
        formData.append('status', status);
        
        const response = await fetch('../api/quotes.php', { method: 'POST', body: formData });
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

async function convertToInvoice(id) { 
    if (!confirm('Confirm Sale and Create Invoice?')) return; 
    try { 
        const formData = new FormData(); 
        formData.append('action', 'convert_to_invoice'); 
        formData.append('id', id); 
        const response = await fetch('../api/quotes.php', { method: 'POST', body: formData }); 
        const result = await response.json(); 
        if (result.success) { 
            alert('Order Confirmed! Invoice Created.'); 
            window.location.href = 'view-invoice.php?id=' + result.invoice_id; 
        } else { 
            alert('Failed: ' + result.message); 
        } 
    } catch (error) { 
        alert('Error: ' + error.message); 
    } 
}

async function sendEmailQuote(id) {
    if (!confirm('Send this quotation by email?')) return;
    
    // Show loading state
    const btn = document.querySelector('.btn-action');
    const originalText = btn.innerText;
    btn.innerText = 'Sending...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_email');
        formData.append('id', id);
        
        const response = await fetch('../api/quotes.php', { method: 'POST', body: formData });
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

