<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;
$glAccounts = $pdo->query("SELECT * FROM erp_accounts WHERE type IN ('asset', 'liability') ORDER BY code")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Bank Account - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 800px; margin: 24px auto; padding: 0 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; padding: 24px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full-width { grid-column: span 2; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; display: none; }
        .alert-success { background: #e6f4ea; color: #137333; }
        .alert-error { background: #fce8e6; color: #c5221f; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Add Bank Account</h1>
        <a href="bank-accounts.php" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div id="alertMessage" class="alert"></div>
            
            <form id="createAccountForm">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Account Name *</label>
                        <input type="text" name="account_name" required placeholder="e.g. CRDB Main Account">
                    </div>
                    
                    <div class="form-group">
                        <label>Bank Name *</label>
                        <input type="text" name="bank_name" required placeholder="e.g. CRDB Bank">
                    </div>
                    
                    <div class="form-group">
                        <label>Account Number</label>
                        <input type="text" name="account_number" placeholder="01J1234567890">
                    </div>
                    
                    <div class="form-group">
                        <label>Branch</label>
                        <input type="text" name="branch" placeholder="e.g. Dar es Salaam">
                    </div>
                    
                    <div class="form-group">
                        <label>Currency</label>
                        <select name="currency">
                            <option value="TSh">TSh (Tanzanian Shilling)</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Opening Balance</label>
                        <input type="number" name="opening_balance" step="0.01" value="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label>Link to GL Account (Optional)</label>
                        <select name="gl_account_id">
                            <option value="">None</option>
                            <?php foreach ($glAccounts as $gl): ?>
                                <option value="<?= $gl['id'] ?>"><?= htmlspecialchars($gl['code'] . ' - ' . $gl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 24px; text-align: right;">
                    <button type="submit" class="btn btn-primary">Create Bank Account</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.getElementById('createAccountForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create_account');
                
                const response = await fetch('../api/bank-reconciliation.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Bank account created successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'bank-accounts.php', 1500);
                } else {
                    throw new Error(result.message || 'Failed to create bank account');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Create Bank Account';
            }
        });
    </script>
</body>
</html>

