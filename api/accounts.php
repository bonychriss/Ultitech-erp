<?php
$functions_path = __DIR__ . '/../includes/functions.php';
if (!file_exists($functions_path)) {
    $functions_path = __DIR__ . '/../../includes/functions.php';
}
require_once $functions_path;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    $journalAccountCol = resolveExistingColumn('erp_journal_items', 'account_id', ['gl_account_id', 'account']);
    
    if ($action === 'list') {
        // Fetch all accounts
        $stmt = $pdo->query("SELECT * FROM erp_accounts ORDER BY code ASC, id ASC");
        $allAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'accounts' => $allAccounts]);
        exit;
        
    } elseif ($action === 'get_next_code') {
        $type = trim(strtolower($_POST['type'] ?? ''));
        if (empty($type)) throw new Exception('Type is required');
        
        $ranges = [
            'asset' => '1',
            'liability' => '2',
            'equity' => '3',
            'revenue' => '4',
            'expense' => '5'
        ];
        
        $prefix = $ranges[$type] ?? '9'; // Default to 9 for unknown
        
        // Find the maximum numeric code starting with the prefix
        $stmt = $pdo->prepare("SELECT MAX(CAST(code AS UNSIGNED)) FROM erp_accounts WHERE code LIKE ? AND code REGEXP '^[0-9]+$'");
        $stmt->execute([$prefix . '%']);
        $maxCode = (int)$stmt->fetchColumn();
        
        if ($maxCode === 0) {
            $nextCode = $prefix . '001';
        } else {
            $nextCode = $maxCode + 1;
        }
        
        echo json_encode(['success' => true, 'next_code' => (string)$nextCode]);
        exit;
        
    } elseif ($action === 'create') {
        if (empty($_POST['name']) || empty($_POST['type'])) {
            throw new Exception('Name and type are required');
        }
        
        $code = trim($_POST['code'] ?? '');
        $type = trim(strtolower($_POST['type']));
        $parentId = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
        
        // If code is not provided, generate next code automatically
        if (empty($code)) {
            $ranges = [
                'asset' => '1',
                'liability' => '2',
                'equity' => '3',
                'revenue' => '4',
                'expense' => '5'
            ];
            $prefix = $ranges[$type] ?? '9';
            $stmt = $pdo->prepare("SELECT MAX(CAST(code AS UNSIGNED)) FROM erp_accounts WHERE code LIKE ? AND code REGEXP '^[0-9]+$'");
            $stmt->execute([$prefix . '%']);
            $maxCode = (int)$stmt->fetchColumn();
            if ($maxCode === 0) {
                $code = $prefix . '001';
            } else {
                $code = (string)($maxCode + 1);
            }
        }
        
        $sql = "INSERT INTO erp_accounts (code, name, type, description, parent_id, is_system) VALUES (?, ?, ?, ?, ?, 0)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $code,
            $_POST['name'],
            $_POST['type'],
            $_POST['description'] ?? null,
            $parentId
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Account created successfully', 'id' => $pdo->lastInsertId()]);
        exit;
        
    } elseif ($action === 'update') {
        if (empty($_POST['id']) || empty($_POST['code']) || empty($_POST['name']) || empty($_POST['type'])) {
            throw new Exception('ID, code, name, and type are required');
        }
        
        $parentId = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
        
        $sql = "UPDATE erp_accounts SET code = ?, name = ?, type = ?, description = ?, parent_id = ? WHERE id = ? AND is_system = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['code'],
            $_POST['name'],
            $_POST['type'],
            $_POST['description'] ?? null,
            $parentId,
            $_POST['id']
        ]);
        
        if ($stmt->rowCount() == 0) {
            // Check if it exists at all
            $chk = $pdo->prepare("SELECT is_system FROM erp_accounts WHERE id = ?");
            $chk->execute([$_POST['id']]);
            $acc = $chk->fetch();
            if (!$acc) {
                throw new Exception('Account not found');
            } elseif ($acc['is_system']) {
                throw new Exception('Cannot update system account');
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Account updated successfully']);
        exit;
        
    } elseif ($action === 'delete') {
        if (empty($_POST['id'])) {
            throw new Exception('ID is required');
        }
        if (!$journalAccountCol) {
            throw new Exception('Journal items account column not found in database.');
        }
        
        $id = $_POST['id'];
        
        // 1. Check if the account is a system account
        $stmt = $pdo->prepare("SELECT is_system FROM erp_accounts WHERE id = ?");
        $stmt->execute([$id]);
        $acc = $stmt->fetch();
        if (!$acc) {
            throw new Exception('Account not found');
        }
        if ($acc['is_system']) {
            throw new Exception('Cannot delete system accounts');
        }
        
        // 2. Check if it has child accounts
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_accounts WHERE parent_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot delete account with sub-accounts. Delete the sub-accounts first.');
        }
        
        // 3. Check if account is used in journal items
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_journal_items WHERE {$journalAccountCol} = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot delete account with transaction history');
        }
        
        // 4. Delete
        $stmt = $pdo->prepare("DELETE FROM erp_accounts WHERE id = ? AND is_system = 0");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'Account deleted successfully']);
        exit;
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
