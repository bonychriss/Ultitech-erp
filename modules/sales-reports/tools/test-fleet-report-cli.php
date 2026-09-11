<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__, 3);
require_once $root . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sales-reports-lib.php';
require_once dirname(__DIR__) . '/includes/report-engine.php';
require_once dirname(__DIR__) . '/includes/report-domain-autofill.php';

$tenant = connectToTenantDatabase('new_trading_voucher-35313030c7e2');
if (!$tenant instanceof PDO) {
    fwrite(STDERR, "Failed to connect tenant DB\n");
    exit(1);
}
$GLOBALS['pdo'] = $tenant;
$pdo = $tenant;
$_SESSION['company_name'] = 'Ultimate Trading';
$_SESSION['company_id'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Fleet Report Test';

$filters = ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'];
$kpis = reportDomainFleetKpis($pdo, $filters);

echo "=== Jan 2026 KPIs ===\n";
foreach ([
    'total_trips', 'completed_trips', 'planned_trips', 'in_transit_trips',
    'total_deliveries', 'completed_deliveries', 'completion_rate_pct',
    'on_time_rate_pct', 'on_time_sample_size', 'open_orders', 'pending_orders',
    'rejected_orders', 'overdue_orders', 'drivers_active', 'vehicles_used',
    'total_mileage', 'delivery_notes_count', 'avg_customer_rating',
] as $k) {
    echo str_pad($k, 24) . ': ' . var_export($kpis[$k] ?? null, true) . "\n";
}

$report = [
    'report_domain' => 'fleet',
    'start_date' => $filters['start_date'],
    'end_date' => $filters['end_date'],
    'report_name' => 'January 2026 Driver & Fleet Report',
    'department' => 'Logistics',
    'prepared_by' => 'Fleet Report Test',
    'filters_json' => '{}',
];

$sections = [];
foreach (reportEngineDefaultSections('fleet') as $key) {
    $sections[] = [
        'key' => $key,
        'title' => reportEngineSectionCatalog('fleet')[$key] ?? $key,
        'visible' => true,
        'content' => '',
    ];
}
$filled = reportEngineAutofillSections($pdo, $report, $sections);

echo "\n=== Autofill ===\n";
$fail = 0;
foreach ($filled as $s) {
    $html = (string) ($s['content'] ?? '');
    $len = strlen($html);
    $bad = $len < 80
        || stripos($html, 'outbound') !== false
        || stripos($html, 'Pitch to local') !== false
        || strpos($html, '_____') !== false;
    if ($s['key'] === 'kpi_fleet_overview' && strpos($html, '6 /') === false) {
        $bad = true;
    }
    echo ($bad ? 'BAD' : 'OK') . ' ' . $s['key'] . " len={$len}\n";
    if ($bad) {
        $fail++;
    }
}

$filterOpts = reportEngineFilterOptions($pdo, 'fleet');
echo "\nFilters: " . count($filterOpts['filters']) . ", drivers=" . count($filterOpts['options']['drivers'] ?? []) . "\n";

$driverId = $filterOpts['options']['drivers'][0]['value'] ?? '';
if ($driverId !== '') {
    $filtered = reportDomainFleetKpis($pdo, array_merge($filters, ['driver_id' => $driverId]));
    echo "Driver filter {$driverId}: trips=" . $filtered['total_trips'] . " (must be <= 6)\n";
    if ((int) $filtered['total_trips'] > 6) {
        $fail++;
    }
}

$statusFiltered = reportDomainFleetKpis($pdo, array_merge($filters, ['trip_status' => 'completed']));
echo "Status=completed trips=" . $statusFiltered['total_trips'] . " completed=" . $statusFiltered['completed_trips'] . "\n";
if ((int) $statusFiltered['total_trips'] !== (int) $statusFiltered['completed_trips']) {
    $fail++;
}

echo "\n" . ($fail === 0 ? "ALL OK\n" : "FAILED={$fail}\n");
exit($fail === 0 ? 0 : 1);
