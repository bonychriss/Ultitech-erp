<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
$page_title = 'Create Journal';

$journalName = trim((string) ($_GET['name'] ?? 'Sales Journal'));
$journalCode = trim((string) ($_GET['code'] ?? 'SAL'));
$journalType = strtolower(trim((string) ($_GET['type'] ?? 'sales')));
$sequencePrefix = trim((string) ($_GET['prefix'] ?? ($journalCode !== '' ? $journalCode : 'JNL')));
$description = trim((string) ($_GET['description'] ?? 'Used to record all sales transactions including invoices and customer credit notes.'));

$journalTypes = [
    'general' => 'General',
    'sales' => 'Sales',
    'purchase' => 'Purchase',
    'bank' => 'Bank',
    'cash' => 'Cash',
    'payments' => 'Payments',
    'payroll' => 'Payroll',
];
if (!isset($journalTypes[$journalType])) {
    $journalType = 'sales';
}

$currencies = ['TZS - Tanzanian Shilling', 'USD - US Dollar', 'KES - Kenyan Shilling'];
$linkedModules = ['Sales', 'Purchasing', 'Balances', 'Payroll', 'General'];
$accounts = [
    '1010 - Accounts Receivable',
    '1001 - Operating Bank Account',
    '1002 - Cash In Hand',
    '4001 - Sales Revenue',
    '5001 - Salary & Wage Expense',
    '2002 - NSSF Payable',
    '1999 - Suspense Account',
];

include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header { display:none !important; }
.main-content.jc-shell { margin-top:0 !important; padding:14px 0 28px !important; background:#f9fafb; font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif; }
.jc-inner { padding:0 16px; }
.jc-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.jc-title { margin:0; font-size:34px; font-weight:800; color:#0b1f5d; line-height:1.1; }
.jc-sub { margin:6px 0 0; color:#64748b; font-size:14px; }
.jc-bc { margin-top:8px; display:flex; gap:7px; flex-wrap:wrap; align-items:center; color:#64748b; font-size:12px; }
.jc-bc a { color:#2563eb; text-decoration:none; font-weight:700; }
.jc-actions { display:flex; gap:10px; flex-wrap:wrap; }
.jc-btn { border:1px solid #dbe2ea; background:#fff; color:#0f172a; border-radius:8px; padding:9px 14px; font-weight:600; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; cursor:pointer; }
.jc-btn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
.jc-grid { display:grid; grid-template-columns:minmax(0,1fr) 560px; gap:14px; align-items:start; }
.jc-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:12px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
.jc-hd { padding:12px 16px; border-bottom:1px solid #eef2f7; font-size:15px; font-weight:700; color:#0f172a; display:flex; gap:8px; align-items:center; }
.jc-num { width:24px; height:24px; border-radius:7px; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; }
.jc-bd { padding:14px 16px; }
.jc-row2 { display:grid; grid-template-columns:1fr 1fr; gap:12px 14px; }
.jc-fg label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:6px; }
.jc-inp,.jc-sel,.jc-ta { width:100%; border:1px solid #dbe2ea; border-radius:8px; height:40px; padding:0 11px; font-size:14px; color:#0f172a; background:#fff; }
.jc-ta { min-height:92px; height:auto; padding:10px 11px; resize:vertical; line-height:1.4; }
.jc-inp:focus,.jc-sel:focus,.jc-ta:focus { outline:none; border-color:#93c5fd; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
.jc-switch { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
.jc-switch .txt { font-size:13px; color:#0f172a; font-weight:600; }
.jc-switch .sub { font-size:11px; color:#64748b; margin-top:2px; }
.jc-tg { width:40px; height:22px; position:relative; flex-shrink:0; }
.jc-tg input { display:none; }
.jc-tg span { position:absolute; inset:0; border-radius:999px; background:#e2e8f0; cursor:pointer; transition:.2s; }
.jc-tg span:before { content:""; position:absolute; width:18px; height:18px; left:2px; top:2px; border-radius:50%; background:#fff; transition:.2s; }
.jc-tg input:checked + span { background:#22c55e; }
.jc-tg input:checked + span:before { transform:translateX(18px); }
.jc-preview-badge { width:76px; height:64px; border-radius:12px; background:#eff6ff; color:#2563eb; font-size:38px; font-weight:800; display:inline-flex; align-items:center; justify-content:center; }
.jc-preview-head { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
.jc-preview-name { font-size:24px; font-weight:800; line-height:1.05; color:#0f172a; margin:0; }
.jc-preview-meta { display:flex; align-items:center; gap:8px; margin-top:6px; flex-wrap:wrap; }
.jc-chip { display:inline-flex; align-items:center; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:700; }
.jc-chip.type { background:#dcfce7; color:#15803d; }
.jc-chip.code { background:#f1f5f9; color:#334155; }
.jc-preview-flow { display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:10px; }
.jc-preview-acc { border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; font-size:13px; color:#334155; background:#fafafa; min-height:84px; }
.jc-preview-acc .hd { font-size:12px; font-weight:700; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.02em; }
.jc-preview-acc.debit .hd { color:#2563eb; }
.jc-preview-acc.credit .hd { color:#16a34a; }
.jc-preview-acc .nm { font-size:13px; font-weight:600; color:#0f172a; line-height:1.35; }
.jc-preview-acc .ft { color:#94a3b8; font-size:11px; margin-top:4px; }
.jc-preview-arrow { width:30px; height:30px; border-radius:50%; border:1px solid #e2e8f0; background:#fff; color:#64748b; display:inline-flex; align-items:center; justify-content:center; font-size:16px; font-weight:700; }
.jc-kv { display:flex; justify-content:space-between; gap:10px; margin-bottom:8px; font-size:13px; color:#475569; }
.jc-kv span:last-child { color:#0f172a; font-weight:600; text-align:right; }
.jc-preview-card .jc-bd { min-height: 280px; }
.jc-settings-card .jc-bd { min-height: 260px; }
.jc-pill { display:inline-flex; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.jc-yes { background:#dcfce7; color:#15803d; }
.jc-no { background:#fef2f2; color:#b91c1c; }
@media (max-width:1100px){ .jc-grid{grid-template-columns:1fr;} }
@media (max-width:740px){ .jc-row2{grid-template-columns:1fr;} }
</style>

<main class="main-content jc-shell">
  <div class="jc-inner">
    <div class="jc-top">
      <div>
        <h1 class="jc-title">Create Journal</h1>
        <p class="jc-sub">Configure a new journal used in the accounting system.</p>
        <nav class="jc-bc" aria-label="Breadcrumb">
          <a href="../index.php">Home</a><i class="fas fa-chevron-right"></i>
          <a href="journal-configuration.php?module=<?= $jeModule ?>">Configuration</a><i class="fas fa-chevron-right"></i>
          <a href="journal-configuration.php?module=<?= $jeModule ?>">Journals</a><i class="fas fa-chevron-right"></i>
          <span>Create Journal</span>
        </nav>
      </div>
      <div class="jc-actions">
        <a class="jc-btn" href="journal-configuration.php?module=<?= $jeModule ?>"><i class="fas fa-xmark"></i> Cancel</a>
        <button type="button" class="jc-btn" id="btnDraft"><i class="far fa-floppy-disk"></i> Save as Draft</button>
        <button type="submit" form="journalCreateForm" class="jc-btn primary"><i class="far fa-floppy-disk"></i> Save Journal</button>
      </div>
    </div>

    <form id="journalCreateForm" method="post" action="#" autocomplete="off">
      <div class="jc-grid">
        <div>
          <section class="jc-card">
            <div class="jc-hd"><span class="jc-num">1</span> General Information</div>
            <div class="jc-bd">
              <div class="jc-row2">
                <div class="jc-fg"><label for="journal_name">Journal Name *</label><input class="jc-inp" id="journal_name" value="<?= htmlspecialchars($journalName) ?>" required></div>
                <div class="jc-fg"><label for="journal_code">Journal Code *</label><input class="jc-inp" id="journal_code" value="<?= htmlspecialchars($journalCode) ?>" maxlength="8" required></div>
                <div class="jc-fg"><label for="journal_type">Journal Type *</label>
                  <select class="jc-sel" id="journal_type" required>
                    <?php foreach ($journalTypes as $k => $v): ?><option value="<?= htmlspecialchars($k) ?>"<?= $k === $journalType ? ' selected' : '' ?>><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="jc-fg"><label for="currency">Currency *</label>
                  <select class="jc-sel" id="currency"><?php foreach ($currencies as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select>
                </div>
                <div class="jc-fg"><label for="seq_prefix">Sequence Prefix *</label><input class="jc-inp" id="seq_prefix" value="<?= htmlspecialchars($sequencePrefix) ?>" required></div>
                <div class="jc-fg"><label for="linked_module">Linked Module</label>
                  <select class="jc-sel" id="linked_module"><?php foreach ($linkedModules as $m): ?><option value="<?= htmlspecialchars($m) ?>"<?= $m === 'Sales' ? ' selected' : '' ?>><?= htmlspecialchars($m) ?></option><?php endforeach; ?></select>
                </div>
              </div>
              <div class="jc-fg" style="margin-top:12px;"><label for="description">Description</label><textarea class="jc-ta" id="description"><?= htmlspecialchars($description) ?></textarea></div>
            </div>
          </section>

          <section class="jc-card">
            <div class="jc-hd"><span class="jc-num">2</span> Default Accounts</div>
            <div class="jc-bd">
              <div class="jc-row2">
                <div class="jc-fg"><label for="debit_account">Default Debit Account *</label><select class="jc-sel" id="debit_account" required><?php foreach ($accounts as $a): ?><option<?= $a === '1010 - Accounts Receivable' ? ' selected' : '' ?>><?= htmlspecialchars($a) ?></option><?php endforeach; ?></select></div>
                <div class="jc-fg"><label for="credit_account">Default Credit Account *</label><select class="jc-sel" id="credit_account" required><?php foreach ($accounts as $a): ?><option<?= $a === '4001 - Sales Revenue' ? ' selected' : '' ?>><?= htmlspecialchars($a) ?></option><?php endforeach; ?></select></div>
                <div class="jc-fg"><label for="suspense_account">Suspense / Adjustment Account *</label><select class="jc-sel" id="suspense_account" required><?php foreach ($accounts as $a): ?><option<?= $a === '1999 - Suspense Account' ? ' selected' : '' ?>><?= htmlspecialchars($a) ?></option><?php endforeach; ?></select></div>
                <div class="jc-fg"><label for="bank_cash_account">Bank/Cash Account (optional)</label><select class="jc-sel" id="bank_cash_account"><option value="">Select account (optional)</option><?php foreach ($accounts as $a): ?><option><?= htmlspecialchars($a) ?></option><?php endforeach; ?></select></div>
              </div>
            </div>
          </section>

          <section class="jc-card">
            <div class="jc-hd"><span class="jc-num">3</span> Posting &amp; Behavior</div>
            <div class="jc-bd jc-row2">
              <div>
                <div class="jc-switch"><div><div class="txt">Allow Manual Entries</div></div><label class="jc-tg"><input type="checkbox" id="allow_manual" checked><span></span></label></div>
                <div class="jc-switch"><div><div class="txt">Allow Reverse</div></div><label class="jc-tg"><input type="checkbox" id="allow_reverse" checked><span></span></label></div>
                <div class="jc-switch" style="margin-bottom:0;"><div><div class="txt">Require Approval</div></div><label class="jc-tg"><input type="checkbox" id="require_approval"><span></span></label></div>
              </div>
              <div>
                <div class="jc-switch"><div><div class="txt">Allow Posting</div></div><label class="jc-tg"><input type="checkbox" id="allow_posting" checked><span></span></label></div>
                <div class="jc-switch"><div><div class="txt">Auto Numbering</div></div><label class="jc-tg"><input type="checkbox" id="auto_numbering" checked><span></span></label></div>
                <div class="jc-switch" style="margin-bottom:0;"><div><div class="txt">Affects Cash Flow</div></div><label class="jc-tg"><input type="checkbox" id="affects_cash"><span></span></label></div>
              </div>
            </div>
          </section>
        </div>

        <aside>
          <section class="jc-card jc-preview-card">
            <div class="jc-hd">Journal Preview</div>
            <div class="jc-bd">
              <div class="jc-preview-head">
                <span class="jc-preview-badge" id="pv_code">SAL</span>
                <div>
                  <h3 class="jc-preview-name" id="pv_name">Sales Journal</h3>
                  <div class="jc-preview-meta">
                    <span class="jc-chip type" id="pv_type">Sales</span>
                    <span class="jc-chip code">Code: <span id="pv_code_txt" style="margin-left:4px;">SAL</span></span>
                  </div>
                </div>
              </div>
              <div class="jc-preview-flow">
                <div class="jc-preview-acc debit"><div class="hd">Debit</div><div class="nm" id="pv_debit">1010 - Accounts Receivable</div><div class="ft">(Default)</div></div>
                <span class="jc-preview-arrow">&#8594;</span>
                <div class="jc-preview-acc credit"><div class="hd">Credit</div><div class="nm" id="pv_credit">4001 - Sales Revenue</div><div class="ft">(Default)</div></div>
              </div>
            </div>
          </section>

          <section class="jc-card jc-settings-card">
            <div class="jc-hd">Settings Summary</div>
            <div class="jc-bd">
              <div class="jc-kv"><span>Journal Type</span><span id="sm_type">Sales</span></div>
              <div class="jc-kv"><span>Currency</span><span id="sm_currency">TZS - Tanzanian Shilling</span></div>
              <div class="jc-kv"><span>Sequence Prefix</span><span id="sm_prefix">SAL</span></div>
              <div class="jc-kv"><span>Linked Module</span><span id="sm_module">Sales</span></div>
              <div class="jc-kv"><span>Auto Numbering</span><span id="sm_auto" class="jc-pill jc-yes">Yes</span></div>
              <div class="jc-kv" style="margin-bottom:0;"><span>Require Approval</span><span id="sm_approval" class="jc-pill jc-no">No</span></div>
            </div>
          </section>

          <section class="jc-card">
            <div class="jc-hd">Audit Information</div>
            <div class="jc-bd">
              <div class="jc-kv"><span>Created By</span><span><?= htmlspecialchars((string) ($_SESSION['full_name'] ?? 'System Admin')) ?></span></div>
              <div class="jc-kv"><span>Created On</span><span><?= htmlspecialchars(date('d M Y h:i A')) ?></span></div>
              <div class="jc-kv"><span>Last Modified By</span><span>-</span></div>
              <div class="jc-kv" style="margin-bottom:0;"><span>Last Modified On</span><span>-</span></div>
            </div>
          </section>
        </aside>
      </div>
    </form>
  </div>
</main>

<script>
(() => {
  const el = (id) => document.getElementById(id);
  function textSel(id) {
    const n = el(id);
    if (!n) return '';
    if (n.tagName === 'SELECT') return n.options[n.selectedIndex]?.text || '';
    return n.value || '';
  }
  function setPill(target, on) {
    const t = el(target);
    if (!t) return;
    t.textContent = on ? 'Yes' : 'No';
    t.className = 'jc-pill ' + (on ? 'jc-yes' : 'jc-no');
  }
  function syncPreview() {
    const name = (el('journal_name')?.value || 'Journal').trim();
    const code = (el('journal_code')?.value || 'JNL').trim().toUpperCase();
    const badge = code.substring(0, 3) || 'JNL';
    el('pv_name').textContent = name;
    el('pv_code').textContent = badge;
    el('pv_code_txt').textContent = code || badge;
    el('pv_type').textContent = textSel('journal_type') || '-';
    el('pv_debit').textContent = textSel('debit_account') || '-';
    el('pv_credit').textContent = textSel('credit_account') || '-';
    el('sm_type').textContent = textSel('journal_type') || '-';
    el('sm_currency').textContent = textSel('currency') || '-';
    el('sm_prefix').textContent = (el('seq_prefix')?.value || '').trim() || '-';
    el('sm_module').textContent = textSel('linked_module') || '-';
    setPill('sm_auto', !!el('auto_numbering')?.checked);
    setPill('sm_approval', !!el('require_approval')?.checked);
  }

  ['journal_name','journal_code','journal_type','currency','seq_prefix','linked_module','description','debit_account','credit_account','auto_numbering','require_approval']
    .forEach((id) => {
      const n = el(id);
      if (!n) return;
      n.addEventListener('input', syncPreview);
      n.addEventListener('change', syncPreview);
    });

  el('btnDraft')?.addEventListener('click', () => {
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'success', title: 'Draft saved', text: 'Journal draft saved in this demo page.', confirmButtonColor: '#2563eb' });
    }
  });

  el('journalCreateForm')?.addEventListener('submit', (e) => {
    e.preventDefault();
    if (typeof Swal !== 'undefined') {
      Swal.fire({ icon: 'success', title: 'Journal saved', text: 'Journal setup has been captured (UI flow complete).', confirmButtonColor: '#2563eb' })
        .then(() => { window.location.href = 'journal-configuration.php?module=<?= $jeModule ?>'; });
    } else {
      window.location.href = 'journal-configuration.php?module=<?= $jeModule ?>';
    }
  });

  syncPreview();
})();
</script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
