<?php
require_once '../../includes/functions.php';
requireFinanceOrAdmin(); // Only finance users and admins can access
ensurePettyCashSchema();

global $pdo;
$user_id = $_SESSION['user_id'] ?? 0;

// Get current balance for user
$current_balance = getPettyCashBalance($user_id);

// Get all vouchers (filter by user if not admin)
$filters = [];
if ($_SESSION['role'] !== 'admin') {
    $filters['custodian_id'] = $user_id;
}
$vouchers = getAllPettyCashVouchers($filters);

// Get statistics
$stats = [
    'total_vouchers' => count($vouchers),
    'pending' => count(array_filter($vouchers, fn($v) => $v['status'] === 'pending')),
    'approved' => count(array_filter($vouchers, fn($v) => $v['status'] === 'approved')),
    'current_balance' => $current_balance
];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_SESSION['role'] === 'admin') {
    $voucher_id = (int)$_POST['voucher_id'];
    
    if ($_POST['action'] === 'approve_voucher') {
        if (approvePettyCashVoucher($voucher_id, $user_id)) {
            header('Location: index.php?success=approved');
            exit;
        }
    } elseif ($_POST['action'] === 'reject_voucher') {
        $reason = trim($_POST['reason'] ?? '');
        if (rejectPettyCashVoucher($voucher_id, $user_id, $reason)) {
            header('Location: index.php?success=rejected');
            exit;
        }
    }
}

// Get categories
$categories = getPettyCashCategories();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Petty Cash Management</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <script>
        function rejectVoucher(id) {
            const reason = prompt("Please enter rejection reason:");
            if (reason !== null && reason.trim() !== "") {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'reject_voucher';
                form.appendChild(actionInput);
                
                const idInput = document.createElement('input');
                idInput.name = 'voucher_id';
                idInput.value = id;
                form.appendChild(idInput);
                
                const reasonInput = document.createElement('input');
                reasonInput.name = 'reason';
                reasonInput.value = reason;
                form.appendChild(reasonInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
    <style>
        :root {
            --primary-color: #2563eb;
            --text-main: #111827;
            --text-muted: #6b7280;
            --border-color: #e5e7eb;
            --bg-body: #f3f4f6;
            --card-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            background: var(--bg-body); 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--text-main);
            overflow-x: hidden;
        }
        /* Layout handled by sidebar.php/style.css */
    </style>
</head>
<body>
<?php 
$logoBase = '../../';
include '../../includes/header_employee.php'; 
?>

<style>
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 0;
            border: 1px solid var(--border-color);
            box-shadow: none;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-shadow);
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 500;
            letter-spacing: 0.025em;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }

        /* Section Container */
        .section {
            background: white;
            border-radius: 0;
            border: 1px solid var(--border-color);
            box-shadow: none;
            overflow: hidden; /* For rounded corners on children */
        }

        .section-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            background: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-main);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid transparent;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }

        .btn-secondary {
            background: white;
            border-color: var(--border-color);
            color: var(--text-main);
        }
        .btn-secondary:hover {
            background-color: #f9fafb;
            border-color: #d1d5db;
        }

        /* Table */
        table {
            width: 100%;
            border-collapse: separate; /* Allows border radius if needed, mostly reset here */
            border-spacing: 0;
        }

        th {
            background: #f9fafb;
            padding: 0.75rem 1.5rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border-color);
        }

        td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.875rem;
            color: #374151;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: #f9fafb;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
        }

        .badge-pending { background: #fef3c7; color: #b45309; }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }

        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: 1fr; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .main-content { padding: 1rem; }
        }
</style>
    
    <main class="main-content">
        
        <!-- Page Header -->
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem;">Overview</h2>
                <p style="color: var(--text-muted); font-size: 0.875rem;">Manage your petty cash vouchers and requests</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="replenishment.php" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    New Request
                </a>
                <a href="reports.php" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    Reports
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Current Balance</div>
                <div class="stat-value">TSh <?= number_format($stats['current_balance'], 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Vouchers</div>
                <div class="stat-value"><?= $stats['total_vouchers'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Approval</div>
                <div class="stat-value"><?= $stats['pending'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Approved</div>
                <div class="stat-value"><?= $stats['approved'] ?></div>
            </div>
        </div>
        
        <!-- Vouchers List -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">Recent Transactions</div>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Voucher #</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vouchers)): ?>
                            <tr><td colspan="7" style="text-align: center; padding: 32px; color: #666;">No vouchers found. Create your first voucher!</td></tr>
                        <?php else: ?>
                            <?php foreach ($vouchers as $voucher): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($voucher['voucher_number']) ?></strong></td>
                                    <td style="white-space: nowrap;"><?= date('M d, Y', strtotime($voucher['date'])) ?></td>
                                    <td><?= htmlspecialchars($voucher['category']) ?></td>
                                    <td><?= htmlspecialchars(substr($voucher['description'], 0, 50)) ?><?= strlen($voucher['description']) > 50 ? '...' : '' ?></td>
                                    <td style="font-weight: 500;">TSh <?= number_format($voucher['amount'], 2) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $voucher['status'] ?>">
                                            <?= ucfirst($voucher['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view-voucher.php?id=<?= $voucher['id'] ?>" style="color: #007bff; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 4px;">
                                            <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                            View
                                        </a>
                                        <?php if ($voucher['status'] === 'pending' && $_SESSION['role'] === 'admin'): ?>
                                            <span style="color: #ddd; margin: 0 6px;">|</span>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="approve_voucher">
                                                <input type="hidden" name="voucher_id" value="<?= $voucher['id'] ?>">
                                                <button type="submit" style="background: none; border: none; color: #059669; font-weight: 500; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 4px; font-family: inherit; font-size: inherit;" onclick="return confirm('Approve this voucher?')">
                                                    <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                    Approve
                                                </button>
                                            </form>
                                            
                                            <span style="color: #ddd; margin: 0 6px;">|</span>
                                            
                                            <button type="button" style="background: none; border: none; color: #dc2626; font-weight: 500; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 4px; font-family: inherit; font-size: inherit;" onclick="rejectVoucher(<?= $voucher['id'] ?>)">
                                                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                    <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                </svg>
                                                Reject
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>

