<?php
require_once 'includes/functions.php';
requireLogin();

$moduleSlug = isset($_GET['module']) ? trim((string) $_GET['module']) : 'revenue';
$moduleQs = '?module=' . rawurlencode($moduleSlug !== '' ? $moduleSlug : 'revenue');

$success = isset($_GET['success']) ? trim((string) $_GET['success']) : '';
$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';

try {
    $stmt = $pdo->query("SELECT * FROM revenue_customers ORDER BY customer_name ASC");
    $customers = $stmt->fetchAll();
} catch (PDOException $e) {
    $customers = [];
    $error = $error !== '' ? $error : ('Could not fetch customers: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Customers â€” Revenue - <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <link href="/assets/css/sales-mobile.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { corePlugins: { preflight: false } };</script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .rev-cust-shell {
            font-family: 'Outfit', system-ui, -apple-system, sans-serif;
            font-size: 16px;
            color: #374151;
        }
        .mov-btn-primary {
            background-color: #2563EB !important;
            color: #fff !important;
            border-color: #2563EB !important;
        }
        .mov-btn-primary:hover {
            background-color: #1D4ED8 !important;
            border-color: #1D4ED8 !important;
            color: #fff !important;
        }
        .rev-cust-table-wrapper {
            overflow-x: auto;
            overflow-y: visible !important;
            -webkit-overflow-scrolling: touch;
        }
        .rev-cust-table thead tr.rev-cust-table-head th {
            background-color: #1c2331 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            border-bottom: 2px solid #151a24 !important;
            vertical-align: middle;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.04em;
        }
        .rev-cust-table thead tr.rev-cust-table-head th:not(:last-child) {
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }
        .rev-cust-table tbody td {
            font-size: 0.9375rem;
            vertical-align: middle;
        }

        @media (max-width: 767.98px) {
            body.revenue-customers-success-sheet-open {
                overflow: hidden;
                touch-action: none;
            }
        }
        .revenue-customers-success-sheet-backdrop {
            display: none;
        }
        .revenue-customers-success-sheet {
            display: none;
        }
        @media (max-width: 767.98px) {
            .revenue-customers-success-sheet-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.48);
                z-index: 1080;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.28s ease, visibility 0.28s ease;
            }
            .revenue-customers-success-sheet-backdrop.is-visible {
                opacity: 1;
                visibility: visible;
            }
            .revenue-customers-success-sheet {
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
            .revenue-customers-success-sheet.is-visible {
                transform: translateY(0);
            }
            .revenue-customers-success-sheet-handle {
                width: 40px;
                height: 5px;
                background: #d1d5db;
                border-radius: 999px;
                margin: 12px auto 8px;
                flex-shrink: 0;
            }
        }
    </style>
</head>
<body class="dashboard dispatch-page">

<?php require_once 'includes/header_employee.php'; ?>

<main class="main-content rev-cust-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="revenue_entries.php<?= htmlspecialchars($moduleQs) ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Revenue
                </a>
                <a href="revenue_customer_create.php<?= htmlspecialchars($moduleQs) ?>" class="btn mov-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0">
                    <i class="fas fa-plus text-sm"></i> New customer
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Customers</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="/select-module.php" class="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline d-none d-md-inline-flex">
                    <i class="fas fa-th-large text-sm"></i> Modules
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <span class="font-medium text-gray-700 tabular-nums"><?= count($customers) ?></span>
                <span class="text-gray-500">customer<?= count($customers) === 1 ? '' : 's' ?></span>
                <span class="text-gray-400 hidden sm:inline">Â·</span>
                <span class="text-gray-600 text-sm sm:text-base">Directory for invoicing and revenue entries.</span>
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger rounded-lg border-0 shadow-sm mb-4" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="mb-3">
                    <div class="alert alert-success mb-0 rounded-lg border-0 shadow-sm d-none d-md-flex align-items-center gap-2">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                    </div>
                    <div class="d-md-none revenue-customers-success-sheet-backdrop" id="revenueCustomersSuccessBackdrop" aria-hidden="true"></div>
                    <div class="d-md-none revenue-customers-success-sheet" id="revenueCustomersSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="revenueCustomersSuccessSheetTitle">
                        <div class="revenue-customers-success-sheet-handle" aria-hidden="true"></div>
                        <div class="px-4 pb-4 pt-0 text-center">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                                <i class="fas fa-check fa-lg"></i>
                            </div>
                            <h2 id="revenueCustomersSuccessSheetTitle" class="h5 fw-bold text-dark mb-2">Success</h2>
                            <p class="text-secondary mb-4 small"><?= htmlspecialchars($success) ?></p>
                            <button type="button" class="btn mov-btn-primary w-100 py-2 rounded-pill fw-semibold border-0" id="revenueCustomersSuccessDismiss">
                                OK
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="bg-white border-t border-gray-200">
            <div class="rev-cust-table-wrapper">
                <table class="table table-hover align-middle mb-0 rev-cust-table w-100">
                    <thead>
                        <tr class="rev-cust-table-head">
                            <th class="ps-3 py-3">Customer name</th>
                            <th class="py-3">Contact</th>
                            <th class="py-3">Address</th>
                            <th class="py-3 d-none d-md-table-cell">Date added</th>
                            <th class="text-center py-3 pe-3" style="width: 120px;"><i class="fas fa-sliders-h text-white/70" title="Actions"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($customers) > 0): ?>
                            <?php foreach ($customers as $c): ?>
                                <tr class="border-b border-gray-100">
                                    <td class="ps-3 py-3 fw-semibold text-gray-900"><?= htmlspecialchars($c['customer_name']) ?></td>
                                    <td class="py-3 text-gray-800">
                                        <div class="d-flex flex-column gap-1">
                                            <?php if (!empty($c['phone'])): ?>
                                                <span><i class="fas fa-phone text-gray-400 me-1 small"></i><?= htmlspecialchars($c['phone']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($c['email'])): ?>
                                                <span class="small text-gray-600"><i class="fas fa-envelope text-gray-400 me-1"></i><?= htmlspecialchars($c['email']) ?></span>
                                            <?php endif; ?>
                                            <?php if (empty($c['phone']) && empty($c['email'])): ?>
                                                <span class="text-muted small fst-italic">No contact info</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 text-gray-700 small"><?= htmlspecialchars($c['address'] ?: 'â€”') ?></td>
                                    <td class="py-3 d-none d-md-table-cell text-gray-600 small tabular-nums"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                                    <td class="text-center py-3 pe-3" onclick="event.stopPropagation();">
                                        <div class="d-flex justify-content-center gap-2">
                                            <a href="revenue_customer_edit.php?id=<?= (int) $c['id'] ?><?= htmlspecialchars($moduleQs) ?>" class="btn btn-sm btn-outline-primary border-gray-300 rounded-md" title="Edit">
                                                <i class="fas fa-pencil-alt"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger border-gray-300 rounded-md" title="Delete"
                                                    onclick="deleteCustomer(<?= (int) $c['id'] ?>, <?= json_encode($c['customer_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-16 px-4 text-gray-500">
                                    <i class="fas fa-users text-gray-300 mb-3 d-block" style="font-size: 3rem;"></i>
                                    <p class="text-gray-700 text-lg font-medium mb-1">No customers yet</p>
                                    <p class="text-gray-500 text-base mb-3">Add a customer to use on invoices and revenue.</p>
                                    <a href="revenue_customer_create.php<?= htmlspecialchars($moduleQs) ?>" class="btn mov-btn-primary rounded-md px-4 border-0">
                                        <i class="fas fa-plus me-2"></i>Add customer
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php if ($success !== ''): ?>
<script>
(function () {
    var sheet = document.getElementById('revenueCustomersSuccessSheet');
    var backdrop = document.getElementById('revenueCustomersSuccessBackdrop');
    var btn = document.getElementById('revenueCustomersSuccessDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('revenue-customers-success-sheet-open');
        requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
            sheet.classList.add('is-visible');
        });
        window.clearTimeout(autoTimer);
        autoTimer = window.setTimeout(closeSheet, 6000);
    }

    function closeSheet() {
        window.clearTimeout(autoTimer);
        backdrop.classList.remove('is-visible');
        sheet.classList.remove('is-visible');
        document.body.classList.remove('revenue-customers-success-sheet-open');
        window.setTimeout(function () {
            if (!sheet.classList.contains('is-visible')) {
                sheet.setAttribute('aria-hidden', 'true');
            }
        }, 350);
        try {
            if (window.history && window.history.replaceState) {
                var u = new URL(window.location.href);
                u.searchParams.delete('success');
                window.history.replaceState({}, '', u.pathname + u.search + u.hash);
            }
        } catch (e) { /* ignore */ }
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
        if (!e.matches) closeSheet();
    });

    if (btn) btn.addEventListener('click', closeSheet);
    backdrop.addEventListener('click', closeSheet);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-visible')) closeSheet();
    });
})();
</script>
<?php endif; ?>

<script>
function deleteCustomer(id, name) {
    if (typeof confirmAction === 'function') {
        confirmAction(
            'Delete Customer?',
            'Are you sure you want to delete "' + name + '"? This will not affect existing transactions but the name will no longer be available for new sales.',
            'Yes, Delete It',
            function() {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'revenue_customer_process.php';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_customer';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id';
                idInput.value = id;
                form.appendChild(idInput);

                document.body.appendChild(form);
                form.submit();
            }
        );
    } else {
        if (confirm('Are you sure you want to delete this customer?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'revenue_customer_process.php';
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_customer';
            form.appendChild(actionInput);
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
    }
}
</script>

<?php if ($success !== ''): ?>
<script>
if (typeof Swal !== 'undefined' && window.matchMedia('(min-width: 768px)').matches) {
    Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: function (toast) {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    }).fire({
        icon: 'success',
        title: <?= json_encode($success, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
    });
}
</script>
<?php endif; ?>

</body>
</html>
