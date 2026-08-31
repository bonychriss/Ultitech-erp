<?php
// modules/finance/api/stats.php
require_once '../../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // 1. Total Income & Expenses
    $statsSql = "SELECT 
        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as total_expenses
        FROM finance_transactions WHERE is_active = 1";
    $stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);

    $income = (float)($stats['total_income'] ?? 0);
    $expenses = (float)($stats['total_expenses'] ?? 0);
    $balance = $income - $expenses;

    // 2. Monthly Trend (Last 6 Months)
    $trendSql = "SELECT 
        DATE_FORMAT(transaction_date, '%Y-%m') as month,
        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as expense
        FROM finance_transactions 
        WHERE is_active = 1 AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ORDER BY month ASC";
    $stmt = $pdo->query($trendSql);
    $trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Current Month Budget vs Actual Summary
    $currentMonth = date('Y-m');
    $budgetSummarySql = "
        SELECT 
            COALESCE(SUM(b.amount), 0) as total_budget,
            (SELECT COALESCE(SUM(amount), 0) 
             FROM finance_transactions 
             WHERE is_active = 1 AND type = 'debit' 
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?) as total_spent
        FROM finance_budgets b
        WHERE b.month = ?";
    $stmt = $pdo->prepare($budgetSummarySql);
    $stmt->execute([$currentMonth, $currentMonth]);
    $budgetSummary = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'summary' => [
            'income' => $income,
            'expenses' => $expenses,
            'balance' => $balance
        ],
        'trend' => $trend,
        'budgetStatus' => [
            'month' => $currentMonth,
            'totalBudget' => (float)$budgetSummary['total_budget'],
            'totalSpent' => (float)$budgetSummary['total_spent'],
            'percentage' => $budgetSummary['total_budget'] > 0 
                ? round(($budgetSummary['total_spent'] / $budgetSummary['total_budget']) * 100, 1) 
                : 0
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
