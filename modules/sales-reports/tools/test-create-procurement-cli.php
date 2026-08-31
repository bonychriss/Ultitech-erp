<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sales-reports-lib.php';
require_once dirname(__DIR__) . '/includes/report-engine.php';
require_once dirname(__DIR__) . '/includes/report-domain-autofill.php';

$pdo = salesReportsBootstrap();

try {
    $id = salesReportsCreate($pdo, [
        'report_domain' => 'procurement',
        'report_name' => 'Aug 2026 Stock Report',
        'report_type' => 'management',
        'template_key' => 'standard',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'prepared_by' => 'Test',
        'department' => 'Warehouse',
        'status' => 'draft',
        'filters' => [],
    ]);
    echo "Created report ID: {$id}\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
