<?php
// modules/payroll/settings.php
require_once __DIR__ . '/config/database.php';

// Strict Access Control
define('ALLOW_ANONYMOUS_PAYROLL', true);
requireFinanceOrAdmin();

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        $stmt = $pdo->prepare('INSERT INTO ' . payroll_table('payroll_settings') . ' (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
        $stmt->execute([$key, $value, $value]);
    }
    $_SESSION['flash_message'] = "Settings saved!";
    $_SESSION['flash_type'] = "success";
}

// Fetch Settings
$settings = [];
try {
    $smtp = $pdo->query('SELECT * FROM ' . payroll_table('payroll_settings'));
    while ($row = $smtp->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // If table missing, redirect to fix_schema
    header('Location: fix_schema.php');
    exit;
}

// Fetch Tax Bands (Direct PHP Render)
try {
    $tax_bands = $pdo->query('SELECT * FROM ' . payroll_table('payroll_tax_bands') . ' ORDER BY min_salary ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If table missing, redirect to fix_schema
    header('Location: fix_schema.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Settings</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }</style>
</head>
<body>
    <?php include '../../includes/header_admin.php'; ?>
    
    <div class="container-fluid px-4 py-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
                    <div>
                        <h2 class="h4 mb-0">Payroll Configuration</h2>
                        <p class="text-muted small mb-0">Manage global tax and social security rates</p>
                    </div>
                    <div class="d-flex gap-2 w-100 w-md-auto">
                        <a href="index.php" class="btn btn-outline-secondary flex-grow-1 flex-md-grow-0">
                            <i class="bi bi-arrow-left me-1"></i> <span class="d-none d-md-inline">Back to Dashboard</span><span class="d-inline d-md-none">Back</span>
                        </a>
                        <button class="btn btn-info flex-grow-1 flex-md-grow-0" type="button" data-bs-toggle="modal" data-bs-target="#manualModal">
                            <i class="bi bi-book me-1"></i> Manual
                        </button>
                    </div>
                </div>

                <!-- PAYE Tax Bands Section (PRIORITY) -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-0">PAYE Tax Bands (Tanzania)</h5>
                                    <small class="text-muted">Configure the progressive tax rates.</small>
                                </div>
                                <button onclick="openTaxBandModal()" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus-lg me-1"></i> Add Band
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Taxable Income (TZS)</th>
                                                <th>Tax Rate</th>
                                                <th>Fixed Base</th>
                                                <th>Tax Charged Description</th>
                                                <th>Status</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="taxBandsList">
                                            <?php foreach ($tax_bands as $band): 
                                                $min = number_format($band['min_salary']);
                                                $max = $band['max_salary'] ? number_format($band['max_salary']) : 'Infinity';
                                                
                                                // Range Logic
                                                $range = $min . ' – ' . $max;
                                                if (!$band['max_salary']) $range = "Above " . $min;

                                                // Description Logic
                                                $offset = number_format($band['offset_amount']);
                                                $desc = '';
                                                if ($band['tax_rate'] == 0) {
                                                    $desc = '0% (No tax)';
                                                } else {
                                                    $base = $band['offset_amount'] > 0 ? $offset . " + " : "";
                                                    $desc = "{$base}{$band['tax_rate']}% of amount above {$min}";
                                                }

                                                // Toggle Logic
                                                $checked = $band['is_active'] ? 'checked' : '';
                                            ?>
                                            <tr>
                                                <td class="fw-medium"><?= $range ?></td>
                                                <td><?= $band['tax_rate'] ?>%</td>
                                                <td><?= $offset ?></td>
                                                <td class="text-muted small"><?= $desc ?></td>
                                                <td>
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" role="switch" 
                                                            id="band_toggle_<?= $band['id'] ?>" <?= $checked ?> 
                                                            onchange='toggleTaxBandStatus(<?= json_encode($band) ?>, this.checked)'>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-light text-primary" onclick='editTaxBand(<?= json_encode($band) ?>)'>
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-light text-danger" onclick="deleteTaxBand(<?= $band['id'] ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if(empty($tax_bands)): ?>
                                                <tr><td colspan="6" class="text-center p-3 text-muted">No tax bands found. Add one above.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-body">
                                <form method="POST">
                                    <h5 class="card-title mb-4">Global Settings</h5>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Default Pay Day (Day of Month)</label>
                                        <input type="number" name="pay_day" class="form-control" value="<?= $settings['pay_day'] ?? 30 ?>" min="1" max="31">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Employee NSSF Contribution (%)</label>
                                        <div class="input-group">
                                            <input type="number" step="0.01" name="social_security_rate" class="form-control" value="<?= $settings['social_security_rate'] ?? 10 ?>">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Tax Rate (Flat Rate %)</label>
                                        <div class="input-group">
                                            <input type="number" step="0.01" name="tax_rate" class="form-control" value="<?= $settings['tax_rate'] ?? 0 ?>">
                                            <span class="input-group-text">%</span>
                                        </div>
                                        <div class="form-text">Set to 0 if using graduated tax tables above.</div>
                                    </div>
                                    
                                    <div class="d-flex gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary flex-grow-1">Save Configuration</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>


                    <!-- Dynamic Payroll Rules Section -->
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Custom Rules (Allowances/Deductions)</h5>
                                <button onclick="openPayrollModal()" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-plus-lg me-1"></i> Add Rule
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Type</th>
                                                <th>Value</th>
                                                <th>Status</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="payrollRulesList">
                                            <!-- Loaded via JS -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
            </div>
        
                </div> <!-- End of Row -->
        
            <!-- Manual Moved to Modal -->

            </div>
        </div>
    </div>


    <!-- Manual Modal -->
    <div class="modal fade" id="manualModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title">Payroll Configuration Manual</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="fw-bold mb-3">Manage global tax and social security rates</h6>
                    
                    <h6 class="text-primary fw-bold mt-4">PAYE Tax Bands (Tanzania)</h6>
                    <p class="text-muted small">Configure the progressive tax rates.</p>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Taxable Income (TZS)</th>
                                    <th>Tax Rate</th>
                                    <th>Fixed Base</th>
                                    <th>Tax Charged Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>0 – 270,000</td>
                                    <td>0.00%</td>
                                    <td>0</td>
                                    <td>0% (No tax)</td>
                                </tr>
                                <tr>
                                    <td>270,001 – 520,000</td>
                                    <td>8.00%</td>
                                    <td>0</td>
                                    <td>8.00% of amount above 270,000</td>
                                </tr>
                                <tr>
                                    <td>520,001 – 760,000</td>
                                    <td>20.00%</td>
                                    <td>20,000</td>
                                    <td>20,000 + 20.00% of amount above 520,000</td>
                                </tr>
                                <tr>
                                    <td>760,001 – 1,000,000</td>
                                    <td>25.00%</td>
                                    <td>68,000</td>
                                    <td>68,000 + 25.00% of amount above 760,000</td>
                                </tr>
                                <tr>
                                    <td>Above 1,000,000</td>
                                    <td>30.00%</td>
                                    <td>128,000</td>
                                    <td>128,000 + 30.00% of amount above 1,000,000</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary fw-bold">Global Settings</h6>
                            <ul class="list-group list-group-flush small">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Default Pay Day
                                    <span class="badge bg-secondary">30th</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Employee NSSF
                                    <span class="badge bg-info text-dark">10%</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Flat Tax Rate
                                    <span class="badge bg-secondary">0%</span>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary fw-bold">Custom Rules</h6>
                            <p class="small text-muted">Use this section to add recurring allowances (e.g., Housing) or deductions (e.g., Loans) that apply to payroll.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Rule Modal -->
    <div class="modal fade" id="payrollRuleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="payrollModalTitle">Add Calculation Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="payrollRuleForm">
                        <input type="hidden" name="id" id="ruleId">
                        <div class="mb-3">
                            <label class="form-label">Rule Name</label>
                            <input type="text" name="name" id="ruleName" class="form-control" required placeholder="e.g. Housing Levy">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Type</label>
                                <select name="type" id="ruleType" class="form-select">
                                    <option value="allowance">Allowance (Credit)</option>
                                    <option value="deduction">Deduction (Debit)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Value</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="value" id="ruleValue" class="form-control" required>
                                    <div class="input-group-text">
                                        <input class="form-check-input mt-0" type="checkbox" name="is_percentage" id="ruleIsPercentage" value="1" checked>
                                        <label class="form-check-label ms-1 small" for="ruleIsPercentage">%</label>
                                    </div>
                                </div>
                                <div class="form-text">Check box for percentage of Basic Salary</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="ruleIsActive" value="1" checked>
                                <label class="form-check-label" for="ruleIsActive">Active</label>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Rule</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Tax Band Modal -->
    <div class="modal fade" id="taxBandModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taxBandModalTitle">Configure Tax Band</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="taxBandForm">
                        <input type="hidden" name="id" id="bandId">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Min Salary</label>
                                <input type="number" name="min_salary" id="bandMin" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Max Salary</label>
                                <input type="number" name="max_salary" id="bandMax" class="form-control" placeholder="Leave empty for Infinity">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tax Rate (%)</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="tax_rate" id="bandRate" class="form-control" required>
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Details</label>
                                <div class="form-text mt-0">Applied to amount above Min Salary</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Fixed Offset Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">TZS</span>
                                <input type="number" step="0.01" name="offset_amount" id="bandOffset" class="form-control" value="0">
                            </div>
                            <div class="form-text">e.g., 20,000 for the second band. This is added to the percentage calculation.</div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Band</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Point to the standard API
    const API_URL = '../../erp/api/settings.php';
    let payrollModal;
    let taxBandModal;

    document.addEventListener('DOMContentLoaded', function() {
        payrollModal = new bootstrap.Modal(document.getElementById('payrollRuleModal'));
        taxBandModal = new bootstrap.Modal(document.getElementById('taxBandModal'));
        
        loadPayrollRules();
        // loadTaxBands(); // Handled by PHP SSR

        document.getElementById('payrollRuleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            savePayrollRule();
        });

        document.getElementById('taxBandForm').addEventListener('submit', function(e) {
            e.preventDefault();
            saveTaxBand();
        });
    });

    // --- Payroll Rules Logic ---
    function openPayrollModal(rule = null) {
        document.getElementById('payrollRuleForm').reset();
        document.getElementById('ruleId').value = '';
        document.getElementById('payrollModalTitle').textContent = 'Add Calculation Rule';
        document.getElementById('ruleIsPercentage').checked = true;
        document.getElementById('ruleIsActive').checked = true;

        if (rule) {
            document.getElementById('payrollModalTitle').textContent = 'Edit Rule';
            document.getElementById('ruleId').value = rule.id;
            document.getElementById('ruleName').value = rule.name;
            document.getElementById('ruleType').value = rule.type;
            document.getElementById('ruleValue').value = rule.value;
            document.getElementById('ruleIsPercentage').checked = rule.is_percentage == 1;
            document.getElementById('ruleIsActive').checked = rule.is_active == 1;
        }
        payrollModal.show();
    }

    async function loadPayrollRules() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_payroll_rules');
            
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const json = await res.json();
            
            const tbody = document.getElementById('payrollRulesList');
            tbody.innerHTML = '';
            
            if (json.success && json.data) {
                json.data.forEach(rule => {
                    const isChecked = rule.is_active == 1 ? 'checked' : '';
                    const toggleHtml = `
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" 
                                id="toggle_${rule.id}" ${isChecked} 
                                onchange='toggleRuleStatus(${JSON.stringify(rule)}, this.checked)'>
                            <label class="form-check-label" for="toggle_${rule.id}"></label>
                        </div>
                    `;
                    
                    const valDisplay = rule.is_percentage == 1 ? `${rule.value}%` : rule.value;
                    const typeDisplay = rule.type.charAt(0).toUpperCase() + rule.type.slice(1);

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><div class="fw-medium">${rule.name}</div></td>
                        <td>${typeDisplay}</td>
                        <td>${valDisplay}</td>
                        <td>${toggleHtml}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-light text-primary" onclick='editRule(${JSON.stringify(rule)})'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-light text-danger" onclick="deleteRule(${rule.id})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        } catch (e) {
            console.error(e);
        }
    }

    function editRule(rule) {
        openPayrollModal(rule);
    }

    async function toggleRuleStatus(rule, newStatus) {
        const formData = new FormData();
        formData.append('action', 'update_payroll_rule');
        formData.append('id', rule.id);
        formData.append('name', rule.name);
        formData.append('value', rule.value);
        formData.append('is_percentage', rule.is_percentage);
        formData.append('type', rule.type);
        formData.append('is_active', newStatus ? 1 : 0);

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const json = await res.json();
            if (!json.success) {
                alert('Error updating status: ' + json.message);
                document.getElementById(`toggle_${rule.id}`).checked = !newStatus;
            } else {
                rule.is_active = newStatus ? 1 : 0;
            }
        } catch (e) {
            console.error(e);
            alert('Failed to update status');
            document.getElementById(`toggle_${rule.id}`).checked = !newStatus;
        }
    }

    async function savePayrollRule() {
        const form = document.getElementById('payrollRuleForm');
        const formData = new FormData(form);
        const id = formData.get('id');
        const action = id ? 'update_payroll_rule' : 'add_payroll_rule';
        
        formData.append('action', action);
        if (!formData.has('is_percentage')) formData.append('is_percentage', 0);
        if (!formData.has('is_active')) formData.append('is_active', 0);

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const json = await res.json();
            if (json.success) {
                payrollModal.hide();
                loadPayrollRules();
            } else {
                alert('Error: ' + json.message);
            }
        } catch (e) {
            console.error(e);
            alert('Failed to save rule');
        }
    }

    async function deleteRule(id) {
        if (!confirm('Are you sure you want to delete this rule?')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete_payroll_rule');
        formData.append('id', id);
        
        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const json = await res.json();
            if (json.success) {
                loadPayrollRules();
            } else {
                alert('Error: ' + json.message);
            }
        } catch (e) {
            alert('Failed to delete');
        }
    }

    // --- PAYE Tax Bands Logic ---
    function openTaxBandModal(band = null) {
        document.getElementById('taxBandForm').reset();
        document.getElementById('bandId').value = '';
        document.getElementById('taxBandModalTitle').textContent = 'Add Tax Band';
        document.getElementById('bandOffset').value = 0;

        if (band) {
            document.getElementById('taxBandModalTitle').textContent = 'Edit Tax Band';
            document.getElementById('bandId').value = band.id;
            document.getElementById('bandMin').value = band.min_salary;
            document.getElementById('bandMax').value = band.max_salary;
            document.getElementById('bandRate').value = band.tax_rate;
            document.getElementById('bandOffset').value = band.offset_amount;
        }
        taxBandModal.show();
    }

    async function loadTaxBands() {
        console.log('Starting loadTaxBands...');
        try {
            const formData = new FormData();
            formData.append('action', 'get_tax_bands');
            
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            // console.log('Fetch response status:', res.status);
            
            const text = await res.text();
            // console.log('Raw response:', text);
            
            let json;
            try {
                json = JSON.parse(text);
            } catch (e) {
                console.error('JSON Parse Error:', e);
                document.getElementById('taxBandsList').innerHTML = `<tr><td colspan="6" class="text-danger">Error parsing data: ${e.message}</td></tr>`;
                return;
            }
            
            const tbody = document.getElementById('taxBandsList');
            tbody.innerHTML = '';
            
            if (json.success && json.data) {
                console.log('Loaded bands:', json.data.length);
                if (json.data.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center p-3">No tax bands found. <a href="#" onclick="openTaxBandModal()">Add one</a></td></tr>`;
                }
                json.data.forEach(band => {
                    const maxDisplay = band.max_salary ? parseInt(band.max_salary).toLocaleString() : 'Infinity'; // Or "Above" logic
                    const minDisplay = parseInt(band.min_salary).toLocaleString();
                    const offsetVal = parseInt(band.offset_amount);
                    const offsetDisplay = offsetVal.toLocaleString();
                    
                    // Format Description exactly like user asked: "X + Y% of amount above Z"
                    let desc = '';
                    if (band.tax_rate == 0) {
                        desc = '0% (No tax)';
                    } else {
                        const baseText = offsetVal > 0 ? `${offsetDisplay} + ` : '';
                        desc = `${baseText}${band.tax_rate}% of amount above ${minDisplay}`;
                    }
                    
                    if (!band.max_salary) {
                        rangeDisplay = `Above ${minDisplay}`;
                    }
                    
                    const isChecked = band.is_active == 1 ? 'checked' : '';
                    const toggleHtml = `
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" 
                                id="band_toggle_${band.id}" ${isChecked} 
                                onchange='toggleTaxBandStatus(${JSON.stringify(band)}, this.checked)'>
                        </div>
                    `;

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="fw-medium">${rangeDisplay}</td>
                        <td>${band.tax_rate}%</td>
                        <td>${offsetDisplay}</td>
                        <td class="text-muted">${desc}</td>
                        <td>${toggleHtml}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-light text-primary" onclick='editTaxBand(${JSON.stringify(band)})'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-light text-danger" onclick="deleteTaxBand(${band.id})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        } catch (e) {
            console.error(e);
        }
    }

    function editTaxBand(band) {
        openTaxBandModal(band);
    }

    async function saveTaxBand() {
        const form = document.getElementById('taxBandForm');
        const formData = new FormData(form);
        const id = formData.get('id');
        const action = id ? 'update_tax_band' : 'add_tax_band';
        
        formData.append('action', action);

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const json = await res.json();
            if (json.success) {
                taxBandModal.hide();
                loadTaxBands();
            } else {
                alert('Error: ' + json.message);
            }
        } catch (e) {
            console.error(e);
            alert('Failed to save tax band');
        }
    }

    async function deleteTaxBand(id) {
        if (!confirm('Are you sure you want to delete this tax band?')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete_tax_band');
        formData.append('id', id);
        
        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const json = await res.json();
            if (json.success) {
                loadTaxBands();
            } else {
                alert('Error: ' + json.message);
            }
        } catch (e) {
            alert('Failed to delete');
        }
    }
    async function toggleTaxBandStatus(band, newStatus) {
        const formData = new FormData();
        formData.append('action', 'update_tax_band');
        formData.append('id', band.id);
        formData.append('min_salary', band.min_salary);
        formData.append('max_salary', band.max_salary || '');
        formData.append('tax_rate', band.tax_rate);
        formData.append('offset_amount', band.offset_amount);
        formData.append('is_active', newStatus ? 1 : 0);

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const json = await res.json();
            if (!json.success) {
                alert('Error updating status: ' + json.message);
                document.getElementById(`band_toggle_${band.id}`).checked = !newStatus;
            } else {
                band.is_active = newStatus ? 1 : 0;
            }
        } catch (e) {
            console.error(e);
            alert('Failed to update status');
            document.getElementById(`band_toggle_${band.id}`).checked = !newStatus;
        }
    }
    </script>
</body>
</html>
