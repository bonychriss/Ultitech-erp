<?php
require_once '../../includes/functions.php';

// Generate next customer code
global $pdo;
$stmt = $pdo->query("SELECT customer_code FROM erp_customers ORDER BY id DESC LIMIT 1");
$lastCode = $stmt->fetchColumn();

if ($lastCode) {
    $num = intval(substr($lastCode, 5)) + 1;
    $nextCode = 'CUST-' . str_pad($num, 4, '0', STR_PAD_LEFT);
} else {
    $nextCode = 'CUST-0001';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Customer - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
    <style>
        :root {
            --primary-color: #2c3e50;
            --accent-color: #1a73e8;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            background: #f5f5f5; 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            color: #374151;
        }
        
        /* Fix 1: Left Gap & Fluid Layout */
        .page-wrapper {
            margin-left: 220px !important;
            width: calc(100% - 220px) !important;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* RENAMED to bypass Sidebar overrides */
        .custom-header { 
            background: #fff; 
            border-bottom: 1px solid #e0e0e0; 
            padding: 16px 15px;
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            position: sticky;
            top: 0; 
            z-index: 1000; 
        }
        
        .header-title-group h1 { 
            font-size: 1.5rem; 
            font-weight: 600; 
            color: #2c3e50;
            margin: 0;
            line-height: 1.2;
            display: block;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
            margin: 0;
        }
        
        .btn { 
            padding: 10px 20px; 
            border-radius: 6px; 
            text-decoration: none; 
            font-size: 0.875rem; 
            font-weight: 500; 
            cursor: pointer; 
            border: 1px solid transparent; 
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary { 
            background: var(--accent-color); 
            color: white; 
            border-color: var(--accent-color);
        }
        .btn-primary:hover { background: #1557b0; }
        
        .btn-secondary { 
            background: #fff; 
            color: #374151; 
            border-color: #d1d5db; 
        }
        .btn-secondary:hover { background: #f3f4f6; border-color: #9ca3af; }
        
        /* RENAMED to bypass Sidebar overrides */
        .custom-container { 
            width: 100%; 
            padding: 15px 15px 15px 10px; /* Small 10px gap */
            max-width: none;
            flex: 1;
        }

        .card { 
            background: white; 
            border-radius: 8px; 
            border: 1px solid #e0e0e0; 
            overflow: visible; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            max-width: 1200px;
            margin: 0;
            width: 100%;
        }
        .card-body { padding: 15px; } /* Tight padding */
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 0; }
        .form-group.full-width { grid-column: span 2; }
        
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #374151; font-size: 0.9rem; }
        
        .input-group { position: relative; }
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 0.9rem;
        }
        
        input, select, textarea { 
            width: 100%; 
            padding: 10px 12px; 
            border: 1px solid #d1d5db; 
            border-radius: 6px; 
            font-size: 0.9rem; 
            transition: border 0.2s, box-shadow 0.2s; 
            color: #111827;
            font-family: inherit;
        }
        input:focus, select:focus, textarea:focus { 
            border-color: var(--accent-color); 
            outline: none; 
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);    
        }
        
        input[readonly] {
            background-color: #f3f4f6;
            color: #6b7280;
            border-color: #e5e7eb;
            cursor: not-allowed;
        }
        
        .input-with-icon { padding-left: 36px; }
        
        /* Fix 3: Section Separation */
        .section-title { 
            grid-column: span 2;
            font-size: 1.1rem; 
            font-weight: 600; 
            color: #111827; 
            margin-bottom: 24px; 
            padding-bottom: 12px; 
            border-bottom: 1px solid #e5e7eb; 
            margin-top: 8px;
        }
        
        .section-title.mt-large { margin-top: 40px; }
        
        .helper-text {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 24px; display: none; font-size: 0.9rem; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        
        input[type="number"] { -moz-appearance: textfield; }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0 !important; width: 100% !important; }
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
            .custom-header { padding: 16px; position: static; }
            .custom-container { padding: 16px !important; }
        }
    </style>

<div class="page-wrapper">
    <!-- Renamed to custom-header -->
    <div class="custom-header">
        <div class="header-title-group">
            <h1>Add New Customer</h1>
        </div>
        <div class="header-actions">
            <a href="list.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" form="createCustomerForm" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Customer
            </button>
        </div>
    </div>
    
    <!-- Renamed to custom-container -->
    <div class="custom-container">
        <div class="card">
            <div class="card-body">
                <div id="alertMessage" class="alert"></div>
                
                <form id="createCustomerForm">
                    <div class="form-grid">
                        <div class="section-title">Basic Information</div>
                        
                        <div class="form-group">
                            <label>Customer Code</label>
                            <div class="input-group">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="text" name="customer_code" value="<?= $nextCode ?>" readonly class="input-with-icon">
                            </div>
                            <div class="helper-text"><i class="fas fa-info-circle"></i> Auto-generated system code</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" required placeholder="Company or Person Name">
                        </div>
                        
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" placeholder="customer@example.com">
                        </div>
                        
                        <div class="form-group">
                            <label>Phone Number</label>
                            <div class="input-group">
                                <i class="fas fa-flag input-icon" style="color: #4b5563;"></i>
                                <input type="text" name="phone" placeholder="+255..." class="input-with-icon">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Tax ID / TIN</label>
                            <input type="text" name="tax_id" placeholder="Tax Identification Number">
                        </div>
                        
                        <div class="form-group">
                            <label>Credit Limit (TSh)</label>
                            <input type="number" name="credit_limit" value="0" step="0.01">
                        </div>
                        
                        <div class="section-title mt-large">Address Details</div>
                        
                        <div class="form-group full-width">
                            <label>Street Address</label>
                            <textarea name="address" rows="2" placeholder="Street, Building, etc."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city" placeholder="Dar es Salaam">
                        </div>
                        
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="country" value="Tanzania">
                        </div>
                        
                        <div class="section-title mt-large">Additional Info</div>
                        
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" rows="3" placeholder="Internal notes about this customer..."></textarea>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('createCustomerForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = document.querySelector('button[form="createCustomerForm"]');
            const originalText = btn.innerHTML; 
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/customers.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Customer created successfully! Redirecting...';
                    alert.style.display = 'block';
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    setTimeout(() => window.location.href = 'list.php', 1500);
                } else {
                    throw new Error(result.message || 'Failed to create customer');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                window.scrollTo({ top: 0, behavior: 'smooth' });
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    </script>
</div>
</body>
</html>