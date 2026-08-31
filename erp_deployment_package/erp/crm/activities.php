<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS `erp_crm_activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('call','email','meeting','note','task') NOT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `opportunity_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$filter = $_GET['filter'] ?? 'pending';
$sql = "SELECT a.*, 
        u.username as creator,
        COALESCE(l.first_name, o.name, c.name) as related_to,
        CASE 
            WHEN l.id IS NOT NULL THEN 'Lead'
            WHEN o.id IS NOT NULL THEN 'Opportunity'
            WHEN c.id IS NOT NULL THEN 'Customer'
        END as related_type
        FROM erp_crm_activities a 
        LEFT JOIN users u ON a.created_by = u.id 
        LEFT JOIN erp_leads l ON a.lead_id = l.id 
        LEFT JOIN erp_opportunities o ON a.opportunity_id = o.id 
        LEFT JOIN erp_customers c ON a.customer_id = c.id 
        WHERE 1=1";

if ($filter === 'pending') {
    $sql .= " AND a.completed = 0";
}

$sql .= " ORDER BY a.due_date ASC";
$activities = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activities - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1000px; margin: 0 auto; padding: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 16px; }
        .activity-item { padding: 16px 24px; border-bottom: 1px solid #f1f3f4; display: flex; align-items: center; gap: 16px; }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon { width: 40px; height: 40px; border-radius: 50%; background: #e8f0fe; color: #1a73e8; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .activity-content { flex: 1; }
        .activity-title { font-weight: 500; margin-bottom: 4px; }
        .activity-meta { font-size: 0.875rem; color: #5f6368; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .btn-check { width: 24px; height: 24px; border-radius: 50%; border: 2px solid #dadce0; background: white; cursor: pointer; }
        .btn-check:hover { border-color: #1a73e8; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ“… My Activities</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back</a>
            <a href="create-activity.php" class="btn btn-primary">+ New Task</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <div style="padding: 16px 24px; border-bottom: 1px solid #e0e0e0;">
                <a href="?filter=pending" class="btn btn-secondary" style="<?= $filter == 'pending' ? 'background:#e8f0fe;border-color:#1a73e8;color:#1a73e8;' : '' ?>">Pending</a>
                <a href="?filter=all" class="btn btn-secondary" style="<?= $filter == 'all' ? 'background:#e8f0fe;border-color:#1a73e8;color:#1a73e8;' : '' ?>">All History</a>
            </div>
            
            <?php if (empty($activities)): ?>
                <div style="padding: 40px; text-align: center; color: #5f6368;">No activities found. You're all caught up!</div>
            <?php else: ?>
                <?php foreach ($activities as $act): ?>
                    <div class="activity-item" id="act_<?= $act['id'] ?>">
                        <?php if ($act['completed'] == 0): ?>
                            <button class="btn-check" onclick="completeActivity(<?= $act['id'] ?>)"></button>
                        <?php else: ?>
                            <div style="color: #137333;">âœ“</div>
                        <?php endif; ?>
                        
                        <div class="activity-icon">
                            <?php
                            switch($act['type']) {
                                case 'call': echo 'ðŸ“ž'; break;
                                case 'email': echo 'âœ‰ï¸'; break;
                                case 'meeting': echo 'ðŸ‘¥'; break;
                                default: echo 'ðŸ“';
                            }
                            ?>
                        </div>
                        
                        <div class="activity-content">
                            <div class="activity-title" style="<?= $act['completed'] ? 'text-decoration: line-through; color: #5f6368;' : '' ?>">
                                <?= htmlspecialchars($act['subject']) ?>
                            </div>
                            <div class="activity-meta">
                                <?= ucfirst($act['type']) ?> â€¢ 
                                <?= $act['related_to'] ? 'For ' . htmlspecialchars($act['related_type']) . ': ' . htmlspecialchars($act['related_to']) : 'General' ?> â€¢ 
                                Due: <?= $act['due_date'] ? date('M d, H:i', strtotime($act['due_date'])) : 'No Date' ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        async function completeActivity(id) {
            if (!confirm('Mark this activity as complete?')) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'complete');
                formData.append('id', id);
                
                const response = await fetch('../api/crm-activities.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    const item = document.getElementById('act_' + id);
                    item.style.opacity = '0.5';
                    setTimeout(() => window.location.reload(), 500);
                }
            } catch (error) {
                alert('Error updating activity');
            }
        }
    </script>
</body>
</html>

