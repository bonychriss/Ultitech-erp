<?php
require_once 'config/database.php';
require_once 'config/functions.php';
requireLogin();

ensureStockCompanySettingsTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sql = "UPDATE company_settings SET 
                company_name = ?, 
                phone = ?, 
                email = ?, 
                address = ?, 
                city = ?, 
                country = ?, 
                bank_details = ?, 
                terms_and_conditions = ?,
                currency = ?,
                default_payment_terms = ?
                WHERE id = 1";

        $chk = $pdo->query('SELECT id FROM company_settings LIMIT 1')->fetch();
        if (!$chk) {
            $sql = 'INSERT INTO company_settings (id, company_name, phone, email, address, city, country, bank_details, terms_and_conditions, currency, default_payment_terms) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['company_name'],
            $_POST['phone'],
            $_POST['email'],
            $_POST['address'],
            $_POST['city'],
            $_POST['country'],
            $_POST['bank_details'],
            $_POST['terms_and_conditions'],
            $_POST['currency'],
            $_POST['default_payment_terms'],
        ]);

        unset($GLOBALS['company_settings_cache']);

        flash('success', 'Company settings updated successfully.');
        redirect('settings.php');
    } catch (PDOException $e) {
        flash('success', 'Database Error: ' . $e->getMessage(), 'error');
    }
}

unset($GLOBALS['company_settings_cache']);
$settings = getCompanySettings($pdo);

$page_title = 'Company Settings';
include 'includes/header.php';

ob_start();
flash('success');
$flash_markup = trim(ob_get_clean());
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .settings-shell { font-family: 'Outfit', system-ui, -apple-system, sans-serif; font-size: 16px; color: #374151; }
    .settings-btn-primary {
        background-color: #2563EB;
        color: #fff !important;
        border: 1px solid #2563EB;
    }
    .settings-btn-primary:hover {
        background-color: #1D4ED8;
        border-color: #1D4ED8;
        color: #fff !important;
    }
    .settings-btn-outline {
        border: 1px solid #e5e7eb;
        color: #374151;
        background: #fff;
    }
    .settings-btn-outline:hover {
        border-color: #2563EB;
        color: #2563EB;
        background: #eff6ff;
    }
    .settings-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        box-shadow: 0 1px 2px rgb(0 0 0 / 0.04);
    }
    .settings-label { font-weight: 600; font-size: 0.875rem; color: #111827; margin-bottom: 0.375rem; display: block; }
    .settings-aside {
        border: 1px solid #bfdbfe;
        background: linear-gradient(180deg, #eff6ff 0%, #fff 40%);
        border-radius: 0.5rem;
        border-left: 3px solid #2563EB;
    }
</style>

<main class="main-content settings-shell bg-[#F9F9F9] min-h-[50vh] pb-10">
    <div class="max-w-[1200px] mx-auto px-3 sm:px-4">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm mb-4 rounded-b-lg overflow-hidden">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="dashboard.php" class="settings-btn-outline px-3 py-2 rounded-md text-sm font-semibold inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-xs"></i> Stock dashboard
                </a>
                <a href="modules/purchases/index.php" class="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-shopping-cart text-xs"></i> Purchases
                </a>
                <div class="flex flex-col min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 leading-tight">Company settings</h1>
                    <span class="text-sm text-gray-500 font-medium">Stock module · documents &amp; currency</span>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <?php if ($flash_markup !== ''): ?>
            <div class="px-4 py-2 bg-gray-50/80 border-b border-gray-100">
                <?php echo $flash_markup; ?>
            </div>
            <?php endif; ?>
        </div>

        <form method="post" class="space-y-4">
            <div class="row g-4">
                <div class="col-lg-8 space-y-4">
                    <section class="settings-card overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-100 bg-white flex items-center gap-2">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-gray-100 text-gray-600"><i class="fas fa-building"></i></span>
                            <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wide m-0">Organization</h2>
                        </div>
                        <div class="p-4 sm:p-5">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="settings-label" for="company_name">Company name <span class="text-danger">*</span></label>
                                    <input type="text" id="company_name" name="company_name" class="form-control rounded-md border-gray-200" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="settings-label" for="phone">Phone</label>
                                    <input type="text" id="phone" name="phone" class="form-control rounded-md border-gray-200" value="<?php echo htmlspecialchars($settings['phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="settings-label" for="email">Email</label>
                                    <input type="email" id="email" name="email" class="form-control rounded-md border-gray-200" value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="settings-label" for="address">Street address</label>
                                    <input type="text" id="address" name="address" class="form-control rounded-md border-gray-200" value="<?php echo htmlspecialchars($settings['address'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="settings-label" for="city">City</label>
                                    <input type="text" id="city" name="city" class="form-control rounded-md border-gray-200" value="<?php echo htmlspecialchars($settings['city'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="settings-label" for="country">Country</label>
                                    <input type="text" id="country" name="country" class="form-control rounded-md border-gray-200" value="<?php echo htmlspecialchars($settings['country'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-100 bg-white flex items-center gap-2">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-gray-100 text-gray-600"><i class="fas fa-sliders-h"></i></span>
                            <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wide m-0">System</h2>
                        </div>
                        <div class="p-4 sm:p-5">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="settings-label" for="currency">Inventory currency</label>
                                    <select id="currency" name="currency" class="form-select rounded-md border-gray-200">
                                        <option value="USD" <?php echo ($settings['currency'] ?? 'USD') === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                        <option value="TZS" <?php echo ($settings['currency'] ?? '') === 'TZS' ? 'selected' : ''; ?>>TZS (TSh)</option>
                                        <option value="EUR" <?php echo ($settings['currency'] ?? '') === 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                                        <option value="GBP" <?php echo ($settings['currency'] ?? '') === 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                                    </select>
                                    <p class="form-text small text-gray-500 mt-1 mb-0">Used on purchase orders and stock reports.</p>
                                </div>
                                <div class="col-md-6">
                                    <label class="settings-label" for="default_payment_terms">Default payment terms</label>
                                    <input type="text" id="default_payment_terms" name="default_payment_terms" class="form-control rounded-md border-gray-200" value="<?php echo htmlspecialchars($settings['default_payment_terms'] ?? 'Net 30'); ?>" placeholder="e.g. Net 30">
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-100 bg-white flex items-center gap-2">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-gray-100 text-gray-600"><i class="fas fa-file-contract"></i></span>
                            <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wide m-0">Documents</h2>
                        </div>
                        <div class="p-4 sm:p-5">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="settings-label" for="bank_details">Bank details / footer notes</label>
                                    <textarea id="bank_details" name="bank_details" class="form-control rounded-md border-gray-200" rows="3"><?php echo htmlspecialchars($settings['bank_details'] ?? ''); ?></textarea>
                                    <p class="form-text small text-gray-500 mt-1 mb-0">Shown on invoices and purchase documents.</p>
                                </div>
                                <div class="col-12">
                                    <label class="settings-label" for="terms_and_conditions">Terms &amp; conditions</label>
                                    <textarea id="terms_and_conditions" name="terms_and_conditions" class="form-control rounded-md border-gray-200" rows="5"><?php echo htmlspecialchars($settings['terms_and_conditions'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-12 pt-2">
                                    <button type="submit" class="btn settings-btn-primary px-5 py-2.5 rounded-md fw-bold shadow-sm">
                                        <i class="fas fa-save me-2"></i> Save settings
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="col-lg-4">
                    <div class="settings-aside p-4 shadow-sm sticky-top" style="top: 5.5rem;">
                        <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide mb-3 flex items-center gap-2">
                            <i class="fas fa-info-circle text-[#2563EB]"></i> Tips
                        </h3>
                        <ul class="small text-gray-600 ps-3 mb-0" style="list-style: disc;">
                            <li class="mb-2">These details feed generated purchase orders, emails, and other stock documents.</li>
                            <li class="mb-2"><strong class="text-gray-800">Currency</strong> controls display symbols and conversion behaviour where exchange rates apply.</li>
                            <li class="mb-0"><strong class="text-gray-800">Email</strong> should be valid if the system sends supplier messages from this profile.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </form>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
