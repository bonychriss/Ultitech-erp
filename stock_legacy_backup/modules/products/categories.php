<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$hasCategoriesTable = false;
$hasStocksCategoriesTable = false;
$useCategoriesTable = false;
$categoriesTable = 'stocks_categories';
$hasStatusColumn = false;
try {
    $hasCategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'categories'")->fetchColumn();
    $hasStocksCategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_categories'")->fetchColumn();

    $catsCount = $hasCategoriesTable ? (int)($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() ?: 0) : 0;
    $stocksCatsCount = $hasStocksCategoriesTable ? (int)($pdo->query("SELECT COUNT(*) FROM stocks_categories")->fetchColumn() ?: 0) : 0;
    $useCategoriesTable = $hasCategoriesTable && ($catsCount >= $stocksCatsCount);
    $categoriesTable = $useCategoriesTable ? 'categories' : 'stocks_categories';

    $cols = $pdo->query("SHOW COLUMNS FROM {$categoriesTable}")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasStatusColumn = in_array('status', $cols, true);
} catch (Throwable $e) {
    $hasCategoriesTable = false;
    $hasStocksCategoriesTable = true;
    $useCategoriesTable = false;
    $categoriesTable = 'stocks_categories';
    $hasStatusColumn = false;
}

$error = '';
/** @var array{title: string, message: string, variant: string}|null $categoryAddSuccess */
$categoryAddSuccess = null;
if (!empty($_SESSION['stock_category_add_success']) && is_array($_SESSION['stock_category_add_success'])) {
    $categoryAddSuccess = $_SESSION['stock_category_add_success'];
    unset($_SESSION['stock_category_add_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($action === 'add') {
        if ($name !== '') {
            if ($hasStatusColumn) {
                $status = $_POST['status'] ?? 'active';
                $stmt = $pdo->prepare("INSERT INTO {$categoriesTable} (name, description, status) VALUES (?, ?, ?)");
                $stmt->execute([$name, $description, $status]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO {$categoriesTable} (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $description]);
            }
            $_SESSION['stock_category_add_success'] = [
                'title' => 'Success!',
                'message' => 'Category added successfully!',
                'variant' => 'success',
            ];
        }
    } elseif ($action === 'edit') {
        $id = $_POST['id'] ?? '';
        if ($hasStatusColumn) {
            $status = $_POST['status'] ?? 'active';
            $stmt = $pdo->prepare("UPDATE {$categoriesTable} SET name = ?, description = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $description, $status, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE {$categoriesTable} SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $description, $id]);
        }
        flash('success', 'Category updated successfully!');
    }
    redirect('categories.php');
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM {$categoriesTable} WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            flash('success', 'Category deleted successfully!');
        } else {
            flash('success', 'Category not found or already deleted.', 'warning');
        }
    } catch (PDOException $e) {
        flash('success', 'Cannot delete category: It is likely linked to existing products.', 'danger');
    }
    redirect('categories.php');
}

$categories = [];
try {
    $categories = $pdo->query("SELECT * FROM {$categoriesTable} ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) {
    $categories = [];
}

$page_title = 'Product Categories';
include '../../includes/header.php';
?>
<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="../../assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .cat-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .cat-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .cat-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .btn.cat-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .cat-modal-form-h {
        background-color: #1c2331;
        color: #fff;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 2px solid #151a24;
    }
    .categories-table thead tr.cat-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .categories-table thead tr.cat-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .categories-table-wrapper {
        overflow-x: auto;
        overflow-y: visible !important;
    }
    .clickable-row {
        cursor: pointer;
        transition: background 0.15s;
    }
    .clickable-row:hover {
        background-color: #f9fafb !important;
    }
    div.dataTables_wrapper {
        padding: 0 1rem 1rem;
        background: #fff;
    }
    div.dataTables_wrapper .dataTables_length {
        padding-top: 0.75rem;
        margin-bottom: 0.25rem;
    }
    div.dataTables_wrapper .dataTables_length select {
        border-radius: 0.375rem;
        border: 1px solid #e5e7eb;
        padding: 0.35rem 2rem 0.35rem 0.5rem;
        font-size: 0.95rem;
        background-color: #fff;
    }
    div.dataTables_wrapper .dataTables_info,
    div.dataTables_wrapper .dataTables_paginate {
        padding-top: 0.75rem;
        font-size: 0.95rem;
        color: #6b7280;
    }
    @media (max-width: 768px) {
        .categories-table {
            min-width: 600px;
        }
        .fab {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #2563EB;
            color: white;
            border: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            z-index: 1000;
            transition: all 0.3s;
        }
        .fab:hover {
            background: #1D4ED8;
            transform: scale(1.05);
            color: white;
        }
    }

    /* Mobile success bottom sheet (Dispatch-style, same as add product) */
    @media (max-width: 767.98px) {
        body.category-add-success-sheet-open {
            overflow: hidden;
            touch-action: none;
        }
    }
    .category-add-success-sheet-backdrop {
        display: none;
    }
    .category-add-success-sheet {
        display: none;
    }
    @media (max-width: 767.98px) {
        .category-add-success-sheet-backdrop {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            z-index: 1080;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.28s ease, visibility 0.28s ease;
        }
        .category-add-success-sheet-backdrop.is-visible {
            opacity: 1;
            visibility: visible;
        }
        .category-add-success-sheet {
            display: block;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            max-height: min(58vh, 420px);
            background: #fff;
            border-radius: 1.25rem 1.25rem 0 0;
            box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.18);
            z-index: 1090;
            transform: translateY(105%);
            transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
            padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
        }
        .category-add-success-sheet.is-visible {
            transform: translateY(0);
        }
        .category-add-success-sheet-handle {
            width: 40px;
            height: 5px;
            background: #d1d5db;
            border-radius: 999px;
            margin: 12px auto 8px;
            flex-shrink: 0;
        }
    }
</style>

<main class="main-content cat-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Products
                </a>
                <button type="button" class="btn cat-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus text-sm"></i> Add category
                </button>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Product categories</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <span class="font-medium text-gray-700 tabular-nums"><?php echo count($categories); ?> categor<?php echo count($categories) === 1 ? 'y' : 'ies'; ?></span>
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php if ($categoryAddSuccess): ?>
                <?php $caVariant = ($categoryAddSuccess['variant'] ?? 'success') === 'warning' ? 'warning' : 'success'; ?>
                <div class="d-md-none category-add-success-sheet-backdrop" id="categoryAddSuccessBackdrop" aria-hidden="true"></div>
                <div class="d-md-none category-add-success-sheet" id="categoryAddSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="categoryAddSuccessSheetTitle">
                    <div class="category-add-success-sheet-handle" aria-hidden="true"></div>
                    <div class="px-4 pb-4 pt-0 text-center">
                        <?php if ($caVariant === 'warning'): ?>
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-15 text-warning mb-3" style="width: 56px; height: 56px;">
                                <i class="fas fa-exclamation-triangle fa-lg"></i>
                            </div>
                        <?php else: ?>
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                                <i class="fas fa-check fa-lg"></i>
                            </div>
                        <?php endif; ?>
                        <h2 id="categoryAddSuccessSheetTitle" class="h5 fw-bold text-dark mb-2"><?php echo htmlspecialchars($categoryAddSuccess['title'] ?? 'Success'); ?></h2>
                        <p class="text-secondary mb-4 small"><?php echo htmlspecialchars($categoryAddSuccess['message'] ?? ''); ?></p>
                        <a href="categories.php" class="btn cat-btn-primary w-100 py-2 rounded-pill fw-semibold border-0 d-inline-flex align-items-center justify-content-center" id="categoryAddSuccessDismiss">
                            View categories
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            <?php flash('success'); ?>
        </div>

        <div class="bg-white border-t border-gray-200">
            <div class="categories-table-wrapper">
                <table class="table table-hover align-middle mb-0 categories-table w-100">
                    <thead>
                        <tr class="cat-table-head">
                            <th class="ps-3 py-3">Name</th>
                            <th class="py-3">Description</th>
                            <th class="py-3">Status</th>
                            <th class="text-center py-3 pe-3" style="width: 100px;"><i class="fas fa-sliders-h text-white/70" title="Actions"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <?php
                            $displayName = htmlspecialchars(html_entity_decode($cat['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                            $displayDesc = htmlspecialchars(html_entity_decode($cat['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                            $attrName = htmlspecialchars(html_entity_decode($cat['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                            $attrDesc = htmlspecialchars(html_entity_decode($cat['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                            $attrStatus = htmlspecialchars($cat['status'] ?? '', ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr class="clickable-row border-b border-gray-100"
                                data-id="<?php echo (int) $cat['id']; ?>"
                                data-name="<?php echo $attrName; ?>"
                                data-desc="<?php echo $attrDesc; ?>"
                                data-status="<?php echo $attrStatus; ?>">
                                <td class="ps-3 py-3 text-base fw-semibold text-gray-900"><?php echo $displayName; ?></td>
                                <td class="py-3 text-base text-gray-700"><?php echo $displayDesc !== '' ? $displayDesc : 'â€”'; ?></td>
                                <td class="py-3">
                                    <?php if (($cat['status'] ?? '') === 'active'): ?>
                                        <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-[#28A745] text-white">Active</span>
                                    <?php else: ?>
                                        <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-gray-500 text-white">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center py-3 pe-3">
                                    <a href="#" class="text-gray-500 hover:text-[#2563EB] me-3 edit-btn"
                                       data-bs-toggle="modal"
                                       data-bs-target="#editModal"
                                       data-id="<?php echo (int) $cat['id']; ?>"
                                       data-name="<?php echo $attrName; ?>"
                                       data-desc="<?php echo $attrDesc; ?>"
                                       data-status="<?php echo $attrStatus; ?>"
                                       title="Edit"
                                       onclick="event.stopPropagation()"><i class="fas fa-edit"></i></a>
                                    <a href="categories.php?delete=<?php echo (int) $cat['id']; ?>"
                                       class="text-danger"
                                       onclick="event.stopPropagation(); return confirm('Delete this category?');"
                                       title="Delete"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-16 px-4">
                                    <i class="fas fa-tags text-5xl text-gray-300 mb-4"></i>
                                    <p class="text-gray-700 text-lg font-medium mb-1">No categories yet</p>
                                    <p class="text-gray-500 text-base mb-0">Add a category to organize products.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <button type="button" class="fab d-md-none border-0" data-bs-toggle="modal" data-bs-target="#addModal" title="Add category">
        <i class="fas fa-plus"></i>
    </button>
</main>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST">
            <div class="modal-content rounded-lg border border-gray-200 shadow-lg overflow-hidden">
                <div class="modal-header text-white border-0 py-3" style="background-color: #2563EB;">
                    <h5 class="modal-title fw-bold mb-0" id="addModalLabel"><i class="fas fa-plus-circle me-2 opacity-90"></i>Add category</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="cat-modal-form-h px-4 py-2 d-flex align-items-center gap-2">
                        <i class="fas fa-tags opacity-80"></i><span>Category details</span>
                    </div>
                    <div class="p-4 pt-3">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="add_name" class="form-label fw-semibold text-gray-700">Category name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="add_name" class="form-control rounded-md border-gray-300" required maxlength="255" placeholder="e.g. Office supplies" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label for="add_description" class="form-label fw-semibold text-gray-700">Description <span class="fw-normal text-gray-500 small">(optional)</span></label>
                            <textarea name="description" id="add_description" class="form-control rounded-md border-gray-300" rows="3" placeholder="Short note for your teamâ€¦"></textarea>
                        </div>
                        <div class="mb-0">
                            <label for="add_status" class="form-label fw-semibold text-gray-700">Status</label>
                            <select name="status" id="add_status" class="form-select rounded-md border-gray-300">
                                <option value="active" selected>Active â€” shown when assigning products</option>
                                <option value="inactive">Inactive â€” hidden from new assignments</option>
                            </select>
                            <div class="form-text text-gray-600 small mt-1">Inactive categories stay on existing products until you change them.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-gray-200 bg-gray-50">
                    <button type="button" class="btn btn-outline-secondary rounded-md px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn cat-btn-primary rounded-md px-4 fw-semibold border-0"><i class="fas fa-save me-2"></i>Save category</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST">
            <div class="modal-content rounded-lg border border-gray-200 shadow-lg overflow-hidden">
                <div class="modal-header text-white border-0 py-3" style="background-color: #2563EB;">
                    <h5 class="modal-title fw-bold mb-0" id="editModalLabel"><i class="fas fa-edit me-2 opacity-90"></i>Edit category</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="cat-modal-form-h px-4 py-2 d-flex align-items-center gap-2">
                        <i class="fas fa-tags opacity-80"></i><span>Category details</span>
                    </div>
                    <div class="p-4 pt-3">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label fw-semibold text-gray-700">Category name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control rounded-md border-gray-300" required maxlength="255" placeholder="e.g. Office supplies" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label for="edit_desc" class="form-label fw-semibold text-gray-700">Description <span class="fw-normal text-gray-500 small">(optional)</span></label>
                            <textarea name="description" id="edit_desc" class="form-control rounded-md border-gray-300" rows="3" placeholder="Short note for your teamâ€¦"></textarea>
                        </div>
                        <div class="mb-0">
                            <label for="edit_status" class="form-label fw-semibold text-gray-700">Status</label>
                            <select name="status" id="edit_status" class="form-select rounded-md border-gray-300">
                                <option value="active">Active â€” shown when assigning products</option>
                                <option value="inactive">Inactive â€” hidden from new assignments</option>
                            </select>
                            <div class="form-text text-gray-600 small mt-1">Inactive categories stay on existing products until you change them.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-gray-200 bg-gray-50">
                    <button type="button" class="btn btn-outline-secondary rounded-md px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn cat-btn-primary rounded-md px-4 fw-semibold border-0"><i class="fas fa-check me-2"></i>Update category</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var editModal = document.getElementById('editModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            if (!button || !button.getAttribute('data-id')) {
                return;
            }
            editModal.querySelector('#edit_id').value = button.getAttribute('data-id');
            editModal.querySelector('#edit_name').value = button.getAttribute('data-name') || '';
            editModal.querySelector('#edit_desc').value = button.getAttribute('data-desc') || '';
            editModal.querySelector('#edit_status').value = button.getAttribute('data-status') || 'active';
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var wrap = document.querySelector('.categories-table-wrapper');
        if (wrap) {
            wrap.addEventListener('click', function(e) {
                var row = e.target.closest('.clickable-row');
                if (!row || !row.dataset.id) {
                    return;
                }
                if (e.target.closest('a') || e.target.closest('button')) {
                    return;
                }
                var modalEl = document.getElementById('editModal');
                if (!modalEl || typeof bootstrap === 'undefined') {
                    return;
                }
                document.getElementById('edit_id').value = row.dataset.id;
                document.getElementById('edit_name').value = row.dataset.name || '';
                document.getElementById('edit_desc').value = row.dataset.desc || '';
                document.getElementById('edit_status').value = row.dataset.status || 'active';
                new bootstrap.Modal(modalEl).show();
            });
        }

        if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable) {
            return;
        }
        <?php if (empty($categories)): ?>
        return;
        <?php endif; ?>
        var $t = jQuery('.categories-table');
        if (!$t.length) {
            return;
        }
        if (jQuery.fn.DataTable.isDataTable($t)) {
            $t.DataTable().destroy();
        }
        $t.DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            stripeClasses: [],
            order: [[0, 'asc']],
            autoWidth: false,
            dom: 'lrtip',
            columnDefs: [
                { orderable: false, targets: [3] }
            ],
            language: {
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'No entries to show',
                infoFiltered: '(filtered from _MAX_ total entries)'
            }
        });
    });
})();
</script>

<?php if ($categoryAddSuccess): ?>
<script>
(function () {
    var sheet = document.getElementById('categoryAddSuccessSheet');
    var backdrop = document.getElementById('categoryAddSuccessBackdrop');
    var btn = document.getElementById('categoryAddSuccessDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;
    var listHref = 'categories.php';

    function goList() {
        window.location.href = listHref;
    }

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('category-add-success-sheet-open');
        requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
            sheet.classList.add('is-visible');
        });
        window.clearTimeout(autoTimer);
        autoTimer = window.setTimeout(function () {
            closeSheet(true);
        }, 6000);
    }

    function closeSheet(fromTimer) {
        window.clearTimeout(autoTimer);
        backdrop.classList.remove('is-visible');
        sheet.classList.remove('is-visible');
        document.body.classList.remove('category-add-success-sheet-open');
        window.setTimeout(function () {
            if (!sheet.classList.contains('is-visible')) {
                sheet.setAttribute('aria-hidden', 'true');
            }
            if (fromTimer) goList();
        }, 350);
    }

    function init() {
        if (mq.matches) openSheet();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    mq.addEventListener('change', function (e) {
        if (!e.matches) {
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('category-add-success-sheet-open');
        }
    });

    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            window.clearTimeout(autoTimer);
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('category-add-success-sheet-open');
            goList();
        });
    }
    backdrop.addEventListener('click', function () {
        closeSheet(true);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-visible')) {
            closeSheet(true);
        }
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Swal === 'undefined') return;
    if (!window.matchMedia('(min-width: 768px)').matches) return;
    var d = <?php echo json_encode($categoryAddSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    if (!d) return;
    Swal.fire({
        title: d.title || 'Success',
        text: d.message || '',
        icon: d.variant === 'warning' ? 'warning' : 'success',
        confirmButtonColor: '#2563EB',
        confirmButtonText: 'OK'
    }).then(function () {
        window.location.href = 'categories.php';
    });
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
