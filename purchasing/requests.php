<?php 
require_once '../../includes/functions.php';
global $pdo;

$sql = "SELECT pr.*, u.full_name as requester_name 
        FROM erp_purchase_requests pr 
        JOIN users u ON pr.requested_by = u.id 
        ORDER BY pr.id DESC";
$requests = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Requests - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#fff; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        .page-wrapper { margin-left: 220px !important; min-height: 100vh; padding: 15px !important; width: calc(100% - 220px) !important; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0 !important; width: 100% !important; } }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header h2 { margin: 0; font-size: 1.75rem; color: #1f2937; }
        
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .table th { text-align: left; padding: 12px 16px; background: #f8f9fa; border-bottom: 2px solid #e5e7eb; color: #4b5563; }
        .table td { padding: 16px; border-bottom: 1px solid #f3f4f6; color: #1f2937; }
        
        .btn { padding:8px 16px; border-radius:6px; text-decoration:none; font-size:0.9rem; font-weight:500; cursor:pointer; border:none; display:inline-flex; align-items: center; gap: 6px; } 
        .btn-primary { background:#1a73e8; color:white; }
        
        .badge { display:inline-block; padding:4px 10px; border-radius:99px; font-size:0.75rem; font-weight:500; } 
        .badge-pending_approval { background:#fef3c7; color:#d97706; }
        .badge-approved { background:#d1fae5; color:#059669; }
        .badge-rejected { background:#fee2e2; color:#dc2626; }
        .badge-po_created { background:#dbeafe; color:#2563eb; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <div class="header">
        <h2>Purchase Requests</h2>
        <a href="create-request.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Request
        </a>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>PR Number</th>
                <th>Date</th>
                <th>Requester</th>
                <th>Department</th>
                <th>Est. Cost</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="7" style="text-align:center; padding:30px; color:#6b7280;">No purchase requests found.</td></tr>
            <?php else: ?>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td style="font-family:monospace; font-weight:500;"><?= htmlspecialchars($r['request_number']) ?></td>
                        <td><?= date('M d, Y', strtotime($r['request_date'])) ?></td>
                        <td><?= htmlspecialchars($r['requester_name']) ?></td>
                        <td><?= htmlspecialchars($r['department']) ?></td>
                        <td><?= number_format($r['total_estimated_cost'], 2) ?></td>
                        <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst(str_replace('_', ' ', $r['status'])) ?></span></td>
                        <td style="color:#6b7280; font-size:0.9rem;"><?= htmlspecialchars(substr($r['notes'] ?? '', 0, 50)) ?>...</td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
