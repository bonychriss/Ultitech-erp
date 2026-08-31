<?php
require_once '../../includes/functions.php';

global $pdo;

// Create roles table
$pdo->exec("CREATE TABLE IF NOT EXISTS `erp_user_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) NOT NULL UNIQUE,
  `description` text DEFAULT NULL,
  `permissions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$roles = $pdo->query("SELECT * FROM erp_user_roles ORDER BY role_name")->fetchAll();

$availablePermissions = [
    'sales' => 'Sales & Invoices',
    'quotes' => 'Quotes',
    'customers' => 'Customers',
    'inventory' => 'Inventory Management',
    'products' => 'Products',
    'deliveries' => 'Delivery Notes',
    'purchasing' => 'Purchase Orders',
    'suppliers' => 'Suppliers',
    'accounting' => 'Accounting',
    'expenses' => 'Expenses',
    'petty_cash' => 'Petty Cash',
    'reports' => 'Reports',
    'hr' => 'HR & Payroll',
    'banking' => 'Bank Reconciliation',
    'settings' => 'System Settings',
    'all' => 'Full Access (Administrator)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Roles - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .roles-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
        .role-card { background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; }
        .role-name { font-size: 1.1rem; font-weight: 600; margin-bottom: 8px; }
        .role-desc { font-size: 0.875rem; color: #5f6368; margin-bottom: 16px; }
        .permissions-list { margin-top: 12px; }
        .permission-item { display: inline-block; padding: 4px 12px; background: #e8f0fe; color: #1967d2; border-radius: 12px; font-size: 0.75rem; margin: 4px; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div style="padding: 16px 24px 0; text-align: right;"><a href="index.php" class="btn btn-secondary">â† Back</a></div>
    
    <div class="container">
        <?php if (empty($roles)): ?>
            <div style="background: white; border-radius: 8px; padding: 40px; text-align: center; color: #5f6368;">
                No roles configured. Default roles will be created automatically.
            </div>
        <?php else: ?>
            <div class="roles-grid">
                <?php foreach ($roles as $role): ?>
                    <?php $perms = json_decode($role['permissions'], true) ?: []; ?>
                    <div class="role-card">
                        <div class="role-name"><?= htmlspecialchars($role['role_name']) ?></div>
                        <div class="role-desc"><?= htmlspecialchars($role['description']) ?></div>
                        
                        <div class="permissions-list">
                            <?php if (in_array('all', $perms)): ?>
                                <span class="permission-item">âœ¨ Full Access</span>
                            <?php else: ?>
                                <?php foreach ($perms as $perm): ?>
                                    <span class="permission-item"><?= $availablePermissions[$perm] ?? $perm ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div style="margin-top: 16px;">
                            <button class="btn btn-secondary" style="padding: 6px 16px; font-size: 0.75rem;">Edit Permissions</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>


