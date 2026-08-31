<?php

require_once __DIR__ . '/../includes/petty-cash-lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    pettyCashDeskRequireAccess();
    $scope = pettyCashDeskScope();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $filters = [];
        if ($scope['custodian_id']) {
            $filters['custodian_id'] = (int) $scope['custodian_id'];
        }
        if (!empty($_GET['status'])) {
            $filters['status'] = (string) $_GET['status'];
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = (string) $_GET['search'];
        }

        $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : 0;
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
        if ($year >= 2000 && $year <= 2100) {
            if ($dateFrom === '') {
                $dateFrom = $year . '-01-01';
            }
            if ($dateTo === '') {
                $dateTo = $year . '-12-31';
            }
        }
        if ($dateFrom !== '') {
            $filters['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $filters['date_to'] = $dateTo;
        }
        if (empty($filters['status']) && empty($filters['search']) && $dateFrom === '' && $dateTo === '') {
            $filters['exclude_cancelled'] = true;
        }

        $filters['limit'] = min(500, max(1, (int) ($_GET['limit'] ?? 200)));

        $rows = getAllPettyCashReplenishments($filters);
        echo json_encode([
            'can_manage' => (bool) $scope['can_manage'],
            'data' => pettyCashDeskFormatReplenishmentRows($rows),
            'total' => count($rows),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    if (!$scope['can_manage']) {
        throw new RuntimeException('Only Admin or Finance can perform this action.');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = (string) ($input['action'] ?? '');
    $id = (int) ($input['id'] ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($id <= 0) {
        throw new RuntimeException('Record id is required.');
    }

    if ($action === 'approve_replenishment') {
        $preview = getPettyCashReplenishmentApprovalPreview($id);
        if (!$preview) {
            throw new RuntimeException('This top-up is no longer pending or cannot be approved.');
        }
        if (empty($preview['can_approve'])) {
            throw new RuntimeException((string) ($preview['insufficient_message'] ?? 'Source account has insufficient balance.'));
        }
        $result = approvePettyCashReplenishment($id, $userId);
        if ($result !== true) {
            throw new RuntimeException(is_string($result) ? $result : 'Failed to approve top-up.');
        }
        echo json_encode(['ok' => true, 'message' => 'Top-up approved.']);
        exit;
    }

    if ($action === 'reject_replenishment') {
        $reason = trim((string) ($input['reason'] ?? ''));
        $result = rejectPettyCashReplenishment($id, $userId, $reason);
        if ($result !== true) {
            throw new RuntimeException(is_string($result) ? $result : 'Failed to reject top-up.');
        }
        echo json_encode(['ok' => true, 'message' => 'Top-up rejected.']);
        exit;
    }

    if ($action === 'cancel_replenishment') {
        $result = cancelPettyCashReplenishment($id);
        if ($result !== true) {
            throw new RuntimeException(is_string($result) ? $result : 'Failed to cancel top-up.');
        }
        echo json_encode(['ok' => true, 'message' => 'Top-up cancelled.']);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
