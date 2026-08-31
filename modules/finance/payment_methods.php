<?php
// modules/finance/payment_methods.php
require_once '../../includes/functions.php';
requireLogin();

// Access Control: Admins or Finance only
if (!isAdmin() && !isFinance()) {
    header("Location: ../../index.php");
    exit;
}

// Fetch Accounts from the NEW Balances Module
// We display them here read-only, management happens in Balances
try {
    $accounts = $pdo->query("SELECT * FROM financial_accounts WHERE status = 'active' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $accounts = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Accounts - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .balance-card {
            transition: transform 0.2s;
        }
        .balance-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="bg-light">

<?php include '../../includes/header_employee.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-dark mb-0">Payment Accounts</h2>
            <a href="../balances/accounts.php" class="btn btn-primary">
                <i class="fas fa-cog me-2"></i>Manage Accounts in Balances
            </a>
        </div>
        
        <!-- Nav -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link" href="my_expenses.php">To Report</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="my_reports.php">My Reports</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="approvals.php">To Approve</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="categories.php">Categories</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="payment_methods.php">Payment Accounts</a>
            </li>
        </ul>

        <?php if (empty($accounts)): ?>
            <div class="alert alert-info d-flex align-items-center">
                <i class="fas fa-info-circle me-3 fs-4"></i>
                <div>
                    <strong>No active accounts found.</strong><br>
                    Please go to the <a href="../balances/accounts.php" class="alert-link">Balances Module</a> to set up your financial accounts (Bank, Cash, Mobile).
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Account Name</th>
                                        <th>Type</th>
                                        <th>Currency</th>
                                        <th class="text-end pe-4">Current Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($accounts as $acc): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark">
                                            <?php if ($acc['type'] == 'bank'): ?>
                                                <i class="fas fa-university me-2 text-primary"></i>
                                            <?php elseif ($acc['type'] == 'mobile'): ?>
                                                <i class="fas fa-mobile-alt me-2 text-warning"></i>
                                            <?php else: ?>
                                                <i class="fas fa-coins me-2 text-success"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($acc['name']) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= ucfirst($acc['type']) ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($acc['currency']) ?>
                                        </td>
                                        <td class="text-end pe-4 fw-bold">
                                            <?= number_format($acc['current_balance'], 2) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="mt-3 text-muted small">
                        <i class="fas fa-info-circle me-1"></i> 
                        These accounts are managed in the <strong>Balances Module</strong>. Changes made there will reflect here automatically.
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
