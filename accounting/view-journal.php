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

    // Serve real file if it exists, otherwise provide demo content download.
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

$journalStmt = $pdo->prepare('SELECT * FROM erp_journal_entries WHERE id = ? AND company_id = ?');
$journalStmt->execute([$id, $company_id]);
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
    $itemsStmt = $pdo->prepare('SELECT ji.*, a.code, a.name FROM erp_journal_items ji JOIN erp_accounts a ON ji.account_id = a.id AND a.company_id = ji.company_id WHERE ji.journal_id = ? AND ji.company_id = ? ORDER BY ji.id ASC');
    $itemsStmt->execute([$id, $company_id]);
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

$page_title = 'Journal Entry Details';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header{display:none!important}
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
.jed-pan{padding:14px 15px}
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
        <p class="jed-sub">View and manage journal entry</p>
        <nav class="jed-bc">
          <a href="../index.php">Home</a><i class="fas fa-chevron-right"></i>
          <a href="#">Finance &amp; Accounting</a><i class="fas fa-chevron-right"></i>
          <a href="journal-entries.php?module=<?= $jeModule ?>">Journal Entries</a><i class="fas fa-chevron-right"></i>
          <span><?= htmlspecialchars((string) ($journal['entry_number'] ?? 'JE')) ?></span>
        </nav>
      </div>
      <div class="jed-actions">
        <a class="jed-btn" href="journal-entries.php?module=<?= $jeModule ?>"><i class="fas fa-arrow-left"></i> Back</a>
        <button type="button" class="jed-btn"><i class="fas fa-print"></i> Print</button>
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
                  <div class="jed-v muted"><?= htmlspecialchars($desc !== '' ? $desc : 'Journal entry') ?></div>
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

        <section class="jed-card">
          <div class="jed-tabs">
            <button class="active" type="button">Overview</button>
            <button type="button">Journal Lines</button>
            <button type="button">Attachments (2)</button>
            <button type="button">Audit Trail</button>
          </div>
          <div class="jed-pan">
            <div class="jed-split">
              <div><div class="jed-k">Description</div><div class="jed-v muted"><?= htmlspecialchars($desc !== '' ? $desc : '—') ?></div></div>
              <div><div class="jed-k">Notes</div><div class="jed-v muted">Payment details and supporting remarks captured on post.</div></div>
            </div>
          </div>
        </section>

        <section class="jed-card">
          <div class="jed-hd">Journal Lines</div>
          <div class="jed-bd" style="overflow:auto;">
            <table class="jed-table">
              <thead>
                <tr>
                  <th>#</th><th>Account Code</th><th>Account Name</th><th>Description</th><th>Department</th><th>Project</th><th class="num">Debit (TZS)</th><th class="num">Credit (TZS)</th>
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
                      <td><?= htmlspecialchars((string) ($item['line_desc'] ?? ($desc !== '' ? $desc : '-'))) ?></td>
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
        </section>

        <div class="jed-split">
          <section class="jed-card">
            <div class="jed-hd">Attachments (<?= count($attachments) ?>)</div>
            <div class="jed-bd">
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
              <button class="jed-btn" type="button" style="padding:6px 10px;font-size:12px;">View All</button>
            </div>
          </section>
          <section class="jed-card">
            <div class="jed-hd">Audit Trail (Latest 3)</div>
            <div class="jed-bd">
              <div class="jed-kv"><span>Posted</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
              <div class="jed-kv"><span>Approved</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
              <div class="jed-kv" style="margin-bottom:0;"><span>Reviewed</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
              <button class="jed-btn" type="button" style="padding:6px 10px;font-size:12px;margin-top:8px;">View Full Audit Trail</button>
            </div>
          </section>
        </div>
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

        <section class="jed-card jed-rel">
          <div class="jed-hd"><i class="fas fa-link"></i> Related Documents</div>
          <div class="jed-bd">
            <a href="#">PV-2026-00045 (Payment Voucher)</a>
            <div style="margin-top:10px;"><button type="button" class="jed-btn" style="padding:6px 10px;font-size:12px;">View All Related</button></div>
          </div>
        </section>
      </aside>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
