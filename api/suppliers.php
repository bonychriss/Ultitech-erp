<?php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    
    if ($action === 'create') {
        // Validate required fields
        if (empty($_POST['name'])) {
            throw new Exception('Supplier name is required');
        }
        
        // Check for duplicate code
        $stmt = $pdo->prepare("SELECT id FROM erp_suppliers WHERE supplier_code = ?");
        $stmt->execute([$_POST['supplier_code']]);
        if ($stmt->fetch()) {
            throw new Exception('Supplier code already exists');
        }
        
        // Insert supplier
        $sql = "INSERT INTO erp_suppliers (
            supplier_code, name, contact_person, email, phone, 
            address, tax_id, payment_terms
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['supplier_code'],
            $_POST['name'],
            $_POST['contact_person'] ?? null,
            $_POST['email'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['address'] ?? null,
            $_POST['tax_id'] ?? null,
            $_POST['payment_terms'] ?? null
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Supplier created successfully', 'id' => $pdo->lastInsertId()]);
        
    } elseif ($action === 'update') {
        // Validate required fields
        if (empty($_POST['id']) || empty($_POST['name'])) {
            throw new Exception('Supplier ID and name are required');
        }
        
        $sql = "UPDATE erp_suppliers SET 
            name = ?, contact_person = ?, email = ?, phone = ?, 
            address = ?, tax_id = ?, payment_terms = ?, status = ?
            WHERE id = ?";
            
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['name'],
            $_POST['contact_person'] ?? null,
            $_POST['email'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['address'] ?? null,
            $_POST['tax_id'] ?? null,
            $_POST['payment_terms'] ?? null,
            $_POST['status'] ?? 'active',
            $_POST['id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Supplier updated successfully']);
        
    } elseif ($action === 'delete') {
        if (empty($_POST['id'])) {
            throw new Exception('Supplier ID is required');
        }
        
        // Check if supplier has POs
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_purchase_orders WHERE supplier_id = ?");
        $stmt->execute([$_POST['id']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot delete supplier with existing purchase orders');
        }
        
        $stmt = $pdo->prepare("DELETE FROM erp_suppliers WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Supplier deleted successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
