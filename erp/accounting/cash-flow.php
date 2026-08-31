<?php
require_once '../../includes/functions.php';
requireLogin();
global $pdo;

$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate = $_GET['end_date'] ?? date('Y-12-31');

// Cash Flow Logic:
// 1. Operating Activities: Net Income + Depreciation + Changes in Current Assets/Liabilities (AR, AP, Inventory)
// 2. Investing Activities: Purchase/Sale of Fixed Assets
// 3. Financing Activities: Loans, Equity

// Quick Simplification for this ERP:
// We look at movements in "Cash/Bank" accounts and categorize the *OFFSETS* (the other side of the entry).
// - expense/revenue offsets -> Operating
// - fixed asset offsets -> Investing
// - liability/equity offsets -> Financing

// Actually, the Direct Method is hard. Indirect Method starting from Net Income is standard.
// Let's use Indirect Method:
// Net Income
// + Depreciation (Non-cash)
// - Increase in AR
// + Decrease in AP
// - Increase in Inventory

// Step 1: Calculate Net Income
$sqlIncome = "SELECT 
    (SELECT COALESCE(SUM(ji.credit - ji.debit), 0) FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id JOIN erp_journal_entries je ON ji.journal_id = je.id WHERE a.type = 'revenue' AND je.date BETWEEN ? AND ?) -
    (SELECT COALESCE(SUM(ji.debit - ji.credit), 0) FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id JOIN erp_journal_entries je ON ji.journal_id = je.id WHERE a.type = 'expense' AND je.date BETWEEN ? AND ?) 
    as net_income";
$stmt = $pdo->prepare($sqlIncome);
$stmt->execute([$startDate, $endDate, $startDate, $endDate]);
$netIncome = $stmt->fetchColumn();

// Step 2: Calculate Changes in Working Capital (Assets = debit increases, Liab = credit increases)
// For Assets: Change = End Bal - Start Bal. But for Cash Flow: Increase in Asset = Outflow (-)
// So we want (Start Bal - End Bal) or simply -(Debit - Credit) over the period.

// Change in AR (Asset)
$stmt = $pdo->prepare("SELECT SUM(ji.debit - ji.credit) FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id JOIN erp_journal_entries je ON ji.journal_id = je.id WHERE a.code LIKE '1200%' AND je.date BETWEEN ? AND ?"); // Assuming 1200 is AR
$stmt->execute([$startDate, $endDate]);
$changeAR = $stmt->fetchColumn() ?: 0;

// Change in Inventory (Asset)
$stmt = $pdo->prepare("SELECT SUM(ji.debit - ji.credit) FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id JOIN erp_journal_entries je ON ji.journal_id = je.id WHERE a.code LIKE '1300%' AND je.date BETWEEN ? AND ?"); // Assuming 1300 is Inventory
$stmt->execute([$startDate, $endDate]);
$changeInventory = $stmt->fetchColumn() ?: 0;

// Change in AP (Liability) -> Increase = Inflow (+)
$stmt = $pdo->prepare("SELECT SUM(ji.credit - ji.debit) FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id JOIN erp_journal_entries je ON ji.journal_id = je.id WHERE a.code LIKE '2000%' AND je.date BETWEEN ? AND ?"); // Assuming 2000 is AP
$stmt->execute([$startDate, $endDate]);
$changeAP = $stmt->fetchColumn() ?: 0;

// Cash Flow from Operations
$cfo = $netIncome - $changeAR - $changeInventory + $changeAP; 

// Cash Flow from Investing (Fixed Assets 1500) -> Increase = Outflow (-)
$stmt = $pdo->prepare("SELECT SUM(ji.debit - ji.credit) FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id JOIN erp_journal_entries je ON ji.journal_id = je.id WHERE a.code LIKE '1500%' AND je.date BETWEEN ? AND ?");
$stmt->execute([$startDate, $endDate]);
$changeFixedAssets = $stmt->fetchColumn() ?: 0;
$cfi = -$changeFixedAssets;

// Cash Flow from Financing (Loans 2500, Equity 3000) -> Increase = Inflow (+)
$stmt = $pdo->prepare("SELECT SUM(ji.credit - ji.debit) FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id JOIN erp_journal_entries je ON ji.journal_id = je.id WHERE (a.code LIKE '2500%' OR a.code LIKE '3000%') AND je.date BETWEEN ? AND ?");
$stmt->execute([$startDate, $endDate]);
$cff = $stmt->fetchColumn() ?: 0;

$netChange = $cfo + $cfi + $cff;

// Cash at Beginning
$stmt = $pdo->prepare("SELECT SUM(ji.debit - ji.credit) FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id JOIN erp_journal_entries je ON ji.journal_id = je.id WHERE a.type = 'asset' AND (a.name LIKE '%Bank%' OR a.name LIKE '%Cash%') AND je.date < ?");
$stmt->execute([$startDate]);
$startCash = $stmt->fetchColumn() ?: 0;

$endCash = $startCash + $netChange;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cash Flow - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
         body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
         .page-wrapper { margin-left: 220px; padding: 30px; }
         .card { background: white; padding: 30px; border-radius: 8px; max-width: 800px; margin: 0 auto; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
         .title { font-size: 1.5rem; font-weight: bold; text-align: center; margin-bottom: 5px; }
         .subtitle { text-align: center; color: #666; margin-bottom: 30px; }
         .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
         .row.header { font-weight: bold; background: #f9fafb; padding: 10px; border-bottom: 2px solid #eee; }
         .row.total { font-weight: bold; border-top: 2px solid #000; border-bottom: none; font-size: 1.1rem; padding-top: 15px; margin-top: 10px; }
         .section-head { font-weight: bold; color: #2563eb; margin-top: 20px; margin-bottom: 10px; text-transform: uppercase; font-size: 0.9rem; }
         .indent { padding-left: 20px; }
         .amount { font-family: monospace; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div class="card">
        <div class="title">Statement of Cash Flows</div>
        <div class="subtitle">For the period <?= date('M d, Y', strtotime($startDate)) ?> to <?= date('M d, Y', strtotime($endDate)) ?></div>

        <!-- Operating -->
        <div class="section-head">Operating Activities</div>
        <div class="row"><div class="indent">Net Income</div><div class="amount"><?= number_format($netIncome, 2) ?></div></div>
        <div class="row"><div class="indent">Adjustments for Working Capital:</div><div></div></div>
        <div class="row"><div class="indent">(Increase)/Decrease in Receivables</div><div class="amount"><?= number_format(-$changeAR, 2) ?></div></div>
        <div class="row"><div class="indent">(Increase)/Decrease in Inventory</div><div class="amount"><?= number_format(-$changeInventory, 2) ?></div></div>
        <div class="row"><div class="indent">Increase/(Decrease) in Payables</div><div class="amount"><?= number_format($changeAP, 2) ?></div></div>
        <div class="row total"><div>Net Cash from Operating Activities</div><div class="amount"><?= number_format($cfo, 2) ?></div></div>

        <!-- Investing -->
        <div class="section-head">Investing Activities</div>
        <div class="row"><div class="indent">Purchase/Sale of Fixed Assets</div><div class="amount"><?= number_format(-$changeFixedAssets, 2) ?></div></div>
        <div class="row total"><div>Net Cash from Investing Activities</div><div class="amount"><?= number_format($cfi, 2) ?></div></div>

        <!-- Financing -->
        <div class="section-head">Financing Activities</div>
        <div class="row"><div class="indent">Loans / Equity</div><div class="amount"><?= number_format($cff, 2) ?></div></div>
        <div class="row total"><div>Net Cash from Financing Activities</div><div class="amount"><?= number_format($cff, 2) ?></div></div>

        <!-- Summary -->
        <br>
        <div class="row total" style="border-top: 4px double #000;"><div>Net Increase in Cash</div><div class="amount"><?= number_format($netChange, 2) ?></div></div>
        <div class="row"><div>Cash at Beginning of Period</div><div class="amount"><?= number_format($startCash, 2) ?></div></div>
        <div class="row total"><div>Cash at End of Period</div><div class="amount"><?= number_format($endCash, 2) ?></div></div>
    </div>
</div>
</body>
</html>
