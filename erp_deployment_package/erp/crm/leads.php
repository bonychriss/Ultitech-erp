<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Create tables if not exist (Fallback for failed SQL execution)
$pdo->exec("CREATE TABLE IF NOT EXISTS `erp_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `source` varchar(50) DEFAULT NULL,
  `status` enum('new','contacted','qualified','lost','converted') DEFAULT 'new',
  `assigned_to` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$status = $_GET['status'] ?? 'all';
$sql = "SELECT l.*, u.username as assigned_user 
        FROM erp_leads l 
        LEFT JOIN users u ON l.assigned_to = u.id 
        WHERE 1=1";
$params = [];

if ($status !== 'all') {
    $sql .= " AND l.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY l.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leads - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e0e0e0; display: flex; gap: 12px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-new { background: #e8f0fe; color: #1967d2; }
        .badge-contacted { background: #fef7e0; color: #b06000; }
        .badge-qualified { background: #e6f4ea; color: #137333; }
        .badge-lost { background: #fce8e6; color: #c5221f; }
        .badge-converted { background: #202124; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸŽ¯ Leads Pipeline</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back</a>
            <a href="create-lead.php" class="btn btn-primary">+ New Lead</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-header">
                <a href="?status=all" class="btn btn-secondary">All</a>
                <a href="?status=new" class="btn btn-secondary">New</a>
                <a href="?status=qualified" class="btn btn-secondary">Qualified</a>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leads)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px; color: #5f6368;">No leads found. Start adding potential customers!</td></tr>
                    <?php else: ?>
                        <?php foreach ($leads as $lead): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($lead['first_name'] . ' ' . $lead['last_name']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($lead['company'] ?? '-') ?></td>
                                <td>
                                    <div><?= htmlspecialchars($lead['email']) ?></div>
                                    <div style="font-size: 0.75rem; color: #5f6368;"><?= htmlspecialchars($lead['phone']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($lead['source']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $lead['status'] ?>">
                                        <?= ucfirst($lead['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($lead['assigned_user'] ?? 'Unassigned') ?></td>
                                <td>
                                    <a href="view-lead.php?id=<?= $lead['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

