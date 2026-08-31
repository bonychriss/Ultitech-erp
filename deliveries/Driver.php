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
                // Update Order Status
                $up = $pdo->prepare("UPDATE delivery_orders SET status = 'accepted' WHERE id = ?");
                $up->execute([$oid]);
                
                // Update Trip Status (if exists and is planned)
                // We assume 1-to-1 mapping for auto-created trips
                $stmtTrip = $pdo->prepare("SELECT trip_id FROM delivery_orders WHERE id = ?");
                $stmtTrip->execute([$oid]);
                $tripId = $stmtTrip->fetchColumn();

                if ($tripId) {
                    $upTrip = $pdo->prepare("UPDATE delivery_trips SET status = 'loading' WHERE id = ? AND status = 'planned'");
                    $upTrip->execute([$tripId]);
                }

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

// Fetch All Pending Requests (Visible to everyone)
$stmtP = $pdo->prepare("
    SELECT do.*, u.full_name as driver_name 
    FROM delivery_orders do
    LEFT JOIN users u ON do.requested_driver_id = u.id
    WHERE do.status = 'request_pending' 
    ORDER BY do.created_at DESC
");
$stmtP->execute();
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
    <title>Driver Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
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
        .info-box { background: #eff6ff; border: 1px solid #dbeafe; color: #1e40af; padding: 6px; border-radius: 4px; font-size: 11px; display: flex; align-items: center; gap: 6px; }
        
        /* Mobile Table Refinement */
        @media (max-width: 768px) {
            .table-wrap {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -15px; /* Bleed slightly */
                padding: 0 15px;
            }
            .data-table {
                min-width: 750px; /* Force scroll */
                display: table !important;
            }
            .data-table thead { display: table-header-group !important; }
            .data-table tbody { display: table-row-group !important; }
            .data-table tr { display: table-row !important; }
            .data-table td, .data-table th { 
                display: table-cell !important; 
                white-space: nowrap !important;
                font-size: 11px !important;
                padding: 8px 6px !important;
            }
            .data-table td::before { display: none !important; } /* Hide stacked labels */
            
            .btn-accept, .btn-reject, .btn {
                padding: 4px 8px !important;
                font-size: 10px !important;
            }
        }
    </style>
</head>
<body class="bg-light">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <main class="main-content">
        <h2>Driver Portal</h2>
        
        <?php if($success): ?>
            <div style="background:#dcfce7; color:#166534; padding:15px; border-radius:6px; margin-bottom:20px;"><?= $success ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div style="background:#fee2e2; color:#b91c1c; padding:15px; border-radius:6px; margin-bottom:20px;"><?= $error ?></div>
        <?php endif; ?>

        <h3>Incoming Requests (<?= count($pending) ?>)</h3>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order Ref</th>
                        <th>Pickup & Destination</th>
                        <th>Deadline</th>
                        <th>Description</th>
                        <th>Attachments</th>
                        <th>Status / Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($pending)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px; color:#666;">
                                No pending requests.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($pending as $t): ?>
                            <tr class="<?= ($t['id'] == $highlightId) ? 'highlight' : '' ?>" id="task-<?= $t['id'] ?>">
                                <td style="font-weight:600;">
                                    #<?= $t['id'] ?><br>
                                    <small style="color:#666; font-weight:400;"><?= htmlspecialchars($t['invoice_ref']) ?></small>
                                </td>
                                <td>
                                    <div style="font-size:12px; color:#555;">From: <strong><?= htmlspecialchars($t['pickup_location'] ?: 'Not specified') ?></strong></div>
                                    <div style="margin-top:4px;">To: <?= htmlspecialchars($t['client_name']) ?></div>
                                    <div style="font-size:11px; color:#666;"><?= htmlspecialchars($t['delivery_address']) ?></div>
                                </td>
                                <td>
                                    <?php if($t['delivery_deadline']): ?>
                                        <?= date('M d, H:i', strtotime($t['delivery_deadline'])) ?>
                                    <?php else: ?>
                                        <span style="color:#999;">ASAP</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:12px;">
                                        <?php if(!empty($t['package_weight'])): ?>
                                            <strong><?= htmlspecialchars($t['package_weight']) ?></strong><br>
                                        <?php endif; ?>
                                        <?= nl2br(htmlspecialchars($t['package_description'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <?php if($t['package_image']): ?>
                                            <a href="../<?= $t['package_image'] ?>" target="_blank" class="file-link" style="padding:2px 6px; font-size:11px;">📷 Photo</a>
                                        <?php endif; ?>
                                        <?php if($t['invoice_file']): ?>
                                            <a href="../<?= $t['invoice_file'] ?>" target="_blank" class="file-link" style="padding:2px 6px; font-size:11px;">📄 Invoice</a>
                                        <?php endif; ?>
                                        <?php if($t['delivery_note_id']): ?>
                                            <a href="view_delivery_note.php?id=<?= $t['delivery_note_id'] ?>" target="_blank" class="file-link" style="padding:2px 6px; font-size:11px; background:#eff6ff; color:#2563eb; border:1px solid #dbeafe;">📋 Delivery Note</a>
                                        <?php endif; ?>
                                        <?php if(!$t['package_image'] && !$t['invoice_file'] && !$t['delivery_note_id']): ?>
                                            <span style="color:#999; font-size:11px;">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($t['requested_driver_id'] == $myId): ?>
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            <form method="POST">
                                                <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                                <input type="hidden" name="action" value="accept">
                                                <button type="submit" class="btn-accept" style="padding:6px; font-size:12px;">✔ Accept</button>
                                            </form>
                                            <button class="btn-reject" onclick="openRejectModal(<?= $t['id'] ?>)" style="padding:6px; font-size:12px;">✖ Reject</button>
                                        </div>
                                    <?php else: ?>
                                        <div class="info-box" style="padding:6px; font-size:11px;">
                                            Waiting for <?= htmlspecialchars($t['driver_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top:40px;">My Active Deliveries (<?= count($accepted) ?>)</h3>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order Ref</th>
                        <th>Client & Destination</th>
                        <th>Deadline</th>
                        <th>Description</th>
                        <th>Attachments</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($accepted)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:20px; color:#666;">
                                No active deliveries found. Accept a request above to start.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($accepted as $t): ?>
                            <tr>
                                <td style="font-weight:600;">#<?= $t['id'] ?><br><small style="color:#666; font-weight:400;"><?= htmlspecialchars($t['invoice_ref']) ?></small></td>
                                <td>
                                    <?= htmlspecialchars($t['client_name']) ?>
                                    <div style="font-size:12px; color:#555;"><?= htmlspecialchars($t['delivery_address']) ?></div>
                                </td>
                                <td>
                                    <?php if($t['delivery_deadline']): ?>
                                        <?= date('M d, H:i', strtotime($t['delivery_deadline'])) ?>
                                    <?php else: ?>
                                        <span style="color:#999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:12px;">
                                        <?php if(!empty($t['package_weight'])): ?>
                                            <strong><?= htmlspecialchars($t['package_weight']) ?></strong><br>
                                        <?php endif; ?>
                                        <?= nl2br(htmlspecialchars($t['package_description'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <?php if($t['package_image']): ?>
                                            <a href="../<?= $t['package_image'] ?>" target="_blank" class="file-link" style="padding:2px 6px; font-size:11px;">📷 Photo</a>
                                        <?php endif; ?>
                                        <?php if($t['invoice_file']): ?>
                                            <a href="../<?= $t['invoice_file'] ?>" target="_blank" class="file-link" style="padding:2px 6px; font-size:11px;">📄 Invoice</a>
                                        <?php endif; ?>
                                        <?php if($t['delivery_note_id']): ?>
                                            <a href="view_delivery_note.php?id=<?= $t['delivery_note_id'] ?>" target="_blank" class="file-link" style="padding:2px 6px; font-size:11px; background:#eff6ff; color:#2563eb; border:1px solid #dbeafe;">📋 Delivery Note</a>
                                        <?php endif; ?>
                                        <?php if(!$t['package_image'] && !$t['invoice_file'] && !$t['delivery_note_id']): ?>
                                            <span style="color:#999; font-size:11px;">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-accepted"><?= strtoupper($t['status']) ?></span>
                                </td>
                                <td>
                                    <a href="process_delivery.php?order_id=<?= $t['id'] ?>" class="btn" style="padding:6px 12px; font-size:13px;">
                                        <?php 
                                        if ($t['status'] === 'accepted') echo 'Start Delivery';
                                        elseif ($t['status'] === 'in_transit') echo 'Finish Delivery';
                                        else echo 'Process';
                                        ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Rejection Modal -->
        <div id="rejectModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:999;">
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
                document.getElementById('rejectModal').style.display = 'flex';
            }
        </script>
    </main>
</body>
</html>
