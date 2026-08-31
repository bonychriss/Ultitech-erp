<?php
require_once '../../includes/functions.php';

global $pdo;

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create_account') {
        $stmt = $pdo->prepare("INSERT INTO erp_bank_accounts (account_name, account_number, bank_name, branch, currency, opening_balance, current_balance, gl_account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['account_name'],
            $_POST['account_number'],
            $_POST['bank_name'],
            $_POST['branch'],
            $_POST['currency'],
            $_POST['opening_balance'],
            $_POST['opening_balance'], // current_balance starts as opening
            $_POST['gl_account_id'] ?: null
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    }
    
    elseif ($action === 'add_transaction') {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO erp_bank_transactions (bank_account_id, transaction_date, description, reference, debit, credit, balance, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['bank_account_id'],
            $_POST['transaction_date'],
            $_POST['description'],
            $_POST['reference'],
            $_POST['debit'] ?: 0,
            $_POST['credit'] ?: 0,
            $_POST['balance'],
            $_SESSION['user_id']
        ]);
        
        // Update bank account balance
        $amount = ($_POST['credit'] ?: 0) - ($_POST['debit'] ?: 0);
        $stmt = $pdo->prepare("UPDATE erp_bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
        $stmt->execute([$amount, $_POST['bank_account_id']]);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    }
    
    elseif ($action === 'reconcile') {
        $stmt = $pdo->prepare("UPDATE erp_bank_transactions SET reconciled = 1, reconciled_date = CURDATE() WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true]);
    }
    
    else {
        throw new Exception("Invalid action");
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
