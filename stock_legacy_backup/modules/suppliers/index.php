<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$stmt = $pdo->query('SELECT * FROM stocks_suppliers ORDER BY name ASC');
$suppliers = $stmt->fetchAll();

$page_title = 'Suppliers';
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
    .sup-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .sup-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .sup-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .suppliers-table thead tr.sup-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .suppliers-table thead tr.sup-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .suppliers-table-wrapper {
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
        .suppliers-table {
            min-width: 800px;
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
            text-decoration: none !important;
        }
        .fab:hover {
            background: #1D4ED8;
            transform: scale(1.05);
            color: white;
        }
    }
</style>

<main class="main-content sup-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="../products/index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Products
                </a>
                <a href="add.php" class="btn sup-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0">
                    <i class="fas fa-plus text-sm"></i> Add supplier
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Suppliers</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="../purchases/index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-shopping-cart text-sm"></i> Purchases
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <span class="font-medium text-gray-700 tabular-nums"><?php echo count($suppliers); ?> supplier<?php echo count($suppliers) === 1 ? '' : 's'; ?></span>
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php flash('success'); ?>
        </div>

        <div class="bg-white border-t border-gray-200">
            <div class="suppliers-table-wrapper">
                <table class="table table-hover align-middle mb-0 suppliers-table w-100">
                    <thead>
                        <tr class="sup-table-head">
                            <th class="ps-3 py-3">Name</th>
                            <th class="py-3">Contact person</th>
                            <th class="py-3">Phone</th>
                            <th class="py-3">Email</th>
                            <th class="text-center py-3 pe-3" style="width: 100px;"><i class="fas fa-sliders-h text-white/70" title="Actions"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr class="clickable-row border-b border-gray-100" data-href="edit.php?id=<?php echo (int) $supplier['id']; ?>">
                                <td class="ps-3 py-3 fw-semibold text-gray-900 text-base"><?php echo htmlspecialchars($supplier['name'] ?? ''); ?></td>
                                <td class="py-3 text-base text-gray-700"><?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?></td>
                                <td class="py-3 text-base text-gray-700 whitespace-nowrap"><?php echo htmlspecialchars($supplier['phone'] ?? ''); ?></td>
                                <td class="py-3 text-base text-gray-700"><?php echo htmlspecialchars($supplier['email'] ?? ''); ?></td>
                                <td class="text-center py-3 pe-3" onclick="event.stopPropagation()">
                                    <a href="edit.php?id=<?php echo (int) $supplier['id']; ?>" class="text-gray-500 hover:text-[#2563EB] me-3" title="Edit"><i class="fas fa-edit"></i></a>
                                    <a href="delete.php?id=<?php echo (int) $supplier['id']; ?>" class="text-danger" title="Delete" onclick="event.stopPropagation(); return confirm('Delete this supplier?');"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-16 px-4">
                                    <i class="fas fa-truck-loading text-5xl text-gray-300 mb-4"></i>
                                    <p class="text-gray-700 text-lg font-medium mb-1">No suppliers yet</p>
                                    <p class="text-gray-500 text-base mb-0">Add a supplier to use on purchases and products.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="add.php" class="fab d-md-none" title="Add supplier"><i class="fas fa-plus"></i></a>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var wrap = document.querySelector('.suppliers-table-wrapper');
    if (wrap) {
        wrap.addEventListener('click', function(e) {
            var row = e.target.closest('.clickable-row');
            if (!row || !row.dataset.href) {
                return;
            }
            if (e.target.closest('a') || e.target.closest('button')) {
                return;
            }
            window.location.href = row.dataset.href;
        });
    }

    if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable) {
        return;
    }
    <?php if (empty($suppliers)): ?>
    return;
    <?php endif; ?>
    var $t = jQuery('.suppliers-table');
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
            { orderable: false, targets: [4] }
        ],
        language: {
            lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            infoEmpty: 'No entries to show',
            infoFiltered: '(filtered from _MAX_ total entries)'
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
