<?php
require_once '../includes/functions.php';
requireAdmin();
// Ensure posted/swift columns available for queries
ensureSwiftDocumentColumn();
ensurePostedColumnsOnPaymentVouchers();

$success = $error = null;

// Handle mark_paid and mark_posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['voucher_id'])) {
    $voucher_id = (int)$_POST['voucher_id'];
    
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
    }
    
    // Redirect to avoid resubmission
    if ($success || $error) {
        $redirect_url = 'all-vouchers.php?' . http_build_query($_GET);
        header('Location: ' . $redirect_url);
        exit;
    }
}

// Get all vouchers with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get filters
$status_filter = isset($_GET['status']) && $_GET['status'] !== 'all' ? $_GET['status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

if ($status_filter) {
    $where_conditions[] = "pv.status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $where_conditions[] = "(pv.voucher_no LIKE ? OR pv.payee_name LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Sorting by voucher number (pattern like PV/UGC/YYYY/NNN)
$sort = isset($_GET['sort']) ? strtolower($_GET['sort']) : 'newest';
if (!in_array($sort, ['newest','asc','desc'])) { $sort = 'newest'; }
$yearDir = ($sort === 'asc') ? 'ASC' : 'DESC';
$seqDir  = ($sort === 'asc') ? 'ASC' : 'DESC';
$order_by = "ORDER BY 
    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(pv.voucher_no, '/', -2), '/', 1) AS UNSIGNED) $yearDir,
    CAST(SUBSTRING_INDEX(pv.voucher_no, '/', -1) AS UNSIGNED) $seqDir";

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM payment_vouchers pv LEFT JOIN users u ON pv.created_by = u.id $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

// Get vouchers
$sql = "
    SELECT pv.id, pv.voucher_no, pv.payee_name, pv.total_amount, pv.status,
           pv.date_created, pv.currency, pv.created_at, pv.prepared_by,
           IFNULL(pv.is_paid,0) AS is_paid,
           IFNULL(pv.is_posted,0) AS is_posted,
           pv.approved_by,
        (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
           pv.supporting_documents,
           (SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count,
           u.full_name as creator_name, u.department, ua.full_name as approver_name,
           (SELECT role FROM users WHERE id = pv.approved_by LIMIT 1) AS approver_role
        FROM payment_vouchers pv
    LEFT JOIN users u ON pv.created_by = u.id
    LEFT JOIN users ua ON pv.approved_by = ua.id
    $where_clause
    $order_by 
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Vouchers - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Compact styling for all-vouchers page */
        body.dashboard .main-content { padding: 12px; }
        body.dashboard .actions { margin-bottom: 12px; }
        body.dashboard .actions .btn { padding: 5px 10px; font-size: 11px; }
        body.dashboard .form-container { padding: 12px; }
        body.dashboard .form-container h2 { font-size: 14px; margin-bottom: 8px; }
        body.dashboard .form-container form[method="GET"] { margin-bottom: 10px; padding: 10px; background: #f8f8f8; }
        body.dashboard .form-container form[method="GET"] label { font-size: 11px; }
        body.dashboard .form-container form[method="GET"] input,
        body.dashboard .form-container form[method="GET"] select { padding: 5px 8px; font-size: 11px; }
        body.dashboard .form-container form[method="GET"] .btn { padding: 5px 10px; font-size: 11px; }
        body.dashboard .data-table th { padding: 8px; font-size: 11px; }
        body.dashboard .data-table td { padding: 6px 8px; font-size: 11px; }
        body.dashboard .data-table .btn { padding: 3px 6px; font-size: 10px; }
        body.dashboard .data-table .icon { width: 14px; height: 14px; }
        body.dashboard .status-badge { padding: 2px 6px; font-size: 10px; }
        body.dashboard .form-container .btn.btn-secondary { padding: 5px 10px; font-size: 11px; }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

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
        <div class="actions">
            <a href="dashboard.php" class="icon-link icon-neutral" title="Back" aria-label="Back">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <a href="reports.php" class="btn btn-secondary">Generate Reports</a>
        </div>

        <div class="form-container">
            <h2>All Payment Vouchers (<?= $total_records ?> total)</h2>
            
            <!-- Filters -->
            <form method="GET" style="margin-bottom: 20px; padding: 20px; background: #f8f8f8; border-radius: 0;">
                <div style="display: grid; grid-template-columns: 1fr 200px 220px 100px; gap: 15px; align-items: end;">
                    <div>
                        <label for="search">Search:</label>
                        <input type="text" id="search" name="search" style="border-radius: 0;"
                               value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Voucher No, Payee, or Creator">
                    </div>
                    <div>
                        <label for="status">Status:</label>
                        <select name="status" id="status" style="border-radius: 0;">
                            <option value="all" <?= $status_filter === '' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div>
                        <label for="sort">Sort:</label>
                        <select name="sort" id="sort" style="border-radius: 0;">
                            <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest voucher no.</option>
                            <option value="asc" <?= $sort==='asc'?'selected':'' ?>>Voucher no. ascending</option>
                            <option value="desc" <?= $sort==='desc'?'selected':'' ?>>Voucher no. descending</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn" style="border-radius: 0;">Filter</button>
                    </div>
                </div>
            </form>

            <?php if (empty($vouchers)): ?>
                <p>No vouchers found with the current filters.</p>
            <?php else: ?>
                <div class="table-wrap stacked-table">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Voucher No.</th>
                            <th>Payee</th>
                            <th>Prepared By</th>
                            <th>Amount</th>
                            <th>Date Created</th>
                            <th>Status</th>
                            <th>Approved By</th>
                            <th>Docs</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = $offset + 1; foreach ($vouchers as $voucher): ?>
                        <tr>
                            <td><?= $sn++ ?></td>
                            <td><?= htmlspecialchars($voucher['voucher_no']) ?></td>
                            <td><?= htmlspecialchars($voucher['payee_name']) ?></td>
                            <td>
                                <?php
                                $prep = trim((string)($voucher['prepared_by'] ?? ''));
                                if ($prep === '' && !empty($voucher['creator_name'])) {
                                    $prep = $voucher['creator_name'];
                                }
                                echo htmlspecialchars($prep !== '' ? $prep : 'â€”');
                                ?>
                                <br><small><?= htmlspecialchars($voucher['department'] ?? '') ?></small>
                            </td>
                            <td><?= htmlspecialchars($voucher['currency']) ?> <?= number_format($voucher['total_amount'], 2) ?></td>
                            <td><?= date('d/m/Y', strtotime($voucher['date_created'])) ?><br>
                                <small><?= date('H:i', strtotime($voucher['created_at'])) ?></small></td>
                            <td>
                                <?php
                                    $isPaidFlag = (int)($voucher['is_paid'] ?? 0) === 1;
                                    $isPostedFlag = (int)($voucher['is_posted'] ?? 0) === 1;
                                    $derivedStatus = $voucher['status'];
                                    // Draft rule: unpaid, pending, and incomplete essentials
                                    $looksDraft = !$isPaidFlag
                                        && strtolower($voucher['status']) === STATUS_PENDING
                                        && (
                                            empty($voucher['payee_name'])
                                            || (float)$voucher['total_amount'] <= 0
                                            || (int)($voucher['item_count'] ?? 0) === 0
                                        );
                                    if ($looksDraft) { $derivedStatus = STATUS_DRAFT; }
                                ?>
                                <?php if ($isPostedFlag): ?>
                                    <span class="status-badge" style="color:#facc15;">Posted</span>
                                <?php elseif ($isPaidFlag): ?>
                                    <span class="status-badge status-approved">Paid</span>
                                <?php else: ?>
                                    <span class="status-badge <?= 'status-' . htmlspecialchars($derivedStatus) ?>"><?php echo ucfirst($derivedStatus); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $voucher['approver_name'] ? htmlspecialchars($voucher['approver_name']) : '-' ?></td>
                            <td>
                                <?php $ac = (int)($voucher['attachment_count'] ?? 0); if ($ac > 0): ?>
                                    <a href="../employee/view-voucher.php?id=<?= $voucher['id'] ?>#attachments" class="icon-link icon-neutral" title="View <?= $ac ?> attachment<?= $ac>1?'s':'' ?>" aria-label="View attachments" style="margin:2px;">
                                        <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M17.657 6.343a4.5 4.5 0 010 6.364l-7.071 7.071a3 3 0 01-4.243-4.243l7.07-7.071a1.5 1.5 0 012.122 2.122l-7.07 7.071" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <span style="font-size:10px; margin-left:2px;"><?= $ac ?></span>
                                    </a>
                                <?php else: ?>
                                    <span style="font-size:11px; color:#666;">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="../employee/view-voucher.php?id=<?= $voucher['id'] ?>" class="icon-link icon-neutral" title="View" aria-label="View voucher" style="margin: 2px;">
                                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
                                        <path d="M12 5c-7.633 0-11 7-11 7s3.367 7 11 7 11-7 11-7-3.367-7-11-7zm0 12a5 5 0 110-10 5 5 0 010 10zm0-2.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/>
                                    </svg>
                                </a>
                <a href="../employee/edit-voucher.php?id=<?= $voucher['id'] ?>" class="icon-link icon-black" title="Edit" aria-label="Edit voucher" style="margin: 2px;">
                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
                        <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zm14.71-9.21a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
                    </svg>
                </a>
                                <?php if ($voucher['status'] === 'pending'): ?>
                                    <?php if (!$looksDraft): ?>
                                        <button onclick="quickApprove(<?= $voucher['id'] ?>, 'approved')" 
                                                style="color: #28a745; text-decoration: underline; background: none; border: none; padding: 0; margin: 2px 8px 2px 2px; cursor: pointer; font-size: 11px;">Approve</button>
                                    <?php else: ?>
                                        <span style="font-size:11px; color:#666; margin:2px;">Draft (complete first)</span>
                                    <?php endif; ?>
                                    <button onclick="quickApprove(<?= $voucher['id'] ?>, 'rejected')" 
                                            style="color: #dc3545; text-decoration: underline; background: none; border: none; padding: 0; margin: 2px; cursor: pointer; font-size: 11px;">Reject</button>
                                <?php endif; ?>
                                <?php
                                  // Finance users can mark paid and posted
                                  $isFinanceUser = isFinance();
                                  $statusLower = strtolower((string)($voucher['status'] ?? ''));
                                  $approverRole = isset($voucher['approver_role']) ? (string)$voucher['approver_role'] : '';
                                  $approvedByAdmin = !empty($voucher['approved_by']) && $approverRole === ROLE_ADMIN;
                                  $canMarkPaid = $isFinanceUser && !$isPaidFlag && $statusLower === 'approved' && $approvedByAdmin;
                                  $canPost = $isFinanceUser && $isPaidFlag && !$isPostedFlag && $statusLower === 'approved';
                                ?>
                                <?php if ($canMarkPaid): ?>
                                  <form method="POST" action="all-vouchers.php" style="display:inline-block; margin-left:8px;">
                                    <input type="hidden" name="voucher_id" value="<?= $voucher['id'] ?>" />
                                    <input type="hidden" name="mark_paid" value="1" />
                                    <?php foreach ($_GET as $key => $value): ?>
                                      <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>" />
                                    <?php endforeach; ?>
                                    <button type="submit" style="color: #065f46; text-decoration: underline; background: none; border: none; padding: 0; margin: 0; cursor: pointer; font-size: 12px;">Mark Paid</button>
                                  </form>
                                <?php endif; ?>
                                <?php if ($canPost): ?>
                                  <form method="POST" action="all-vouchers.php" style="display:inline-block; margin-left:8px;" onsubmit="return confirm('Mark this voucher as POSTED?');">
                                    <input type="hidden" name="voucher_id" value="<?= $voucher['id'] ?>" />
                                    <input type="hidden" name="mark_posted" value="1" />
                                    <?php foreach ($_GET as $key => $value): ?>
                                      <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>" />
                                    <?php endforeach; ?>
                                    <button type="submit" style="color: #0d7c0d; text-decoration: underline; background: none; border: none; padding: 0; margin: 0; cursor: pointer; font-size: 12px;">Mark Posted</button>
                                  </form>
                                <?php endif; ?>
                <button onclick="quickDelete(<?= $voucher['id'] ?>)" class="icon-btn icon-danger" title="Delete" aria-label="Delete voucher" style="margin: 2px;">
                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
                        <polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <?php
                    $base_url = '?';
                    if ($status_filter) $base_url .= 'status=' . urlencode($status_filter) . '&';
                    if ($search) $base_url .= 'search=' . urlencode($search) . '&';
                    if ($sort) $base_url .= 'sort=' . urlencode($sort) . '&';
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <a href="<?= $base_url ?>page=<?= $page - 1 ?>" class="btn btn-secondary">Previous</a>
                    <?php endif; ?>
                    
                    <span style="margin: 0 15px;">Page <?= $page ?> of <?= $total_pages ?></span>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="<?= $base_url ?>page=<?= $page + 1 ?>" class="btn btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="../assets/js/voucher-v5.js?v=9"></script>
    <script>
        function quickApprove(voucherId, action) {
            if (confirm('Are you sure you want to ' + action + ' this voucher?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'dashboard.php';
                
                const voucherInput = document.createElement('input');
                voucherInput.type = 'hidden';
                voucherInput.name = 'voucher_id';
                voucherInput.value = voucherId;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = action;
                
                form.appendChild(voucherInput);
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function quickDelete(voucherId) {
            if (confirm('Delete this voucher permanently? This cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'dashboard.php';

                const voucherInput = document.createElement('input');
                voucherInput.type = 'hidden';
                voucherInput.name = 'voucher_id';
                voucherInput.value = voucherId;

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';

                form.appendChild(voucherInput);
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
