<?php
require_once '../../includes/functions.php';
global $pdo;

// 1. Calculate Net Income (Revenue - Expenses) for Retained Earnings
// Note: This is simplified. In a real system you'd exclude closed fiscal periods, 
// but for this blueprint we assume all history contributes to RE unless a closing entry was made.

// Revenue
$sqlRev = "SELECT COALESCE(SUM(ji.credit) - SUM(ji.debit), 0) FROM erp_accounts a JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'revenue'";
$totalRevenue = $pdo->query($sqlRev)->fetchColumn();

// Expenses
$sqlExp = "SELECT COALESCE(SUM(ji.debit) - SUM(ji.credit), 0) FROM erp_accounts a JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'expense'";
$totalExpense = $pdo->query($sqlExp)->fetchColumn();

$netIncome = $totalRevenue - $totalExpense;

// 2. Fetch Assets
$assets = $pdo->query("SELECT a.name, COALESCE(SUM(ji.debit) - SUM(ji.credit), 0) as balance 
                       FROM erp_accounts a 
                       LEFT JOIN erp_journal_items ji ON a.id = ji.account_id 
                       WHERE a.type = 'asset' 
                       GROUP BY a.id, a.name 
                       HAVING balance != 0")->fetchAll();
$totalAssets = array_sum(array_column($assets, 'balance'));

// 3. Fetch Liabilities
$liabilities = $pdo->query("SELECT a.name, COALESCE(SUM(ji.credit) - SUM(ji.debit), 0) as balance 
                            FROM erp_accounts a 
                            LEFT JOIN erp_journal_items ji ON a.id = ji.account_id 
                            WHERE a.type = 'liability' 
                            GROUP BY a.id, a.name 
                            HAVING balance != 0")->fetchAll();
$totalLiabilities = array_sum(array_column($liabilities, 'balance'));

// 4. Fetch Equity (excluding Retained Earnings if handled separately, but usually we just add NI to it)
$equity = $pdo->query("SELECT a.name, COALESCE(SUM(ji.credit) - SUM(ji.debit), 0) as balance 
                       FROM erp_accounts a 
                       LEFT JOIN erp_journal_items ji ON a.id = ji.account_id 
                       WHERE a.type = 'equity' 
                       GROUP BY a.id, a.name 
                       HAVING balance != 0")->fetchAll();
$totalEquityAccounts = array_sum(array_column($equity, 'balance'));

// Total Equity = Equity Accounts + Net Income (Retained Earnings)
$totalEquity = $totalEquityAccounts + $netIncome;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance Sheet - ERP</title>
    <!-- Modern Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --primary: #1a1a1a;
            --accent: #FFD700;
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            background: var(--bg); 
            font-family: 'Inter', sans-serif; 
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        .main-content {
            margin-left: 220px;
            padding: 32px;
            min-height: 100vh;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .header h1 { font-size: 1.5rem; font-weight: 700; color: var(--primary); }
        .header-date { color: var(--text-muted); font-size: 0.9rem; }

        .report-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            height: fit-content;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 12px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
        }

        .line-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.9rem;
        }
        
        .line-item:last-of-type { border-bottom: none; }
        
        .total-row {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 2px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary);
        }

        .retained-earnings {
            background: #fdfce8; /* yellowish tint */
            padding: 8px;
            margin: 4px -8px;
            border-radius: 4px;
        }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; }
            .report-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
    
    <div class="header">
        <div>
            <h1>Balance Sheet</h1>
            <div class="header-date">As of <?= date('F d, Y') ?></div>
        </div>
        <button onclick="window.print()" style="background: white; border: 1px solid #e5e7eb; padding: 8px 16px; border-radius: 6px; cursor: pointer;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <div class="report-container">
        <!-- Assets -->
        <div class="card">
            <div class="section-title">
                <span>Assets</span>
            </div>
            
            <?php foreach ($assets as $asset): ?>
                <div class="line-item">
                    <span><?= htmlspecialchars($asset['name']) ?></span>
                    <span><?= number_format($asset['balance'], 2) ?></span>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($assets)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 20px;">No assets recorded</div>
            <?php endif; ?>

            <div class="total-row">
                <span>Total Assets</span>
                <span><?= number_format($totalAssets, 2) ?></span>
            </div>
        </div>

        <!-- Liabilities & Equity -->
        <div class="card">
            <!-- Liabilities -->
            <div style="margin-bottom: 32px;">
                <div class="section-title">
                    <span>Liabilities</span>
                </div>
                
                <?php foreach ($liabilities as $lia): ?>
                    <div class="line-item">
                        <span><?= htmlspecialchars($lia['name']) ?></span>
                        <span><?= number_format($lia['balance'], 2) ?></span>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($liabilities)): ?>
                    <div style="text-align: center; color: var(--text-muted); padding: 10px;">No liabilities recorded</div>
                <?php endif; ?>

                <div class="line-item" style="font-weight: 600; margin-top: 8px;">
                    <span>Total Liabilities</span>
                    <span><?= number_format($totalLiabilities, 2) ?></span>
                </div>
            </div>

            <!-- Equity -->
            <div>
                <div class="section-title">
                    <span>Equity</span>
                </div>
                
                <?php foreach ($equity as $eq): ?>
                    <div class="line-item">
                        <span><?= htmlspecialchars($eq['name']) ?></span>
                        <span><?= number_format($eq['balance'], 2) ?></span>
                    </div>
                <?php endforeach; ?>

                <!-- Calculated Retained Earnings -->
                <div class="line-item retained-earnings">
                    <span>Retained Earnings (Net Income)</span>
                    <span><?= number_format($netIncome, 2) ?></span>
                </div>

                <div class="line-item" style="font-weight: 600; margin-top: 8px;">
                    <span>Total Equity</span>
                    <span><?= number_format($totalEquity, 2) ?></span>
                </div>
            </div>
            
            <!-- Grand Total L+E -->
            <div class="total-row" style="margin-top: 32px; border-top: 4px double #e5e7eb;">
                <span>Total Liabilities & Equity</span>
                <span><?= number_format($totalLiabilities + $totalEquity, 2) ?></span>
            </div>
        </div>
    </div>

</div>

</body>
</html>
