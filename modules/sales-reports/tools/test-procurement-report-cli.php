<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__, 3);
require_once $root . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sales-reports-lib.php';
require_once dirname(__DIR__) . '/includes/report-engine.php';
require_once dirname(__DIR__) . '/includes/report-domain-autofill.php';
require_once dirname(__DIR__) . '/includes/sales-reports-export.php';

$tenant = connectToTenantDatabase('new_trading_voucher-35313030c7e2');
if (!$tenant instanceof PDO) {
    fwrite(STDERR, "Failed tenant connect\n");
    exit(1);
}
$GLOBALS['pdo'] = $tenant;
$pdo = $tenant;
$_SESSION['company_name'] = 'Ultimate Trading';
$_SESSION['company_id'] = 1;

echo 'domains: ' . implode(', ', array_keys(reportEngineDomains())) . "\n";
echo 'procurement normalize: ' . reportEngineNormalizeDomain('procurement') . "\n";
echo 'store alias stock: ' . reportEngineNormalizeDomain('stock') . "\n";

$filters = ['start_date' => '2026-03-01', 'end_date' => '2026-03-31'];
$kpis = reportDomainProcurementKpis($pdo, $filters);
echo "\n=== Mar 2026 KPIs ===\n";
foreach ([
    'purchase_count', 'domestic_count', 'import_count', 'domestic_value', 'import_value',
    'customs_value', 'total_procurement', 'domestic_pct', 'import_pct', 'customs_pct',
    'supplier_count', 'completed_count', 'pending_count', 'shipment_count',
    'pending_delivery_count', 'delayed_delivery_count',
] as $k) {
    echo str_pad($k, 26) . ': ' . var_export($kpis[$k] ?? null, true) . "\n";
}

// Independent SQL check
$dateExpr = 'COALESCE(purchase_date, created_at)';
$purch = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) v FROM purchases WHERE DATE({$dateExpr}) BETWEEN '2026-03-01' AND '2026-03-31' AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('cancelled','canceled')")->fetch(PDO::FETCH_ASSOC);
$clear = $pdo->query("SELECT COALESCE(SUM(COALESCE(estimated_clearance_cost,0)+COALESCE(customs_duty,0)+COALESCE(customs_brokerage,0)+COALESCE(port_charges,0)),0) c FROM shipments WHERE DATE(COALESCE(shipment_date,created_at)) BETWEEN '2026-03-01' AND '2026-03-31'")->fetchColumn();

$fail = 0;
$expectedDomestic = (float) $purch['v'];
$expectedCustoms = (float) $clear;
$expectedTotal = $expectedDomestic + (float) $kpis['import_value'] + $expectedCustoms;
echo "\nSQL purchases value={$expectedDomestic} customs={$expectedCustoms}\n";
if ((int) $kpis['purchase_count'] !== (int) $purch['c']) {
    echo "FAIL purchase_count\n";
    $fail++;
} else {
    echo "OK purchase_count\n";
}
if (abs((float) $kpis['domestic_value'] - $expectedDomestic) > 0.05) {
    echo "FAIL domestic_value got {$kpis['domestic_value']}\n";
    $fail++;
} else {
    echo "OK domestic_value\n";
}
if (abs((float) $kpis['customs_value'] - $expectedCustoms) > 0.05) {
    echo "FAIL customs_value got {$kpis['customs_value']}\n";
    $fail++;
} else {
    echo "OK customs_value\n";
}
if (abs((float) $kpis['total_procurement'] - $expectedTotal) > 0.05) {
    echo "FAIL total\n";
    $fail++;
} else {
    echo "OK total_procurement\n";
}

// No store sections
$sections = reportEngineDefaultSections('procurement');
foreach (['inventory_overview', 'stock_movement_analysis', 'low_stock_analysis', 'inventory_valuation'] as $bad) {
    if (in_array($bad, $sections, true)) {
        echo "FAIL store section present: {$bad}\n";
        $fail++;
    }
}
echo "OK no store sections in procurement defaults\n";

$report = [
    'report_domain' => 'procurement',
    'start_date' => '2026-03-01',
    'end_date' => '2026-03-31',
    'report_name' => 'March 2026 Procurement Report',
    'department' => 'Procurement',
    'prepared_by' => 'Test',
    'filters_json' => '{}',
];
$sectionRows = [];
foreach ($sections as $key) {
    $sectionRows[] = [
        'key' => $key,
        'title' => reportEngineSectionCatalog('procurement')[$key] ?? $key,
        'visible' => true,
        'content' => '',
    ];
}
$filled = reportEngineAutofillSections($pdo, $report, $sectionRows);
$htmlParts = [];
echo "\n=== Autofill ===\n";
foreach ($filled as $s) {
    $html = (string) ($s['content'] ?? '');
    $htmlParts[] = $html;
    $bad = strlen($html) < 40
        || stripos($html, 'Stock Overview') !== false
        || stripos($html, 'inventory valuation') !== false
        || stripos($html, 'units on hand') !== false;
    echo ($bad ? 'BAD' : 'OK') . ' ' . $s['key'] . ' len=' . strlen($html) . "\n";
    if ($bad) {
        $fail++;
    }
}

$export = salesReportsExportHtml($report, implode("\n", $htmlParts));
$out = $root . '/reports/Procurement_Report_March_2026_preview.html';
file_put_contents($out, $export);
echo "Wrote {$out}\n";

// Empty Aug should be idle-safe
$aug = reportDomainProcurementKpis($pdo, ['start_date' => '2026-08-01', 'end_date' => '2026-08-31']);
echo 'Aug idle: ' . (reportDomainProcurementIsIdle($aug) ? 'yes' : 'no') . " total={$aug['total_procurement']}\n";

echo "\n" . ($fail === 0 ? "ALL OK\n" : "FAILED={$fail}\n");
exit($fail === 0 ? 0 : 1);
