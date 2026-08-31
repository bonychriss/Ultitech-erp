<?php
require_once 'functions.php';
requireLogin();

// Update Settings
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
                WHERE id = 1"; // Assuming single record
        
        // Check if record exists, if not insert
        $chk = $pdo->query("SELECT id FROM company_settings LIMIT 1")->fetch();
        if (!$chk) {
             $sql = "INSERT INTO company_settings (company_name, phone, email, address, city, country, bank_details, terms_and_conditions, currency, default_payment_terms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
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
            $_POST['default_payment_terms']
        ]);
        
        flash('success', 'Company settings updated successfully.');
        redirect('settings.php');
        
    } catch (PDOException $e) {
        flash('error', 'Database Error: ' . $e->getMessage());
    }
}

// Fetch Current Settings
$settings = $pdo->query("SELECT * FROM company_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    // Default empty array if no settings yet
    $settings = [
        'company_name' => '', 'phone' => '', 'email' => '', 'address' => '', 
        'city' => '', 'country' => '', 'bank_details' => '', 'terms_and_conditions' => '',
        'currency' => 'USD', 'default_payment_terms' => 'Net 30'
    ];
}

$page_title = 'Company Settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | <?= COMPANY_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body class="bg-light">
<?php include 'header_admin.php'; ?>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold mb-0">Company Settings</h2>
        </div>
        
        <?php flash('success'); flash('error'); ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow-sm rounded-0">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-building me-2"></i> Organization Details</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Company Name</label>
                                    <input type="text" name="company_name" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Phone Number</label>
                                    <input type="text" name="phone" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Email Address</label>
                                    <input type="email" name="email" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Street Address</label>
                                    <input type="text" name="address" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['address'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-muted">City</label>
                                    <input type="text" name="city" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['city'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Country</label>
                                    <input type="text" name="country" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['country'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-12 mt-4">
                                    <h6 class="fw-bold border-bottom pb-2 mb-3">System Configuration</h6>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Inventory Currency</label>
                                    <select name="currency" class="form-select rounded-0">
                                        <option value="USD" <?php echo ($settings['currency'] ?? 'USD') == 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                        <option value="TZS" <?php echo ($settings['currency'] ?? '') == 'TZS' ? 'selected' : ''; ?>>TZS (TSh)</option>
                                        <option value="EUR" <?php echo ($settings['currency'] ?? '') == 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                                        <option value="GBP" <?php echo ($settings['currency'] ?? '') == 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                                    </select>
                                    <div class="form-text">Used for all Purchase Orders and Reports.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Default Payment Terms</label>
                                    <input type="text" name="default_payment_terms" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['default_payment_terms'] ?? 'Net 30'); ?>" placeholder="e.g. Net 30">
                                </div>
                                
                                <div class="col-12 mt-4">
                                    <h6 class="fw-bold border-bottom pb-2 mb-3">Documents Configuration</h6>
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Bank Details / Footer Notes</label>
                                    <textarea name="bank_details" class="form-control rounded-0" rows="3"><?php echo htmlspecialchars($settings['bank_details'] ?? ''); ?></textarea>
                                    <div class="form-text">This will appear on invoices and POs.</div>
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Terms & Conditions</label>
                                    <textarea name="terms_and_conditions" class="form-control rounded-0" rows="5"><?php echo htmlspecialchars($settings['terms_and_conditions'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="col-12 text-end mt-4">
                                    <button type="submit" class="btn btn-primary px-4 rounded-0">
                                        <i class="fas fa-save me-2"></i> Save Settings
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="alert alert-info rounded-0 border-0 shadow-sm">
                    <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i> Tip</h5>
                    <p class="mb-0 small">
                        These details are used across all generated documents, including Purchase Orders, Invoices, and Quotes.
                        <br><br>
                        <strong>Currency:</strong> This sets the symbol and code used for all financial values in the stock module.
                        <br><br>
                        Ensure the email address is valid as it will be used as the "Reply-To" address for system emails.
                    </p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include 'includes/layout_footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
