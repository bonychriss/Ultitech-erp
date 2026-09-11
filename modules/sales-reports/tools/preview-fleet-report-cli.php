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
    fwrite(STDERR, "Failed to connect tenant DB\n");
    exit(1);
}
$GLOBALS['pdo'] = $tenant;
$pdo = $tenant;
$_SESSION['company_name'] = 'Ultimate Trading';
$_SESSION['company_id'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Fleet Preview';

$filters = ['start_date' => '2026-01-01', 'end_date' => '2026-01-31'];
$reportMeta = [
    'report_domain' => 'fleet',
    'start_date' => $filters['start_date'],
    'end_date' => $filters['end_date'],
    'report_name' => 'January 2026 Driver & Fleet Report',
    'department' => 'Logistics',
    'prepared_by' => 'Fleet Preview',
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
$filled = reportEngineAutofillSections($pdo, $reportMeta, $sections);
$htmlParts = [];
foreach ($filled as $s) {
    $htmlParts[] = (string) ($s['content'] ?? '');
}
$html = implode("\n", array_filter($htmlParts));

$outDir = $root . '/reports';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$exportHtml = salesReportsExportHtml($reportMeta, $html);
$htmlPath = $outDir . '/Fleet_Report_January_2026_preview.html';
file_put_contents($htmlPath, $exportHtml);
echo "Wrote: {$htmlPath}\n";
echo 'Length: ' . strlen($exportHtml) . "\n";

// Also dump plain-text review of each section
$reviewPath = $outDir . '/Fleet_Report_January_2026_review.txt';
$review = [];
foreach ($filled as $s) {
    $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</tr>'], ["\n", "\n", "\n", "\n", "\n", "\n"], (string) ($s['content'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    $review[] = "===== {$s['key']} =====\n{$text}\n";
}
file_put_contents($reviewPath, implode("\n", $review));
echo "Wrote: {$reviewPath}\n";
