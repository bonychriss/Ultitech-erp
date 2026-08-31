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
            <div class="tab" onclick="switchTab('payroll')">Payroll</div>
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
                <div style="margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">Save Numbering Settings</button>
                </div>
            </form>
        </div>

        <!-- Payroll Tab -->
        <div id="payroll-tab" class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3>Payroll Calculations</h3>
                <button onclick="openPayrollModal()" class="btn btn-primary"><i class="fas fa-plus"></i> Add Rule</button>
            </div>
            
            <div id="payrollAlert" class="alert"></div>
            
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa;">
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e0e0e0;">Name</th>
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e0e0e0;">Type</th>
                        <th style="padding: 12px; text-align: right; border-bottom: 2px solid #e0e0e0;">Value</th>
                        <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e0e0e0;">Status</th>
                        <th style="padding: 12px; text-align: right; border-bottom: 2px solid #e0e0e0;">Actions</th>
                    </tr>
                </thead>
                <tbody id="payrollRulesList">
                    <!-- Loaded via JS -->
                </tbody>
            </table>

            <div style="display: flex; justify-content: space-between; align-items: center; margin: 40px 0 20px 0; border-top: 1px solid #e0e0e0; padding-top: 20px;">
                <h3>PAYE Tax Bands (Tanzania)</h3>
                <button onclick="openTaxBandModal()" class="btn btn-primary"><i class="fas fa-plus"></i> Add Band</button>
            </div>
            
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa;">
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e0e0e0;">Taxable Income (TZS)</th>
                        <th style="padding: 12px; text-align: right; border-bottom: 2px solid #e0e0e0;">Tax Rate</th>
                        <th style="padding: 12px; text-align: right; border-bottom: 2px solid #e0e0e0;">Fixed Base</th>
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e0e0e0;">Tax Charged Description</th>
                        <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e0e0e0;">Status</th>
                        <th style="padding: 12px; text-align: right; border-bottom: 2px solid #e0e0e0;">Actions</th>
                    </tr>
                </thead>
                <tbody id="taxBandsList">
                    <!-- Loaded via JS -->
                </tbody>
            </table>
        </div>

        <!-- Payroll Modal -->
        <div id="payrollModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000;">
            <div style="background: white; padding: 24px; border-radius: 8px; width: 400px; max-width: 90%;">
                <h3 id="modalTitle" style="margin-bottom: 16px;">Add Payroll Rule</h3>
                <form id="payrollRuleForm">
                    <input type="hidden" name="id" id="ruleId">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" id="ruleName" required placeholder="e.g. NSSF Support">
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" id="ruleType">
                            <option value="deduction">Deduction (Subtract)</option>
                            <option value="allowance">Allowance (Add)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Value</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="number" name="value" id="ruleValue" step="0.01" required style="flex: 1;">
                            <select name="is_percentage" id="ruleIsPercentage" style="width: 80px;">
                                <option value="1">%</option>
                                <option value="0">Fixed</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="is_active" id="ruleActive">
                            <option value="1">Active</option>
                            <option value="0">Disabled</option>
                        </select>
                    </div>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" onclick="closePayrollModal()" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Rule</button>
                    </div>
                </form>
            </div>
            </div>
        </div>
        
        <!-- Tax Band Modal -->
        <div id="taxBandModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000;">
            <div style="background: white; padding: 24px; border-radius: 8px; width: 400px; max-width: 90%;">
                <h3 id="taxModalTitle" style="margin-bottom: 16px;">Add Tax Band</h3>
                <form id="taxBandForm">
                    <input type="hidden" name="id" id="bandId">
                    <div style="display: flex; gap: 10px; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <label>Min Salary</label>
                            <input type="number" name="min_salary" id="bandMin" required>
                        </div>
                        <div style="flex: 1;">
                            <label>Max Salary</label>
                            <input type="number" name="max_salary" id="bandMax" placeholder="Empty = ∞">
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <label>Tax Rate (%)</label>
                            <input type="number" step="0.01" name="tax_rate" id="bandRate" required>
                        </div>
                        <div style="flex: 1;">
                            <label>Fixed Offset</label>
                            <input type="number" step="0.01" name="offset_amount" id="bandOffset" value="0">
                        </div>
                    </div>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" onclick="closeTaxBandModal()" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Band</button>
                    </div>
                </form>
            </div>
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
                btn.disabled = false;
                btn.textContent = 'Save Numbering Settings';
            }
        });

        // --- Payroll Functions ---
        
        async function loadPayrollRules() {
            try {
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=get_payroll_rules'
                });
                const result = await response.json();
                if (result.success) {
                    const tbody = document.getElementById('payrollRulesList');
                    tbody.innerHTML = '';
                    if (result.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" style="padding: 16px; text-align: center; color: #64748b;">No rules defined.</td></tr>';
                        return;
                    }
                    result.data.forEach(rule => {
                        const tr = document.createElement('tr');
                        tr.style.borderBottom = '1px solid #f1f3f4';
                        const valDisplay = parseFloat(rule.value) + (rule.is_percentage == 1 ? '%' : ' Fixed');
                        const statusBadge = rule.is_active == 1 
                            ? '<span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem;">Active</span>' 
                            : '<span style="background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem;">Disabled</span>';
                        
                        tr.innerHTML = `
                            <td style="padding: 12px;"><strong>${rule.name}</strong></td>
                            <td style="padding: 12px; text-transform: capitalize;">${rule.type}</td>
                            <td style="padding: 12px; text-align: right;">${valDisplay}</td>
                            <td style="padding: 12px; text-align: center;">${statusBadge}</td>
                            <td style="padding: 12px; text-align: right;">
                                <button onclick='editRule(${JSON.stringify(rule)})' style="background: none; border: none; cursor: pointer; color: #3b82f6; margin-right: 8px;"><i class="fas fa-edit"></i></button>
                                <button onclick="deleteRule(${rule.id})" style="background: none; border: none; cursor: pointer; color: #ef4444;"><i class="fas fa-trash"></i></button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }

        // Initial Load
        loadPayrollRules();

        function openPayrollModal() {
            document.getElementById('payrollRuleForm').reset();
            document.getElementById('ruleId').value = '';
            document.getElementById('modalTitle').textContent = 'Add Payroll Rule';
            document.getElementById('payrollModal').style.display = 'flex';
        }

        function closePayrollModal() {
            document.getElementById('payrollModal').style.display = 'none';
        }

        function editRule(rule) {
            document.getElementById('ruleId').value = rule.id;
            document.getElementById('ruleName').value = rule.name;
            document.getElementById('ruleType').value = rule.type;
            document.getElementById('ruleValue').value = rule.value;
            document.getElementById('ruleIsPercentage').value = rule.is_percentage;
            document.getElementById('ruleActive').value = rule.is_active;
            
            document.getElementById('modalTitle').textContent = 'Edit Payroll Rule';
            document.getElementById('payrollModal').style.display = 'flex';
        }

        async function deleteRule(id) {
            if (!confirm('Are you sure you want to delete this rule?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_payroll_rule');
            formData.append('id', id);
            await fetch('../api/settings.php', { method: 'POST', body: formData });
            loadPayrollRules();
        }

        document.getElementById('payrollRuleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const id = formData.get('id');
            formData.append('action', id ? 'update_payroll_rule' : 'add_payroll_rule');
            
            try {
                const response = await fetch('../api/settings.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    closePayrollModal();
                    loadPayrollRules();
                } else {
                    alert('Error saving rule');
                }
            } catch (e) {
                alert('Error: ' + e.message);
            }
        });
        });
        
        // --- Tax Bands Functions ---
        async function loadTaxBands() {
            try {
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=get_tax_bands'
                });
                const result = await response.json();
                if (result.success) {
                    const tbody = document.getElementById('taxBandsList');
                    tbody.innerHTML = '';
                    if (!result.data || result.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" style="padding: 16px; text-align: center; color: #64748b;">No tax bands defined.</td></tr>';
                        return;
                    }
                    result.data.forEach(band => {
                        const tr = document.createElement('tr');
                        tr.style.borderBottom = '1px solid #f1f3f4';
                        
                        const maxDisplay = band.max_salary ? parseInt(band.max_salary).toLocaleString() : 'Infinity';
                        const minDisplay = parseInt(band.min_salary).toLocaleString();
                        const offsetVal = parseInt(band.offset_amount);
                        const offsetDisplay = offsetVal.toLocaleString();
                        
                        let desc = '';
                        if (band.tax_rate == 0) {
                            desc = '0% (No tax)';
                        } else {
                            const baseText = offsetVal > 0 ? `${offsetDisplay} + ` : '';
                            desc = `${baseText}${band.tax_rate}% of amount above ${minDisplay}`;
                        }
                        
                        let rangeDisplay = `${minDisplay} – ${maxDisplay}`;
                        if (!band.max_salary) {
                            rangeDisplay = `Above ${minDisplay}`;
                        }

                        tr.innerHTML = `
                            <td style="padding: 12px; font-weight: 500;">${rangeDisplay}</td>
                            <td style="padding: 12px; text-align: right;">${band.tax_rate}%</td>
                            <td style="padding: 12px; text-align: right;">${offsetDisplay}</td>
                            <td style="padding: 12px; color: #64748b; font-size: 0.9em;">${desc}</td>
                            <td style="padding: 12px; text-align: right;">
                                <button onclick='editTaxBand(${JSON.stringify(band)})' style="background: none; border: none; cursor: pointer; color: #3b82f6; margin-right: 8px;"><i class="fas fa-edit"></i></button>
                                <button onclick="deleteTaxBand(${band.id})" style="background: none; border: none; cursor: pointer; color: #ef4444;"><i class="fas fa-trash"></i></button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }
        
        loadTaxBands();

        function openTaxBandModal(band = null) {
            document.getElementById('taxBandForm').reset();
            document.getElementById('bandId').value = '';
            document.getElementById('taxModalTitle').textContent = 'Add Tax Band';
            document.getElementById('bandOffset').value = 0;
            
            if (band) {
                document.getElementById('bandId').value = band.id;
                document.getElementById('bandMin').value = band.min_salary;
                document.getElementById('bandMax').value = band.max_salary;
                document.getElementById('bandRate').value = band.tax_rate;
                document.getElementById('bandOffset').value = band.offset_amount;
                document.getElementById('taxModalTitle').textContent = 'Edit Tax Band';
            }
            document.getElementById('taxBandModal').style.display = 'flex';
        }

        function closeTaxBandModal() {
            document.getElementById('taxBandModal').style.display = 'none';
        }
        
        function editTaxBand(band) {
            openTaxBandModal(band);
        }

        async function deleteTaxBand(id) {
            if (!confirm('Are you sure you want to delete this tax band?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_tax_band');
            formData.append('id', id);
            await fetch('../api/settings.php', { method: 'POST', body: formData });
            loadTaxBands();
        }

        document.getElementById('taxBandForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const id = formData.get('id');
            formData.append('action', id ? 'update_tax_band' : 'add_tax_band');
            
            try {
                const response = await fetch('../api/settings.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    closeTaxBandModal();
                    loadTaxBands();
                } else {
                    alert('Error saving tax band');
                }
            } catch (e) {
                alert('Error: ' + e.message);
            }
        });
</div>
</body>
</html>
