<?php

require_once __DIR__ . '/../includes/petty-cash-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    pettyCashDeskRequireAccess();
    global $pdo;

    $scope = pettyCashDeskScope();
    $custodianScope = $scope['custodian_id'];
    $stats = getPettyCashDashboardStats($custodianScope);
    $flow = getPettyCashFlowTrend(6, $custodianScope);

    $categories = [];
    foreach (getPettyCashCategories() as $cat) {
        $name = is_array($cat) ? (string) ($cat['name'] ?? '') : (string) $cat;
        if ($name !== '') {
            $categories[] = ['id' => $name, 'name' => $name];
        }
    }

    echo json_encode([
        'stats' => $stats,
        'flow' => $flow,
        'categories' => $categories,
        'can_manage' => (bool) $scope['can_manage'],
        'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
        'urls' => [
            'create_voucher' => pettyCashModuleUrl('create-voucher.php'),
            'replenishment' => pettyCashModuleUrl('replenishment.php'),
            'reports' => pettyCashModuleUrl('reports.php'),
            'categories' => pettyCashModuleUrl('categories/index.php'),
        ],
        'current_month_label' => date('F Y'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
