<?php
require_once 'includes/functions.php';

// DEBUG LOGGING
file_put_contents(__DIR__ . '/debug_dashboard.log', date('H:i:s') . " - Dashboard accessed\n");

// Ensure bookkeeping columns referenced in queries are present
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();

// Handle quick approve / reject / delete actions (buttons from voucher listing pages)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['voucher_id'])) {
    $voucherId = (int)$_POST['voucher_id'];
    $action = $_POST['action'];
    try {
        if ($action === 'approved') {
            // Only admins can approve; employees should not see approve button targeting this page
            if (!isAdmin()) { throw new Exception('Not authorized to approve'); }
            $ok = approveVoucherByAdmin($voucherId, (int)$_SESSION['user_id']);
            if (!$ok) { throw new Exception('Approve failed'); }
            header('Location: my-vouchers.php?msg=approved');
            exit;
        } elseif ($action === 'rejected') {
            if (!isAdmin()) { throw new Exception('Not authorized to reject'); }
            $ok = rejectVoucherByAdmin($voucherId, (int)$_SESSION['user_id']);
            if (!$ok) { throw new Exception('Reject failed'); }
            header('Location: my-vouchers.php?msg=rejected');
            exit;
        } elseif ($action === 'delete') {
            // Allow creator deletion if not approved yet
            if (!canDeleteVoucher($voucherId, (int)$_SESSION['user_id'])) { throw new Exception('Cannot delete this voucher'); }
            deleteVoucherHard($voucherId, (int)$_SESSION['user_id']);
            header('Location: my-vouchers.php?msg=deleted');
            exit;
        }
    } catch (Exception $e) {
        // Fallback: redirect with error message (simple handling)
        header('Location: my-vouchers.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Stats summary (system-wide)
$stmt = $pdo->prepare("SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN pv.status='pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN pv.status='approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN pv.status='rejected' THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN IFNULL(pv.is_paid,0) = 1 THEN 1 ELSE 0 END) AS paid,
        SUM(CASE WHEN IFNULL(pv.is_posted,0) = 1 THEN 1 ELSE 0 END) AS posted,
        SUM(CASE WHEN LOWER(pv.status)='pending' AND (
                COALESCE(pv.payee_name,'') = '' OR COALESCE(pv.total_amount,0) <= 0 OR 
                NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id)
        ) THEN 1 ELSE 0 END) AS draft
    FROM payment_vouchers pv");
$stmt->execute();
$stats = $stmt->fetch();

// Recent vouchers ordering by numeric parts of voucher_no (PV/UGT/YYYY/NNN)
$sort = isset($_GET['sort']) ? strtolower($_GET['sort']) : 'newest';
if (!in_array($sort, ['newest','asc','desc'], true)) { $sort = 'newest'; }
$yearDir = ($sort === 'asc') ? 'ASC' : 'DESC';
$seqDir  = ($sort === 'asc') ? 'ASC' : 'DESC';
$orderBy = "ORDER BY \n    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(pv.voucher_no, '/', -2), '/', 1) AS UNSIGNED) $yearDir,\n    CAST(SUBSTRING_INDEX(pv.voucher_no, '/', -1) AS UNSIGNED) $seqDir";

$stmt = $pdo->prepare("\n    SELECT pv.id, pv.voucher_no, pv.payee_name, pv.currency, pv.total_amount, pv.status, pv.date_created,\n           IFNULL(pv.is_paid,0) AS is_paid, IFNULL(pv.is_posted,0) AS is_posted,\n           (SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count\n      FROM payment_vouchers pv\n      $orderBy\n      LIMIT 12\n");
$stmt->execute();
$recent = $stmt->fetchAll();

file_put_contents(__DIR__ . '/debug_dashboard.log', date('H:i:s') . " - Data fetched, rendering HTML\n", FILE_APPEND);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - Ultimate General Trading</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.dashboard .main-content { padding:16px 14px; }
        .stats-mini { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:8px; margin-bottom:14px; }
        .stats-mini .mini { border:1px solid #e5e7eb; background:#fff; padding:10px; border-radius:0; }
        .stats-mini .mini .n { font-size:20px; font-weight:600; line-height:1; color:#111; }
        .stats-mini .mini .l { font-size:11px; color:#555; margin-top:4px; }
        body.dashboard .actions { margin-bottom:16px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        body.dashboard .actions .btn { padding:6px 12px; font-size:12px; border-radius:0; }
        body.dashboard .form-container { padding:16px; border-radius:0; }
        body.dashboard .form-container h2 { font-size:16px; margin-bottom:10px; }
        body.dashboard .data-table { border-radius:0; margin-bottom:16px; }
        body.dashboard .data-table th { padding:10px; font-size:12px; }
        body.dashboard .data-table td { padding:8px 10px; font-size:12px; }
        body.dashboard .company-logo-img { height:62px; }
        /* Keep desktop view for recent vouchers tables on mobile - allow horizontal scroll */
        @media (max-width:640px){
            /* Only apply mobile styles to tables that are NOT recent vouchers */
            body.dashboard .data-table:not(.recent-admin):not(.recent-employee) th{padding:6px; font-size:11px;}
            body.dashboard .data-table:not(.recent-admin):not(.recent-employee) td{padding:5px 6px; font-size:11px;}
            
            /* Recent vouchers tables: Keep desktop appearance with horizontal scroll */
            body.dashboard .table-wrap.recent-vouchers-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                width: 100%;
                margin: 0;
                position: relative;
            }
            
            body.dashboard .table-wrap.recent-vouchers-wrapper table.recent-admin,
            body.dashboard .table-wrap.recent-vouchers-wrapper table.recent-employee {
                min-width: 900px;
                width: 100%;
            }
            
            body.dashboard .table-wrap.recent-vouchers-wrapper table.recent-admin th,
            body.dashboard .table-wrap.recent-vouchers-wrapper table.recent-admin td,
            body.dashboard .table-wrap.recent-vouchers-wrapper table.recent-employee th,
            body.dashboard .table-wrap.recent-vouchers-wrapper table.recent-employee td {
                padding: 10px !important;
                font-size: 12px !important;
                white-space: nowrap;
            }
            
            body.dashboard .table-wrap.recent-vouchers-wrapper table.recent-admin td small,
            body.dashboard .table-wrap.recent-vouchers-wrapper table.recent-employee td small {
                display: inline-block !important;
            }
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/includes/header_employee.php'; ?>

    <main class="main-content">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
            <div class="success-message">
                Voucher created successfully.
            </div>
        <?php endif; ?>
        <div class="stats-mini">
            <div class="mini"><div class="n"><?= (int)($stats['total'] ?? 0) ?></div><div class="l">Total Vouchers</div></div>
            <div class="mini"><div class="n"><?= (int)($stats['pending'] ?? 0) ?></div><div class="l">Pending Approval</div></div>
            <div class="mini"><div class="n"><?= (int)($stats['approved'] ?? 0) ?></div><div class="l">Approved</div></div>
            <div class="mini"><div class="n"><?= (int)($stats['rejected'] ?? 0) ?></div><div class="l">Rejected</div></div>
            <div class="mini"><div class="n"><?= (int)($stats['paid'] ?? 0) ?></div><div class="l">Paid</div></div>
            <div class="mini"><div class="n"><?= (int)($stats['posted'] ?? 0) ?></div><div class="l">Posted</div></div>
            <div class="mini"><div class="n"><?= (int)($stats['draft'] ?? 0) ?></div><div class="l">Draft</div></div>
        </div>

        <div class="actions">
            <a href="employee/user-manual.php" class="btn" style="background: #FF902F; color: white;"><i class="fas fa-book-open me-1"></i> User Guide</a>
            <a href="create-voucher.php" class="btn">Create New Voucher</a>
            <a href="my-vouchers.php" class="btn btn-secondary">View All Vouchers</a>
            <!-- ERP Link hidden <a href="../erp/index.php" class="btn" style="background: #1a73e8; color: white;">📊 ERP System</a> -->
        </div>

        <div class="form-container">
            <h2>Recent Vouchers</h2>
            <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <label for="sortVoucherNo" style="font-size:12px; color:#374151;">Sort:</label>
                <select id="sortVoucherNo" onchange="applySort(this.value)" style="padding:6px 8px; font-size:12px;">
                    <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest voucher no.</option>
                    <option value="asc" <?= $sort==='asc'?'selected':'' ?>>Voucher no. ascending</option>
                    <option value="desc" <?= $sort==='desc'?'selected':'' ?>>Voucher no. descending</option>
                </select>
            </div>

            <?php if (empty($recent)): ?>
                <p>No vouchers found.</p>
            <?php else: ?>
            <div class="table-wrap recent-vouchers-wrapper">
            <table class="data-table recent-employee">
                <thead>
                    <tr>
                        <th>Voucher No.</th>
                        <th>Payee</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Docs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $v): ?>
                    <?php 
                        $isPaidFlag = (int)($v['is_paid'] ?? 0) === 1;
                        $isPostedFlag = (int)($v['is_posted'] ?? 0) === 1;
                        $derivedStatus = $v['status'];
                        $looksDraft = !$isPaidFlag && strtolower($v['status']) === STATUS_PENDING && ((float)$v['total_amount'] <= 0);
                        if ($looksDraft) { $derivedStatus = STATUS_DRAFT; }
                        $ac = (int)($v['attachment_count'] ?? 0);
                    ?>
                    <tr onclick="window.location.href='view-voucher.php?id=<?= (int)$v['id'] ?>'" style="cursor: pointer;">
                        <td><?= htmlspecialchars($v['voucher_no']) ?></td>
                        <td><?= htmlspecialchars($v['payee_name']) ?></td>
                        <td><?= htmlspecialchars($v['currency']) ?> <?= number_format((float)$v['total_amount'], 2) ?></td>
                        <td><?= date('d/m/Y', strtotime($v['date_created'])) ?></td>
                        <td>
                            <?php if ($isPostedFlag): ?>
                                <span class="status-badge" style="color:#facc15;">Posted</span>
                            <?php elseif ($isPaidFlag): ?>
                                <span class="status-badge status-approved">Paid</span>
                            <?php else: ?>
                                <span class="status-badge <?= 'status-' . htmlspecialchars($derivedStatus) ?>"><?php echo ucfirst($derivedStatus); ?></span>
                            <?php endif; ?>
                        </td>
                        <td onclick="event.stopPropagation()">
                            <?php if ($ac > 0): ?>
                                <a href="view-voucher.php?id=<?= (int)$v['id'] ?>#attachments" class="icon-link icon-neutral" title="View attachments">
                                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M17.657 6.343a4.5 4.5 0 010 6.364l-7.071 7.071a3 3 0 01-4.243-4.243l7.07-7.071a1.5 1.5 0 012.122 2.122l-7.07 7.071" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span style="font-size:10px; margin-left:2px; vertical-align:middle;"><?= $ac ?></span>
                                </a>
                            <?php else: ?>
                                <span style="font-size:11px; color:#666;">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <!-- View icon removed; row is clickable -->
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function applySort(val){
            const url = new URL(window.location.href);
            url.searchParams.set('sort', val);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>