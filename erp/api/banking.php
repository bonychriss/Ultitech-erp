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

    if ($action === 'reconcile') {
        if (empty($_POST['transaction_id'])) {
            throw new Exception('Transaction ID is required');
        }

        $txnId = $_POST['transaction_id'];

        $pdo->beginTransaction();

        // 1. Mark Transaction as Reconciled
        $stmt = $pdo->prepare("UPDATE erp_bank_transactions SET reconciled = 1, reconciled_date = CURDATE() WHERE id = ?");
        $stmt->execute([$txnId]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Transaction not found or already reconciled');
        }

        // 2. Find linked Invoice via erp_invoice_payments
        // We look for payments linked to this transaction
        $stmtLink = $pdo->prepare("SELECT invoice_id FROM erp_invoice_payments WHERE bank_transaction_id = ?");
        $stmtLink->execute([$txnId]);
        $payments = $stmtLink->fetchAll();

        foreach ($payments as $payment) {
            $invoiceId = $payment['invoice_id'];

            // 3. Update Invoice Status to 'paid'
            // Only if it was 'in_payment'. If 'partial', it remains 'partial' until fully paid?
            // Odoo logic: If the payment covers the full amount, it becomes Paid.
            // Our register_payment logic already set it to 'in_payment' if balance <= 0.
            // So we just check if balance <= 0, then set to 'paid'.

            $stmtInv = $pdo->prepare("SELECT balance, status FROM erp_invoices WHERE id = ?");
            $stmtInv->execute([$invoiceId]);
            $inv = $stmtInv->fetch();

            if ($inv && $inv['balance'] <= 0 && $inv['status'] === 'in_payment') {
                $pdo->prepare("UPDATE erp_invoices SET status = 'paid' WHERE id = ?")->execute([$invoiceId]);
            }
        }

        // 5. Trigger GL Reconciliation (Clear Suspense -> Debit Bank)
        require_once '../accounting/journal_entry_service.php';
        $jeService = new JournalEntryService($pdo);
        $jeService->reconcilePayment($txnId);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Transaction reconciled successfully']);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
