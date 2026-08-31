<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
require_once __DIR__ . '/../includes/report-engine.php';
salesReportsRequireAccess('create');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$pdo = salesReportsBootstrap();
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

try {
    $domain = reportEngineNormalizeDomain($body['report_domain'] ?? 'sales');
    $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];

    $id = salesReportsCreate($pdo, [
        'report_domain' => $domain,
        'report_name' => $body['report_name'] ?? (date('F Y') . ' ' . reportEngineDomainLabel($domain)),
        'report_type' => $body['report_type'] ?? ($domain === 'sales' ? 'monthly' : 'management'),
        'template_key' => $body['template_key'] ?? reportEngineDefaultTemplateKey($domain),
        'start_date' => $body['start_date'] ?? date('Y-m-01'),
        'end_date' => $body['end_date'] ?? date('Y-m-d'),
        'prepared_by' => trim((string) ($body['prepared_by'] ?? '')),
        'department' => $body['department'] ?? (string) ($_SESSION['department'] ?? (reportEngineDomains()[$domain]['department_default'] ?? 'Sales')),
        'branch' => $body['branch'] ?? '',
        'status' => 'draft',
        'description' => $body['description'] ?? '',
        'filters' => $filters,
    ]);
    echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('sales report create api: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not create report.']);
}
