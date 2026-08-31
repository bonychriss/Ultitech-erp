<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../includes/functions.php';

global $pdo;

// Check if delivery_notes table exists, if not create it
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_delivery_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_number VARCHAR(50) UNIQUE NOT NULL,
        customer_id INT NOT NULL,
        delivery_date DATE NOT NULL,
        driver_name VARCHAR(255),
        vehicle_number VARCHAR(50),
        status ENUM('draft', 'dispatched', 'delivered') DEFAULT 'draft',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES erp_customers(id)
    )");
    // Ensure the 'notes' column exists on live databases where the table may predate this column
    $colCheck = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_delivery_notes' AND COLUMN_NAME = 'notes'");
    $colCheck->execute();
    if (!$colCheck->fetch()) {
        $pdo->exec("ALTER TABLE erp_delivery_notes ADD COLUMN notes TEXT");
    }
} catch (Exception $e) {
    echo "Table creation error: " . $e->getMessage();
}

$status = $_GET['status'] ?? 'all';
$sql = "SELECT dn.*, c.name as customer_name 
        FROM erp_delivery_notes dn 
        JOIN erp_customers c ON dn.customer_id = c.id 
        WHERE 1=1";
$params = [];

if ($status !== 'all') {
    $sql .= " AND dn.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY dn.delivery_date DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deliveries = $stmt->fetchAll();
} catch (Exception $e) {
    echo "Query error: " . $e->getMessage();
    $deliveries = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Notes - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#fff; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        
        /* Layout & Container - specific overrides */
        .page-wrapper { margin-left: 220px !important; min-height: 100vh; padding: 15px !important; width: calc(100% - 220px) !important; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0 !important; padding: 10px !important; width: 100% !important; } }
        
        .header { background: transparent !important; margin-bottom: 20px; padding: 0 !important; display: flex !important; justify-content: space-between; align-items: center; border: none !important; }
        .header h2 { font-size: 1.75rem; font-weight: 600; color: #1f2937; margin: 0; }
        .header-actions { display: flex; gap: 12px; margin: 0 !important; }

        .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
        
        /* Card: Flat, no padding on container itself */
        .card { background: white; border-radius: 0; border: none !important; overflow: visible; box-shadow: none !important; width: 100%; max-width: 100% !important; }
        
        .card-header { padding: 0 0 20px 0; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: transparent; }
        
        .table { width:100%; border-collapse:collapse; } 
        .table th { text-align:left; padding:12px 16px; font-size:0.8rem; font-weight:600; color:#4b5563; text-transform:uppercase; border-bottom:2px solid #e5e7eb; background:#f8f9fa; } 
        .table td { padding:16px; border-bottom:1px solid #f3f4f6; color: #1f2937; vertical-align: middle; }
        .table tr:hover { background:#f8fafc; } 
        
        .btn { padding:8px 16px; border-radius:6px; text-decoration:none; font-size:0.9rem; font-weight:500; cursor:pointer; border:none; display:inline-flex; align-items: center; gap: 6px; } 
        .btn-primary { background:#1a73e8; color:white; } 
        .btn-secondary { background:#fff; color:#374151; border:1px solid #d1d5db; } 
        .btn-success { background:#10b981; color:white; }
        
        .badge { display:inline-block; padding:4px 10px; border-radius:99px; font-size:0.75rem; font-weight:500; } 
        .badge-warning { background:#fef3c7; color:#d97706; } 
        .badge-success { background:#d1fae5; color:#059669; } 
        .badge-info { background:#dbeafe; color:#2563eb; } 
        .badge-danger { background:#fee2e2; color:#dc2626; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
    <div class="page-wrapper">
        <!-- Header -->
        <div class="header">
            <h2>Delivery Notes</h2>
            <div class="header-actions">
                <a href="../index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="create-delivery.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Delivery
                </a>
            </div>
        </div>
    
        <div class="container">
            <div class="card">
                <!-- Filter Toolbar -->
                <div class="card-header">
                    <form method="GET" style="display: flex; width: 100%; align-items: center;">
                         <select name="status" style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff; color: #374151; min-width: 150px;" onchange="this.form.submit()">
                            <option value="all">All Status</option>
                            <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="dispatched" <?= $status == 'dispatched' ? 'selected' : '' ?>>Dispatched</option>
                            <option value="delivered" <?= $status == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        </select>
                    </form>
                </div>
                
                <table class="table">
                    <thead>
                        <tr>
                            <th>DN #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Driver</th>
                            <th>Vehicle</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deliveries)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 64px 24px; color: #5f6368;">
                                    <div style="font-size: 4rem; margin-bottom: 16px; color: #d1d5db;"><i class="fas fa-truck"></i></div>
                                    <h3 style="margin-bottom: 8px; font-weight: 500;">No delivery notes found</h3>
                                    <p>Create delivery notes to track shipments.</p>
                                    <a href="create-delivery.php" class="btn btn-primary" style="margin-top: 16px;">
                                        <i class="fas fa-plus"></i> New Delivery
                                    </a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($deliveries as $dn): ?>
                                <tr>
                                    <td style="font-weight: 500; font-family: monospace; color: #111827;"><?= htmlspecialchars($dn['delivery_number']) ?></td>
                                    <td><?= htmlspecialchars($dn['customer_name']) ?></td>
                                    <td><?= date('M d, Y', strtotime($dn['delivery_date'])) ?></td>
                                    <td><?= htmlspecialchars($dn['driver_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($dn['vehicle_number'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'draft' => 'badge-warning',
                                            'dispatched' => 'badge-info',
                                            'delivered' => 'badge-success'
                                        ];
                                        ?>
                                        <span class="badge <?= $statusClass[$dn['status']] ?? 'badge-info' ?>">
                                            <?= ucfirst($dn['status']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <div style="display: inline-flex; gap: 4px;">
                                            <a href="view-delivery.php?id=<?= $dn['id'] ?>" class="btn-icon" style="text-decoration: none; color: #6b7280; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.2s;" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>


