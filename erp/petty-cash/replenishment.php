<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}

global $pdo;
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$can_manage = pettyCashCanManage();

$moduleQs = array_filter([
    'module' => $_GET['module'] ?? 'petty_cash',
    'company_slug' => $_GET['company_slug'] ?? null,
], static fn($v) => $v !== null && $v !== '');

$buildUrl = static function (array $extra = []) use ($moduleQs): string {
    return 'replenishment.php?' . http_build_query(array_merge($moduleQs, $extra));
};

$pettyAccounts = pettyCashListFinancialAccounts('petty');
$sourceAccounts = pettyCashListFinancialAccounts('source');
$hasFinancialAccounts = pettyCashHasFinancialAccounts() && !empty($sourceAccounts);

$pettyBalanceTotal = 0.0;
foreach ($pettyAccounts as $acc) {
    $pettyBalanceTotal += (float) ($acc['live_balance'] ?? $acc['current_balance'] ?? 0);
}

$error = '';
$redirectSuccess = static function (string $key) use ($buildUrl) {
    header('Location: ' . $buildUrl(['success' => $key]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'request') {
        if (!$can_manage) {
            $error = 'Only Finance or Admin can create top-up requests.';
        } elseif (!$hasFinancialAccounts) {
            $error = 'Financial accounts are not configured. Set up accounts in Balances first.';
        } else {
            $pettyAccountId = (int) ($_POST['petty_cash_account_id'] ?? 0);
            $sourceAccountId = (int) ($_POST['source_account_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($pettyAccountId <= 0 || $sourceAccountId <= 0) {
                $error = 'Select both petty cash and source accounts.';
            } elseif ($pettyAccountId === $sourceAccountId) {
                $error = 'Source and petty cash accounts must be different.';
            } elseif ($amount <= 0) {
                $error = 'Amount must be greater than zero.';
            } elseif ($description === '') {
                $error = 'Please enter a reason or description.';
            } else {
                $newId = createPettyCashReplenishment([
                    'custodian_id' => $user_id,
                    'petty_cash_account_id' => $pettyAccountId,
                    'source_account_id' => $sourceAccountId,
                    'amount' => $amount,
                    'description' => $description,
                    'created_by' => $user_id,
                ]);
                if ($newId) {
                    $redirectSuccess('submitted');
                }
                $error = 'Failed to submit top-up request.';
            }
        }
    }
}

$flash_success = '';
$successKey = isset($_GET['success']) ? (string) $_GET['success'] : '';
if ($successKey !== '') {
    $messages = [
        'submitted' => 'Top-up request submitted. No balances were changed until approval.',
        'approved' => 'Top-up approved. Funds transferred and accounting entries posted.',
        'rejected' => 'Top-up rejected. No balances were changed.',
        'cancelled' => 'Top-up cancelled.',
    ];
    $flash_success = $messages[$successKey] ?? '';
} else {
    $flash_success = '';
}

$page_title = 'Petty cash top-up';
$listUrl = 'replenishments/index.php?' . http_build_query($moduleQs);
$overviewHref = 'index.php?' . http_build_query($moduleQs);

$pc_lottie_form_id = 'topupRequestForm';
$pc_lottie_redirect = $listUrl;
$pc_lottie_show_success = ($successKey === 'submitted');
$pc_lottie_submit_message = 'Submitting top-up request...';
$pc_lottie_success_message = 'Top-up submitted for approval!';
$pc_lottie_okay_label = 'View all requests';
$pc_lottie_view_label = 'New request';
$pc_lottie_view_url = $buildUrl();

$formatBalance = static function (array $acc): string {
    $bal = (float) ($acc['live_balance'] ?? $acc['current_balance'] ?? 0);

    return number_format($bal, 2);
};

$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$formValues = [
    'petty_cash_account_id' => (int) ($_POST['petty_cash_account_id'] ?? 0),
    'source_account_id' => (int) ($_POST['source_account_id'] ?? 0),
    'amount' => trim((string) ($_POST['amount'] ?? '')),
    'description' => trim((string) ($_POST['description'] ?? '')),
];

$selectChevron = "background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;";

include __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { corePlugins: { preflight: false } };</script>
<style>
    .pc-topup-shell {
        font-family: 'Inter', system-ui, sans-serif;
        background: #f8fafc;
        color: #1e293b;
        padding: 2rem;
        min-height: 50vh;
    }
    .pc-topup-shell .editor-shell { max-width: 1140px; margin: 0 auto; }
    .pc-topup-shell .editor-topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e5e7eb;
    }
    .pc-topup-shell .editor-layout {
        display: grid;
        grid-template-columns: 180px minmax(0, 1fr);
        gap: 2rem;
        align-items: start;
    }
    .pc-topup-shell .section-nav { position: sticky; top: 96px; align-self: start; }
    .pc-topup-shell .section-nav ul { list-style: none; margin: 0; padding: 0; }
    .pc-topup-shell .section-nav li + li { margin-top: 0.5rem; }
    .pc-topup-shell .section-nav a {
        display: block;
        padding: 0.45rem 0.75rem;
        border-radius: 8px;
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .pc-topup-shell .section-nav a:hover { background: #eff6ff; color: #2563eb; }
    .pc-topup-shell .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
    .pc-topup-shell .editor-section {
        padding-bottom: 2rem;
        margin-bottom: 2rem;
        border-bottom: 1px solid #e5e7eb;
    }
    .pc-topup-shell .section-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
    .pc-topup-shell .section-subtitle { font-size: 12px; color: #94a3b8; margin: 0 0 1.25rem; }
    .pc-topup-shell .form-row {
        display: grid;
        grid-template-columns: 210px 1fr;
        align-items: start;
        margin-bottom: 24px;
    }
    .pc-topup-shell .form-row:last-child { margin-bottom: 0; }
    .pc-topup-shell .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 12px; }
    .pc-topup-shell .form-label span { color: #ef4444; margin-left: 2px; }
    .pc-topup-shell .form-input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 14px;
        color: #1e293b;
        outline: none;
        transition: all 0.2s;
        background: #fff;
    }
    .pc-topup-shell .form-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }
    .pc-topup-shell .form-input-price { color: #16a34a !important; font-weight: 600; }
    .pc-topup-shell .form-input-price::placeholder { color: #86efac; }
    .pc-topup-shell .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; }
    .pc-topup-shell .summary-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border-radius: 10px;
        background: #f3e8ff;
        color: #6d28d9;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 1.5rem;
    }
    .pc-topup-shell .btn-save {
        background: #7c3aed;
        color: #fff;
        padding: 14px 48px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 15px;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
    }
    .pc-topup-shell .btn-save:hover { background: #6d28d9; }
    .pc-topup-shell .btn-cancel {
        border: 1px solid #d8b4fe;
        color: #7c3aed;
        background: #faf5ff;
        padding: 14px 32px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 15px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }
    .pc-topup-shell .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
    .pc-topup-shell .alert-banner {
        margin-bottom: 1.5rem;
        border-radius: 12px;
        padding: 12px 16px;
        font-size: 14px;
        font-weight: 600;
    }
    .pc-topup-shell .alert-banner.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
    .pc-topup-shell .alert-banner.error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
    .pc-topup-shell .info-card {
        max-width: 640px;
        padding: 1.5rem;
        border-radius: 14px;
        border: 1px solid #e5e7eb;
        background: #fff;
    }
    .pc-topup-shell .pc-topup-actions {
        display: flex;
        justify-content: flex-start;
        align-items: stretch;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 0.5rem;
        margin-bottom: 2rem;
    }
    .pc-topup-shell .pc-topup-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 0;
        overflow: hidden;
    }
    .pc-topup-shell .pc-topup-card .editor-main {
        padding: 1.25rem 1.5rem 1.5rem;
    }
    @media (max-width: 992px) {
        .pc-topup-shell { padding: 1rem; }
        .pc-topup-shell .editor-layout { grid-template-columns: 1fr; gap: 1rem; }
        .pc-topup-shell .section-nav { position: static; }
        .pc-topup-shell .section-nav ul {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 4px;
            scrollbar-width: none;
        }
        .pc-topup-shell .section-nav ul::-webkit-scrollbar { display: none; }
        .pc-topup-shell .section-nav li + li { margin-top: 0; }
        .pc-topup-shell .section-nav a { white-space: nowrap; flex-shrink: 0; }
        .pc-topup-shell .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 20px; }
        .pc-topup-shell .form-label { padding-top: 0; font-size: 13px; }
    }

    @media (max-width: 768px) {
        html body .main-content.pc-topup-shell,
        html body.dashboard .main-content.pc-topup-shell {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            padding: 0 !important;
            box-sizing: border-box;
            background: #f1f5f9 !important;
        }
        .pc-topup-shell {
            padding: 12px 16px calc(80px + env(safe-area-inset-bottom, 0px));
            min-height: auto;
            box-sizing: border-box;
            overflow-x: hidden;
        }
        .pc-topup-shell .editor-shell {
            max-width: 100%;
            width: 100%;
            padding-left: 2px;
            padding-right: 2px;
            box-sizing: border-box;
        }
        .pc-topup-shell .editor-topbar {
            flex-direction: column-reverse;
            align-items: flex-start;
            gap: 0.35rem;
            margin-bottom: 0.75rem;
            padding: 0 4px 0.65rem 8px;
            border-bottom: none;
            width: 100%;
            box-sizing: border-box;
        }
        .pc-topup-shell .editor-topbar h1 {
            font-family: 'Inter', system-ui, sans-serif !important;
            font-size: 1.25rem !important;
            font-weight: 700 !important;
            line-height: 1.3;
            color: #0f172a;
            margin: 0;
            padding-left: 2px;
        }
        .pc-topup-shell .editor-topbar a {
            font-size: 13px;
            padding: 4px 0 4px 2px;
            min-height: auto;
        }
        .pc-topup-shell .alert-banner {
            font-size: 13px;
            padding: 10px 12px;
            margin-bottom: 0.75rem;
            line-height: 1.45;
        }
        .pc-topup-shell .summary-pill {
            display: block;
            width: 100%;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.5;
            padding: 10px 12px;
            margin-bottom: 0.75rem;
            box-sizing: border-box;
            border-radius: 10px;
        }
        .pc-topup-shell .summary-pill .summary-total {
            display: block;
            margin-top: 6px;
            font-weight: 700;
            color: #5b21b6;
        }
        .pc-topup-shell .editor-layout {
            display: block;
        }
        .pc-topup-shell .section-nav {
            display: none;
        }
        .pc-topup-shell .pc-topup-card {
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }
        .pc-topup-shell .pc-topup-card .editor-main {
            padding: 1rem;
        }
        .pc-topup-shell .editor-section {
            padding-bottom: 1rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
            scroll-margin-top: 12px;
        }
        .pc-topup-shell .editor-section:last-of-type {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .pc-topup-shell .section-title {
            font-size: 1rem;
            margin-bottom: 2px;
        }
        .pc-topup-shell .section-subtitle {
            font-size: 12px;
            margin-bottom: 0.85rem;
        }
        .pc-topup-shell .form-row {
            margin-bottom: 1.1rem;
            gap: 6px;
        }
        .pc-topup-shell .form-label {
            font-size: 13px;
            font-weight: 600;
            padding-top: 0;
            margin-bottom: 2px;
        }
        .pc-topup-shell .form-row > div {
            min-width: 0;
        }
        .pc-topup-shell select.form-input,
        .pc-topup-shell .form-input {
            font-size: 16px;
            min-height: 48px;
            padding: 12px 14px;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .pc-topup-shell textarea.form-input {
            min-height: 100px !important;
            font-size: 16px;
        }
        .pc-topup-shell .help-text {
            font-size: 11px;
            margin-top: 4px;
        }
        .pc-topup-shell .pc-topup-actions {
            position: static;
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 1.25rem 0 0;
            padding: 1rem 0 0;
            border-top: 1px solid #e5e7eb;
            background: transparent;
            box-shadow: none;
            backdrop-filter: none;
        }
        .pc-topup-shell .pc-topup-actions .btn-save {
            order: 1;
            width: 100%;
            min-height: 50px;
            padding: 14px 20px;
            font-size: 16px;
            border-radius: 12px;
        }
        .pc-topup-shell .pc-topup-actions .btn-cancel {
            order: 2;
            width: 100%;
            min-height: 48px;
            padding: 12px 20px;
            justify-content: center;
            font-size: 15px;
            border-radius: 12px;
        }
        .pc-topup-shell .info-card {
            max-width: 100%;
            padding: 1.25rem;
        }
        .pc-topup-shell .info-card .btn-cancel {
            width: 100%;
            justify-content: center;
        }
    }

    @media (min-width: 769px) {
        .pc-topup-shell .pc-topup-actions {
            flex-direction: row;
        }
        .pc-topup-shell .pc-topup-actions .btn-cancel { order: 1; }
        .pc-topup-shell .pc-topup-actions .btn-save { order: 2; }
        .pc-topup-shell .pc-topup-card .editor-main {
            padding: 1.5rem;
        }
    }

    @media (max-width: 480px) {
        .pc-topup-shell { padding-left: 14px; padding-right: 14px; }
        .pc-topup-shell .editor-topbar { padding-left: 6px; }
    }
</style>

<main class="main-content pc-topup-shell">
    <div class="editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800 m-0">New top-up request</h1>
            </div>
            <a href="<?= $esc($listUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2" style="text-decoration:none;">
                <i class="fas fa-arrow-left text-xs"></i> Back to all requests
            </a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert-banner error"><?= $esc($error) ?></div>
        <?php endif; ?>

        <?php if ($can_manage && $hasFinancialAccounts): ?>
        <div class="summary-pill">
            <span><i class="fas fa-info-circle"></i> Pending until approved. Source balance checked on approval.</span>
            <span class="summary-total">Petty cash total: TZS <?= number_format($pettyBalanceTotal, 2) ?></span>
        </div>

        <form method="POST" id="topupRequestForm">
            <input type="hidden" name="action" value="request">
            <div class="editor-layout">
                <aside class="section-nav" aria-label="Form sections">
                    <ul>
                        <li><a href="#transfer-accounts" class="is-active">Accounts</a></li>
                        <li><a href="#amount-details">Amount &amp; reason</a></li>
                    </ul>
                </aside>

                <div class="pc-topup-card">
                <div class="editor-main">
                    <section class="editor-section" id="transfer-accounts">
                        <h2 class="section-title">Transfer accounts</h2>
                        <p class="section-subtitle">Choose where funds are deducted from and which petty cash account receives them.</p>

                        <div class="form-row">
                            <label class="form-label" for="petty_cash_account_id">Petty cash account <span>*</span></label>
                            <div>
                                <select name="petty_cash_account_id" id="petty_cash_account_id" required class="form-input appearance-none pr-10" style="<?= $selectChevron ?>">
                                    <option value="">Select account to receive funds</option>
                                    <?php foreach ($pettyAccounts as $acc): ?>
                                        <?php $aid = (int) $acc['id']; ?>
                                        <option value="<?= $aid ?>" <?= $formValues['petty_cash_account_id'] === $aid ? 'selected' : '' ?>>
                                            <?= $esc($acc['name']) ?> — TZS <?= $formatBalance($acc) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($pettyAccounts)): ?>
                                    <p class="help-text" style="color:#b45309;">No petty/cash accounts found. Add a cash account named &ldquo;Petty Cash&rdquo; in Balances.</p>
                                <?php else: ?>
                                    <p class="help-text">The petty cash float account that will receive the top-up.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="source_account_id">Source account <span>*</span></label>
                            <div>
                                <select name="source_account_id" id="source_account_id" required class="form-input appearance-none pr-10" style="<?= $selectChevron ?>">
                                    <option value="">Select account to deduct from</option>
                                    <?php foreach ($sourceAccounts as $acc): ?>
                                        <?php $aid = (int) $acc['id']; ?>
                                        <option value="<?= $aid ?>" <?= $formValues['source_account_id'] === $aid ? 'selected' : '' ?>>
                                            <?= $esc($acc['name']) ?> &middot; <?= $esc(ucfirst((string) ($acc['type'] ?? ''))) ?> — TZS <?= $formatBalance($acc) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="help-text">Bank, mobile, or cash account funds will be moved from on approval.</p>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="amount-details">
                        <h2 class="section-title">Amount &amp; reason</h2>
                        <p class="section-subtitle">Enter the top-up amount and a clear description for approvers.</p>

                        <div class="form-row">
                            <label class="form-label" for="amount">Amount (TZS) <span>*</span></label>
                            <div>
                                <input
                                    type="number"
                                    name="amount"
                                    id="amount"
                                    required
                                    min="0.01"
                                    step="0.01"
                                    class="form-input form-input-price"
                                    placeholder="0.00"
                                    value="<?= $esc($formValues['amount']) ?>"
                                >
                                <p class="help-text">Must be greater than zero.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="description">Reason / description <span>*</span></label>
                            <div>
                                <textarea
                                    name="description"
                                    id="description"
                                    rows="4"
                                    required
                                    class="form-input"
                                    style="min-height:120px;resize:vertical;"
                                    placeholder="e.g. Monthly petty cash float for office expenses"
                                ><?= $esc($formValues['description']) ?></textarea>
                                <p class="help-text">Explain why this top-up is needed. Shown on the request list and approval screen.</p>
                            </div>
                        </div>
                    </section>

                    <div class="pc-topup-actions">
                        <button type="submit" class="btn-save">Submit for approval</button>
                        <a href="<?= $esc($listUrl) ?>" class="btn-cancel">Cancel</a>
                    </div>
                </div>
                </div>
            </div>
        </form>

        <?php elseif ($can_manage): ?>
        <div class="info-card">
            <h2 class="section-title">Setup required</h2>
            <p class="section-subtitle">Financial accounts are required before you can create top-up requests.</p>
            <p class="help-text" style="margin-top:1rem;color:#64748b;">Configure accounts under <strong>Balances</strong>, then return here to submit a request.</p>
            <a href="<?= $esc($listUrl) ?>" class="btn-cancel" style="margin-top:1.25rem;">View all requests</a>
        </div>
        <?php else: ?>
        <div class="info-card">
            <h2 class="section-title">Finance only</h2>
            <p class="section-subtitle">Top-up requests are created by Finance or Admin.</p>
            <p class="help-text" style="margin-top:1rem;color:#64748b;">Contact your administrator if you need a petty cash top-up.</p>
            <a href="<?= $esc($overviewHref) ?>" class="btn-cancel" style="margin-top:1.25rem;">Back to dashboard</a>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php if ($can_manage && $hasFinancialAccounts): ?>
<?php include __DIR__ . '/includes/lottie-success-overlay.php'; ?>
<?php endif; ?>

<script>
(function () {
    var links = document.querySelectorAll('.pc-topup-shell .section-nav a[href^="#"]');
    links.forEach(function (link) {
        link.addEventListener('click', function (e) {
            var id = link.getAttribute('href');
            var el = id ? document.querySelector(id) : null;
            if (!el) return;
            e.preventDefault();
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            links.forEach(function (l) { l.classList.remove('is-active'); });
            link.classList.add('is-active');
        });
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
