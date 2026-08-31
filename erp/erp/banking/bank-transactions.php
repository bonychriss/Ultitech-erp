<?php
require_once '../../includes/functions.php';

global $pdo;

// Create transactions table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS `erp_bank_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_account_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `debit` decimal(15,2) DEFAULT 0.00,
  `credit` decimal(15,2) DEFAULT 0.00,
  `balance` decimal(15,2) DEFAULT 0.00,
  `reconciled` tinyint(1) DEFAULT 0,
  `reconciled_date` date DEFAULT NULL,
  `matched_journal_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$accountId = $_GET['account_id'] ?? null;
if (!$accountId) {
    header('Location: bank-accounts.php');
    exit;
}

$account = $pdo->prepare("SELECT * FROM erp_bank_accounts WHERE id = ?");
$account->execute([$accountId]);
$account = $account->fetch();

if (!$account) {
    header('Location: bank-accounts.php');
    exit;
}

$transactions = $pdo->prepare("SELECT * FROM erp_bank_transactions WHERE bank_account_id = ? ORDER BY transaction_date DESC, id DESC");
$transactions->execute([$accountId]);
$transactions = $transactions->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Bank Transactions - <?= htmlspecialchars($account['account_name']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
        }

        .header {
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 1.5rem;
            font-weight: 500;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .account-info {
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card {
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.75rem;
            font-weight: 500;
            color: #5f6368;
            text-transform: uppercase;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }

        .table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f3f4;
            font-size: 0.875rem;
        }

        .text-right {
            text-align: right;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-block;
        }

        .btn-primary {
            background: #1a73e8;
            color: white;
        }

        .btn-secondary {
            background: #fff;
            color: #202124;
            border: 1px solid #dadce0;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-success {
            background: #e6f4ea;
            color: #137333;
        }

        .badge-warning {
            background: #fef7e0;
            color: #b06000;
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="header">
        <h1>ðŸ’³ <?= htmlspecialchars($account['account_name']) ?></h1>
        <div>
            <a href="bank-accounts.php" class="btn btn-secondary">â† Back</a>
            <a href="add-transaction.php?account_id=<?= $accountId ?>" class="btn btn-primary">+ Add Transaction</a>
        </div>
    </div>

    <div class="container">
        <div class="account-info">
            <div>
                <div style="font-size: 0.875rem; color: #5f6368; margin-bottom: 4px;">
                    <?= htmlspecialchars($account['bank_name']) ?> â€¢
                    <?= htmlspecialchars($account['account_number']) ?></div>
                <div style="font-size: 1.5rem; font-weight: 600; color: #137333;">TSh
                    <?= number_format($account['current_balance'], 2) ?></div>
            </div>
            <!-- Removed generic reconcile.php link in favor of inline reconcile -->
            <!-- <a href="reconcile.php?account_id=<?= $accountId ?>" class="btn btn-primary">Start Reconciliation</a> -->
        </div>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th class="text-right">Debit</th>
                        <th class="text-right">Credit</th>
                        <th class="text-right">Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #5f6368;">No transactions yet.
                                Import from bank statement.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $trx): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($trx['transaction_date'])) ?></td>
                                <td><?= htmlspecialchars($trx['description']) ?></td>
                                <td><?= htmlspecialchars($trx['reference'] ?? '-') ?></td>
                                <td class="text-right"><?= $trx['debit'] > 0 ? number_format($trx['debit'], 2) : '-' ?></td>
                                <td class="text-right"><?= $trx['credit'] > 0 ? number_format($trx['credit'], 2) : '-' ?></td>
                                <td class="text-right"><?= number_format($trx['balance'], 2) ?></td>
                                <td>
                                    <span class="badge badge-<?= $trx['reconciled'] ? 'success' : 'warning' ?>">
                                        <?= $trx['reconciled'] ? 'Reconciled' : 'Pending' ?>
                                    </span>
                                    <?php if (!$trx['reconciled']): ?>
                                        <button onclick="reconcileTransaction(<?= $trx['id'] ?>)" class="btn btn-sm"
                                            style="font-size: 0.75rem; padding: 2px 8px; margin-left: 8px; border: 1px solid #ccc; background: #fff; cursor: pointer; color: #1a73e8;">Reconcile</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        async function reconcileTransaction(id) {
            if (!confirm('Reconcile this transaction? This will mark linked invoices as PAID.')) return;

            try {
                const formData = new FormData();
                formData.append('action', 'reconcile');
                formData.append('transaction_id', id);

                const response = await fetch('../api/banking.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    alert('Reconciled!');
                    window.location.reload();
                } else {
                    alert('Failed: ' + result.message);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }
    </script>
</body>

</html>