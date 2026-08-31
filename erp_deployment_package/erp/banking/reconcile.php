<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

$accountId = $_GET['account_id'] ?? null;
if (!$accountId) {
    header('Location: bank-accounts.php');
    exit;
}

$account = $pdo->prepare("SELECT * FROM erp_bank_accounts WHERE id = ?");
$account->execute([$accountId]);
$account = $account->fetch();

// Get unreconciled transactions
$unreconciledTrx = $pdo->prepare("SELECT * FROM erp_bank_transactions WHERE bank_account_id = ? AND reconciled = 0 ORDER BY transaction_date ASC");
$unreconciledTrx->execute([$accountId]);
$unreconciledTrx = $unreconciledTrx->fetchAll();

// Get recent journal entries for matching
$journalEntries = $pdo->query("SELECT je.*, ji.debit, ji.credit 
    FROM erp_journal_entries je 
    JOIN erp_journal_items ji ON je.id = ji.journal_id 
    WHERE ji.account_id = {$account['gl_account_id']} 
    ORDER BY je.date DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bank Reconciliation - <?= htmlspecialchars($account['account_name']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        .recon-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-header { padding: 16px 20px; border-bottom: 1px solid #e0e0e0; font-weight: 600; background: #f8f9fa; }
        .trx-item { padding: 12px 16px; border-bottom: 1px solid #f1f3f4; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .trx-item:hover { background: #f8f9fa; }
        .trx-item.matched { background: #e6f4ea; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .btn-success { background: #137333; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ”„ Bank Reconciliation</h1>
        <a href="bank-transactions.php?account_id=<?= $accountId ?>" class="btn btn-secondary">â† Back</a>
    </div>
    
    <div class="container">
        <div style="background: white; border-radius: 8px; border: 1px solid #e0e0e0; padding: 20px; margin-bottom: 24px;">
            <h3 style="margin-bottom: 12px;"><?= htmlspecialchars($account['account_name']) ?></h3>
            <div style="display: flex; gap: 40px;">
                <div>
                    <div style="font-size: 0.875rem; color: #5f6368;">Bank Balance</div>
                    <div style="font-size: 1.5rem; font-weight: 600; color: #1a73e8;">TSh <?= number_format($account['current_balance'], 2) ?></div>
                </div>
                <div>
                    <div style="font-size: 0.875rem; color: #5f6368;">Unreconciled Items</div>
                    <div style="font-size: 1.5rem; font-weight: 600; color: #b06000;"><?= count($unreconciledTrx) ?></div>
                </div>
            </div>
        </div>
        
        <div class="recon-grid">
            <div class="card">
                <div class="card-header">Bank Statement Transactions</div>
                <div style="max-height: 600px; overflow-y: auto;">
                    <?php if (empty($unreconciledTrx)): ?>
                        <div style="padding: 40px; text-align: center; color: #5f6368;">
                            All transactions reconciled! âœ“
                        </div>
                    <?php else: ?>
                        <?php foreach ($unreconciledTrx as $trx): ?>
                            <div class="trx-item" onclick="markReconciled(<?= $trx['id'] ?>)">
                                <div>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($trx['description']) ?></div>
                                    <div style="font-size: 0.75rem; color: #5f6368;"><?= date('M d, Y', strtotime($trx['transaction_date'])) ?></div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: 600;">
                                        <?php if ($trx['debit'] > 0): ?>
                                            <span style="color: #c5221f;">-<?= number_format($trx['debit'], 2) ?></span>
                                        <?php else: ?>
                                            <span style="color: #137333;">+<?= number_format($trx['credit'], 2) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn btn-success" style="padding: 4px 12px; font-size: 0.75rem; margin-top: 4px;">Mark Reconciled</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">Book Entries (GL)</div>
                <div style="max-height: 600px; overflow-y: auto;">
                    <?php if (empty($journalEntries)): ?>
                        <div style="padding: 40px; text-align: center; color: #5f6368;">
                            No journal entries found for this account.
                        </div>
                    <?php else: ?>
                        <?php foreach ($journalEntries as $je): ?>
                            <div class="trx-item">
                                <div>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($je['description']) ?></div>
                                    <div style="font-size: 0.75rem; color: #5f6368;"><?= date('M d, Y', strtotime($je['date'])) ?> â€¢ <?= htmlspecialchars($je['reference'] ?? '-') ?></div>
                                </div>
                                <div style="font-weight: 600;">
                                    <?php if ($je['debit'] > 0): ?>
                                        <span style="color: #137333;">+<?= number_format($je['debit'], 2) ?></span>
                                    <?php else: ?>
                                        <span style="color: #c5221f;">-<?= number_format($je['credit'], 2) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        async function markReconciled(id) {
            if (!confirm('Mark this transaction as reconciled?')) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'reconcile');
                formData.append('id', id);
                
                const response = await fetch('../api/bank-reconciliation.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error marking transaction as reconciled');
            }
        }
    </script>
</body>
</html>

