<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);

global $pdo;

$id = (int) ($_GET['id'] ?? 0);
$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
$attachments = [
    ['name' => 'Rent_Invoice_June2026.pdf', 'size' => '245 KB'],
    ['name' => 'Payment_Receipt_CRDB.pdf', 'size' => '198 KB'],
];

if (isset($_GET['download_attachment'])) {
    $requested = trim((string) ($_GET['download_attachment'] ?? ''));
    $allowedNames = array_map(static function ($a) {
        return (string) ($a['name'] ?? '');
    }, $attachments);
    if (!in_array($requested, $allowedNames, true)) {
        http_response_code(404);
        echo 'Attachment not found.';
        exit;
    }

    $safeName = basename($requested);
    $candidate = __DIR__ . '/../uploads/journal-attachments/' . $safeName;
    if (is_file($candidate)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) filesize($candidate));
        readfile($candidate);
        exit;
    }

    $demo = "Demo attachment placeholder\nJournal ID: {$id}\nFile: {$safeName}\nGenerated: " . date('Y-m-d H:i:s');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . (string) strlen($demo));
    echo $demo;
    exit;
}

$jeHasCompany = function_exists('columnExists') && columnExists('erp_journal_entries', 'company_id', $pdo);
$jiHasCompany = function_exists('columnExists') && columnExists('erp_journal_items', 'company_id', $pdo);
$acctHasCompany = function_exists('columnExists') && columnExists('erp_accounts', 'company_id', $pdo);

if ($jeHasCompany && $company_id > 0) {
    $journalStmt = $pdo->prepare('SELECT * FROM erp_journal_entries WHERE id = ? AND company_id = ?');
    $journalStmt->execute([$id, $company_id]);
} else {
    $journalStmt = $pdo->prepare('SELECT * FROM erp_journal_entries WHERE id = ?');
    $journalStmt->execute([$id]);
}
$journal = $journalStmt->fetch(PDO::FETCH_ASSOC);
if (!$journal) {
    http_response_code(404);
    echo 'Journal entry not found.';
    exit;
}

$items = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
try {
    $joinOn = 'ji.account_id = a.id';
    if ($jiHasCompany && $acctHasCompany) {
        $joinOn .= ' AND a.company_id = ji.company_id';
    }
    $itemsSql = "SELECT ji.*, a.code, a.name FROM erp_journal_items ji JOIN erp_accounts a ON {$joinOn} WHERE ji.journal_id = ?";
    $itemsParams = [$id];
    if ($jiHasCompany && $company_id > 0) {
        $itemsSql .= ' AND ji.company_id = ?';
        $itemsParams[] = $company_id;
    }
    $itemsSql .= ' ORDER BY ji.id ASC';
    $itemsStmt = $pdo->prepare($itemsSql);
    $itemsStmt->execute($itemsParams);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($items as $it) {
        $totalDebit += (float) ($it['debit'] ?? 0);
        $totalCredit += (float) ($it['credit'] ?? 0);
    }
} catch (Throwable $e) {
    $items = [];
}

$desc = trim((string) ($journal['description'] ?? ''));
$journalType = 'General Journal';
$descLower = strtolower($desc);
if (strpos($descLower, 'payroll') !== false) {
    $journalType = 'Payroll Journal';
} elseif (strpos($descLower, 'sale') !== false) {
    $journalType = 'Sales Journal';
} elseif (strpos($descLower, 'bank') !== false || strpos($descLower, 'payment') !== false) {
    $journalType = 'Bank Journal';
} elseif (strpos($descLower, 'purchase') !== false || strpos($descLower, 'supplier') !== false) {
    $journalType = 'Purchase Journal';
} elseif (strpos($descLower, 'voucher') !== false) {
    $journalType = 'Payment Voucher';
}

if (!function_exists('sales_module_url')) {
    $salesFn = __DIR__ . '/../modules/sales/functions.php';
    if (is_file($salesFn)) {
        require_once $salesFn;
    }
}

$jeInvoiceViewUrl = static function (int $invoiceId): string {
    if ($invoiceId <= 0) {
        return '';
    }
    if (function_exists('sales_module_url')) {
        return sales_module_url('invoices/view.php', ['id' => $invoiceId, 'module' => 'sales']);
    }
    if (function_exists('company_url')) {
        return company_url('sales/invoice/' . $invoiceId) . '?module=sales';
    }
    $path = '/modules/sales/invoices/view.php?id=' . $invoiceId . '&module=sales';

    return function_exists('app_url') ? app_url($path) : $path;
};

/** @var array<string,int> */
$jeInvoiceIdByNumber = [];
$jeResolveInvoiceId = static function (string $invoiceNumber) use ($pdo, &$jeInvoiceIdByNumber): int {
    $key = strtoupper(trim($invoiceNumber));
    if ($key === '') {
        return 0;
    }
    if (array_key_exists($key, $jeInvoiceIdByNumber)) {
        return $jeInvoiceIdByNumber[$key];
    }
    $jeInvoiceIdByNumber[$key] = 0;
    try {
        if (function_exists('tableExists') && !tableExists('invoices', $pdo)) {
            return 0;
        }
        $st = $pdo->prepare('SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1');
        $st->execute([$invoiceNumber]);
        $jeInvoiceIdByNumber[$key] = (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $jeInvoiceIdByNumber[$key] = 0;
    }

    return $jeInvoiceIdByNumber[$key];
};

$jeLinkifyInvoiceRefs = static function (string $text) use ($jeResolveInvoiceId, $jeInvoiceViewUrl): string {
    $text = trim($text);
    if ($text === '' || $text === '-') {
        return htmlspecialchars($text !== '' ? $text : '-', ENT_QUOTES, 'UTF-8');
    }
    $parts = preg_split('/\b(INV-\d{4}-\d+)\b/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false || count($parts) === 1) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    $html = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match('/^INV-\d{4}-\d+$/i', $part)) {
            $invId = $jeResolveInvoiceId($part);
            $label = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            if ($invId > 0) {
                $href = $jeInvoiceViewUrl($invId);
            } elseif (function_exists('sales_module_url')) {
                $href = sales_module_url('invoices/index.php', [
                    'module' => 'sales',
                    'q' => $part,
                ]);
            } elseif (function_exists('app_url')) {
                $href = app_url('/modules/sales/invoices/index.php?module=sales&q=' . rawurlencode($part));
            } else {
                $href = '/modules/sales/invoices/index.php?module=sales&q=' . rawurlencode($part);
            }
            $html .= '<a class="jed-inv-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
        } else {
            $html .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        }
    }

    return $html !== '' ? $html : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
};

$jeVoucherViewUrl = static function (int $voucherId): string {
    if ($voucherId <= 0) {
        return '';
    }
    $path = 'view-voucher.php?id=' . $voucherId . '&module=voucher';
    if (function_exists('company_url')) {
        return company_url($path);
    }

    return function_exists('app_url') ? app_url('/' . $path) : '/' . $path;
};

$jeResolveVoucherId = static function (string $voucherNo) use ($pdo): int {
    $voucherNo = trim($voucherNo);
    if ($voucherNo === '') {
        return 0;
    }
    try {
        if (function_exists('tableExists') && !tableExists('payment_vouchers', $pdo)) {
            return 0;
        }
        $st = $pdo->prepare('SELECT id FROM payment_vouchers WHERE voucher_no = ? LIMIT 1');
        $st->execute([$voucherNo]);
        $found = (int) ($st->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }
        $alt = str_replace('-', '/', $voucherNo);
        if ($alt !== $voucherNo) {
            $st->execute([$alt]);
            $found = (int) ($st->fetchColumn() ?: 0);
            if ($found > 0) {
                return $found;
            }
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
};

/** @var list<array{key:string,label:string,href:string,type:string}> */
$relatedDocs = [];
$relatedSeen = [];
$jeAddRelated = static function (string $key, string $label, string $href, string $type) use (&$relatedDocs, &$relatedSeen): void {
    $k = strtoupper(trim($key));
    if ($k === '' || isset($relatedSeen[$k]) || $href === '') {
        return;
    }
    $relatedSeen[$k] = true;
    $relatedDocs[] = [
        'key' => $k,
        'label' => $label,
        'href' => $href,
        'type' => $type,
    ];
};

$scanText = trim($desc . ' ' . (string) ($journal['reference'] ?? '') . ' ' . (string) ($journal['entry_number'] ?? ''));
if (preg_match_all('/\b(INV-\d{4}-\d+)\b/i', $scanText, $invMatches)) {
    foreach (array_unique($invMatches[1]) as $invNo) {
        $invId = $jeResolveInvoiceId((string) $invNo);
        if ($invId > 0) {
            $href = $jeInvoiceViewUrl($invId);
        } elseif (function_exists('sales_module_url')) {
            $href = sales_module_url('invoices/index.php', ['module' => 'sales', 'q' => $invNo]);
        } else {
            $href = function_exists('app_url')
                ? app_url('/modules/sales/invoices/index.php?module=sales&q=' . rawurlencode((string) $invNo))
                : '/modules/sales/invoices/index.php?module=sales&q=' . rawurlencode((string) $invNo);
        }
        $jeAddRelated((string) $invNo, strtoupper((string) $invNo) . ' (Invoice)', $href, 'invoice');
    }
}
if (preg_match_all('/\b(PV[\/\-][A-Z0-9][A-Z0-9\/\-]*)\b/i', $scanText, $pvMatches)) {
    foreach (array_unique($pvMatches[1]) as $pvNo) {
        $pvId = $jeResolveVoucherId((string) $pvNo);
        if ($pvId > 0) {
            $jeAddRelated((string) $pvNo, (string) $pvNo . ' (Payment Voucher)', $jeVoucherViewUrl($pvId), 'voucher');
        }
    }
}

$page_title = 'Journal Entry Details';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header{display:none!important}
.jed-inv-link{color:#2563eb;font-weight:700;text-decoration:none}
.jed-inv-link:hover{text-decoration:underline}
.jed-rel-list{display:flex;flex-direction:column;gap:8px}
.jed-rel-list a{font-size:12px;color:#2563eb;text-decoration:none;font-weight:700}
.jed-rel-list a:hover{text-decoration:underline}
.jed-rel-empty{font-size:12px;color:#94a3b8}
.jed-rel-more{display:none;margin-top:8px;padding-top:8px;border-top:1px solid #eef2f7}
.jed-rel-more.is-open{display:block}
.jed-btn[aria-expanded="true"]{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.main-content.jed-shell{margin-top:0!important;padding:14px 0 26px!important;background:#f9fafb;font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif;color:#0f172a}
.jed-wrap{padding:0 16px}
.jed-top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.jed-title{margin:0;font-size:34px;font-weight:800;color:#0b1f5d;line-height:1.1}
.jed-sub{margin:6px 0 0;font-size:14px;color:#64748b}
.jed-bc{margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:#64748b}
.jed-bc a{color:#2563eb;text-decoration:none;font-weight:700}
.jed-actions{display:flex;gap:10px;flex-wrap:wrap}
.jed-btn{border:1px solid #dbe2ea;background:#fff;color:#0f172a;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:7px;cursor:pointer}
.jed-btn.warn{color:#f97316}
.jed-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:12px;align-items:start}
.jed-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.05);overflow:hidden;margin-bottom:12px}
.jed-hd{padding:12px 15px;border-bottom:1px solid #eef2f7;font-size:15px;font-weight:700;color:#0f172a}
.jed-bd{padding:14px 15px}
.jed-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 14px}
.jed-main{display:flex;gap:12px;align-items:flex-start}
.jed-status{width:54px;height:54px;border-radius:50%;background:#dcfce7;color:#16a34a;display:inline-flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0}
.jed-ref{font-size:32px;font-weight:800;line-height:1.05;color:#0f172a}
.jed-tag{display:inline-flex;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;background:#dcfce7;color:#15803d;margin-bottom:6px}
.jed-k{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.03em}
.jed-v{font-size:14px;color:#0f172a;font-weight:600;margin-top:3px}
.jed-v.muted{font-weight:500;color:#475569}
.jed-tabs{display:flex;gap:4px;flex-wrap:wrap;padding:0 12px;border-bottom:1px solid #eef2f7}
.jed-tabs button{border:0;background:transparent;padding:11px 12px;font-size:13px;font-weight:600;color:#64748b;border-bottom:2px solid transparent;cursor:pointer}
.jed-tabs button.active{color:#2563eb;border-bottom-color:#2563eb}
.jed-tabs button:hover{color:#2563eb}
.jed-pan{padding:14px 15px;display:none}
.jed-pan.is-active{display:block}
.jed-split{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.jed-table{width:100%;border-collapse:collapse;font-size:13px;min-width:820px}
.jed-table th,.jed-table td{padding:9px 8px;border-bottom:1px solid #eef2f7;vertical-align:middle}
.jed-table th{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700;background:#fafafa;text-align:left}
.jed-table .num{text-align:right}
.jed-footer-tot{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px;padding:10px 12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px}
.jed-footer-tot .n{font-size:12px;color:#64748b}
.jed-footer-tot .v{font-size:20px;font-weight:800;color:#0f172a;margin-top:2px}
.jed-footer-tot .ok{color:#16a34a}
.jed-footer-tot-right{max-width:560px;margin-left:auto}
.jed-ok{margin-top:10px;padding:10px 12px;border-radius:8px;background:#ecfdf5;border:1px solid #bbf7d0;color:#15803d;font-size:13px;font-weight:600}
.jed-kv{display:flex;justify-content:space-between;gap:8px;margin-bottom:9px;font-size:13px;color:#64748b}
.jed-kv span:last-child{font-weight:700;color:#0f172a;text-align:right}
.jed-pill{display:inline-flex;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#15803d}
.jed-flow-row{display:grid;grid-template-columns:22px minmax(0,1fr) auto;gap:8px 10px;align-items:start;margin-bottom:12px}
.jed-flow-row:last-child{margin-bottom:0}
.jed-dot{width:16px;height:16px;border-radius:50%;background:#22c55e;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:9px;line-height:1;flex-shrink:0;margin-top:2px}
.jed-flow-title{font-size:13px;font-weight:700;color:#0f172a;line-height:1.15}
.jed-flow-by{font-size:11px;color:#64748b;margin-top:3px}
.jed-flow-time{font-size:11px;color:#64748b;font-weight:600;white-space:nowrap;padding-top:1px}
.jed-att-row{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #eef2f7}
.jed-att-row:last-of-type{border-bottom:0}
.jed-att-main{min-width:0}
.jed-att-name{font-size:13px;color:#0f172a;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jed-att-meta{font-size:11px;color:#64748b;margin-top:2px}
.jed-att-actions{display:flex;align-items:center;gap:8px}
.jed-dl{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border:1px solid #dbe2ea;border-radius:7px;background:#fff;color:#2563eb;text-decoration:none;font-size:12px;font-weight:700}
.jed-rel a{font-size:12px;color:#2563eb;text-decoration:none;font-weight:700}
@media(max-width:1200px){.jed-grid{grid-template-columns:1fr}.jed-meta{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.jed-meta,.jed-split,.jed-footer-tot{grid-template-columns:1fr}.jed-ref{font-size:26px}}
</style>

<main class="main-content jed-shell">
  <div class="jed-wrap">
    <div class="jed-top">
      <div>
        <h1 class="jed-title">Journal Entry Details</h1>
        <p class="jed-sub"><?= htmlspecialchars((string) ($journal['entry_number'] ?? 'JE')) ?> · <?= htmlspecialchars($journalType) ?></p>
        <div class="jed-bc">
          <a href="journal-entries.php?module=<?= $jeModule ?>">Journal Entries</a><i class="fas fa-chevron-right"></i>
          <span><?= htmlspecialchars((string) ($journal['entry_number'] ?? 'JE')) ?></span>
        </div>
      </div>
      <div class="jed-actions">
        <button type="button" class="jed-btn"><i class="fas fa-download"></i> Download</button>
        <button type="button" class="jed-btn warn"><i class="fas fa-rotate-left"></i> Reverse Entry</button>
      </div>
    </div>

    <div class="jed-grid">
      <div>
        <section class="jed-card">
          <div class="jed-bd jed-meta">
            <div style="grid-column:span 2;">
              <div class="jed-main">
                <span class="jed-status"><i class="far fa-file-lines"></i></span>
                <div>
                  <span class="jed-tag">Posted</span>
                  <div class="jed-ref"><?= htmlspecialchars((string) ($journal['entry_number'] ?? 'JE-000001')) ?></div>
                  <div class="jed-v muted" style="margin-top:6px;"><?= htmlspecialchars($journalType) ?></div>
                  <div class="jed-v muted"><?= $desc !== '' ? $jeLinkifyInvoiceRefs($desc) : 'Journal entry' ?></div>
                </div>
              </div>
            </div>
            <div><div class="jed-k">Transaction Date</div><div class="jed-v"><?= !empty($journal['date']) ? htmlspecialchars(date('d/m/Y', strtotime((string)$journal['date']))) : '-' ?></div></div>
            <div><div class="jed-k">Posting Date</div><div class="jed-v"><?= !empty($journal['date']) ? htmlspecialchars(date('d/m/Y', strtotime((string)$journal['date']))) : '-' ?></div></div>
            <div><div class="jed-k">Period</div><div class="jed-v"><?= !empty($journal['date']) ? htmlspecialchars(date('F Y', strtotime((string)$journal['date']))) : '-' ?></div></div>
            <div><div class="jed-k">Reference No.</div><div class="jed-v"><?= htmlspecialchars((string) ($journal['entry_number'] ?? '-')) ?></div></div>
            <div><div class="jed-k">Created By</div><div class="jed-v"><?= htmlspecialchars((string) ($_SESSION['full_name'] ?? 'System Admin')) ?></div></div>
            <div><div class="jed-k">Created On</div><div class="jed-v"><?= !empty($journal['created_at']) ? htmlspecialchars(date('d/m/Y h:i A', strtotime((string)$journal['created_at']))) : htmlspecialchars(date('d/m/Y h:i A')) ?></div></div>
          </div>
        </section>

        <section class="jed-card" id="jedTabsCard">
          <div class="jed-tabs" role="tablist" aria-label="Journal details">
            <button class="active" type="button" role="tab" aria-selected="true" data-jed-tab="overview">Overview</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="lines">Journal Lines</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="attachments">Attachments (<?= count($attachments) ?>)</button>
            <button type="button" role="tab" aria-selected="false" data-jed-tab="audit">Audit Trail</button>
          </div>

          <div class="jed-pan is-active" data-jed-panel="overview" role="tabpanel">
            <div class="jed-split">
              <div><div class="jed-k">Description</div><div class="jed-v muted"><?= $desc !== '' ? $jeLinkifyInvoiceRefs($desc) : '—' ?></div></div>
              <div><div class="jed-k">Notes</div><div class="jed-v muted">Payment details and supporting remarks captured on post.</div></div>
            </div>
          </div>

          <div class="jed-pan" data-jed-panel="lines" role="tabpanel" hidden>
            <div style="overflow:auto;">
              <table class="jed-table">
                <thead>
                  <tr>
                    <th>No.</th><th>Account Code</th><th>Account Name</th><th>Description</th><th>Department</th><th>Project</th><th class="num">Debit (TZS)</th><th class="num">Credit (TZS)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($items)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#64748b;padding:22px;">No journal lines found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($items as $idx => $item): ?>
                      <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= htmlspecialchars((string) ($item['code'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['name'] ?? '-')) ?></td>
                        <td><?= $jeLinkifyInvoiceRefs((string) ($item['line_desc'] ?? ($desc !== '' ? $desc : '-'))) ?></td>
                        <td><?= htmlspecialchars((string) ($item['department'] ?? 'Administration')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['project'] ?? '-')) ?></td>
                        <td class="num"><?= (float)($item['debit'] ?? 0) > 0 ? number_format((float)$item['debit'], 2) : '0.00' ?></td>
                        <td class="num"><?= (float)($item['credit'] ?? 0) > 0 ? number_format((float)$item['credit'], 2) : '0.00' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
              <div class="jed-footer-tot jed-footer-tot-right">
                <div><div class="n">Total Debit (TZS)</div><div class="v"><?= number_format($totalDebit, 2) ?></div></div>
                <div><div class="n">Total Credit (TZS)</div><div class="v"><?= number_format($totalCredit, 2) ?></div></div>
                <div><div class="n">Difference</div><div class="v ok"><?= number_format($totalDebit - $totalCredit, 2) ?></div></div>
              </div>
              <div class="jed-ok"><i class="far fa-circle-check"></i> Debits and credits are balanced.</div>
            </div>
          </div>

          <div class="jed-pan" data-jed-panel="attachments" role="tabpanel" hidden>
            <?php foreach ($attachments as $att): ?>
              <div class="jed-att-row">
                <div class="jed-att-main">
                  <div class="jed-att-name"><?= htmlspecialchars((string) $att['name']) ?></div>
                  <div class="jed-att-meta"><?= htmlspecialchars((string) $att['size']) ?></div>
                </div>
                <div class="jed-att-actions">
                  <a class="jed-dl" href="?id=<?= (int) $id ?>&module=<?= urlencode((string) $jeModule) ?>&download_attachment=<?= urlencode((string) $att['name']) ?>"><i class="fas fa-download"></i> Download</a>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($attachments)): ?>
              <div class="jed-v muted">No attachments.</div>
            <?php endif; ?>
          </div>

          <div class="jed-pan" data-jed-panel="audit" role="tabpanel" hidden>
            <div class="jed-kv"><span>Posted</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
            <div class="jed-kv"><span>Approved</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
            <div class="jed-kv" style="margin-bottom:0;"><span>Reviewed</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
          </div>
        </section>
      </div>

      <aside>
        <section class="jed-card">
          <div class="jed-hd"><i class="fas fa-square-poll-vertical"></i> Journal Summary</div>
          <div class="jed-bd">
            <div class="jed-kv"><span>Journal</span><span><?= htmlspecialchars($journalType) ?></span></div>
            <div class="jed-kv"><span>Status</span><span><span class="jed-pill">Posted</span></span></div>
            <div class="jed-kv"><span>Debit (TZS)</span><span><?= number_format($totalDebit, 2) ?></span></div>
            <div class="jed-kv"><span>Credit (TZS)</span><span><?= number_format($totalCredit, 2) ?></span></div>
            <div class="jed-kv" style="margin-bottom:0;"><span>Difference</span><span style="color:#16a34a;"><?= number_format($totalDebit - $totalCredit, 2) ?></span></div>
          </div>
        </section>

        <section class="jed-card">
          <div class="jed-hd"><i class="fas fa-gears"></i> Approval Workflow</div>
          <div class="jed-bd">
            <div class="jed-flow-row">
              <span class="jed-dot"><i class="fas fa-check"></i></span>
              <div><div class="jed-flow-title">Draft</div><div class="jed-flow-by">System Admin</div></div>
              <div class="jed-flow-time">01/06/2026 10:15 AM</div>
            </div>
            <div class="jed-flow-row">
              <span class="jed-dot"><i class="fas fa-check"></i></span>
              <div><div class="jed-flow-title">Reviewed</div><div class="jed-flow-by">Finance User</div></div>
              <div class="jed-flow-time">01/06/2026 11:05 AM</div>
            </div>
            <div class="jed-flow-row">
              <span class="jed-dot"><i class="fas fa-check"></i></span>
              <div><div class="jed-flow-title">Approved</div><div class="jed-flow-by">Finance Manager</div></div>
              <div class="jed-flow-time">01/06/2026 11:20 AM</div>
            </div>
            <div class="jed-flow-row">
              <span class="jed-dot"><i class="fas fa-check"></i></span>
              <div><div class="jed-flow-title">Posted</div><div class="jed-flow-by">System Admin</div></div>
              <div class="jed-flow-time">01/06/2026 11:25 AM</div>
            </div>
          </div>
        </section>

        <section class="jed-card jed-rel" id="jedRelatedCard">
          <div class="jed-hd"><i class="fas fa-link"></i> Related Documents</div>
          <div class="jed-bd">
            <?php if (empty($relatedDocs)): ?>
              <div class="jed-rel-empty">No related documents linked to this journal entry.</div>
            <?php else: ?>
              <?php
              $primary = $relatedDocs[0];
              $extraDocs = array_slice($relatedDocs, 1);
              ?>
              <div class="jed-rel-list">
                <a href="<?= htmlspecialchars($primary['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($primary['label'], ENT_QUOTES, 'UTF-8') ?></a>
              </div>
              <?php if (!empty($extraDocs)): ?>
                <div class="jed-rel-more" id="jedRelatedMore" hidden>
                  <div class="jed-rel-list">
                    <?php foreach ($extraDocs as $doc): ?>
                      <a href="<?= htmlspecialchars($doc['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($doc['label'], ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
              <div style="margin-top:10px;">
                <button
                  type="button"
                  class="jed-btn"
                  id="jedRelatedToggle"
                  style="padding:6px 10px;font-size:12px;"
                  aria-expanded="false"
                  aria-controls="jedRelatedMore"
                  data-related-count="<?= (int) count($relatedDocs) ?>"
                >View All Related<?= count($relatedDocs) > 1 ? ' (' . count($relatedDocs) . ')' : '' ?></button>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </aside>
    </div>
  </div>
</main>
<script>
(function () {
  var card = document.getElementById('jedTabsCard');
  if (card) {
    var tabs = card.querySelectorAll('[data-jed-tab]');
    var panels = card.querySelectorAll('[data-jed-panel]');
    function activate(name) {
      tabs.forEach(function (btn) {
        var on = btn.getAttribute('data-jed-tab') === name;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach(function (panel) {
        var on = panel.getAttribute('data-jed-panel') === name;
        panel.classList.toggle('is-active', on);
        if (on) panel.removeAttribute('hidden');
        else panel.setAttribute('hidden', '');
      });
    }
    tabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activate(btn.getAttribute('data-jed-tab') || 'overview');
      });
    });
  }

  var toggle = document.getElementById('jedRelatedToggle');
  var more = document.getElementById('jedRelatedMore');
  if (toggle) {
    toggle.addEventListener('click', function () {
      if (more) {
        var open = more.classList.toggle('is-open');
        if (open) more.removeAttribute('hidden');
        else more.setAttribute('hidden', '');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.textContent = open ? 'Hide Related' : ('View All Related (' + (toggle.getAttribute('data-related-count') || '') + ')');
        return;
      }
      var first = document.querySelector('#jedRelatedCard .jed-rel-list a');
      if (first && first.href) {
        window.location.href = first.href;
      }
    });
  }
})();
</script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
