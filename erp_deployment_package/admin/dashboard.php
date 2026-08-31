<?php
// Enable error reporting for debugging on InfinityFree
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once '../includes/functions.php';
requireAdmin();
// Ensure voucher bookkeeping columns exist (swift_document, is_posted, posted_by, posted_at)
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();

$scanReport = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['security_scan'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } else {
    // Lightweight integrity scan: list suspicious files and PHP inside uploads
    $root = realpath(dirname(__DIR__));
    $suspicious = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
      $path = $file->getPathname();
      $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
      // Skip known folders that are blocked or large
      // Normalize path separators for portable checks
      $nrel = str_replace('\\', '/', $rel);
      // Skip large/irrelevant directories using fast prefix tests (avoids regex compilation issues on some hosts)
      if (strpos($nrel, '.git/') === 0 || strpos($nrel, 'laravel-core/') === 0 || strpos($nrel, 'tasks/') === 0 || strpos($nrel, 'vendor/') === 0) {
        continue;
      }
      // Flag any PHP-like file inside uploads/signatures/images directories (no regex needed)
      if ((strpos($nrel, 'assets/uploads/') === 0 || strpos($nrel, 'assets/signatures/') === 0 || strpos($nrel, 'assets/images/') === 0)
          && preg_match('/\.(php|phtml|php7|phar)$/i', $nrel)) {
        $suspicious[] = ['type'=>'php-in-upload','path'=>$rel];
        continue;
      }
      // Scan PHP for obfuscated payload signatures
      if ($file->isFile() && preg_match('/\.php[0-9]*$/i', $path)) {
        $size = $file->getSize();
        if ($size > 0 && $size < 512*1024) { // skip huge files
          $code = @file_get_contents($path, false, null, 0, 200000);
          if ($code !== false) {
            if (preg_match('/base64_decode\s*\(/i', $code) && preg_match('/eval\s*\(/i', $code)) {
              $suspicious[] = ['type'=>'eval-base64','path'=>$rel];
              continue;
            }
            if (preg_match('/(shell_exec|passthru|system)\s*\(/i', $code)) {
              $suspicious[] = ['type'=>'shell-call','path'=>$rel];
              continue;
            }
            if (preg_match('/gzinflate|gzuncompress|str_rot13/i', $code) && preg_match('/eval\s*\(/i', $code)) {
              $suspicious[] = ['type'=>'compressed-eval','path'=>$rel];
              continue;
            }
          }
        }
      }
    }
    $scanReport = $suspicious;
    if (empty($suspicious)) { $success = 'Security scan: no suspicious files found.'; }
  }
}

// 1) Stats summary
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
    ) THEN 1 ELSE 0 END) AS draft,
    SUM(CASE WHEN pv.status='approved' THEN pv.total_amount ELSE 0 END) AS approved_amount
  FROM payment_vouchers pv");
$stmt->execute();
$stats = $stmt->fetch();

$success = $error = null;

// 2) Handle approval / rejection / delete / mark paid / mark posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['voucher_id'])) {
    $voucher_id = (int)$_POST['voucher_id'];
    
    // Handle mark_paid and mark_posted separately
    if (isset($_POST['mark_paid']) && $_POST['mark_paid'] == '1') {
        $result = markVoucherPaid($voucher_id, (int)$_SESSION['user_id']);
        if ($result['ok']) {
            $success = 'Voucher marked as paid.';
        } else {
            $error = 'Error: ' . ($result['error'] ?? 'Failed to mark voucher as paid.');
        }
    } elseif (isset($_POST['mark_posted']) && $_POST['mark_posted'] == '1') {
        $result = markVoucherPosted($voucher_id, (int)$_SESSION['user_id']);
        if ($result['ok']) {
            $success = 'Voucher marked as posted.';
        } else {
            $error = 'Error: ' . ($result['error'] ?? 'Failed to mark voucher as posted.');
        }
    } elseif (isset($_POST['action']) && !empty($_POST['action'])) {
        $action = $_POST['action'];
        $comments = trim($_POST['comments'] ?? '');

        if (!in_array($action, ['approved','rejected','delete'], true)) {
            $error = 'Invalid action.';
    } else if ($action === 'delete') {
        // Admin-only hard delete
        try {
            $del = $pdo->prepare('DELETE FROM payment_vouchers WHERE id = ?');
            $del->execute([$voucher_id]);
            $success = 'Voucher deleted permanently.';
        } catch (Exception $e) { $error = 'Error deleting voucher: '.$e->getMessage(); }
    } else {
        // Approve or reject
        // Guard: block approving obvious drafts
    if ($action === 'approved') {
            try {
        $chk = $pdo->prepare("SELECT pv.status, pv.payee_name, pv.total_amount, pv.voucher_no, pv.approved_by,
          (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
          IFNULL(pv.is_paid,0) AS is_paid
          FROM payment_vouchers pv WHERE pv.id = ? LIMIT 1");
                $chk->execute([$voucher_id]);
                $row = $chk->fetch();
                if ($row) {
                    $isPaidFlag = (int)($row['is_paid'] ?? 0) === 1;
                    $looksDraft = !$isPaidFlag
                        && strtolower($row['status']) === STATUS_PENDING
                        && (empty($row['payee_name']) || (float)$row['total_amount'] <= 0 || (int)$row['item_count'] === 0);
                    if ($looksDraft) {
                        $error = 'Cannot approve a draft voucher. Please ask the creator to complete it first.';
                        $action = null; // neutralize
          } else {
            // Anomaly guard: Voucher already paid before admin approval -> block approval and notify
            $notApprovedYet = strtolower($row['status']) !== STATUS_APPROVED;
            if ($isPaidFlag && $notApprovedYet) {
              $error = 'Approval blocked: Voucher is already marked as PAID before admin approval.';
              $action = null; // neutralize
              // Notifications: alert admins and finance users
              try {
                $vno = (string)($row['voucher_no'] ?? ('#'.$voucher_id));
                createNotification([
                  'user_id' => null,
                  'audience' => 'admin',
                  'title' => 'Anomaly: Paid before approval',
                  'message' => sprintf('Voucher %s is marked PAID but not approved. Approval was blocked for investigation.', $vno),
                  'voucher_id' => $voucher_id,
                ]);
                // Notify all finance users individually
                $fu = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND LOWER(department) = 'finance'");
                $fu->execute();
                $financeUsers = $fu->fetchAll();
                if ($financeUsers) {
                  foreach ($financeUsers as $urow) {
                    createNotification([
                      'user_id' => (int)$urow['id'],
                      'audience' => 'user',
                      'title' => 'Approval blocked',
                      'message' => sprintf('Voucher %s: approval blocked because it was paid before admin approval. Please coordinate with Admin.', $vno),
                      'voucher_id' => $voucher_id,
                    ]);
                  }
                }
              } catch (Exception $eN) { /* ignore notify errors */ }
            }
                    }
                }
            } catch (Exception $eDraft) { error_log('Draft check failed: '.$eDraft->getMessage()); }
        }
        if ($action !== null) {
            try {
                $pdo->beginTransaction();

                if ($action === 'approved') {
                    // Map approver to GM name per business rule
                    $gmName = null;
                    try {
                        $approverStmt = $pdo->prepare('SELECT username, email, full_name FROM users WHERE id = ? LIMIT 1');
                        $approverStmt->execute([$_SESSION['user_id']]);
                        $approver = $approverStmt->fetch();
                        $approverEmail = strtolower(trim($approver['email'] ?? ''));
                        $approverUsername = trim($approver['username'] ?? '');
                        $approverFullName = trim($approver['full_name'] ?? '');
                        if ($approverEmail === 'rajabmwanyika@gmail.com') {
                            $gmName = 'RAJABU MWANYIKA';
                        } elseif ($approverEmail === 'rajabmsomali@gmail.com') {
                            $gmName = $approverFullName !== '' ? $approverFullName : ($approverUsername !== '' ? $approverUsername : null);
                        }
                    } catch (Exception $eMap) { error_log('Approver mapping failed: '.$eMap->getMessage()); }

                    $up = $pdo->prepare('UPDATE payment_vouchers SET status=?, approved_by=?, approved_at=NOW(), general_manager=? WHERE id=?');
                    $up->execute([$action, $_SESSION['user_id'], $gmName, $voucher_id]);
                } else { // rejected
                    $up = $pdo->prepare('UPDATE payment_vouchers SET status=?, approved_by=?, approved_at=NOW() WHERE id=?');
                    $up->execute([$action, $_SESSION['user_id'], $voucher_id]);
                }

                logVoucherAction($voucher_id, $_SESSION['user_id'], $action, $comments);
                $pdo->commit();
                try { notifyUserVoucherStatus($voucher_id, $action); } catch (Exception $eN) { /* ignore */ }
                $success = 'Voucher has been '.$action.' successfully.';
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = 'Error processing voucher: '.$ex->getMessage();
            }
        }
    }
    }
}

// 3) Recent vouchers list (prioritize pending vouchers, then sorted by numeric parts of voucher_no)
$sort = isset($_GET['sort']) ? strtolower($_GET['sort']) : 'newest';
if (!in_array($sort, ['newest','asc','desc'], true)) { $sort = 'newest'; }
$yearDir = ($sort === 'asc') ? 'ASC' : 'DESC';
$seqDir  = ($sort === 'asc') ? 'ASC' : 'DESC';

$stmt = $pdo->prepare("
    SELECT
        pv.id, pv.voucher_no, pv.payee_name, pv.description, pv.total_amount, pv.status, pv.date_created,
        pv.currency, pv.prepared_by, IFNULL(pv.is_paid,0) AS is_paid, IFNULL(pv.is_posted,0) AS is_posted,
        pv.supporting_documents, pv.approved_by, pv.created_at,
        (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
        (SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count,
        u.full_name AS creator_name, u.department,
        (SELECT role FROM users WHERE id = pv.approved_by LIMIT 1) AS approver_role
    FROM payment_vouchers pv
    LEFT JOIN users u ON pv.created_by = u.id
    ORDER BY 
        CASE WHEN pv.status = 'pending' THEN 0 ELSE 1 END,
        pv.created_at DESC,
        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(pv.voucher_no, '/', -2), '/', 1) AS UNSIGNED) $yearDir,
        CAST(SUBSTRING_INDEX(pv.voucher_no, '/', -1) AS UNSIGNED) $seqDir
    LIMIT 20
");
$stmt->execute();
$recent_vouchers = $stmt->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Admin Dashboard - Ultimate General Trading</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>" />
<style>
body.dashboard .main-content { padding:16px 14px; }
.stats-mini { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:8px; margin-bottom:14px; }
.stats-mini .mini { border:1px solid #e5e7eb; background:#fff; padding:10px; border-radius:0; }
.stats-mini .mini .n { font-size:20px; font-weight:600; line-height:1; color:#111; }
.stats-mini .mini .l { font-size:11px; color:#555; margin-top:4px; }
body.dashboard .actions { margin-bottom:16px; }
body.dashboard .actions .btn { padding:6px 12px; font-size:12px; border-radius:0; }
body.dashboard #searchInput { padding:6px 8px !important; width:220px !important; font-size:12px; border-radius:0; }
body.dashboard .form-container select { padding:6px 8px !important; font-size:12px; border-radius:0; }
body.dashboard .form-container { padding:16px; border-radius:0; }
body.dashboard .form-container h2 { font-size:16px; margin-bottom:10px; }
body.dashboard .data-table { border-radius:0; margin-bottom:16px; }
body.dashboard .data-table th { padding:10px; font-size:12px; }
body.dashboard .data-table td { padding:8px 10px; font-size:12px; }
body.dashboard .data-table .btn { padding:3px 8px !important; font-size:11px !important; border-radius:0; }
body.dashboard .data-table button[onclick*="showApprovalModal"] {
  background: none !important;
  border: none !important;
  padding: 0 !important;
  box-shadow: none !important;
  cursor: pointer;
  text-decoration: underline;
}
body.dashboard .data-table form[action="dashboard.php"] button[type="submit"][style*="Mark Paid"],
body.dashboard .data-table form[action="dashboard.php"] button[type="submit"][style*="Mark Posted"] {
  background: none !important;
  border: none !important;
  padding: 0 !important;
  box-shadow: none !important;
  cursor: pointer;
  text-decoration: underline;
}
body.dashboard #approvalModal > div { padding:16px !important; width:90%; max-width:420px; border-radius:0; }
body.dashboard #approvalModal textarea { border-radius:0; }
body.dashboard #approvalModal h3 { font-size:16px; margin-bottom:8px; }
body.dashboard #approvalModal .form-group label { font-size:12px; }
body.dashboard #approvalModal textarea { font-size:12px; padding:8px; }
body.dashboard #approvalModal button.btn { padding:6px 10px; font-size:12px; }
body.dashboard #approvalModal #modalSubmitBtn {
  background: none !important;
  border: none !important;
  padding: 0 !important;
  margin: 0 0 0 12px !important;
  box-shadow: none !important;
  cursor: pointer;
  text-decoration: underline;
  font-size: 12px;
}
body.dashboard .header .user-info > div p { font-size:11px; }
body.dashboard .header .user-info > div p:first-child { font-size:12px; }
body.dashboard .company-logo-img { height:62px; }
/* Reduce action icon sizes */
body.dashboard .data-table .icon-link .icon { width: 14px; height: 14px; }
body.dashboard .data-table .icon-btn .icon { width: 14px; height: 14px; }
/* Keep desktop view for recent vouchers tables on mobile - allow horizontal scroll */
@media (max-width:640px){
  /* Only apply mobile styles to tables that are NOT recent vouchers */
  body.dashboard .data-table:not(.recent-admin):not(.recent-employee) th{padding:6px; font-size:11px;}
  body.dashboard .data-table:not(.recent-admin):not(.recent-employee) td{padding:5px 6px; font-size:11px;}
  body.dashboard .data-table:not(.recent-admin):not(.recent-employee) td small{display:none;}
  
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
<?php require_once __DIR__.'/../includes/header_admin.php'; ?>
<main class="main-content">
<?php if ($success || $error): ?>
  <div class="toast-container no-print" aria-live="polite" aria-atomic="true">
    <div class="toast show" role="status"><?= htmlspecialchars($success ?? $error) ?></div>
  </div>
  <script>
  (function(){
    function initToast(){
      var t=document.querySelector('.toast'); if(!t) return;
      setTimeout(function(){ t.classList.remove('show'); t.classList.add('hide');
        var done=false; setTimeout(function(){ if(done) return; done=true; var c=t&&t.parentNode; if(c){ c.parentNode && c.parentNode.removeChild(c);} },600);
        t.addEventListener('transitionend',function(){ if(done) return; done=true; var c=t&&t.parentNode; if(c){ c.parentNode && c.parentNode.removeChild(c);} },{once:true});
      },3000);
    }
    if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',initToast); } else { initToast(); }
  })();
  </script>
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
  <a href="../employee/create-voucher.php" class="btn" style="background: #28a745; color: white;">+ Create Voucher</a>
  <a href="all-vouchers.php" class="btn">View All Vouchers</a>
  <a href="manage-users.php" class="btn btn-secondary">Manage Users</a>
  <a href="reports.php" class="btn btn-secondary">Reports</a>
  <a href="view-attendance.php" class="btn btn-secondary" style="background: #4caf50; color: white;">View Attendance</a>
</div>

  <div class="form-container">
    <!-- Security scan trigger (admin only) -->
    <form method="POST" action="dashboard.php" style="margin-bottom:12px;" onsubmit="return confirm('Run quick integrity scan now?');">
      <input type="hidden" name="security_scan" value="1" />
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
      <button type="submit" class="btn btn-secondary" style="font-size:12px;">Run Security Scan</button>
    </form>
  <h2>Recent Vouchers - Quick Actions</h2>
  <?php if (is_array($scanReport)): ?>
    <div class="form-container" style="margin:10px 0; background:#fff; border:1px solid #eee;">
      <h3 style="font-size:14px; margin:0 0 8px;">Security Scan Report</h3>
      <?php if (empty($scanReport)): ?>
        <p style="font-size:12px; color:#065f46;">No suspicious files detected.</p>
      <?php else: ?>
        <p style="font-size:12px;">Suspicious files found (review and remove if unauthorized):</p>
        <ul style="font-size:12px; line-height:1.4;">
          <?php foreach ($scanReport as $row): ?>
            <li><code><?= htmlspecialchars($row['path']) ?></code> <small style="color:#666;">(<?= htmlspecialchars($row['type']) ?>)</small></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div style="margin-bottom:20px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <input type="text" id="searchInput" placeholder="Search vouchers..." onkeyup="filterTable()" />
    <select onchange="filterByStatus(this.value)">
      <option value="all">All Statuses</option>
      <option value="pending">Pending</option>
      <option value="approved">Approved</option>
      <option value="rejected">Rejected</option>
    </select>
    <select id="sortVoucherNo" onchange="applySort(this.value)">
      <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest voucher no.</option>
      <option value="asc" <?= $sort==='asc'?'selected':'' ?>>Voucher no. ascending</option>
      <option value="desc" <?= $sort==='desc'?'selected':'' ?>>Voucher no. descending</option>
    </select>
  </div>

  <?php if (empty($recent_vouchers)): ?>
    <p>No vouchers found.</p>
  <?php else: ?>
  <div class="table-wrap recent-vouchers-wrapper">
  <table class="data-table recent-admin">
      <thead>
        <tr>
          <th>Voucher No.</th><th>Payee</th><th>Description</th><th>Prepared By</th><th>Amount</th><th>Date</th><th>Status</th><th>Docs</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recent_vouchers as $v): ?>
        <tr>
          <td data-label="Voucher No."><?= htmlspecialchars($v['voucher_no']) ?></td>
          <td data-label="Payee">&nbsp;<?= htmlspecialchars($v['payee_name']) ?></td>
          <td data-label="Description"><?= htmlspecialchars($v['description'] ?? '') ?></td>
          <td data-label="Prepared By">
            <?php $prep = trim((string)($v['prepared_by'] ?? '')); if ($prep === '' && !empty($v['creator_name'])) { $prep = $v['creator_name']; } echo htmlspecialchars($prep !== '' ? $prep : 'â€”'); ?>
            <br><small><?= htmlspecialchars($v['department'] ?? '') ?></small>
          </td>
          <td data-label="Amount"><?= htmlspecialchars($v['currency']) ?> <?= number_format($v['total_amount'],2) ?></td>
          <td data-label="Date"><?= date('d/m/Y', strtotime($v['date_created'])) ?></td>
          <td data-label="Status">
            <?php
              $isPaidFlag = (int)($v['is_paid'] ?? 0) === 1;
              $isPostedFlag = (int)($v['is_posted'] ?? 0) === 1;
              $derivedStatus = $v['status'];
              $looksDraft = !$isPaidFlag && strtolower($v['status']) === STATUS_PENDING && (empty($v['payee_name']) || (float)$v['total_amount'] <= 0 || (int)($v['item_count'] ?? 0) === 0);
              if ($looksDraft) { $derivedStatus = STATUS_DRAFT; }
            ?>
            <?php if ($isPostedFlag): ?>
              <span class="status-badge" style="color:#facc15;">Posted</span>
            <?php elseif ($isPaidFlag): ?>
              <span class="status-badge status-approved">Paid</span>
            <?php else: ?>
              <span class="status-badge <?= 'status-'.htmlspecialchars($derivedStatus) ?>"><?= ucfirst($derivedStatus) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Docs">
            <?php $ac=(int)($v['attachment_count'] ?? 0); if ($ac>0): ?>
              <a href="../employee/view-voucher.php?id=<?= $v['id'] ?>#attachments" class="icon-link icon-neutral" title="View <?= $ac ?> attachment<?= $ac>1?'s':'' ?>">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M17.657 6.343a4.5 4.5 0 010 6.364l-7.071 7.071a3 3 0 01-4.243-4.243l7.07-7.071a1.5 1.5 0 012.122 2.122l-7.07 7.071" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span style="font-size:10px; margin-left:2px; vertical-align:middle;"><?= $ac ?></span>
              </a>
            <?php else: ?>
              <span style="font-size:11px; color:#666;">0</span>
            <?php endif; ?>
          </td>
          <td data-label="Actions">
            <a href="../employee/view-voucher.php?id=<?= $v['id'] ?>" class="icon-link icon-neutral" title="View" style="margin-right:6px;">
              <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 5c-7.633 0-11 7-11 7s3.367 7 11 7 11-7 11-7-3.367-7-11-7zm0 12a5 5 0 110-10 5 5 0 010 10zm0-2.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/></svg>
            </a>
            <a href="../employee/edit-voucher.php?id=<?= $v['id'] ?>" class="icon-link icon-neutral" title="Edit" style="margin-right:6px;">
              <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <?php
              $isPaidFlag = (int)($v['is_paid'] ?? 0) === 1;
              $looksDraft = !$isPaidFlag && strtolower($v['status']) === STATUS_PENDING && (empty($v['payee_name']) || (float)$v['total_amount'] <= 0 || (int)($v['item_count'] ?? 0) === 0);
            ?>
            <?php if ($v['status'] === 'pending'): ?>
              <?php if (!$looksDraft): ?>
                <button onclick="showApprovalModal(<?= $v['id'] ?>,'approve')" style="color: #28a745; text-decoration: underline; background: none; border: none; padding: 0; margin: 0; cursor: pointer; font-size: 12px; margin-right: 8px;">Approve</button>
              <?php else: ?>
                <span style="font-size:11px; color:#666; margin-right:5px;">Draft (complete first)</span>
              <?php endif; ?>
              <button onclick="showApprovalModal(<?= $v['id'] ?>,'reject')" style="color: #dc3545; text-decoration: underline; background: none; border: none; padding: 0; margin: 0; cursor: pointer; font-size: 12px;">Reject</button>
            <?php endif; ?>
            <?php
              // Finance users can mark paid and posted
              $isFinanceUser = isFinance();
              $statusLower = strtolower((string)($v['status'] ?? ''));
              $approverRole = isset($v['approver_role']) ? (string)$v['approver_role'] : '';
              $approvedByAdmin = !empty($v['approved_by']) && $approverRole === ROLE_ADMIN;
              $canMarkPaid = $isFinanceUser && !$isPaidFlag && $statusLower === 'approved' && $approvedByAdmin;
              $canPost = $isFinanceUser && $isPaidFlag && !$isPostedFlag && $statusLower === 'approved';
            ?>
            <?php if ($canMarkPaid): ?>
              <form method="POST" action="dashboard.php" style="display:inline-block; margin-left:8px;">
                <input type="hidden" name="voucher_id" value="<?= $v['id'] ?>" />
                <input type="hidden" name="mark_paid" value="1" />
                <button type="submit" style="color: #065f46; text-decoration: underline; background: none; border: none; padding: 0; margin: 0; cursor: pointer; font-size: 12px;">Mark Paid</button>
              </form>
            <?php endif; ?>
            <?php if ($canPost): ?>
              <form method="POST" action="dashboard.php" style="display:inline-block; margin-left:8px;" onsubmit="return confirm('Mark this voucher as POSTED?');">
                <input type="hidden" name="voucher_id" value="<?= $v['id'] ?>" />
                <input type="hidden" name="mark_posted" value="1" />
                <button type="submit" style="color: #0d7c0d; text-decoration: underline; background: none; border: none; padding: 0; margin: 0; cursor: pointer; font-size: 12px;">Mark Posted</button>
              </form>
            <?php endif; ?>
            <form method="POST" action="dashboard.php" style="display:inline-block; margin-left:6px;" onsubmit="return confirm('Delete this voucher permanently? This cannot be undone.');">
              <input type="hidden" name="voucher_id" value="<?= $v['id'] ?>" />
              <input type="hidden" name="action" value="delete" />
              <button type="submit" class="icon-btn icon-danger" title="Delete">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</main>

<div id="approvalModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.5);">
  <div style="background:#fff; margin:15% auto; padding:30px; border-radius:0; width:80%; max-width:500px;">
    <h3 id="modalTitle">Approve Voucher</h3>
    <form method="POST" id="approvalForm">
      <input type="hidden" id="modalVoucherId" name="voucher_id" />
      <input type="hidden" id="modalAction" name="action" />
      <div class="form-group">
        <label for="comments">Comments (Optional)</label>
        <textarea id="comments" name="comments" rows="4" style="border-radius: 0;" placeholder="Add any comments about this approval/rejection..."></textarea>
      </div>
      <div style="text-align:right; margin-top:20px;">
        <button type="button" onclick="closeApprovalModal()" class="btn btn-secondary" style="border-radius: 0;">Cancel</button>
        <button type="submit" id="modalSubmitBtn" style="color: #28a745; text-decoration: underline; background: none; border: none; padding: 0; margin: 0 0 0 12px; cursor: pointer; font-size: 12px;">Approve</button>
      </div>
    </form>
  </div>
</div>

<script src="../assets/js/voucher-v5.js?v=9"></script>
<script>
function applySort(val){ const url=new URL(window.location.href); url.searchParams.set('sort',val); window.location.href=url.toString(); }
function filterTable(){
  var input = document.getElementById('searchInput');
  var q = (input && input.value ? input.value.toLowerCase() : '').trim();
  var rows = document.querySelectorAll('table.data-table tbody tr');
  rows.forEach(function(r){
    var text = r.textContent.toLowerCase();
    r.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
  });
}
function filterByStatus(val){
  var rows = document.querySelectorAll('table.data-table tbody tr');
  var v = (val || 'all').toLowerCase();
  rows.forEach(function(r){
    if (v === 'all') { r.style.display = ''; return; }
    var badge = r.querySelector('.status-badge');
    var t = badge ? badge.textContent.toLowerCase() : '';
    r.style.display = (t.indexOf(v) !== -1) ? '' : 'none';
  });
}
function showApprovalModal(id, action){
  document.getElementById('modalVoucherId').value=id;
  document.getElementById('modalAction').value = action==='approve' ? 'approved' : 'rejected';
  document.getElementById('modalTitle').textContent = action==='approve' ? 'Approve Voucher' : 'Reject Voucher';
  const btn=document.getElementById('modalSubmitBtn');
  btn.textContent = action==='approve' ? 'Approve' : 'Reject';
  if (action==='approve') {
    btn.style.color = '#28a745';
  } else {
    btn.style.color = '#dc3545';
  }
  document.getElementById('approvalModal').style.display='block';
}
function closeApprovalModal(){ document.getElementById('approvalModal').style.display='none'; document.getElementById('comments').value=''; }
window.onclick=function(e){ const m=document.getElementById('approvalModal'); if(e.target===m){ closeApprovalModal(); } };
</script>
</body>
</html>
