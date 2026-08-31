<?php
require_once '../../includes/functions.php';
global $pdo;

// Fetch all accounts with their total debits and credits
// We sum everything from erp_journal_items
$sql = "SELECT 
            a.code, 
            a.name, 
            a.type, 
            COALESCE(SUM(ji.debit), 0) as total_debit, 
            COALESCE(SUM(ji.credit), 0) as total_credit
        FROM erp_accounts a
        LEFT JOIN erp_journal_items ji ON a.id = ji.account_id
        GROUP BY a.id, a.code, a.name, a.type
        HAVING total_debit > 0 OR total_credit > 0
        ORDER BY a.code ASC";

$stmt = $pdo->query($sql);
$accounts = $stmt->fetchAll();

$totalDebit = 0;
$totalCredit = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trial Balance - ERP</title>
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
        
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .table { width: 100%; border-collapse: collapse; }
        .table th { 
            text-align: left; 
            padding: 12px 16px; 
            font-size: 0.8rem; 
            font-weight: 600; 
            color: var(--text-muted); 
            text-transform: uppercase; 
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        .table td { 
            padding: 12px 16px; 
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.9rem;
        }
        .table tr:last-child td { border-bottom: none; }
        .text-right { text-align: right; }
        .font-mono { font-family: 'Courier New', monospace; }
        .font-bold { font-weight: 700; }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.75rem;
            border-radius: 4px;
            font-weight: 500;
            text-transform: capitalize;
        }
        .badge-asset { background: #dbf4ff; color: #0070a3; }
        .badge-liability { background: #fff0db; color: #b86e00; }
        .badge-equity { background: #e6fffa; color: #007a5e; }
        .badge-revenue { background: #def7ec; color: #03543f; }
        .badge-expense { background: #fde8e8; color: #9b1c1c; }

        .total-row td {
            background: #f9fafb;
            font-weight: 700;
            border-top: 2px solid #e5e7eb;
        }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
    
    <div class="header">
        <h1>Trial Balance</h1>
        <button onclick="window.print()" style="background: white; border: 1px solid #e5e7eb; padding: 8px 16px; border-radius: 6px; cursor: pointer;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account Name</th>
                    <th>Type</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $acc): ?>
                    <?php 
                        $totalDebit += $acc['total_debit'];
                        $totalCredit += $acc['total_credit'];
                    ?>
                    <tr>
                        <td class="font-mono"><?= htmlspecialchars($acc['code'] ?? '--') ?></td>
                        <td><?= htmlspecialchars($acc['name']) ?></td>
                        <td><span class="badge badge-<?= strtolower($acc['type']) ?>"><?= ucfirst($acc['type']) ?></span></td>
                        <td class="text-right font-mono"><?= $acc['total_debit'] > 0 ? number_format($acc['total_debit'], 2) : '-' ?></td>
                        <td class="text-right font-mono"><?= $acc['total_credit'] > 0 ? number_format($acc['total_credit'], 2) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                
                <tr class="total-row">
                    <td colspan="3" class="text-right">TOTAL</td>
                    <td class="text-right font-mono"><?= number_format($totalDebit, 2) ?></td>
                    <td class="text-right font-mono"><?= number_format($totalCredit, 2) ?></td>
                </tr>
            </tbody>
        </table>
        
        <?php if (abs($totalDebit - $totalCredit) > 0.01): ?>
            <div style="margin-top: 16px; padding: 12px; background: #fee2e2; color: #991b1b; border-radius: 6px; font-weight: 500;">
                <i class="fas fa-exclamation-triangle"></i> Warning: Trial Balance is not balanced! Difference: <?= number_format(abs($totalDebit - $totalCredit), 2) ?>
            </div>
        <?php else: ?>
            <div style="margin-top: 16px; padding: 12px; background: #def7ec; color: #03543f; border-radius: 6px; font-weight: 500;">
                <i class="fas fa-check-circle"></i> Trial Balance is balanced.
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
