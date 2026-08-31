<?php

require_once __DIR__ . '/../includes/petty-cash-lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    pettyCashDeskRequireAccess();
    $scope = pettyCashDeskScope();
    if (!$scope['can_manage']) {
        throw new RuntimeException('Only Finance or Admin can create top-up requests.');
    }
    if (!pettyCashHasFinancialAccounts()) {
        throw new RuntimeException('Financial accounts are not configured. Set up accounts in Balances first.');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $pettyAccountId = (int) ($input['petty_cash_account_id'] ?? 0);
    $sourceAccountId = (int) ($input['source_account_id'] ?? 0);
    $amount = (float) ($input['amount'] ?? 0);
    $description = trim((string) ($input['description'] ?? ''));
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($pettyAccountId <= 0 || $sourceAccountId <= 0) {
        throw new RuntimeException('Select both petty cash and source accounts.');
    }
    if ($pettyAccountId === $sourceAccountId) {
        throw new RuntimeException('Source and petty cash accounts must be different.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('Amount must be greater than zero.');
    }
    if ($description === '') {
        throw new RuntimeException('Please enter a reason or description.');
    }

    $sourceAccounts = pettyCashListFinancialAccounts('all');
    $sourceAccount = null;
    foreach ($sourceAccounts as $acc) {
        if ((int) ($acc['id'] ?? 0) === $sourceAccountId) {
            $sourceAccount = $acc;
            break;
        }
    }
    if (!$sourceAccount) {
        throw new RuntimeException('Source account was not found.');
    }
    $sourceBalance = (float) ($sourceAccount['live_balance'] ?? $sourceAccount['current_balance'] ?? 0);
    if ($sourceBalance <= 0) {
        throw new RuntimeException('Selected source account has zero balance and cannot be used.');
    }
    if ($amount > $sourceBalance) {
        throw new RuntimeException('Amount cannot exceed the source account balance.');
    }

    $newId = createPettyCashReplenishment([
        'custodian_id' => $userId,
        'petty_cash_account_id' => $pettyAccountId,
        'source_account_id' => $sourceAccountId,
        'amount' => $amount,
        'description' => $description,
        'created_by' => $userId,
    ]);

    if (!$newId) {
        throw new RuntimeException('Failed to submit top-up request.');
    }

    echo json_encode([
        'ok' => true,
        'replenishment_id' => (int) $newId,
        'redirect' => pettyCashModuleUrl('replenishments/index.php'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
