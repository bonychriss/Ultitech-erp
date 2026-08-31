<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    $status = $_POST['status'] ?? '';

    if (empty($ids)) {
        echo json_encode(['status' => 'error', 'message' => 'No emails selected']);
        exit;
    }

    if (!in_array($status, ['trash', 'archived', 'inbox', 'read', 'unread'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
        exit;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE module_emails SET status = ? WHERE id IN ($placeholders)");
        
        $params = array_merge([$status], $ids);
        $stmt->execute($params);

        echo json_encode([
            'status' => 'success', 
            'message' => count($ids) . ' emails moved to ' . $status
        ]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
