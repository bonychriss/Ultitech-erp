<?php
require_once __DIR__ . '/includes/config.php';

try {
    // Revenue
    $revSum = $pdo->query("SELECT SUM(amount_total) FROM revenue_entries")->fetchColumn() ?: 0;
    $revApprovedSum = $pdo->query("SELECT SUM(amount_total) FROM revenue_entries WHERE approval_status='approved'")->fetchColumn() ?: 0;
    echo "Revenue Entries Total: " . number_format($revSum, 2) . " (Approved: " . number_format($revApprovedSum, 2) . ")\n";

    // Expenses
    $expSum1 = $pdo->query("SELECT SUM(amount) FROM petty_cash_vouchers WHERE status='approved'")->fetchColumn() ?: 0;
    $expSum2 = $pdo->query("SELECT SUM(amount) FROM erp_expenses WHERE status='approved'")->fetchColumn() ?: 0;
    $totalExp = $expSum1 + $expSum2;
    echo "Expenses Total (Petty Cash + ERP): " . number_format($totalExp, 2) . " (Petty Cash: " . number_format($expSum1, 2) . ", ERP: " . number_format($expSum2, 2) . ")\n";

    // Invoices
    $invPaid = $pdo->query("SELECT SUM(amount_paid) FROM invoices")->fetchColumn() ?: 0;
    $invOutstanding = $pdo->query("SELECT SUM(balance_due) FROM invoices WHERE status != 'cancelled'")->fetchColumn() ?: 0;
    $invTotal = $pdo->query("SELECT SUM(total_amount) FROM invoices WHERE status != 'cancelled'")->fetchColumn() ?: 0;
    
    echo "Total Invoice Amount: " . number_format($invTotal, 2) . "\n";
    echo "Paid Invoice Amount: " . number_format($invPaid, 2) . "\n";
    echo "Outstanding Invoice Amount: " . number_format($invOutstanding, 2) . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
