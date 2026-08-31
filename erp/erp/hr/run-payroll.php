<?php require_once '../../includes/functions.php';  global $pdo; $month = $_GET['month'] ?? date('Y-m'); 
// Fetch existing payroll check
$stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_payroll WHERE DATE_FORMAT(payroll_month, '%Y-%m') = ?"); 
$stmt->execute([$month]); 
$existingCount = $stmt->fetchColumn(); 
$employees = $pdo->query("SELECT * FROM erp_employees WHERE status = 'active' ORDER BY first_name")->fetchAll(); 

// Fetch Active Payroll Rules
$rules = $pdo->query("SELECT * FROM erp_payroll_settings WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Run Payroll - ERP</title><link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>"><style>* { margin: 0; padding: 0; box-sizing: border-box; } body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; } .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 100%; padding: 24px; } .page-wrapper { margin-left: 220px; min-height: 100vh; } @media (max-width: 768px) { .page-wrapper { margin-left: 0; } } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; } .card-body { padding: 24px; } .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; } .alert-warning { background: #fef7e0; color: #b06000; } .alert-info { background: #e8f0fe; color: #1967d2; } .table { width: 100%; border-collapse: collapse; margin-top: 20px; } .table th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; font-size: 0.875rem; } .table td { padding: 12px; border-bottom: 1px solid #f1f3f4; } .table input { width: 100px; padding: 6px; border: 1px solid #dadce0; border-radius: 4px; } .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; } .btn-primary { background: #1a73e8; color: white; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }</style></head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
<div style="padding: 16px 24px 0; text-align: right;"><a href="payroll.php" class="btn btn-secondary">Cancel</a></div><div class="container"><div class="card"><div class="card-body"><form method="GET" style="margin-bottom: 20px;"><label style="font-weight: 500; margin-right: 8px;">Select Month:</label><input type="month" name="month" value="<?= $month ?>" onchange="this.form.submit()" style="padding: 8px;"></form><?php if ($existingCount > 0): ?><div class="alert alert-warning"><strong>Warning:</strong> Payroll for <?= date('F Y', strtotime($month)) ?> has already been generated.</div><?php else: ?><div class="alert alert-info">Ready to generate payroll for <strong><?= count($employees) ?> active employees</strong> for <?= date('F Y', strtotime($month)) ?>.</div><?php endif; ?>
<form id="runPayrollForm"><input type="hidden" name="month" value="<?= $month ?>">
<div class="alert alert-info" style="font-size: 0.9rem;">
    <strong>Applied Rules:</strong> 
    <?php 
    if (empty($rules)) echo "None";
    else {
        $names = array_map(function($r) { 
            return $r['name'] . ' (' . ($r['is_percentage'] ? $r['value'].'%' : $r['value']) . ' ' . ucfirst(substr($r['type'],0,3)) . ')'; 
        }, $rules);
        echo implode(', ', $names);
    }
    ?>
</div>
<table class="table"><thead><tr><th>Employee</th><th>Basic Salary</th><th>Allowances</th><th>Deductions</th><th>Net Salary</th></tr></thead><tbody>
<?php foreach ($employees as $emp): 
    // Auto-calculate defaults
    $defaultAllow = 0;
    $defaultDeduct = 0;
    $basic = $emp['basic_salary'];
    
    foreach ($rules as $rule) {
        $val = ($rule['is_percentage']) ? ($basic * ($rule['value'] / 100)) : $rule['value'];
        if ($rule['type'] === 'allowance') $defaultAllow += $val;
        if ($rule['type'] === 'deduction') $defaultDeduct += $val;
    }
?>
<tr>
    <td><strong><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></strong><input type="hidden" name="employees[<?= $emp['id'] ?>][id]" value="<?= $emp['id'] ?>"></td>
    <td><input type="number" name="employees[<?= $emp['id'] ?>][basic]" value="<?= $emp['basic_salary'] ?>" readonly style="background: #f8f9fa;"></td>
    <td><input type="number" name="employees[<?= $emp['id'] ?>][allowances]" value="<?= number_format($defaultAllow, 2, '.', '') ?>" step="0.01" oninput="calculateNet(<?= $emp['id'] ?>)" id="allow_<?= $emp['id'] ?>"></td>
    <td><input type="number" name="employees[<?= $emp['id'] ?>][deductions]" value="<?= number_format($defaultDeduct, 2, '.', '') ?>" step="0.01" oninput="calculateNet(<?= $emp['id'] ?>)" id="deduct_<?= $emp['id'] ?>"></td>
    <td><span id="net_<?= $emp['id'] ?>" style="font-weight: 600;"><?= number_format($basic + $defaultAllow - $defaultDeduct, 2) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table><div style="margin-top: 24px; text-align: right;"><button type="submit" class="btn btn-primary">Process Payroll</button></div></form></div></div></div><script>function calculateNet(id) { const basic = parseFloat(document.querySelector(`input[name="employees[${id}][basic]"]`).value) || 0; const allow = parseFloat(document.getElementById(`allow_${id}`).value) || 0; const deduct = parseFloat(document.getElementById(`deduct_${id}`).value) || 0; const net = basic + allow - deduct; document.getElementById(`net_${id}`).textContent = net.toFixed(2); } document.getElementById('runPayrollForm').addEventListener('submit', async function(e) { e.preventDefault(); if (!confirm('Are you sure you want to process payroll?')) return; const btn = this.querySelector('button[type="submit"]'); btn.disabled = true; btn.textContent = 'Processing...'; try { const formData = new FormData(this); formData.append('action', 'run_payroll'); const response = await fetch('../api/payroll.php', { method: 'POST', body: formData }); const result = await response.json(); if (result.success) { alert('Payroll processed successfully!'); window.location.href = 'payroll.php?month=' + formData.get('month'); } else { throw new Error(result.message || 'Failed to process payroll'); } } catch (error) { alert('Error: ' + error.message); btn.disabled = false; btn.textContent = 'Process Payroll'; } });</script>
</div>
</body>
</html>

