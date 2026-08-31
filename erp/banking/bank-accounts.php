<?php
require_once '../../includes/functions.php';

global $pdo;

// Create tables if not exist
$pdo->exec("CREATE TABLE IF NOT EXISTS `erp_bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(255) NOT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `bank_name` varchar(255) NOT NULL,
  `branch` varchar(255) DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'TSh',
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `gl_account_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$accounts = $pdo->query("SELECT * FROM erp_bank_accounts WHERE status = 'active' ORDER BY account_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bank Accounts - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 24px; }
        .accounts-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .account-card { background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; transition: box-shadow 0.2s; }
        .account-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .account-name { font-size: 1.1rem; font-weight: 600; margin-bottom: 8px; }
        .account-details { font-size: 0.875rem; color: #5f6368; margin-bottom: 12px; }
        .account-balance { font-size: 1.5rem; font-weight: 700; color: #137333; margin-top: 12px; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div class="header">
        <h1><i class="fas fa-university"></i> Bank Accounts</h1>
        <div>
            <a href="../index.php" class="btn btn-secondary">â† Back</a>
            <a href="create-bank-account.php" class="btn btn-primary">+ Add Bank Account</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (empty($accounts)): ?>
            <div class="card" style="padding: 40px; text-align: center; color: #5f6368;">
                <div style="font-size: 3rem; margin-bottom: 16px;"><i class="fas fa-university"></i></div>
                <h3 style="margin-bottom: 8px;">No Bank Accounts</h3>
                <p>Add your first bank account to start reconciliation.</p>
                <a href="create-bank-account.php" class="btn btn-primary" style="margin-top: 16px;">+ Add Bank Account</a>
            </div>
        <?php else: ?>
            <div class="accounts-grid">
                <?php foreach ($accounts as $acc): ?>
                    <div class="account-card">
                        <div class="account-name"><?= htmlspecialchars($acc['account_name']) ?></div>
                        <div class="account-details">
                            <?= htmlspecialchars($acc['bank_name']) ?><br>
                            Account: <?= htmlspecialchars($acc['account_number']) ?>
                        </div>
                        <div class="account-balance">
                            TSh <?= number_format($acc['current_balance'], 2) ?>
                        </div>
                        <div style="margin-top: 16px; display: flex; gap: 8px;">
                            <a href="bank-transactions.php?account_id=<?= $acc['id'] ?>" class="btn btn-primary" style="flex: 1; text-align: center;">View Transactions</a>
                            <a href="reconcile.php?account_id=<?= $acc['id'] ?>" class="btn btn-secondary">Reconcile</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>


