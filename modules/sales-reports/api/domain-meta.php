<?php
require_once __DIR__ . '/../includes/sales-reports-lib.php';
require_once __DIR__ . '/../includes/report-engine.php';
require_once __DIR__ . '/../includes/report-domain-data.php';

salesReportsRequireAccess('view');
header('Content-Type: application/json; charset=utf-8');

$pdo = salesReportsBootstrap();
$domain = reportEngineNormalizeDomain($_GET['domain'] ?? 'sales');

echo json_encode([
    'success' => true,
    'domain' => $domain,
    'domain_meta' => reportEngineDomains()[$domain] ?? null,
    'filters' => reportEngineFilterOptions($pdo, $domain),
    'domains' => array_values(reportEngineDomains()),
], JSON_UNESCAPED_UNICODE);
