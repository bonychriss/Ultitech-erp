<?php
/**
 * Embeddable Purchase Order document for the create-voucher PO picker.
 * Renders the official PO sheet only (no stock desk chrome / redirects).
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$poId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
if ($poId <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:1.5rem;color:#b91c1c;">Invalid purchase order.</body></html>';
    exit;
}

$poViewLib = dirname(__DIR__, 2) . '/stock/modules/purchases/includes/po-view-lib.php';
if (!is_file($poViewLib)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:1.5rem;color:#b91c1c;">PO document renderer unavailable.</body></html>';
    exit;
}

require_once $poViewLib;

try {
    if (function_exists('poViewDeskBootstrap')) {
        poViewDeskBootstrap();
    }
    $ctx = poViewLoadContext($poId);
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:1.5rem;color:#b91c1c;">'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</body></html>';
    exit;
}

$documentHtml = (string) ($ctx['document_html'] ?? '');
$fontStylesheets = (string) ($ctx['font_stylesheets'] ?? '');
$docFontFamily = (string) ($ctx['document_font_family'] ?? "'Arima', Arial, sans-serif");
$displayNo = (string) ($ctx['display_po_number'] ?? ('PO-' . $poId));

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');
$isEmbed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
?><!DOCTYPE html>
<html lang="en"<?= $isEmbed ? ' class="po-embed"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars('Purchase Order ' . $displayNo, ENT_QUOTES, 'UTF-8') ?></title>
    <?= $fontStylesheets ?>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #e8edf3;
            color: #111;
            font-family: <?= htmlspecialchars($docFontFamily, ENT_QUOTES, 'UTF-8') ?>;
        }
        body {
            padding: 20px 16px 32px;
        }
        html.po-embed,
        html.po-embed body {
            overflow: hidden !important;
            background: #fff;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        html.po-embed::-webkit-scrollbar,
        html.po-embed body::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        html.po-embed body {
            padding: 8px;
        }
        html.po-embed .sheet-container {
            box-shadow: none;
        }
        #order-content {
            margin: 0 auto;
            max-width: 210mm;
        }
        .sheet-container {
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            padding: 8mm 8mm;
            background: #fff;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.12);
            position: relative;
            box-sizing: border-box;
        }
        .sheet { background: #fff; position: relative; }
        .sheet-title { font-size: 18pt; font-weight: 700; color: #1e293b; margin: 0; }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
            margin-bottom: 18px;
        }
        .form-value { font-size: 0.95rem; color: #111827; }
        .notebook { margin-top: 8px; }
        .o-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; table-layout: fixed; }
        .o-table th {
            border-bottom: 2px solid #000;
            text-transform: uppercase;
            font-size: 9pt;
            font-weight: 600;
            background-color: gold;
            color: #000;
            padding: 8px 5px;
            vertical-align: middle;
        }
        .o-table td {
            padding: 8px 5px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            font-size: 10pt;
        }
        .o-table .num { text-align: right; }
        .o-table th.num { text-align: right; }
        .o-table img {
            max-width: 48px;
            max-height: 48px;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }
        .totals-area {
            display: flex;
            justify-content: flex-end;
            margin-top: 12px;
        }
        .o-totals-table {
            width: min(100%, 340px);
            border-collapse: collapse;
            font-size: 10.25pt;
        }
        .o-totals-table td {
            padding: 3px 6px;
            vertical-align: top;
            line-height: 1.35;
            color: #000;
        }
        .o-totals-table td:last-child {
            text-align: right;
            white-space: nowrap;
        }
        .o-totals-table .o-totals-grand td {
            border-top: 1px solid #d1d5db;
            border-bottom: 1px solid #d1d5db;
            padding-top: 6px;
            padding-bottom: 6px;
            font-weight: 700;
        }
        .text-muted { color: #64748b; }
        .mb-0 { margin-bottom: 0; }
        .mb-1 { margin-bottom: 0.25rem; }
        .fw-bold { font-weight: 700; }
        @media (max-width: 700px) {
            body { padding: 8px; }
            .sheet-container { padding: 5mm; box-shadow: none; }
            .form-grid { grid-template-columns: 1fr; gap: 12px; }
            .sheet-title { font-size: 14pt; }
        }
    </style>
</head>
<body>
<?= $documentHtml !== '' ? $documentHtml : '<p style="padding:2rem;text-align:center;color:#b91c1c;">Document could not be rendered.</p>' ?>
</body>
</html>
