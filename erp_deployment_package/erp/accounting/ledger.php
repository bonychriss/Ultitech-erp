<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Get all accounts for filter
$accounts = $pdo->query("SELECT * FROM erp_accounts ORDER BY code")->fetchAll();

// Filter parameters
$accountId = $_GET['account_id'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$transactions = [];
$openingBalance = 0;

if ($accountId) {
    // 1. Get Opening Balance (sum of all transactions before start date)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(debit - credit), 0) FROM erp_journal_items ji 
                           JOIN erp_journal_entries je ON ji.journal_id = je.id 
                           WHERE ji.account_id = ? AND je.date < ?");
    $stmt->execute([$accountId, $startDate]);
    $openingBalance = $stmt->fetchColumn();

    // 2. Get Transactions within date range
    $stmt = $pdo->prepare("SELECT ji.*, je.date, je.description as journal_desc, je.reference 
                           FROM erp_journal_items ji 
                           JOIN erp_journal_entries je ON ji.journal_id = je.id 
                           WHERE ji.account_id = ? AND je.date BETWEEN ? AND ? 
                           ORDER BY je.date ASC, je.id ASC");
    $stmt->execute([$accountId, $startDate, $endDate]);
    $transactions = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>General Ledger - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 24px; }
        .filter-bar { padding: 20px; background: #fff; border-bottom: 1px solid #e0e0e0; display: flex; gap: 16px; align-items: end; }
        .form-group { flex: 1; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 0.875rem; color: #5f6368; }
        .form-control { width: 100%; padding: 8px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; }
        .table td { padding: 12px 16px; border-bottom: 1px solid #f1f3f4; font-size: 0.875rem; }
        .text-right { text-align: right; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .balance-row { background: #f8f9fa; font-weight: 600; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ“š General Ledger</h1>
        <a href="../index.php" class="btn btn-secondary">â† Back to Dashboard</a>
    </div>
    
    <div class="container">
        <div class="card">
            <form class="filter-bar">
                <div class="form-group" style="flex: 2;">
                    <label>Account</label>
                    <select name="account_id" class="form-control" required>
                        <option value="">Select Account</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>" <?= $accountId == $acc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($acc['code'] . ' - ' . $acc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $startDate ?>">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $endDate ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-bottom: 2px;">View Ledger</button>
            </form>
            
            <?php if ($accountId): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                            <th class="text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="balance-row">
                            <td colspan="5">Opening Balance</td>
                            <td class="text-right"><?= number_format($openingBalance, 2) ?></td>
                        </tr>
                        
                        <?php 
                        $runningBalance = $openingBalance;
                        foreach ($transactions as $trx): 
                            $runningBalance += ($trx['debit'] - $trx['credit']);
                        ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($trx['date'])) ?></td>
                                <td><?= htmlspecialchars($trx['reference'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($trx['journal_desc']) ?></td>
                                <td class="text-right"><?= $trx['debit'] > 0 ? number_format($trx['debit'], 2) : '-' ?></td>
                                <td class="text-right"><?= $trx['credit'] > 0 ? number_format($trx['credit'], 2) : '-' ?></td>
                                <td class="text-right"><?= number_format($runningBalance, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <tr class="balance-row">
                            <td colspan="5">Closing Balance</td>
                            <td class="text-right"><?= number_format($runningBalance, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding: 40px; text-align: center; color: #5f6368;">
                    Select an account to view its ledger history.
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

