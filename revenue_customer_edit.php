<?php
require_once 'includes/functions.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);
$error = $_GET['error'] ?? '';

if (!$id) {
    header("Location: revenue_customers.php?error=Missing customer ID");
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM revenue_customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        header("Location: revenue_customers.php?error=Customer not found");
        exit();
    }
} catch (PDOException $e) {
    header("Location: revenue_customers.php?error=" . urlencode($e->getMessage()));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Customer - <?= COMPANY_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            --accent-color: #2563eb;
            --success-color: #059669;
            --danger-color: #dc2626;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --card-radius: 12px;
        }

        body.revenue-page { 
            background: #f8fafc; 
            font-family: 'Poppins', sans-serif !important; 
            font-weight: 300 !important;
            font-size: 0.85rem;
            color: #1e293b;
        }

        .main-content {
            padding: 2rem;
            max-width: 800px;
            margin: 0 auto;
        }

        .rev-card {
            background: #fff;
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
            border: none;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .rev-card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #f1f5f9;
            background: #fff;
        }

        .rev-card-header h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600 !important;
            color: #0f172a;
        }

        .rev-card-body { padding: 2rem; }

        .rev-form-group { margin-bottom: 1.5rem; }
        
        .rev-form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .rev-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s;
            font-family: 'Poppins', sans-serif;
            color: #1e293b;
            background: #f8fafc;
        }

        .rev-input:focus {
            border-color: var(--accent-color);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .rev-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600 !important;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
        }

        .rev-btn-primary { 
            background: #0f172a; 
            color: #fff; 
            width: 100%;
        }
        .rev-btn-primary:hover { background: #1e293b; transform: translateY(-1px); }

        .rev-btn-outline {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #475569;
            padding: 0.6rem 1rem;
            font-size: 0.85rem;
        }
        .rev-btn-outline:hover { background: #f8fafc; }

    </style>
</head>
<body class="revenue-page">
    <?php require_once 'includes/header_employee.php'; ?>
    <main class="main-content">
        
        <div class="rev-header" style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:2rem;">
            <div>
                <h1 style="font-size:1.75rem; font-weight:700; color:#0f172a; margin:0;">Edit <span style="color:#64748b; font-weight:300;">Customer</span></h1>
                <p style="margin:0.25rem 0 0 0; color:#64748b; font-size:0.9rem;">Update client details and contact information.</p>
            </div>
            
            <a href="revenue_customers.php?module=revenue" class="rev-btn rev-btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Directory
            </a>
        </div>

        <?php if($error): ?>
            <div style="background:#fee2e2; color:#b91c1c; padding:1rem; border-radius:12px; margin-bottom:1.5rem; display:flex; align-items:center; gap:0.75rem; border:1px solid #fecaca; font-size: 0.9rem; font-weight: 500;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="rev-card">
            <div class="rev-card-header">
                <h2>Customer Data</h2>
            </div>
            <div class="rev-card-body">
                <form action="revenue_customer_process.php" method="POST">
                    <input type="hidden" name="action" value="edit_customer">
                    <input type="hidden" name="id" value="<?= $customer['id'] ?>">
                    
                    <div class="rev-form-group">
                        <label>Full Name / Company Name</label>
                        <input type="text" name="customer_name" class="rev-input" required value="<?= htmlspecialchars($customer['customer_name']) ?>">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="rev-form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" class="rev-input" value="<?= htmlspecialchars($customer['phone'] ?: '') ?>" placeholder="+255...">
                        </div>
                        <div class="rev-form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" class="rev-input" value="<?= htmlspecialchars($customer['email'] ?: '') ?>" placeholder="customer@example.com">
                        </div>
                    </div>

                    <div class="rev-form-group">
                        <label>Address / Location</label>
                        <textarea name="address" rows="3" class="rev-input" placeholder="Physical address..."><?= htmlspecialchars($customer['address'] ?: '') ?></textarea>
                    </div>

                    <button type="submit" class="rev-btn rev-btn-primary">
                        <i class="fas fa-save"></i> Update Customer
                    </button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
