<?php require_once '../../includes/functions.php';  global $pdo; $accounts = $pdo->query("SELECT * FROM erp_accounts ORDER BY code")->fetchAll(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Journal Entry - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; } .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 100%; padding: 24px; } .page-wrapper { margin-left: 220px; min-height: 100vh; } @media (max-width: 768px) { .page-wrapper { margin-left: 0; } } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; } .card-body { padding: 24px; } .form-group { margin-bottom: 16px; } label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; } input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; } .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; } .btn-primary { background: #1a73e8; color: white; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; } .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; display: none; } .alert-success { background: #e6f4ea; color: #137333; } .alert-error { background: #fce8e6; color: #c5221f; } .line-items { margin: 20px 0; } .line-item { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 12px; margin-bottom: 12px; align-items: end; } .totals { display: flex; justify-content: space-between; padding: 16px; background: #f8f9fa; border-radius: 4px; margin-top: 20px; font-weight: 600; }</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><a href="journal-entries.php" class="btn btn-secondary">Cancel</a></div>
    <div class="container"><div class="card"><div class="card-body"><div id="alertMessage" class="alert"></div>
    <form id="createJournalForm">
        <div class="form-group"><label>Date *</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div>
        <h3 style="margin: 20px 0 12px 0;">Line Items</h3>
        <div id="lineItems" class="line-items"></div>
        <button type="button" onclick="addLine()" class="btn btn-secondary">+ Add Line</button>
        <div class="totals"><div>Total Debit: <span id="totalDebit">0.00</span></div><div>Total Credit: <span id="totalCredit">0.00</span></div><div id="balanceStatus" style="color: #c5221f;">Not Balanced</div></div>
        <div style="margin-top: 24px; text-align: right;"><button type="submit" class="btn btn-primary" id="submitBtn" disabled>Save Journal Entry</button></div>
    </form></div></div></div>
    <script>
        const accounts = <?= json_encode($accounts) ?>;
        let lineCount = 0;
        function addLine() {
            const div = document.createElement('div');
            div.className = 'line-item';
            div.innerHTML = `
                <select name="items[${lineCount}][account_id]" required><option value="">Select Account</option>${accounts.map(a => `<option value="${a.id}">${a.code} - ${a.name}</option>`).join('')}</select>
                <input type="number" name="items[${lineCount}][debit]" step="0.01" placeholder="Debit" oninput="calculateTotals()">
                <input type="number" name="items[${lineCount}][credit]" step="0.01" placeholder="Credit" oninput="calculateTotals()">
                <button type="button" onclick="this.parentElement.remove(); calculateTotals();" class="btn btn-secondary" style="padding: 10px;">Ã—</button>
            `;
            document.getElementById('lineItems').appendChild(div);
            lineCount++;
        }
        function calculateTotals() {
            let totalDebit = 0, totalCredit = 0;
            document.querySelectorAll('.line-item').forEach(line => {
                totalDebit += parseFloat(line.querySelector('input[name*="[debit]"]').value || 0);
                totalCredit += parseFloat(line.querySelector('input[name*="[credit]"]').value || 0);
            });
            document.getElementById('totalDebit').textContent = totalDebit.toFixed(2);
            document.getElementById('totalCredit').textContent = totalCredit.toFixed(2);
            const balanced = Math.abs(totalDebit - totalCredit) < 0.01 && totalDebit > 0;
            document.getElementById('balanceStatus').textContent = balanced ? 'Balanced âœ“' : 'Not Balanced';
            document.getElementById('balanceStatus').style.color = balanced ? '#137333' : '#c5221f';
            document.getElementById('submitBtn').disabled = !balanced;
        }
        addLine(); addLine();
        document.getElementById('createJournalForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true; btn.textContent = 'Saving...';
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                const response = await fetch('../api/journal-entries.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Journal entry created successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'view-journal.php?id=' + result.id, 1500);
                } else { throw new Error(result.message || 'Failed to create journal entry'); }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false; btn.textContent = 'Save Journal Entry';
            }
        });
    </script>
</div>
</body>
</html>

