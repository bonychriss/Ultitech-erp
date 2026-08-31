<?php
require_once '../includes/functions.php';
if (file_exists(__DIR__ . '/setup_schema.php')) {
    require_once __DIR__ . '/setup_schema.php';
    if (function_exists('ensureAccountingSchema')) {
        ensureAccountingSchema();
    }
}
global $pdo;

$sqlRev = "SELECT COALESCE(SUM(ji.credit) - SUM(ji.debit), 0) FROM erp_accounts a JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'revenue'";
$totalRevenue = $pdo->query($sqlRev)->fetchColumn();
$sqlExp = "SELECT COALESCE(SUM(ji.debit) - SUM(ji.credit), 0) FROM erp_accounts a JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'expense'";
$totalExpense = $pdo->query($sqlExp)->fetchColumn();
$netIncome = $totalRevenue - $totalExpense;

$assets = $pdo->query("SELECT a.name, COALESCE(SUM(ji.debit) - SUM(ji.credit), 0) as balance FROM erp_accounts a JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'asset' GROUP BY a.id, a.name HAVING balance != 0")->fetchAll();
$totalAssets = array_sum(array_column($assets, 'balance'));

$liabilities = $pdo->query("SELECT a.name, COALESCE(SUM(ji.credit) - SUM(ji.debit), 0) as balance FROM erp_accounts a JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'liability' GROUP BY a.id, a.name HAVING balance != 0")->fetchAll();
$totalLiabilities = array_sum(array_column($liabilities, 'balance'));

$equity = $pdo->query("SELECT a.name, COALESCE(SUM(ji.credit) - SUM(ji.debit), 0) as balance FROM erp_accounts a JOIN erp_journal_items ji ON a.id = ji.account_id WHERE a.type = 'equity' GROUP BY a.id, a.name HAVING balance != 0")->fetchAll();
$totalEquityAccounts = array_sum(array_column($equity, 'balance'));
$totalEquity = $totalEquityAccounts + $netIncome;

$page_title = 'Balance Sheet';

$rootPath = '/';
$logoBase = '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Balance Sheet - ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <style>
        .bs-shell {
            min-width: 0;
            padding-bottom: env(safe-area-inset-bottom, 0px);
        }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .bs-card {
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            padding: 1rem;
            max-width: 1000px;
            margin: 0 auto;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        @media (min-width: 576px) {
            .bs-card { padding: 1.5rem; }
        }
        @media (min-width: 768px) {
            .bs-card { padding: 2.5rem; margin: 20px auto; }
        }
        .report-header { text-align: center; margin-bottom: 1.25rem; }
        @media (min-width: 768px) {
            .report-header { margin-bottom: 40px; }
        }
        .report-header h1 {
            font-size: clamp(1.05rem, 4.5vw, 1.8rem);
            font-weight: 700;
            margin-bottom: 5px;
            text-transform: uppercase;
            line-height: 1.25;
            word-break: break-word;
        }
        .report-header h2 { font-size: clamp(0.95rem, 3.5vw, 1.1rem); color: #666; font-weight: 400; }
        .report-header .text-muted { font-size: clamp(0.8rem, 3vw, 0.95rem); }

        .section-title {
            font-weight: 700;
            font-size: clamp(1rem, 3.5vw, 1.15rem);
            color: #111;
            border-bottom: 2px solid #333;
            padding-bottom: 8px;
            margin: 1.25rem 0 12px;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        @media (min-width: 768px) {
            .section-title { margin: 30px 0 15px; }
        }

        .line-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.35rem 0.75rem;
            align-items: start;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: clamp(0.875rem, 3.2vw, 1rem);
        }
        .line-item .bs-name {
            word-break: break-word;
            overflow-wrap: anywhere;
            min-width: 0;
        }
        .line-item .bs-amt {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .retained-earnings { font-style: italic; color: #555; }

        .subtotal {
            font-weight: 700;
            background: #fcfcfc;
            padding: 12px;
            margin: 15px 0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
            flex-wrap: wrap;
            border-top: 2px solid #ccc;
            font-size: clamp(0.9rem, 3.2vw, 1rem);
        }
        .subtotal span:last-child { white-space: nowrap; font-variant-numeric: tabular-nums; }

        .grand-total {
            background: #212529;
            color: white;
            padding: 1rem 1.1rem;
            margin-top: 1.25rem;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0.65rem;
            font-size: clamp(1rem, 3.5vw, 1.2rem);
            font-weight: 700;
            border-radius: 8px;
        }
        @media (min-width: 576px) {
            .grand-total {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 15px 25px;
                margin-top: 30px;
            }
        }
        .grand-total span:last-child {
            text-align: right;
            font-size: clamp(0.9rem, 3.2vw, 1.1rem);
        }
    </style>
</head>
<body class="dashboard">

<?php include '../includes/header_employee.php'; ?>

<main class="bs-shell container-fluid px-2 px-sm-3 px-md-4 py-3">
    <div class="bs-card">
        <div class="report-header">
            <h1><?= htmlspecialchars(COMPANY_NAME) ?></h1>
            <h2>Balance Sheet</h2>
            <div class="text-muted">As of <?= date('F d, Y') ?></div>
        </div>

        <div class="row g-4 g-md-0">
            <div class="col-12 col-md-6 pe-md-4">
                <div class="section-title">Assets</div>
                <?php foreach ($assets as $asset): ?>
                <div class="line-item">
                    <span class="bs-name"><?= htmlspecialchars($asset['name']) ?></span>
                    <span class="bs-amt">TSh <?= number_format($asset['balance'], 2) ?></span>
                </div>
                <?php endforeach; ?>
                <div class="subtotal mt-4 mt-md-5">
                    <span>Total Assets</span>
                    <span>TSh <?= number_format($totalAssets, 2) ?></span>
                </div>
            </div>

            <div class="col-12 col-md-6 ps-md-4">
                <div class="section-title">Liabilities</div>
                <?php foreach ($liabilities as $lia): ?>
                <div class="line-item">
                    <span class="bs-name"><?= htmlspecialchars($lia['name']) ?></span>
                    <span class="bs-amt">TSh <?= number_format($lia['balance'], 2) ?></span>
                </div>
                <?php endforeach; ?>

                <div class="section-title mt-4 mt-md-5">Equity</div>
                <?php foreach ($equity as $eq): ?>
                <div class="line-item">
                    <span class="bs-name"><?= htmlspecialchars($eq['name']) ?></span>
                    <span class="bs-amt">TSh <?= number_format($eq['balance'], 2) ?></span>
                </div>
                <?php endforeach; ?>
                <div class="line-item retained-earnings">
                    <span class="bs-name">Retained Earnings (Net Income)</span>
                    <span class="bs-amt">TSh <?= number_format($netIncome, 2) ?></span>
                </div>

                <div class="subtotal mt-4">
                    <span>Total Liabilities &amp; Equity</span>
                    <span>TSh <?= number_format($totalLiabilities + $totalEquity, 2) ?></span>
                </div>
            </div>
        </div>

        <div class="grand-total">
            <span>Financial Position Status</span>
            <span>
                <?php if (round($totalAssets, 2) == round($totalLiabilities + $totalEquity, 2)): ?>
                    <i class="fa fa-check-circle me-2" aria-hidden="true"></i> Balanced
                <?php else: ?>
                    <i class="fa fa-exclamation-triangle me-2" aria-hidden="true"></i> Out of Balance
                <?php endif; ?>
            </span>
        </div>
    </div>
</main>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
