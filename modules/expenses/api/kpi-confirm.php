<?php
require_once '../../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../includes/balances_integration.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$key = trim((string) ($_GET['key'] ?? ''));
$allowed = ['postedThisMonth', 'monthlySpend', 'totalRecords', 'listedNow'];

if (!in_array($key, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid KPI key']);
    exit;
}

try {
    expenses_balances_bootstrap();
    expenses_backfill_pending_records($pdo);

    if ($key === 'listedNow') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $listedCount = (int) ($input['listedCount'] ?? 0);
        $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];

        $trace = [
            'title' => 'Listed now',
            'headline' => (string) $listedCount,
            'method' => 'Rows returned by the filtered list API',
            'criteria' => [],
            'confirmation' => expenses_kpi_build_confirmation('listedNow', [
                'listedCount' => $listedCount,
            ]),
            'items' => [],
        ];

        if ($filters !== []) {
            foreach ($filters as $label => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $trace['criteria'][] = [
                    'label' => (string) $label,
                    'value' => (string) $value,
                ];
            }
        }
    } else {
        $traces = expenses_build_kpi_traces($pdo);
        $trace = $traces[$key] ?? [];
    }

    if ($trace === []) {
        http_response_code(404);
        echo json_encode(['error' => 'Trace not found']);
        exit;
    }

    $forceAi = isset($_GET['ai']) && (string) $_GET['ai'] === '1';
    if ($forceAi) {
        $ai = expenses_kpi_ai_confirm($key, $trace);
        echo json_encode([
            'confirmation' => $ai['confirmation'],
            'viaAi' => $ai['viaAi'],
        ]);
        exit;
    }

    echo json_encode([
        'confirmation' => (string) ($trace['confirmation'] ?? ''),
        'viaAi' => false,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
