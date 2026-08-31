<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

$accountId = $_GET['account_id'] ?? null;
if (!$accountId) {
    header('Location: bank-accounts.php');
    exit;
}

$account = $pdo->prepare("SELECT * FROM erp_bank_accounts WHERE id = ?");
$account->execute([$accountId]);
$account = $account->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Transaction - ERP</title>
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
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
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
        <h1>Add Bank Transaction</h1>
        <a href="bank-transactions.php?account_id=<?= $accountId ?>" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div id="alertMessage" class="alert"></div>
            
            <form id="addTransactionForm">
                <input type="hidden" name="bank_account_id" value="<?= $accountId ?>">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Transaction Date *</label>
                        <input type="date" name="transaction_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Reference</label>
                        <input type="text" name="reference" placeholder="e.g. CHQ123456">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Description *</label>
                        <input type="text" name="description" required placeholder="e.g. Payment to supplier">
                    </div>
                    
                    <div class="form-group">
                        <label>Debit (Money Out)</label>
                        <input type="number" name="debit" step="0.01" value="0.00" onchange="updateBalance()">
                    </div>
                    
                    <div class="form-group">
                        <label>Credit (Money In)</label>
                        <input type="number" name="credit" step="0.01" value="0.00" onchange="updateBalance()">
                    </div>
                    
                    <div class="form-group">
                        <label>Running Balance *</label>
                        <input type="number" name="balance" step="0.01" required value="<?= $account['current_balance'] ?>">
                    </div>
                </div>
                
                <div style="margin-top: 24px; text-align: right;">
                    <button type="submit" class="btn btn-primary">Add Transaction</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function updateBalance() {
            const debit = parseFloat(document.querySelector('[name="debit"]').value) || 0;
            const credit = parseFloat(document.querySelector('[name="credit"]').value) || 0;
            const currentBalance = <?= $account['current_balance'] ?>;
            const newBalance = currentBalance + credit - debit;
            document.querySelector('[name="balance"]').value = newBalance.toFixed(2);
        }
        
        document.getElementById('addTransactionForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'add_transaction');
                
                const response = await fetch('../api/bank-reconciliation.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Transaction added successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'bank-transactions.php?account_id=<?= $accountId ?>', 1500);
                } else {
                    throw new Error(result.message || 'Failed to add transaction');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Add Transaction';
            }
        });
    </script>
</body>
</html>

