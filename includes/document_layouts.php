<?php
/**
 * Document layout helpers (PDF + base URL).
 *
 * This project has a layout designer (document_layouts table), but some modules
 * need a simple, reliable PDF export even if no designer-driven layouts exist yet.
 */

/**
 * Base URL used inside documents for absolute assets/links.
 */
function getDocumentBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Try to detect "/staff" base (works for localhost + deployed).
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $pos = strpos($script, '/');
    $basePath = ($pos !== false) ? substr($script, 0, $pos + strlen('/')) : '/';

    return $scheme . '://' . $host . rtrim($basePath, '/');
}

/**
 * Render HTML for a document type.
 *
 * @param string $type
 * @param array<string,mixed> $vars
 */
function renderDocumentHtml(string $type, array $vars): string
{
    if ($type === 'dispatch_report') {
        $records = $vars['records'] ?? [];
        $summary = $vars['summary'] ?? ['count' => 0, 'total_price' => 0];
        $periodLabel = (string) ($vars['periodLabel'] ?? '');

        $rowsHtml = '';
        foreach ($records as $r) {
            $num = htmlspecialchars((string) ($r['dispatch_number'] ?? ''));
            $date = htmlspecialchars((string) ($r['dispatch_date'] ?? ''));
            $route = htmlspecialchars((string) (($r['dispatch_from'] ?? '-') . ' ? ' . ($r['dispatch_to'] ?? '-')));
            $price = number_format((float) ($r['route_price'] ?? 0), 2);
            $addr = htmlspecialchars((string) ($r['address_to'] ?? ''));
            $contents = htmlspecialchars((string) ($r['contents'] ?? ''));
            $by = htmlspecialchars((string) ($r['full_name'] ?? ''));
            $rowsHtml .= "<tr>
                <td>{$num}</td>
                <td>{$date}</td>
                <td>{$route}</td>
                <td style=\"text-align:right;\">{$price}</td>
                <td>{$addr}</td>
                <td>{$contents}</td>
                <td>{$by}</td>
            </tr>";
        }

        $count = (int) ($summary['count'] ?? 0);
        $total = number_format((float) ($summary['total_price'] ?? 0), 2);
        $company = defined('COMPANY_NAME') ? COMPANY_NAME : 'Company';

        return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: sans-serif; font-size: 12px; color: #111827; }
    .h1 { font-size: 18px; font-weight: 700; margin: 0; }
    .muted { color: #6b7280; }
    .meta { margin-top: 6px; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th { background: #111827; color: #fff; text-transform: uppercase; letter-spacing: .06em; font-size: 10px; padding: 8px; text-align: left; }
    td { border-bottom: 1px solid #e5e7eb; padding: 8px; vertical-align: top; }
    .summary { margin-top: 10px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 10px; }
    .row { display: flex; gap: 18px; }
    .k { font-weight: 700; }
  </style>
  <title>Dispatch Report</title>
</head>
<body>
  <div class="h1">' . htmlspecialchars((string) $company) . ' ? Dispatch Report</div>
  <div class="meta muted">Period: ' . htmlspecialchars($periodLabel) . ' ? Generated: ' . date('Y-m-d H:i') . '</div>

  <div class="summary">
    <div class="row">
      <div><span class="k">Total records:</span> ' . $count . '</div>
      <div><span class="k">Total route price:</span> ' . $total . '</div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Date</th>
        <th>Route</th>
        <th style="text-align:right;">Price</th>
        <th>Address</th>
        <th>Contents</th>
        <th>Created by</th>
      </tr>
    </thead>
    <tbody>' . $rowsHtml . '</tbody>
  </table>
</body>
</html>';
    }

    if ($type === 'customer_statement') {
        $customer = $vars['customer'] ?? null;
        $dateFrom = (string) ($vars['dateFrom'] ?? '');
        $dateTo = (string) ($vars['dateTo'] ?? '');
        $monthly = $vars['monthly'] ?? [];
        $grandTotal = (float) ($vars['grandTotal'] ?? 0);
        $sumPaid = (float) ($vars['sumPaid'] ?? 0);
        $sumBalance = (float) ($vars['sumBalance'] ?? 0);
        $openingBalance = (float) ($vars['openingBalance'] ?? 0);

        $company = defined('COMPANY_NAME') ? COMPANY_NAME : 'Company';
        $custName = htmlspecialchars((string) ($customer['company_name'] ?? 'Customer'));
        $custCode = htmlspecialchars((string) ($customer['customer_code'] ?? ''));

        $sectionsHtml = '';
        foreach ($monthly as $m) {
            $label = htmlspecialchars((string) ($m['label'] ?? ''));
            $mt = number_format((float) ($m['total'] ?? 0), 2);
            $mp = number_format((float) ($m['total_paid'] ?? 0), 2);
            $mb = number_format((float) ($m['total_balance'] ?? 0), 2);
            $body = '';
            foreach (($m['rows'] ?? []) as $r) {
                $inv = htmlspecialchars((string) ($r['invoice_number'] ?? ''));
                $dFmt = htmlspecialchars((string) ($r['invoice_date_fmt'] ?? $r['invoice_date'] ?? ''));
                $isOpRow = !empty($r['is_opening']);
                if (function_exists('customer_statement_due_column_parts')) {
                    $dueP = customer_statement_due_column_parts($r, $isOpRow);
                    $dueFmt = htmlspecialchars($dueP['primary']);
                } else {
                    $dueFmt = htmlspecialchars((string) ($r['due_date_fmt'] ?? $r['due_date'] ?? ''));
                }
                $trStyle = '';
                if (function_exists('customer_statement_row_is_paid')) {
                    if ($isOpRow) {
                        $trStyle = ' style="color:#374151;"';
                    } elseif (customer_statement_row_is_paid($r, $isOpRow)) {
                        $trStyle = ' style="color:#2563eb;"';
                    } else {
                        $trStyle = ' style="color:#dc2626;"';
                    }
                }
                $os = htmlspecialchars((string) ($r['order_status'] ?? ''));
                $ps = htmlspecialchars((string) ($r['payment_status_label'] ?? ''));
                $amt = number_format((float) ($r['total_amount'] ?? 0), 2);
                $paid = number_format((float) ($r['amount_paid'] ?? 0), 2);
                $bal = number_format((float) ($r['line_balance'] ?? 0), 2);
                $body .= "<tr{$trStyle}>
                    <td>{$inv}</td>
                    <td>{$dFmt}</td>
                    <td style=\"text-align:center; vertical-align:middle;\">{$dueFmt}</td>
                    <td>{$os}</td>
                    <td>{$ps}</td>
                    <td style=\"text-align:right; font-weight:700;\">{$amt}</td>
                    <td style=\"text-align:right;\">{$paid}</td>
                    <td style=\"text-align:right; font-weight:700;\">{$bal}</td>
                </tr>";
            }
            $body .= "<tr>
                <td colspan=\"5\" style=\"font-weight:700; background:#f3f4f6;\">Month total</td>
                <td style=\"text-align:right; font-weight:700; background:#f3f4f6;\">{$mt}</td>
                <td style=\"text-align:right; font-weight:700; background:#f3f4f6;\">{$mp}</td>
                <td style=\"text-align:right; font-weight:700; background:#f3f4f6;\">{$mb}</td>
            </tr>";

            $sectionsHtml .= "<div class=\"section-title\">{$label}</div>
<table>
  <thead>
    <tr>
      <th>Invoice #</th>
      <th>Invoice date</th>
      <th style=\"text-align:center;\">Due (days)</th>
      <th>Order</th>
      <th>Status</th>
      <th style=\"text-align:right;\">Total</th>
      <th style=\"text-align:right;\">Paid</th>
      <th style=\"text-align:right;\">Balance</th>
    </tr>
  </thead>
  <tbody>{$body}</tbody>
</table>";
        }

        return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: sans-serif; font-size: 11px; color: #111827; }
    .h1 { font-size: 18px; font-weight: 700; margin: 0; }
    .muted { color: #6b7280; }
    .meta { margin-top: 6px; }
    .box { margin-top: 12px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 10px; }
    .section-title { margin-top: 14px; font-size: 14px; font-weight: 800; text-align: center; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th { background: #111827; color: #fff; text-transform: uppercase; letter-spacing: .04em; font-size: 9px; padding: 6px; text-align: left; }
    td { border-bottom: 1px solid #e5e7eb; padding: 6px; vertical-align: top; }
    .row { display: flex; gap: 18px; flex-wrap: wrap; }
    .k { font-weight: 700; }
  </style>
  <title>Customer Statement</title>
</head>
<body>
  <div class="h1">' . htmlspecialchars((string) $company) . ' - Customer Statement</div>
  <div class="meta muted">Customer: ' . $custName . ($custCode !== '' ? ' (' . $custCode . ')' : '') . '</div>
  <div class="meta muted">Period: ' . htmlspecialchars($dateFrom) . ' to ' . htmlspecialchars($dateTo) . ' - Generated: ' . date('Y-m-d H:i') . '</div>

  <div class="box">
    <div class="row">
      <div><span class="k">Opening (before period):</span> ' . number_format($openingBalance, 2) . '</div>
      <div><span class="k">Period invoiced:</span> ' . number_format($grandTotal, 2) . '</div>
      <div><span class="k">Period paid:</span> ' . number_format($sumPaid, 2) . '</div>
      <div><span class="k">Period balance:</span> ' . number_format($sumBalance, 2) . '</div>
    </div>
  </div>

  ' . $sectionsHtml . '
</body>
</html>';
    }

    throw new RuntimeException('Unknown document type: ' . $type);
}

/**
 * Normalize a download filename so it always ends with .pdf.
 */
function sanitizePdfFileName(string $fileName): string
{
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', trim($fileName));
    if (!is_string($safe) || $safe === '' || $safe === '_') {
        $safe = 'document';
    }
    $safe = preg_replace('/\.(html?|docx?|csv)$/i', '', $safe);
    if (!preg_match('/\.pdf$/i', $safe)) {
        $safe .= '.pdf';
    }

    return $safe;
}

/**
 * Download pre-rendered HTML as a PDF (or printable HTML fallback).
 */
function downloadHtmlPdf(string $html, string $fileName): void
{
    $fileName = sanitizePdfFileName($fileName);

    if (function_exists('iconv')) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $html);
        if (is_string($clean) && $clean !== '') {
            $html = $clean;
        }
    }

    $autoloadCandidates = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../meeting/vendor/autoload.php',
    ];
    foreach ($autoloadCandidates as $autoload) {
        if (file_exists($autoload)) {
            require_once $autoload;
            break;
        }
    }

    if (class_exists('\Mpdf\Mpdf')) {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp_mpdf';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }
        if (!is_writable($tempDir)) {
            $tempDir = sys_get_temp_dir();
        }

        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 12,
                'tempDir' => $tempDir,
            ]);
            $mpdf->WriteHTML($html);
            $mpdf->Output($fileName, 'D');
            exit;
        } catch (Throwable $e) {
            error_log('downloadHtmlPdf mPDF error: ' . $e->getMessage());
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo $html;
    echo '<script>window.onload=function(){window.print();}</script>';
    exit;
}

/**
 * Download a PDF for a given document type.
 *
 * @param string $type
 * @param array<string,mixed> $vars
 * @param string $fileName
 */
function downloadDocumentPdf(string $type, array $vars, string $fileName): void
{
    downloadHtmlPdf(renderDocumentHtml($type, $vars), $fileName);
}

