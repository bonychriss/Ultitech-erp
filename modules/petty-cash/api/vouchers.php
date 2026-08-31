<?php

require_once __DIR__ . '/../includes/petty-cash-lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    pettyCashDeskRequireAccess();
    global $pdo;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $filters = pettyCashDeskParseVoucherFilters($_GET);
        $filters['limit'] = min(500, max(1, (int) ($_GET['limit'] ?? 150)));
        $rows = getAllPettyCashVouchers($filters);
        echo json_encode([
            'data' => pettyCashDeskFormatVoucherRows($rows),
            'total' => count($rows),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = (string) ($input['action'] ?? '');
    $id = (int) ($input['id'] ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $scope = pettyCashDeskScope();

    if (!$scope['can_manage']) {
        throw new RuntimeException('Only Admin or Finance can perform this action.');
    }
    if ($id <= 0) {
        throw new RuntimeException('Record id is required.');
    }

    if ($action === 'approve_voucher') {
        $result = approvePettyCashVoucher($id, $userId);
        if ($result !== true) {
            throw new RuntimeException(is_string($result) ? $result : 'Failed to approve voucher.');
        }
        echo json_encode(['ok' => true, 'message' => 'Voucher approved and posted to Balances.']);
        exit;
    }

    if ($action === 'reject_voucher') {
        $reason = trim((string) ($input['reason'] ?? ''));
        $result = rejectPettyCashVoucher($id, $userId, $reason);
        if ($result !== true) {
            throw new RuntimeException(is_string($result) ? $result : 'Failed to reject voucher.');
        }
        echo json_encode(['ok' => true, 'message' => 'Voucher rejected.']);
        exit;
    }

    if ($action === 'cancel_voucher') {
        $result = cancelPettyCashVoucher($id);
        if ($result !== true) {
            throw new RuntimeException(is_string($result) ? $result : 'Failed to cancel voucher.');
        }
        echo json_encode(['ok' => true, 'message' => 'Voucher cancelled.']);
        exit;
    }

    if ($action === 'approve_replenishment') {
        $result = approvePettyCashReplenishment($id, $userId);
        if ($result !== true) {
            throw new RuntimeException(is_string($result) ? $result : 'Failed to approve top-up.');
        }
        echo json_encode(['ok' => true, 'message' => 'Top-up approved.']);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
