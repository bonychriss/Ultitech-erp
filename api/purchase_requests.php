<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../includes/functions.php';
require_once '../includes/ActivityLogger.php';

ob_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    $logger = new ActivityLogger($pdo);

    if ($action === 'create') {
        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('Items are required');
        }

        $pdo->beginTransaction();

        // Generate PR number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(request_number, 4) AS UNSIGNED)) FROM erp_purchase_requests");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $prNumber = 'PR-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);

        // Calculate total
        $totalEstimated = 0;
        foreach ($_POST['items'] as $item) {
            $qty = floatval($item['quantity']);
            $cost = floatval($item['estimated_unit_cost']);
            $totalEstimated += ($qty * $cost);
        }

        // Insert Header
        $sql = "INSERT INTO erp_purchase_requests (request_number, request_date, requested_by, department, status, notes, total_estimated_cost) VALUES (?, ?, ?, ?, 'pending_approval', ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $prNumber,
            date('Y-m-d'),
            $_SESSION['user_id'],
            $_SESSION['department'] ?? 'General',
            $_POST['notes'] ?? null,
            $totalEstimated
        ]);
        $prId = $pdo->lastInsertId();

        // Insert Items
        $stmt = $pdo->prepare("INSERT INTO erp_pr_items (pr_id, product_name, quantity, unit, estimated_unit_cost, total_cost, product_id) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($_POST['items'] as $item) {
            $qty = floatval($item['quantity']);
            $cost = floatval($item['estimated_unit_cost']);
            $total = $qty * $cost;
            $productId = !empty($item['product_id']) ? $item['product_id'] : null;

            $stmt->execute([
                $prId,
                $item['product_name'], // Can be free text or populated from product name
                $qty,
                $item['unit'] ?? 'pcs',
                $cost,
                $total,
                $productId
            ]);
        }

        $pdo->commit();
        
        // Log
        $logger->log('purchase_request', $prId, 'created', "Purchase Request $prNumber created");

        echo json_encode(['success' => true, 'message' => 'Purchase Request created successfully', 'id' => $prId]);

    } elseif ($action === 'approve') {
        if (empty($_POST['id'])) throw new Exception('ID required');
        if (!isAdmin() && !isFinance()) throw new Exception('Unauthorized'); // Basic check

        $pdo->prepare("UPDATE erp_purchase_requests SET status = 'approved', approval_level = 1 WHERE id = ?")->execute([$_POST['id']]);
        echo json_encode(['success' => true, 'message' => 'PR Approved']);

    } elseif ($action === 'reject') {
        if (empty($_POST['id'])) throw new Exception('ID required');
        if (!isAdmin() && !isFinance()) throw new Exception('Unauthorized');

        $pdo->prepare("UPDATE erp_purchase_requests SET status = 'rejected' WHERE id = ?")->execute([$_POST['id']]);
        echo json_encode(['success' => true, 'message' => 'PR Rejected']);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Throwable $e) {
    ob_clean();
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
