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
$money = static fn ($n): string => 'TZS ' . number_format((float) $n);
$pct = static fn ($v): string => $v === null ? '&ndash;' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
$change = static function ($v, bool $points = false): string {
    if ($v === null) {
        return '<span class="muted">No earlier data</span>';
    }
    $v = (float) $v;
    $text = rtrim(rtrim(number_format(abs($v), 1), '0'), '.') . ($points ? ' pts' : '%');
    if ($v == 0.0) {
        return '<span class="muted">No change</span>';
    }

    return $v > 0 ? '<span class="up">&#9650; ' . $text . '</span>' : '<span class="down">&#9660; ' . $text . '</span>';
};

$companyName = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_name', '')) : '';
if ($companyName === '') {
    $companyName = 'Ultimate General Trading';
}
$tz = new DateTimeZone(WEB_DASH_TZ);
$generated = (new DateTimeImmutable('now', $tz))->format('j M Y, H:i');
$generatedBy = trim((string) ($_SESSION['full_name'] ?? ''));
$period = $date($d['from']) . ' &ndash; ' . $date($d['to']);
$prevPeriod = $date($d['prev_from']) . ' &ndash; ' . $date($d['prev_to']);
$t = $d['totals'];
$c = $d['changes'];
$r = $d['rates'];

$kpis = [
    ['Visitors', $num($t['visitors']), $change($c['visitors'])],
    ['Product views', $num($t['product_views']), $change($c['product_views'])],
    ['Enquiries', $num($t['enquiries']), $change($c['enquiries'])],
    ['Quotes', $num($t['quotes']), $change($c['quotes'])],
    ['Revenue', $money($t['revenue']), $change($c['revenue'])],
    ['Sales', $num($t['sales']), $change($c['sales'])],
    ['Conversion rate (visitors to enquiries)', $pct($r['visitor_to_enquiry']), $change($c['conversion'], true)],
    ['Likes', $num($t['likes']), $change($c['likes'])],
];

$rows = [];
$monthly = count($d['days']) > 62;
foreach ($d['days'] as $day) {
    $key = $monthly ? substr($day['date'], 0, 7) : $day['date'];
    if (!isset($rows[$key])) {
        $rows[$key] = ['label' => $monthly ? $date($day['date'], 'M Y') : $date($day['date'], 'D j M Y'),
            'visitors' => 0, 'product_views' => 0, 'enquiries' => 0, 'quotes' => 0, 'revenue' => 0.0, 'likes' => 0];
    }
    foreach (['visitors', 'product_views', 'enquiries', 'quotes', 'revenue', 'likes'] as $k) {
        $rows[$key][$k] += $day[$k];
    }
}

$topTable = static function (string $title, array $items, string $unit) use ($e, $num): string {
    $html = '<h2>' . $e($title) . '</h2>';
    if ($items === []) {
        return $html . '<p class="muted">None in this period.</p>';
    }
    $html .= '<table class="grid"><thead><tr><th class="rank">#</th><th>Product</th><th class="num">' . $e(ucfirst($unit)) . '</th></tr></thead><tbody>';
    foreach (array_values($items) as $i => $item) {
        $html .= '<tr><td class="rank">' . ($i + 1) . '</td><td>' . $e($item['name'] ?? '') . '</td><td class="num">' . $num($item['count'] ?? 0) . '</td></tr>';
    }

    return $html . '</tbody></table>';
};

ob_start();
?>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; color: #0f172a; }
    .brand { border-bottom: 3px solid #7c3aed; padding-bottom: 6pt; margin-bottom: 10pt; }
    .brand-name { font-size: 15pt; font-weight: bold; }
    .brand-sub { font-size: 9pt; color: #64748b; }
    h1 { font-size: 13pt; margin: 0 0 2pt; }
    h2 { font-size: 11pt; margin: 14pt 0 5pt; color: #0f172a; }
    .meta td { font-size: 8.5pt; color: #475569; padding: 1pt 10pt 1pt 0; }
    .muted { color: #94a3b8; }
    .up { color: #15803d; }
    .down { color: #b91c1c; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #f1f5f9; color: #475569; font-size: 8.5pt; text-align: left; padding: 5pt 6pt; border-bottom: 1px solid #e2e8f0; }
    table.grid td { padding: 4.5pt 6pt; border-bottom: 1px solid #eef2f7; }
    table.grid tfoot td { font-weight: bold; border-top: 1px solid #cbd5e1; background: #f8fafc; }
    .num { text-align: right; }
    .rank { width: 18pt; color: #64748b; }
    .note { background: #fff7ed; color: #9a3412; padding: 6pt 8pt; margin: 6pt 0; font-size: 8.5pt; }
</style>

<div class="brand">
    <div class="brand-name"><?= $e($companyName) ?></div>
    <div class="brand-sub">Website performance report &middot; ultimate.co.tz</div>
</div>

<h1>Website dashboard</h1>
<table class="meta">
    <tr><td><b>Period:</b> <?= $period ?></td><td><b>Compared with:</b> <?= $prevPeriod ?></td></tr>
    <tr><td><b>Generated:</b> <?= $e($generated) ?> (Dar es Salaam)</td><td><?= $generatedBy !== '' ? '<b>By:</b> ' . $e($generatedBy) : '' ?></td></tr>
</table>

<?php if (empty($d['shop_connected'])): ?>
    <div class="note">Visitors, product views and likes are unavailable because ultimate.co.tz did not respond. Enquiries, quotes and revenue are up to date.</div>
<?php elseif (!empty($d['tracking_since']) && $d['tracking_since'] > $d['from']): ?>
    <div class="note">Visitor and product-view tracking started on <?= $e($date($d['tracking_since'])) ?>, so earlier days show zero.</div>
<?php endif; ?>

<h2>Summary</h2>
<table class="grid">
    <thead><tr><th>Metric</th><th class="num">This period</th><th class="num">vs previous period</th></tr></thead>
    <tbody>
    <?php foreach ($kpis as [$label, $value, $delta]): ?>
        <tr><td><?= $e($label) ?></td><td class="num"><b><?= $value ?></b></td><td class="num"><?= $delta ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Conversion funnel</h2>
<table class="grid">
    <thead><tr><th>Step</th><th class="num">Count</th><th class="num">Rate</th></tr></thead>
    <tbody>
        <tr><td>Visitors</td><td class="num"><?= $num($t['visitors']) ?></td><td class="num"></td></tr>
        <tr><td>Enquiries</td><td class="num"><?= $num($t['enquiries']) ?></td><td class="num"><?= $pct($r['visitor_to_enquiry']) ?> of visitors sent an enquiry</td></tr>
        <tr><td>Quotes</td><td class="num"><?= $num($t['quotes']) ?></td><td class="num"><?= $pct($r['enquiry_to_quote']) ?> of enquiries were quoted</td></tr>
        <tr><td>Sales</td><td class="num"><?= $num($t['sales']) ?></td><td class="num"><?= $pct($r['enquiry_to_sale']) ?> of enquiries became an invoice</td></tr>
    </tbody>
</table>

<h2><?= $monthly ? 'Monthly' : 'Daily' ?> breakdown</h2>
<table class="grid">
    <thead><tr><th><?= $monthly ? 'Month' : 'Date' ?></th><th class="num">Visitors</th><th class="num">Product views</th><th class="num">Enquiries</th><th class="num">Quotes</th><th class="num">Revenue</th><th class="num">Likes</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= $e($row['label']) ?></td>
            <td class="num"><?= $num($row['visitors']) ?></td>
            <td class="num"><?= $num($row['product_views']) ?></td>
            <td class="num"><?= $num($row['enquiries']) ?></td>
            <td class="num"><?= $num($row['quotes']) ?></td>
            <td class="num"><?= $money($row['revenue']) ?></td>
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
            <td class="num"><?= $money($t['revenue']) ?></td>
            <td class="num"><?= $num($t['likes']) ?></td>
        </tr>
    </tfoot>
</table>

<?= $topTable('Most viewed products', (array) $d['top_viewed'], 'views') ?>
<?= $topTable('Most liked products', (array) $d['top_liked'], 'likes') ?>
<?php
$html = (string) ob_get_clean();

try {
    $tempDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'mpdf';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 14,
        'margin_right' => 14,
        'margin_top' => 14,
        'margin_bottom' => 16,
        'tempDir' => $tempDir,
    ]);
    $mpdf->SetTitle('Website dashboard ' . $d['from'] . ' to ' . $d['to']);
    $mpdf->SetAuthor($companyName);
    $mpdf->SetFooter('UltiTech ERP &middot; Website dashboard||Page {PAGENO} of {nbpg}');
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
