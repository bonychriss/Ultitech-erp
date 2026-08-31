<?php
require_once '../../includes/functions.php';

global $pdo;
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM erp_products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    die("Product not found");
}

// Get categories
$categories = $pdo->query("SELECT * FROM erp_categories ORDER BY name")->fetchAll();

// Get suppliers
$suppliers = $pdo->query("SELECT * FROM erp_suppliers ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #64748b;
            --background-color: #f4f6f9;
            --card-bg: #ffffff;
            --border-color: #dee2e6;
            --text-color: #334155;
            --danger-color: #dc2626;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: var(--background-color);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            color: var(--text-color);
        }

        /* Override global container styles */
        .container {
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .page-wrapper {
            margin-left: 220px !important;
            min-height: 100vh;
            padding: 24px 30px !important;
            /* Increased gap */
            width: auto !important;
        }

        @media (max-width: 768px) {
            .page-wrapper {
                margin-left: 0 !important;
                padding: 16px !important;
            }
        }

        /* Modern Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .header-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        /* Modern Card */
        .card-container {
            width: 100%;
            max-width: 1000px;
        }

        .modern-card {
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 30px;
        }

        .section-header {
            margin-bottom: 24px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
        }

        .section-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }

        /* Grid Layout */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        /* Labels and Inputs */
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #64748b;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        input[type="text"],
        input[type="number"],
        input[type="email"],
        select,
        textarea {
            width: 100%;
            height: 45px;
            padding: 0 16px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            color: #1e293b;
            transition: all 0.2s ease;
            background: #fff;
        }

        textarea {
            height: auto;
            padding: 12px 16px;
            line-height: 1.5;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        input::placeholder,
        textarea::placeholder {
            color: #94a3b8;
        }

        /* Input Group (for Category + Manage) */
        .input-group {
            display: flex;
            gap: 10px;
        }

        .btn-icon {
            flex-shrink: 0;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            border-radius: 6px;
            color: var(--primary-color);
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .btn-icon:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
        }

        /* Buttons */
        .btn {
            height: 40px;
            padding: 0 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            box-shadow: 0 2px 5px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: transparent;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            color: #334155;
            border-color: #94a3b8;
        }

        .btn-danger {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .btn-danger:hover {
            background: #fecaca;
            color: #b91c1c;
        }

        /* Alerts */
        .alert {
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-size: 14px;
            display: none;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Custom File Upload */
        .file-upload-wrapper {
            position: relative;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8fafc;
        }

        .file-upload-wrapper:hover,
        .file-upload-wrapper.highlight {
            border-color: var(--primary-color);
            background: #eff6ff;
        }

        .upload-icon {
            margin-bottom: 12px;
        }

        .upload-text {
            font-size: 14px;
            font-weight: 500;
            color: #334155;
            margin-bottom: 4px;
        }

        .upload-hint {
            font-size: 12px;
            color: #94a3b8;
        }

        .browser-link {
            color: var(--primary-color);
            text-decoration: underline;
        }

        .preview-container {
            margin-top: 20px;
            position: relative;
            display: inline-block;
        }

        #imagePreview {
            max-width: 100%;
            max-height: 200px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .remove-file {
            position: absolute;
            top: -10px;
            right: -10px;
            width: 24px;
            height: 24px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* Current Image Display */
        .current-image {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
            padding: 12px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }

        .current-image img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
        }

        .current-image-info h4 {
            font-size: 14px;
            margin-bottom: 2px;
            color: #1e293b;
        }

        .current-image-info p {
            font-size: 12px;
            color: #64748b;
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="page-wrapper">
        <form id="editProductForm" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= $product['id'] ?>">

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-title">
                    <h1>Edit Product</h1>
                </div>
                <div class="header-actions">
                    <a href="list.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Update Product</button>
                </div>
            </div>

            <!-- Content Card -->
            <div class="card-container">
                <div class="modern-card">
                    <div id="alertMessage" class="alert"></div>

                    <!-- Product Details Section -->
                    <div class="section-header">
                        <h2>Product Details</h2>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>SKU (Stock Keeping Unit)</label>
                            <input type="text" name="sku" value="<?= htmlspecialchars($product['sku']) ?>" readonly
                                style="background-color: #f8f9fa; color: #6c757d; cursor: not-allowed;">
                        </div>

                        <div class="form-group">
                            <label>Product Name <span style="color:red">*</span></label>
                            <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Category</label>
                            <div class="input-group">
                                <select name="category_id">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= $product['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <a href="categories.php" class="btn-icon" title="Manage Categories">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <line x1="12" y1="5" x2="12" y2="19"></line>
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                    </svg>
                                </a>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Unit</label>
                            <select name="unit">
                                <option value="pcs" <?= $product['unit'] == 'pcs' ? 'selected' : '' ?>>Pieces (pcs)
                                </option>
                                <option value="kg" <?= $product['unit'] == 'kg' ? 'selected' : '' ?>>Kilograms (kg)
                                </option>
                                <option value="m" <?= $product['unit'] == 'm' ? 'selected' : '' ?>>Meters (m)</option>
                                <option value="box" <?= $product['unit'] == 'box' ? 'selected' : '' ?>>Box</option>
                                <option value="service" <?= $product['unit'] == 'service' ? 'selected' : '' ?>>Service (No
                                    Stock)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="active" <?= ($product['status'] ?? 'active') == 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($product['status'] ?? 'active') == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description"
                                rows="5"><?= htmlspecialchars($product['description']) ?></textarea>
                        </div>

                        <!-- File Upload -->
                        <div class="form-group full-width">
                            <label>Product Image</label>

                            <?php if (!empty($product['image_path'])): ?>
                                <div class="current-image">
                                    <img src="../../<?= htmlspecialchars($product['image_path']) ?>"
                                        alt="Current Product Image">
                                    <div class="current-image-info">
                                        <h4>Current Image</h4>
                                        <p>Upload a new image below to replace this one.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="file-upload-wrapper" id="dropZone">
                                <input type="file" name="image" id="fileInput" accept="image/*" hidden>
                                <div class="upload-content">
                                    <div class="upload-icon">
                                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#9ca3af"
                                            stroke-width="2">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="17 8 12 3 7 8"></polyline>
                                            <line x1="12" y1="3" x2="12" y2="15"></line>
                                        </svg>
                                    </div>
                                    <p class="upload-text">Drag and drop new image here or <span
                                            class="browser-link">browse</span></p>
                                    <p class="upload-hint">JPG, PNG, GIF, or WEBP. Max 2MB.</p>
                                </div>
                                <div id="previewContainer" class="preview-container" style="display: none;">
                                    <img id="imagePreview" src="" alt="Preview">
                                    <button type="button" id="removeFileCb" class="remove-file">&times;</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing & Inventory Section -->
                    <div class="section-header" style="margin-top: 40px;">
                        <h2>Pricing & Inventory</h2>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Selling Price <span style="color:red">*</span></label>
                            <input type="number" name="unit_price" value="<?= $product['unit_price'] ?>" required
                                step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label>Cost Price</label>
                            <input type="number" name="cost_price" value="<?= $product['cost_price'] ?>" step="0.01"
                                min="0">
                        </div>

                        <div class="form-group">
                            <label>Current Stock</label>
                            <input type="number" name="stock_quantity" value="<?= $product['stock_quantity'] ?>"
                                step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label>Reorder Level</label>
                            <input type="number" name="reorder_level" value="<?= $product['reorder_level'] ?>"
                                step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label>Supplier</label>
                            <select name="supplier_id">
                                <option value="">Select Primary Supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= $sup['id'] ?>" <?= isset($product['supplier_id']) && $product['supplier_id'] == $sup['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sup['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Barcode</label>
                            <input type="text" name="barcode" value="<?= htmlspecialchars($product['barcode'] ?? '') ?>"
                                placeholder="Scan barcode here...">
                        </div>
                    </div>

                    <!-- Danger Zone -->
                    <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                        <button type="button" class="btn btn-danger"
                            onclick="deleteProduct(<?= $product['id'] ?>)">Delete Product</button>
                        <span style="margin-left: 12px; color: #64748b; font-size: 13px;">Cannot be undone.</span>
                    </div>

                </div>
            </div>
        </form>

        <script>
            // File Upload Logic
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('fileInput');
            const previewContainer = document.getElementById('previewContainer');
            const imagePreview = document.getElementById('imagePreview');
            const uploadContent = document.querySelector('.upload-content');
            const removeBtn = document.getElementById('removeFileCb');

            // Trigger file input click
            dropZone.addEventListener('click', (e) => {
                if (e.target !== removeBtn) fileInput.click();
            });

            // Handle Drag & Drop
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => dropZone.classList.add('highlight'), false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => dropZone.classList.remove('highlight'), false);
            });

            dropZone.addEventListener('drop', handleDrop, false);

            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length) {
                    handleFiles(files);
                }
            }

            fileInput.addEventListener('change', function () {
                if (this.files.length) {
                    handleFiles(this.files);
                }
            });

            function handleFiles(files) {
                const file = files[0];
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        imagePreview.src = e.target.result;
                        uploadContent.style.display = 'none';
                        previewContainer.style.display = 'block';

                        // Store file in a property for the submit handler if dropped
                        if (fileInput.files[0] !== file) {
                            dropZone.droppedFile = file;
                        }
                    }
                    reader.readAsDataURL(file);
                }
            }

            removeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                fileInput.value = '';
                delete dropZone.droppedFile;
                uploadContent.style.display = 'block';
                previewContainer.style.display = 'none';
            });

            // Form Submit Logic
            document.getElementById('editProductForm').addEventListener('submit', async function (e) {
                e.preventDefault();

                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Saving...';

                // const alert = document.getElementById('alertMessage');
                // alert.style.display = 'none';

                try {
                    const formData = new FormData(this);
                    formData.append('action', 'update');

                    // If we have a dropped file that isn't in the input
                    if (dropZone.droppedFile) {
                        formData.set('image', dropZone.droppedFile);
                    }

                    const response = await fetch('../api/products.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast('success', 'Product updated successfully!');
                        setTimeout(() => window.location.href = 'list.php', 1500);
                    } else {
                        throw new Error(result.message || 'Failed to update product');
                    }
                } catch (error) {
                    showToast('error', error.message);
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            });

            function deleteProduct(id) {
                confirmAction('Are you sure?', 'This will permanently delete the product.', 'Yes, delete it!', async () => {
                    try {
                        const formData = new FormData();
                        formData.append('action', 'delete');
                        formData.append('id', id);

                        const response = await fetch('../api/products.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            showToast('success', 'Product deleted successfully');
                            setTimeout(() => window.location.href = 'list.php', 1000);
                        } else {
                            showToast('error', 'Failed to delete: ' + result.message);
                        }
                    } catch (error) {
                        showToast('error', 'Error: ' + error.message);
                    }
                });
            }
        </script>
    </div>
</body>

</html>