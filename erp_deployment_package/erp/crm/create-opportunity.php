<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;
$customers = $pdo->query("SELECT id, name FROM erp_customers ORDER BY name")->fetchAll();
$leads = $pdo->query("SELECT id, first_name, last_name, company FROM erp_leads WHERE status != 'converted' ORDER BY first_name")->fetchAll();
$users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Opportunity - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 800px; margin: 24px auto; padding: 0 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; padding: 24px; }
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
        <h1>New Opportunity</h1>
        <a href="opportunities.php" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div id="alertMessage" class="alert"></div>
            
            <form id="createOppForm">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Deal Name *</label>
                        <input type="text" name="name" placeholder="e.g. Hospital PPE Contract 2025" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Linked To (Customer)</label>
                        <select name="customer_id">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>OR Linked To (Lead)</label>
                        <select name="lead_id">
                            <option value="">Select Lead</option>
                            <?php foreach ($leads as $l): ?>
                                <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?> (<?= htmlspecialchars($l['company']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Estimated Amount</label>
                        <input type="number" name="amount" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Expected Close Date</label>
                        <input type="date" name="expected_close_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Stage</label>
                        <select name="stage" onchange="updateProb(this)">
                            <option value="new" data-prob="10">New (10%)</option>
                            <option value="qualified" data-prob="30">Qualified (30%)</option>
                            <option value="proposal" data-prob="60">Proposal (60%)</option>
                            <option value="negotiation" data-prob="80">Negotiation (80%)</option>
                            <option value="won" data-prob="100">Won (100%)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Probability (%)</label>
                        <input type="number" name="probability" id="probability" value="10" min="0" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label>Assigned To</label>
                        <select name="assigned_to">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 24px; text-align: right;">
                    <button type="submit" class="btn btn-primary">Create Deal</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function updateProb(select) {
            const prob = select.options[select.selectedIndex].getAttribute('data-prob');
            document.getElementById('probability').value = prob;
        }
        
        document.getElementById('createOppForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/opportunities.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Opportunity created successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'opportunities.php', 1500);
                } else {
                    throw new Error(result.message || 'Failed to create opportunity');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Create Deal';
            }
        });
    </script>
</body>
</html>

