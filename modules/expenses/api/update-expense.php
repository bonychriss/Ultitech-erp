<?php
require_once '../../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/currency_helpers.php';
require_once __DIR__ . '/../includes/balances_integration.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
    exit;
}

$expenseId = (int) ($_POST['expense_id'] ?? 0);
$isDraft = strtolower(trim((string) ($_POST['save_mode'] ?? 'post'))) === 'draft';

$errors = [];

if ($expenseId <= 0) {
    $errors[] = 'Expense ID is required.';
}

$date = trim((string) ($_POST['date'] ?? ''));
$main_account_id = !empty($_POST['main_account_id']) ? (int) $_POST['main_account_id'] : null;
$account_id = !empty($_POST['account_id']) ? (int) $_POST['account_id'] : null;
$amount = (float) ($_POST['amount'] ?? 0);
$tax_amount = 0.0;
$currency_input = trim((string) ($_POST['currency'] ?? $_POST['currency_code'] ?? 'TZS'));
$currency_code = expenses_currency_display_code($currency_input);
$payment_method = $_POST['payment_method'] ?? 'cash';
if ($payment_method === 'bank_transfer') {
    $payment_method = 'mobile_money';
}
$source_account_id = !empty($_POST['source_account_id']) ? (int) $_POST['source_account_id'] : null;
$description = trim($_POST['description'] ?? '');

$existingDraft = null;
if ($errors === [] && $expenseId > 0) {
    $existingDraft = expenses_fetch_editable_draft($pdo, $expenseId);
    if ($existingDraft === null) {
        $errors[] = 'Only draft expenses can be edited.';
    }
}

$existingAttachment = trim((string) ($existingDraft['attachment'] ?? ''));

if ($isDraft) {
    if ($date === '') {
        $date = date('Y-m-d');
    }
    if ($amount < 0) {
        $errors[] = 'Amount cannot be negative.';
    }
} else {
    if ($date === '') {
        $errors[] = 'Date is required.';
    }
    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }
    if ($description === '') {
        $errors[] = 'Description is required.';
    }
    if (!$account_id) {
        $errors[] = expenses_expense_accounts_are_hierarchical($pdo)
            ? 'Please select an expense sub-account.'
            : 'Please select an expense account.';
    } elseif (!expenses_validate_expense_sub_account($pdo, $account_id, $main_account_id)) {
        $errors[] = 'Please select a valid expense sub-account under the chosen main account.';
    }
    if (!$source_account_id) {
        $errors[] = 'Please select the bank or cash account used.';
    }
}

if ($account_id && !expenses_resolve_financial_account($pdo, $account_id)) {
    $errors[] = 'Please select a valid expense sub-account from Chart of Accounts.';
}
if ($source_account_id && !expenses_resolve_financial_account($pdo, $source_account_id)) {
    $errors[] = 'Please select a valid payment account from Chart of Accounts.';
}
if ($source_account_id) {
    $sourceRow = expenses_resolve_financial_account($pdo, $source_account_id);
    if ($sourceRow && expenses_is_petty_cash_account_row($sourceRow)) {
        $errors[] = 'Petty cash payments belong in the Petty Cash module, not Expenses.';
    }
}

$attachmentPath = $existingAttachment !== '' ? $existingAttachment : null;
$hasAttachment = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;

if ($hasAttachment) {
    if ($_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $allowedExts = ['jpg', 'jpeg', 'png', 'pdf'];
        $fileName = $_FILES['attachment']['name'];
        $fileTmp = $_FILES['attachment']['tmp_name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExts, true)) {
            $errors[] = 'Invalid file type. Allowed: jpg, png, pdf';
        } else {
            $uploadDir = dirname(__DIR__, 3) . '/uploads/expenses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $newFileName = uniqid('exp_', true) . '.' . $ext;
            if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                $attachmentPath = 'uploads/expenses/' . $newFileName;
            } else {
                $errors[] = 'Failed to upload file.';
            }
        }
    } else {
        $errors[] = 'Failed to upload file.';
    }
} elseif (!$isDraft && $attachmentPath === null) {
    $errors[] = 'Receipt attachment is required.';
}

if ($errors !== []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

try {
    expenses_ensure_schema($pdo);
    $pdo->beginTransaction();

    $expenseNumber = (string) ($existingDraft['expense_number'] ?? '');

    $editableWhere = expenses_editable_expense_sql_constraint();

    if ($isDraft) {
        $stmt = $pdo->prepare("UPDATE erp_expenses
            SET date = ?, account_id = ?, source_account_id = ?, amount = ?, tax_amount = ?, currency_code = ?, payment_method = ?, description = ?, attachment = ?, status = 'draft', is_posted = 0
            WHERE id = ? AND {$editableWhere}");
        $stmt->execute([
            $date,
            $account_id ?: null,
            $source_account_id ?: null,
            $amount,
            $tax_amount,
            $currency_code,
            $payment_method,
            $description,
            $attachmentPath,
            $expenseId,
        ]);
    } else {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE erp_expenses
            SET date = ?, account_id = ?, source_account_id = ?, amount = ?, tax_amount = ?, currency_code = ?, payment_method = ?, description = ?, attachment = ?, status = 'pending', is_posted = 0, approved_by = ?, approved_at = NOW()
            WHERE id = ? AND {$editableWhere}");
        $stmt->execute([
            $date,
            $account_id,
            $source_account_id,
            $amount,
            $tax_amount,
            $currency_code,
            $payment_method,
            $description,
            $attachmentPath,
            $userId,
            $expenseId,
        ]);
    }

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Draft expense not found or cannot be updated.']);
        exit;
    }

    if (!$isDraft) {
        $postResult = expenses_post_erp_expense_row($pdo, $expenseId);
        if (empty($postResult['success'])) {
            $pdo->rollBack();
            throw new PDOException((string) ($postResult['message'] ?? 'Could not post expense to balances.'));
        }
        expenses_mark_expense_posted($pdo, $expenseId);
        $_SESSION['bal_lottie_success'] = 'Expense recorded and posted to the ledger.';
        $message = 'Expense recorded and posted to the ledger.';
    } else {
        $message = 'Draft updated.';
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'id' => $expenseId,
        'expense_number' => $expenseNumber,
        'posted' => !$isDraft,
        'draft' => $isDraft,
        'redirect' => 'index.php?module=expenses',
        'message' => $message,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
