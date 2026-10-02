<?php
/**
 * Sales Report � export helpers (PDF, Word, Excel, Print).
 */

declare(strict_types=1);

require_once __DIR__ . '/sales-reports-lib.php';
require_once __DIR__ . '/sales-reports-data.php';
require_once __DIR__ . '/sales-reports-format.php';
require_once __DIR__ . '/ui-lib.php';

function salesReportsExportHtml(array $report, string $contentHtml, bool $forPrint = false, bool $forPdf = false): string
{
    $company = htmlspecialchars((string) ($_SESSION['company_name'] ?? 'Company'), ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars((string) ($report['report_name'] ?? 'Sales Report'), ENT_QUOTES, 'UTF-8');
    $period = salesReportsFormatPeriod((string) ($report['start_date'] ?? ''), (string) ($report['end_date'] ?? ''));
    $domain = function_exists('reportEngineReportDomain')
        ? reportEngineReportDomain($report)
        : strtolower((string) ($report['report_domain'] ?? 'sales'));
    $footerLabel = match ($domain) {
        'procurement' => 'Confidential Procurement Report',
        'store_warehouse' => 'Confidential Store Report',
        'finance' => 'Confidential Finance Report',
        'fleet' => 'Confidential Delivery and Logistics Report',
        'operations' => 'Confidential Operations Report',
        default => 'Confidential Sales Report',
    };

    $printCss = $forPrint
        ? '@media screen { html, body { background: #e8e6e3; margin: 0; } .sr-cover-page { margin: 0; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: none; } .sr-cover-page::after { display: none; } .sr-report-body { background: transparent; } .sr-editor-page { display: block; background: #fff; min-height: 980px; margin: 0; padding: 72px 96px 96px; border: 0; border-radius: 12px; overflow: hidden; box-shadow: none; } .sr-page-gap { display: block; height: 36px; margin: 0; padding: 0; background: transparent; border: 0; outline: 0; box-shadow: none; overflow: hidden; } }'
        . '@media print { html, body { margin: 0; background: #fff; } .no-print { display: none; } .sr-page-gap { display: none !important; height: 0 !important; } .sr-cover-page, .sr-editor-page { page-break-after: always; break-after: page; background: #fff; box-shadow: none; } .sr-editor-page:last-child { page-break-after: auto; break-after: auto; } }'
        : '';
    $contentHtml = salesReportsStripEditorPageChrome($contentHtml);
    $contentHtml = salesReportsEnsureCoverHtml($report, $contentHtml);
    if ($forPdf && function_exists('reportEngineRewriteCoverImagesForPdf')) {
        $contentHtml = reportEngineRewriteCoverImagesForPdf($contentHtml);
    }
    $hasCover = str_contains($contentHtml, 'sr-cover-page');
    $headerBlock = $hasCover ? '' : (
        '<div class="report-header">'
        . salesReportsCompanyLogoHtml('64px', 'top-right')
        . '<h1>' . $title . '</h1>'
        . '<p><strong>' . $company . '</strong></p>'
        . '<p>Reporting Period: ' . htmlspecialchars($period) . '</p></div>'
    );

    // Prefer DejaVu for PDF engines; keep DM Sans name for browser print/Word where web fonts load.
    $fontStack = '"DM Sans", DejaVu Sans, sans-serif';
    $bodyMargin = $hasCover ? '0 40px 0' : '40px';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $title . '</title>'
        . salesReportsFontStylesheetTag()
        . '<style>
            body { font-family: ' . $fontStack . '; font-size: 11pt; color: #222; margin: ' . $bodyMargin . '; line-height: 1.55; }
            h1 { font-size: 22pt; color: #1a1a2e; }
            h1, h2, h3, h4 { border: none !important; border-bottom: none !important; padding-bottom: 0; }
            h2 { font-size: 12pt; color: #1a1a2e; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 28px; margin-bottom: 10px; font-weight: 700; }
            h3 { font-size: 11pt; color: #333; text-transform: uppercase; margin-top: 18px; margin-bottom: 8px; font-weight: 700; }
            table { border-collapse: collapse; width: 100%; margin: 12px 0; }
            .sr-report-body th, .sr-report-body td { border: 1px solid #d7dbe3; padding: 7px 9px; vertical-align: top; }
            th { background: #1a1a2e; color: #fff; font-size: 9pt; text-align: left; }
            td.sr-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
            .report-header { position: relative; text-align: center; margin-bottom: 30px; border-bottom: 3px solid #1a1a2e; padding-bottom: 20px; padding-top: 8px; min-height: 72px; }
            .sr-cover-page { position: relative; page-break-after: always; text-align: left; background: #fff; min-height: 250mm; }
            .sr-cover-page > table { height: 250mm; }
            .sr-cover-page table { width: 100%; margin: 0; border-collapse: collapse; }
            .sr-cover-page td { border: 0; padding: 0; vertical-align: top; }
            .sr-company-logo--top-right { position: absolute; top: 0; right: 0; text-align: right; margin: 0; }
            .sr-company-logo img { display: inline-block; }
            .sr-section { page-break-inside: avoid; margin-bottom: 20px; }
            .sr-rep-appendix { page-break-before: always; }
            ul { margin: 8px 0 16px 20px; }
            li { margin-bottom: 6px; }
            .page-footer { margin-top: 40px; font-size: 9pt; color: #999; text-align: center; border-top: 1px solid #ddd; padding-top: 10px; }
            .sr-muted { color: #667085; }
            .sr-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 14px 0 18px; }
            .sr-kpi-card { border: 1px solid #d7dbe3; background: #f7f8fb; padding: 12px 14px; border-radius: 8px; }
            .sr-kpi-card--primary { background: #1a1a2e; color: #fff; border-color: #1a1a2e; }
            .sr-kpi-card--ok { border-color: #86efac; background: #f0fdf4; }
            .sr-kpi-card--warn { border-color: #fcd34d; background: #fffbeb; }
            .sr-kpi-label { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.8; margin-bottom: 4px; }
            .sr-kpi-value { font-size: 13pt; font-weight: 700; line-height: 1.2; }
            .sr-row-total td { font-weight: 700; background: #eef1f6; }
            .sr-notes { margin-top: 12px; padding: 10px 12px; background: #f8fafc; border-left: 3px solid #1a1a2e; }
            .sr-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 8.5pt; font-weight: 600; white-space: nowrap; }
            .sr-badge--ok { background: #dcfce7; color: #166534; }
            .sr-badge--warn { background: #fef3c7; color: #92400e; }
            .sr-badge--danger { background: #fee2e2; color: #991b1b; }
            .sr-badge--info { background: #e0e7ff; color: #3730a3; }
            .sr-pct-cell { position: relative; min-width: 72px; }
            .sr-pct-bar { position: absolute; left: 0; top: 50%; transform: translateY(-50%); height: 8px; background: #94a3b8; border-radius: 4px; opacity: 0.35; max-width: 100%; }
            .sr-pct-label { position: relative; z-index: 1; }
            .sr-change-up { color: #166534; font-weight: 600; }
            .sr-change-down { color: #991b1b; font-weight: 600; }
            .sr-proc-activities td:nth-child(2) { font-size: 10pt; }
            @media print {
              .sr-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
              .sr-kpi-card { break-inside: avoid; }
            }
            @media (max-width: 900px) {
              .sr-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            ' . $printCss . '
        </style></head><body>'
        . $headerBlock
        . salesReportsExportBodyWithCover($contentHtml, $company, $footerLabel)
        . ($forPrint ? salesReportsScreenPageScript() : '')
        . '</body></html>';
}

function salesReportsStripEditorPageChrome(string $html): string
{
    $html = preg_replace('/<div[^>]*class="[^"]*sr-page-gap[^"]*"[^>]*>.*?<\/div>/is', '', $html) ?? $html;
    $offset = 0;
    while (($pos = stripos($html, 'sr-editor-page', $offset)) !== false) {
        $start = strripos(substr($html, 0, $pos), '<div');
        if ($start === false) {
            break;
        }
        $end = salesReportsFindDivEnd($html, $start);
        $openEnd = strpos($html, '>', $start);
        if ($end === null || $openEnd === false || $openEnd >= $end) {
            break;
        }
        $inner = substr($html, $openEnd + 1, ($end - 6) - ($openEnd + 1));
        $html = substr($html, 0, $start) . $inner . substr($html, $end);
        $offset = $start;
    }

    return $html;
}

function salesReportsScreenPageScript(): string
{
    return '<script>(function(){function paginate(){var cover=document.querySelector(".sr-cover-page");var body=document.querySelector(".sr-report-body");if(!body)return;body.querySelectorAll(".sr-editor-page").forEach(function(page){var parent=page.parentNode;while(page.firstChild)parent.insertBefore(page.firstChild,page);page.remove();});document.querySelectorAll(".sr-page-gap").forEach(function(node){node.remove();});var pageHeight=cover&&cover.offsetHeight>=700&&cover.offsetHeight<=1400?cover.offsetHeight:980;var inner=Math.max(480,pageHeight-168);var blocks=[].filter.call(body.children,function(el){return el.nodeType===1;});var groups=[];var current=[];var used=0;blocks.forEach(function(el){var cs=getComputedStyle(el);var h=el.offsetHeight+(parseFloat(cs.marginTop)||0)+(parseFloat(cs.marginBottom)||0);if(current.length&&used+h>inner){groups.push(current);current=[el];used=h;return;}current.push(el);used+=h;});if(current.length)groups.push(current);function gap(){var node=document.createElement("div");node.className="sr-page-gap";return node;}if(cover)cover.after(gap());groups.forEach(function(group,index){var page=document.createElement("div");page.className="sr-editor-page";group[0].before(page);group.forEach(function(el){page.appendChild(el);});if(index>0)page.before(gap());});}if(document.readyState==="complete")paginate();else window.addEventListener("load",paginate);})();</script>';
}

function salesReportsExportBodyWithCover(string $contentHtml, string $company, string $footerLabel): string
{
    $coverHtml = '';
    $pos = stripos($contentHtml, 'sr-cover-page');
    if ($pos !== false) {
        $start = strripos(substr($contentHtml, 0, $pos), '<div');
        $end = $start === false ? null : salesReportsFindDivEnd($contentHtml, $start);
        if ($start !== false && $end !== null) {
            $coverHtml = substr($contentHtml, $start, $end - $start);
            $contentHtml = substr($contentHtml, $end);
        }
    }

    return $coverHtml
        . '<div class="sr-report-body">'
        . $contentHtml
        . '<div class="page-footer">' . $company . ' - ' . htmlspecialchars($footerLabel, ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div>';
}

function salesReportsExportPdf(array $report, string $contentHtml): void
{
    $html = salesReportsExportHtml($report, $contentHtml, false, true);
    $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($report['report_name'] ?? 'sales_report')) . '.pdf';

    $docLayouts = dirname(__DIR__, 3) . '/includes/document_layouts.php';
    if (is_file($docLayouts)) {
        require_once $docLayouts;
        if (function_exists('downloadHtmlPdf')) {
            downloadHtmlPdf($html, $filename);
            return;
        }
    }

    // Fallback print view (HTML) — never label as .pdf
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . preg_replace('/\.pdf$/i', '.html', $filename) . '"');
    echo $html . '<script>window.onload=function(){window.print();}</script>';
    exit;
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
