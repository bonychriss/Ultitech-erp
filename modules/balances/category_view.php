<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    $_SESSION['error'] = 'Access denied.';
    redirect('accounts.php');
}

$categoryId = (int) ($_GET['id'] ?? 0);
if ($categoryId <= 0) {
    $_SESSION['error'] = 'Invalid category selected.';
    redirect('category_create.php');
}

$category = null;
try {
    $stmt = $pdo->prepare("
        SELECT c.*,
               p.name AS parent_name,
               p.code AS parent_code
        FROM financial_account_categories c
        LEFT JOIN financial_account_categories p ON p.id = c.parent_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $category = null;
}

if (!$category) {
    $_SESSION['error'] = 'Category not found.';
    redirect('category_create.php');
}

$typeMap = [
    'asset' => ['asset', 'cash', 'bank', 'mobile'],
    'liability' => ['liability'],
    'equity' => ['equity'],
    'revenue' => ['revenue'],
    'expense' => ['expense'],
];
$catTypeNorm = strtolower((string) ($category['account_type'] ?? 'asset'));
$allowedTypes = $typeMap[$catTypeNorm] ?? [$catTypeNorm];

$accounts = [];
try {
    $inClause = implode(',', array_fill(0, count($allowedTypes), '?'));
    $sql = "
        SELECT
            fa.id, fa.name, fa.type, fa.status, fa.currency,
            COALESCE(fa.opening_balance, 0) AS opening_balance,
            COALESCE(tx.total_credits, 0) AS tx_credits,
            COALESCE(tx.total_debits, 0) AS tx_debits,
            (COALESCE(fa.opening_balance, 0) + COALESCE(tx.total_credits, 0) - COALESCE(tx.total_debits, 0)) AS live_balance
        FROM financial_accounts fa
        LEFT JOIN (
            SELECT
                account_id,
                SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS total_credits,
                SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS total_debits
            FROM account_transactions
            GROUP BY account_id
        ) tx ON tx.account_id = fa.id
        WHERE LOWER(fa.type) IN ($inClause)
        ORDER BY fa.name ASC
        LIMIT 12
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($allowedTypes);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $accounts = [];
}

$childCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM financial_account_categories WHERE parent_id = ?");
    $stmt->execute([$categoryId]);
    $childCount = (int) ($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $childCount = 0;
}

$accountsCount = count($accounts);
$activeAccountsCount = 0;
foreach ($accounts as $a) {
    if (strtolower((string) ($a['status'] ?? '')) === 'active') {
        $activeAccountsCount++;
    }
}

$page_title = 'Account Category Details';
include __DIR__ . '/includes/header.php';
?>
<style>
    .employee-header { display:none !important; }
    .main-content.catv-shell { margin-top:0 !important; padding:10px 0 20px !important; background:#f8fafc; font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif; color:#0f172a; }
    .catv-wrap { padding:0 12px; }
    .catv-top { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; margin-bottom:10px; }
    .catv-row { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; }
    .catv-bc { display:flex; gap:8px; align-items:center; flex-wrap:wrap; font-size:13px; color:#64748b; margin-bottom:6px; }
    .catv-bc a { color:#2563eb; text-decoration:none; font-weight:700; }
    .catv-title { margin:0; font-size:34px; font-weight:800; color:#0f172a; line-height:1.1; }
    .catv-sub { margin:5px 0 0; font-size:14px; color:#64748b; }
    .catv-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .catv-btn { border:1px solid #dbe2ea; border-radius:8px; background:#fff; color:#0f172a; text-decoration:none; font-size:13px; font-weight:700; padding:9px 13px; display:inline-flex; align-items:center; gap:6px; }
    .catv-btn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
    .catv-grid { display:grid; grid-template-columns:1.7fr 1fr; gap:10px; align-items:start; }
    .catv-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:10px; }
    .catv-sec { margin:0 0 8px; font-size:20px; font-weight:800; color:#0f172a; }
    .catv-main { display:grid; grid-template-columns:84px 1fr; gap:12px; align-items:start; }
    .catv-icon { width:70px; height:70px; border-radius:50%; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:28px; }
    .catv-meta { display:grid; grid-template-columns:200px 1fr; gap:7px 14px; font-size:14px; }
    .catv-meta b { color:#0f172a; font-size:15px; font-weight:800; }
    .pill { display:inline-flex; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:800; }
    .pill-active { background:#dcfce7; color:#15803d; }
    .pill-type { background:#dbeafe; color:#1d4ed8; }
    .catv-two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .catv-kv { display:grid; grid-template-columns:1fr auto; gap:8px; border:1px solid #eef2f7; border-radius:8px; padding:10px 12px; font-size:14px; color:#64748b; }
    .catv-kv b { color:#0f172a; font-size:15px; }
    .catv-table-wrap { overflow:auto; border:1px solid #eef2f7; border-radius:8px; }
    .catv-table { width:100%; min-width:760px; border-collapse:collapse; font-size:14px; }
    .catv-table th, .catv-table td { border-bottom:1px solid #eef2f7; padding:10px 11px; vertical-align:middle; }
    .catv-table th { background:#fafafa; text-transform:uppercase; font-size:12px; color:#64748b; font-weight:800; }
    .catv-notes { font-size:14px; color:#334155; line-height:1.7; }
    .num { text-align:right; font-variant-numeric:tabular-nums; }
    @media (max-width:1100px) { .catv-grid { grid-template-columns:1fr; } .catv-two { grid-template-columns:1fr; } }
</style>

<main class="main-content catv-shell">
    <div class="catv-wrap">
        <div class="catv-top">
            <div class="catv-row">
                <div>
                    <div class="catv-bc">
                        <a href="accounts.php">Chart of Accounts</a>
                        <i class="fas fa-chevron-right"></i>
                        <a href="category_create.php">Account Categories</a>
                        <i class="fas fa-chevron-right"></i>
                        <span><?php echo htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <h1 class="catv-title">Account Category Details</h1>
                    <p class="catv-sub">View and manage this account category in your chart of accounts.</p>
                </div>
                <div class="catv-actions">
                    <a class="catv-btn" href="category_edit.php?id=<?php echo (int) $categoryId; ?>"><i class="fas fa-pen"></i> Edit Category</a>
                    <a class="catv-btn" href="category_create.php"><i class="fas fa-ellipsis"></i> Actions</a>
                    <a class="catv-btn primary" href="coa_create.php?module=balances"><i class="fas fa-circle-plus"></i> Create Account</a>
                </div>
            </div>
        </div>

        <div class="catv-grid">
            <section>
                <div class="catv-card">
                    <div class="catv-main">
                        <div class="catv-icon"><i class="far fa-folder-open"></i></div>
                        <div class="catv-meta">
                            <span>Category Code</span><b><?php echo htmlspecialchars((string) ($category['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></b>
                            <span>Category Name</span><b><?php echo htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></b>
                            <span>Account Type</span><b><span class="pill pill-type"><?php echo htmlspecialchars((string) ($category['account_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></b>
                            <span>Parent Category</span><b><?php echo htmlspecialchars((string) (($category['parent_code'] ?? '') !== '' ? (($category['parent_code'] ?? '') . ' - ' . ($category['parent_name'] ?? '')) : 'None'), ENT_QUOTES, 'UTF-8'); ?></b>
                            <span>Reporting Group</span><b><?php echo htmlspecialchars((string) ($category['reporting_group'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></b>
                            <span>Financial Statement</span><b><?php echo htmlspecialchars((string) ($category['financial_statement'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></b>
                            <span>Display Order</span><b><?php echo (int) ($category['display_order'] ?? 0); ?></b>
                            <span>Description</span><b><?php echo htmlspecialchars((string) ($category['description'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></b>
                        </div>
                    </div>
                </div>

                <div class="catv-card">
                    <h2 class="catv-sec">Hierarchy &amp; Rules</h2>
                    <div class="catv-two">
                        <div class="catv-kv"><span>Is Header Category</span><b><?php echo htmlspecialchars((string) ($category['is_header'] ?? 'No'), ENT_QUOTES, 'UTF-8'); ?></b></div>
                        <div class="catv-kv"><span>Financial Statement Section</span><b><?php echo htmlspecialchars((string) ($category['reporting_group'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></b></div>
                        <div class="catv-kv"><span>Allow Child Categories</span><b><?php echo htmlspecialchars((string) ($category['allow_child'] ?? 'No'), ENT_QUOTES, 'UTF-8'); ?></b></div>
                        <div class="catv-kv"><span>Default Currency</span><b>TZS</b></div>
                    </div>
                </div>

                <div class="catv-card">
                    <h2 class="catv-sec">Accounts Under This Category</h2>
                    <div class="catv-table-wrap">
                        <table class="catv-table">
                            <thead>
                                <tr>
                                    <th>Account Name</th>
                                    <th>Account Type</th>
                                    <th>Status</th>
                                    <th class="num">Balance (TZS)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$accounts): ?>
                                    <tr><td colspan="4">No linked accounts found for this category type.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($accounts as $a): ?>
                                        <?php $active = strtolower((string) ($a['status'] ?? '')) === 'active'; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string) ($a['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><span class="pill pill-type"><?php echo htmlspecialchars(ucfirst((string) ($a['type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td><span class="pill <?php echo $active ? 'pill-active' : ''; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span></td>
                                            <td class="num"><?php echo number_format((float) ($a['live_balance'] ?? 0), 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <aside>
                <div class="catv-card">
                    <h2 class="catv-sec">Category Usage</h2>
                    <div class="catv-two">
                        <div class="catv-kv"><span>Accounts Using Category</span><b><?php echo number_format($accountsCount); ?></b></div>
                        <div class="catv-kv"><span>Child Categories</span><b><?php echo number_format($childCount); ?></b></div>
                        <div class="catv-kv"><span>Related Reporting Group</span><b><?php echo htmlspecialchars((string) ($category['reporting_group'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></b></div>
                        <div class="catv-kv"><span>Status</span><b><span class="pill <?php echo strtolower((string) ($category['status'] ?? '')) === 'active' ? 'pill-active' : ''; ?>"><?php echo htmlspecialchars((string) ($category['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></b></div>
                    </div>
                </div>

                <div class="catv-card">
                    <h2 class="catv-sec">Notes</h2>
                    <div class="catv-notes">
                        <?php echo nl2br(htmlspecialchars((string) ($category['notes'] ?? 'No notes available.'), ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                </div>

                <div class="catv-card">
                    <h2 class="catv-sec">Audit Information</h2>
                    <div class="catv-kv"><span>Created By</span><b><?php echo htmlspecialchars((string) ($_SESSION['full_name'] ?? $_SESSION['name'] ?? 'System Administrator'), ENT_QUOTES, 'UTF-8'); ?></b></div>
                    <div class="catv-kv"><span>Created At</span><b><?php echo htmlspecialchars(date('d M Y h:i A', strtotime((string) ($category['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></b></div>
                    <div class="catv-kv"><span>Last Modified On</span><b><?php echo htmlspecialchars(date('d M Y h:i A', strtotime((string) ($category['updated_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></b></div>
                </div>
            </aside>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
