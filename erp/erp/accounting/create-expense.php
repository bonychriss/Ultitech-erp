<?php require_once '../../includes/functions.php';  global $pdo; $accounts = $pdo->query("SELECT * FROM erp_accounts WHERE type = 'expense' ORDER BY name")->fetchAll(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Record Expense - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; } .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 100%; padding: 24px; } .page-wrapper { margin-left: 220px; min-height: 100vh; } @media (max-width: 768px) { .page-wrapper { margin-left: 0; } } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; } .card-body { padding: 24px; } .form-group { margin-bottom: 16px; } label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; } input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; } .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; } .btn-primary { background: #1a73e8; color: white; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; } .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; display: none; } .alert-success { background: #e6f4ea; color: #137333; } .alert-error { background: #fce8e6; color: #c5221f; }</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><a href="expenses.php" class="btn btn-secondary">Cancel</a></div>
    <div class="container"><div class="card"><div class="card-body"><div id="alertMessage" class="alert"></div>
    <form id="createExpenseForm">
        <div class="form-group"><label>Date *</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="form-group"><label>Payee</label><input type="text" name="payee" placeholder="Who was paid?"></div>
        <div class="form-group"><label>Expense Category *</label><select name="account_id" required><option value="">Select Category</option><?php foreach ($accounts as $acc): ?><option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Amount *</label><input type="number" name="amount" step="0.01" required placeholder="0.00"></div>
        <div class="form-group"><label>Payment Method</label><select name="payment_method"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="cheque">Cheque</option></select></div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="3"></textarea></div>
        <div style="margin-top: 24px; text-align: right;"><button type="submit" class="btn btn-primary">Record Expense</button></div>
    </form></div></div></div>
    <script>
        document.getElementById('createExpenseForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true; btn.textContent = 'Saving...';
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                const response = await fetch('../api/expenses.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Expense recorded successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'expenses.php', 1500);
                } else { throw new Error(result.message || 'Failed to record expense'); }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false; btn.textContent = 'Record Expense';
            }
        });
    </script>
</div>
</body>
</html>

