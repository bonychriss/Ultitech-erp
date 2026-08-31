<?php
require_once '../../includes/functions.php';

global $pdo;

// Fetch Account Balances by Type
$sql = "SELECT a.type, a.name, COALESCE(SUM(ji.debit - ji.credit), 0) as balance 
        FROM erp_accounts a 
        LEFT JOIN erp_journal_items ji ON a.id = ji.account_id 
        GROUP BY a.id, a.type 
        ORDER BY a.code";
$accounts = $pdo->query($sql)->fetchAll(PDO::FETCH_GROUP);

// Helper function to sum balances
function sumType($accounts, $type) {
    if (!isset($accounts[$type])) return 0;
    return array_sum(array_column($accounts[$type], 'balance'));
}

// Calculate Totals
// Assets are Debits (positive), Liabilities & Equity are Credits (negative in this query logic, so we invert or handle accordingly)
// Actually, for Balance Sheet:
// Assets = Debit Balance
// Liabilities = Credit Balance
// Equity = Credit Balance + Net Income

$totalAssets = sumType($accounts, 'asset');
$totalLiabilities = -sumType($accounts, 'liability'); // Invert because credits are negative in sum(debit-credit)
$totalEquity = -sumType($accounts, 'equity');

// Calculate Net Income (Revenue - Expense) for Retained Earnings
$revenue = -sumType($accounts, 'revenue');
$expense = sumType($accounts, 'expense');
$netIncome = $revenue - $expense;

// Add Net Income to Equity
$totalEquity += $netIncome;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance Sheet - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
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
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; padding: 40px; }
        .sheet-header { text-align: center; margin-bottom: 40px; }
        .sheet-header h2 { margin-bottom: 8px; color: #202124; }
        .sheet-header p { color: #5f6368; }
        
        .section { margin-bottom: 30px; }
        .section-title { font-size: 1.1rem; font-weight: 600; color: #1a73e8; border-bottom: 2px solid #f1f3f4; padding-bottom: 8px; margin-bottom: 16px; text-transform: uppercase; }
        
        .line-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f8f9fa; }
        .line-item:last-child { border-bottom: none; }
        
        .total-row { display: flex; justify-content: space-between; padding: 12px 0; margin-top: 8px; font-weight: 700; border-top: 2px solid #202124; font-size: 1.1rem; }
        
        .subsection-total { font-weight: 600; padding-top: 8px; display: flex; justify-content: space-between; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        
        @media print {
            body { background: white; }
            .header { display: none; }
            .card { border: none; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back</a>
            <button onclick="window.print()" class="btn btn-secondary" style="margin-left: 8px;">Print</button>
        </div></div>
    
    <div class="container">
        <div class="card">
            <div class="sheet-header">
                <h2><?= COMPANY_NAME ?></h2>
                <p>Balance Sheet</p>
                <p>As of <?= date('F d, Y') ?></p>
            </div>
            
            <!-- ASSETS -->
            <div class="section">
                <div class="section-title">Assets</div>
                <?php if (isset($accounts['asset'])): ?>
                    <?php foreach ($accounts['asset'] as $acc): ?>
                        <?php if (abs($acc['balance']) > 0): ?>
                            <div class="line-item">
                                <span><?= htmlspecialchars($acc['name']) ?></span>
                                <span>TSh <?= number_format($acc['balance'], 2) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="total-row">
                    <span>Total Assets</span>
                    <span>TSh <?= number_format($totalAssets, 2) ?></span>
                </div>
            </div>
            
            <!-- LIABILITIES -->
            <div class="section">
                <div class="section-title">Liabilities</div>
                <?php if (isset($accounts['liability'])): ?>
                    <?php foreach ($accounts['liability'] as $acc): ?>
                        <?php if (abs($acc['balance']) > 0): ?>
                            <div class="line-item">
                                <span><?= htmlspecialchars($acc['name']) ?></span>
                                <span>TSh <?= number_format(-$acc['balance'], 2) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="subsection-total">
                    <span>Total Liabilities</span>
                    <span>TSh <?= number_format($totalLiabilities, 2) ?></span>
                </div>
            </div>
            
            <!-- EQUITY -->
            <div class="section">
                <div class="section-title">Equity</div>
                <?php if (isset($accounts['equity'])): ?>
                    <?php foreach ($accounts['equity'] as $acc): ?>
                        <?php if (abs($acc['balance']) > 0): ?>
                            <div class="line-item">
                                <span><?= htmlspecialchars($acc['name']) ?></span>
                                <span>TSh <?= number_format(-$acc['balance'], 2) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Net Income (Retained Earnings) -->
                <div class="line-item">
                    <span>Net Income (Retained Earnings)</span>
                    <span>TSh <?= number_format($netIncome, 2) ?></span>
                </div>
                
                <div class="subsection-total">
                    <span>Total Equity</span>
                    <span>TSh <?= number_format($totalEquity, 2) ?></span>
                </div>
                
                <div class="total-row" style="margin-top: 20px;">
                    <span>Total Liabilities & Equity</span>
                    <span>TSh <?= number_format($totalLiabilities + $totalEquity, 2) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

