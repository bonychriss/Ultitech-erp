<?php
require_once '../includes/functions.php';
requireLogin();
ensureDeliveriesSchema();

$myId = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle Accept/Reject Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['order_id'])) {
        $oid = (int)$_POST['order_id'];
        $act = $_POST['action'];
        $reason = trim($_POST['reason'] ?? '');
        
        // Verify ownership
        $stmtCheck = $pdo->prepare("SELECT id FROM delivery_orders WHERE id = ? AND requested_driver_id = ?");
        $stmtCheck->execute([$oid, $myId]);
        if ($stmtCheck->fetch()) {
            if ($act === 'accept') {
                $up = $pdo->prepare("UPDATE delivery_orders SET status = 'accepted' WHERE id = ?");
                $up->execute([$oid]);
                $success = "Delivery #$oid Accepted!";
            } elseif ($act === 'reject') {
                if (empty($reason)) {
                    $error = "Reason required for rejection.";
                } else {
                    $up = $pdo->prepare("UPDATE delivery_orders SET status = 'rejected', rejection_reason = ? WHERE id = ?");
                    $up->execute([$reason, $oid]);
                    $success = "Delivery #$oid Rejected.";
                }
            }
        } else {
            $error = "Unauthorized action.";
        }
    }
}

// Check if specific ID requested (e.g. from WhatsApp link)
$highlightId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch My Requests (Pending)
$stmtP = $pdo->prepare("
    SELECT * FROM delivery_orders 
    WHERE requested_driver_id = ? AND status = 'request_pending' 
    ORDER BY created_at DESC
");
$stmtP->execute([$myId]);
$pending = $stmtP->fetchAll();

// Fetch My Accepted (Active)
$stmtA = $pdo->prepare("
    SELECT * FROM delivery_orders 
    WHERE requested_driver_id = ? AND status IN ('accepted', 'pending', 'loading', 'in_transit')
    ORDER BY delivery_deadline ASC
");
$stmtA->execute([$myId]);
$accepted = $stmtA->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Driver Tasks</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .task-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .task-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; border-bottom: 1px solid #f3f4f6; padding-bottom: 10px; }
        .task-label { font-size: 11px; text-transform: uppercase; color: #6b7280; font-weight: 600; letter-spacing: 0.5px; }
        .task-value { font-size: 14px; font-weight: 500; color: #111; margin-bottom: 8px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-accepted { background: #dcfce7; color: #166534; }
        
        .btn-accept { background: #16a34a; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; width: 100%; font-weight:600; }
        .btn-reject { background: #dc2626; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; width: 100%; font-weight:600; }
        
        .img-thumb { width:100%; height:150px; object-fit:cover; border-radius:6px; background:#f9fafb; display:block; margin-bottom:10px; }
        .file-link { display:flex; align-items:center; gap:8px; padding:8px; background:#f3f4f6; border-radius:4px; text-decoration:none; color:#374151; font-size:13px; margin-bottom:5px; }
        
        .highlight { border: 2px solid #2563eb; }
    </style>
</head>
<body class="bg-light">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <main class="main-content">
        <h2>My Delivery Tasks</h2>
        
        <?php if($success): ?>
            <div style="background:#dcfce7; color:#166534; padding:15px; border-radius:6px; margin-bottom:20px;"><?= $success ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div style="background:#fee2e2; color:#b91c1c; padding:15px; border-radius:6px; margin-bottom:20px;"><?= $error ?></div>
        <?php endif; ?>

        <h3>Incoming Requests (<?= count($pending) ?>)</h3>
        <?php if(empty($pending)): ?>
            <p style="color:#666;">No pending requests.</p>
        <?php endif; ?>

        <?php foreach($pending as $t): ?>
            <div class="task-card <?= ($t['id'] == $highlightId) ? 'highlight' : '' ?>" id="task-<?= $t['id'] ?>">
                <div class="task-header">
                    <div>
                        <div class="task-label">Order Ref</div>
                        <div style="font-weight:700; font-size:16px;">#<?= $t['id'] ?> (<?= htmlspecialchars($t['invoice_ref']) ?>)</div>
                    </div>
                    <span class="badge badge-pending">PENDING ACCEPTANCE</span>
                </div>
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div>
                        <div class="task-label">Pickup Location</div>
                        <div class="task-value"><?= htmlspecialchars($t['pickup_location'] ?: 'Not specified') ?></div>
                    </div>
                    <div>
                        <div class="task-label">Destination</div>
                        <div class="task-value"><?= htmlspecialchars($t['client_name']) ?><br><small><?= htmlspecialchars($t['delivery_address']) ?></small></div>
                    </div>
                    <div>
                        <div class="task-label">Deadline</div>
                         <div class="task-value"><?= $t['delivery_deadline'] ? date('M d, H:i', strtotime($t['delivery_deadline'])) : 'ASAP' ?></div>
                    </div>
                </div>

                <div class="task-label">Description</div>
                <div class="task-value" style="background:#f9fafb; padding:10px; border-radius:4px;"><?= nl2br(htmlspecialchars($t['package_description'])) ?></div>

                <div style="margin:15px 0;">
                    <?php if($t['package_image']): ?>
                        <div class="task-label">Package Photo</div>
                        <a href="../<?= $t['package_image'] ?>" target="_blank">
                            <img src="../<?= $t['package_image'] ?>" class="img-thumb" alt="Package">
                        </a>
                    <?php endif; ?>
                    
                    <?php if($t['invoice_file']): ?>
                        <a href="../<?= $t['invoice_file'] ?>" target="_blank" class="file-link">
                            <span style="font-size:18px;">ðŸ“ƒ</span> View Invoice File
                        </a>
                    <?php endif; ?>
                </div>

                <div style="display:flex; gap:10px;">
                    <form method="POST" style="flex:1;">
                        <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                        <input type="hidden" name="action" value="accept">
                        <button type="submit" class="btn-accept">✔ Accept Delivery</button>
                    </form>
                    
                    <button class="btn-reject" onclick="openRejectModal(<?= $t['id'] ?>)" style="flex:1;">âœ” Reject</button>
                </div>
            </div>
        <?php endforeach; ?>

        <h3 style="margin-top:40px;">My Active Deliveries (<?= count($accepted) ?>)</h3>
        <?php foreach($accepted as $t): ?>
            <div class="task-card">
                 <div class="task-header">
                    <div>
                        <div class="task-label">Order Ref</div>
                        <div style="font-weight:700;">#<?= $t['id'] ?> (<?= htmlspecialchars($t['invoice_ref']) ?>)</div>
                    </div>
                    <span class="badge badge-accepted"><?= strtoupper($t['status']) ?></span>
                </div>
                <div>
                     <a href="process_delivery.php?order_id=<?= $t['id'] ?>" class="btn" style="width:100%; text-align:center;">Process / Update Status</a>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Rejection Modal -->
        <div id="rejectModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
             <div style="background:white; padding:20px; border-radius:8px; width:90%; max-width:400px;">
                 <h3>Reject Delivery Request</h3>
                 <form method="POST">
                     <input type="hidden" name="order_id" id="rejectOrderId" value="">
                     <input type="hidden" name="action" value="reject">
                     <label style="display:block; margin-bottom:5px;">Reason for Rejection *</label>
                     <textarea name="reason" required style="width:100%; height:100px; padding:10px; margin-bottom:15px; border:1px solid #ccc;"></textarea>
                     <div style="display:flex; justify-content:flex-end; gap:10px;">
                         <button type="button" onclick="document.getElementById('rejectModal').style.display='none'" style="padding:10px; background:#eee; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                         <button type="submit" class="btn-reject" style="width:auto;">Confirm Reject</button>
                     </div>
                 </form>
             </div>
        </div>

        <script>
            function openRejectModal(id) {
                document.getElementById('rejectOrderId').value = id;
                document.getElementById('rejectModal').style.display = 'flex'; // Fix display
            }
        </script>
    </main>
</body>
</html>
