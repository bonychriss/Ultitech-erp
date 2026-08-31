<?php
require_once '../includes/functions.php';
requireLogin();

if (!isset($_GET['order_id'])) {
    die("Order ID missing");
}
$orderId = $_GET['order_id'];

// Fetch Order Details with Delivery Note Signature
$stmt = $pdo->prepare("SELECT o.*, t.trip_ref, dn.receiver_signature_path 
                       FROM delivery_orders o 
                       LEFT JOIN delivery_trips t ON o.trip_id = t.id 
                       LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id 
                       WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) die("Order not found");

// Auto-start delivery if status is 'accepted'
$deliveryStarted = false;
if ($order['status'] === 'accepted') {
    // Update to in_transit
    $up = $pdo->prepare("UPDATE delivery_orders SET status = 'in_transit' WHERE id = ?");
    $up->execute([$orderId]);
    $order['status'] = 'in_transit'; // Update local var
    $deliveryStarted = true;

    // Notify Creator
    if (!empty($order['created_by'])) {
        createSystemNotification(
            $order['created_by'],
            "Delivery Started",
            "Driver " . $_SESSION['full_name'] . " has started delivery #" . $order['invoice_ref'],
            "deliveries/index.php" // Or link to specific view
        );
    }

    // [NEW] Auto-start Trip if it's still planned/loading
    if (!empty($order['trip_id'])) {
        $upTrip = $pdo->prepare("UPDATE delivery_trips SET status = 'in_transit', start_time = IFNULL(start_time, NOW()) WHERE id = ? AND status IN ('planned', 'loading')");
        $upTrip->execute([$order['trip_id']]);
    }
}

// Auto-create Trip if missing (so it shows in trips.php) - Run unconditionally if missing
if (empty($order['trip_id'])) {
    $tripRef = 'DT-' . date('ymd-His');
    // Ensure we have a valid driver ID. If missing, use current user.
    $driverId = $order['requested_driver_id'] ?: $_SESSION['user_id'];
    
    $stmtTrip = $pdo->prepare("INSERT INTO delivery_trips (trip_ref, driver_id, status, start_time) VALUES (?, ?, 'in_transit', NOW())");
    $stmtTrip->execute([$tripRef, $driverId]);
    $newTripId = $pdo->lastInsertId();
    
    // Assign order to trip
    $upOrder = $pdo->prepare("UPDATE delivery_orders SET trip_id = ? WHERE id = ?");
    $upOrder->execute([$newTripId, $orderId]);
    
    // Update local object
    $order['trip_id'] = $newTripId;
    $order['trip_ref'] = $tripRef;
}

// Handle POD Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_delivery') {
    try {
        // 1. Handle Signature Upload (Base64)
        $signaturePath = '';
        if (!empty($_POST['signature_data'])) {
            $data = $_POST['signature_data'];
            list($type, $data) = explode(';', $data);
            list(, $data)      = explode(',', $data);
            $data = base64_decode($data);
            $filename = 'sig_pod_' . $orderId . '_' . time() . '.png';
            $dir = ensureSignatureDir();
            file_put_contents($dir . '/' . $filename, $data);
            $signaturePath = 'assets/signatures/' . $filename;
        }

        // 2. Handle Photo Evidence
        if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] == 0) {
            $ext = pathinfo($_FILES['proof_photo']['name'], PATHINFO_EXTENSION);
            $photoPath = '../uploads/evidence/pod_' . $orderId . '_' . time() . '.' . $ext;
            if (!is_dir('../uploads/evidence')) mkdir('../uploads/evidence', 0777, true);
            move_uploaded_file($_FILES['proof_photo']['tmp_name'], $photoPath);

            // Log evidence
            $dbPhotoPath = 'uploads/evidence/' . basename($photoPath);
            $stmtEv = $pdo->prepare("INSERT INTO delivery_evidence (delivery_order_id, type, file_path) VALUES (?, 'photo_drop', ?)");
            $stmtEv->execute([$orderId, $dbPhotoPath]);
        }

        // 3. Update Order Status
        // [FIX] Only update signature if a brand new one is provided. 
        // This prevents overwriting a client's QR signature with 'empty' if the driver doesn't sign on their device.
        $stmtUpdate = $pdo->prepare("UPDATE delivery_orders SET 
            status = 'delivered',
            recipient_name = ?,
            recipient_role = ?,
            geo_lat = ?,
            geo_lng = ?,
            signature_path = CASE WHEN ? != '' THEN ? ELSE signature_path END,
            completion_time = CURRENT_TIMESTAMP
            WHERE id = ?");
        
        $stmtUpdate->execute([
            $_POST['recipient_name'],
            $_POST['recipient_role'],
            $_POST['geo_lat'],
            $_POST['geo_lng'],
            $signaturePath, // Check for empty
            $signaturePath, // Value to set if not empty
            $orderId
        ]);

        // 3.5 Sync Receiver Signature to Delivery Note
        if (!empty($order['delivery_note_id']) && !empty($signaturePath)) {
            $upDNRcv = $pdo->prepare("UPDATE delivery_notes SET receiver_signature_path = ? WHERE id = ?");
            $upDNRcv->execute([$signaturePath, $order['delivery_note_id']]);
        }

        // 4. Notify Creator of Completion
        if (!empty($order['created_by'])) {
            createSystemNotification(
                $order['created_by'],
                "Delivery Completed",
                "Order #" . $order['invoice_ref'] . " has been delivered to " . $_POST['recipient_name'],
                "deliveries/index.php"
            );
        }

        // 5. Automatic Signature Sync (Employee Signature to DN)
        if (!empty($order['delivery_note_id'])) {
            $driverSig = getUserSignaturePathById($_SESSION['user_id']);
            if ($driverSig) {
                $upDN = $pdo->prepare("UPDATE delivery_notes SET authorized_signature_path = ? WHERE id = ?");
                $upDN->execute([$driverSig, $order['delivery_note_id']]);
            }
        }

        // 6. [NEW] Auto-Complete Trip if all orders are finished
        if (!empty($order['trip_id'])) {
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM delivery_orders WHERE trip_id = ? AND status NOT IN ('delivered', 'failed', 'returned')");
            $stmtCheck->execute([$order['trip_id']]);
            $remaining = $stmtCheck->fetchColumn();

            if ($remaining == 0) {
                $upTrip = $pdo->prepare("UPDATE delivery_trips SET status = 'completed', end_time = NOW() WHERE id = ? AND status != 'completed'");
                $upTrip->execute([$order['trip_id']]);
            } else {
                // Otherwise ensure it's at least 'in_transit'
                $upTransit = $pdo->prepare("UPDATE delivery_trips SET status = 'in_transit' WHERE id = ? AND status IN ('planned', 'loading')");
                $upTransit->execute([$order['trip_id']]);
            }
        }

        header("Location: view_trip.php?trip_id=" . $order['trip_id']);
        exit;
    } catch (Exception $e) {
        $error = "Error saving POD: " . $e->getMessage();
    }
}

// AJAX: Check Signature Status
if (isset($_GET['check_sig'])) {
    // Check both Order and DN for signature
    $stmtSig = $pdo->prepare("SELECT signature_path, delivery_note_id FROM delivery_orders WHERE id = ?");
    $stmtSig->execute([$orderId]);
    $orderData = $stmtSig->fetch(PDO::FETCH_ASSOC);
    
    $sigPath = $orderData['signature_path'] ?: '';
    
    // Only count as "Client Signed Digitally" if it's on THIS order and has the client prefix
    $isClientSig = (strpos($sigPath, 'client_') !== false);
    
    header('Content-Type: application/json');
    echo json_encode([
        'signed' => $isClientSig,
        'path' => $isClientSig ? $sigPath : null
    ]);
    exit;
}

// Generate Verification Hash for QR
$vHash = generateOrderVerificationHash($orderId);
$verifyUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . APP_BASE_PATH . "/deliveries/verify_delivery.php?hash=" . $vHash;

$currentSig = $order['signature_path'] ?? '';
$clientHasSigned = (strpos($currentSig, 'client_') !== false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Proof of Delivery - #<?= $orderId ?></title>
    <!-- Styles -->
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <style>
        :root {
            /* Override or extend global vars */
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #059669;
            --danger: #dc2626;
            --bg: #f8fafc;
            --card: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        /* Reset conflicts with sidebar/header */
        body { 
            padding-bottom: 0;
            background: var(--bg);
        }

        .main-content {
            padding-bottom: 120px;
            padding-top: 24px;
        }

        .pod-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        
        @media (min-width: 1024px) {
            .pod-grid {
                grid-template-columns: 420px 1fr;
                align-items: start;
                gap: 32px;
            }
            .main-content { padding-top: 32px; }
        }

        .container { padding: 0 24px; max-width: 1100px; margin: 0 auto; }

        .card {
            background: var(--card);
            border-radius: 0;
            border: 1px solid var(--border);
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: none;
        }
        .card-header {
            padding: 14px 16px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .card-body { padding: 16px; }

        .input-group { margin-bottom: 16px; }
        .input-group:last-child { margin-bottom: 0; }
        .label { display: block; font-size: 13px; font-weight: 600; color: var(--text-main); margin-bottom: 6px; }
        .input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 0;
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
            background: #f9fafb;
        }
        .input:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* GPS Status */
        .gps-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 500;
            padding: 12px;
            border-radius: 0;
            background: #f1f5f9;
            margin-top: 12px;
            border-left: 3px solid var(--border);
        }
        .gps-dot { width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; }
        .gps-dot.active { background: var(--success); box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.2); animation: pulse 2s infinite; }

        /* Photo Upload */
        .photo-btn {
            width: 100%;
            padding: 31px;
            border: 1px solid var(--border);
            border-radius: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.2s;
            background: #f9fafb;
        }
        .photo-btn:hover { background: #f1f5f9; border-color: var(--text-muted); }
        .photo-preview { margin-top: 12px; width: 100%; height: 200px; object-fit: cover; border-radius: 0; display: none; }

        /* Signature Pad */
        .sig-container {
            border: 1px solid var(--border);
            border-radius: 0;
            background: #fff;
            position: relative;
            touch-action: none;
        }
        .sig-canvas { width: 100%; height: 160px; display: block; cursor: crosshair; }
        .sig-actions {
            display: flex;
            justify-content: flex-end;
            padding: 8px;
            background: #f8fafc;
            border-top: 1px solid var(--border);
        }
        .btn-clear { background: none; border: none; color: var(--danger); font-size: 12px; font-weight: 600; cursor: pointer; }

        .btn-finish {
            width: 100%;
            background: var(--primary);
            color: #fff;
            border: 1px solid var(--primary-dark);
            padding: 16px;
            border-radius: 0;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }
        .btn-finish:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2); }
        .btn-finish:active { transform: scale(0.98); }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

    </style>
    <style>
        /* Mobile Specific Overrides */
        @media (max-width: 768px) {
            .main-content { padding: 15px !important; }
            .pod-grid { grid-template-columns: 1fr !important; gap: 0 !important; }
            .header-row { margin-bottom: 15px !important; }
            
            /* Flatten All */
            .card, .step-card, .qr-ticket, .qr-card-wrapper > div {
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
                margin-bottom: 24px !important;
            }
            .step-header { padding: 10px 0 !important; border:none !important; }
            .step-body { padding: 0 !important; }
            .qr-ticket { padding: 20px 0 !important; background: none !important; color: #1e293b !important; text-align: center !important; }
            .qr-ticket::after { display: none; }
            .qr-frame { background: #f8fafc; border: 1px solid #e2e8f0; display: inline-block; margin: 15px auto; }
            
            .btn-finish { border-radius: 4px; }
            .input { background: #fff; border-radius: 4px; }
            .photo-btn { background: #fff; border-radius: 4px; }
        }
  @media (min-width: 769px) {
            /* Removed mobile-module-nav */
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_employee.php'; ?>

    <main class="main-content">
        <div class="header-row" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 15px;">
            <div>
                <nav style="margin-bottom: 12px;">
                    <a href="Driver.php" class="back-link" style="display:inline-flex; align-items:center; gap:6px; color:#64748b; text-decoration:none; font-size:12px; font-weight:500; background:#fff; padding:5px 12px; border:1px solid #e2e8f0; border-radius:0; box-shadow:none;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>
                        Back to Portal
                    </a>
                </nav>
                <h1 style="margin:0; font-size:26px; font-weight:800; color:#0f172a; letter-spacing:-0.02em;">Finish Delivery #<?= $orderId ?></h1>
                <div style="margin-top:4px; font-size:15px; color:#64748b; display:flex; align-items:center; gap:8px;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2m8-10a4 4 0 014-4 4 4 0 014 4 4 4 0 01-4 4 4 4 0 01-4-4z"/></svg>
                    <?= htmlspecialchars($order['client_name']) ?>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="background: #e0f2fe; color: #0369a1; font-size:11px; font-weight:700; padding:6px 14px; border-radius:0; border:1px solid #bae6fd; display:flex; align-items:center; gap:6px;">
                    <span style="width:6px; height:6px; background:#0369a1; border-radius:0;"></span>
                    IN-TRANSIT
                </span>
            </div>
        </div>

        <div class="pod-grid">
            <!-- LEFT COLUMN: QR & Context -->
            <div class="qr-card-wrapper">
                <div class="qr-ticket">
                    <div style="font-size:12px; font-weight:600; opacity:0.8; letter-spacing:1px; margin-bottom:5px;">DIGITAL HANDOVER</div>
                    <h2 style="margin:0; font-size:20px; font-weight:800;">SCAN TO VERIFY</h2>
                    
                    <div class="qr-frame">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($verifyUrl) ?>" width="180" height="180" alt="QR">
                    </div>
                    
                    <p style="font-size:13px; opacity:0.9; margin:0; line-height:1.4;">Ask client to scan this code with their phone camera to view documents and sign.</p>
                </div>

                <!-- SHAREABLE LINK SECTION -->
                <div style="background: white; border: 1px solid #e2e8f0; border-top: none; padding: 15px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:6px; letter-spacing:0.05em; text-transform:uppercase;">Remote Signing Link</label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="shareUrl" value="<?= htmlspecialchars($verifyUrl) ?>" readonly style="width:100%; padding:8px; font-size:12px; color:#475569; background:#f8fafc; border:1px solid #cbd5e1; outline:none;">
                        <button type="button" onclick="copyShareLink()" title="Copy Link" style="background:#f1f5f9; border:1px solid #cbd5e1; padding:0 10px; cursor:pointer;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                    <button type="button" onclick="window.open('https://wa.me/?text=' + encodeURIComponent('Please sign for your delivery here: ' + document.getElementById('shareUrl').value), '_blank')" style="display:block; width:100%; margin-top:10px; padding:10px; background:#25D366; color:white; border:none; font-weight:600; font-size:13px; border-radius:4px; cursor:pointer;">
                        Share via WhatsApp
                    </button>
                    <script>
                    function copyShareLink() {
                        const copyText = document.getElementById("shareUrl");
                        if (!copyText) return;
                        copyText.select();
                        copyText.setSelectionRange(0, 99999); 
                        navigator.clipboard.writeText(copyText.value);
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Link Copied!', showConfirmButton: false, timer: 1500 });
                    }
                    </script>
                </div>

                <div class="step-card" style="margin-top:20px; border-top-color:#64748b;">
                    <div class="step-header">
                        <div class="step-icon" style="background:#f1f5f9; color:#64748b;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="step-title">Delivery Info</div>
                    </div>
                    <div class="step-body" style="font-size:13px; color:#475569;">
                        <div style="margin-bottom:8px;"><strong>Recipient:</strong><br><?= htmlspecialchars($order['client_name']) ?></div>
                        <div><strong>Address:</strong><br><?= htmlspecialchars($order['delivery_address']) ?></div>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Actions Form -->
            <div class="pod-workflow">
                <form id="podForm" method="POST" enctype="multipart/form-data" onsubmit="return handleFormSubmit(event)">
                    <input type="hidden" name="action" value="complete_delivery">
                    <input type="hidden" name="signature_data" id="signature_data">
                    <input type="hidden" name="geo_lat" id="geo_lat">
                    <input type="hidden" name="geo_lng" id="geo_lng">

                    <!-- STEP 1: Details -->
                    <div class="step-card">
                        <div class="step-header">
                            <div class="step-icon">
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </div>
                            <div class="step-title">1. Recipient Details</div>
                        </div>
                        <div class="step-body">
                            <div class="input-group">
                                <label class="label">Received By (Name)</label>
                                <input type="text" name="recipient_name" class="input" placeholder="Full Name" required>
                            </div>
                            <div class="input-group">
                                <label class="label">Position / Role</label>
                                <select name="recipient_role" class="input" required>
                                    <option value="">Select Role...</option>
                                    <option value="Customer">Client / Customer</option>
                                    <option value="Manager">Site Manager</option>
                                    <option value="Security">Security / Guard</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 2: Evidence -->
                    <div class="step-card">
                        <div class="step-header">
                            <div class="step-icon">
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </div>
                            <div class="step-title">2. Evidence & Sign</div>
                        </div>
                        <div class="step-body">
                            <label class="label">Drop Photo</label>
                            <label class="photo-btn" style="margin-bottom:20px;">
                                <input type="file" name="proof_photo" accept="image/*" capture="camera" class="hidden" onchange="previewImage(this)">
                                <span style="font-weight:600; color:#64748b;">Tap to Capture Photo</span>
                            </label>
                            <img id="preview" class="photo-preview">

                            <label class="label">Manual Signature (Alternative)</label>
                            <p style="font-size:11px; color:var(--text-muted); margin-bottom:10px;">If client cannot scan the QR, you may sign on their behalf here.</p>
                            
                            <!-- Remote Signature Detected View -->
                            <div id="remote-sig-box" style="display: <?= $clientHasSigned ? 'block' : 'none' ?>; background:#f0fdf4; border:1px solid #bbf7d0; padding:15px; text-align:center;">
                                <div style="color:#166534; font-weight:700; font-size:14px; margin-bottom:5px;">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" style="margin-right:4px;"><path d="M5 13l4 4L19 7"/></svg>
                                    Client Signed Digitally
                                </div>
                                <div style="font-size:12px; color:#15803d;">Signature applied from QR verification.</div>
                            </div>

                            <!-- Manual Signature Pad -->
                            <div id="manual-sig-box" style="display: <?= $clientHasSigned ? 'none' : 'block' ?>;">
                                <div class="sig-container">
                                    <canvas id="signature-pad" class="sig-canvas"></canvas>
                                    <div class="sig-actions">
                                        <button type="button" class="btn-clear" onclick="clearSignature()">Clear</button>
                                    </div>
                                </div>
                            </div>
                            <div class="gps-status">
                                <div id="gps-dot" class="gps-dot"></div>
                                <span id="gps-text">Locating...</span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-finish">
                        CONFIRM DELIVERY
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Initialize Signature Pad
        const canvas = document.getElementById('signature-pad');
        const signaturePad = new SignaturePad(canvas, {
            backgroundColor: 'rgba(255, 255, 255, 0)',
            penColor: '#0f172a'
        });

        // Resize canvas correctly
        function resizeCanvas() {
            const ratio =  Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            signaturePad.clear(); // otherwise scale will mess up existing signature
        }

        window.addEventListener("resize", resizeCanvas);
        resizeCanvas();

        function clearSignature() {
            signaturePad.clear();
        }

        // Image Preview
        function previewImage(input) {
            const preview = document.getElementById('preview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // GPS Capture
        if ("geolocation" in navigator) {
            const options = { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 };
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    document.getElementById('geo_lat').value = pos.coords.latitude;
                    document.getElementById('geo_lng').value = pos.coords.longitude;
                    document.getElementById('gps-dot').classList.add('active');
                    document.getElementById('gps-text').innerText = "Location Captured Securely";
                    document.getElementById('gps-text').style.color = "#059669";
                },
                (err) => {
                    document.getElementById('gps-text').innerText = "GPS Error: " + err.message;
                    document.getElementById('gps-text').style.color = "#dc2626";
                    console.warn(err);
                },
                options
            );
        } else {
            document.getElementById('gps-text').innerText = "GPS Not Supported";
        }

        // Form Submission
        async function handleFormSubmit(e) {
            // Check if remote signed
            const remoteSigned = document.getElementById('remote-sig-box').style.display !== 'none';

            if (!remoteSigned && signaturePad.isEmpty()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Signature Required',
                    text: 'Please ask the recipient to sign before finishing.'
                });
                return false;
            }

            // Set signature data IF not remote signed
            if (!remoteSigned) {
                document.getElementById('signature_data').value = signaturePad.toDataURL();
            }
            
            // Show loading
            Swal.fire({
                title: 'Processing POD...',
                text: 'Uploading evidence and updating status.',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
        }

        // Poll for Remote Signature
        setInterval(() => {
            const remoteBox = document.getElementById('remote-sig-box');
            if (remoteBox.style.display !== 'none') return; // Already signed

            fetch('process_delivery.php?order_id=<?= $orderId ?>&check_sig=1')
                .then(r => r.json())
                .then(data => {
                    if (data.signed) {
                        document.getElementById('manual-sig-box').style.display = 'none';
                        document.getElementById('remote-sig-box').style.display = 'block';
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: 'Client has signed via QR Code',
                            showConfirmButton: false,
                            timer: 3000
                        });
                    }
                })
                .catch(err => console.error("Polling error", err));
        }, 3000); // Check every 3 seconds

        <?php if($deliveryStarted): ?>
        Swal.fire({
            icon: 'info',
            title: 'Delivery Started',
            text: 'You are now in-transit. Please capture Proof of Delivery below once arrived.',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4000
        });
        <?php endif; ?>
    </script>
</body>
</html>
