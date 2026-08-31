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
    global $pdo;

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $pettyCashAccountId = (int) ($_POST['petty_cash_account_id'] ?? 0);
    $categoryAccountId = (int) ($_POST['expense_account_id'] ?? $_POST['category_account_id'] ?? 0);

    $validated = petty_cash_validate_voucher_accounts($pdo, $pettyCashAccountId, $categoryAccountId);
    if (empty($validated['ok'])) {
        throw new RuntimeException((string) ($validated['message'] ?? 'Invalid accounts.'));
    }

    $data = [
        'date' => trim((string) ($_POST['date'] ?? '')),
        'custodian_id' => $userId,
        'category' => (string) $validated['category'],
        'description' => trim((string) ($_POST['description'] ?? '')),
        'amount' => (float) ($_POST['amount'] ?? 0),
        'created_by' => $userId,
        'petty_cash_account_id' => (int) $validated['petty_cash_account_id'],
        'expense_account_id' => (int) $validated['expense_account_id'],
    ];

    $uploadDir = pettyCashUploadDir();
    if (!empty($_FILES['receipt']['name']) && (int) ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        $ext = pathinfo((string) $_FILES['receipt']['name'], PATHINFO_EXTENSION);
        $fileName = 'receipt_' . time() . '_' . uniqid('', true) . ($ext ? '.' . $ext : '');
        $target = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $target)) {
            $data['receipt_path'] = 'assets/uploads/petty-cash/' . $fileName;
        }
    }

    if ($data['amount'] <= 0) {
        throw new RuntimeException('Amount must be greater than zero.');
    }
    if ($data['date'] === '' || $data['category'] === '' || $data['description'] === '') {
        throw new RuntimeException('Please fill in date, category, and description.');
    }

    $voucherId = createPettyCashVoucher($data);
    if (!$voucherId) {
        throw new RuntimeException('Failed to create voucher.');
    }

    echo json_encode([
        'ok' => true,
        'voucher_id' => (int) $voucherId,
        'redirect' => pettyCashModuleUrl('view-voucher.php', ['id' => (int) $voucherId]),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
