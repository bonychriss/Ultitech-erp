<?php
require_once 'includes/functions.php';
requireLogin();

$moduleSlug = isset($_GET['module']) ? trim((string) $_GET['module']) : '';
$moduleSlug = $moduleSlug !== '' ? $moduleSlug : 'revenue';
$moduleQs = '?module=' . rawurlencode($moduleSlug);

$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>New customer â€” Revenue - <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <link href="/assets/css/sales-mobile.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { corePlugins: { preflight: false } };</script>
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
        .rev-cust-form-h {
            background-color: #1c2331;
            color: #fff;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.65rem 1.25rem;
            border-bottom: 2px solid #151a24;
        }
    </style>
</head>
<body class="dashboard dispatch-page">

<?php require_once 'includes/header_employee.php'; ?>

<main class="main-content rev-cust-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="revenue_customers.php<?= htmlspecialchars($moduleQs) ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Customers
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">New customer</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="revenue_entries.php<?= htmlspecialchars($moduleQs) ?>" class="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline d-none d-md-inline-flex">
                    <i class="fas fa-coins text-sm"></i> Revenue
                </a>
                <a href="/select-module.php" class="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline d-none d-lg-inline-flex">
                    <i class="fas fa-th-large text-sm"></i> Modules
                </a>
            </div>
            <div class="px-4 py-2 text-sm sm:text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <i class="fas fa-info-circle text-gray-400 me-1"></i>Add a client for invoices and revenue entries. Name is required.
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger rounded-lg border-0 shadow-sm mb-4" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden mx-auto" style="max-width: 56rem;">
                <div class="rev-cust-form-h"><i class="fas fa-user-plus me-2 opacity-80"></i>Customer details</div>
                <div class="p-4 p-lg-5">
                    <form action="revenue_customer_process.php" method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="create_customer">
                        <input type="hidden" name="module" value="<?= htmlspecialchars($moduleSlug) ?>">

                        <div class="mb-3">
                            <label for="customer_name" class="form-label fw-semibold text-gray-700">Full name / company <span class="text-danger">*</span></label>
                            <input type="text" name="customer_name" id="customer_name" class="form-control rounded-md border-gray-300" required maxlength="255" placeholder="e.g. John Doe or ABC Corp" autocomplete="organization">
                            <div class="invalid-feedback">Please enter a customer name.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label fw-semibold text-gray-700">Phone</label>
                                <input type="text" name="phone" id="phone" class="form-control rounded-md border-gray-300" placeholder="+255â€¦" autocomplete="tel">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label fw-semibold text-gray-700">Email</label>
                                <input type="email" name="email" id="email" class="form-control rounded-md border-gray-300" placeholder="customer@example.com" autocomplete="email">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="address" class="form-label fw-semibold text-gray-700">Address / location</label>
                            <textarea name="address" id="address" rows="3" class="form-control rounded-md border-gray-300" placeholder="Physical addressâ€¦"></textarea>
                        </div>

                        <div class="d-flex flex-wrap gap-2 pt-2 border-top border-gray-100">
                            <button type="submit" class="btn mov-btn-primary rounded-md px-4 py-2 fw-semibold border-0">
                                <i class="fas fa-save me-2"></i>Create customer
                            </button>
                            <a href="revenue_customers.php<?= htmlspecialchars($moduleQs) ?>" class="btn btn-outline-secondary rounded-md px-4 py-2">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    var form = document.querySelector('form.needs-validation');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        form.classList.add('was-validated');
    }, false);
})();
</script>

</body>
</html>
