<?php

require_once __DIR__ . '/../includes/petty-cash-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    pettyCashDeskRequireAccess();
    $scope = pettyCashDeskScope();

    $pettyAccounts = pettyCashListFinancialAccounts('petty');
    $sourceAccounts = pettyCashListFinancialAccounts('all');
    $hasAccounts = pettyCashHasFinancialAccounts() && $sourceAccounts !== [];

    $defaultPetty = petty_cash_ensure_default_main_account($pdo);
    if ($defaultPetty && (int) ($defaultPetty['id'] ?? 0) > 0) {
        $defaultId = (int) $defaultPetty['id'];
        $found = false;
        foreach ($pettyAccounts as $acc) {
            if ((int) ($acc['id'] ?? 0) === $defaultId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            array_unshift($pettyAccounts, [
                'id' => $defaultId,
                'name' => (string) ($defaultPetty['name'] ?? 'Petty Cash'),
                'type' => (string) ($defaultPetty['type'] ?? 'cash'),
                'live_balance' => (float) ($defaultPetty['balance'] ?? 0),
                'current_balance' => (float) ($defaultPetty['balance'] ?? 0),
            ]);
        }
    }

    $pettyBalanceTotal = 0.0;
    foreach ($pettyAccounts as $acc) {
        $pettyBalanceTotal += (float) ($acc['live_balance'] ?? $acc['current_balance'] ?? 0);
    }

    $formatAcc = static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'balance' => (float) ($row['live_balance'] ?? $row['current_balance'] ?? 0),
        ];
    };

    echo json_encode([
        'can_manage' => (bool) $scope['can_manage'],
        'has_financial_accounts' => $hasAccounts || ($defaultPetty && (int) ($defaultPetty['id'] ?? 0) > 0),
        'petty_balance_total' => $pettyBalanceTotal,
        'custodian_float' => getPettyCashBalance($scope['user_id']),
        'default_petty_cash_account_id' => $defaultPetty ? (string) ((int) $defaultPetty['id']) : '',
        'petty_accounts' => array_map($formatAcc, $pettyAccounts),
        'source_accounts' => array_map($formatAcc, $sourceAccounts),
        'urls' => [
            'desk' => pettyCashModuleUrl('index.php'),
            'list' => pettyCashModuleUrl('replenishments/index.php'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
