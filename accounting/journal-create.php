<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_journals.php';
requireLogin();
global $pdo;

$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
$page_title = 'Create Journal';

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

$journalTypes = accounting_journal_types();
$currencies = ['TZS' => 'TZS - Tanzanian Shilling', 'USD' => 'USD - US Dollar', 'KES' => 'KES - Kenyan Shilling'];
$linkedModules = ['Sales', 'Purchasing', 'Balances', 'Payroll', 'General'];
$journals = accounting_journals_get($pdo);
$editId = trim((string) ($_GET['id'] ?? ''));
$journal = null;
foreach ($journals as $configuredJournal) {
    if ($editId !== '' && (string) $configuredJournal['id'] === $editId) {
        $journal = $configuredJournal;
        break;
    }
}

$formError = '';
$formData = $journal ?? [
    'name' => 'General Journal',
    'code' => 'GEN',
    'type' => 'general',
    'currency' => 'TZS',
    'posting_sequence' => 'GEN-',
    'linked_module' => 'General',
    'description' => 'Manual adjustments, accruals, and entries that do not belong to a specialized journal.',
    'status' => 'active',
    'is_default' => false,
    'allow_manual' => true,
    'allow_posting' => true,
    'allow_reverse' => true,
    'require_approval' => false,
    'auto_numbering' => true,
    'affects_cash' => false,
];

if ($editId !== '' && $journal === null) {
    $formError = 'The requested journal could not be found.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = trim((string) ($_POST['id'] ?? ''));
    $formData = $_POST;
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $formError = 'Your session expired. Reload the page and try again.';
    } else {
        $result = accounting_journal_save($pdo, $_POST, (string) ($_POST['save_mode'] ?? 'active'), $editId !== '' ? $editId : null);
        if ($result['ok']) {
            $redirect = 'journal-configuration.php?module=' . rawurlencode((string) ($_GET['module'] ?? 'balances')) . '&saved=1';
            header('Location: ' . $redirect);
            exit;
        }
        $formError = $result['error'];
    }
}

$journalName = (string) ($formData['name'] ?? '');
$journalCode = (string) ($formData['code'] ?? '');
$journalType = (string) ($formData['type'] ?? 'general');
$sequencePrefix = (string) ($formData['posting_sequence'] ?? 'GEN-');
$description = (string) ($formData['description'] ?? '');
$selectedCurrency = (string) ($formData['currency'] ?? 'TZS');
$selectedModule = (string) ($formData['linked_module'] ?? 'General');
$journalStatus = (string) ($formData['status'] ?? 'active');
$isDefault = !empty($formData['is_default']);
$isEdit = $editId !== '';
$e = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header { display:none !important; }
.main-content.jc-shell { margin-top:0 !important; padding:14px 0 28px !important; background:#f9fafb; font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif; }
.jc-inner { max-width:1100px; margin:0 auto; padding:0 20px; }
.jc-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
.jc-title { margin:0; font-size:28px; font-weight:750; color:#0f172a; line-height:1.2; }
.jc-sub { margin:5px 0 0; color:#64748b; font-size:14px; }
.jc-bc { margin-top:8px; display:flex; gap:7px; flex-wrap:wrap; align-items:center; color:#64748b; font-size:12px; }
.jc-bc a { color:#2563eb; text-decoration:none; font-weight:600; }
.jc-panel { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 2px rgba(15,23,42,.04); overflow:hidden; }
.jc-row { display:grid; grid-template-columns:minmax(180px, 0.8fr) minmax(0, 1.2fr); align-items:center; gap:18px; padding:15px 20px; border-bottom:1px solid #eef2f7; }
.jc-row-head { display:flex; align-items:center; gap:11px; color:#334155; }
.jc-row-head i { width:18px; color:#64748b; text-align:center; }
.jc-row-head strong { display:block; color:#0f172a; font-size:14px; font-weight:650; }
.jc-row-head small { display:block; color:#64748b; font-size:12px; margin-top:3px; }
.jc-control { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
.jc-control.single { grid-template-columns:minmax(0,1fr); }
.jc-field label { display:block; margin-bottom:5px; color:#64748b; font-size:12px; font-weight:600; }
.jc-input,.jc-select,.jc-textarea { width:100%; min-height:40px; border:1px solid #dbe2ea; border-radius:7px; padding:8px 10px; color:#0f172a; background:#fff; font:inherit; font-size:14px; }
.jc-textarea { min-height:72px; resize:vertical; }
.jc-input:focus,.jc-select:focus,.jc-textarea:focus { outline:none; border-color:#93c5fd; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.jc-checks { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px 16px; }
.jc-check { display:flex; align-items:center; gap:8px; color:#334155; font-size:13px; }
.jc-check input { width:16px; height:16px; accent-color:#2563eb; }
.jc-help { padding:12px 20px; color:#64748b; font-size:12px; line-height:1.5; background:#f8fafc; }
.jc-help a { color:#2563eb; font-weight:600; }
.jc-error { margin-bottom:14px; padding:11px 14px; border:1px solid #fecaca; border-radius:8px; background:#fef2f2; color:#b91c1c; }
.jc-footer { display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; background:#f8fafc; border-top:1px solid #eef2f7; }
.jc-btn { border:1px solid #dbe2ea; background:#fff; color:#334155; border-radius:7px; padding:9px 14px; font-weight:600; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:7px; cursor:pointer; }
.jc-btn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
@media (max-width:700px) { .jc-row { grid-template-columns:1fr; gap:10px; padding:14px; } .jc-checks { grid-template-columns:repeat(2,minmax(0,1fr)); } .jc-inner { padding:0 12px; } }
@media (max-width:440px) { .jc-control { grid-template-columns:1fr; } .jc-checks { grid-template-columns:1fr; } }
</style>

<main class="main-content jc-shell">
  <div class="jc-inner">
    <div class="jc-top">
      <div>
        <h1 class="jc-title"><?= $isEdit ? 'Edit Journal' : 'New Journal' ?></h1>
        <p class="jc-sub">Set up the journal profile and posting preferences.</p>
        <nav class="jc-bc" aria-label="Breadcrumb">
          <a href="../index.php">Home</a><i class="fas fa-chevron-right"></i>
          <a href="journal-configuration.php?module=<?= $jeModule ?>">Configuration</a><i class="fas fa-chevron-right"></i>
          <a href="journal-configuration.php?module=<?= $jeModule ?>">Journals</a><i class="fas fa-chevron-right"></i>
          <span><?= $isEdit ? 'Edit Journal' : 'New Journal' ?></span>
        </nav>
      </div>
    </div>

    <?php if ($formError !== ''): ?>
      <div class="jc-error" role="alert"><?= $e($formError) ?></div>
    <?php endif; ?>

    <form id="journalCreateForm" method="post" action="journal-create.php?module=<?= $jeModule ?><?= $isEdit ? '&id=' . rawurlencode($editId) : '' ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= $e($editId) ?>">
      <div class="jc-panel">
        <div class="jc-row">
          <div class="jc-row-head"><i class="fas fa-book"></i><div><strong>Journal</strong><small>Name and short code</small></div></div>
          <div class="jc-control">
            <div class="jc-field"><label for="journal_name">Journal name</label><input class="jc-input" id="journal_name" name="name" value="<?= $e($journalName) ?>" maxlength="100" required></div>
            <div class="jc-field"><label for="journal_code">Short code</label><input class="jc-input" id="journal_code" name="code" value="<?= $e($journalCode) ?>" maxlength="8" required></div>
          </div>
        </div>
        <div class="jc-row">
          <div class="jc-row-head"><i class="fas fa-layer-group"></i><div><strong>Journal type</strong><small>Defines how this profile is used</small></div></div>
          <div class="jc-control">
            <div class="jc-field"><label for="journal_type">Type</label><select class="jc-select" id="journal_type" name="type" required><?php foreach ($journalTypes as $k => $v): ?><option value="<?= $e($k) ?>"<?= $k === $journalType ? ' selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?></select></div>
            <div class="jc-field"><label for="linked_module">Linked module</label><select class="jc-select" id="linked_module" name="linked_module"><?php foreach ($linkedModules as $m): ?><option value="<?= $e($m) ?>"<?= $m === $selectedModule ? ' selected' : '' ?>><?= $e($m) ?></option><?php endforeach; ?></select></div>
          </div>
        </div>
        <div class="jc-row">
          <div class="jc-row-head"><i class="fas fa-hashtag"></i><div><strong>Numbering</strong><small>Entry prefix and currency</small></div></div>
          <div class="jc-control">
            <div class="jc-field"><label for="seq_prefix">Numbering prefix</label><input class="jc-input" id="seq_prefix" name="prefix" value="<?= $e($sequencePrefix) ?>" maxlength="12" required></div>
            <div class="jc-field"><label for="currency">Currency</label><select class="jc-select" id="currency" name="currency"><?php foreach ($currencies as $code => $label): ?><option value="<?= $e($code) ?>"<?= $code === $selectedCurrency ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></div>
          </div>
        </div>
        <div class="jc-row">
          <div class="jc-row-head"><i class="fas fa-sliders"></i><div><strong>Status and default</strong><small>Only one active default journal</small></div></div>
          <div class="jc-control">
            <div class="jc-field"><label for="status">Status</label><select class="jc-select" id="status" name="status"><option value="active"<?= $journalStatus === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $journalStatus === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></div>
            <label class="jc-check"><input type="checkbox" name="is_default" value="1"<?= $isDefault ? ' checked' : '' ?>> Default journal</label>
          </div>
        </div>
        <div class="jc-row">
          <div class="jc-row-head"><i class="fas fa-gear"></i><div><strong>Posting behavior</strong><small>Preferences for this journal</small></div></div>
          <div class="jc-checks">
            <label class="jc-check"><input type="checkbox" name="allow_manual" value="1"<?= !empty($formData['allow_manual']) ? ' checked' : '' ?>> Manual entries</label>
            <label class="jc-check"><input type="checkbox" name="allow_posting" value="1"<?= !empty($formData['allow_posting']) ? ' checked' : '' ?>> Allow posting</label>
            <label class="jc-check"><input type="checkbox" name="allow_reverse" value="1"<?= !empty($formData['allow_reverse']) ? ' checked' : '' ?>> Allow reversals</label>
            <label class="jc-check"><input type="checkbox" name="require_approval" value="1"<?= !empty($formData['require_approval']) ? ' checked' : '' ?>> Require approval</label>
            <label class="jc-check"><input type="checkbox" name="auto_numbering" value="1"<?= !empty($formData['auto_numbering']) ? ' checked' : '' ?>> Auto-number entries</label>
            <label class="jc-check"><input type="checkbox" name="affects_cash" value="1"<?= !empty($formData['affects_cash']) ? ' checked' : '' ?>> Affects cash flow</label>
          </div>
        </div>
        <div class="jc-row">
          <div class="jc-row-head"><i class="fas fa-align-left"></i><div><strong>Description</strong><small>Optional note</small></div></div>
          <div class="jc-control single"><div class="jc-field"><label for="description">Description</label><textarea class="jc-textarea" id="description" name="description" maxlength="1000"><?= $e($description) ?></textarea></div></div>
        </div>
        <div class="jc-help">These profile options do not yet change GL posting. Manage account mappings in <a href="settings.php?module=<?= $jeModule ?>">Accounting Settings</a>.</div>
        <div class="jc-footer">
          <button type="reset" class="jc-btn"><i class="fas fa-rotate-left"></i> Reset</button>
          <a class="jc-btn" href="journal-configuration.php?module=<?= $jeModule ?>">Cancel</a>
          <button type="submit" class="jc-btn primary"><i class="far fa-floppy-disk"></i> Save changes</button>
        </div>
      </div>
    </form>
  </div>
</main>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
