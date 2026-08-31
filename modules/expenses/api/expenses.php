<?php
require_once '../../../includes/functions.php';
require_once __DIR__ . '/../includes/balances_integration.php';
requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    if ($method === 'GET') {
        expenses_backfill_pending_records($pdo);

        $type = $_GET['type'] ?? '';

        if ($type === 'pending_vouchers' || $type === 'pending_posting') {
            // Direct expenses awaiting posting only (payment vouchers stay in the vouchers module).
            $stmt = $pdo->query("SELECT id, expense_number as ref, payee, amount, currency_code as currency, status, date, 'receipt' as source_type 
                                FROM erp_expenses 
                                WHERE status = 'approved' AND is_posted = 0
                                  AND " . expenses_receipt_only_sql() . "
                                ORDER BY date DESC");
            $direct = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['data' => $direct]);
            exit;
        }

        if ($type === 'pending_approval') {
            // List Expenses awaiting Admin Approval
            $stmt = $pdo->query("SELECT e.*
                                FROM erp_expenses e
                                WHERE e.status = 'pending'
                                  AND " . expenses_receipt_only_sql('e') . "
                                ORDER BY e.date DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row = expenses_enrich_list_row($pdo, $row);
            }
            unset($row);
            echo json_encode(['data' => $rows]);
            exit;
        }

        // List expenses (all except deleted).
        $filters = expenses_parse_list_filters($_GET);

        $params = [];
        $where = expenses_build_list_where($filters, $params);

        // Count total
        $countQuery = "SELECT COUNT(*) FROM erp_expenses e $where";
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Fetch all matching rows (no pagination).
        $query = "SELECT e.*
                  FROM erp_expenses e
                  $where
                  ORDER BY e.date DESC, e.id DESC
                  LIMIT 5000";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expenses as &$row) {
            $row = expenses_enrich_list_row($pdo, $row);
        }
        unset($row);

        echo json_encode([
            'data' => $expenses,
            'total' => $total,
        ]);

    } elseif ($method === 'POST') {
        $action = $input['action'] ?? '';

        if ($action === 'approve') {
            if (!isAdmin() && !isFinance()) throw new Exception("Unauthorized");
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) throw new Exception("ID required");

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE erp_expenses SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND is_posted = 0");
            $stmt->execute([$_SESSION['user_id'], $id]);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                throw new Exception('Expense not found or already posted.');
            }

            $postResult = expenses_post_erp_expense_row($pdo, $id);
            if (empty($postResult['success'])) {
                $pdo->rollBack();
                throw new Exception((string) ($postResult['message'] ?? 'Could not post expense to balances.'));
            }

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Expense approved and posted to Chart of Accounts.',
                'posted' => true,
            ]);
            exit;
        }

        // Create Expense (Direct Receipt) - New Workflow
        $required = ['date', 'payee', 'account_id', 'amount', 'currency_code', 'source_account_id'];
        foreach ($required as $f) {
            if (empty($input[$f])) throw new Exception("Field $f is required");
        }

        $pdo->beginTransaction();

        $dateStr = date('Ymd', strtotime($input['date']));
        $stmt = $pdo->query("SELECT COUNT(*) FROM erp_expenses WHERE expense_number LIKE 'EXP-$dateStr-%'");
        $count = $stmt->fetchColumn() + 1;
        $expNum = "EXP-$dateStr-" . str_pad($count, 3, '0', STR_PAD_LEFT);

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $stmt = $pdo->prepare("INSERT INTO erp_expenses 
            (expense_number, date, payee, account_id, source_account_id, amount, tax_amount, currency_code, payment_method, description, status, is_posted, source_type, created_by, approved_by, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', 0, 'receipt', ?, ?, NOW())");
        
        $stmt->execute([
            $expNum,
            $input['date'],
            $input['payee'],
            $input['account_id'],
            $input['source_account_id'],
            $input['amount'],
            $input['tax_amount'] ?? 0,
            $input['currency_code'] ?? 'TSh',
            $input['payment_method'] ?? 'cash',
            $input['description'] ?? '',
            $userId,
            $userId,
        ]);
        $expenseId = (int) $pdo->lastInsertId();

        $postResult = expenses_post_erp_expense_row($pdo, $expenseId);
        if (empty($postResult['success'])) {
            $pdo->rollBack();
            throw new Exception((string) ($postResult['message'] ?? 'Could not post expense to balances.'));
        }

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'id' => $expenseId,
            'message' => 'Expense recorded and posted to Chart of Accounts.',
            'posted' => true,
        ]);

    } elseif ($method === 'PUT') {
        $id = $input['id'] ?? null;
        if (!$id) throw new Exception("ID required");

        $fields = ['date', 'payee', 'account_id', 'amount', 'tax_amount', 'currency_code', 'payment_method', 'description', 'source_account_id'];
        $updates = [];
        $params = [];
        
        foreach ($fields as $f) {
            if (isset($input[$f])) {
                $updates[] = "$f = ?";
                $params[] = $input[$f];
            }
        }
        
        if (empty($updates)) throw new Exception("No fields to update");
        
        $params[] = $id;
        $sql = "UPDATE erp_expenses SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Expense updated']);

    } elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? $input['id'] ?? null;
        if (!$id) throw new Exception("ID required");

        $stmt = $pdo->prepare("DELETE FROM erp_expenses WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Expense deleted']);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
