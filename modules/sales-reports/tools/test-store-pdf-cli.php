<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sales-reports-lib.php';
require_once dirname(__DIR__) . '/includes/report-engine.php';
require_once dirname(__DIR__) . '/includes/report-domain-autofill.php';
require_once dirname(__DIR__) . '/includes/sales-reports-export.php';
require_once dirname(__DIR__, 3) . '/includes/document_layouts.php';

$pdo = connectToTenantDatabase('new_trading_voucher-35313030c7e2');
$GLOBALS['pdo'] = $pdo;
$_SESSION['company_name'] = 'Ultimate Trading';
$_SESSION['company_id'] = 1;
$_SESSION['user_id'] = 1;

$report = $pdo->query("SELECT * FROM sales_reports WHERE report_domain IN ('procurement','store_warehouse') AND deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$report) {
    fwrite(STDERR, "No store report found\n");
    exit(1);
}
$id = (int) $report['id'];
echo "Using report #{$id}: {$report['report_name']}\n";

$doc = salesReportsGetDocument($pdo, $id);
$sections = json_decode((string) ($doc['sections_json'] ?? '[]'), true) ?: [];
$htmlBody = salesReportsUiMergeDocumentHtml($doc, $sections);
if (trim(strip_tags($htmlBody)) === '') {
    $fill = reportEngineApplyAutofill($pdo, $id, true);
    $htmlBody = (string) ($fill['content_html'] ?? '');
    echo "Autofilled, html=" . strlen($htmlBody) . "\n";
}

$full = salesReportsExportHtml($report, $htmlBody);
// Strip google fonts for mPDF
$full = preg_replace('/<link[^>]+fonts\.googleapis[^>]*>/i', '', $full) ?? $full;
$full = preg_replace('/@import\s+url\(["\']?https?:\/\/fonts\.googleapis\.com[^)]+\)\s*;?/i', '', $full) ?? $full;
$full = str_replace('"DM Sans", sans-serif', 'dejavusans, sans-serif', $full);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp_mpdf';
@mkdir($tempDir, 0777, true);
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 10,
    'margin_right' => 10,
    'margin_top' => 10,
    'margin_bottom' => 12,
    'tempDir' => $tempDir,
    'default_font' => 'dejavusans',
]);
$mpdf->WriteHTML($full);
$out = dirname(__DIR__, 3) . '/reports/Store_Report_PDF_test.pdf';
$mpdf->Output($out, \Mpdf\Output\Destination::FILE);
$bytes = filesize($out);
$fh = fopen($out, 'rb');
$magic = fread($fh, 5);
fclose($fh);
echo "Wrote {$out} ({$bytes} bytes), magic={$magic}\n";
echo (str_starts_with($magic, '%PDF') ? "VALID PDF\n" : "INVALID\n");
