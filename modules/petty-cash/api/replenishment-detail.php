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
        $repId = (int) ($_GET['rep_id'] ?? $_GET['id'] ?? 0);
        if ($repId <= 0) {
            throw new RuntimeException('Top-up id is required.');
        }
        $viewOnly = isset($_GET['view']) && (string) $_GET['view'] === '1';
        $preview = $viewOnly
            ? getPettyCashReplenishmentViewData($repId)
            : getPettyCashReplenishmentApprovalPreview($repId);
        if (!$preview) {
            throw new RuntimeException('Top-up request not found.');
        }

        echo json_encode([
            'can_manage' => (bool) $scope['can_manage'],
            'view_only' => $viewOnly,
            'preview' => $preview,
            'urls' => [
                'list' => pettyCashModuleUrl('replenishments/index.php'),
                'desk' => pettyCashModuleUrl('index.php'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    if (!$scope['can_manage']) {
        throw new RuntimeException('Only Admin or Finance can approve top-ups.');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $repId = (int) ($input['rep_id'] ?? $input['id'] ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $preview = getPettyCashReplenishmentApprovalPreview($repId);
    if (!$preview) {
        throw new RuntimeException('This top-up is no longer pending or cannot be approved.');
    }
    if (empty($preview['can_approve'])) {
        throw new RuntimeException((string) ($preview['insufficient_message'] ?? 'Source account has insufficient balance.'));
    }

    $result = approvePettyCashReplenishment($repId, $userId);
    if ($result !== true) {
        throw new RuntimeException(is_string($result) ? $result : 'Failed to approve top-up.');
    }

    echo json_encode([
        'ok' => true,
        'redirect' => pettyCashModuleUrl('replenishments/index.php', ['success' => 'approved']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
