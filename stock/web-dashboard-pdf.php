<?php

declare(strict_types=1);

/**
 * PDF download of the Website dashboard: /{company}/website/dashboard?ajax=dashboard-pdf&from=&to=
 * Reached through website.php, which has already checked the login and company.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/includes/web-dashboard-lib.php';
requireLogin();

if (!function_exists('isUltimate') || !isUltimate()) {
    http_response_code(403);
    exit('Ultimate company only.');
}

global $pdo;
$query = static fn (string $key): string => is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';

try {
    $d = webDashboardData($pdo, $query('range'), $query('from'), $query('to'));
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PDF library missing (vendor/autoload.php).');
    }
    require_once $autoload;
} catch (Throwable $e) {
    error_log('website dashboard pdf: ' . $e->getMessage());
    http_response_code(500);
    exit('The dashboard PDF could not be created. Please try again.');
}

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$date = static fn (string $ymd, string $format = 'j M Y'): string => ($t = strtotime($ymd)) ? date($format, $t) : $ymd;
$num = static fn ($n): string => number_format((float) $n);
$pct = static fn ($v): string => $v === null ? '&mdash;' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
$change = static function ($v, bool $points = false): string {
    if ($v === null) {
        return '<span class="flat">&mdash;</span>';
    }
    $v = (float) $v;
    $text = rtrim(rtrim(number_format(abs($v), 1), '0'), '.') . ($points ? ' pts' : '%');
    if ($v == 0.0) {
        return '<span class="flat">0%</span>';
    }

    return $v > 0 ? '<span class="up">&#9650; ' . $text . '</span>' : '<span class="down">&#9660; ' . $text . '</span>';
};

$companyName = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_name', '')) : '';
if ($companyName === '') {
    $companyName = 'Ultimate General Trading';
}

$imageVars = [];
$logoFile = dirname(__DIR__) . '/images/ULTIMATE GENERAL LOGO.white_page-0001-cropped.svg';
$logoSvg = is_file($logoFile) ? (string) file_get_contents($logoFile) : '';
if ($logoSvg !== '') {
    // The source artwork sits inside a 782x782 canvas; crop to the logo itself.
    $imageVars['logo'] = (string) preg_replace('/viewBox="[^"]*"/', 'viewBox="40 213 589 216"', $logoSvg, 1);
}

$tz = new DateTimeZone(WEB_DASH_TZ);
$generated = (new DateTimeImmutable('now', $tz))->format('j M Y, H:i');
$generatedBy = trim((string) ($_SESSION['full_name'] ?? ''));
$range = static function (string $from, string $to) use ($date): string {
    if (substr($from, 0, 7) === substr($to, 0, 7)) {
        return $date($from, 'j') . '&ndash;' . $date($to, 'j M Y');
    }

    return substr($from, 0, 4) === substr($to, 0, 4)
        ? $date($from, 'j M') . ' &ndash; ' . $date($to, 'j M Y')
        : $date($from) . ' &ndash; ' . $date($to);
};
$period = $range($d['from'], $d['to']);
$prevPeriod = $range($d['prev_from'], $d['prev_to']);
$t = $d['totals'];
$c = $d['changes'];
$r = $d['rates'];

$kpis = [
    ['Visitors', $num($t['visitors']), $change($c['visitors'])],
    ['Product views', $num($t['product_views']), $change($c['product_views'])],
    ['Enquiries', $num($t['enquiries']), $change($c['enquiries'])],
    ['Quotes', $num($t['quotes']), $change($c['quotes'])],
    ['Revenue (TZS)', $num($t['revenue']), $change($c['revenue'])],
    ['Sales', $num($t['sales']), $change($c['sales'])],
    ['Conversion rate', $pct($r['visitor_to_enquiry']), $change($c['conversion'], true)],
    ['Likes', $num($t['likes']), $change($c['likes'])],
];

$funnel = [
    ['Visitors', $t['visitors'], '&mdash;', 'Unique visitors to ultimate.co.tz'],
    ['Enquiries', $t['enquiries'], $pct($r['visitor_to_enquiry']), 'of visitors sent an enquiry'],
    ['Quotes', $t['quotes'], $pct($r['enquiry_to_quote']), 'of enquiries received a quote'],
    ['Sales', $t['sales'], $pct($r['enquiry_to_sale']), 'of enquiries became an invoice'],
];

$rows = [];
$monthly = count($d['days']) > 62;
foreach ($d['days'] as $day) {
    $key = $monthly ? substr($day['date'], 0, 7) : $day['date'];
    if (!isset($rows[$key])) {
        $rows[$key] = ['label' => $monthly ? $date($day['date'], 'M Y') : $date($day['date'], 'D, j M Y'),
            'visitors' => 0, 'product_views' => 0, 'enquiries' => 0, 'quotes' => 0, 'revenue' => 0.0, 'likes' => 0];
    }
    foreach (['visitors', 'product_views', 'enquiries', 'quotes', 'revenue', 'likes'] as $k) {
        $rows[$key][$k] += $day[$k];
    }
}

$viewed = array_values((array) $d['top_viewed']);
$liked = array_values((array) $d['top_liked']);
$topRows = max(count($viewed), count($liked), 1);

ob_start();
?>
<style>
    body { font-family: dejavusans; font-size: 8.5pt; color: #111827; line-height: 1.35; }
    .up { color: #15803d; }
    .down { color: #b91c1c; }
    .flat { color: #9ca3af; }
    .muted { color: #6b7280; }
    .num { text-align: right; }

    table.header { width: 100%; border-bottom: 0.7mm solid #f9c229; }
    table.header td { vertical-align: bottom; padding-bottom: 3mm; }
    .company { font-size: 10pt; font-weight: bold; text-align: right; }
    .company-sub { font-size: 7.5pt; color: #6b7280; text-align: right; }

    h1 { font-size: 17pt; font-weight: bold; margin: 5mm 0 0.5mm; color: #111827; }
    .subtitle { font-size: 8.5pt; color: #6b7280; margin-bottom: 3.5mm; }

    table.meta { width: 100%; border-collapse: collapse; border-top: 0.25mm solid #e5e7eb; border-bottom: 0.25mm solid #e5e7eb; }
    table.meta td { width: 25%; padding: 2.5mm 3mm 2.5mm 0; vertical-align: top; }
    .meta-label { font-size: 6.6pt; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4pt; }
    .meta-value { font-size: 8.5pt; font-weight: bold; color: #111827; margin-top: 0.8mm; }

    .note { margin-top: 3.5mm; padding: 2.2mm 3mm; border-left: 0.9mm solid #d97706; background: #fffbeb; color: #78350f; font-size: 7.8pt; }

    h2 { font-size: 10.5pt; font-weight: bold; color: #111827; margin: 5.5mm 0 2.2mm; }

    table.kpis { width: 100%; border-collapse: collapse; }
    table.kpis td { width: 25%; border: 0.25mm solid #e5e7eb; padding: 2.4mm 3.5mm; vertical-align: top; }
    .kpi-label { font-size: 6.8pt; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4pt; }
    .kpi-value { font-size: 15pt; font-weight: bold; color: #111827; margin-top: 1mm; }
    .kpi-change { font-size: 7.2pt; margin-top: 0.8mm; }
    .kpi-change .vs { color: #9ca3af; }

    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #f3f4f6; color: #374151; font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3pt; text-align: left; padding: 2mm 2.5mm; border-top: 0.4mm solid #111827; border-bottom: 0.25mm solid #d1d5db; }
    table.data td { font-size: 8pt; padding: 1.45mm 2.5mm; border-bottom: 0.2mm solid #eceef1; }
    table.data tr.alt td { background: #fafafa; }
    table.data th.num, table.data td.num { text-align: right; }
    table.data tfoot td { font-weight: bold; background: #f3f4f6; border-top: 0.4mm solid #111827; border-bottom: 0.4mm solid #111827; }
    table.data td.rank { color: #6b7280; }
    table.data .split { border-left: 0.25mm solid #d1d5db; padding-left: 3.5mm; }
    table.data td.empty { color: #9ca3af; }

    .footnote { margin-top: 5mm; padding-top: 2mm; border-top: 0.25mm solid #e5e7eb; font-size: 6.8pt; color: #6b7280; }
</style>

<table class="header">
    <tr>
        <td style="width:50%;">
            <?php if (isset($imageVars['logo'])): ?>
                <img src="var:logo" style="height:13mm; width:auto;" />
            <?php else: ?>
                <span class="company"><?= $e($companyName) ?></span>
            <?php endif; ?>
        </td>
        <td style="width:50%;">
            <div class="company"><?= $e($companyName) ?></div>
            <div class="company-sub">ultimate.co.tz</div>
        </td>
    </tr>
</table>

<h1>Website Performance Report</h1>
<div class="subtitle">Visitors, enquiries and sales generated through ultimate.co.tz</div>

<table class="meta">
    <tr>
        <td><div class="meta-label">Reporting period</div><div class="meta-value"><?= $period ?></div></td>
        <td><div class="meta-label">Compared with</div><div class="meta-value"><?= $prevPeriod ?></div></td>
        <td><div class="meta-label">Generated</div><div class="meta-value"><?= $e($generated) ?></div></td>
        <td><div class="meta-label">Prepared by</div><div class="meta-value"><?= $generatedBy !== '' ? $e($generatedBy) : '&mdash;' ?></div></td>
    </tr>
</table>

<?php if (empty($d['shop_connected'])): ?>
    <div class="note">Visitors, product views and likes are unavailable because ultimate.co.tz did not respond. Enquiries, quotes and revenue are up to date.</div>
<?php elseif (!empty($d['tracking_since']) && $d['tracking_since'] > $d['from']): ?>
    <div class="note">Visitor and product-view tracking started on <?= $e($date($d['tracking_since'])) ?>, so earlier days show zero.</div>
<?php endif; ?>

<h2>Key metrics</h2>
<table class="kpis">
    <?php foreach (array_chunk($kpis, 4) as $kpiRow): ?>
        <tr>
            <?php foreach ($kpiRow as [$label, $value, $delta]): ?>
                <td>
                    <div class="kpi-label"><?= $e($label) ?></div>
                    <div class="kpi-value"><?= $value ?></div>
                    <div class="kpi-change"><?= $delta ?> <span class="vs">vs previous period</span></div>
                </td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Conversion funnel</h2>
<table class="data">
    <thead><tr><th style="width:24%;">Stage</th><th class="num" style="width:14%;">Count</th><th class="num" style="width:16%;">Rate</th><th>Measure</th></tr></thead>
    <tbody>
    <?php foreach ($funnel as $i => [$label, $count, $rate, $measure]): ?>
        <tr<?= $i % 2 ? ' class="alt"' : '' ?>>
            <td><b><?= $e($label) ?></b></td>
            <td class="num"><?= $num($count) ?></td>
            <td class="num"><?= $rate ?></td>
            <td class="muted"><?= $e($measure) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2><?= $monthly ? 'Monthly' : 'Daily' ?> breakdown</h2>
<table class="data">
    <thead>
        <tr>
            <th style="width:22%;"><?= $monthly ? 'Month' : 'Date' ?></th>
            <th class="num">Visitors</th>
            <th class="num">Product views</th>
            <th class="num">Enquiries</th>
            <th class="num">Quotes</th>
            <th class="num">Revenue (TZS)</th>
            <th class="num">Likes</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach (array_values($rows) as $i => $row): ?>
        <tr<?= $i % 2 ? ' class="alt"' : '' ?>>
            <td><?= $e($row['label']) ?></td>
            <td class="num"><?= $num($row['visitors']) ?></td>
            <td class="num"><?= $num($row['product_views']) ?></td>
            <td class="num"><?= $num($row['enquiries']) ?></td>
            <td class="num"><?= $num($row['quotes']) ?></td>
            <td class="num"><?= $num($row['revenue']) ?></td>
            <td class="num"><?= $num($row['likes']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td>Total</td>
            <td class="num"><?= $num($t['visitors']) ?></td>
            <td class="num"><?= $num($t['product_views']) ?></td>
            <td class="num"><?= $num($t['enquiries']) ?></td>
            <td class="num"><?= $num($t['quotes']) ?></td>
            <td class="num"><?= $num($t['revenue']) ?></td>
            <td class="num"><?= $num($t['likes']) ?></td>
        </tr>
    </tfoot>
</table>

<h2>Top products</h2>
<table class="data" style="page-break-inside: avoid;">
    <thead>
        <tr>
            <th style="width:5%;">#</th><th style="width:35%;">Most viewed</th><th class="num" style="width:10%;">Views</th>
            <th class="split" style="width:5%;">#</th><th style="width:35%;">Most liked</th><th class="num" style="width:10%;">Likes</th>
        </tr>
    </thead>
    <tbody>
    <?php for ($i = 0; $i < $topRows; $i++): ?>
        <tr<?= $i % 2 ? ' class="alt"' : '' ?>>
            <?php foreach ([$viewed, $liked] as $side => $list): ?>
                <?php $split = $side === 1 ? ' split' : ''; ?>
                <?php if (isset($list[$i])): ?>
                    <td class="rank<?= $split ?>"><?= $i + 1 ?></td>
                    <td><?= $e($list[$i]['name'] ?? '') ?></td>
                    <td class="num"><?= $num($list[$i]['count'] ?? 0) ?></td>
                <?php elseif ($i === 0): ?>
                    <td class="empty<?= $split ?>" colspan="3">No <?= $side === 0 ? 'product views' : 'likes' ?> in this period.</td>
                <?php else: ?>
                    <td class="rank<?= $split ?>"></td><td></td><td></td>
                <?php endif; ?>
            <?php endforeach; ?>
        </tr>
    <?php endfor; ?>
    </tbody>
</table>

<div class="footnote">
    Changes compare each figure with the previous period of the same length; &mdash; means there is no data for that period.
    Visitors are counted once per browser per day, excluding staff accounts and automated traffic.
    Conversion rate is the share of visitors who sent an enquiry; its change is shown in percentage points.
</div>
<?php
$html = (string) ob_get_clean();

$footer = '<table width="100%" style="border-top:0.25mm solid #e5e7eb; font-family:dejavusans; font-size:6.8pt; color:#6b7280;"><tr>'
    . '<td style="padding-top:2mm;">' . $e($companyName) . ' &middot; Website Performance Report &middot; ' . $period . '</td>'
    . '<td style="padding-top:2mm; text-align:right;">Page {PAGENO} of {nbpg}</td></tr></table>';

try {
    $tempDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'mpdf';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 14,
        'margin_bottom' => 18,
        'margin_footer' => 8,
        'tempDir' => $tempDir,
        'use_kwt' => true,
    ]);
    $mpdf->imageVars = $imageVars;
    $mpdf->SetTitle('Website Performance Report ' . $d['from'] . ' to ' . $d['to']);
    $mpdf->SetAuthor($companyName);
    $mpdf->SetCreator('UltiTech ERP');
    $mpdf->SetHTMLFooter($footer);
    $mpdf->WriteHTML($html);
    $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    $filename = 'website-dashboard-' . $d['from'] . '-to-' . $d['to'] . '.pdf';

    // The dashboard reads X-Report-Size to show download progress; Content-Length is lost when the response is compressed.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', 'Off');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('X-Report-Size: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    echo $pdf;
} catch (Throwable $ex) {
    error_log('website dashboard pdf: ' . $ex->getMessage());
    http_response_code(500);
    echo 'The dashboard PDF could not be created. Please try again.';
}
exit;
