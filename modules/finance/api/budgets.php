<?php
// modules/finance/api/budgets.php
require_once '../../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    if ($method === 'GET') {
        $month = $_GET['month'] ?? date('Y-m');
        
        // 1. Get Budgets
        $budgetSql = "SELECT * FROM finance_budgets WHERE month = ?";
        $stmt = $pdo->prepare($budgetSql);
        $stmt->execute([$month]);
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Get Actual Spending per Category for this month
        $actualSql = "SELECT category, SUM(amount) as total_spent 
                      FROM finance_transactions 
                      WHERE is_active = 1 
                      AND type = 'debit' 
                      AND DATE_FORMAT(transaction_date, '%Y-%m') = ? 
                      GROUP BY category";
        $stmt = $pdo->prepare($actualSql);
        $stmt->execute([$month]);
        $actuals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // 3. Merge
        $report = [];
        foreach ($budgets as $b) {
            $cat = $b['category'];
            $report[] = [
                'category' => $cat,
                'budget' => (float)$b['amount'],
                'actual' => (float)($actuals[$cat] ?? 0),
                'remaining' => (float)$b['amount'] - (float)($actuals[$cat] ?? 0)
            ];
        }
        
        // Add categories that have spending but no budget
        foreach ($actuals as $cat => $spent) {
            $hasBudget = false;
            foreach ($budgets as $b) {
                if ($b['category'] === $cat) { $hasBudget = true; break; }
            }
            if (!$hasBudget) {
                $report[] = [
                    'category' => $cat,
                    'budget' => 0,
                    'actual' => (float)$spent,
                    'remaining' => -(float)$spent
                ];
            }
        }

        echo json_encode([
            'month' => $month,
            'report' => $report
        ]);

    } elseif ($method === 'POST') {
        $category = $input['category'] ?? '';
        $amount = $input['amount'] ?? 0;
        $month = $input['month'] ?? date('Y-m');
        
        if (empty($category)) throw new Exception("Category required");
        if ($amount < 0) throw new Exception("Invalid amount");
        
        $sql = "INSERT INTO finance_budgets (category, amount, month) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE amount = VALUES(amount)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$category, $amount, $month]);
        
        echo json_encode(['success' => true, 'message' => 'Budget updated']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
