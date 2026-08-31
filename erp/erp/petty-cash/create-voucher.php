<?php
require_once '../../includes/functions.php';
requireFinanceOrAdmin(); // Only finance users and admins can access
ensurePettyCashSchema();

global $pdo;
$user_id = $_SESSION['user_id'] ?? 0;

// Get categories
$categories = getPettyCashCategories();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'date' => $_POST['date'],
        'custodian_id' => $user_id,
        'category' => $_POST['category'],
        'description' => $_POST['description'],
        'amount' => (float)$_POST['amount'],
        'created_by' => $user_id
    ];
    
    // Handle receipt upload
    if (!empty($_FILES['receipt']['name'])) {
        $upload_dir = '../../assets/uploads/petty-cash/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0775, true);
        }
        
        $file_ext = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
        $file_name = 'receipt_' . time() . '_' . uniqid() . '.' . $file_ext;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $file_path)) {
            $data['receipt_path'] = 'assets/uploads/petty-cash/' . $file_name;
        }
    }
    
    // Create voucher
    $voucher_id = createPettyCashVoucher($data);
    
    if ($voucher_id) {
        header('Location: index.php?success=1');
        exit;
    } else {
        $error = 'Failed to create voucher. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Petty Cash Voucher</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Shared Dashboard Form Styles */
        body { background: #f3f4f6; }
        .main-content { padding: 20px; max-width: 900px; margin: 0 auto; }
        
        /* Form Container */
        .form-container {
            background: transparent;
            padding: 0;
            box-shadow: none;
            max-width: 100%;
        }
        
        .page-header {
            margin-bottom: 24px;
        }
        .page-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        .page-header p {
            color: #6b7280;
            font-size: 13px;
            margin-top: 4px;
        }

        /* Form Layout */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            color: #111827;
            background: white;
            transition: all 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group small {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: #6b7280;
        }
        
        /* File Upload */
        .file-upload {
            background: white;
            border: 2px dashed #e5e7eb;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .file-upload:hover {
            border-color: #2563eb;
            background: #f8fafc;
        }
        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .upload-icon {
            width: 32px;
            height: 32px;
            color: #9ca3af;
        }

        /* Buttons */
        .actions-bar {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 12px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #2563eb;
            color: white;
            border: 1px solid transparent;
        }
        .btn-primary:hover { background: #1d4ed8; }
        
        .btn-secondary {
            background: white;
            border: 1px solid #d1d5db;
            color: #374151;
        }
        .btn-secondary:hover { background: #f9fafb; border-color: #9ca3af; }

        @media (max-width: 640px) {
            .form-row { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
</head>
<body class="dashboard">
    <?php 
    $logoBase = '../../';
    include '../../includes/header_employee.php'; 
    ?>
    
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header" style="margin-bottom: 2rem;">
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem;">Create Voucher</h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Fill in the details below to submit a new petty cash expense.</p>
        </div>
        
        <div class="form-container" style="background: white; padding: 2rem; border: 1px solid var(--border-color); box-shadow: var(--card-shadow);">
            <?php if (isset($error)): ?>
                <div class="alert alert-error" style="background: #fee2e2; color: #b91c1c; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
                    <svg style="width: 18px; height: 18px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-row">
                    <!-- Date -->
                    <div class="form-group">
                        <label>Date <span style="color: #ef4444">*</span></label>
                        <input type="date" name="date" value="<?= date('Y-m-d') ?>" required max="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <!-- Category -->
                    <div class="form-group">
                        <label>Category <span style="color: #ef4444">*</span></label>
                        <select name="category" required>
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <!-- Amount -->
                    <div class="form-group">
                        <label>Amount (TSh) <span style="color: #ef4444">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00">
                    </div>

                    <!-- Receipt Upload (Small) -->
                     <div class="form-group">
                        <label>Receipt (Optional)</label>
                        <input type="file" name="receipt" accept="image/*,.pdf" style="padding: 7px;">
                        <small>Images or PDF, max 5MB</small>
                    </div>
                </div>
                
                <!-- Description -->
                <div class="form-group">
                    <label>Description <span style="color: #ef4444">*</span></label>
                    <textarea name="description" required placeholder="Describe the expense details..."></textarea>
                </div>
                
                <!-- Actions -->
                <div class="actions-bar">
                    <button type="submit" class="btn btn-primary">
                        Submit Voucher
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        // Show selected file name
        document.getElementById('receipt').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            const fileNameEl = document.getElementById('file-name');
            if (fileName) {
                fileNameEl.textContent = 'ðŸ“Ž ' + fileName;
            } else {
                fileNameEl.textContent = '';
            }
        });
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const amount = parseFloat(document.querySelector('input[name="amount"]').value);
            if (amount <= 0) {
                e.preventDefault();
                alert('Amount must be greater than zero');
                return false;
            }
        });
    </script>
</body>
</html>

