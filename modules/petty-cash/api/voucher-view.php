<?php

require_once __DIR__ . '/../includes/petty-cash-lib.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * @return string
 */
function petty_cash_voucher_signature_url(?string $rawPath): string
{
    $rawPath = trim((string) $rawPath);
    if ($rawPath === '') {
        return '';
    }
    if (function_exists('mediaUrlFromPath')) {
        $url = mediaUrlFromPath($rawPath, false);
        if ($url !== '') {
            return $url;
        }
    }
    return function_exists('app_url')
        ? app_url('/' . ltrim($rawPath, '/'))
        : ('/' . ltrim($rawPath, '/'));
}

/**
 * @param array<string, mixed> $row
 */
function petty_cash_format_account_label(array $row): string
{
    $name = trim((string) ($row['name'] ?? ''));
    $code = trim((string) ($row['code'] ?? ''));
    if ($code === '' || $name === '') {
        return $name !== '' ? $name : $code;
    }
    if (stripos($name, $code) === 0) {
        return $name;
    }
    return $code . ' - ' . $name;
}

try {
    pettyCashDeskRequireAccess();
    $scope = pettyCashDeskScope();
    global $pdo;

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Voucher id is required.');
    }

    $voucher = getPettyCashVoucher($id);
    if (!$voucher) {
        throw new RuntimeException('Voucher not found.');
    }
    if (!$scope['can_manage'] && (int) ($voucher['custodian_id'] ?? 0) !== (int) $scope['user_id']) {
        throw new RuntimeException('Access denied.');
    }

    $receiptPath = (string) ($voucher['receipt_path'] ?? '');
    $receiptUrl = '';
    if ($receiptPath !== '') {
        $receiptUrl = function_exists('app_url')
            ? app_url('/' . ltrim($receiptPath, '/'))
            : ('/' . ltrim($receiptPath, '/'));
    }

    $pettyCashAccountName = (string) ($voucher['petty_cash_account_name'] ?? '');
    $expenseAccountName = (string) ($voucher['expense_account_name'] ?? '');
    $pettyCashAccountId = (int) ($voucher['petty_cash_account_id'] ?? 0);
    $expenseAccountId = (int) ($voucher['expense_account_id'] ?? 0);

    if (function_exists('tableExists') && tableExists('financial_accounts', $pdo)) {
        $ids = array_values(array_filter([$pettyCashAccountId, $expenseAccountId]));
        if ($ids) {
            $hasCode = function_exists('columnExists') && columnExists('financial_accounts', 'code', $pdo);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = $hasCode
                ? "SELECT id, name, code FROM financial_accounts WHERE id IN ($placeholders)"
                : "SELECT id, name FROM financial_accounts WHERE id IN ($placeholders)";
            $accStmt = $pdo->prepare($sql);
            $accStmt->execute($ids);
            $labelsById = [];
            foreach ($accStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $labelsById[(int) ($row['id'] ?? 0)] = petty_cash_format_account_label($row);
            }
            if ($pettyCashAccountId > 0 && isset($labelsById[$pettyCashAccountId])) {
                $pettyCashAccountName = $labelsById[$pettyCashAccountId];
            }
            if ($expenseAccountId > 0 && isset($labelsById[$expenseAccountId])) {
                $expenseAccountName = $labelsById[$expenseAccountId];
            }
        }
    }

    $categoryLabel = $expenseAccountName !== ''
        ? $expenseAccountName
        : (string) ($voucher['category'] ?? '');

    echo json_encode([
        'can_manage' => (bool) $scope['can_manage'],
        'voucher' => [
            'id' => (int) ($voucher['id'] ?? 0),
            'voucher_number' => (string) ($voucher['voucher_number'] ?? ''),
            'date' => (string) ($voucher['date'] ?? ''),
            'category' => (string) ($voucher['category'] ?? ''),
            'description' => (string) ($voucher['description'] ?? ''),
            'amount' => (float) ($voucher['amount'] ?? 0),
            'status' => (string) ($voucher['status'] ?? ''),
            'is_posted' => (int) ($voucher['is_posted'] ?? 0),
            'petty_cash_account_id' => $pettyCashAccountId,
            'expense_account_id' => $expenseAccountId,
            'petty_cash_account_name' => $pettyCashAccountName,
            'expense_account_name' => $expenseAccountName,
            'category_label' => $categoryLabel,
            'custodian_name' => (string) ($voucher['custodian_name'] ?? ''),
            'created_by_name' => (string) ($voucher['created_by_name'] ?? ''),
            'approved_by_name' => (string) ($voucher['approved_by_name'] ?? ''),
            'approved_at' => (string) ($voucher['approved_at'] ?? ''),
            'created_at' => (string) ($voucher['created_at'] ?? ''),
            'rejection_reason' => (string) ($voucher['rejection_reason'] ?? ''),
            'receipt_url' => $receiptUrl,
            'custodian_signature_url' => petty_cash_voucher_signature_url($voucher['custodian_signature_path'] ?? null),
            'approved_by_signature_url' => petty_cash_voucher_signature_url($voucher['approved_by_signature_path'] ?? null),
            'created_by_signature_url' => petty_cash_voucher_signature_url($voucher['created_by_signature_path'] ?? null),
        ],
        'urls' => [
            'desk' => pettyCashModuleUrl('index.php'),
            'vouchers' => pettyCashModuleUrl('vouchers/index.php'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
