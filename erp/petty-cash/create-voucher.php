<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}
$_SESSION['active_module'] = 'petty_cash';

global $pdo;
$user_id = $_SESSION['user_id'] ?? 0;

$categories = getPettyCashCategories();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_category_name = trim((string) ($_POST['new_category_name'] ?? ''));
    $selected_category = trim((string) ($_POST['category'] ?? ''));

    if ($new_category_name !== '') {
        $created = createPettyCashCategory($new_category_name);
        if (is_int($created)) {
            $resolved_category = $new_category_name;
        } elseif (is_string($created)) {
            $dup = $pdo->prepare('SELECT name FROM petty_cash_categories WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $dup->execute([$new_category_name]);
            $existingName = $dup->fetchColumn();
            $resolved_category = $existingName ? (string) $existingName : '';
            if ($resolved_category === '' && strpos($created, 'already exists') === false) {
                $error = $created;
            } elseif ($resolved_category === '') {
                $resolved_category = $new_category_name;
            }
        }
    } else {
        $resolved_category = $selected_category;
    }

    $data = [
        'date' => trim($_POST['date'] ?? ''),
        'custodian_id' => $user_id,
        'category' => $resolved_category,
        'description' => trim($_POST['description'] ?? ''),
        'amount' => (float) ($_POST['amount'] ?? 0),
        'created_by' => $user_id,
    ];

    $uploadDir = __DIR__ . '/../../assets/uploads/petty-cash/';
    if (!empty($_FILES['receipt']['name']) && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $file_ext = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
        $file_name = 'receipt_' . time() . '_' . uniqid('', true) . '.' . $file_ext;
        $file_path = $uploadDir . $file_name;

        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $file_path)) {
            $data['receipt_path'] = 'assets/uploads/petty-cash/' . $file_name;
        }
    }

    if ($error === '') {
        if ($data['amount'] <= 0) {
            $error = 'Amount must be greater than zero.';
        } elseif ($data['date'] === '' || $data['category'] === '' || $data['description'] === '') {
            if ($data['category'] === '' && $new_category_name === '' && $selected_category === '') {
                $error = 'Please select a category or create a new petty cash category.';
            } else {
                $error = 'Please fill in all required fields.';
            }
        } else {
            $voucher_id = createPettyCashVoucher($data);

            if ($voucher_id) {
                $q = array_merge($_GET ?: [], [
                    'success' => 'created',
                    'voucher_id' => (int) $voucher_id,
                ]);
                header('Location: create-voucher.php?' . http_build_query($q));
                exit;
            }
            $error = 'Failed to create voucher. Please try again.';
        }
    }
}

$page_title = 'Create Petty Cash Voucher';

$overviewQuery = $_GET ?: [];
unset($overviewQuery['success']);
$backUrl = 'index.php' . (!empty($overviewQuery) ? '?' . http_build_query($overviewQuery) : '');

$formValues = [
    'date' => trim((string) ($_POST['date'] ?? date('Y-m-d'))),
    'category' => trim((string) ($_POST['category'] ?? '')),
    'new_category_name' => trim((string) ($_POST['new_category_name'] ?? '')),
    'amount' => trim((string) ($_POST['amount'] ?? '')),
    'description' => trim((string) ($_POST['description'] ?? '')),
];
$showNewCategory = $formValues['new_category_name'] !== '';

$moduleQs = array_filter([
    'module' => $_GET['module'] ?? 'petty_cash',
    'company_slug' => $_GET['company_slug'] ?? null,
], static fn($v) => $v !== null && $v !== '');
$pc_lottie_redirect = 'index.php?' . http_build_query(array_merge($moduleQs, ['success' => 'created']));
$pc_lottie_show_success = isset($_GET['success']) && $_GET['success'] === 'created';
$pc_lottie_view_url = '';
if ($pc_lottie_show_success && !empty($_GET['voucher_id'])) {
    $viewQs = $moduleQs;
    $viewQs['id'] = (int) $_GET['voucher_id'];
    $pc_lottie_view_url = 'view-voucher.php?' . http_build_query($viewQs);
}

$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$selectArrow = "background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;";

include __DIR__ . '/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {
        font-family: 'Inter', sans-serif;
        background: #f8fafc;
        color: #1e293b;
    }
    .main-content-wrapper { padding: 2rem; }
    .page-shell { padding-left: 4rem; }
    .editor-shell { max-width: 1140px; margin: 0 auto; }
    .editor-topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e5e7eb;
    }
    .editor-layout {
        display: grid;
        grid-template-columns: 180px minmax(0, 1fr);
        gap: 2rem;
        align-items: start;
    }
    .section-nav { position: sticky; top: 96px; align-self: start; }
    .section-nav ul { list-style: none; margin: 0; padding: 0; }
    .section-nav li + li { margin-top: 0.5rem; }
    .section-nav a {
        display: block;
        padding: 0.45rem 0.75rem;
        border-radius: 8px;
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .section-nav a:hover { background: #f3e8ff; color: #7c3aed; }
    .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
    .editor-main { min-width: 0; }
    .editor-section {
        padding-bottom: 2rem;
        margin-bottom: 2rem;
        border-bottom: 1px solid #e5e7eb;
    }
    .editor-section:last-of-type { margin-bottom: 1.5rem; }
    .section-header { margin-bottom: 1.25rem; }
    .form-row {
        display: grid;
        grid-template-columns: 210px 1fr;
        align-items: start;
        margin-bottom: 24px;
    }
    .form-row:last-child { margin-bottom: 0; }
    .form-label {
        font-size: 14px;
        font-weight: 500;
        color: #1e293b;
        padding-top: 12px;
    }
    .form-label span { color: #ef4444; margin-left: 2px; }
    .form-input {
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
    .form-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }
    .form-input-price {
        color: #16a34a !important;
        font-weight: 600;
    }
    .form-input-price::placeholder { color: #86efac; }
    .help-text {
        font-size: 12px;
        color: #94a3b8;
        margin-top: 6px;
        line-height: 1.5;
        font-weight: 400;
    }
    .section-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }
    .section-subtitle {
        font-size: 12px;
        color: #94a3b8;
        margin: 0;
    }
    .btn-save {
        background: #7c3aed;
        color: white;
        padding: 14px 48px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 15px;
        transition: all 0.2s;
        box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
        border: none;
        cursor: pointer;
    }
    .btn-save:hover { background: #6d28d9; }
    .btn-cancel {
        border: 1px solid #d8b4fe;
        color: #7c3aed;
        background: #faf5ff;
        transition: all 0.2s;
        cursor: pointer;
    }
    .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
    .file-upload-box {
        border: 2px dashed #e2e8f0;
        border-radius: 12px;
        padding: 1.25rem;
        background: #fafafa;
        transition: all 0.2s;
    }
    .file-upload-box:hover { border-color: #c4b5fd; background: #faf5ff; }
    .file-upload-box input[type="file"] { font-size: 13px; color: #64748b; }
    .btn-inline {
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
        padding: 0 14px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
        cursor: pointer;
    }
    .btn-inline:hover { background: #f8fafc; }
    .input-group-inline { display: flex; gap: 8px; align-items: stretch; }
    .input-group-inline .form-input { flex: 1; min-width: 0; }
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
        .btn-save { width: 100%; padding: 14px 24px; }
    }
</style>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">Create Petty Cash Voucher</h1>
            </div>
            <a href="<?= $esc($backUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Overview
            </a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 max-w-[1000px]">
                <?= $esc($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="voucher-form">
            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#voucher-info" class="is-active">General</a></li>
                        <li><a href="#voucher-details">Details</a></li>
                        <li><a href="#attachment">Attachment</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="voucher-info">
                        <div class="section-header">
                            <h2 class="section-title">Voucher Information</h2>
                            <p class="section-subtitle">Date and category for this petty cash voucher.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="date">Date <span>*</span></label>
                            <div>
                                <input type="date"
                                       name="date"
                                       id="date"
                                       required
                                       max="<?= date('Y-m-d') ?>"
                                       value="<?= $esc($formValues['date']) ?>"
                                       class="form-input">
                                <div class="help-text">Voucher date cannot be in the future.</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="category">Category <span>*</span></label>
                            <div>
                                <div class="input-group-inline">
                                    <select name="category"
                                            id="category"
                                            class="form-input appearance-none pr-10"
                                            style="<?= $esc($selectArrow) ?>"
                                            <?= $showNewCategory ? '' : 'required' ?>>
                                        <option value="">Select category…</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $esc($cat) ?>" <?= $formValues['category'] === $cat ? 'selected' : '' ?>><?= $esc($cat) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn-inline" onclick="toggleNewCategory()">+ New</button>
                                </div>
                                <input type="text"
                                       name="new_category_name"
                                       id="new_category_input"
                                       value="<?= $esc($formValues['new_category_name']) ?>"
                                       placeholder="Enter new category name…"
                                       class="form-input mt-2"
                                       style="<?= $showNewCategory ? '' : 'display:none;' ?>">
                                <div class="help-text">Choose an existing category, or click + New to type a new one.</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="amount">Amount (TSh) <span>*</span></label>
                            <div>
                                <input type="number"
                                       name="amount"
                                       id="amount"
                                       step="0.01"
                                       min="0.01"
                                       required
                                       placeholder="0.00"
                                       value="<?= $esc($formValues['amount']) ?>"
                                       class="form-input form-input-price">
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="voucher-details">
                        <div class="section-header">
                            <h2 class="section-title">Voucher Details</h2>
                            <p class="section-subtitle">Describe what this petty cash voucher is for.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="description">Description <span>*</span></label>
                            <div>
                                <textarea name="description"
                                          id="description"
                                          rows="5"
                                          required
                                          placeholder="Describe the purpose and any relevant notes for this voucher…"
                                          class="form-input min-h-[120px]"><?= $esc($formValues['description']) ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="attachment">
                        <div class="section-header">
                            <h2 class="section-title">Receipt</h2>
                            <p class="section-subtitle">Optional supporting document for this voucher.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="receipt">Receipt</label>
                            <div>
                                <div class="file-upload-box">
                                    <input type="file"
                                           id="receipt"
                                           name="receipt"
                                           accept="image/*,.pdf">
                                    <div class="help-text mt-2">Accepted formats: images or PDF.</div>
                                    <div id="file-name" class="help-text text-purple-600 font-medium"></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="flex justify-start gap-4 mb-20">
                        <button type="button" onclick="location.href='<?= $esc($backUrl) ?>'" class="btn-cancel px-8 py-3 rounded-xl font-bold">Cancel</button>
                        <button type="submit" class="btn-save">
                            <i class="fas fa-paper-plane mr-2 text-sm"></i>Submit Voucher
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/lottie-success-overlay.php'; ?>

<script>
(function () {
    var receipt = document.getElementById('receipt');
    var fileNameEl = document.getElementById('file-name');
    var form = document.getElementById('voucher-form');
    var categorySelect = document.getElementById('category');
    var newCategoryInput = document.getElementById('new_category_input');

    window.toggleNewCategory = function () {
        if (!newCategoryInput || !categorySelect) return;
        var showing = newCategoryInput.style.display !== 'none';
        if (showing) {
            newCategoryInput.style.display = 'none';
            newCategoryInput.value = '';
            categorySelect.required = true;
        } else {
            newCategoryInput.style.display = 'block';
            categorySelect.required = false;
            categorySelect.value = '';
            newCategoryInput.focus();
        }
    };

    if (newCategoryInput && categorySelect) {
        newCategoryInput.addEventListener('input', function () {
            if (newCategoryInput.value.trim() !== '') {
                categorySelect.required = false;
                categorySelect.value = '';
            } else {
                categorySelect.required = true;
            }
        });
        categorySelect.addEventListener('change', function () {
            if (categorySelect.value.trim() !== '') {
                newCategoryInput.value = '';
                categorySelect.required = true;
            }
        });
    }

    if (receipt && fileNameEl) {
        receipt.addEventListener('change', function (e) {
            var f = e.target.files && e.target.files[0];
            fileNameEl.textContent = f ? ('Selected: ' + f.name) : '';
        });
    }

    document.querySelectorAll('.section-nav a').forEach(function (link) {
        link.addEventListener('click', function () {
            document.querySelectorAll('.section-nav a').forEach(function (a) { a.classList.remove('is-active'); });
            link.classList.add('is-active');
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
