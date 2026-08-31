<?php
require_once '../../../includes/functions.php';
require_once __DIR__ . '/../includes/balances_integration.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pv_id = $input['voucher_id'] ?? null;
$expense_id = $input['expense_id'] ?? null;
$source_account_id = $input['source_account_id'] ?? null;
$category_id = $input['category_id'] ?? null; 
$conversion_rate = $input['conversion_rate'] ?? null;
$converted_amount = $input['converted_amount'] ?? null;
$company_id = (int) (currentCompanyId() ?? 0);

if ($pv_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Payment vouchers are managed separately and cannot be posted from the expenses module.']);
    exit;
}

if (!$expense_id || !$source_account_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Expense ID and Source Account are required']);
    exit;
}

try {
    $pdo->beginTransaction();
    try { $pdo->query("SELECT company_id FROM erp_expenses LIMIT 1"); }
    catch (PDOException $e) { $pdo->exec("ALTER TABLE erp_expenses ADD COLUMN company_id INT NULL AFTER id"); }

    $final_amount = 0;
    $audit_note = "";
    $ref_no = "";
    $exp = null;
    $expenseRecordId = 0;

    $stmt = $pdo->prepare("SELECT * FROM erp_expenses WHERE id = ? AND company_id = ? AND status = 'approved' AND is_posted = 0 FOR UPDATE");
    $stmt->execute([$expense_id, $company_id]);
    $exp = $stmt->fetch();
    if (!$exp) throw new Exception("Approved expense not found or already posted.");

    $final_amount = $exp['amount'];
    $ref_no = $exp['expense_number'];
    $expenseRecordId = $exp['id'];

    if ($converted_amount && $converted_amount > 0) {
        $final_amount = $converted_amount;
        $audit_note = "Direct Expense Conversion: " . $exp['description'] . " (Converted from " . number_format($exp['amount'], 2) . " " . $exp['currency_code'] . " @ rate " . $conversion_rate . ")";

        $stmt = $pdo->prepare("UPDATE erp_expenses SET amount = ?, currency_code = 'TSh', description = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$final_amount, $audit_note, $expense_id, $company_id]);
    }

    $stmt = $pdo->prepare("UPDATE erp_expenses SET source_account_id = ?, account_id = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$source_account_id, $category_id ?: $exp['account_id'], $expense_id, $company_id]);
    $exp['account_id'] = $category_id ?: $exp['account_id'];
    $exp['source_account_id'] = $source_account_id;

    $postDesc = $audit_note !== '' ? $audit_note : ('Posted Expense #' . $ref_no);
    $txDate = null;
    if (!empty($exp['date'] ?? null)) {
        $txDate = $exp['date'] . ' 12:00:00';
    }

    $expenseAccountId = (int) ($category_id ?: ($exp['account_id'] ?? 0));
    if ($expenseAccountId <= 0) {
        throw new Exception('Expense sub-account (chart of accounts) is required.');
    }

    $postResult = expenses_post_to_balances(
        $pdo,
        (int) $expenseRecordId,
        (int) $source_account_id,
        $expenseAccountId,
        (float) $final_amount,
        $postDesc,
        $txDate,
        $company_id > 0 ? $company_id : null
    );
    if (empty($postResult['success'])) {
        throw new Exception((string) ($postResult['message'] ?? 'Failed to post expense to balances.'));
    }

    $stmt = $pdo->prepare('UPDATE erp_expenses SET is_posted = 1 WHERE id = ?');
    $stmt->execute([(int) $expenseRecordId]);

    $newBalance = (float) ($postResult['source_balance'] ?? 0);
    if ($newBalance === 0.0) {
        $stmt = $pdo->prepare('SELECT current_balance FROM financial_accounts WHERE id = ?' . ($company_id > 0 ? ' AND company_id = ?' : ''));
        $stmt->execute($company_id > 0 ? [$source_account_id, $company_id] : [$source_account_id]);
        $newBalance = (float) ($stmt->fetchColumn() ?: 0);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => 'Posted successfully.',
        'posted_amount' => number_format($final_amount, 2),
        'remaining_balance' => number_format($newBalance, 2)
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
