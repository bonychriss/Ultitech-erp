<?php
require_once __DIR__ . '/../balances/config/database.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    $_SESSION['error'] = 'Access denied.';
    redirect('/select-module.php');
}

$page_title = 'Accounting';
$u = static function (string $path): string {
    return function_exists('company_url') ? company_url($path) : app_url('/' . ltrim($path, '/'));
};
$expensesIconUrl = app_url('/modules/accounting/assets/expenses-icon.png');

include __DIR__ . '/includes/header.php';
?>

<style>
    .employee-header { display:none !important; }
    .acct-shell { background:#f8fafc; padding:14px 14px 22px; }
    .acct-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .acct-title { margin:0; font-size:34px; font-weight:800; color:#0f172a; line-height:1.1; }
    .acct-sub { margin:6px 0 0; font-size:14px; color:#64748b; }
    .acct-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
    .acct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px; text-decoration:none; color:#0f172a; display:block; transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease; }
    .acct-card:hover { border-color:#bfdbfe; box-shadow:0 10px 24px rgba(15,23,42,.08); transform: translateY(-1px); }
    .acct-ico { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; margin-bottom:10px; }
    .acct-ico--img { background:transparent; padding:0; overflow:visible; }
    .acct-ico--img img { width:48px; height:48px; object-fit:contain; display:block; }
    .c1 { background:#eff6ff; color:#2563eb; }
    .c2 { background:#ecfeff; color:#0891b2; }
    .c3 { background:#f0fdf4; color:#16a34a; }
    .c4 { background:#fff7ed; color:#d97706; }
    .c5 { background:#fdf2f8; color:#db2777; }
    .c6 { background:#f5f3ff; color:#7c3aed; }
    .acct-h { font-size:16px; font-weight:800; margin:0 0 4px; }
    .acct-p { margin:0; font-size:13px; color:#64748b; line-height:1.45; }
    @media (max-width:1100px){ .acct-grid{grid-template-columns:repeat(2,minmax(0,1fr));} }
    @media (max-width:720px){ .acct-grid{grid-template-columns:1fr;} }
</style>

<main class="main-content acct-shell">
    <div class="acct-top">
        <div>
            <h1 class="acct-title">Accounting</h1>
            <p class="acct-sub">Choose a section to configure or work with balances, revenue, journals, and reports.</p>
        </div>
    </div>

    <section class="acct-grid">
        <a class="acct-card" href="<?= htmlspecialchars($u('modules/balances/index') . '?module=balances') ?>">
            <div class="acct-ico c2"><i class="fas fa-scale-balanced"></i></div>
            <div class="acct-h">Balances</div>
            <p class="acct-p">Liquidity dashboard, accounts, transfers, transactions.</p>
        </a>

        <a class="acct-card" href="<?= htmlspecialchars($u('revenue_entries') . '?module=revenue') ?>">
            <div class="acct-ico c4"><i class="fas fa-coins"></i></div>
            <div class="acct-h">Revenue</div>
            <p class="acct-p">Record income, manage revenue entries and credit notes.</p>
        </a>

        <a class="acct-card" href="<?= htmlspecialchars($u('modules/expenses/index') . '?module=expenses') ?>">
            <div class="acct-ico acct-ico--img" aria-hidden="true">
                <img src="<?= htmlspecialchars($expensesIconUrl) ?>" alt="">
            </div>
            <div class="acct-h">Expenses</div>
            <p class="acct-p">Record and manage expense vouchers and payees.</p>
        </a>

        <a class="acct-card" href="<?= htmlspecialchars($u('accounting/journal-entries') . '?module=accounting') ?>">
            <div class="acct-ico c1"><i class="fas fa-book"></i></div>
            <div class="acct-h">Journal Entries</div>
            <p class="acct-p">Create and review journal entries.</p>
        </a>

        <a class="acct-card" href="<?= htmlspecialchars($u('accounting/settings') . '?module=accounting') ?>">
            <div class="acct-ico c6"><i class="fas fa-sliders"></i></div>
            <div class="acct-h">Accounting Settings</div>
            <p class="acct-p">Default sales revenue account and other GL posting defaults.</p>
        </a>

        <a class="acct-card" href="<?= htmlspecialchars($u('accounting/journal-configuration') . '?module=accounting') ?>">
            <div class="acct-ico c6"><i class="fas fa-gear"></i></div>
            <div class="acct-h">Journal Configuration</div>
            <p class="acct-p">Configure journal settings and posting rules.</p>
        </a>

        <a class="acct-card" href="<?= htmlspecialchars($u('accounting/trial-balance') . '?module=accounting') ?>">
            <div class="acct-ico c3"><i class="fas fa-table"></i></div>
            <div class="acct-h">Trial Balance</div>
            <p class="acct-p">Generate trial balance for a period.</p>
        </a>

        <a class="acct-card" href="<?= htmlspecialchars($u('accounting/reconciliation') . '?module=accounting') ?>">
            <div class="acct-ico c2"><i class="fas fa-link"></i></div>
            <div class="acct-h">Reconciliation</div>
            <p class="acct-p">Match bank statements with system transactions.</p>
        </a>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
