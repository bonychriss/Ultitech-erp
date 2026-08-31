<?php require_once '../../includes/functions.php';  global $pdo; $sql = "SELECT l.*, e.first_name, e.last_name, d.name as department_name, u.full_name as approved_by_name FROM erp_leave_requests l JOIN erp_employees e ON l.employee_id = e.id LEFT JOIN erp_departments d ON e.department_id = d.id LEFT JOIN users u ON l.approved_by = u.id ORDER BY l.created_at DESC"; $requests = $pdo->query($sql)->fetchAll(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Requests - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; } .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 100%; padding: 24px; } .page-wrapper { margin-left: 220px; min-height: 100vh; } @media (max-width: 768px) { .page-wrapper { margin-left: 0; } } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; } .table { width: 100%; border-collapse: collapse; } .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; } .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; } .table tr:hover { background: #f8f9fa; } .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; } .btn-primary { background: #1a73e8; color: white; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; } .btn-success { background: #137333; color: white; padding: 4px 12px; font-size: 0.75rem; } .btn-danger { background: #c5221f; color: white; padding: 4px 12px; font-size: 0.75rem; } .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; } .badge-warning { background: #fef7e0; color: #b06000; } .badge-success { background: #e6f4ea; color: #137333; } .badge-danger { background: #fce8e6; color: #c5221f; }</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions"><a href="../index.php" class="btn btn-secondary">â† Back</a><a href="request-leave.php" class="btn btn-primary">+ Request Leave</a></div></div>
    <div class="container"><div class="card"><?php if (empty($requests)): ?><div style="text-align: center; padding: 64px 24px; color: #5f6368;"><div style="font-size: 4rem; margin-bottom: 16px;">ðŸ–ï¸</div><h3>No leave requests found</h3><p>Employees can request time off here.</p><a href="request-leave.php" class="btn btn-primary" style="margin-top: 16px;">+ Request Leave</a></div><?php else: ?><table class="table"><thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($requests as $req): ?><tr><td><div style="font-weight: 500;"><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?></div><div style="font-size: 0.75rem; color: #5f6368;"><?= htmlspecialchars($req['department_name'] ?? '-') ?></div></td><td><?= ucfirst($req['leave_type']) ?></td><td><?= date('M d', strtotime($req['start_date'])) ?> - <?= date('M d, Y', strtotime($req['end_date'])) ?><div style="font-size: 0.75rem; color: #5f6368;"><?php $start = new DateTime($req['start_date']); $end = new DateTime($req['end_date']); echo $start->diff($end)->days + 1 . ' days'; ?></div></td><td><?= htmlspecialchars($req['reason']) ?></td><td><?php $statusClass = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'rejected' => 'badge-danger']; ?><span class="badge <?= $statusClass[$req['status']] ?? 'badge-info' ?>"><?= ucfirst($req['status']) ?></span><?php if ($req['approved_by_name']): ?><div style="font-size: 0.75rem; color: #5f6368; margin-top: 4px;">by <?= htmlspecialchars($req['approved_by_name']) ?></div><?php endif; ?></td><td><?php if ($req['status'] === 'pending'): ?><button onclick="updateStatus(<?= $req['id'] ?>, 'approved')" class="btn btn-success">Approve</button> <button onclick="updateStatus(<?= $req['id'] ?>, 'rejected')" class="btn btn-danger">Reject</button><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?></div></div>
    <script>
        async function updateStatus(id, status) {
            if (!confirm(`Are you sure you want to ${status} this request?`)) return;
            try {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('id', id);
                formData.append('status', status);
                const response = await fetch('../api/leave.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) { location.reload(); } else { alert('Failed to update status: ' + result.message); }
            } catch (error) { alert('Error: ' + error.message); }
        }
    </script>
</div>
</body>
</html>

