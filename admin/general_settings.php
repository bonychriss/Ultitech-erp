<?php
require_once '../includes/functions.php';
requireAdmin();

$page_title = "General Settings";
$success_msg = '';
$error_msg = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = clean_input($_POST['company_name']);
    $company_address = clean_input($_POST['company_address']);
    $company_tin = clean_input($_POST['company_tin']);
    $company_vat = clean_input($_POST['company_vat']);
    $company_phone = clean_input($_POST['company_phone']);
    $company_email = clean_input($_POST['company_email']);
    $company_website = clean_input($_POST['company_website']);
    $bank_details = clean_input($_POST['bank_details']);
    $default_currency = clean_input($_POST['default_currency']);
    $include_catalogue = isset($_POST['include_catalogue']) ? 1 : 0;
    
    // Handle Logo Upload
    $logo_sql = "";
    $params = [$company_name, $company_address, $company_tin, $company_vat, $company_phone, $company_email, $company_website, $bank_details, $default_currency, $include_catalogue];
    
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['company_logo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_filename = 'company_logo_' . time() . '.' . $ext;
            // Upload to main assets/images folder
            $upload_path = '../assets/images/' . $new_filename;
            
            if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $upload_path)) {
                $logo_sql = ", company_logo = ?";
                $params[] = $new_filename; 
            } else {
                $error_msg = "Failed to upload logo.";
            }
        } else {
            $error_msg = "Invalid file type. Only JPG, PNG, GIF allowed.";
        }
    }
    
    if (empty($error_msg)) {
        // Find existing settings based on id=1 from the sales_settings table 
        // We will repurpose `sales_settings` as the main global settings table for now
        $sql = "UPDATE sales_settings SET company_name = ?, company_address = ?, company_tin = ?, company_vat = ?, company_phone = ?, company_email = ?, company_website = ?, bank_details = ?, default_currency = ?, include_catalogue = ? $logo_sql WHERE id = 1";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
             $success_msg = "Settings updated successfully!";
        } else {
             $error_msg = "Database update failed.";
        }
    }
}

// Fetch Current Settings
$stmt = $pdo->query("SELECT * FROM sales_settings WHERE id = 1");
$settings = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

// Default values if table empty (fallback)
if (!$settings) {
    $settings = [
        'company_name' => 'Ultimate General Trading Company',
        'company_address' => 'Mikocheni B, Dar es salaam Tanzania',
        'company_logo' => 'Untitled.jpg',
        'company_phone' => '',
        'company_email' => '',
        'include_catalogue' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Settings - <?= COMPANY_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .settings-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 24px;
        }
    </style>
</head>

<body class="dashboard">

    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

    <main class="main-content">
        <div class="header-wrapper" style="margin-bottom: 24px;">
            <div class="header-left">
                <h2>General Settings</h2>
                <p>Manage application-wide branding, company profile, and document templates.</p>
            </div>
            <div class="header-actions">
                <a href="../select-module.php" class="btn btn-secondary btn-sm" style="height: auto; padding: 6px 12px; font-size: 14px; background: transparent; border: 1px solid #ddd; color: #444;">
                    <i class="fas fa-arrow-left"></i> Back to Start
                </a>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?= $success_msg; ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><?= $error_msg; ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            
            <div class="row">
                <!-- Left Column: Primary Settings -->
                <div class="col-xl-8 col-lg-7">
                    <!-- Card 1: Company Profile -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 text-primary" style="color: #3b82f6 !important;"><i class="fas fa-building me-2"></i>Company Profile</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Company Name</label>
                                <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" required>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">TIN Number</label>
                                    <input type="text" name="company_tin" class="form-control" value="<?php echo htmlspecialchars($settings['company_tin'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">VAT Number</label>
                                    <input type="text" name="company_vat" class="form-control" value="<?php echo htmlspecialchars($settings['company_vat'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Phone</label>
                                    <input type="text" name="company_phone" class="form-control" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Email</label>
                                    <input type="email" name="company_email" class="form-control" value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Website</label>
                                <input type="text" name="company_website" class="form-control" value="<?php echo htmlspecialchars($settings['company_website'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Address</label>
                                <textarea name="company_address" class="form-control" rows="4" required><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea>
                                <div class="form-text">This text will appear below the company name on documents. Use Enter for new lines.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Bank Details (Footer)</label>
                                <textarea name="bank_details" class="form-control" rows="4"><?php echo htmlspecialchars($settings['bank_details'] ?? ''); ?></textarea>
                                <div class="form-text">Enter bank name, account number, SWIFT code, etc. This will appear at the bottom left of quotes and invoices.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Secondary Settings -->
                <div class="col-xl-4 col-lg-5">
                    <!-- Card 2: Branding & Appearance -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 text-primary" style="color: #3b82f6 !important;"><i class="fas fa-paint-brush me-2"></i>Branding & Appearance</h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-4">
                                <label class="form-label d-block fw-bold">Current Logo</label>
                                <?php 
                                    $logoFile = !empty($settings['company_logo']) ? $settings['company_logo'] : 'Untitled.jpg';
                                    $logoPath = '../assets/images/' . $logoFile;
                                ?>
                                <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Company Logo" class="img-thumbnail mb-2" style="max-height: 120px;">
                                <div class="small text-muted">Displayed on Profiles & Forms</div>
                            </div>
                            
                            <hr>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Upload New Logo</label>
                                <input type="file" name="company_logo" class="form-control">
                                <div class="form-text">For best results use a transparent PNG or white background JPG.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Default Currency</label>
                                <input type="text" name="default_currency" class="form-control" value="<?php echo htmlspecialchars($settings['default_currency'] ?? 'TZS'); ?>" placeholder="e.g. TZS, USD">
                                <div class="form-text">Used on Quotes & Invoices</div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 3: Document Defaults -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 text-primary" style="color: #3b82f6 !important;"><i class="fas fa-file-pdf me-2"></i>Document Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="include_catalogue" name="include_catalogue" 
                                        <?php echo !empty($settings['include_catalogue']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="include_catalogue">Include Product Catalogue</label>
                                </div>
                                <div class="form-text mt-2">If enabled, a catalogue of all products on the receipt/invoice will be automatically appended to the end of generated PDF documents.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-2 mb-5">
                <button type="submit" class="btn btn-primary px-5 btn-lg shadow-sm" style="background-color: #3b82f6;">
                    <i class="fas fa-save me-2"></i> Save Settings
                </button>
            </div>

        </form>

    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
