<?php
require_once '../../includes/functions.php';

global $pdo;

// Create tax rates table
$pdo->exec("CREATE TABLE IF NOT EXISTS `erp_tax_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `rate` decimal(5,2) NOT NULL,
  `type` enum('percentage','fixed') DEFAULT 'percentage',
  `is_default` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$taxRates = $pdo->query("SELECT * FROM erp_tax_rates ORDER BY is_default DESC, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tax Rates - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1000px; margin: 0 auto; padding: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 24px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; }
        .table td { padding: 12px 16px; border-bottom: 1px solid #f1f3f4; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-default { background: #e8f0fe; color: #1967d2; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { background: white; width: 500px; margin: 100px auto; border-radius: 8px; padding: 24px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 0.875rem; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div class="header">
        <h1><i class="fas fa-dollar-sign"></i> Tax Rates</h1>
        <div>
            <a href="index.php" class="btn btn-secondary">â† Back</a>
            <button onclick="openModal()" class="btn btn-primary">+ Add Tax Rate</button>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Rate</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($taxRates)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 40px; color: #5f6368;">No tax rates configured.</td></tr>
                    <?php else: ?>
                        <?php foreach ($taxRates as $tax): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($tax['name']) ?>
                                    <?php if ($tax['is_default']): ?>
                                        <span class="badge badge-default">Default</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($tax['rate'], 2) ?><?= $tax['type'] == 'percentage' ? '%' : '' ?></td>
                                <td><?= ucfirst($tax['type']) ?></td>
                                <td><span class="badge badge-success"><?= ucfirst($tax['status']) ?></span></td>
                                <td>
                                    <button class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Add Tax Rate Modal -->
    <div id="taxModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Add Tax Rate</h3>
            <form id="taxForm">
                <div class="form-group">
                    <label>Tax Name *</label>
                    <input type="text" name="name" required placeholder="e.g. VAT 18%">
                </div>
                
                <div class="form-group">
                    <label>Rate *</label>
                    <input type="number" name="rate" step="0.01" required placeholder="18.00">
                </div>
                
                <div class="form-group">
                    <label>Type</label>
                    <select name="type">
                        <option value="percentage">Percentage</option>
                        <option value="fixed">Fixed Amount</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_default" value="1"> Set as Default
                    </label>
                </div>
                
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">Add Tax Rate</button>
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openModal() {
            document.getElementById('taxModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('taxModal').style.display = 'none';
        }
        
        document.getElementById('taxForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'add_tax_rate');
                
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error adding tax rate');
            }
        });
    </script>
</body>
</html>


