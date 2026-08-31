<?php require_once '../../includes/functions.php'; requireLogin(); global $pdo; $expenses = $pdo->query("SELECT e.*, a.name as account_name FROM erp_expenses e JOIN erp_accounts a ON e.account_id = a.id ORDER BY e.date DESC LIMIT 50")->fetchAll(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expenses - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; } .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 1400px; margin: 0 auto; padding: 24px; } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; } .table { width: 100%; border-collapse: collapse; } .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; } .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; } .table tr:hover { background: #f8f9fa; } .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; } .btn-primary { background: #1a73e8; color: white; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }</style>
</head>
<body>
    <div class="header"><h1>ðŸ’¸ Expenses</h1><div class="header-actions"><a href="../index.php" class="btn btn-secondary">â† Back</a><a href="create-expense.php" class="btn btn-primary">+ Record Expense</a></div></div>
    <div class="container"><div class="card"><table class="table"><thead><tr><th>Date</th><th>Expense #</th><th>Payee</th><th>Category</th><th>Amount</th><th>Payment Method</th></tr></thead><tbody>
    <?php if (empty($expenses)): ?><tr><td colspan="6" style="text-align: center; padding: 32px; color: #5f6368;">No expenses recorded yet.</td></tr><?php else: ?><?php foreach ($expenses as $exp): ?><tr><td><?= date('M d, Y', strtotime($exp['date'])) ?></td><td><?= htmlspecialchars($exp['expense_number']) ?></td><td><?= htmlspecialchars($exp['payee'] ?? '-') ?></td><td><?= htmlspecialchars($exp['account_name']) ?></td><td style="font-weight: 600;">TSh <?= number_format($exp['amount'], 2) ?></td><td><?= ucfirst($exp['payment_method']) ?></td></tr><?php endforeach; ?><?php endif; ?>
    </tbody></table></div></div>
</body>
</html>

