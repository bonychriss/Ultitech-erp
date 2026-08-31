<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean_input($_POST['name'] ?? '');
    $contact_person = clean_input($_POST['contact_person'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $address = clean_input($_POST['address'] ?? '');

    if ($name === '') {
        $error = 'Supplier name is required.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO stocks_suppliers (name, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $contact_person, $email, $phone, $address]);
            flash('success', 'Supplier created successfully!');
            redirect('index.php');
        } catch (PDOException $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

$page_title = 'Add Supplier';
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
    .sup-form-card-h {
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

<main class="main-content sup-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Suppliers
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Add supplier</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <i class="fas fa-info-circle text-gray-400 me-1"></i>Fields marked <span class="fw-semibold text-gray-800">*</span> are required.
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger rounded-lg border-0 shadow-sm mb-4" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden mx-auto" style="max-width: 42rem;">
                <div class="sup-form-card-h"><i class="fas fa-truck-loading me-2 opacity-80"></i>Supplier details</div>
                <div class="p-4 p-lg-5">
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold text-gray-700">Supplier name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control rounded-md border-gray-300" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="contact_person" class="form-label fw-semibold text-gray-700">Contact person</label>
                                <input type="text" class="form-control rounded-md border-gray-300" id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($_POST['contact_person'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label fw-semibold text-gray-700">Phone</label>
                                <input type="text" class="form-control rounded-md border-gray-300" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold text-gray-700">Email</label>
                            <input type="email" class="form-control rounded-md border-gray-300" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label for="address" class="form-label fw-semibold text-gray-700">Address</label>
                            <textarea class="form-control rounded-md border-gray-300" id="address" name="address" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                        </div>
                        <div class="d-flex flex-wrap gap-2 pt-2 border-top border-gray-100">
                            <button type="submit" class="btn sup-btn-primary rounded-md px-4 py-2 fw-semibold border-0">
                                <i class="fas fa-save me-2"></i>Save supplier
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary rounded-md px-4 py-2">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
