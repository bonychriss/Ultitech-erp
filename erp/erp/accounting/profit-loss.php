<?php require_once '../../includes/functions.php';  global $pdo; $sql = "SELECT a.name, COALESCE(SUM(ji.credit), 0) as amount FROM erp_accounts a LEFT JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'revenue' GROUP BY a.id"; $revenues = $pdo->query($sql)->fetchAll(); $totalRevenue = array_sum(array_column($revenues, 'amount')); $sql = "SELECT a.name, COALESCE(SUM(ji.debit), 0) as amount FROM erp_accounts a LEFT JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'expense' GROUP BY a.id"; $expenses = $pdo->query($sql)->fetchAll(); $totalExpense = array_sum(array_column($expenses, 'amount')); $netIncome = $totalRevenue - $totalExpense; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profit & Loss - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; margin: 0; padding: 0; } .page-wrapper { margin-left: 220px; min-height: 100vh; } .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; margin: 0; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 100%; padding: 24px; } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; padding: 24px; max-width: 900px; margin: 0 auto; } .section { margin: 20px 0; } .section-title { font-weight: 600; font-size: 1.1rem; margin-bottom: 12px; color: #1a73e8; border-bottom: 2px solid #f1f3f4; padding-bottom: 8px; } .line-item { display: flex; justify-content: space-between; padding: 8px 0; } .subtotal { font-weight: 600; background: #f8f9fa; padding: 12px; margin: 12px 0; display: flex; justify-content: space-between; } .net-income { background: #e8f0fe; padding: 20px; margin-top: 20px; display: flex; justify-content: space-between; font-size: 1.2rem; font-weight: 700; color: #1a73e8; } .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; } @media (max-width: 768px) { .page-wrapper { margin-left: 0; } }</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions"><a href="../index.php" class="btn btn-secondary">â† Back</a></div></div>
    <div class="container"><div class="card">
        <h2 style="text-align: center; margin-bottom: 8px;"><?= COMPANY_NAME ?></h2>
        <p style="text-align: center; color: #5f6368; margin-bottom: 24px;">Income Statement - <?= date('F Y') ?></p>
        <div class="section"><div class="section-title">Revenue</div>
        <?php foreach ($revenues as $rev): if ($rev['amount'] > 0): ?><div class="line-item"><span><?= htmlspecialchars($rev['name']) ?></span><span>TSh <?= number_format($rev['amount'], 2) ?></span></div><?php endif; endforeach; ?>
        <div class="subtotal"><span>Total Revenue</span><span>TSh <?= number_format($totalRevenue, 2) ?></span></div></div>
        <div class="section"><div class="section-title">Expenses</div>
        <?php foreach ($expenses as $exp): if ($exp['amount'] > 0): ?><div class="line-item"><span><?= htmlspecialchars($exp['name']) ?></span><span>TSh <?= number_format($exp['amount'], 2) ?></span></div><?php endif; endforeach; ?>
        <div class="subtotal"><span>Total Expenses</span><span>TSh <?= number_format($totalExpense, 2) ?></span></div></div>
        <div class="net-income"><span>Net Income</span><span style="color: <?= $netIncome >= 0 ? '#137333' : '#c5221f' ?>">TSh <?= number_format($netIncome, 2) ?></span></div>
    </div></div>
    </div>
</body>
</html>

