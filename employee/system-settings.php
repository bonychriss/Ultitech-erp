<?php
require_once '../includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$feedback = '';

// Handle form submission (e.g. changing theme, notification preferences, etc)
// (Theme logic removed per user request)

$current_theme = $_SESSION['theme'] ?? 'theme-minimal';
$initial = strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1));
$user = $pdo->query("SELECT * FROM users WHERE id = " . (int)$userId)->fetch();
$dashboardUrl = isAdmin() ? '../admin/dashboard.php' : 'dashboard.php';

// --- Sales Settings Logic ---
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sales_settings'])) {
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
            // Upload to main assets/images folder so it's accessible everywhere
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
        $sql = "UPDATE sales_settings SET company_name = ?, company_address = ?, company_tin = ?, company_vat = ?, company_phone = ?, company_email = ?, company_website = ?, bank_details = ?, default_currency = ?, include_catalogue = ? $logo_sql WHERE id = 1";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
             $success_msg = "Sales settings updated successfully!";
             $feedback = "Sales settings updated successfully!";
        } else {
             $error_msg = "Database update failed.";
             $feedback = "Database update failed.";
        }
    } else {
        $feedback = $error_msg;
    }
}

// Fetch Current Settings
$settings = $pdo->query("SELECT * FROM sales_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

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
	<title>System Settings - <?= htmlspecialchars($user['full_name']) ?></title>
	<link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
	<style>
        /* Global Overrides */
        body {
            background-color: #f4f3ee !important; /* Creamy grey background */
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }

        .hover-arrow {
            transition: transform 0.2s ease;
        }
        .hover-arrow:hover {
            transform: translateX(-4px);
        }
    </style>
	<!-- FontAwesome & Bootstrap Icons -->
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
	
	<!-- SweetAlert2 -->
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="dashboard">

	<!-- Header Include -->
	<?php 
	$active_module = 'account';
	require_once '../includes/header_employee.php'; 
	?>

	<!-- Main Content Wrapper -->
	<div class="container-fluid p-0">
		<!-- Header -->
		<div class="d-flex justify-content-between align-items-center mb-4 pt-3 px-3">
			<div>
				<a href="employee/account.php?module=account" class="text-decoration-none text-muted mb-2 d-inline-block hover-arrow">
					<i class="fas fa-arrow-left me-1"></i> Back to Profile
				</a>
				<h2 class="fw-bold mb-0 text-dark" style="font-family: 'Inter', sans-serif;">System Settings</h2>
			</div>
			<a href="../logout.php" class="btn btn-danger btn-sm d-flex align-items-center gap-2 px-3">
				<i class="fas fa-sign-out-alt"></i> Logout
			</a>
		</div>

		<?php if (!empty($feedback)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer)
                            toast.addEventListener('mouseleave', Swal.resumeTimer)
                        }
                    });

                    Toast.fire({
                        icon: '<?= strpos(strtolower($feedback), "success") !== false ? "success" : "error" ?>',
                        title: '<?= htmlspecialchars($feedback) ?>'
                    });
                });
            </script>
        <?php endif; ?>

		<div class="row g-4">

        <!-- Sales / Company Settings Form -->
        <div class="row g-4 mt-2 mb-5">
            <div class="col-lg-12">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="save_sales_settings" value="1">
                    <div class="row">
                        <!-- Left Column: Primary Settings -->
                        <div class="col-xl-8 col-lg-7">
                            <div class="card shadow-sm border-0 rounded-4 mb-4">
                                <div class="card-header bg-white pt-4 px-4 pb-0 border-bottom-0">
                                    <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-building me-2"></i>Company Profile (Sales Documents)</h5>
                                </div>
                                <div class="card-body p-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Company Name</label>
                                        <input type="text" name="company_name" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($settings['company_name']); ?>" required>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">TIN Number</label>
                                            <input type="text" name="company_tin" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($settings['company_tin'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">VAT Number</label>
                                            <input type="text" name="company_vat" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($settings['company_vat'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Phone</label>
                                            <input type="text" name="company_phone" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Email</label>
                                            <input type="email" name="company_email" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Website</label>
                                        <input type="text" name="company_website" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($settings['company_website'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Address</label>
                                        <textarea name="company_address" class="form-control bg-light border-0" rows="4" required><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea>
                                        <div class="form-text">This text will appear below the company name on documents. Use Enter for new lines.</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Bank Details (Footer)</label>
                                        <textarea name="bank_details" class="form-control bg-light border-0" rows="4"><?php echo htmlspecialchars($settings['bank_details'] ?? ''); ?></textarea>
                                        <div class="form-text">Enter bank name, account number, SWIFT code, etc. This will appear at the bottom left of quotes and invoices.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column: Secondary Settings -->
                        <div class="col-xl-4 col-lg-5">
                            <div class="card shadow-sm border-0 rounded-4 mb-4">
                                <div class="card-header bg-white pt-4 px-4 pb-0 border-bottom-0">
                                    <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-paint-brush me-2"></i>Branding & Appearance</h5>
                                </div>
                                <div class="card-body p-4">
                                    <div class="text-center mb-4">
                                        <label class="form-label d-block fw-bold">Current Logo</label>
                                        <?php 
                                            $logoPath = '../assets/images/' . ($settings['company_logo'] ?: 'Untitled.jpg');
                                        ?>
                                        <img src="<?php echo $logoPath; ?>" alt="Company Logo" class="img-thumbnail border-0 shadow-sm mb-2" style="max-height: 120px;">
                                        <div class="small text-muted">Displayed on Quotes & Invoices</div>
                                    </div>
                                    
                                    <hr class="text-muted opacity-25">

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Upload New Logo</label>
                                        <input type="file" name="company_logo" class="form-control bg-light border-0">
                                        <div class="form-text">For best results use a transparent PNG or white background JPG.</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Default Currency</label>
                                        <input type="text" name="default_currency" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($settings['default_currency'] ?? 'TZS'); ?>" placeholder="e.g. TZS, USD">
                                        <div class="form-text">Used on Quotes & Invoices</div>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm border-0 rounded-4 mb-4">
                                <div class="card-header bg-white pt-4 px-4 pb-0 border-bottom-0">
                                    <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-file-pdf me-2"></i>Document Settings</h5>
                                </div>
                                <div class="card-body p-4">
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

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-2">
                        <button type="submit" class="btn btn-primary px-5 btn-lg shadow-sm rounded-pill">
                            <i class="fas fa-save me-2"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
	</div>

	<!-- Bootstrap JS -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
