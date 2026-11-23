<?php
if (!defined('GL_VIEW')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/gl/gl_view_helpers.php';

[$from, $to] = gl_period_params();
$rows = isset($trialBalanceRows) ? $trialBalanceRows : gl_account_movements($pdo, $from, $to);
$export = (string) ($_GET['export'] ?? '');
$accountSearch = trim((string) ($_GET['account_search'] ?? ''));
if (in_array($export, ['csv', 'pdf'], true) && $accountSearch !== '') {
    $searchNeedle = strtolower($accountSearch);
    $rows = array_values(array_filter($rows, static function (array $row) use ($searchNeedle): bool {
        $searchText = strtolower(trim(
            (string) $row['code'] . ' ' . (string) $row['name'] . ' ' . (string) $row['type']
        ));
        return strpos($searchText, $searchNeedle) !== false;
    }));
}
$tot = ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing_dr' => 0.0, 'closing_cr' => 0.0];
foreach ($rows as $r) {
    $tot['opening'] += $r['opening'];
    $tot['debit'] += $r['debit'];
    $tot['credit'] += $r['credit'];
    if ($r['closing'] >= 0) {
        $tot['closing_dr'] += $r['closing'];
    } else {
        $tot['closing_cr'] += -$r['closing'];
    }
}
$variance = round($tot['closing_dr'] - $tot['closing_cr'], 2);
$balanced = abs($variance) < 0.005 && abs($tot['debit'] - $tot['credit']) < 0.005;
$module = (string) ($_GET['module'] ?? 'accounting');

if ($export === 'csv') {
    $csv = [];
    foreach ($rows as $r) {
        $csv[] = [
            $r['code'],
            $r['name'],
            ucfirst($r['type']),
            $r['opening'],
            $r['debit'],
            $r['credit'],
            $r['closing'] >= 0 ? $r['closing'] : 0,
            $r['closing'] < 0 ? -$r['closing'] : 0,
        ];
    }
    gl_send_csv("trial-balance-{$to}.csv", ['Code', 'Account', 'Type', 'Opening', 'Debit', 'Credit', 'Closing debit', 'Closing credit'], $csv);
}

if ($export === 'pdf') {
    $pdfAscii = static function (string $text): string {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            return '';
        }
        return preg_replace('/[^\x20-\x7E]/', '', $converted) ?? '';
    };
    $pdfEscape = static function (string $text) use ($pdfAscii): string {
        return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $pdfAscii($text));
    };
    $pdfText = static function (float $x, float $y, float $size, string $text, bool $rightAligned = false) use ($pdfEscape, $pdfAscii): string {
        $plain = $pdfAscii($text);
        if ($rightAligned) {
            $x -= strlen($plain) * $size * 0.52;
        }
        return sprintf("BT /F1 %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $size, $x, $y, $pdfEscape($plain));
    };

    $pdfRows = [];
    foreach ($rows as $row) {
        $closing = (float) $row['closing'];
        $pdfRows[] = [
            (string) $row['code'],
            substr($pdfAscii((string) $row['name']), 0, 36),
            substr($pdfAscii(ucfirst((string) $row['type'])), 0, 14),
            number_format((float) $row['opening'], 2),
            number_format((float) $row['debit'], 2),
            number_format((float) $row['credit'], 2),
            $closing > 0.004 ? number_format($closing, 2) : '',
            $closing < -0.004 ? number_format(-$closing, 2) : '',
        ];
    }

    $pdfLogo = null;
    if (function_exists('getCompanyLogoUrl') && function_exists('imagecreatefromstring')) {
        $logoUrl = getCompanyLogoUrl();
        $logoPath = parse_url($logoUrl, PHP_URL_PATH);
        $logoHost = parse_url($logoUrl, PHP_URL_HOST);
        $requestHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (is_string($logoPath) && $logoPath !== '' && ($logoHost === null || strcasecmp((string) $logoHost, $requestHost) === 0)) {
            $appBasePath = '/' . trim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
            if ($appBasePath !== '/' && str_starts_with($logoPath, $appBasePath . '/')) {
                $logoPath = substr($logoPath, strlen($appBasePath) + 1);
            } else {
                $logoPath = ltrim($logoPath, '/');
            }
            $appRoot = realpath(dirname(__DIR__, 2));
            $logoFile = $appRoot !== false
                ? $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($logoPath))
                : '';
            $resolvedLogoFile = $logoFile !== '' ? realpath($logoFile) : false;
            if ($appRoot !== false
                && $resolvedLogoFile !== false
                && str_starts_with($resolvedLogoFile, $appRoot . DIRECTORY_SEPARATOR)
                && is_file($resolvedLogoFile)
            ) {
                $imageInfo = @getimagesize($resolvedLogoFile);
                $imageBytes = @file_get_contents($resolvedLogoFile);
                if ($imageInfo && is_string($imageBytes) && $imageBytes !== '') {
                    $sourceImage = @imagecreatefromstring($imageBytes);
                    if ($sourceImage !== false) {
                        $sourceWidth = imagesx($sourceImage);
                        $sourceHeight = imagesy($sourceImage);
                        $scale = min(1, 180 / max(1, $sourceWidth), 54 / max(1, $sourceHeight));
                        $logoWidth = max(1, (int) round($sourceWidth * $scale));
                        $logoHeight = max(1, (int) round($sourceHeight * $scale));
                        $canvas = imagecreatetruecolor($logoWidth, $logoHeight);
                        $white = imagecolorallocate($canvas, 255, 255, 255);
                        imagefill($canvas, 0, 0, $white);
                        imagecopyresampled($canvas, $sourceImage, 0, 0, 0, 0, $logoWidth, $logoHeight, $sourceWidth, $sourceHeight);
                        ob_start();
                        $jpegCreated = imagejpeg($canvas, null, 88);
                        $jpegBytes = ob_get_clean();
                        if ($jpegCreated && is_string($jpegBytes) && $jpegBytes !== '') {
                            $pdfLogo = ['bytes' => $jpegBytes, 'width' => $logoWidth, 'height' => $logoHeight];
                        }
                        imagedestroy($canvas);
                        imagedestroy($sourceImage);
                    }
                }
            }
        }
    }

    $pdfPages = [];
    $pdfRowPages = array_chunk($pdfRows, 32);
    if (!$pdfRowPages) {
        $pdfRowPages = [[]];
    }
    foreach ($pdfRowPages as $pageIndex => $pageRows) {
        $content = '';
        if ($pdfLogo !== null) {
            $logoDrawWidth = 72.0;
            $logoDrawHeight = $logoDrawWidth * $pdfLogo['height'] / max(1, $pdfLogo['width']);
            $logoY = 536 + (32 - $logoDrawHeight) / 2;
            $content .= sprintf("q %.2F 0 0 %.2F 32 %.2F cm /Logo Do Q\n", $logoDrawWidth, $logoDrawHeight, $logoY);
        }
        $titleX = $pdfLogo !== null ? 120.0 : 32.0;
        $content .= $pdfText($titleX, 558, 15, 'Trial Balance');
        $content .= $pdfText($titleX, 540, 9, "Period: {$from} to {$to}");
        $content .= $pdfText(810, 540, 8, 'Generated: ' . date('Y-m-d H:i'), true);
        $content .= "0.75 w 32 528 m 810 528 l S\n";

        $headers = ['Code', 'Account', 'Type', 'Opening', 'Period Debit', 'Period Credit', 'Closing Debit', 'Closing Credit'];
        $positions = [32, 78, 315, 380, 462, 548, 635, 723];
        foreach ($headers as $index => $header) {
            $content .= $pdfText((float) $positions[$index], 512, 7.5, $header);
        }
        $content .= "0.5 w 32 504 m 810 504 l S\n";

        $y = 489;
        foreach ($pageRows as $row) {
            $content .= $pdfText(32, $y, 7.5, $row[0]);
            $content .= $pdfText(78, $y, 7.5, $row[1]);
            $content .= $pdfText(315, $y, 7.5, $row[2]);
            for ($column = 3; $column < 8; $column++) {
                $content .= $pdfText((float) $positions[$column] + 74, $y, 7.5, $row[$column], true);
            }
            $content .= "0.25 w 32 " . ($y - 4) . " m 810 " . ($y - 4) . " l S\n";
            $y -= 14;
        }

        if ($pageIndex === count($pdfRowPages) - 1) {
            $content .= $pdfText(32, $y - 3, 8, 'Total');
            $content .= $pdfText(454, $y - 3, 8, number_format($tot['opening'], 2), true);
            $content .= $pdfText(536, $y - 3, 8, number_format($tot['debit'], 2), true);
            $content .= $pdfText(622, $y - 3, 8, number_format($tot['credit'], 2), true);
            $content .= $pdfText(709, $y - 3, 8, number_format($tot['closing_dr'], 2), true);
            $content .= $pdfText(810, $y - 3, 8, number_format($tot['closing_cr'], 2), true);
            $content .= $pdfText(32, max(28, $y - 23), 8, 'Total Variance: ' . number_format($variance, 2));
        }
        $content .= $pdfText(810, 18, 7, 'Page ' . ($pageIndex + 1), true);
        $pdfPages[] = $content;
    }

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];
    $pageReferences = [];
    foreach ($pdfPages as $index => $content) {
        $pageObject = 4 + $index * 2;
        $contentObject = $pageObject + 1;
        $pageReferences[] = "{$pageObject} 0 R";
        $xObjectResource = '';
        if ($pdfLogo !== null) {
            $logoObject = 4 + count($pdfPages) * 2;
            $xObjectResource = " /XObject << /Logo {$logoObject} 0 R >>";
        }
        $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >>{$xObjectResource} >> /Contents {$contentObject} 0 R >>";
        $objects[$contentObject] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";
    }
    if ($pdfLogo !== null) {
        $logoObject = 4 + count($pdfPages) * 2;
        $objects[$logoObject] = "<< /Type /XObject /Subtype /Image /Width {$pdfLogo['width']} /Height {$pdfLogo['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($pdfLogo['bytes']) . " >>\nstream\n{$pdfLogo['bytes']}\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pdfPages) . ' >>';
    ksort($objects);

    $document = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $objectNumber => $objectBody) {
        $offsets[$objectNumber] = strlen($document);
        $document .= "{$objectNumber} 0 obj\n{$objectBody}\nendobj\n";
    }
    $xrefOffset = strlen($document);
    $objectCount = max(array_keys($objects)) + 1;
    $document .= "xref\n0 {$objectCount}\n0000000000 65535 f \n";
    for ($number = 1; $number < $objectCount; $number++) {
        $document .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
    }
    $document .= "trailer\n<< /Size {$objectCount} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="trial-balance-' . $to . '.pdf"');
    header('Content-Length: ' . strlen($document));
    echo $document;
    exit;
}

$csvQuery = $_GET;
$csvQuery['export'] = 'csv';
$csvUrl = '?' . http_build_query($csvQuery);
$pdfQuery = $_GET;
$pdfQuery['export'] = 'pdf';
$pdfUrl = '?' . http_build_query($pdfQuery);

$page_title = 'Trial Balance';
include __DIR__ . '/../../modules/balances/includes/header.php';
include __DIR__ . '/../../includes/gl/gl_page_style.php';
?>
<style>
.main-content.glr.glr-trial{padding-top:8px!important;background:#f4f5f7}
.glr-trial .glr-wrap{width:100%;max-width:none;padding:0 10px}
.glr-trial-breadcrumb{display:flex;align-items:center;gap:9px;min-height:42px;padding:0 12px;margin-bottom:8px;background:transparent;border:0;color:#94a3b8;font-size:13px}
.glr-trial-breadcrumb a{color:#8290a2;text-decoration:none}
.glr-trial-breadcrumb a:hover{color:#2563eb}
.glr-trial-breadcrumb .separator{color:#c3cad3}
.glr-trial-breadcrumb [aria-current="page"]{color:#334155;font-weight:650}
.glr-trial-head{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:44px;padding:8px 12px;background:#fff;border:1px solid #e6e8eb;border-bottom:0}
.glr-trial-title{display:flex;align-items:center;gap:8px;margin:0;font-size:15px;font-weight:700;color:#334155}
.glr-trial-title i{color:#64748b;font-size:14px}
.glr-trial-date{font-size:11px;color:#7b8490;white-space:nowrap}
.glr-trial-filter{min-height:60px;padding:9px 12px;border:1px solid #e6e8eb;border-bottom:0;background:#fff}
.glr-trial-filter .glr-form{padding:0;gap:9px}
.glr-trial-filter label{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:#64748b}
.glr-trial-filter input{height:36px;font-size:12px}
.glr-trial-filter .glr-btn{height:42px;padding:0 18px;font-size:14px}
.glr-trial-filter button.glr-btn[type="submit"]{width:38px;padding:0;background:transparent;color:#2563eb;border-color:transparent}
.glr-trial-filter button.glr-btn[type="submit"]:hover{background:#eff6ff}
.glr-trial-filter button.glr-btn[type="submit"]:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.glr-trial-download{position:relative}
.glr-trial-download summary{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 9px;border-radius:4px;color:#2563eb;cursor:pointer;list-style:none;font-size:12px;font-weight:600;white-space:nowrap}
.glr-trial-download summary::-webkit-details-marker{display:none}
.glr-trial-download summary:hover,.glr-trial-download[open] summary{background:#eff6ff}
.glr-trial-download summary:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.glr-trial-download.is-downloading .fa-download{animation:glr-download-pulse .7s ease-in-out infinite alternate}
.glr-trial-download.is-downloading summary{color:#1d4ed8}
@keyframes glr-download-pulse{from{transform:translateY(-2px);opacity:.55}to{transform:translateY(2px);opacity:1}}
@media(prefers-reduced-motion:reduce){.glr-trial-download.is-downloading .fa-download{animation:none}}
.glr-trial-download-menu{position:absolute;top:calc(100% + 3px);right:0;z-index:5;min-width:145px;padding:4px;background:#fff;border:1px solid #e0e5eb;border-radius:5px;box-shadow:0 5px 16px rgba(15,23,42,.14)}
.glr-trial-download-menu a{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:3px;color:#344054;text-decoration:none;font-size:12px}
.glr-trial-download-menu a:hover{background:#f1f5f9}
.glr-trial-download-menu .fa-file-csv{color:#16803c}
.glr-trial-download-menu .fa-file-pdf{color:#c62828}
.glr-trial-search{height:36px!important;min-width:170px;width:min(308px,30vw);margin-left:auto;border:1px solid #dfe3e8;border-radius:4px;padding:0 12px;font-size:12px}
.glr-trial-table-card{background:#fff;border:1px solid #e6e8eb;overflow:hidden}
.glr-trial-table-wrap{height:min(68vh,720px);min-height:320px;overflow:auto}
.glr-trial-table{width:100%;border-collapse:collapse;font-size:11px}
.glr-trial-table th,.glr-trial-table td{height:34px;padding:5px 12px;border-bottom:1px solid #eceef0;white-space:nowrap}
.glr-trial-table thead th{position:sticky;top:0;z-index:2;height:32px;background:#fff;color:#606a76;font-size:10px;font-weight:600;text-align:left}
.glr-trial-table .glr-trial-num{text-align:right;font-variant-numeric:tabular-nums}
.glr-trial-nominal{width:46%;min-width:250px}
.glr-trial-expand{width:22px;height:22px;margin-right:5px;padding:0;border:0;background:transparent;color:#89929d;cursor:pointer}
.glr-trial-expand:hover{color:#2563eb}
.glr-trial-account a{color:#3f4a56;text-decoration:none}
.glr-trial-account a:hover{color:#2563eb;text-decoration:underline}
.glr-trial-account-code{margin-right:5px;color:#697380}
.glr-trial-row:hover td{background:#f8fafc}
.glr-trial-detail td{height:auto;padding:0 14px 9px 48px;background:#fafbfc;white-space:normal}
.glr-trial-detail[hidden]{display:none}
.glr-trial-detail-grid{display:flex;flex-wrap:wrap;gap:8px 26px;padding:9px 0 2px;color:#66717d;font-size:10px}
.glr-trial-detail-grid span{display:inline-flex;gap:5px}
.glr-trial-detail-grid b{color:#334155;font-weight:650}
.glr-trial-empty{padding:35px 12px!important;color:#77818c;text-align:center!important}
.glr-trial-table tfoot td{position:sticky;bottom:0;background:#f8fafb;font-weight:700;border-top:1px solid #dfe3e8}
.glr-trial-pagination{display:flex;align-items:center;justify-content:flex-end;gap:8px;height:32px;padding:0 10px;color:#7b8490;font-size:10px}
.glr-trial-page-btn{width:24px;height:24px;padding:0;border:0;border-radius:4px;background:transparent;color:#5f6975;cursor:pointer}
.glr-trial-page-btn:hover:not(:disabled){background:#f0f2f4}
.glr-trial-page-btn:disabled{color:#c6cbd1;cursor:default}
.glr-trial-variance{margin-top:7px;padding:8px 10px 10px;background:#fff;border:1px solid #e6e8eb}
.glr-trial-variance-label{font-size:10px;color:#66717d}
.glr-trial-variance-value{padding-top:4px;text-align:center;font-size:15px;font-weight:750;color:#354052;font-variant-numeric:tabular-nums}
.glr-trial-variance-value.is-unbalanced{color:#b42318}
html[data-theme="dark"] .main-content.glr.glr-trial{background:#111827}
html[data-theme="dark"] .glr-trial-breadcrumb{background:transparent;color:#94a3b8}
html[data-theme="dark"] .glr-trial-breadcrumb [aria-current="page"]{color:#dbe4ed}
html[data-theme="dark"] .glr-trial-head,html[data-theme="dark"] .glr-trial-filter,
html[data-theme="dark"] .glr-trial-table-card,html[data-theme="dark"] .glr-trial-variance{background:#17212b;border-color:#334155}
html[data-theme="dark"] .glr-trial-title,html[data-theme="dark"] .glr-trial-table td,
html[data-theme="dark"] .glr-trial-table .glr-trial-account a{color:#dbe4ed}
html[data-theme="dark"] .glr-trial-table thead th,html[data-theme="dark"] .glr-trial-table tfoot td{background:#17212b;color:#cbd5e1}
html[data-theme="dark"] .glr-trial-detail td,html[data-theme="dark"] .glr-trial-row:hover td{background:#1d2935}
html[data-theme="dark"] .glr-trial-search,html[data-theme="dark"] .glr-trial-filter input{background:#111a23;color:#e2e8f0;border-color:#334155}
html[data-theme="dark"] .glr-trial-download-menu{background:#17212b;border-color:#334155}
html[data-theme="dark"] .glr-trial-download-menu a{color:#dbe4ed}
html[data-theme="dark"] .glr-trial-download-menu a:hover{background:#1d2935}
html[data-theme="dark"] .glr-trial-detail-grid b,html[data-theme="dark"] .glr-trial-variance-value{color:#e2e8f0}
@media(max-width:680px){
  .glr-trial .glr-wrap{padding:0 6px}
  .glr-trial-breadcrumb{min-height:36px;padding:0 8px;font-size:12px}
  .glr-trial-head{align-items:flex-start;flex-wrap:wrap}
  .glr-trial-filter .glr-form{flex-wrap:wrap}
  .glr-trial-search{width:100%;margin-left:0}
  .glr-trial-table-wrap{height:65vh;min-height:280px}
  .glr-trial-table{min-width:640px}
}
@media print{
  .glr-trial-filter,.glr-trial-pagination{display:none!important}
  .glr-trial .glr-wrap{padding:0}
  .glr-trial-table-wrap{height:auto;overflow:visible}
  .glr-trial-table thead th,.glr-trial-table tfoot td{position:static}
}
</style>

<main class="main-content glr glr-trial">
  <div class="glr-wrap">
    <nav class="glr-trial-breadcrumb" aria-label="Breadcrumb">
      <a href="../">Home</a>
      <span class="separator" aria-hidden="true">/</span>
      <a href="../modules/accounting/index.php?module=<?= rawurlencode($module) ?>#financial-statements">Accounting</a>
      <span class="separator" aria-hidden="true">/</span>
      <span aria-current="page">Trial Balance</span>
    </nav>
    <header class="glr-trial-head">
      <h1 class="glr-trial-title"><i class="fas fa-circle-info" aria-hidden="true"></i>Trial Balance</h1>
      <div class="glr-trial-date"><?= gl_h(date('d M Y', strtotime($from))) ?> – <?= gl_h(date('d M Y', strtotime($to))) ?></div>
    </header>

    <section class="glr-trial-filter" aria-label="Trial balance filters">
      <form method="get" class="glr-form">
        <input type="hidden" name="module" value="<?= gl_h($module) ?>">
        <label>From <input type="date" name="from_date" value="<?= gl_h($from) ?>"></label>
        <label>To <input type="date" name="to_date" value="<?= gl_h($to) ?>"></label>
        <button class="glr-btn" type="submit" aria-label="View trial balance" title="View trial balance"><i class="fas fa-magnifying-glass" aria-hidden="true"></i></button>
        <details class="glr-trial-download">
          <summary aria-label="Download Trial Balance" title="Download Trial Balance"><i class="fas fa-download" aria-hidden="true"></i><span>Download</span></summary>
          <div class="glr-trial-download-menu">
            <a href="<?= gl_h($csvUrl) ?>" data-export-format="csv"><i class="fas fa-file-csv" aria-hidden="true"></i>Download CSV</a>
            <a href="<?= gl_h($pdfUrl) ?>" data-export-format="pdf"><i class="fas fa-file-pdf" aria-hidden="true"></i>Download PDF</a>
          </div>
        </details>
        <input class="glr-trial-search" id="glrTrialSearch" type="search" placeholder="Search accounts" aria-label="Search accounts">
      </form>
    </section>

    <section class="glr-trial-table-card" aria-label="Trial balance accounts">
      <div class="glr-trial-table-wrap">
        <table class="glr-trial-table">
          <thead>
            <tr>
              <th class="glr-trial-nominal">Nominal</th>
              <th class="glr-trial-num">Debit</th>
              <th class="glr-trial-num">Credit</th>
              <th class="glr-trial-num">Net</th>
            </tr>
          </thead>
          <tbody id="glrTrialRows">
          <?php if (!$rows): ?>
            <tr><td class="glr-trial-empty" colspan="4">No posted entries for this period.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $index => $r):
              $accountId = (int) $r['id'];
              $closing = (float) $r['closing'];
              $debitBalance = max(0, $closing);
              $creditBalance = max(0, -$closing);
              $searchText = strtolower(trim($r['code'] . ' ' . $r['name'] . ' ' . $r['type']));
              $ledgerUrl = 'ledger.php?' . http_build_query([
                  'module' => $module,
                  'account_id' => $accountId,
                  'from_date' => $from,
                  'to_date' => $to,
              ]);
          ?>
            <tr class="glr-trial-row" data-account-row
                data-search="<?= gl_h($searchText) ?>"
                data-opening="<?= number_format((float) $r['opening'], 2, '.', '') ?>"
                data-period-debit="<?= number_format((float) $r['debit'], 2, '.', '') ?>"
                data-period-credit="<?= number_format((float) $r['credit'], 2, '.', '') ?>"
                data-closing="<?= number_format($closing, 2, '.', '') ?>"
                data-closing-debit="<?= number_format($debitBalance, 2, '.', '') ?>"
                data-closing-credit="<?= number_format($creditBalance, 2, '.', '') ?>">
              <td class="glr-trial-account">
                <button class="glr-trial-expand" type="button" aria-expanded="false" aria-label="Show period details for <?= gl_h($r['code'] . ' ' . $r['name']) ?>" aria-controls="glr-trial-detail-<?= $accountId ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                <a href="<?= gl_h($ledgerUrl) ?>"><span class="glr-trial-account-code"><?= gl_h($r['code'] !== '' ? $r['code'] : '-') ?></span><?= gl_h($r['name']) ?></a>
              </td>
              <td class="glr-trial-num"><?= $debitBalance > 0.004 ? number_format($debitBalance, 2) : '' ?></td>
              <td class="glr-trial-num"><?= $creditBalance > 0.004 ? number_format($creditBalance, 2) : '' ?></td>
              <td class="glr-trial-num"><?= number_format($closing, 2) ?></td>
            </tr>
            <tr class="glr-trial-detail" id="glr-trial-detail-<?= $accountId ?>" data-detail-row hidden>
              <td colspan="4">
                <div class="glr-trial-detail-grid">
                  <span>Opening balance <b><?= gl_fmt((float) $r['opening']) ?></b></span>
                  <span>Period debits <b><?= number_format((float) $r['debit'], 2) ?></b></span>
                  <span>Period credits <b><?= number_format((float) $r['credit'], 2) ?></b></span>
                  <span>Closing balance <b><?= gl_fmt($closing) ?></b></span>
                  <span>Account type <b><?= gl_h(ucfirst((string) $r['type'])) ?></b></span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <?php if ($rows): ?>
          <tfoot>
            <tr data-trial-totals
                data-opening="<?= number_format($tot['opening'], 2, '.', '') ?>"
                data-period-debit="<?= number_format($tot['debit'], 2, '.', '') ?>"
                data-period-credit="<?= number_format($tot['credit'], 2, '.', '') ?>"
                data-closing-debit="<?= number_format($tot['closing_dr'], 2, '.', '') ?>"
                data-closing-credit="<?= number_format($tot['closing_cr'], 2, '.', '') ?>"
                data-variance="<?= number_format($variance, 2, '.', '') ?>">
              <td>Total</td>
              <td class="glr-trial-num"><?= number_format($tot['closing_dr'], 2) ?></td>
              <td class="glr-trial-num"><?= number_format($tot['closing_cr'], 2) ?></td>
              <td class="glr-trial-num"><?= number_format($variance, 2) ?></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
      <nav class="glr-trial-pagination" id="glrTrialPagination" aria-label="Trial balance pages" hidden>
        <span id="glrTrialPageLabel"></span>
        <button class="glr-trial-page-btn" id="glrTrialPrev" type="button" aria-label="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
        <button class="glr-trial-page-btn" id="glrTrialNext" type="button" aria-label="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
      </nav>
    </section>

    <section class="glr-trial-variance" aria-label="Total variance">
      <div class="glr-trial-variance-label">Total Variance</div>
      <div class="glr-trial-variance-value<?= $balanced ? '' : ' is-unbalanced' ?>"><?= gl_fmt($variance) ?></div>
    </section>
  </div>
</main>
<script>
(function () {
  var downloadMenu = document.querySelector('.glr-trial-download');
  if (downloadMenu) {
    var downloadSummary = downloadMenu.querySelector('summary');
    var downloadIcon = downloadSummary && downloadSummary.querySelector('.fa-download');
    var downloadLabel = downloadSummary && downloadSummary.querySelector('span');
    var downloadTimer;
    document.addEventListener('click', function (event) {
      if (!downloadMenu.contains(event.target)) {
        downloadMenu.open = false;
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        downloadMenu.open = false;
      }
    });
    downloadMenu.addEventListener('click', function (event) {
      var link = event.target.closest('.glr-trial-download-menu a');
      if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
      window.clearTimeout(downloadTimer);
      downloadMenu.classList.add('is-downloading');
      downloadMenu.setAttribute('aria-busy', 'true');
      if (downloadIcon) downloadIcon.className = 'fas fa-download';
      if (downloadLabel) downloadLabel.textContent = 'Preparing…';
      downloadMenu.open = false;
      downloadTimer = window.setTimeout(function () {
        downloadMenu.classList.remove('is-downloading');
        downloadMenu.removeAttribute('aria-busy');
        if (downloadLabel) downloadLabel.textContent = 'Download';
      }, 2400);
    });
  }

  var body = document.getElementById('glrTrialRows');
  var pagination = document.getElementById('glrTrialPagination');
  var label = document.getElementById('glrTrialPageLabel');
  var search = document.getElementById('glrTrialSearch');
  var prev = document.getElementById('glrTrialPrev');
  var next = document.getElementById('glrTrialNext');
  if (!body || !pagination || !label || !search || !prev || !next) return;

  var pageSize = 100;
  var page = 0;
  var accounts = Array.prototype.slice.call(body.querySelectorAll('[data-account-row]'));

  function render() {
    var query = search.value.trim().toLowerCase();
    var filtered = accounts.filter(function (row) {
      return !query || (row.getAttribute('data-search') || '').indexOf(query) !== -1;
    });
    document.querySelectorAll('.glr-trial-download-menu a[data-export-format]').forEach(function (link) {
      var url = new URL(link.href, window.location.href);
      if (query) {
        url.searchParams.set('account_search', search.value.trim());
      } else {
        url.searchParams.delete('account_search');
      }
      link.href = url.toString();
    });
    var pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
    page = Math.min(page, pageCount - 1);
    var first = page * pageSize;
    var last = Math.min(first + pageSize, filtered.length);
    var visible = new Set(filtered.slice(first, last));

    accounts.forEach(function (row) {
      var isVisible = visible.has(row);
      row.hidden = !isVisible;
      var detail = row.nextElementSibling;
      var button = row.querySelector('.glr-trial-expand');
      if (!isVisible && button) {
        button.setAttribute('aria-expanded', 'false');
        button.innerHTML = '<i class="fas fa-chevron-right" aria-hidden="true"></i>';
      }
      if (detail) detail.hidden = !isVisible || !button || button.getAttribute('aria-expanded') !== 'true';
    });

    label.textContent = filtered.length ? (first + 1) + '–' + last + ' of ' + filtered.length : '0 accounts';
    prev.disabled = page === 0;
    next.disabled = page >= pageCount - 1;
    pagination.hidden = filtered.length <= pageSize;
  }

  body.addEventListener('click', function (event) {
    var button = event.target.closest('.glr-trial-expand');
    if (!button) return;
    var expanded = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    button.innerHTML = '<i class="fas fa-chevron-' + (expanded ? 'down' : 'right') + '" aria-hidden="true"></i>';
    var detail = button.closest('[data-account-row]').nextElementSibling;
    if (detail) detail.hidden = !expanded;
  });
  search.addEventListener('input', function () { page = 0; render(); });
  prev.addEventListener('click', function () { if (page > 0) { page--; render(); } });
  next.addEventListener('click', function () { page++; render(); });
  pagination.hidden = false;
  render();
})();
</script>
<?php include __DIR__ . '/../../modules/balances/includes/footer.php'; ?>
