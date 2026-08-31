<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS `erp_opportunities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT 0.00,
  `stage` enum('new','qualified','proposal','negotiation','won','lost') DEFAULT 'new',
  `probability` int(3) DEFAULT 0,
  `expected_close_date` date DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stage = $_GET['stage'] ?? 'all';
$sql = "SELECT o.*, 
        COALESCE(c.name, CONCAT(l.first_name, ' ', l.last_name)) as client_name,
        u.username as assigned_user 
        FROM erp_opportunities o 
        LEFT JOIN erp_customers c ON o.customer_id = c.id 
        LEFT JOIN erp_leads l ON o.lead_id = l.id 
        LEFT JOIN users u ON o.assigned_to = u.id 
        WHERE 1=1";
$params = [];

if ($stage !== 'all') {
    $sql .= " AND o.stage = ?";
    $params[] = $stage;
}

$sql .= " ORDER BY o.expected_close_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$opportunities = $stmt->fetchAll();

// Calculate Pipeline Value
$pipelineValue = 0;
foreach ($opportunities as $opp) {
    if ($opp['stage'] != 'lost' && $opp['stage'] != 'won') {
        $pipelineValue += $opp['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Opportunities - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; }
        .filters { display: flex; gap: 12px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .progress-bar { width: 100px; height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 8px; }
        .progress-fill { height: 100%; background: #1a73e8; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ’¼ Opportunities Pipeline</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back</a>
            <a href="create-opportunity.php" class="btn btn-primary">+ New Deal</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-header">
                <div class="filters">
                    <a href="?stage=all" class="btn btn-secondary">All</a>
                    <a href="?stage=proposal" class="btn btn-secondary">Proposal</a>
                    <a href="?stage=negotiation" class="btn btn-secondary">Negotiation</a>
                </div>
                <div style="font-weight: 600; color: #1a73e8;">
                    Active Pipeline: TSh <?= number_format($pipelineValue) ?>
                </div>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Deal Name</th>
                        <th>Client</th>
                        <th>Stage</th>
                        <th>Probability</th>
                        <th>Amount</th>
                        <th>Expected Close</th>
                        <th>Owner</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($opportunities)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px; color: #5f6368;">No active opportunities. Go get some business!</td></tr>
                    <?php else: ?>
                        <?php foreach ($opportunities as $opp): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($opp['name']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($opp['client_name'] ?? 'Unknown') ?></td>
                                <td><?= ucfirst($opp['stage']) ?></td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= $opp['probability'] ?>%;"></div>
                                    </div>
                                    <?= $opp['probability'] ?>%
                                </td>
                                <td style="font-weight: 600;">TSh <?= number_format($opp['amount']) ?></td>
                                <td><?= date('M d, Y', strtotime($opp['expected_close_date'])) ?></td>
                                <td><?= htmlspecialchars($opp['assigned_user'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

