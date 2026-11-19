<?php
if (!defined('GL_VIEW')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/gl/gl_view_helpers.php';
require_once __DIR__ . '/../../includes/gl/gl_bridge.php';

// Keep Accounts Payable accruals current whenever this statement is opened.
try {
    gl_bridge_sync_all($pdo, ['only' => ['payable']]);
} catch (Throwable $e) {
    error_log('balance-sheet payable sync: ' . $e->getMessage());
}

$asOf = gl_date_param('as_of', date('Y-m-d'));
$yearsCount = (int) ($_GET['years'] ?? 3);
if (!in_array($yearsCount, [1, 2, 3, 4, 5], true)) {
    $yearsCount = 3;
}

$columns = [];
$cursor = new DateTimeImmutable($asOf);
for ($i = 0; $i < $yearsCount; $i++) {
    $date = $cursor->modify('-' . $i . ' years')->format('Y-m-d');
    $columns[] = [
        'date' => $date,
        'year' => (int) substr($date, 0, 4),
        'label' => date('Y', strtotime($date)),
    ];
}
$columns = array_reverse($columns); // oldest → newest, like the template

$sheets = [];
foreach ($columns as $col) {
    $sheets[$col['date']] = gl_balance_sheet($pdo, $col['date']);
}
$latest = $sheets[$asOf];
$balanced = abs((float) $latest['difference']) < 0.005;

$sectionMeta = [
    'current_assets' => ['group' => 'assets', 'title' => 'CURRENT ASSETS', 'total' => 'TOTAL CURRENT ASSETS'],
    'fixed_assets' => ['group' => 'assets', 'title' => 'FIXED ASSETS', 'total' => 'TOTAL FIXED ASSETS'],
    'other_assets' => ['group' => 'assets', 'title' => 'OTHER ASSETS', 'total' => 'TOTAL OTHER ASSETS'],
    'current_liabilities' => ['group' => 'liabilities', 'title' => 'CURRENT LIABILITIES', 'total' => 'TOTAL CURRENT LIABILITIES'],
    'long_term_liabilities' => ['group' => 'liabilities', 'title' => 'LONG-TERM LIABILITIES', 'total' => 'TOTAL LONG-TERM LIABILITIES'],
    'equity' => ['group' => 'equity', 'title' => "OWNER'S EQUITY", 'total' => "TOTAL OWNER'S EQUITY"],
];

/** Collect account rows that appear in any year for a section. */
$unionSection = static function (string $section) use ($sheets): array {
    $byId = [];
    foreach ($sheets as $sheet) {
        foreach ($sheet['sections'][$section] ?? [] as $row) {
            $id = (int) $row['id'];
            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'id' => $id,
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                ];
            }
        }
    }
    $rows = array_values($byId);
    usort($rows, static fn ($a, $b) => strnatcasecmp($a['code'] . ' ' . $a['name'], $b['code'] . ' ' . $b['name']));

    return $rows;
};

$amountAt = static function (string $section, int $accountId, string $date) use ($sheets): float {
    foreach ($sheets[$date]['sections'][$section] ?? [] as $row) {
        if ((int) $row['id'] === $accountId) {
            return (float) $row['amount'];
        }
    }

    return 0.0;
};

$earningsByDate = [];
foreach ($columns as $col) {
    $earningsByDate[$col['date']] = (float) $sheets[$col['date']]['current_earnings'];
}
$hasEarnings = false;
foreach ($earningsByDate as $v) {
    if (abs($v) >= 0.005) {
        $hasEarnings = true;
        break;
    }
}

$companyName = trim((string) ($_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : '')));
if ($companyName === '') {
    $companyName = 'Company';
}
$asOfLabel = date('F j,', strtotime($asOf));
$module = (string) ($_GET['module'] ?? 'accounting');

$buildLines = static function () use (
    $sectionMeta,
    $unionSection,
    $amountAt,
    $columns,
    $sheets,
    $hasEarnings,
    $earningsByDate
): array {
    $lines = [];
    $pushSection = static function (string $section) use (&$lines, $sectionMeta, $unionSection, $amountAt, $columns, $sheets): void {
        $meta = $sectionMeta[$section];
        $accounts = $unionSection($section);
        $lines[] = ['kind' => 'subsection', 'label' => $meta['title']];
        if ($accounts === []) {
            $zeros = array_fill(0, count($columns), 0.0);
            $lines[] = ['kind' => 'account', 'id' => 0, 'code' => '', 'label' => 'None', 'amounts' => $zeros, 'muted' => true];
        }
        foreach ($accounts as $account) {
            $amounts = [];
            foreach ($columns as $col) {
                $amounts[] = $amountAt($section, (int) $account['id'], $col['date']);
            }
            $lines[] = [
                'kind' => 'account',
                'id' => (int) $account['id'],
                'code' => $account['code'],
                'label' => $account['name'],
                'amounts' => $amounts,
            ];
        }
        $totals = [];
        foreach ($columns as $col) {
            $totals[] = (float) ($sheets[$col['date']]['section_totals'][$section] ?? 0);
        }
        $lines[] = ['kind' => 'subtotal', 'label' => $meta['total'], 'amounts' => $totals];
    };

    $lines[] = ['kind' => 'major', 'label' => 'ASSETS'];
    foreach (['current_assets', 'fixed_assets', 'other_assets'] as $section) {
        $pushSection($section);
    }
    $assetTotals = [];
    foreach ($columns as $col) {
        $assetTotals[] = (float) $sheets[$col['date']]['total_assets'];
    }
    $lines[] = ['kind' => 'grand', 'label' => 'TOTAL ASSETS', 'amounts' => $assetTotals];

    $lines[] = ['kind' => 'major', 'label' => "LIABILITIES AND OWNER'S EQUITY"];
    foreach (['current_liabilities', 'long_term_liabilities'] as $section) {
        $pushSection($section);
    }

    $lines[] = ['kind' => 'subsection', 'label' => "OWNER'S EQUITY"];
    foreach ($unionSection('equity') as $account) {
        $amounts = [];
        foreach ($columns as $col) {
            $amounts[] = $amountAt('equity', (int) $account['id'], $col['date']);
        }
        $lines[] = [
            'kind' => 'account',
            'id' => (int) $account['id'],
            'code' => $account['code'],
            'label' => $account['name'],
            'amounts' => $amounts,
        ];
    }
    if ($hasEarnings) {
        $earnAmounts = [];
        foreach ($columns as $col) {
            $earnAmounts[] = $earningsByDate[$col['date']];
        }
        $lines[] = [
            'kind' => 'account',
            'id' => 0,
            'code' => '',
            'label' => 'Retained Earnings (current earnings)',
            'amounts' => $earnAmounts,
            'muted' => true,
        ];
    }
    $equityTotals = [];
    foreach ($columns as $col) {
        $equityTotals[] = (float) $sheets[$col['date']]['total_equity'];
    }
    $lines[] = ['kind' => 'subtotal', 'label' => "TOTAL OWNER'S EQUITY", 'amounts' => $equityTotals];

    $lieTotals = [];
    foreach ($columns as $col) {
        $lieTotals[] = (float) $sheets[$col['date']]['total_liabilities'] + (float) $sheets[$col['date']]['total_equity'];
    }
    $lines[] = ['kind' => 'grand', 'label' => 'TOTAL LIABILITIES AND OWNER\'S EQUITY', 'amounts' => $lieTotals];

    return $lines;
};

$lines = $buildLines();

$export = (string) ($_GET['export'] ?? '');
if ($export === 'csv') {
    $header = ['Section', 'Code', 'Account'];
    foreach ($columns as $col) {
        $header[] = $col['label'];
    }
    $csv = [];
    $section = '';
    foreach ($lines as $line) {
        if ($line['kind'] === 'major' || $line['kind'] === 'subsection') {
            $section = $line['label'];
            continue;
        }
        $row = [$section, $line['code'] ?? '', $line['label']];
        foreach ($line['amounts'] as $amount) {
            $row[] = $amount;
        }
        $csv[] = $row;
    }
    gl_send_csv('balance-sheet-' . $asOf . '.csv', $header, $csv);
}

if ($export === 'pdf') {
    $logo = gl_pdf_logo();
    $yearWidth = min(90.0, 420.0 / max(1, count($columns)));
    $pages = [];
    $chunks = array_chunk($lines, 28) ?: [[]];
    foreach ($chunks as $pageIndex => $pageLines) {
        $c = '';
        if ($logo !== null) {
            $lw = 64.0;
            $lh = $lw * $logo['height'] / max(1, $logo['width']);
            $c .= sprintf("q %.2F 0 0 %.2F 32 %.2F cm /Logo Do Q\n", $lw, $lh, 540 + (28 - $lh) / 2);
        }
        $tx = $logo !== null ? 110.0 : 32.0;
        $c .= gl_pdf_text($tx, 560, 11, gl_pdf_ascii($companyName), false, true);
        $c .= gl_pdf_text(810, 560, 16, 'BALANCE SHEET', true, true);
        $c .= gl_pdf_text(810, 542, 9, 'As of ' . date('F j,', strtotime($asOf)), true);
        $x = 810.0;
        foreach (array_reverse($columns) as $col) {
            $c .= gl_pdf_text($x, 526, 9, $col['label'], true, true);
            $x -= $yearWidth;
        }
        $c .= "0.7 w 32 518 m 810 518 l S\n";
        $y = 500;
        foreach ($pageLines as $line) {
            $bold = in_array($line['kind'], ['major', 'subsection', 'subtotal', 'grand'], true);
            $indent = $line['kind'] === 'account' ? '    ' : ($line['kind'] === 'subsection' ? '  ' : '');
            $label = $indent . (($line['code'] ?? '') !== '' ? $line['code'] . '  ' : '') . $line['label'];
            $c .= gl_pdf_text(32, $y, $line['kind'] === 'major' ? 9 : 8, substr(gl_pdf_ascii($label), 0, 70), false, $bold);
            if (isset($line['amounts'])) {
                $x = 810.0;
                foreach (array_reverse($line['amounts']) as $amount) {
                    $c .= gl_pdf_text($x, $y, 8, gl_fmt((float) $amount), true, $bold);
                    $x -= $yearWidth;
                }
            }
            if ($line['kind'] === 'grand' || $line['kind'] === 'subtotal') {
                $c .= '0.45 w 32 ' . ($y + 10) . ' m 810 ' . ($y + 10) . " l S\n";
            }
            $y -= 14;
        }
        $c .= gl_pdf_text(810, 18, 7, 'Page ' . ($pageIndex + 1) . ' of ' . count($chunks) . '  |  Amounts in TZS', true);
        $pages[] = $c;
    }
    gl_pdf_send('balance-sheet-' . $asOf . '.pdf', $pages, $logo);
}

$exportUrl = static function (string $format) use ($asOf, $yearsCount, $module): string {
    return '?' . http_build_query([
        'module' => $module,
        'as_of' => $asOf,
        'years' => $yearsCount,
        'export' => $format,
    ]);
};

$page_title = 'Balance Sheet';
include __DIR__ . '/../../modules/balances/includes/header.php';
include __DIR__ . '/../../includes/gl/gl_page_style.php';
$colCount = count($columns);
?>
<style>
.main-content.glr.glr-bs{padding-top:8px!important;background:#f1f5f9!important}
html:not([data-theme="dark"]) body:has(.glr-bs),html:not([data-theme="dark"]) body:has(.glr-bs) .layout-main-wrapper{background:#f1f5f9!important}
.glr-bs .glr-wrap{width:100%;max-width:1100px;margin:0 auto;padding:0 12px 28px}
.glr-bs-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 12px}
.glr-bs-toolbar .glr-form{padding:0;background:transparent;border:0}
.glr-bs-sheet{background:#fff;border:1px solid #e5e7eb;border-radius:4px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.glr-bs-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:22px 28px 10px}
.glr-bs-company{margin:0;font-size:18px;font-weight:700;color:#0f172a;letter-spacing:.01em}
.glr-bs-title{margin:0;font-size:28px;font-weight:800;color:#1e3a8a;letter-spacing:.02em;text-align:right;line-height:1.1}
.glr-bs-asof{margin:6px 0 0;font-size:13px;color:#334155;text-align:right}
.glr-bs-table-wrap{overflow:auto;padding:0 12px 18px}
.glr-bs-table{width:100%;border-collapse:collapse;font-size:13px;color:#0f172a}
.glr-bs-table th,.glr-bs-table td{padding:7px 10px;border:0;vertical-align:bottom}
.glr-bs-table thead th{font-size:13px;font-weight:700;color:#0f172a;text-align:right;border-bottom:2px solid #334155;padding-top:4px;padding-bottom:10px}
.glr-bs-table thead th.glr-bs-label{text-align:left;font-weight:600;color:#64748b;border-bottom-color:transparent}
.glr-bs-table .glr-bs-cur{width:28px;text-align:left;color:#64748b;font-size:12px;padding-right:0}
.glr-bs-table .glr-bs-amt{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;min-width:108px}
.glr-bs-table .glr-bs-neg{color:#dc2626}
.glr-bs-table tr.glr-bs-major td{padding-top:18px;padding-bottom:8px;font-size:14px;font-weight:800;letter-spacing:.04em;border-bottom:1.5px solid #334155}
.glr-bs-table tr.glr-bs-sub td{padding-top:14px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#0f172a}
.glr-bs-table tr.glr-bs-account td.glr-bs-label{padding-left:28px}
.glr-bs-table tr.glr-bs-account a{color:#0f172a;text-decoration:none}
.glr-bs-table tr.glr-bs-account a:hover{color:#1d4ed8;text-decoration:underline}
.glr-bs-table tr.glr-bs-muted td.glr-bs-label{font-style:italic;color:#475569}
.glr-bs-table tr.glr-bs-total td{font-weight:700;background:#dbeafe;border-top:1px solid #93c5fd;border-bottom:1px solid #93c5fd}
.glr-bs-table tr.glr-bs-grand td{font-weight:800;background:#bfdbfe;border-top:2px solid #1e3a8a;border-bottom:2px solid #1e3a8a}
.glr-bs-status{display:flex;justify-content:flex-end;padding:0 28px 18px}
.glr-bs-note{margin:0;padding:0 28px 18px;font-size:12px;color:#64748b}
@media print{
  .glr-nav,.glr-bs-toolbar,.sidebar,.mobile-footer,.employee-header{display:none!important}
  .main-content.glr.glr-bs{background:#fff!important;padding:0!important}
  .glr-bs-sheet{border:0;box-shadow:none}
}
@media (max-width: 720px){
  .glr-bs-head{flex-direction:column;align-items:stretch;padding:16px}
  .glr-bs-title,.glr-bs-asof{text-align:left}
  .glr-bs-title{font-size:22px}
  .glr-bs-table tr.glr-bs-account td.glr-bs-label{padding-left:14px}
}
</style>
<main class="main-content glr glr-bs">
  <div class="glr-wrap">
    <div class="glr-bs-toolbar">
      <form method="get" class="glr-form">
        <input type="hidden" name="module" value="<?= gl_h($module) ?>">
        <label>As of <input type="date" name="as_of" value="<?= gl_h($asOf) ?>"></label>
        <label>Years
          <select name="years">
            <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
              <option value="<?= $n ?>" <?= $yearsCount === $n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="glr-btn" type="submit">View</button>
      </form>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="glr-btn secondary" href="<?= gl_h($exportUrl('csv')) ?>">Export CSV</a>
        <a class="glr-btn secondary" href="<?= gl_h($exportUrl('pdf')) ?>">Export PDF</a>
        <button class="glr-btn secondary" type="button" onclick="window.print()">Print</button>
      </div>
    </div>

    <div class="glr-bs-sheet">
      <div class="glr-bs-head">
        <h2 class="glr-bs-company"><?= gl_h($companyName) ?></h2>
        <div>
          <h1 class="glr-bs-title">BALANCE SHEET</h1>
          <p class="glr-bs-asof">As of <?= gl_h($asOfLabel) ?></p>
        </div>
      </div>

      <div class="glr-bs-status">
        <span class="glr-pill <?= $balanced ? 'ok' : 'bad' ?>">
          <?= $balanced ? 'Assets = Liabilities + Equity' : ('Out by ' . gl_fmt((float) $latest['difference'])) ?>
        </span>
      </div>

      <div class="glr-bs-table-wrap">
        <table class="glr-bs-table">
          <thead>
            <tr>
              <th class="glr-bs-label"></th>
              <?php foreach ($columns as $col): ?>
                <th class="glr-bs-cur"></th>
                <th class="glr-bs-amt"><?= gl_h($col['label']) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lines as $line):
                $kind = $line['kind'];
                $rowClass = match ($kind) {
                    'major' => 'glr-bs-major',
                    'subsection' => 'glr-bs-sub',
                    'subtotal' => 'glr-bs-total',
                    'grand' => 'glr-bs-grand',
                    default => 'glr-bs-account' . (!empty($line['muted']) ? ' glr-bs-muted' : ''),
                };
                ?>
              <tr class="<?= $rowClass ?>">
                <td class="glr-bs-label">
                  <?php if ($kind === 'account' && !empty($line['id'])): ?>
                    <a href="ledger.php?account_id=<?= (int) $line['id'] ?>&to_date=<?= gl_h($asOf) ?>&module=<?= gl_h($module) ?>">
                      <?= gl_h(($line['code'] !== '' ? $line['code'] . '  ' : '') . $line['label']) ?>
                    </a>
                  <?php else: ?>
                    <?= gl_h($line['label']) ?>
                  <?php endif; ?>
                </td>
                <?php if (isset($line['amounts'])): ?>
                  <?php foreach ($line['amounts'] as $amount):
                      $neg = $amount < -0.004;
                      ?>
                    <td class="glr-bs-cur"><?= abs($amount) >= 0.005 ? 'TSh' : '' ?></td>
                    <td class="glr-bs-amt<?= $neg ? ' glr-bs-neg' : '' ?>"><?= gl_fmt((float) $amount) ?></td>
                  <?php endforeach; ?>
                <?php else: ?>
                  <?php for ($i = 0; $i < $colCount; $i++): ?>
                    <td class="glr-bs-cur"></td>
                    <td class="glr-bs-amt"></td>
                  <?php endfor; ?>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="glr-bs-note">Amounts in Tanzanian Shillings (TZS). Comparative columns use the same month and day in prior years.</p>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../modules/balances/includes/footer.php'; ?>
