<?php
require_once '../../includes/functions.php';
requireFinanceOrAdmin(); // Only finance users and admins can access
ensurePettyCashSchema();

global $pdo;
$user_id = $_SESSION['user_id'] ?? 0;
$is_admin = ($_SESSION['role'] ?? '') === 'admin';

// Handle replenishment request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request') {
    $amount = (float)$_POST['amount'];
    if ($amount > 0) {
        $current_balance = getPettyCashBalance($user_id);
        
        $stmt = $pdo->prepare("
            INSERT INTO petty_cash_replenishments 
            (replenishment_number, date, custodian_id, amount, previous_balance, new_balance, status, created_by) 
            VALUES (?, CURRENT_DATE, ?, ?, ?, ?, 'pending', ?)
        ");
        
        $rep_number = 'REP-' . date('Ym') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $new_balance = $current_balance + $amount; // This is projected balance, actual update happens on approval
        
        $stmt->execute([$rep_number, $user_id, $amount, $current_balance, $new_balance, $user_id]);
        
        header('Location: replenishment.php?success=requested');
        exit;
    }
}

// Handle approval (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve' && $is_admin) {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("SELECT * FROM petty_cash_replenishments WHERE id = ? AND status = 'pending'");
    $stmt->execute([$id]);
    $rep = $stmt->fetch();
    
    if ($rep) {
        // Update replenishment status
        $stmt = $pdo->prepare("UPDATE petty_cash_replenishments SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$user_id, $id]);
        
        // Update actual balance
        updatePettyCashBalance($rep['custodian_id'], $rep['amount'], 'add');
        
        header('Location: replenishment.php?success=approved');
        exit;
    }
}

// Get history
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name as custodian_name, a.full_name as approved_by_name 
    FROM petty_cash_replenishments r 
    LEFT JOIN users u ON r.custodian_id = u.id 
    LEFT JOIN users a ON r.approved_by = a.id 
    ORDER BY r.created_at DESC
");
$stmt->execute();
$history = $stmt->fetchAll();

$current_balance = getPettyCashBalance($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Petty Cash Replenishment</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        /* Header styles removed, using shared header */
        
        .main-content {
            padding: 24px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .grid-layout {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 24px;
        }
        
        .card {
            background: white;
            padding: 24px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 24px;
        }
        
        .card-header {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #111827;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: #555;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            width: 100%;
            justify-content: center;
        }
        .btn-primary {
            background: #1a73e8;
            color: white;
        }
        .btn-secondary {
            background: #fff;
            color: #202124;
            border: 1px solid #dadce0;
            width: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            text-align: left;
            padding: 12px;
            color: #555;
            background: #f8f9fa;
            border-bottom: 1px solid #ddd;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 11px;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fce8e6; color: #c5221f; }
        
        .balance-display {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        
        @media (max-width: 900px) {
            .grid-layout { grid-template-columns: 1fr; }
            .header-content { padding: 6px 12px; }
            .company-logo-img { height: 36px; }
            .header-info h1 { font-size: 12px; }
        }
    </style>
</head>
<body class="dashboard">
    <?php 
    $logoBase = '../../';
    include '../../includes/header_employee.php'; 
    ?>
    
    <main class="main-content">
        <div class="page-header" style="margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">Petty Cash Replenishment</h2>
            <p style="color: #6b7280; font-size: 13px; margin-top: 4px;">Request and view replenishment history</p>
        </div>
        <div class="grid-layout">
            <!-- Request Form -->
            <div>
                <div class="card">
                    <div class="card-header">Current Balance</div>
                    <div class="balance-display">TSh <?= number_format($current_balance, 2) ?></div>
                    <p style="color: #666; font-size: 13px;">Available funds in petty cash</p>
                </div>
                
                <div class="card">
                    <div class="card-header">Request Replenishment</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="request">
                        <div class="form-group">
                            <label>Amount (TSh)</label>
                            <input type="number" name="amount" required min="1" step="0.01" placeholder="Enter amount...">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <svg style="width: 18px; height: 18px;" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Submit Request
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- History -->
            <div class="card">
                <div class="card-header">Replenishment History</div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Ref #</th>
                                <th>Date</th>
                                <th>Requested By</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                                <tr><td colspan="6" style="text-align: center; padding: 32px; color: #666;">No replenishment history found</td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $item): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($item['replenishment_number']) ?></strong></td>
                                        <td><?= date('M d, Y', strtotime($item['date'])) ?></td>
                                        <td><?= htmlspecialchars($item['custodian_name']) ?></td>
                                        <td style="font-weight: 600;">TSh <?= number_format($item['amount'], 2) ?></td>
                                        <td>
                                            <span class="badge badge-<?= $item['status'] ?>">
                                                <?= ucfirst($item['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($item['status'] === 'pending' && $is_admin): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                    <button type="submit" style="background: none; border: none; color: #059669; font-weight: 600; cursor: pointer; text-decoration: underline; font-size: 13px;">
                                                        Approve
                                                    </button>
                                                </form>
                                            <?php elseif ($item['status'] === 'approved'): ?>
                                                <span style="color: #666; font-size: 12px;">
                                                    by <?= htmlspecialchars($item['approved_by_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>

