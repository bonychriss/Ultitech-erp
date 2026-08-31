<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';
require_once __DIR__ . '/../includes/revenue-kpi-trace-lib.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$key = trim((string) ($_GET['key'] ?? ''));
$allowed = [
    'totalNet',
    'totalVat',
    'totalInclTax',
    'outstandingAr',
    'thisMonth',
    'totalInvoices',
    'outstandingInvoices',
    'overdueInvoices',
];

if (!in_array($key, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid KPI key']);
    exit;
}

try {
    revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($input)) {
        $input = [];
    }

    $trace = is_array($input['trace'] ?? null) ? $input['trace'] : [];
    if ($trace === []) {
        http_response_code(400);
        echo json_encode(['error' => 'Trace payload required']);
        exit;
    }

    $forceAi = isset($_GET['ai']) && (string) $_GET['ai'] === '1';
    if ($forceAi) {
        $ai = revenue_kpi_ai_confirm($key, $trace);
        echo json_encode([
            'confirmation' => $ai['confirmation'],
            'viaAi' => $ai['viaAi'],
        ]);
        exit;
    }

    $context = is_array($trace['context'] ?? null) ? $trace['context'] : [];
    echo json_encode([
        'confirmation' => (string) ($trace['confirmation'] ?? revenue_kpi_build_confirmation($key, $context)),
        'viaAi' => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
