<?php require_once '../../includes/functions.php'; requireLogin(); global $pdo; $id = $_GET['id'] ?? 0; $je = $pdo->prepare("SELECT * FROM erp_journal_entries WHERE id = ?"); $je->execute([$id]); $journal = $je->fetch(); if (!$journal) die("Journal entry not found"); $items = $pdo->prepare("SELECT ji.*, a.code, a.name FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id WHERE ji.journal_id = ?"); $items->execute([$id]); $items = $items->fetchAll(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Journal Entry - <?= htmlspecialchars($journal['entry_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; padding: 20px; } .container { background: white; max-width: 800px; margin: 0 auto; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); } .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #f1f3f4; padding-bottom: 20px; } .table { width: 100%; border-collapse: collapse; margin: 20px 0; } .table th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; } .table td { padding: 12px; border-bottom: 1px solid #f1f3f4; } .totals { display: flex; justify-content: space-between; padding: 16px; background: #f8f9fa; border-radius: 4px; margin-top: 20px; font-weight: 600; } .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; color: white; background: #1a73e8; display: inline-block; }</style>
</head>
<body>
    <div style="text-align: center; margin-bottom: 20px;"><a href="journal-entries.php" class="btn">â† Back to Journal Entries</a></div>
    <div class="container">
        <div class="header"><h1>Journal Entry</h1><p><?= htmlspecialchars($journal['entry_number']) ?> - <?= date('M d, Y', strtotime($journal['date'])) ?></p><p><?= htmlspecialchars($journal['description'] ?? '') ?></p></div>
        <table class="table"><thead><tr><th>Account</th><th>Debit</th><th>Credit</th></tr></thead><tbody>
        <?php $totalDebit = 0; $totalCredit = 0; foreach ($items as $item): $totalDebit += $item['debit']; $totalCredit += $item['credit']; ?><tr><td><?= htmlspecialchars($item['code'] . ' - ' . $item['name']) ?></td><td><?= $item['debit'] > 0 ? number_format($item['debit'], 2) : '' ?></td><td><?= $item['credit'] > 0 ? number_format($item['credit'], 2) : '' ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <div class="totals"><div>Total Debit: TSh <?= number_format($totalDebit, 2) ?></div><div>Total Credit: TSh <?= number_format($totalCredit, 2) ?></div></div>
    </div>
</body>
</html>

