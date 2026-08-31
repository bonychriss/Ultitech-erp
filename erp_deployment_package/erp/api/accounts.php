<?php
require_once '../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    
    if ($action === 'create') {
        if (empty($_POST['code']) || empty($_POST['name']) || empty($_POST['type'])) {
            throw new Exception('Code, name, and type are required');
        }
        
        $sql = "INSERT INTO erp_accounts (code, name, type, description, is_system) VALUES (?, ?, ?, ?, 0)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['code'],
            $_POST['name'],
            $_POST['type'],
            $_POST['description'] ?? null
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Account created successfully']);
        
    } elseif ($action === 'update') {
        if (empty($_POST['id']) || empty($_POST['code']) || empty($_POST['name']) || empty($_POST['type'])) {
            throw new Exception('ID, code, name, and type are required']);
        }
        
        $sql = "UPDATE erp_accounts SET code = ?, name = ?, type = ?, description = ? WHERE id = ? AND is_system = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['code'],
            $_POST['name'],
            $_POST['type'],
            $_POST['description'] ?? null,
            $_POST['id']
        ]);
        
        if ($stmt->rowCount() == 0) {
            throw new Exception('Cannot update system account or account not found');
        }
        
        echo json_encode(['success' => true, 'message' => 'Account updated successfully']);
        
    } elseif ($action === 'delete') {
        if (empty($_POST['id'])) {
            throw new Exception('ID is required');
        }
        
        // Check if account is used
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_journal_items WHERE account_id = ?");
        $stmt->execute([$_POST['id']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot delete account with transactions');
        }
        
        $stmt = $pdo->prepare("DELETE FROM erp_accounts WHERE id = ? AND is_system = 0");
        $stmt->execute([$_POST['id']]);
        
        if ($stmt->rowCount() == 0) {
            throw new Exception('Cannot delete system account or account not found');
        }
        
        echo json_encode(['success' => true, 'message' => 'Account deleted successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
