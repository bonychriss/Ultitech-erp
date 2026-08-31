<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    $_SESSION['error'] = 'Access denied.';
    redirect('accounts.php');
}

balances_ensure_account_types_schema($pdo);

$balancesQs = static function (array $extra = []): string {
    $qs = $extra;
    if (!empty($_GET['module'])) {
        $qs['module'] = (string) $_GET['module'];
    }
    if (!empty($_GET['company_slug'])) {
        $qs['company_slug'] = (string) $_GET['company_slug'];
    }
    return $qs === [] ? '' : ('?' . http_build_query($qs));
};
$moduleQs = $balancesQs();
$accountTypeListUrl = 'account_types.php' . $balancesQs();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['form_action'] ?? '');

    if ($action === 'create') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        if ($slug === '' && $label !== '' && function_exists('balances_slugify_label')) {
            $slug = balances_slugify_label($label);
        }
        $codeMin = (int) ($_POST['code_range_min'] ?? 1000);
        $codeMax = (int) ($_POST['code_range_max'] ?? 1999);

        if ($label === '' || $slug === '') {
            $_SESSION['error'] = 'Type name and slug are required.';
        } elseif ($codeMin <= 0 || $codeMax <= 0 || $codeMin >= $codeMax) {
            $_SESSION['error'] = 'Code range must be valid (min less than max).';
        } else {
            try {
                $maxOrder = (int) ($pdo->query('SELECT COALESCE(MAX(display_order), 0) FROM financial_account_types')->fetchColumn() ?: 0);
                $st = $pdo->prepare('
                    INSERT INTO financial_account_types (slug, label, code_range_min, code_range_max, status, display_order)
                    VALUES (?, ?, ?, ?, \'Active\', ?)
                ');
                $st->execute([$slug, $label, $codeMin, $codeMax, $maxOrder + 10]);
                $_SESSION['bal_lottie_success'] = 'Account type created successfully.';
                $_SESSION['bal_lottie_redirect'] = $accountTypeListUrl;
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Could not create account type. The slug may already exist.';
            }
        }
        header('Location: account_type_create.php' . $moduleQs);
        exit;
    }

}

$page_title = 'Add Account Type';
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$formAction = 'account_type_create.php' . $moduleQs;
$backUrl = 'account_types.php' . $moduleQs;
$sessionError = trim((string) ($_SESSION['error'] ?? ''));
$sessionSuccess = trim((string) ($_SESSION['success'] ?? ''));
if ($sessionError !== '') {
    unset($_SESSION['error']);
}
if ($sessionSuccess !== '') {
    unset($_SESSION['success']);
}

$formValues = [
    'label' => trim((string) ($_POST['label'] ?? '')),
    'slug' => trim((string) ($_POST['slug'] ?? '')),
    'code_range_min' => (int) ($_POST['code_range_min'] ?? 6000),
    'code_range_max' => (int) ($_POST['code_range_max'] ?? 6999),
];
if ($formValues['slug'] === '' && $formValues['label'] !== '' && function_exists('balances_slugify_label')) {
    $formValues['slug'] = balances_slugify_label($formValues['label']);
}

include __DIR__ . '/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
    .employee-header { display: none !important; }
    .main-content-wrapper { padding: 2rem; }
    .page-shell { padding-left: 4rem; }
    .editor-shell { max-width: 1140px; margin: 0 auto; }
    .editor-topbar {
        display: flex; align-items: center; justify-content: space-between;
        gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e7eb;
    }
    .editor-layout { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 2rem; align-items: start; }
    .section-nav { position: sticky; top: 96px; align-self: start; }
    .section-nav ul { list-style: none; margin: 0; padding: 0; }
    .section-nav li + li { margin-top: 0.5rem; }
    .section-nav a {
        display: block; padding: 0.45rem 0.75rem; border-radius: 8px; color: #64748b;
        font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.2s ease;
    }
    .section-nav a:hover { background: #eff6ff; color: #2563eb; }
    .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
    .editor-main { min-width: 0; }
    .editor-section { padding-bottom: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
    .editor-section:last-of-type { margin-bottom: 1.5rem; }
    .section-header { margin-bottom: 1.25rem; }
    .form-row { display: grid; grid-template-columns: 210px 1fr; align-items: start; margin-bottom: 24px; }
    .form-row:last-child { margin-bottom: 0; }
    .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 12px; }
    .form-label span { color: #ef4444; margin-left: 2px; }
    .form-input {
        width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px;
        font-size: 14px; color: #1e293b; outline: none; transition: all 0.2s; background: #fff;
    }
    .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
    .form-input-readonly {
        background: #f8fafc; font-family: monospace; font-weight: 700; color: #2563eb; border-style: dashed;
    }
    .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; }
    .section-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
    .section-subtitle { font-size: 12px; color: #94a3b8; margin: 0; }
    .btn-save {
        background: #7c3aed; color: white; padding: 10px 22px; border-radius: 10px; font-weight: 600;
        font-size: 13px; border: none; cursor: pointer; box-shadow: 0 2px 8px rgba(124, 58, 237, 0.18);
    }
    .btn-save:hover { background: #6d28d9; }
    .btn-cancel {
        border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff; transition: all 0.2s;
        cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;
        padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 600;
    }
    .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
    .btn-slug-edit {
        border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff; border-radius: 10px;
        padding: 0 16px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;
    }
    .btn-slug-edit:hover { background: #f3e8ff; }
    .code-range-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .cat-list-section { margin-top: 3rem; padding-top: 2rem; border-top: 1px solid #e5e7eb; }
    .cat-list-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .cat-list-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; }
    .cat-search { width: 260px; max-width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
    .cat-table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
    .cat-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .cat-table th, .cat-table td { border-bottom: 1px solid #f1f5f9; padding: 12px 14px; vertical-align: middle; }
    .cat-table th { background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; }
    .cat-pill { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .pill-active { background: #dcfce7; color: #15803d; }
    .pill-inactive { background: #f1f5f9; color: #475569; }
    .cat-action-btn {
        width: 32px; height: 32px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;
        color: #64748b; display: inline-flex; align-items: center; justify-content: center;
    }
    .cat-action-btn:hover { color: #7c3aed; border-color: #d8b4fe; }
    @media (max-width: 992px) {
        .main-content-wrapper { padding: 1rem !important; }
        .page-shell { padding-left: 0; }
        .editor-topbar { flex-direction: column; align-items: flex-start; }
        .editor-layout { grid-template-columns: 1fr; gap: 1rem; }
        .section-nav { position: static; }
        .section-nav ul { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .section-nav li + li { margin-top: 0; }
        .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 20px; }
        .form-label { padding-top: 0; font-size: 13px; }
        .btn-save { width: 100%; padding: 10px 18px; }
        .btn-cancel { width: 100%; justify-content: center; }
        .code-range-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 992px) {
        html body .main-content,
        html body .content-wrapper,
        html body main,
        html body.dashboard .main-content,
        html body .header,
        html body .admin-header,
        html body .employee-header {
            margin-left: 0 !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }
    }
</style>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">Add Account Type</h1>
            </div>
            <a href="<?= $esc($backUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Account Types
            </a>
        </div>

        <?php if ($sessionError !== ''): ?>
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 max-w-[1000px]">
            <?= $esc($sessionError) ?>
        </div>
        <?php endif; ?>
        <?php if ($sessionSuccess !== ''): ?>
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-700 max-w-[1000px]">
            <?= $esc($sessionSuccess) ?>
        </div>
        <?php endif; ?>

        <form id="accountTypeForm" method="post" action="<?= $esc($formAction) ?>">
            <input type="hidden" name="form_action" value="create">

            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#general-info" class="is-active">General</a></li>
                        <li><a href="#code-series">Code Series</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="general-info">
                        <div class="section-header">
                            <h2 class="section-title">General Information</h2>
                            <p class="section-subtitle">Display name and internal slug for chart-of-accounts account types.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Display Name <span>*</span></label>
                            <div>
                                <input class="form-input" name="label" id="typeLabel" required
                                    placeholder="e.g. Petty Cash" value="<?= $esc($formValues['label']) ?>">
                                <div class="help-text">Shown in the Account Type dropdown on New Account.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Slug <span>*</span></label>
                            <div>
                                <div class="flex gap-2 items-stretch">
                                    <input class="form-input form-input-readonly flex-1" name="slug" id="typeSlug" required
                                        value="<?= $esc($formValues['slug']) ?>"
                                        placeholder="Auto-generated from name" pattern="[a-z0-9_]+" autocomplete="off" readonly>
                                    <button type="button" class="btn-slug-edit" id="typeSlugEdit" title="Edit slug manually">Edit</button>
                                </div>
                                <div class="help-text">Generated automatically from Display Name (e.g. "Petty Cash" -&gt; <code>petty_cash</code>). Click Edit to override.</div>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="code-series">
                        <div class="section-header">
                            <h2 class="section-title">Code Series</h2>
                            <p class="section-subtitle">Numeric range used when auto-assigning account codes for this type.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Code Range <span>*</span></label>
                            <div>
                                <div class="code-range-grid">
                                    <div>
                                        <input class="form-input" type="number" name="code_range_min" min="1" required
                                            value="<?= (int) $formValues['code_range_min'] ?>">
                                        <div class="help-text">Minimum (e.g. 1000 for assets)</div>
                                    </div>
                                    <div>
                                        <input class="form-input" type="number" name="code_range_max" min="1" required
                                            value="<?= (int) $formValues['code_range_max'] ?>">
                                        <div class="help-text">Maximum (e.g. 1999 for assets)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="flex justify-start gap-3 mb-12">
                        <a href="<?= $esc($backUrl) ?>" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-save">Save Account Type</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const label = document.getElementById('typeLabel');
    const slug = document.getElementById('typeSlug');
    const editBtn = document.getElementById('typeSlugEdit');
    const form = document.getElementById('accountTypeForm');
    if (!label || !slug) return;

    function slugifyLabel(text) {
        return String(text || '').toLowerCase().trim()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function syncSlugFromLabel() {
        if (slug.dataset.manual === '1') return;
        slug.value = slugifyLabel(label.value);
    }

    function setManualMode(on) {
        slug.dataset.manual = on ? '1' : '0';
        slug.readOnly = !on;
        slug.classList.toggle('form-input-readonly', !on);
        if (editBtn) {
            editBtn.textContent = on ? 'Auto' : 'Edit';
        }
        if (!on) syncSlugFromLabel();
    }

    label.addEventListener('input', syncSlugFromLabel);
    label.addEventListener('change', syncSlugFromLabel);
    label.addEventListener('paste', () => setTimeout(syncSlugFromLabel, 0));
    slug.addEventListener('input', () => { slug.value = slugifyLabel(slug.value); });

    if (editBtn) {
        editBtn.addEventListener('click', () => {
            setManualMode(slug.dataset.manual !== '1');
            if (slug.dataset.manual === '1') slug.focus();
        });
    }

    if (form) {
        form.addEventListener('submit', () => {
            if (slug.dataset.manual !== '1') syncSlugFromLabel();
        });
    }

    syncSlugFromLabel();

    document.querySelectorAll('.section-nav a').forEach((link) => {
        link.addEventListener('click', () => {
            document.querySelectorAll('.section-nav a').forEach((a) => a.classList.remove('is-active'));
            link.classList.add('is-active');
        });
    });
})();
</script>

<?php
$accountTypeLottieSuccess = !empty($_SESSION['bal_lottie_success']);
if (!empty($_SESSION['bal_lottie_redirect'])) {
    $bal_lottie_redirect = (string) $_SESSION['bal_lottie_redirect'];
    unset($_SESSION['bal_lottie_redirect']);
} else {
    $bal_lottie_redirect = $accountTypeListUrl;
}
$bal_lottie_okay_label = 'View list';
$bal_lottie_view_url = '';
$pc_lottie_mobile_only = false;
$pc_lottie_form_ids = ['accountTypeForm'];
include __DIR__ . '/includes/footer.php';
?>
<?php if ($accountTypeLottieSuccess && $bal_lottie_redirect !== ''): ?>
<script>
document.addEventListener('pc-lottie-ready', function () {
    var redirectUrl = <?= json_encode($bal_lottie_redirect, JSON_UNESCAPED_SLASHES) ?>;
    setTimeout(function () {
        if (redirectUrl) {
            window.location.replace(redirectUrl);
        }
    }, 2800);
}, { once: true });
</script>
<?php endif; ?>
