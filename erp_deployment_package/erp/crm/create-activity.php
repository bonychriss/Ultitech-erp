<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;
$leads = $pdo->query("SELECT id, first_name, last_name, company FROM erp_leads WHERE status != 'converted' ORDER BY first_name")->fetchAll();
$opportunities = $pdo->query("SELECT id, name FROM erp_opportunities WHERE stage != 'won' AND stage != 'lost' ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT id, name FROM erp_customers ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Activity - ERP</title>
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
        <h1>New Activity</h1>
        <a href="activities.php" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div id="alertMessage" class="alert"></div>
            
            <form id="createActForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Type *</label>
                        <select name="type" required>
                            <option value="call">Call</option>
                            <option value="email">Email</option>
                            <option value="meeting">Meeting</option>
                            <option value="task">Task</option>
                            <option value="note">Note</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="datetime-local" name="due_date">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Subject *</label>
                        <input type="text" name="subject" required placeholder="e.g. Follow up on proposal">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Related To (Lead)</label>
                        <select name="lead_id">
                            <option value="">Select Lead</option>
                            <?php foreach ($leads as $l): ?>
                                <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Related To (Opportunity)</label>
                        <select name="opportunity_id">
                            <option value="">Select Deal</option>
                            <?php foreach ($opportunities as $o): ?>
                                <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 24px; text-align: right;">
                    <button type="submit" class="btn btn-primary">Create Task</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.getElementById('createActForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/crm-activities.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Activity created successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'activities.php', 1500);
                } else {
                    throw new Error(result.message || 'Failed to create activity');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Create Task';
            }
        });
    </script>
</body>
</html>

