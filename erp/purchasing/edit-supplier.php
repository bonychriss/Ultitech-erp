<?php
require_once '../../includes/functions.php';

global $pdo;
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM erp_suppliers WHERE id = ?");
$stmt->execute([$id]);
$supplier = $stmt->fetch();

if (!$supplier) {
    die("Supplier not found");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Supplier - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 100%; padding: 24px; }
        
        .page-wrapper {
            margin-left: 220px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0; }
        }
        
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
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><a href="suppliers.php" class="btn btn-secondary">Cancel</a></div>
    
    <div class="container">
        <div class="card">
            <div class="card-body">
                <div id="alertMessage" class="alert"></div>
                
                <form id="editSupplierForm">
                    <input type="hidden" name="id" value="<?= $supplier['id'] ?>">
                    
                    <div class="form-grid">
                        <div class="section-title">Basic Information</div>
                        
                        <div class="form-group">
                            <label>Supplier Code</label>
                            <input type="text" name="supplier_code" value="<?= htmlspecialchars($supplier['supplier_code']) ?>" readonly style="background: #f8f9fa;">
                        </div>
                        
                        <div class="form-group">
                            <label>Company Name *</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($supplier['name']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Contact Person</label>
                            <input type="text" name="contact_person" value="<?= htmlspecialchars($supplier['contact_person']) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($supplier['email']) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($supplier['phone']) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Tax ID / TIN</label>
                            <input type="text" name="tax_id" value="<?= htmlspecialchars($supplier['tax_id']) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="active" <?= $supplier['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $supplier['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="section-title">Address & Terms</div>
                        
                        <div class="form-group full-width">
                            <label>Address</label>
                            <textarea name="address" rows="2"><?= htmlspecialchars($supplier['address']) ?></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Payment Terms</label>
                            <input type="text" name="payment_terms" value="<?= htmlspecialchars($supplier['payment_terms']) ?>">
                        </div>
                    </div>
                    
                    <div style="margin-top: 24px; display: flex; justify-content: space-between;">
                        <button type="button" class="btn btn-danger" onclick="deleteSupplier(<?= $supplier['id'] ?>)">Delete Supplier</button>
                        <button type="submit" class="btn btn-primary">Update Supplier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('editSupplierForm').addEventListener('submit', async function(e) {
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
                
                const response = await fetch('../api/suppliers.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Supplier updated successfully!';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'suppliers.php', 1500);
                } else {
                    throw new Error(result.message || 'Failed to update supplier');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
        
        async function deleteSupplier(id) {
            if (!confirm('Are you sure you want to delete this supplier? This action cannot be undone.')) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);
                
                const response = await fetch('../api/suppliers.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.href = 'suppliers.php';
                } else {
                    alert('Failed to delete: ' + result.message);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }
    </script>
</div>
</body>
</html>

