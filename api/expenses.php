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
    $expenseAccountCol = resolveExistingColumn('erp_expenses', 'account_id', ['gl_account_id', 'expense_account_id']);
    $journalAccountCol = resolveExistingColumn('erp_journal_items', 'account_id', ['gl_account_id', 'account']);
    
    if ($action === 'create') {
        if (empty($_POST['date']) || empty($_POST['account_id']) || empty($_POST['amount'])) {
            throw new Exception('Date, account, and amount are required');
        }
        
        $pdo->beginTransaction();
        
        // Generate expense number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(expense_number, 5) AS UNSIGNED)) FROM erp_expenses");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $expenseNumber = 'EXP-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
        
        // Insert expense
        if (!$expenseAccountCol) {
            throw new Exception('Expense account column not found in database.');
        }
        if (!$journalAccountCol) {
            throw new Exception('Journal items account column not found in database.');
        }

        $sql = "INSERT INTO erp_expenses (expense_number, date, payee, {$expenseAccountCol}, amount, payment_method, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $expenseNumber,
            $_POST['date'],
            $_POST['payee'] ?? null,
            $_POST['account_id'],
            floatval($_POST['amount']),
            $_POST['payment_method'] ?? 'cash',
            $_POST['description'] ?? null,
            $_SESSION['user_id']
        ]);
        
        // Create automatic journal entry
        $jeNumber = 'JE-EXP-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("INSERT INTO erp_journal_entries (entry_number, date, description, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $jeNumber,
            $_POST['date'],
            'Expense: ' . ($_POST['payee'] ?? 'N/A'),
            $_SESSION['user_id']
        ]);
        $jeId = $pdo->lastInsertId();
        
        // Get Cash account (1000)
        $stmt = $pdo->query("SELECT id FROM erp_accounts WHERE code = '1000'");
        $cashAccId = $stmt->fetchColumn();
        
        // Debit expense account
        $stmt = $pdo->prepare("INSERT INTO erp_journal_items (journal_id, {$journalAccountCol}, debit, credit) VALUES (?, ?, ?, 0)");
        $stmt->execute([$jeId, $_POST['account_id'], floatval($_POST['amount'])]);
        
        // Credit cash
        if ($cashAccId) {
            $stmt = $pdo->prepare("INSERT INTO erp_journal_items (journal_id, {$journalAccountCol}, debit, credit) VALUES (?, ?, 0, ?)");
            $stmt->execute([$jeId, $cashAccId, floatval($_POST['amount'])]);
        }
        
        // Update Voucher is_posted status if linked
        if (!empty($_POST['voucher_id'])) {
            $vid = (int)$_POST['voucher_id'];
            $pdo->prepare("UPDATE payment_vouchers SET is_posted = 1 WHERE id = ?")->execute([$vid]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Expense recorded successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
