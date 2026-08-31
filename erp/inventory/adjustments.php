<?php
require_once '../../includes/functions.php';

global $pdo;

// Get adjustments
$sql = "SELECT a.*, u.full_name as created_by_name 
        FROM erp_inventory_adjustments a 
        JOIN users u ON a.created_by = u.id 
        ORDER BY a.created_at DESC";
$adjustments = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Adjustments - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 100%; padding: 24px; }
        
        .page-wrapper {
            margin-left: 220px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e0e0e0; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; }
        .table tr:hover { background: #f8f9fa; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-danger { background: #fce8e6; color: #c5221f; }
        .badge-info { background: #e8f0fe; color: #1967d2; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">← Back to Dashboard</a>
            <a href="create-adjustment.php" class="btn btn-primary">+ New Adjustment</a>
        </div></div>
    
    <div class="container">
        <div class="card">
            <?php if (empty($adjustments)): ?>
                <div style="text-align: center; padding: 64px 24px; color: #5f6368;">
                    <div style="font-size: 4rem; margin-bottom: 16px;">📉</div>
                    <h3>No adjustments found</h3>
                    <p>Use adjustments to correct stock levels (damage, theft, etc.)</p>
                    <a href="create-adjustment.php" class="btn btn-primary" style="margin-top: 16px;">+ New Adjustment</a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ref #</th>
                            <th>Date</th>
                            <th>Reason</th>
                            <th>Created By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adjustments as $adj): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($adj['adjustment_number']) ?></strong></td>
                                <td><?= date('M d, Y', strtotime($adj['date'])) ?></td>
                                <td>
                                    <span class="badge badge-warning">
                                        <?= ucfirst(str_replace('_', ' ', $adj['reason'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($adj['created_by_name']) ?></td>
                                <td><?= htmlspecialchars($adj['notes'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
