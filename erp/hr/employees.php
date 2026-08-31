<?php
require_once '../../includes/functions.php';
global $pdo;

$sql = "SELECT * FROM erp_employees ORDER BY id DESC";
$employees = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employees - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
         * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        .page-wrapper { margin-left: 220px !important; padding: 30px; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 1.8rem; font-weight: 700; color: #111827; }
        
        .btn-primary { background: #2563eb; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; }
        
        .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px; background: #f9fafb; font-weight: 600; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .table td { padding: 12px; border-bottom: 1px solid #f3f4f6; color: #374151; vertical-align: middle; }
        
        .avatar { width: 36px; height: 36px; background: #e0e7ff; color: #3730a3; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem; margin-right: 12px; }
        .emp-name { display: flex; align-items: center; font-weight: 500; }
        
        .badge { display:inline-block; padding:4px 10px; border-radius:99px; font-size:0.75rem; font-weight:600; } 
        .badge-active { background:#d1fae5; color:#059669; }
        .badge-terminated { background:#fee2e2; color:#dc2626; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <div class="header">
        <h1 class="page-title">Employees</h1>
        <a href="create-employee.php" class="btn-primary">
            <i class="fas fa-plus"></i> New Employee
        </a>
    </div>

    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Emp. Code</th>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Email</th>
                    <th>Salary</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="7" style="text-align:center; padding: 30px; color:#6b7280;">No employees found.</td></tr>
                <?php else: ?>
                    <?php foreach ($employees as $emp): 
                        $initials = strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1));
                    ?>
                        <tr>
                            <td style="font-family:monospace; color:#6b7280;"><?= htmlspecialchars($emp['employee_code']) ?></td>
                            <td>
                                <div class="emp-name">
                                    <div class="avatar"><?= $initials ?></div>
                                    <div><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($emp['position']) ?></td>
                            <td><?= htmlspecialchars($emp['email']) ?></td>
                            <td><?= number_format($emp['basic_salary'], 2) ?></td>
                            <td><span class="badge badge-<?= strtolower($emp['status']) ?>"><?= ucfirst($emp['status']) ?></span></td>
                            <td>
                                <a href="edit-employee.php?id=<?= $emp['id'] ?>" style="color: #2563eb; margin-right: 12px;"><i class="fas fa-edit"></i></a>
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
