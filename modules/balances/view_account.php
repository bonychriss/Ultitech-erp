<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$accountId = (int) ($_GET['id'] ?? 0);
$moduleParam = (string) ($_GET['module'] ?? 'balances');
$moduleQs = $moduleParam !== '' ? '?module=' . rawurlencode($moduleParam) : '';
$accountsBackUrl = 'accounts.php' . ($moduleQs !== '' ? $moduleQs : '');

if ($accountId <= 0) {
    $_SESSION['error'] = 'Invalid account selected.';
    redirect($accountsBackUrl);
}

$account = null;
if (function_exists('balancesFetchAccountsWithLiveBalance')) {
    foreach (balancesFetchAccountsWithLiveBalance($pdo, false) as $row) {
        if ((int) ($row['id'] ?? 0) === $accountId) {
            $account = $row;
            break;
        }
    }
}

if (!$account) {
    $_SESSION['error'] = 'Account not found.';
    redirect($accountsBackUrl);
}

$nameRaw = (string) ($account['name'] ?? '');
$code = '-';
$displayName = $nameRaw !== '' ? $nameRaw : '-';
if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $nameRaw, $m)) {
    $code = trim($m[1]);
    $displayName = trim($m[2]);
}

$typeRaw = strtolower((string) ($account['type'] ?? ''));
$typeLabelMap = [
    'asset' => 'Asset',
    'liability' => 'Liability',
    'equity' => 'Equity',
    'revenue' => 'Revenue',
    'expense' => 'Expense',
    'cash' => 'Asset',
    'bank' => 'Asset',
    'mobile' => 'Asset',
];
$typeLabel = $typeLabelMap[$typeRaw] ?? ucfirst(str_replace('_', ' ', $typeRaw));
$paymentTypeLabel = ucwords(str_replace('_', ' ', $typeRaw));
$normalBalance = in_array($typeLabel, ['Liability', 'Equity', 'Revenue'], true) ? 'Credit' : 'Debit';
$status = strtolower((string) ($account['status'] ?? 'inactive'));
$currency = (string) ($account['currency'] ?? 'TZS');
$openingBalance = (float) ($account['opening_balance_safe'] ?? $account['opening_balance'] ?? 0);
$txCredits = (float) ($account['tx_credits'] ?? 0);
$txDebits = (float) ($account['tx_debits'] ?? 0);
$liveBalance = (float) ($account['live_balance'] ?? $account['current_balance'] ?? 0);

$bucket = function_exists('balancesAccountLiquidityBucket')
    ? balancesAccountLiquidityBucket($typeRaw)
    : (in_array($typeRaw, ['cash', 'cod'], true) ? 'cash' : (in_array($typeRaw, ['mobile', 'digital_wallet'], true) ? 'mobile' : 'bank'));
$iconMap = [
    'cash' => ['icon' => 'fa-money-bill-wave', 'bg' => 'bg-green-50', 'border' => 'border-green-100', 'text' => 'text-green-500'],
    'bank' => ['icon' => 'fa-university', 'bg' => 'bg-blue-50', 'border' => 'border-blue-100', 'text' => 'text-blue-500'],
    'mobile' => ['icon' => 'fa-mobile-alt', 'bg' => 'bg-purple-50', 'border' => 'border-purple-100', 'text' => 'text-purple-500'],
];
$meta = $iconMap[$bucket] ?? $iconMap['bank'];

$recentTransactions = [];
$txCount = 0;
$monthCredits = 0.0;
$monthDebits = 0.0;
$monthStart = date('Y-m-01 00:00:00');

try {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM account_transactions WHERE account_id = ?');
    $countStmt->execute([$accountId]);
    $txCount = (int) ($countStmt->fetchColumn() ?: 0);

    $monthStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credits,
            COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debits
        FROM account_transactions
        WHERE account_id = ? AND transaction_date >= ?
    ");
    $monthStmt->execute([$accountId, $monthStart]);
    $monthRow = $monthStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $monthCredits = (float) ($monthRow['credits'] ?? 0);
    $monthDebits = (float) ($monthRow['debits'] ?? 0);

    $txStmt = $pdo->prepare('
        SELECT id, transaction_date, type, amount, description, reference_type, reference_id
        FROM account_transactions
        WHERE account_id = ?
        ORDER BY transaction_date DESC, id DESC
        LIMIT 10
    ');
    $txStmt->execute([$accountId]);
    $recentTransactions = $txStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recentTransactions = [];
}

$monthNet = $monthCredits - $monthDebits;
$netMovement = $txCredits - $txDebits;
$bucketLabel = ucfirst($bucket);
$typeClass = $typeLabel === 'Asset' ? 'text-green-600' : ($typeLabel === 'Liability' ? 'text-red-600' : ($typeLabel === 'Equity' ? 'text-purple-600' : 'text-gray-700'));

$txUrl = 'transactions.php?' . http_build_query(['module' => $moduleParam, 'account_id' => $accountId]);
$editUrl = 'coa_edit.php?id=' . $accountId . ($moduleParam !== '' ? '&module=' . rawurlencode($moduleParam) : '');
$canEdit = isAdmin();
$canManage = isAdmin() || isFinance();
$isSubAccountView = (int) ($account['parent_id'] ?? 0) > 0;
$subCreateUrl = 'coa_create.php?' . http_build_query(['module' => $moduleParam, 'parent_id' => $accountId]);
$subAccountRows = [];

if (!$isSubAccountView && function_exists('balancesFetchAccountsWithLiveBalance')) {
    foreach (balancesFetchAccountsWithLiveBalance($pdo, false) as $subRow) {
        if ((int) ($subRow['parent_id'] ?? 0) !== $accountId) {
            continue;
        }
        $subNameRaw = (string) ($subRow['name'] ?? '');
        $subCode = '-';
        $subName = $subNameRaw;
        if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $subNameRaw, $subMatch)) {
            $subCode = trim($subMatch[1]);
            $subName = trim($subMatch[2]);
        }
        $subAccountRows[] = [
            'id' => (int) ($subRow['id'] ?? 0),
            'code' => $subCode,
            'name' => $subName,
            'currency' => (string) ($subRow['currency'] ?? 'TZS'),
            'status' => strtolower((string) ($subRow['status'] ?? 'inactive')),
            'balance' => (float) ($subRow['live_balance'] ?? $subRow['current_balance'] ?? 0),
        ];
    }
}

$page_title = $displayName;
include __DIR__ . '/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; font-weight: 300; }
    .main-content { background: #f8fafc; color: #000; }
    .acc-view { max-width: 1280px; margin: 0 auto; padding: 1.25rem 2rem 2.5rem; }
    .acc-hero {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 1.75rem 2rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        margin-bottom: 1.5rem;
    }
    .acc-kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    @media (min-width: 992px) {
        .acc-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .acc-kpi {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1.25rem 1.5rem;
        min-height: 132px;
        display: flex;
        flex-direction: column;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .acc-kpi-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        margin-bottom: 0.875rem;
    }
    .acc-kpi-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.1;
    }
    .acc-kpi-label {
        font-size: 0.75rem;
        font-weight: 500;
        color: #64748b;
        margin-top: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .acc-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }
    .acc-card-head {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.8125rem;
        font-weight: 600;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .acc-info-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0;
    }
    @media (min-width: 640px) {
        .acc-info-grid { grid-template-columns: 1fr 1fr; }
    }
    .acc-info-item {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #f8fafc;
    }
    @media (min-width: 640px) {
        .acc-info-item:nth-child(odd) { border-right: 1px solid #f8fafc; }
    }
    .acc-info-label {
        font-size: 0.6875rem;
        font-weight: 600;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-bottom: 0.35rem;
    }
    .acc-info-value {
        font-size: 0.9375rem;
        font-weight: 500;
        color: #0f172a;
    }
    .acc-formula-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.875rem 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.875rem;
    }
    .acc-formula-row:last-child { border-bottom: 0; }
    .acc-sub-list { padding: 0; }
    .acc-sub-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .acc-sub-item:last-child { border-bottom: 0; }
    .acc-sub-name { font-size: 0.875rem; font-weight: 500; color: #1d4ed8; }
    .acc-sub-meta { font-size: 0.6875rem; color: #94a3b8; margin-top: 0.2rem; }
    .acc-sub-balance { font-size: 0.9375rem; font-weight: 600; color: #059669; text-align: right; }
    .acc-sub-add {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.45rem 0.85rem;
        border-radius: 999px;
        background: #7c3aed;
        color: #fff;
        font-size: 0.75rem;
        font-weight: 500;
        text-decoration: none;
    }
    .acc-sub-add:hover { background: #6d28d9; color: #fff; }
    .acc-formula-total {
        margin-top: 0.5rem;
        padding: 1rem 1.25rem;
        border-radius: 12px;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        border: 1px solid #bbf7d0;
    }
    .acc-formula-total.is-negative {
        background: linear-gradient(135deg, #fef2f2 0%, #fff1f2 100%);
        border-color: #fecaca;
    }
    .table-container { border-radius: 12px; overflow: hidden; background: #fff; }
    .tx-row:hover { background-color: #f8fafc; }
    .acc-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.625rem 1.25rem;
        border-radius: 9999px;
        font-size: 0.8125rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.15s ease;
        white-space: nowrap;
    }
    .acc-btn-outline {
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #334155;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .acc-btn-outline:hover { background: #f8fafc; color: #0f172a; }
    .acc-btn-primary {
        background: #a855f7;
        border: 1px solid #a855f7;
        color: #fff;
        box-shadow: 0 1px 2px rgba(168, 85, 247, 0.25);
    }
    .acc-btn-primary:hover { background: #9333ea; color: #fff; }
    @media (max-width: 992px) {
        .acc-view { padding: 1rem; }
        .acc-hero { padding: 1.25rem; }
    }
</style>

<main class="main-content">
    <div class="bg-gray-50 min-h-screen pb-12">
        <div class="acc-view">

            <nav class="flex items-center gap-2 text-sm font-light text-gray-500 mb-5">
                <a href="index.php<?= htmlspecialchars($moduleQs, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-800">Dashboard</a>
                <i class="fas fa-chevron-right text-[10px] text-gray-300"></i>
                <a href="<?= htmlspecialchars($accountsBackUrl, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-800">Accounts</a>
                <i class="fas fa-chevron-right text-[10px] text-gray-300"></i>
                <span class="text-gray-800 font-normal truncate max-w-[200px] sm:max-w-none"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
            </nav>

            <div class="acc-hero">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                    <div class="flex items-start sm:items-center gap-5 flex-1 min-w-0">
                        <div class="w-20 h-20 rounded-full border-2 <?= $meta['border'] ?> flex-shrink-0 <?= $meta['bg'] ?> flex items-center justify-center shadow-sm">
                            <i class="fas <?= $meta['icon'] ?> <?= $meta['text'] ?> text-2xl"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <?php if ($status === 'active'): ?>
                                    <span class="px-2.5 py-1 bg-green-100 text-green-700 text-[10px] font-semibold rounded-full uppercase tracking-wide">Active</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 bg-gray-100 text-gray-600 text-[10px] font-semibold rounded-full uppercase tracking-wide">Inactive</span>
                                <?php endif; ?>
                                <span class="px-2.5 py-1 bg-blue-50 text-blue-700 text-[10px] font-semibold rounded-full uppercase tracking-wide"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="px-2.5 py-1 bg-slate-100 text-slate-600 text-[10px] font-semibold rounded-full uppercase tracking-wide"><?= htmlspecialchars($bucketLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <h1 class="text-2xl sm:text-3xl font-semibold text-slate-900 tracking-tight leading-tight"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h1>
                            <p class="text-sm font-light text-slate-500 mt-1">
                                Code <span class="font-medium text-slate-700"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></span>
                                &middot; <?= htmlspecialchars($paymentTypeLabel, ENT_QUOTES, 'UTF-8') ?>
                                &middot; <?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 lg:flex-shrink-0">
                        <a href="<?= htmlspecialchars($accountsBackUrl, ENT_QUOTES, 'UTF-8') ?>" class="acc-btn acc-btn-outline">
                            <i class="fas fa-arrow-left text-xs"></i> Back
                        </a>
                        <a href="<?= htmlspecialchars($txUrl, ENT_QUOTES, 'UTF-8') ?>" class="acc-btn acc-btn-outline">
                            <i class="fas fa-list text-xs"></i> Transactions
                        </a>
                        <?php if ($canEdit): ?>
                            <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" class="acc-btn acc-btn-primary">
                                <i class="fas fa-edit text-xs"></i> Edit
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="acc-kpi-grid">
                <div class="acc-kpi">
                    <div class="acc-kpi-icon bg-emerald-50 text-emerald-600"><i class="fas fa-wallet"></i></div>
                    <div class="acc-kpi-value <?= $liveBalance < 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= number_format($liveBalance, 2) ?></div>
                    <div class="acc-kpi-label">Live balance (<?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?>)</div>
                </div>
                <div class="acc-kpi">
                    <div class="acc-kpi-icon bg-blue-50 text-blue-600"><i class="fas fa-piggy-bank"></i></div>
                    <div class="acc-kpi-value"><?= number_format($openingBalance, 2) ?></div>
                    <div class="acc-kpi-label">Opening balance</div>
                </div>
                <div class="acc-kpi">
                    <div class="acc-kpi-icon bg-green-50 text-green-600"><i class="fas fa-arrow-down"></i></div>
                    <div class="acc-kpi-value text-green-600">+<?= number_format($monthCredits, 2) ?></div>
                    <div class="acc-kpi-label">Credits this month</div>
                </div>
                <div class="acc-kpi">
                    <div class="acc-kpi-icon bg-red-50 text-red-600"><i class="fas fa-arrow-up"></i></div>
                    <div class="acc-kpi-value text-red-600">-<?= number_format($monthDebits, 2) ?></div>
                    <div class="acc-kpi-label">Debits this month</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mb-6">
                <div class="lg:col-span-3 acc-card">
                    <div class="acc-card-head">Account information</div>
                    <div class="acc-info-grid">
                        <div class="acc-info-item">
                            <div class="acc-info-label">Account name</div>
                            <div class="acc-info-value"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="acc-info-item">
                            <div class="acc-info-label">Account code</div>
                            <div class="acc-info-value"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="acc-info-item">
                            <div class="acc-info-label">Payment type</div>
                            <div class="acc-info-value"><?= htmlspecialchars($paymentTypeLabel, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="acc-info-item">
                            <div class="acc-info-label">Chart type</div>
                            <div class="acc-info-value <?= $typeClass ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="acc-info-item">
                            <div class="acc-info-label">Normal balance</div>
                            <div class="acc-info-value"><?= htmlspecialchars($normalBalance, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="acc-info-item">
                            <div class="acc-info-label">Total transactions</div>
                            <div class="acc-info-value"><?= number_format($txCount) ?></div>
                        </div>
                        <?php if (!empty($account['created_at'])): ?>
                        <div class="acc-info-item">
                            <div class="acc-info-label">Created</div>
                            <div class="acc-info-value"><?= htmlspecialchars(date('M j, Y', strtotime((string) $account['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($account['updated_at'])): ?>
                        <div class="acc-info-item">
                            <div class="acc-info-label">Last updated</div>
                            <div class="acc-info-value"><?= htmlspecialchars(date('M j, Y', strtotime((string) $account['updated_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="lg:col-span-2 acc-card">
                    <div class="acc-card-head">Balance calculation</div>
                    <div class="p-5">
                        <div class="acc-formula-row">
                            <span class="text-slate-500 font-light">Opening balance</span>
                            <span class="font-medium text-slate-900"><?= number_format($openingBalance, 2) ?></span>
                        </div>
                        <div class="acc-formula-row">
                            <span class="text-slate-500 font-light">+ Total credits</span>
                            <span class="font-medium text-green-600">+<?= number_format($txCredits, 2) ?></span>
                        </div>
                        <div class="acc-formula-row">
                            <span class="text-slate-500 font-light">&minus; Total debits</span>
                            <span class="font-medium text-red-600">&minus;<?= number_format($txDebits, 2) ?></span>
                        </div>
                        <div class="acc-formula-total <?= $liveBalance < 0 ? 'is-negative' : '' ?>">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-slate-700">Live balance</span>
                                <span class="text-xl font-bold <?= $liveBalance < 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= number_format($liveBalance, 2) ?> <span class="text-sm font-medium"><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?></span></span>
                            </div>
                            <p class="text-xs text-slate-500 mt-2 font-light">Opening + credits &minus; debits</p>
                        </div>
                        <div class="mt-4 pt-4 border-t border-slate-100">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-500 font-light">Net movement (all time)</span>
                                <span class="font-medium <?= $netMovement >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= $netMovement >= 0 ? '+' : '' ?><?= number_format($netMovement, 2) ?></span>
                            </div>
                            <div class="flex items-center justify-between text-sm mt-2">
                                <span class="text-slate-500 font-light">Net this month</span>
                                <span class="font-medium <?= $monthNet >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= $monthNet >= 0 ? '+' : '' ?><?= number_format($monthNet, 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isSubAccountView): ?>
            <div class="acc-card mb-6">
                <div class="acc-card-head flex items-center justify-between gap-3">
                    <span>Sub-accounts</span>
                    <?php if ($canManage): ?>
                        <a href="<?= htmlspecialchars($subCreateUrl, ENT_QUOTES, 'UTF-8') ?>" class="acc-sub-add">
                            <i class="fas fa-plus text-[10px]"></i> Add sub-account
                        </a>
                    <?php endif; ?>
                </div>
                <?php if (empty($subAccountRows)): ?>
                    <div class="px-6 py-10 text-center">
                        <p class="text-sm font-light text-slate-500 mb-3">No sub-accounts yet. Break this account into smaller ledgers when needed.</p>
                        <?php if ($canManage): ?>
                            <a href="<?= htmlspecialchars($subCreateUrl, ENT_QUOTES, 'UTF-8') ?>" class="acc-sub-add">Add first sub-account</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="acc-sub-list">
                        <?php foreach ($subAccountRows as $subAccount):
                            $subViewUrl = 'view_account.php?' . http_build_query(['module' => $moduleParam, 'id' => (int) $subAccount['id']]);
                        ?>
                            <a href="<?= htmlspecialchars($subViewUrl, ENT_QUOTES, 'UTF-8') ?>" class="acc-sub-item hover:bg-slate-50 transition-colors">
                                <div>
                                    <div class="acc-sub-name"><?= htmlspecialchars($subAccount['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="acc-sub-meta">code: <?= htmlspecialchars($subAccount['code'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars(strtolower($subAccount['currency']), ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($subAccount['status'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="acc-sub-balance"><?= number_format($subAccount['balance'], 2) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="acc-card">
                <div class="acc-card-head flex items-center justify-between">
                    <span>Recent transactions</span>
                    <span class="text-xs font-normal normal-case tracking-normal text-slate-400"><?= number_format($txCount) ?> total</span>
                </div>
                <?php if (empty($recentTransactions)): ?>
                    <div class="px-6 py-16 text-center">
                        <div class="w-14 h-14 mx-auto mb-4 rounded-full bg-slate-50 flex items-center justify-center text-slate-400">
                            <i class="fas fa-receipt text-lg"></i>
                        </div>
                        <p class="text-base font-normal text-slate-700 mb-1">No transactions yet</p>
                        <p class="text-sm font-light text-slate-500">Activity on this account will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container border-0 shadow-none rounded-none">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100">
                                    <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider">Description</th>
                                    <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider">Reference</th>
                                    <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider text-right pr-8">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($recentTransactions as $tx):
                                    $isCredit = ($tx['type'] ?? '') === 'credit';
                                    $ref = trim((string) ($tx['reference_type'] ?? ''));
                                    if ($ref !== '' && !empty($tx['reference_id'])) {
                                        $ref .= ' #' . (int) $tx['reference_id'];
                                    }
                                    if ($ref === '') {
                                        $ref = '�';
                                    }
                                ?>
                                <tr class="tx-row transition-colors font-light text-black">
                                    <td class="px-6 py-3.5 text-xs whitespace-nowrap"><?= htmlspecialchars(date('M j, Y', strtotime((string) ($tx['transaction_date'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-3.5">
                                        <?php if ($isCredit): ?>
                                            <span class="px-2 py-0.5 bg-green-100 text-green-700 text-[9px] font-medium rounded uppercase">In</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-red-100 text-red-700 text-[9px] font-medium rounded uppercase">Out</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3.5 text-xs max-w-xs truncate" title="<?= htmlspecialchars((string) ($tx['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($tx['description'] ?? '�'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-3.5 text-xs text-slate-500"><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-3.5 text-right pr-8">
                                        <span class="text-sm font-normal <?= $isCredit ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= $isCredit ? '+' : '-' ?><?= number_format((float) ($tx['amount'] ?? 0), 2) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($txCount > count($recentTransactions)): ?>
                    <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between bg-slate-50/50">
                        <span class="text-xs font-light text-slate-500">Showing latest <?= count($recentTransactions) ?> of <?= number_format($txCount) ?></span>
                        <a href="<?= htmlspecialchars($txUrl, ENT_QUOTES, 'UTF-8') ?>" class="acc-btn acc-btn-outline text-xs py-2">
                            View all transactions <i class="fas fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
