<?php 
$functions_path = __DIR__ . '/../includes/functions.php';
if (!file_exists($functions_path)) {
    $functions_path = __DIR__ . '/../../includes/functions.php';
}
require_once $functions_path;
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Account - ERP</title>
    <link rel="stylesheet" href="<?= app_url('/assets/css/style.css') ?>?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #004560;
            --primary-hover: #002d3f;
            --primary-glow: rgba(0, 69, 96, 0.08);
            --bg-canvas: #f8fdff;
            --border: #e2e8f0;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            background: var(--bg-canvas); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
            min-height: 100vh;
        }
        
        .page-wrapper {
            margin-left: 240px;
            padding: 40px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        @media (max-width: 992px) {
            .page-wrapper { margin-left: 0; padding: 20px; }
        }

        .page-header {
            margin-bottom: 32px;
        }

        .header-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.5px;
        }

        .header-title p {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.04);
            max-width: 600px;
            margin: 0 auto;
        }

        .card-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
        }

        input, select, textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: all 0.2s;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 14px rgba(0, 69, 96, 0.2);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #f1f5f9;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 0 0 20px; text-align: right;">
        <a href="chart-of-accounts.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Chart of Accounts</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-body">
                <div class="page-header">
                    <div class="header-title">
                        <h1>Create New Account</h1>
                        <p>Register a primary parent ledger account in your ERP system.</p>
                    </div>
                </div>
                
                <div id="alertMessage" class="alert"></div>
                
                <form id="createAccountForm">
                    <div class="form-group">
                        <label>Account Name *</label>
                        <input type="text" name="name" required placeholder="e.g. Petty Cash" maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label>Account Type * <a href="account-types.php" class="btn btn-secondary" style="float:right; padding: 4px 10px; font-size: 12px; border-radius: 6px;">Manage Types</a></label>
                        <select name="type" id="accountTypeSelect" required>
                            <option value="">Select Type</option>
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Account Code (Optional)</label>
                        <input type="text" name="code" id="accountCodeInput" placeholder="Auto-generated if left blank" class="font-mono">
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3" placeholder="Optional notes about this account"></textarea>
                    </div>
                    
                    <div style="margin-top: 24px; text-align: right;">
                        <button type="submit" class="btn btn-primary">Save Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('accountTypeSelect').addEventListener('change', async function() {
    const type = this.value;
    const codeInput = document.getElementById('accountCodeInput');
    if (!type) { codeInput.value = ''; return; }
    codeInput.placeholder = 'Generating code...';
    try {
        const formData = new FormData();
        formData.append('action', 'get_next_code');
        formData.append('type', type);
        const resp = await fetch('../api/accounts.php', { method: 'POST', body: formData });
        const result = await resp.json();
        if (result.success) {
            codeInput.value = result.next_code;
        } else {
            codeInput.placeholder = 'Auto-generated if left blank';
        }
    } catch (e) {
        codeInput.placeholder = 'Auto-generated if left blank';
    }
});

document.getElementById('createAccountForm').addEventListener('submit', async function(e) { 
    e.preventDefault(); 
    const btn = this.querySelector('button[type="submit"]'); 
    btn.disabled = true; 
    btn.textContent = 'Saving...'; 
    const alert = document.getElementById('alertMessage'); 
    alert.style.display = 'none'; 
    
    try { 
        const formData = new FormData(this); 
        formData.append('action', 'create'); 
        const response = await fetch('../api/accounts.php', { method: 'POST', body: formData }); 
        const result = await response.json(); 
        
        if (result.success) { 
            alert.className = 'alert alert-success'; 
            alert.textContent = 'Account created successfully! Redirecting...'; 
            alert.style.display = 'block'; 
            setTimeout(() => window.location.href = 'chart-of-accounts.php', 1500); 
        } else { 
            throw new Error(result.message || 'Failed to create account'); 
        } 
    } catch (error) { 
        alert.className = 'alert alert-error'; 
        alert.textContent = error.message; 
        alert.style.display = 'block'; 
        btn.disabled = false; 
        btn.textContent = 'Save Account'; 
    } 
});
</script>
</body>
</html>
