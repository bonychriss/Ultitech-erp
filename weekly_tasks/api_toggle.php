<?php
require_once '../includes/functions.php';
ensureWeeklyTasksSchema();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$itemId = (int)($input['item_id'] ?? 0);
$completed = (bool)($input['completed'] ?? false);
$userId = $_SESSION['user_id'];

try {
    // limit check to ownership via join
    $stmt = $pdo->prepare("
        SELECT i.id, p.user_id 
        FROM weekly_plan_items i
        JOIN weekly_plans p ON i.plan_id = p.id
        WHERE i.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if (!$item || $item['user_id'] != $userId) {
        echo json_encode(['success' => false, 'error' => 'Task not found or owned by you']);
        exit;
    }

    // Update status
    $sql = "UPDATE weekly_plan_items SET is_completed = ?, completed_at = ? WHERE id = ?";
    $now = $completed ? date('Y-m-d H:i:s') : null;
    $pdo->prepare($sql)->execute([$completed ? 1 : 0, $now, $itemId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
