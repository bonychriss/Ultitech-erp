<?php
require_once '../../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("SELECT id, name FROM payees WHERE is_active = 1 ORDER BY name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        
        if (!$name) throw new Exception("Name required");
        
        // Check duplicate
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payees WHERE name = ? AND is_active = 1");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Payee exists");
        
        $stmt = $pdo->prepare("INSERT INTO payees (name, created_at, created_by, is_active) VALUES (?, NOW(), ?, 1)");
        $stmt->execute([$name, $_SESSION['user_id']]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name]);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
