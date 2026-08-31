<?php
require_once '../../includes/functions.php';
requireLogin();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 800px; margin: 24px auto; padding: 0 24px; }
        
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-body { padding: 24px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full-width { grid-column: span 2; }
        
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; transition: border 0.2s; }
        input:focus, select:focus, textarea:focus { border-color: #1a73e8; outline: none; }
        
        .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; margin-right: 10px; }
        .btn-danger { background: #dc3545; color: white; float: left; }
        
        .section-title { font-size: 1rem; font-weight: 500; color: #202124; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid #f1f3f4; grid-column: span 2; margin-top: 8px; }
        
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; display: none; }
        .alert-success { background: #e6f4ea; color: #137333; }
        .alert-error { background: #fce8e6; color: #c5221f; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Edit Product</h1>
        <a href="list.php" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-body">
                <div id="alertMessage" class="alert"></div>
                
                <form id="editProductForm">
                    <input type="hidden" name="id" value="<?= $product['id'] ?>">
                    
                    <div class="form-grid">
                        <div class="section-title">Product Details</div>
                        
                        <div class="form-group">
                            <label>SKU (Stock Keeping Unit)</label>
                            <input type="text" name="sku" value="<?= htmlspecialchars($product['sku']) ?>" readonly style="background: #f8f9fa;">
                        </div>
                        
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $product['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Unit</label>
                            <select name="unit">
                                <option value="pcs" <?= $product['unit'] == 'pcs' ? 'selected' : '' ?>>Pieces (pcs)</option>
                                <option value="kg" <?= $product['unit'] == 'kg' ? 'selected' : '' ?>>Kilograms (kg)</option>
                                <option value="m" <?= $product['unit'] == 'm' ? 'selected' : '' ?>>Meters (m)</option>
                                <option value="box" <?= $product['unit'] == 'box' ? 'selected' : '' ?>>Box</option>
                                <option value="service" <?= $product['unit'] == 'service' ? 'selected' : '' ?>>Service (No Stock)</option>
                            </select>
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" rows="3"><?= htmlspecialchars($product['description']) ?></textarea>
                        </div>
                        
                        <div class="section-title">Pricing & Inventory</div>
                        
                        <div class="form-group">
                            <label>Selling Price *</label>
                            <input type="number" name="unit_price" value="<?= $product['unit_price'] ?>" required step="0.01" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label>Cost Price</label>
                            <input type="number" name="cost_price" value="<?= $product['cost_price'] ?>" step="0.01" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label>Current Stock</label>
                            <input type="number" name="stock_quantity" value="<?= $product['stock_quantity'] ?>" step="0.01" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label>Reorder Level</label>
                            <input type="number" name="reorder_level" value="<?= $product['reorder_level'] ?>" step="0.01" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="active" <?= $product['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $product['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Barcode</label>
                            <input type="text" name="barcode" value="<?= htmlspecialchars($product['barcode']) ?>">
                        </div>
                    </div>
                    
                    <div style="margin-top: 24px; display: flex; justify-content: space-between;">
                        <button type="button" class="btn btn-danger" onclick="deleteProduct(<?= $product['id'] ?>)">Delete Product</button>
                        <button type="submit" class="btn btn-primary">Update Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('editProductForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'update');
                
                const response = await fetch('../api/products.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Product updated successfully!';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'list.php', 1500);
                } else {
                    throw new Error(result.message || 'Failed to update product');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
        
        async function deleteProduct(id) {
            if (!confirm('Are you sure you want to delete this product? This action cannot be undone.')) return;
            
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
                    window.location.href = 'list.php';
                } else {
                    alert('Failed to delete: ' + result.message);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }
    </script>
</body>
</html>

