<?php
/**
 * Sales Report � export helpers (PDF, Word, Excel, Print).
 */

declare(strict_types=1);

require_once __DIR__ . '/sales-reports-lib.php';
require_once __DIR__ . '/sales-reports-data.php';
require_once __DIR__ . '/sales-reports-format.php';
require_once __DIR__ . '/ui-lib.php';

function salesReportsExportHtml(array $report, string $contentHtml, bool $forPrint = false): string
{
    $company = htmlspecialchars((string) ($_SESSION['company_name'] ?? 'Company'), ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars((string) ($report['report_name'] ?? 'Sales Report'), ENT_QUOTES, 'UTF-8');
    $period = salesReportsFormatPeriod((string) ($report['start_date'] ?? ''), (string) ($report['end_date'] ?? ''));

    $printCss = $forPrint ? '@media print { body { margin: 0; } .no-print { display: none; } }' : '';
    $hasCover = str_contains($contentHtml, 'sr-cover-page');
    $headerBlock = $hasCover ? '' : (
        '<div class="report-header">'
        . salesReportsCompanyLogoHtml('64px', 'top-right')
        . '<h1>' . $title . '</h1>'
        . '<p><strong>' . $company . '</strong></p>'
        . '<p>Reporting Period: ' . htmlspecialchars($period) . '</p></div>'
    );

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $title . '</title>'
        . salesReportsFontStylesheetTag()
        . '<style>
            @import url("https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap");
            body { font-family: "DM Sans", sans-serif; font-size: 11pt; color: #222; margin: 40px; line-height: 1.55; }
            h1 { font-size: 22pt; color: #1a1a2e; }
            h1, h2, h3, h4 { border: none !important; border-bottom: none !important; padding-bottom: 0; }
            h2 { font-size: 12pt; color: #1a1a2e; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 28px; margin-bottom: 10px; font-weight: 700; }
            h3 { font-size: 11pt; color: #333; text-transform: uppercase; margin-top: 18px; margin-bottom: 8px; font-weight: 700; }
            table { border-collapse: collapse; width: 100%; margin: 12px 0; }
            th, td { border: 1px solid #bbb; padding: 5px 7px; }
            th { background: #1a1a2e; color: #fff; font-size: 9pt; }
            .report-header { position: relative; text-align: center; margin-bottom: 30px; border-bottom: 3px solid #1a1a2e; padding-bottom: 20px; padding-top: 8px; min-height: 72px; }
            .sr-cover-page { position: relative; page-break-after: always; }
            .sr-company-logo--top-right { position: absolute; top: 0; right: 0; text-align: right; margin: 0; }
            .sr-company-logo img { display: inline-block; }
            .sr-section { page-break-inside: avoid; margin-bottom: 20px; }
            .sr-rep-appendix { page-break-before: always; }
            ul { margin: 8px 0 16px 20px; }
            li { margin-bottom: 6px; }
            .page-footer { margin-top: 40px; font-size: 9pt; color: #999; text-align: center; border-top: 1px solid #ddd; padding-top: 10px; }
            ' . $printCss . '
        </style></head><body>'
        . $headerBlock
        . $contentHtml
        . '<div class="page-footer">' . $company . ' - Confidential Sales Report</div>'
        . '</body></html>';
}

function salesReportsExportPdf(array $report, string $contentHtml): void
{
    $html = salesReportsExportHtml($report, $contentHtml);

    $docLayouts = dirname(__DIR__, 3) . '/includes/document_layouts.php';
    if (is_file($docLayouts)) {
        require_once $docLayouts;
        if (function_exists('downloadHtmlPdf')) {
            $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($report['report_name'] ?? 'sales_report')) . '.pdf';
            downloadHtmlPdf($html, $filename);
            return;
        }
    }

    // Fallback: open print dialog; filename kept as .pdf for consistency
    $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($report['report_name'] ?? 'sales_report')) . '.pdf';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $html . '<script>window.onload=function(){window.print();}</script>';
}

function salesReportsExportWord(array $report, string $contentHtml): void
{
    $html = salesReportsExportHtml($report, $contentHtml);
    $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($report['report_name'] ?? 'sales_report')) . '.doc';
    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $html;
}

function salesReportsExportExcel(array $report, PDO $pdo): void
{
    $filters = salesReportsFiltersFromReport($report);
    $transactions = salesReportsFetchErpData($pdo, 'sales_transactions', $filters);
    $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($report['report_name'] ?? 'sales_report')) . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sales Report Data Export']);
    fputcsv($out, ['Report', $report['report_name'] ?? '']);
    fputcsv($out, ['Period', salesReportsFormatPeriod($report['start_date'] ?? '', $report['end_date'] ?? '')]);
    fputcsv($out, []);
    fputcsv($out, ['Invoice', 'Date', 'Customer', 'Total', 'Paid', 'Balance', 'Status']);
    foreach ($transactions['snapshot'] ?? [] as $r) {
        fputcsv($out, [
            $r['invoice_number'] ?? '',
            $r['invoice_date'] ?? '',
            $r['customer_name'] ?? '',
            $r['total_amount'] ?? 0,
            $r['amount_paid'] ?? 0,
            $r['balance_due'] ?? 0,
            $r['status'] ?? '',
        ]);
    }
    fclose($out);
}
