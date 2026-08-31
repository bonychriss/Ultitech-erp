<?php
require_once '../includes/functions.php';
requireAdmin();

// Quick stats
$stmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status IN ('pending', 'confirming') THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
FROM payment_vouchers");
$stats = $stmt->fetch();

// Recent pending vouchers (top 5)
$stmt = $pdo->query("SELECT pv.id, pv.voucher_no, pv.payee_name, pv.total_amount, pv.currency, pv.date_created, pv.prepared_by,
                            IFNULL(pv.is_paid,0) AS is_paid,
                            IFNULL(pv.is_posted,0) AS is_posted,
                            (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
                            u.full_name AS creator_name, u.department
                     FROM payment_vouchers pv
                     LEFT JOIN users u ON pv.created_by = u.id
                     WHERE pv.status IN ('pending', 'confirming')
                     ORDER BY CASE WHEN pv.status = 'confirming' THEN 0 ELSE 1 END, pv.created_at ASC
                     LIMIT 5");
$pending = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Home - Ultimate General Trading</title>
  <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
</head>
<body class="dashboard">
<?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

<main class="main-content">
  <div class="dashboard-stats">
    <div class="stat-card">
      <div class="stat-number"><?= (int)$stats['total'] ?></div>
      <div class="stat-label">Total Vouchers</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= (int)$stats['pending'] ?></div>
      <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= (int)$stats['approved'] ?></div>
      <div class="stat-label">Approved</div>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= (int)$stats['rejected'] ?></div>
      <div class="stat-label">Rejected</div>
    </div>
  </div>

  <div class="actions">
    <a href="dashboard.php" class="btn">Open Admin Dashboard</a>
    <a href="all-vouchers.php" class="btn btn-secondary">All Vouchers</a>
    <a href="manage-users.php" class="btn btn-secondary">Manage Users</a>
    <a href="reports.php" class="btn btn-secondary">Reports</a>
  </div>

  <div class="form-container">
    <h2>Pending Approvals (5 latest)</h2>
    <?php if (empty($pending)): ?>
      <p>No pending vouchers at the moment.</p>
    <?php else: ?>
  <div class="table-wrap stacked-table">
  <table class="data-table">
        <thead>
          <tr>
            <th>Voucher No.</th>
            <th>Payee</th>
            <th>Prepared By</th>
            <th>Amount</th>
            <th>Date</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($pending as $v): ?>
          <tr>
            <td data-label="Voucher No."><?= htmlspecialchars($v['voucher_no']) ?></td>
            <td data-label="Payee"><?= htmlspecialchars($v['payee_name']) ?></td>
            <td data-label="Prepared By">
              <?php
                $prep = trim((string)($v['prepared_by'] ?? ''));
                if ($prep === '' && !empty($v['creator_name'])) {
                  $prep = $v['creator_name'];
                }
                echo htmlspecialchars($prep !== '' ? $prep : 'â€”');
              ?>
              <br><small><?= htmlspecialchars($v['department'] ?? '') ?></small>
            </td>
            <td data-label="Amount"><?= htmlspecialchars($v['currency']) ?> <?= number_format($v['total_amount'], 2) ?></td>
            <td data-label="Date"><?= date('d/m/Y', strtotime($v['date_created'])) ?></td>
            <td data-label="Status">
              <?php
                $isPaidFlag = (int)($v['is_paid'] ?? 0) === 1;
                $isPostedFlag = (int)($v['is_posted'] ?? 0) === 1;
                $derivedStatus = 'pending';
                $looksDraft = !$isPaidFlag
                  && (empty($v['payee_name']) || (float)$v['total_amount'] <= 0 || (int)($v['item_count'] ?? 0) === 0);
                if ($looksDraft) { $derivedStatus = STATUS_DRAFT; }
              ?>
              <?php if ($isPostedFlag): ?>
                <span class="status-badge" style="color:#facc15;">Posted</span>
              <?php elseif ($isPaidFlag): ?>
                <span class="status-badge status-approved">Paid</span>
              <?php else: ?>
                <span class="status-badge <?= 'status-' . htmlspecialchars($derivedStatus) ?>"><?= ucfirst($derivedStatus) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Actions">
              <a href="../employee/view-voucher.php?id=<?= $v['id'] ?>" class="icon-link icon-neutral" title="View" aria-label="View voucher" style="margin-right:6px;">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
                  <path d="M12 5c-7.633 0-11 7-11 7s3.367 7 11 7 11-7 11-7-3.367-7-11-7zm0 12a5 5 0 110-10 5 5 0 010 10zm0-2.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/>
                </svg>
              </a>
              <form method="POST" action="dashboard.php" style="display:inline-block;">
                <input type="hidden" name="voucher_id" value="<?= $v['id'] ?>">
                <input type="hidden" name="action" value="approved">
                <button type="submit" class="btn btn-success" style="padding:5px 8px; font-size:12px;">Approve</button>
              </form>
              <form method="POST" action="dashboard.php" style="display:inline-block; margin-left:6px;">
                <input type="hidden" name="voucher_id" value="<?= $v['id'] ?>">
                <input type="hidden" name="action" value="rejected">
                <button type="submit" class="btn btn-danger" style="padding:5px 8px; font-size:12px;">Reject</button>
              </form>
              <!-- Mark Paid action is finance-only; not shown on admin home list -->
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
  </table>
  </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>

