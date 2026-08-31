<?php
require_once '../includes/functions.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    header('Location: ../dashboard.php');
    exit;
}

// Handle Lock Toggle Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_restricted' && isset($_POST['voucher_id'])) {
    
    // Auth check logic
    $voucherId = (int) $_POST['voucher_id'];
    
    // Fetch current state and creator department
    $stmt = $pdo->prepare("SELECT pv.is_restricted, u.department AS creator_department FROM payment_vouchers pv LEFT JOIN users u ON pv.created_by = u.id WHERE pv.id = ?");
    $stmt->execute([$voucherId]);
    $row = $stmt->fetch();
    
    if ($row) {
        $isAuth = isAdmin();
        if (!$isAuth && isFinance()) {
            $creatorDept = strtolower(trim((string) $row['creator_department']));
            if ($creatorDept === 'finance') {
                $isAuth = true;
            }
        }
        
        if ($isAuth) {
            $newState = $row['is_restricted'] == 1 ? 0 : 1;
            $up = $pdo->prepare("UPDATE payment_vouchers SET is_restricted = ? WHERE id = ?");
            $up->execute([$newState, $voucherId]);
            $_SESSION['success_msg'] = $newState ? "Voucher locked successfully." : "Voucher unlocked successfully.";
        } else {
            $_SESSION['error_msg'] = "Not authorized to lock this voucher.";
        }
    }
    header('Location: lock_vouchers.php?module=voucher');
    exit;
}

// Fetch Vouchers (company-scoped; raise cap so admin can manage locks across the desk)
$sort = isset($_GET['sort']) ? strtolower($_GET['sort']) : 'newest';
$yearDir = ($sort === 'asc') ? 'ASC' : 'DESC';
$seqDir = ($sort === 'asc') ? 'ASC' : 'DESC';
$orderBy = "ORDER BY CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(pv.voucher_no, '/', -2), '/', 1) AS UNSIGNED) $yearDir, CAST(SUBSTRING_INDEX(pv.voucher_no, '/', -1) AS UNSIGNED) $seqDir";

$scopeSql = '';
$scopeParams = [];
if (function_exists('companyScopeSql')) {
    list($scopeFrag, $scopeParams) = companyScopeSql('payment_vouchers', 'pv');
    if ($scopeFrag !== '') {
        $scopeSql = ' WHERE 1=1' . $scopeFrag;
    }
} elseif (function_exists('getCompanySql')) {
    $companySql = getCompanySql('pv');
    if ($companySql !== '') {
        $scopeSql = ' WHERE 1=1' . $companySql;
        $scopeParams = getCompanyParam();
    }
}

$stmt = $pdo->prepare("
    SELECT pv.id, pv.voucher_no, pv.payee_name, pv.currency, pv.total_amount, pv.status, pv.date_created, 
           pv.is_restricted, pv.is_paid, pv.is_posted,
           (SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count,
           u.department AS creator_department,
           pv.created_by
    FROM payment_vouchers pv
    LEFT JOIN users u ON pv.created_by = u.id
    $scopeSql
    $orderBy
    LIMIT 2000
");
$stmt->execute($scopeParams);
$vouchers = $stmt->fetchAll();

// Users for WhatsApp
$waUsers = $pdo->query("SELECT id, full_name, whatsapp_number FROM users WHERE whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Voucher Locks - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        body.dashboard .main-content { padding: 20px; }
        .data-table { width: 100%; border-collapse: collapse; background: white; }
        .data-table th, .data-table td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; font-size: 13px; }
        .data-table th { background: #f9fafb; font-weight: 600; }
        
        /* Toggle Switch */
        .switch { position: relative; display: inline-block; width: 34px; height: 20px; margin-bottom: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #EF4444; } /* Red for Locked */
        input:checked + .slider:before { transform: translateX(14px); }
        
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
        .status-pending { background: #fee2e2; color: #b91c1c; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-paid { background: #dcfce7; color: #15803d; }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <main class="main-content">
        <div class="card" style="border-radius:0; border:none; box-shadow:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 style="margin:0;">Recent Vouchers (Lock Management)</h2>
                <div>
                     <label style="font-size:12px;">Sort:</label>
                     <select onchange="window.location.href='?module=voucher&sort='+this.value" style="padding:5px;">
                         <option value="newest" <?= $sort == 'newest' ? 'selected' : '' ?>>Newest</option>
                         <option value="asc" <?= $sort == 'asc' ? 'selected' : '' ?>>Oldest</option>
                     </select>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Voucher No.</th>
                            <th>Payee</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Lock</th> <!-- The requested lock column -->
                            <th>WhatsApp</th>
                            <th>Docs</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($vouchers as $v): ?>
                            <?php 
                                $isRestricted = !empty($v['is_restricted']) && $v['is_restricted'] == 1;
                                $status = ucfirst($v['status']);
                                if($v['is_paid']) $status = 'Paid';
                                
                                // Determine if user can toggle lock (Admin + FinanceOwn)
                                $uDept = strtolower($_SESSION['department'] ?? '');
                                $isFinance = preg_match('/(finance|accounts|accounting)/i', $uDept);
                                $creatorDept = strtolower($v['creator_department'] ?? '');
                                $isCreatorFinance = preg_match('/(finance|accounts|accounting)/i', $creatorDept);
                                
                                $canLock = isAdmin() || ($isFinance && $isCreatorFinance);
                            ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($v['voucher_no']) ?>
                                    <?php if($isRestricted): ?> 🔒 <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($v['payee_name']) ?></td>
                                <td><?= htmlspecialchars($v['currency']) . ' ' . number_format($v['total_amount'], 2) ?></td>
                                <td><?= date('d/m/Y', strtotime($v['date_created'])) ?></td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($status) ?>"><?= $status ?></span>
                                </td>
                                <td>
                                    <?php if($canLock): ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="toggle_restricted">
                                            <input type="hidden" name="voucher_id" value="<?= $v['id'] ?>">
                                            <label class="switch" title="Toggle Lock">
                                                <input type="checkbox" onchange="this.form.submit()" <?= $isRestricted ? 'checked' : '' ?>>
                                                <span class="slider round"></span>
                                            </label>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#ccc;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="#" class="btn-icon" style="color:#25D366;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326z"/></svg>
                                    </a>
                                </td>
                                <td>
                                    <?php if($v['attachment_count'] > 0): ?>
                                        <a href="../view-voucher.php?id=<?= $v['id'] ?>#attachments">
                                            <?= $v['attachment_count'] ?>
                                        </a>
                                    <?php else: ?>
                                        0
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../view-voucher.php?id=<?= $v['id'] ?>" style="color:#2563eb;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>
