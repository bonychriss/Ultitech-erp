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
        if (empty($_POST['date']) || empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('Date and items are required');
        }
        
        $pdo->beginTransaction();
        
        // Generate JE number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(entry_number, 4) AS UNSIGNED)) FROM erp_journal_entries");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $jeNumber = 'JE-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
        
        // Insert JE header
        $sql = "INSERT INTO erp_journal_entries (entry_number, date, description, created_by) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $jeNumber,
            $_POST['date'],
            $_POST['description'] ?? null,
            $_SESSION['user_id']
        ]);
        $jeId = $pdo->lastInsertId();
        
        // Insert JE items and validate balance
        $totalDebit = 0;
        $totalCredit = 0;
        
        $stmt = $pdo->prepare("INSERT INTO erp_journal_items (journal_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
        
        foreach ($_POST['items'] as $item) {
            $debit = floatval($item['debit'] ?? 0);
            $credit = floatval($item['credit'] ?? 0);
            
            $stmt->execute([
                $jeId,
                $item['account_id'],
                $debit,
                $credit
            ]);
            
            $totalDebit += $debit;
            $totalCredit += $credit;
        }
        
        // Validate balance
        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new Exception('Debits must equal credits');
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Journal entry created successfully', 'id' => $jeId]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
