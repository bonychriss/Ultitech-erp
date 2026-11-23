<?php
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/balances-guard.php';

$moduleParam = balances_guard_module_param();
balances_bootstrap_or_error('institutions.php', [
    'back_url' => balances_guard_accounts_url($moduleParam),
    'retry_url' => balances_guard_current_url(),
]);

requireLogin();

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

$accountsUrl = balances_guard_accounts_url($moduleParam);
$selfUrl = 'institutions.php' . ($moduleParam !== '' ? '?module=' . rawurlencode($moduleParam) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Your session expired. Reload the page and try again.';
    } elseif ($action === 'update') {
        $newLogo = '';
        try {
            $newLogo = balances_store_uploaded_account_image('logo');
            $res = balances_update_custom_institution($pdo, $id, (string) ($_POST['name'] ?? ''), (string) ($_POST['type'] ?? ''), $newLogo);
        } catch (RuntimeException $e) {
            $res = ['success' => false, 'message' => $e->getMessage()];
        }
        if (!$res['success'] && $newLogo !== '') {
            @unlink(dirname(__DIR__, 2) . '/' . $newLogo);
        }
        $_SESSION[$res['success'] ? 'success' : 'error'] = $res['message'];
    } elseif ($action === 'delete') {
        $ok = balances_delete_custom_institution($pdo, $id);
        $_SESSION[$ok ? 'success' : 'error'] = $ok ? 'Saved logo removed. Existing accounts keep their image.' : 'Saved logo not found.';
    }
    redirect($selfUrl);
}

$saved = balances_list_custom_institutions($pdo);
$typeLabels = ['bank' => 'Bank', 'mobile' => 'Mobile money', 'cash' => 'Cash'];
$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$page_title = 'Saved Bank & Wallet Logos';
$bodyExtraClass = 'page-balances-accounts';
include __DIR__ . '/includes/header.php';
?>
<style>
    .inst-wrap { max-width: 960px; margin: 0 auto; padding: 1.5rem 1.75rem 2.5rem; font-family: 'Inter', sans-serif; }
    .inst-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 1rem; }
    .inst-head h1 { font-size: 1.15rem; font-weight: 700; color: #111827; margin: 0; }
    .inst-head p { margin: 4px 0 0; font-size: 0.8125rem; color: #6b7280; }
    .inst-back { font-size: 0.8125rem; color: #6d28d9; text-decoration: none; white-space: nowrap; }
    .inst-flash { padding: 0.6rem 0.9rem; border-radius: 10px; font-size: 0.8125rem; margin-bottom: 0.9rem; }
    .inst-flash.ok { background: #ecfdf5; color: #047857; }
    .inst-flash.err { background: #fef2f2; color: #b91c1c; }
    .inst-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; }
    .inst-row { display: flex; align-items: center; gap: 12px; padding: 0.7rem 1rem; border-bottom: 1px solid #f1f5f9; }
    .inst-row:last-child { border-bottom: 0; }
    .inst-row form.inst-edit { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; flex-wrap: wrap; }
    .inst-logo { position: relative; flex: 0 0 40px; width: 40px; height: 40px; border-radius: 10px; overflow: hidden; cursor: pointer;
        box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.1); background: #fff; display: inline-flex; align-items: center; justify-content: center; }
    .inst-logo img { width: 100%; height: 100%; object-fit: contain; }
    .inst-logo input { position: absolute; width: 1px; height: 1px; opacity: 0; }
    .inst-logo::after { content: '\f030'; font-family: 'Font Awesome 6 Free', 'Font Awesome 5 Free'; font-weight: 900; position: absolute; inset: 0;
        display: flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.45); color: #fff; font-size: 0.8rem; opacity: 0; transition: opacity 0.15s; }
    .inst-logo:hover::after { opacity: 1; }
    .inst-row input[type="text"], .inst-row select { padding: 0.45rem 0.7rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.8125rem; color: #111827; background: #fff; }
    .inst-row input[type="text"] { flex: 1 1 220px; min-width: 0; }
    .inst-btn { padding: 0.45rem 0.9rem; border-radius: 8px; font-size: 0.8125rem; font-weight: 500; border: 1px solid transparent; cursor: pointer; }
    .inst-btn-save { background: #0f766e; color: #fff; }
    .inst-btn-del { background: #fff; color: #b91c1c; border-color: #fecaca; }
    .inst-empty { padding: 2.5rem 1rem; text-align: center; color: #6b7280; font-size: 0.875rem; }
    html[data-theme="dark"] .inst-panel { background: #111b21; border-color: #2a3942; }
    html[data-theme="dark"] .inst-row { border-color: #2a3942; }
    html[data-theme="dark"] .inst-head h1 { color: #e9edef; }
    html[data-theme="dark"] .inst-row input[type="text"], html[data-theme="dark"] .inst-row select { background: #0b141a; border-color: #2a3942; color: #e9edef; }
</style>

<main class="main-content">
    <div class="inst-wrap">
        <div class="inst-head">
            <div>
                <h1>Saved bank &amp; wallet logos</h1>
                <p>Names and logos users added while creating accounts. They appear in the sub-account name list. Click a logo to replace it.</p>
            </div>
            <a class="inst-back" href="<?= $h($accountsUrl) ?>"><i class="fas fa-arrow-left"></i> Back to accounts</a>
        </div>

        <?php foreach (['success' => 'ok', 'error' => 'err'] as $key => $cls): ?>
            <?php if (!empty($_SESSION[$key])): ?>
                <div class="inst-flash <?= $cls ?>"><?= $h($_SESSION[$key]) ?></div>
                <?php unset($_SESSION[$key]); ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="inst-panel">
            <?php if (!$saved): ?>
                <div class="inst-empty">No saved logos yet. When you create a bank, mobile money or cash account with your own logo, it is saved here.</div>
            <?php endif; ?>
            <?php foreach ($saved as $inst): ?>
                <div class="inst-row">
                    <form method="post" enctype="multipart/form-data" class="inst-edit">
                        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                        <label class="inst-logo" title="Replace logo">
                            <img src="<?= $h(balancesAccountImageUrl($inst['logo'])) ?>" alt="">
                            <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp,.gif,image/*"
                                   onchange="if (this.files[0]) { this.previousElementSibling.src = URL.createObjectURL(this.files[0]); }">
                        </label>
                        <input type="text" name="name" value="<?= $h($inst['name']) ?>" maxlength="100" required aria-label="Name">
                        <select name="type" aria-label="Type">
                            <?php foreach ($typeLabels as $slug => $label): ?>
                                <option value="<?= $slug ?>"<?= $slug === $inst['type'] ? ' selected' : '' ?>><?= $h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="inst-btn inst-btn-save">Save</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Remove this saved logo? Accounts that already use it keep their image.');">
                        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                        <button type="submit" class="inst-btn inst-btn-del">Remove</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
