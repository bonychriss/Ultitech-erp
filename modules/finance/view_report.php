<?php
// modules/finance/view_report.php
require_once '../../includes/functions.php';
require_once '../../modules/balances/functions.php';

// Ensure expenses_history has comments column and expenses_requests has voucher_number
try {
    $pdo->exec("ALTER TABLE expenses_history ADD COLUMN comments TEXT DEFAULT NULL");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE expenses_requests ADD COLUMN voucher_number VARCHAR(50) DEFAULT NULL");
} catch (PDOException $e) {}

requireLogin();

$user_id = $_SESSION['user_id'];
$report_id = (int) ($_GET['id'] ?? 0);

// Fetch Report
$stmt = $pdo->prepare("SELECT * FROM expenses_reports WHERE id = ?");
$stmt->execute([$report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Report not found");
}

// Fetch Items
$stmt = $pdo->prepare("
    SELECT r.*, 
           CONCAT(COALESCE(p.name, ''), IF(p.name IS NOT NULL, ' > ', ''), c.name) as category_name
    FROM expenses_requests r 
    LEFT JOIN expenses_categories c ON r.category_id = c.id
    LEFT JOIN expenses_categories p ON c.parent_id = p.id
    WHERE r.report_id = ?
");
$stmt->execute([$report_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$can_edit = ($report['status'] === 'draft' && $report['user_id'] == $user_id);
if (isFinance() && $report['status'] !== 'ratified') {
    $can_edit = true;
}
$is_manager = (isAdmin() || isFinance()); // Simplified manager check

// Handle Actions (Submit, Approve, Refuse)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit' && $can_edit) {
        $pdo->prepare("UPDATE expenses_reports SET status = 'submitted', submitted_at = NOW() WHERE id = ?")->execute([$report_id]);
        $pdo->prepare("UPDATE expenses_requests SET status = 'submitted' WHERE report_id = ?")->execute([$report_id]);
        
        // Log
        $pdo->prepare("INSERT INTO expenses_history (report_id, user_id, action) VALUES (?, ?, 'submitted')")->execute([$report_id, $user_id]);
        
        header("Location: view_report.php?id=$report_id&msg=submitted");
        exit;
    }
    
    if ($action === 'approve' && $is_manager) {
        $pdo->prepare("UPDATE expenses_reports SET status = 'approved', approved_at = NOW(), employee_approver_id = ? WHERE id = ?")->execute([$user_id, $report_id]);
        $pdo->prepare("UPDATE expenses_requests SET status = 'approved' WHERE report_id = ?")->execute([$report_id]);
         // Log
        $pdo->prepare("INSERT INTO expenses_history (report_id, user_id, action) VALUES (?, ?, 'approved')")->execute([$report_id, $user_id]);
        
        header("Location: view_report.php?id=$report_id&msg=approved");
        exit;
    }
    
    // Handle Payment & Posting (Action = Pay)
    // Handle Payment & Posting (Action = Pay)
    if ($action === 'pay' && isFinance()) {
        $account_id = (int) $_POST['account_id'];
        $date = $_POST['payment_date'];
        
        // 1. Mark Report as Paid
        $pdo->prepare("UPDATE expenses_reports SET status = 'paid', paid_at = ?, posted_at = NOW() WHERE id = ?")->execute([$date, $report_id]);
        $pdo->prepare("UPDATE expenses_requests SET status = 'paid' WHERE report_id = ?")->execute([$report_id]);
        
        // 2. Record Transaction in Balances Module (Debit - Money Out)
        // Fetch account name for log
        $accName = $pdo->query("SELECT name FROM financial_accounts WHERE id = $account_id")->fetchColumn();
        
        recordTransaction(
            $account_id,
            'debit', // Money leaving the account
            $report['total_amount'],
            "Payment for Expense Report #$report_id (" . $report['report_title'] . ")",
            'expense_report',
            $report_id,
            $date
        );

        // Log
        $pdo->prepare("INSERT INTO expenses_history (report_id, user_id, action, comments) VALUES (?, ?, 'paid', ?)")->execute([$report_id, $user_id, "Paid via $accName"]);
        
        header("Location: view_report.php?id=$report_id&msg=paid");
        exit;
    }

    // Handle Ratify (Admin Only)
    if ($action === 'ratify' && isAdmin()) {
        $pdo->prepare("UPDATE expenses_reports SET status = 'ratified' WHERE id = ?")->execute([$report_id]);
        $pdo->prepare("UPDATE expenses_requests SET status = 'ratified' WHERE report_id = ?")->execute([$report_id]);
        
        // Log
        $pdo->prepare("INSERT INTO expenses_history (report_id, user_id, action) VALUES (?, ?, 'ratified')")->execute([$report_id, $user_id]);
        
        header("Location: view_report.php?id=$report_id&msg=ratified");
        exit;
    }

    // Handle Line Updates (Admin/Finance only)
    if ($action === 'update_line' && $can_edit) {
        $line_id = (int) $_POST['line_id'];
        $desc = $_POST['description'];
        $amt = (float) $_POST['amount'];
        $voucher = $_POST['voucher_number'] ?? '';
        
        $pdo->prepare("UPDATE expenses_requests SET description = ?, amount = ?, voucher_number = ? WHERE id = ?")->execute([$desc, $amt, $voucher, $line_id]);
        
        // Recalculate Total
        $sum = $pdo->prepare("SELECT SUM(amount) FROM expenses_requests WHERE report_id = ?")->execute([$report_id]);
        $newTotal = $pdo->query("SELECT SUM(amount) FROM expenses_requests WHERE report_id = $report_id")->fetchColumn();
        $pdo->prepare("UPDATE expenses_reports SET total_amount = ? WHERE id = ?")->execute([$newTotal, $report_id]);
        
        header("Location: view_report.php?id=$report_id&msg=updated");
        exit;
    }
}
?>

<?php
// Status Bar Helper
function isStatusActive($current, $step) {
    // Flow: draft -> paid -> ratified
    // We keep old statuses just in case to avoid errors, but main flow is draft->paid->ratified
    $order = ['draft'=>0, 'submitted'=>0, 'approved'=>0, 'paid'=>1, 'ratified'=>2];
    $c = $order[$current] ?? 0;
    $s = $order[$step] ?? 0;
    
    return $c >= $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($report['report_title']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Odoo-style CSS Variables & Layout from view-quote.php */
        :root {
            --odoo-brand: #714B67;
            --odoo-brand-dark: #5b3c53;
            --odoo-action: #008784;
            --odoo-gray: #f9f9f9;
            --odoo-border: #dee2e6;
        }

        /* Modern Pipeline Widget (Chevron style) */
        .pipeline-widget {
            display: flex;
            align-items: center;
            background: #fff;
            border: 1px solid var(--odoo-border);
            border-radius: 4px;
            overflow: hidden;
            margin-left: auto; /* Push to the right */
        }
        .pipeline-item {
            position: relative;
            padding: 4px 20px 4px 30px; /* Better padding for readability */
            font-size: 11px; /* Slightly larger */
            font-weight: 600;
            color: #666;
            background: #fdfdfd;
            text-transform: none; /* Sentence case is easier to read and fits better */
            letter-spacing: 0.2px;
            display: flex;
            align-items: center;
            white-space: nowrap;
        }
        .pipeline-item:first-child { padding-left: 15px; }
        .pipeline-item::after {
            content: "";
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%) rotate(45deg);
            width: 20px;
            height: 20px;
            background: #fdfdfd;
            border-right: 1px solid var(--odoo-border);
            border-top: 1px solid var(--odoo-border);
            z-index: 2;
        }
        .pipeline-item.active {
            background: var(--odoo-action);
            color: white;
        }
        .pipeline-item.active::after {
            background: var(--odoo-action);
        }
        .pipeline-item.done {
            background: #eef2ff;
            color: #4f46e5;
        }
        .pipeline-item.done::after {
            background: #eef2ff;
        }
        .pipeline-item:last-child::after { display: none; }
        
        .cursor-pointer { cursor: pointer; }
        
        /* Timeline Styles */
        .timeline { border-left: 2px solid #e9ecef; margin-left: 10px; padding-left: 20px; position: relative; }
        .timeline-item { position: relative; margin-bottom: 25px; }
        .timeline-marker { 
            position: absolute; left: -26px; top: 0; 
            width: 12px; height: 12px; border-radius: 50%; background: #adb5bd; border: 2px solid #fff; box-shadow: 0 0 0 1px #dee2e6;
        }
        .timeline-content { position: relative; top: -5px; }

        /* Loader Styles */
        .loader {
            position: fixed;
            top: 0; bottom: 0; left: 0; right: 0;
            z-index: 9999;
            background: rgba(255, 255, 255, 0.8);
            display: none; /* Hidden by default */
            align-items: center;
            justify-content: center;
        }

        .jimu-primary-loading:before,
        .jimu-primary-loading:after {
            position: absolute;
            top: 0;
            content: '';
        }

        .jimu-primary-loading:before {
            left: -19.992px;
        }

        .jimu-primary-loading:after {
            left: 19.992px;
            -webkit-animation-delay: 0.32s !important;
            animation-delay: 0.32s !important;
        }

        .jimu-primary-loading:before,
        .jimu-primary-loading:after,
        .jimu-primary-loading {
            background: #076fe5;
            -webkit-animation: loading-keys-app-loading 0.8s infinite ease-in-out;
            animation: loading-keys-app-loading 0.8s infinite ease-in-out;
            width: 13.6px;
            height: 32px;
        }

        .jimu-primary-loading {
            text-indent: -9999em;
            margin: auto;
            position: absolute;
            right: calc(50% - 6.8px);
            top: calc(50% - 16px);
            -webkit-animation-delay: 0.16s !important;
            animation-delay: 0.16s !important;
        }

        @-webkit-keyframes loading-keys-app-loading {
            0%, 80%, 100% {
                opacity: .75;
                box-shadow: 0 0 #076fe5;
                height: 32px;
            }
            40% {
                opacity: 1;
                box-shadow: 0 -8px #076fe5;
                height: 40px;
            }
        }

        @keyframes loading-keys-app-loading {
            0%, 80%, 100% {
                opacity: .75;
                box-shadow: 0 0 #076fe5;
                height: 32px;
            }
            40% {
                opacity: 1;
                box-shadow: 0 -8px #076fe5;
                height: 40px;
            }
        }
    </style>
</head>
<body class="bg-white">

    <!-- Loader -->
    <div class="loader" id="pageLoader">
      <div class="jimu-primary-loading"></div>
    </div>

<?php include '../../includes/header_employee.php'; ?>

<div class="main-content p-0">
    <div class="container-fluid p-0">

        <!-- 1. Control Panel Header -->
        <div class="border-bottom bg-white py-3 px-4 d-flex justify-content-between align-items-center sticky-top shadow-sm" style="z-index: 10;">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small text-muted">
                        <li class="breadcrumb-item"><a href="my_reports.php" class="text-decoration-none">Reports</a></li>
                        <li class="breadcrumb-item active"><?= htmlspecialchars($report['report_title']) ?></li>
                    </ol>
                </nav>
                <div class="d-flex align-items-center gap-3">
                    <h3 class="fw-bold mb-0 text-dark">
                        <?= htmlspecialchars($report['report_title']) ?>
                    </h3>
                    <span class="badge bg-light text-dark border rounded-pill fw-normal px-3">
                        <?= number_format($report['total_amount']) ?> TZS
                    </span>
                </div>
            </div>
            
            <div class="d-flex gap-3 align-items-center">
                 <!-- Actions -->
                <div class="d-flex gap-2">
                    <?php if (($report['status'] === 'draft' || $report['status'] === 'approved') && isFinance()): ?>
                        <button type="button" class="btn btn-primary px-4 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#payModal">
                            <i class="fas fa-money-bill-wave me-1"></i> Pay & Post
                        </button>
                    <?php endif; ?>

                    <?php if ($report['status'] === 'paid' && isAdmin()): ?>
                    <form method="POST" id="ratifyForm">
                        <input type="hidden" name="action" value="ratify">
                        <button type="button" onclick="confirmRatify()" class="btn btn-dark px-4 rounded-pill shadow-sm">
                            <i class="fas fa-lock me-1"></i> Ratify
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Pipeline -->
                <div class="pipeline-widget">
                    <?php
                    $stages = [
                        'draft'    => ['label' => 'Draft', 'keys' => ['draft', 'submitted', 'approved']], 
                        'paid'     => ['label' => 'Paid', 'keys' => ['paid']], 
                        'ratified' => ['label' => 'Ratified', 'keys' => ['ratified']]
                    ];
                    $found_active = false;
                    $current_status = $report['status'];

                    foreach ($stages as $s_id => $s_data):
                        $is_active = in_array($current_status, $s_data['keys']);
                        $is_done = false;
                        if (!$is_active && !$found_active) $is_done = true;
                        if ($is_active) $found_active = true;
                        $class = $is_active ? 'active' : ($is_done ? 'done' : '');
                        ?>
                        <div class="pipeline-item <?php echo $class; ?>">
                            <?php echo $s_data['label']; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>


        <!-- Content Row -->
        <div class="row g-4 mt-2 px-4 pb-5">
            <!-- Left: Report Details -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-bold">Expense Lines</h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary small text-uppercase fw-bold">
                                <tr>
                                    <th class="ps-4 border-bottom-0 py-3">Date</th>
                                    <th class="border-bottom-0 py-3">Description</th>
                                    <th class="border-bottom-0 py-3">Voucher #</th>
                                    <th class="border-bottom-0 py-3">Category</th>
                                    <th class="text-end border-bottom-0 py-3">Amount</th>
                                    <th class="text-end pe-4 border-bottom-0 py-3" style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="ps-4 py-3 text-muted"><?= $item['date'] ?></td>
                                    <td class="fw-medium text-dark">
                                        <?= htmlspecialchars($item['description']) ?>
                                    </td>
                                    <td class="text-secondary small">
                                        <?= htmlspecialchars($item['voucher_number'] ?? '-') ?>
                                    </td>
                                    <td><span class="badge bg-light text-secondary border fw-normal"><?= $item['category_name'] ?></span></td>
                                    <td class="text-end fw-bold text-dark">
                                        <?= number_format($item['amount']) ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if ($item['receipt_path']): ?>
                                            <a href="../../<?= $item['receipt_path'] ?>" target="_blank" class="btn btn-sm btn-icon btn-light text-muted" title="Download Receipt">
                                                <i class="fas fa-paperclip"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_edit): ?>
                                            <button class="btn btn-sm btn-icon btn-light text-muted" onclick="editLine(<?= $item['id'] ?>, '<?= addslashes($item['description']) ?>', <?= $item['amount'] ?>, '<?= addslashes($item['voucher_number'] ?? '') ?>')">
                                                <i class="fas fa-pencil"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="border-top bg-white">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold py-3 text-secondary text-uppercase small">Total Amount:</td>
                                    <td class="text-end fw-bold text-dark fs-5 py-3">
                                        <?= number_format($report['total_amount']) ?> <span class="fs-6 text-muted">TZS</span>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                
                <!-- Chatter / History -->
                <div class="mt-5">
                    <h6 class="fw-bold mb-4 px-3">History & Activity</h6>
                    
                    <div class="timeline">
                        <!-- Helper to fetch history would go here -->
                        <?php
                            $hist = $pdo->prepare("SELECT h.*, u.full_name FROM expenses_history h JOIN users u ON h.user_id = u.id WHERE report_id = ? ORDER BY created_at DESC");
                            $hist->execute([$report_id]);
                            $logs = $hist->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <?php if (empty($logs)): ?>
                            <p class="text-muted small ps-3">No history yet.</p>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <strong class="text-dark hover-primary"><?= htmlspecialchars($l['full_name']) ?></strong>
                                            <small class="text-muted"><?= date('M d, H:i', strtotime($l['created_at'])) ?></small>
                                        </div>
                                        <p class="mb-0 text-secondary small">
                                            <?= htmlspecialchars($l['action']) ?> this report.
                                            <?php if (!empty($l['comments'])): ?>
                                                <br>
                                                <span class="fst-italic text-muted">"<?= htmlspecialchars($l['comments']) ?>"</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Info -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h6 class="text-muted mb-3">Report Details</h6>
                        <div class="mb-3">
                            <label class="small text-muted d-block">Employee</label>
                            <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong>
                        </div>
                        <div class="mb-3">
                            <label class="small text-muted d-block">Next Step</label>
                            <?php if (in_array($report['status'], ['draft', 'submitted', 'approved'])): ?>
                                <span class="text-warning">Finance: Pay & Post</span>
                            <?php elseif ($report['status'] == 'paid'): ?>
                                <span class="text-info">Admin: Ratify & Lock</span>
                            <?php elseif ($report['status'] == 'ratified'): ?>
                                <span class="text-success">Process Complete</span>
                            <?php else: ?>
                                <span class="text-success">Done</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editLineModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_line">
                <input type="hidden" name="line_id" id="edit_line_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Expense Line</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="edit_desc" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Voucher Number</label>
                        <input type="text" name="voucher_number" id="edit_voucher" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" name="amount" id="edit_amt" class="form-control" step="0.01" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="payForm">
                <input type="hidden" name="action" value="pay">
                <div class="modal-header">
                    <h5 class="modal-title">Pay Expense Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Total Amount:</strong> <?= number_format($report['total_amount']) ?> TZS
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Account</label>
                        <select name="account_id" class="form-select" required>
                            <option value="">Select Account...</option>
                            <?php 
                            $accounts = $pdo->query("SELECT * FROM financial_accounts WHERE status = 'active' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($accounts as $acc): 
                            ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= htmlspecialchars($acc['name']) ?> 
                                    (<?= ucfirst($acc['type']) ?> - <?= $acc['currency'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" onclick="confirmPay()" class="btn btn-primary">Pay & Post</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function editLine(id, desc, amt, voucher) {
        document.getElementById('edit_line_id').value = id;
        document.getElementById('edit_desc').value = desc;
        document.getElementById('edit_amt').value = amt;
        document.getElementById('edit_voucher').value = voucher || '';
        new bootstrap.Modal(document.getElementById('editLineModal')).show();
    }

    function confirmPay() {
        // Validate form first
        const form = document.getElementById('payForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        Swal.fire({
            title: 'Are you sure?',
            text: "This process cannot be reversed. The amount will be deducted from the selected account immediately.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, Pay & Post!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show Loader
                document.getElementById('pageLoader').style.display = 'flex';
                form.submit();
            }
        });
    }

    function confirmRatify() {
        Swal.fire({
            title: 'Ratify Report?',
            text: "This will lock the report permanently.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#212529',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, Ratify & Lock'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show Loader
                document.getElementById('pageLoader').style.display = 'flex';
                document.getElementById('ratifyForm').submit();
            }
        });
    }
</script>

</body>
</html>
