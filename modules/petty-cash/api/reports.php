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
    $scope = pettyCashDeskScope();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $filters = ['exclude_cancelled' => true];
        if ($scope['custodian_id']) {
            $filters['custodian_id'] = (int) $scope['custodian_id'];
        }
        if (!empty($_GET['status'])) {
            $filters['status'] = (string) $_GET['status'];
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = (string) $_GET['search'];
        }

        $dateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-01')));
        $dateTo = trim((string) ($_GET['date_to'] ?? date('Y-m-t')));
        $category = trim((string) ($_GET['category'] ?? ''));
        $custodianId = isset($_GET['custodian_id']) && $_GET['custodian_id'] !== ''
            ? (int) $_GET['custodian_id']
            : null;

        if (!$scope['can_manage']) {
            $custodianId = (int) $scope['user_id'];
        }

        $filters['date_from'] = $dateFrom;
        $filters['date_to'] = $dateTo;
        if ($category !== '') {
            $filters['category'] = $category;
        }
        if ($custodianId) {
            $filters['custodian_id'] = $custodianId;
        }

        $vouchers = getAllPettyCashVouchers($filters);
        $totalAmount = array_sum(array_column($vouchers, 'amount'));
        $approved = array_filter($vouchers, static fn ($v) => strtolower((string) ($v['status'] ?? '')) === 'approved');
        $approvedAmount = array_sum(array_column($approved, 'amount'));

        $byCategory = [];
        $byCustodian = [];
        foreach ($vouchers as $v) {
            $cat = (string) ($v['category'] ?? '');
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = ['count' => 0, 'amount' => 0.0];
            }
            $byCategory[$cat]['count']++;
            $byCategory[$cat]['amount'] += (float) ($v['amount'] ?? 0);

            $custName = (string) ($v['custodian_name'] ?? 'Unknown');
            if (!isset($byCustodian[$custName])) {
                $byCustodian[$custName] = ['count' => 0, 'amount' => 0.0];
            }
            $byCustodian[$custName]['count']++;
            $byCustodian[$custName]['amount'] += (float) ($v['amount'] ?? 0);
        }

        $categories = [];
        foreach (getPettyCashCategories() as $cat) {
            $name = is_array($cat) ? (string) ($cat['name'] ?? '') : (string) $cat;
            if ($name !== '') {
                $categories[] = ['name' => $name];
            }
        }

        $custodians = [];
        if ($scope['can_manage']) {
            try {
                $custodians = $pdo->query(
                    "SELECT DISTINCT u.id, u.full_name AS name
                     FROM petty_cash_vouchers v
                     JOIN users u ON v.custodian_id = u.id
                     ORDER BY u.full_name ASC"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $custodians = [];
            }
        }

        echo json_encode([
            'can_manage' => (bool) $scope['can_manage'],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'category' => $category,
                'custodian_id' => $custodianId,
            ],
            'summary' => [
                'total_count' => count($vouchers),
                'total_amount' => $totalAmount,
                'approved_count' => count($approved),
                'approved_amount' => $approvedAmount,
            ],
            'by_category' => $byCategory,
            'by_custodian' => $byCustodian,
            'vouchers' => pettyCashDeskFormatVoucherRows($vouchers),
            'categories' => $categories,
            'custodians' => $custodians,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
