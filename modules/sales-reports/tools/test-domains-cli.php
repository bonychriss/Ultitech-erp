<?php
/**
 * CLI smoke test for report domain data providers (no HTTP).
 * Usage: php modules/sales-reports/tools/test-domains-cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die('CLI only');
}

require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sales-reports-lib.php';
require_once dirname(__DIR__) . '/includes/report-domain-data.php';

$pdo = salesReportsBootstrap();
$start = date('Y-m-01');
$end = date('Y-m-d');
$filters = ['start_date' => $start, 'end_date' => $end];

$domains = ['procurement', 'finance', 'fleet', 'store_warehouse'];
foreach ($domains as $domain) {
    $report = [
        'report_domain' => $domain,
        'start_date' => $start,
        'end_date' => $end,
        'report_name' => ucfirst(str_replace('_', ' ', $domain)) . ' Test',
    ];
    echo "\n=== {$domain} ===\n";
    try {
        $snap = reportEngineBuildSnapshot($pdo, $report);
        $kpiKeys = array_keys($snap['kpis'] ?? $snap['summary'] ?? []);
        echo 'Snapshot keys: ' . implode(', ', array_slice(array_keys($snap), 0, 12)) . "\n";
        if (!empty($snap['kpis'])) {
            echo 'KPIs: ' . json_encode($snap['kpis'], JSON_UNESCAPED_UNICODE) . "\n";
        }
        $menu = reportEngineErpMenu($domain);
        $firstSource = null;
        foreach ($menu as $group => $items) {
            foreach ($items as $key => $label) {
                $firstSource = $key;
                break 2;
            }
        }
        if ($firstSource) {
            $erp = reportEngineFetchErpData($pdo, $domain, $firstSource, $filters);
            echo "ERP block ({$firstSource}): " . (strlen($erp['html'] ?? '') > 0 ? 'OK (' . strlen($erp['html']) . ' bytes)' : 'empty') . "\n";
        }
        echo "OK\n";
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
    }
}
