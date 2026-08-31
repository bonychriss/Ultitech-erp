<?php

require_once '../../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/currency_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = [];
if (is_string($raw) && $raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode($raw, true);
    $input = is_array($decoded) ? $decoded : [];
} else {
    $input = $_POST;
}

if (!verify_csrf($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
    exit;
}

$id = (int) ($input['id'] ?? $input['expense_id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Expense ID is required.']);
    exit;
}

try {
    expenses_ensure_schema($pdo);
    expenses_balances_bootstrap();

    $draft = expenses_fetch_editable_draft($pdo, $id);
    if ($draft === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Draft expense not found or already posted.']);
        exit;
    }

    $amount = (float) ($draft['amount'] ?? 0);
    $accountId = (int) ($draft['account_id'] ?? 0);
    $sourceId = (int) ($draft['source_account_id'] ?? 0);
    $date = trim((string) ($draft['date'] ?? ''));
    $description = trim((string) ($draft['description'] ?? ''));

    $errors = [];
    if ($date === '') {
        $errors[] = 'Date is required before posting.';
    }
    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero before posting.';
    }
    if ($accountId <= 0) {
        $errors[] = 'Expense account is required before posting.';
    } elseif (!expenses_resolve_financial_account($pdo, $accountId)) {
        $errors[] = 'Expense account was not found.';
    }
    if ($sourceId <= 0) {
        $errors[] = 'Paid from (bank or cash) account is required before posting.';
    } else {
        $sourceRow = expenses_resolve_financial_account($pdo, $sourceId);
        if (!$sourceRow) {
            $errors[] = 'Payment account was not found.';
        } elseif (expenses_is_petty_cash_account_row($sourceRow)) {
            $errors[] = 'Petty cash payments belong in the Petty Cash module, not Expenses.';
        }
    }
    if ($description === '') {
        $errors[] = 'Description is required before posting.';
    }

    if ($errors !== []) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $errors[0], 'errors' => $errors]);
        exit;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $editableWhere = expenses_editable_expense_sql_constraint();

    $sourceBefore = expenses_account_live_balance($pdo, $sourceId);
    $expenseBefore = expenses_account_live_balance($pdo, $accountId);

    $pdo->beginTransaction();

    $upd = $pdo->prepare("
        UPDATE erp_expenses
        SET status = 'approved', approved_by = ?, approved_at = NOW()
        WHERE id = ? AND {$editableWhere}
    ");
    $upd->execute([$userId > 0 ? $userId : null, $id]);
    if ($upd->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Draft expense not found or cannot be posted.']);
        exit;
    }

    $postResult = expenses_post_erp_expense_row($pdo, $id);
    if (empty($postResult['success'])) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => (string) ($postResult['message'] ?? 'Could not post expense to balances.'),
        ]);
        exit;
    }

    expenses_mark_expense_posted($pdo, $id);
    $pdo->commit();

    $sourceAfter = isset($postResult['source_balance'])
        ? (float) $postResult['source_balance']
        : expenses_account_live_balance($pdo, $sourceId);
    $expenseAfter = isset($postResult['expense_balance'])
        ? (float) $postResult['expense_balance']
        : expenses_account_live_balance($pdo, $accountId);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['bal_lottie_success'] = 'Expense posted to the ledger.';
    }

    $currency = (string) ($draft['currency_code'] ?? 'TZS');
    if (function_exists('expenses_currency_display_code')) {
        $currency = expenses_currency_display_code($currency);
    }

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'expense_number' => (string) ($draft['expense_number'] ?? ''),
        'posted' => true,
        'message' => 'Expense posted to the ledger.',
        'balances' => [
            'amount' => $amount,
            'currency_code' => $currency,
            'source_account' => [
                'id' => $sourceId,
                'name' => expenses_resolve_source_account_name($pdo, $sourceId),
                'balance_before' => $sourceBefore,
                'balance_after' => $sourceAfter,
            ],
            'expense_account' => [
                'id' => $accountId,
                'name' => expenses_resolve_category_name($pdo, $accountId),
                'balance_before' => $expenseBefore,
                'balance_after' => $expenseAfter,
            ],
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('post-draft: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
