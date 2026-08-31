<?php
require_once '../../includes/functions.php';
global $pdo;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Employee - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        .page-wrapper { margin-left: 220px !important; padding: 30px; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 1.8rem; font-weight: 700; color: #111827; }

        .card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); max-width: 800px; margin: 0 auto; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 0.95rem; color: #374151; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; transition: border-color 0.15s; }
        .form-control:focus { border-color: #2563eb; outline: none; ring: 2px solid #2563eb30; }
        
        .section-header { font-size: 1.1rem; font-weight: 600; color: #111827; margin: 32px 0 16px 0; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; grid-column: span 2; }
        .section-header:first-child { margin-top: 0; }
        
        .btn-submit { background: #2563eb; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; font-size: 1rem; margin-top: 24px; }
        .btn-submit:hover { background: #1d4ed8; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <div class="header">
        <h1 class="page-title">New Employee</h1>
        <a href="employees.php" style="color:#6b7280; text-decoration:none;"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="card">
        <form id="createEmployeeForm">
            <div class="form-grid">
                <div class="section-header">Personal Information</div>
                
                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" required>
                </div>

                <div class="section-header">Employment Details</div>
                
                <div class="form-group">
                    <label class="form-label">Employee Code</label>
                    <input type="text" name="employee_code" class="form-control" value="EMP-<?= rand(1000,9999) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Join Date</label>
                    <input type="date" name="join_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                 <div class="form-group">
                    <label class="form-label">Position</label>
                    <input type="text" name="position" class="form-control" placeholder="e.g. Sales Manager" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="probation">Probation</option>
                        <option value="terminated">Terminated</option>
                    </select>
                </div>

                <div class="section-header">Compensation</div>
                
                <div class="form-group">
                    <label class="form-label">Basic Salary (Monthly TSh)</label>
                    <input type="number" name="basic_salary" class="form-control" required min="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Bank Name</label>
                    <input type="text" name="bank_name" class="form-control">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Bank Account Number</label>
                    <input type="text" name="bank_account_number" class="form-control">
                </div>
            </div>

            <button type="submit" class="btn-submit">Create Employee</button>
        </form>
    </div>
</div>

<script>
document.getElementById('createEmployeeForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'create');

    try {
        const response = await fetch('../api/employees.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            alert('Employee created successfully!');
            window.location.href = 'employees.php';
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Request failed: ' + error.message);
    }
});
</script>
</body>
</html>
