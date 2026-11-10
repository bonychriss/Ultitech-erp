<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_journals.php';
requireLogin();

$page_title = 'Journal Configuration';

global $pdo;

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

$jeModuleRaw = isset($_GET['module']) ? (string) $_GET['module'] : 'balances';
$jeModuleEsc = htmlspecialchars($jeModuleRaw, ENT_QUOTES, 'UTF-8');
$flash = '';
$flashError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Your session expired. Reload the page and try again.';
        $flashError = true;
    } elseif (($_POST['action'] ?? '') === 'toggle_status') {
        $result = accounting_journal_toggle_status($pdo, trim((string) ($_POST['id'] ?? '')));
        $flash = $result['ok'] ? 'Journal status updated.' : $result['error'];
        $flashError = !$result['ok'];
    } else {
        $flash = 'The requested journal action is not valid.';
        $flashError = true;
    }

    if ($flash !== '') {
        $_SESSION['journal_config_flash'] = ['message' => $flash, 'error' => $flashError];
        header('Location: journal-configuration.php?module=' . rawurlencode($jeModuleRaw));
        exit;
    }
}

if (isset($_SESSION['journal_config_flash']) && is_array($_SESSION['journal_config_flash'])) {
    $flash = (string) ($_SESSION['journal_config_flash']['message'] ?? '');
    $flashError = !empty($_SESSION['journal_config_flash']['error']);
    unset($_SESSION['journal_config_flash']);
}
if ($flash === '' && isset($_GET['saved'])) {
    $flash = 'Journal settings saved.';
}

$journals = accounting_journals_get($pdo);
$journalTypes = accounting_journal_types();
foreach ($journals as &$journal) {
    $journal['type_label'] = $journalTypes[$journal['type'] ?? ''] ?? 'General';
    $journal['is_default'] = !empty($journal['is_default']);
    $journal['debit_display'] = 'â€”';
    $journal['credit_display'] = 'â€”';
}
unset($journal);

$inactiveCount = count(array_filter($journals, function ($j) {
    return ($j['status'] ?? '') !== 'active';
}));

$company_display = $_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company');

$jcEmbed = isset($_GET['embed']);
if ($jcEmbed) {
    $hideSidebar = true;
}

include __DIR__ . '/../modules/balances/includes/header.php';
?>
<script src="https://cdn.tailwindcss.com"></script>
<style>
    .stock-dash {
        max-width: 1600px;
        margin: 0 auto;
        padding: 1.25rem 1.5rem 2rem;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }
    .stock-dash .dash-header h1 {
        font-size: 1.75rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: -0.02em;
        margin: 0;
    }
    .stock-dash .dash-header p { color: #64748b; font-size: 0.875rem; margin: 0; }
    .stock-dash .dash-header--ledger {
        display: grid;
        grid-template-columns: minmax(0, auto) minmax(180px, 1fr) auto;
        align-items: center;
        gap: 1rem 1.25rem;
        margin-bottom: 1.5rem;
    }
    .stock-dash .dash-header--ledger .dash-header-title { min-width: 0; }
    .stock-dash .dash-header--ledger .dash-header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: flex-end;
    }
    .stock-dash .dash-alert {
        margin-top: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: #fff7ed;
        color: #c2410c;
        padding: 0.375rem 0.75rem;
        border-radius: 0.5rem;
        border: 1px solid #ffedd5;
        font-size: 0.8125rem;
        font-weight: 600;
    }
    @media (max-width: 992px) {
        .stock-dash { padding: 1rem; }
        .stock-dash .dash-header--ledger { grid-template-columns: 1fr; }
        .stock-dash .dash-header--ledger .dash-header-actions { justify-self: start; }
    }
    .stock-dash .btn-outline {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        background: #fff;
        color: #334155;
        font-size: 0.8125rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.15s;
        cursor: pointer;
        font-family: inherit;
    }
    .stock-dash .btn-outline:hover { background: #f8fafc; color: #334155; }
    .stock-dash .btn-blue {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        background: #2563eb;
        color: #fff;
        font-size: 0.8125rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid #2563eb;
        cursor: pointer;
        font-family: inherit;
    }
    .stock-dash .btn-blue:hover { background: #1d4ed8; color: #fff; }
    .stock-dash .btn-danger {
        background: #fff;
        border: 1px solid #fecaca;
        color: #b91c1c;
    }
    .stock-dash .btn-danger:hover { background: #fef2f2; color: #b91c1c; }
    .stock-dash .kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    @media (min-width: 992px) {
        .stock-dash .kpi-grid--four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .stock-dash .kpi-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1.25rem 1.5rem;
        min-height: 148px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        display: flex;
        flex-direction: column;
    }
    .stock-dash .kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        margin-bottom: 1rem;
    }
    .stock-dash .kpi-icon--total { background: #dbeafe; color: #2563eb; }
    .stock-dash .kpi-icon--active { background: #dcfce7; color: #16a34a; }
    .stock-dash .kpi-icon--inactive { background: #fee2e2; color: #dc2626; }
    .stock-dash .kpi-icon--default { background: #ede9fe; color: #7c3aed; }
    .stock-dash .kpi-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
    .stock-dash .kpi-label { font-size: 0.8rem; font-weight: 500; color: #64748b; margin-top: 0.35rem; }
    .stock-dash .kpi-sub { font-size: 0.75rem; color: #94a3b8; margin-top: auto; padding-top: 0.75rem; }
    .stock-dash .dash-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        margin-bottom: 1.25rem;
    }
    .stock-dash .dash-card-h {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .stock-dash .dash-card-h h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }
    .stock-dash .dash-card-b { padding: 1rem 1.25rem; }
    .stock-dash .tx-search-form {
        width: 100%;
        min-width: 0;
        max-width: 520px;
        justify-self: center;
    }
    .stock-dash .tx-search-wrap {
        display: flex;
        align-items: stretch;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: 0 0 0 0.875rem;
        min-height: 44px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .stock-dash .tx-search-wrap:focus-within {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
    }
    .stock-dash .tx-search-wrap > i {
        color: #94a3b8;
        display: flex;
        align-items: center;
        margin-right: 0.5rem;
    }
    .stock-dash .tx-search-input {
        flex: 1;
        min-width: 0;
        border: none;
        outline: none;
        background: transparent;
        font-family: inherit;
        font-size: 0.875rem;
        color: #0f172a;
        padding: 0.625rem 0.75rem 0.625rem 0;
    }
    .stock-dash .tx-search-input::placeholder { color: #94a3b8; }
    .stock-dash .ledger-table { width: 100%; margin: 0; font-size: 0.8125rem; min-width: 680px; }
    .stock-dash .ledger-table thead th {
        background: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
        padding: 0.65rem 0.75rem;
    }
    .stock-dash .ledger-table td {
        padding: 0.65rem 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        vertical-align: middle;
    }
    .stock-dash .ledger-table tbody tr { cursor: pointer; transition: background 0.12s; }
    .stock-dash .ledger-table tbody tr:hover { background: #f8fafc; }
    .stock-dash .ledger-table tbody tr.selected {
        background: #eff6ff;
        outline: 2px solid #93c5fd;
        outline-offset: -2px;
    }
    .stock-dash .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .stock-dash .status-pill {
        border-radius: 999px;
        font-size: 0.6875rem;
        font-weight: 700;
        padding: 0.2rem 0.55rem;
        display: inline-flex;
    }
    .stock-dash .status-active { background: #dcfce7; color: #15803d; }
    .stock-dash .status-inactive { background: #fee2e2; color: #b91c1c; }
    .stock-dash .default-yes { color: #2563eb; font-weight: 700; font-size: 0.8125rem; }
    .stock-dash .default-no { color: #64748b; font-weight: 600; font-size: 0.8125rem; }
    .stock-dash .action-btn {
        width: 28px;
        height: 28px;
        border: 1px solid #e2e8f0;
        border-radius: 0.375rem;
        background: #fff;
        color: #2563eb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }
    .stock-dash .action-btn:hover { background: #eff6ff; }
    .stock-dash .jconf-detail-hd {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        background: #f8fafc;
    }
    .stock-dash .jconf-detail-hd h2 {
        margin: 0;
        font-size: 1.125rem;
        font-weight: 700;
        color: #0f172a;
    }
    .stock-dash .jconf-detail-hd .meta { font-size: 0.8125rem; color: #64748b; margin-top: 0.25rem; }
    .stock-dash .jconf-tabs {
        display: flex;
        gap: 0.25rem;
        flex-wrap: wrap;
        padding: 0 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        background: #fff;
    }
    .stock-dash .jconf-tabs button {
        border: 0;
        background: transparent;
        padding: 0.75rem 0.875rem;
        font-size: 0.8125rem;
        font-weight: 600;
        color: #64748b;
        border-bottom: 2px solid transparent;
        margin-bottom: -1px;
        cursor: pointer;
        font-family: inherit;
    }
    .stock-dash .jconf-tabs button:hover { color: #2563eb; }
    .stock-dash .jconf-tabs button.active { color: #2563eb; border-bottom-color: #2563eb; }
    .stock-dash .jconf-tab-panel { display: none; padding: 1.25rem; }
    .stock-dash .jconf-tab-panel.active { display: block; }
    .stock-dash .jconf-grid3 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1.25rem 1.5rem;
    }
    .stock-dash .jconf-fg label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: #64748b;
        margin-bottom: 0.35rem;
    }
    .stock-dash .jconf-fg .val { font-size: 0.875rem; color: #0f172a; font-weight: 500; }
    .stock-dash .jconf-check {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8125rem;
        color: #0f172a;
        margin-bottom: 0.65rem;
    }
    .stock-dash .jconf-check i { color: #16a34a; }
    .stock-dash .jconf-detail-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    @media (max-width: 1100px) {
        .stock-dash .jconf-grid3 { grid-template-columns: 1fr; }
    }
</style>

<?php if ($jcEmbed): ?>
<style>
    html, body { background: transparent !important; }
    .employee-header, #erp-auto-breadcrumb, .erp-auto-breadcrumb { display: none !important; }
    .layout-main-wrapper { min-height: 0 !important; }
    body .main-content { padding: 0 !important; margin: 0 !important; background: transparent !important; }
    .stock-dash { max-width: none !important; padding: 0.4rem 0.2rem 1rem !important; }
    .stock-dash .dash-header h1, .stock-dash .dash-header-title > p { display: none !important; }
    .stock-dash .dash-header--ledger { grid-template-columns: minmax(160px, 1fr) auto !important; gap: 0.6rem 1rem; margin-bottom: 0.9rem; }
    .stock-dash .dash-header--ledger .dash-header-title:empty, .stock-dash .dash-header--ledger .dash-header-title { grid-column: 1 / -1; order: 3; margin: 0; }
    .stock-dash .dash-header--ledger .dash-header-title { display: contents; }
    .stock-dash .dash-alert { margin: 0; grid-column: 1 / -1; order: 3; width: fit-content; }
    .stock-dash .dash-header p { font-size: 0.72rem !important; }
    .stock-dash .dash-alert { font-size: 0.72rem; padding: 0.2rem 0.55rem; margin-top: 0.4rem; background: #fffbeb; border-color: #fde68a; color: #b45309; }
    .stock-dash .tx-search-wrap, .stock-dash .tx-search-input { font-size: 0.78rem; }
    .stock-dash .btn-outline { font-size: 0.74rem; padding: 0.4rem 0.8rem; }
    .stock-dash .btn-blue { background: #059669 !important; border-color: #059669 !important; font-size: 0.74rem; padding: 0.4rem 0.8rem; }
    .stock-dash .btn-blue:hover { background: #065f46 !important; border-color: #065f46 !important; }
    .stock-dash .kpi-card { padding: 0.7rem 0.9rem; }
    .stock-dash .kpi-value { font-size: 1.15rem !important; }
    .stock-dash .kpi-label { font-size: 0.74rem !important; }
    .stock-dash .kpi-sub { font-size: 0.68rem !important; }
    .stock-dash .dash-card-h h3 { font-size: 0.85rem !important; }
    .stock-dash .dash-card-h h3 .text-blue-600 { color: #059669 !important; }
    .stock-dash table { font-size: 0.76rem; }
    .stock-dash .dash-alert[role="note"] { display: none !important; }
    .stock-dash .dash-header--ledger { margin-bottom: 0.5rem !important; }
    .stock-dash .dash-card { margin-bottom: 0.5rem !important; }
    .stock-dash .dash-card-h { padding: 0.45rem 0.8rem !important; }
    .stock-dash .ledger-table { min-width: 0 !important; }
    .stock-dash table th, .stock-dash table td { padding: 0.4rem 0.6rem !important; }
    @media (max-width: 992px) { .stock-dash .dash-header--ledger { grid-template-columns: 1fr; } }
</style>
<?php endif; ?>

<main class="main-content">
<div class="stock-dash">
    <div class="dash-header dash-header--ledger">
        <div class="dash-header-title">
            <h1>Journal Configuration</h1>
            <p class="mt-1"><?= date('l, d M Y') ?> Â· <?= htmlspecialchars($company_display) ?></p>
            <p class="mt-1">Journal profiles are saved for this company. GL account mappings remain under Accounting Settings.</p>
            <?php if ($inactiveCount > 0): ?>
            <div class="dash-alert">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                <?= (int) $inactiveCount ?> inactive journal<?= $inactiveCount === 1 ? '' : 's' ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="tx-search-form">
            <div class="tx-search-wrap">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" id="jconfSearch" class="tx-search-input" placeholder="Search journalsâ€¦" autocomplete="off" aria-label="Search journals">
            </div>
        </div>
        <div class="dash-header-actions">
            <a href="journal-entries.php?module=<?= $jeModuleEsc ?>" class="btn-outline"><i class="fas fa-book"></i> Journal Entries</a>
            <button type="button" class="btn-blue" id="jconfNewJournal"><i class="fas fa-plus"></i> New Journal</button>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="dash-alert" role="status" style="<?= $flashError ? 'background:#fef2f2;border-color:#fecaca;color:#b91c1c;' : 'background:#f0fdf4;border-color:#bbf7d0;color:#15803d;' ?>">
            <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="dash-alert" role="note" style="margin-bottom:1rem;">
        These profiles and their behavior flags are saved for reference but are not yet enforced by GL posting.
        They do not override chart-of-accounts mappings; set GL accounts in <a href="settings.php?module=<?= $jeModuleEsc ?>">Accounting Settings</a>.
    </div>

    <div class="dash-card">
        <div class="dash-card-h">
            <h3><i class="fas fa-list text-blue-600 me-1"></i> Journals</h3>
        </div>
        <div class="table-wrap">
            <table class="table ledger-table mb-0" id="jconfTable">
                <thead>
                    <tr>
                        <th>Journal</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Default</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="jconfTbody">
                    <?php foreach ($journals as $j): ?>
                        <tr data-id="<?= htmlspecialchars($j['id']) ?>" tabindex="0" role="button">
                            <td><?= htmlspecialchars($j['name']) ?></td>
                            <td><strong><?= htmlspecialchars($j['code']) ?></strong></td>
                            <td><?= htmlspecialchars($j['type_label']) ?></td>
                            <td>
                                <?php if (($j['status'] ?? '') === 'active'): ?>
                                    <span class="status-pill status-active">Active</span>
                                <?php else: ?>
                                    <span class="status-pill status-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= !empty($j['is_default']) ? 'default-yes' : 'default-no' ?>">
                                    <?= !empty($j['is_default']) ? 'Yes' : 'No' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="#" class="action-btn jconf-edit" data-id="<?= htmlspecialchars($j['id']) ?>" title="Edit" aria-label="Edit" onclick="event.stopPropagation();"><i class="fas fa-pen"></i></a>
                                                                <?php $jActive = ($j['status'] ?? '') === 'active'; $jLocked = $jActive && !empty($j['is_default']); ?>
                                                                <form method="post" class="jconf-status-form" style="display:inline;" onclick="event.stopPropagation();">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="action" value="toggle_status">
                                                                    <input type="hidden" name="id" value="<?= htmlspecialchars($j['id']) ?>">
                                                                    <button type="submit" class="action-btn" style="background:none;border:0;<?= $jLocked ? 'opacity:.35;cursor:not-allowed;' : '' ?>" <?= $jLocked ? 'disabled' : '' ?> title="<?= $jLocked ? 'Set another active journal as default first.' : ($jActive ? 'Deactivate' : 'Activate') ?>" aria-label="<?= $jActive ? 'Deactivate' : 'Activate' ?>"><i class="fas fa-power-off"></i></button>
                                                                </form>
                                                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>

<script>
(() => {
    const rows = <?= json_encode($journals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const byId = Object.fromEntries(rows.map(r => [r.id, r]));

    function selectRow(tr) {
        document.querySelectorAll('#jconfTbody tr').forEach(t => t.classList.remove('selected'));
        tr.classList.add('selected');
        const id = tr.getAttribute('data-id');
        activeJournalId = id;
    }

    document.querySelectorAll('#jconfTbody tr').forEach(tr => {
        tr.addEventListener('click', () => selectRow(tr));
        tr.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectRow(tr); }
        });
    });

    const search = document.getElementById('jconfSearch');
    if (search) {
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            document.querySelectorAll('#jconfTbody tr').forEach(tr => {
                const t = tr.textContent.toLowerCase();
                tr.style.display = !q || t.includes(q) ? '' : 'none';
            });
        });
    }

    let activeJournalId = null;
    function openCreatePage(journalId) {
        const u = new URL('journal-create.php', window.location.href);
        u.searchParams.set('module', '<?= $jeModuleEsc ?>');
        if (journalId && byId[journalId]) {
            const r = byId[journalId];
            u.searchParams.set('id', r.id);
        }
        window.location.href = u.toString();
    }

    document.getElementById('jconfNewJournal')?.addEventListener('click', () => {
        openCreatePage(null);
    });
    document.querySelectorAll('.jconf-status-form').forEach(f => f.addEventListener('submit', (event) => {
        if (!window.confirm('Change the active status of this journal profile?')) {
            event.preventDefault();
        }
    }));
    document.querySelectorAll('.jconf-edit').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            const id = a.getAttribute('data-id');
            const tr = document.querySelector(`#jconfTbody tr[data-id="${id}"]`);
            if (tr) selectRow(tr);
            openCreatePage(id);
        });
    });

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            const el = document.getElementById('jconfSearch');
            if (el) {
                e.preventDefault();
                el.focus();
                el.select();
            }
        }
    });

    const first = document.querySelector('#jconfTbody tr');
    if (first) selectRow(first);
})();
</script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
