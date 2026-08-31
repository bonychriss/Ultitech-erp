<?php require_once '../../includes/functions.php'; requireLogin(); global $pdo; $sql = "SELECT a.code, a.name, a.type, COALESCE(SUM(ji.debit), 0) as total_debit, COALESCE(SUM(ji.credit), 0) as total_credit FROM erp_accounts a LEFT JOIN erp_journal_items ji ON a.id = ji.account_id GROUP BY a.id ORDER BY a.code"; $accounts = $pdo->query($sql)->fetchAll(); $totalDebit = 0; $totalCredit = 0; foreach ($accounts as $acc) { $totalDebit += $acc['total_debit']; $totalCredit += $acc['total_credit']; } ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trial Balance - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; } .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 1000px; margin: 0 auto; padding: 24px; } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; } .table { width: 100%; border-collapse: collapse; } .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; } .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; } .totals { background: #f8f9fa; font-weight: 600; } .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }</style>
</head>
<body>
    <div class="header"><h1>ðŸ“Š Trial Balance</h1><div class="header-actions"><a href="../index.php" class="btn btn-secondary">â† Back</a></div></div>
    <div class="container"><div class="card"><table class="table"><thead><tr><th>Code</th><th>Account Name</th><th>Type</th><th style="text-align: right;">Debit</th><th style="text-align: right;">Credit</th></tr></thead><tbody>
    <?php foreach ($accounts as $acc): if ($acc['total_debit'] == 0 && $acc['total_credit'] == 0) continue; ?><tr><td><?= htmlspecialchars($acc['code']) ?></td><td><?= htmlspecialchars($acc['name']) ?></td><td><?= ucfirst($acc['type']) ?></td><td style="text-align: right;"><?= number_format($acc['total_debit'], 2) ?></td><td style="text-align: right;"><?= number_format($acc['total_credit'], 2) ?></td></tr><?php endforeach; ?>
    <tr class="totals"><td colspan="3">TOTAL</td><td style="text-align: right;">TSh <?= number_format($totalDebit, 2) ?></td><td style="text-align: right;">TSh <?= number_format($totalCredit, 2) ?></td></tr>
    </tbody></table></div></div>
</body>
</html>

