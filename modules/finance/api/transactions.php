<?php
// modules/finance/api/transactions.php
require_once '../../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    if ($method === 'GET') {
        // Stats & List
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $search = $_GET['search'] ?? '';
        $startDate = $_GET['startDate'] ?? '';
        $endDate = $_GET['endDate'] ?? '';
        
        $params = [];
        $where = ["is_active = 1"];
        
        if (!empty($search)) {
            $where[] = "(description LIKE ? OR category LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($startDate)) {
            $where[] = "transaction_date >= ?";
            $params[] = $startDate;
        }
        
        if (!empty($endDate)) {
            $where[] = "transaction_date <= ?";
            $params[] = $endDate;
        }
        
        $whereSql = implode(" AND ", $where);
        
        // Fetch Filtered Transactions
        $listSql = "SELECT * FROM finance_transactions 
                    WHERE $whereSql 
                    ORDER BY transaction_date DESC, created_at DESC 
                    LIMIT $limit";
        $stmt = $pdo->prepare($listSql);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'transactions' => $transactions
        ]);

    } elseif ($method === 'POST') {
        // Add Transaction
        $type = $input['type'] ?? 'debit';
        $amount = $input['amount'] ?? 0;
        $category = $input['category'] ?? 'General';
        $desc = $input['description'] ?? '';
        $date = $input['date'] ?? date('Y-m-d');
        
        if ($amount <= 0) throw new Exception("Invalid amount");
        
        $sql = "INSERT INTO finance_transactions (type, category, amount, description, transaction_date, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$type, $category, $amount, $desc, $date, $userId]);
        
        echo json_encode(['success' => true, 'message' => 'Transaction added']);

    } elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? $input['id'] ?? null;
        if (!$id) throw new Exception("ID required");

        $stmt = $pdo->prepare("UPDATE finance_transactions SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'Transaction deleted']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
