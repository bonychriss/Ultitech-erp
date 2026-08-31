<?php
require_once '../../includes/functions.php';
requireLogin();

// Generate next customer code
global $pdo;
$stmt = $pdo->query("SELECT customer_code FROM erp_customers ORDER BY id DESC LIMIT 1");
$lastCode = $stmt->fetchColumn();

if ($lastCode) {
    $num = intval(substr($lastCode, 5)) + 1;
    $nextCode = 'CUST-' . str_pad($num, 4, '0', STR_PAD_LEFT);
} else {
    $nextCode = 'CUST-0001';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Customer - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 800px; margin: 24px auto; padding: 0 24px; }
        
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-body { padding: 24px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full-width { grid-column: span 2; }
        
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; transition: border 0.2s; }
        input:focus, select:focus, textarea:focus { border-color: #1a73e8; outline: none; }
        
        .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; margin-right: 10px; }
        
        .section-title { font-size: 1rem; font-weight: 500; color: #202124; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid #f1f3f4; grid-column: span 2; margin-top: 8px; }
        
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; display: none; }
        .alert-success { background: #e6f4ea; color: #137333; }
        .alert-error { background: #fce8e6; color: #c5221f; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Add New Customer</h1>
        <a href="list.php" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-body">
                <div id="alertMessage" class="alert"></div>
                
                <form id="createCustomerForm">
                    <div class="form-grid">
                        <div class="section-title">Basic Information</div>
                        
                        <div class="form-group">
                            <label>Customer Code</label>
                            <input type="text" name="customer_code" value="<?= $nextCode ?>" readonly style="background: #f8f9fa;">
                        </div>
                        
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" required placeholder="Company or Person Name">
                        </div>
                        
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" placeholder="customer@example.com">
                        </div>
                        
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" placeholder="+255...">
                        </div>
                        
                        <div class="form-group">
                            <label>Tax ID / TIN</label>
                            <input type="text" name="tax_id" placeholder="Tax Identification Number">
                        </div>
                        
                        <div class="form-group">
                            <label>Credit Limit</label>
                            <input type="number" name="credit_limit" value="0" step="0.01">
                        </div>
                        
                        <div class="section-title">Address Details</div>
                        
                        <div class="form-group full-width">
                            <label>Street Address</label>
                            <textarea name="address" rows="2" placeholder="Street, Building, etc."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city" placeholder="Dar es Salaam">
                        </div>
                        
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="country" value="Tanzania">
                        </div>
                        
                        <div class="section-title">Additional Info</div>
                        
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" rows="3" placeholder="Internal notes about this customer..."></textarea>
                        </div>
                    </div>
                    
                    <div style="margin-top: 24px; text-align: right;">
                        <button type="submit" class="btn btn-primary">Save Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('createCustomerForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/customers.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Customer created successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'list.php', 1500);
                } else {
                    throw new Error(result.message || 'Failed to create customer');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
    </script>
</body>
</html>

