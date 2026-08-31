<?php
require_once '../../includes/functions.php';

global $pdo;

// Create settings table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS `erp_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL UNIQUE,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','json') DEFAULT 'text',
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Get all settings
function getSetting($key, $default = '') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM erp_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetchColumn();
    return $result !== false ? $result : $default;
}

$settings = [
    'company_name' => getSetting('company_name', 'My Company'),
    'company_address' => getSetting('company_address'),
    'company_phone' => getSetting('company_phone'),
    'company_email' => getSetting('company_email'),
    'company_vrn' => getSetting('company_vrn'),
    'company_tin' => getSetting('company_tin'),
    'company_logo' => getSetting('company_logo'),
    'currency' => getSetting('currency', 'TSh'),
    'timezone' => getSetting('timezone', 'Africa/Dar_es_Salaam'),
    'date_format' => getSetting('date_format', 'Y-m-d'),
    'tax_enabled' => getSetting('tax_enabled', '1'),
    'default_tax_rate' => getSetting('default_tax_rate', '18')
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
        .tabs { display: flex; gap: 8px; margin-bottom: 24px; background: white; border-radius: 8px; padding: 8px; border: 1px solid #e0e0e0; }
        .tab { padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 500; font-size: 0.875rem; color: #5f6368; }
        .tab.active { background: #e8f0fe; color: #1a73e8; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; padding: 24px; margin-bottom: 24px; display: none; }
        .card.active { display: block; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full-width { grid-column: span 2; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; display: none; }
        .alert-success { background: #e6f4ea; color: #137333; }
        .logo-preview { max-width: 200px; margin-top: 12px; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><a href="../index.php" class="btn btn-secondary">â†  Back to Dashboard</a></div>
    
    <div class="container">
        <div class="tabs">
            <div class="tab active" onclick="switchTab('company')">Company Profile</div>
            <div class="tab" onclick="switchTab('system')">System Settings</div>
            <div class="tab" onclick="switchTab('numbering')">Numbering</div>
            <div class="tab" onclick="switchTab('tax')">Tax Rates</div>
            <div class="tab" onclick="switchTab('roles')">User Roles</div>
        </div>
        
        <!-- Company Profile Tab -->
        <div id="company-tab" class="card active">
            <h3 style="margin-bottom: 20px;">Company Profile</h3>
            <div id="companyAlert" class="alert"></div>
            
            <form id="companyForm" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Company Name *</label>
                        <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name']) ?>" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Address</label>
                        <textarea name="company_address" rows="3"><?= htmlspecialchars($settings['company_address']) ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="company_phone" value="<?= htmlspecialchars($settings['company_phone']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="company_email" value="<?= htmlspecialchars($settings['company_email']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Company VRN</label>
                        <input type="text" name="company_vrn" value="<?= htmlspecialchars($settings['company_vrn']) ?>" placeholder="e.g. 40-123456-Q">
                    </div>

                    <div class="form-group">
                        <label>Company TIN</label>
                        <input type="text" name="company_tin" value="<?= htmlspecialchars($settings['company_tin']) ?>" placeholder="e.g. 123-456-789">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Company Logo</label>
                        <input type="file" name="logo" accept="image/*">
                        <?php if ($settings['company_logo']): ?>
                            <img src="../../<?= htmlspecialchars($settings['company_logo']) ?>" class="logo-preview" alt="Current Logo">
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">Save Company Profile</button>
                </div>
            </form>
        </div>
        
        <!-- System Settings Tab -->
        <div id="system-tab" class="card">
            <h3 style="margin-bottom: 20px;">System Settings</h3>
            <div id="systemAlert" class="alert"></div>
            
            <form id="systemForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Default Currency</label>
                        <select name="currency">
                            <option value="TSh" <?= $settings['currency'] == 'TSh' ? 'selected' : '' ?>>TSh (Tanzanian Shilling)</option>
                            <option value="USD" <?= $settings['currency'] == 'USD' ? 'selected' : '' ?>>USD</option>
                            <option value="EUR" <?= $settings['currency'] == 'EUR' ? 'selected' : '' ?>>EUR</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Timezone</label>
                        <select name="timezone">
                            <option value="Africa/Dar_es_Salaam" <?= $settings['timezone'] == 'Africa/Dar_es_Salaam' ? 'selected' : '' ?>>East Africa Time</option>
                            <option value="UTC" <?= $settings['timezone'] == 'UTC' ? 'selected' : '' ?>>UTC</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Date Format</label>
                        <select name="date_format">
                            <option value="Y-m-d" <?= $settings['date_format'] == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                            <option value="d/m/Y" <?= $settings['date_format'] == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                            <option value="m/d/Y" <?= $settings['date_format'] == 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Enable Tax</label>
                        <select name="tax_enabled">
                            <option value="1" <?= $settings['tax_enabled'] == '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= $settings['tax_enabled'] == '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Default Tax Rate (%)</label>
                        <input type="number" name="default_tax_rate" step="0.01" value="<?= htmlspecialchars($settings['default_tax_rate']) ?>">
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">Save System Settings</button>
                </div>
            </form>
        </div>
        
        <!-- Numbering Tab -->
        <div id="numbering-tab" class="card">
            <h3 style="margin-bottom: 20px;">Document Numbering</h3>
            <div id="numberingAlert" class="alert"></div>
            
            <form id="numberingForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Invoice Prefix</label>
                        <input type="text" name="invoice_prefix" value="<?= htmlspecialchars(getSetting('invoice_prefix', 'INV-')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Next Invoice Number</label>
                        <input type="number" name="invoice_next_number" value="<?= htmlspecialchars(getSetting('invoice_next_number', '1001')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Quote Prefix</label>
                        <input type="text" name="quote_prefix" value="<?= htmlspecialchars(getSetting('quote_prefix', 'QT-')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Next Quote Number</label>
                        <input type="number" name="quote_next_number" value="<?= htmlspecialchars(getSetting('quote_next_number', '1001')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>PO Prefix</label>
                        <input type="text" name="po_prefix" value="<?= htmlspecialchars(getSetting('po_prefix', 'PO-')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Next PO Number</label>
                        <input type="number" name="po_next_number" value="<?= htmlspecialchars(getSetting('po_next_number', '1001')) ?>">
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">Save Numbering Settings</button>
                </div>
            </form>
        </div>
        
        <!-- Tax Rates Tab -->
        <div id="tax-tab" class="card">
            <h3 style="margin-bottom: 20px;">Tax Rates</h3>
            <p style="color: #5f6368; margin-bottom: 20px;">Configure tax rates for invoices and quotes.</p>
            <a href="tax-rates.php" class="btn btn-primary">Manage Tax Rates</a>
        </div>
        
        <!-- User Roles Tab -->
        <div id="roles-tab" class="card">
            <h3 style="margin-bottom: 20px;">User Roles & Permissions</h3>
            <p style="color: #5f6368; margin-bottom: 20px;">Configure user roles and their permissions.</p>
            <a href="user-roles.php" class="btn btn-primary">Manage Roles</a>
        </div>
    </div>
    
    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.card').forEach(c => c.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById(tab + '-tab').classList.add('active');
        }
        
        // Company Form
        document.getElementById('companyForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const alert = document.getElementById('companyAlert');
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'update_company');
                
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Company profile updated successfully!';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Save Company Profile';
            }
        });
        
        // System Form
        document.getElementById('systemForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const alert = document.getElementById('systemAlert');
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'update_system');
                
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'System settings updated successfully!';
                    alert.style.display = 'block';
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save System Settings';
            }
        });
        
        // Numbering Form
        document.getElementById('numberingForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const alert = document.getElementById('numberingAlert');
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'update_numbering');
                
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Numbering settings updated successfully!';
                    alert.style.display = 'block';
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save Numbering Settings';
            }
        });
    </script>
</div>
</body>
</html>
