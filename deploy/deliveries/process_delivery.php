<?php
require_once '../includes/functions.php';
requireLogin();

if (!isset($_GET['order_id'])) {
    die("Order ID missing");
}
$orderId = $_GET['order_id'];

// Fetch Order Details
$stmt = $pdo->prepare("SELECT o.*, t.trip_ref FROM delivery_orders o JOIN delivery_trips t ON o.trip_id = t.id WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) die("Order not found");

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
            $signaturePath = '../uploads/signatures/sig_' . $orderId . '_' . time() . '.png';
            if (!is_dir('../uploads/signatures')) mkdir('../uploads/signatures', 0777, true);
            file_put_contents($signaturePath, $data);
        }

        // 2. Handle Photo Evidence
        if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] == 0) {
            $ext = pathinfo($_FILES['proof_photo']['name'], PATHINFO_EXTENSION);
            $photoPath = '../uploads/evidence/pod_' . $orderId . '_' . time() . '.' . $ext;
            if (!is_dir('../uploads/evidence')) mkdir('../uploads/evidence', 0777, true);
            move_uploaded_file($_FILES['proof_photo']['tmp_name'], $photoPath);

            // Log evidence
            $stmtEv = $pdo->prepare("INSERT INTO delivery_evidence (delivery_order_id, type, file_path) VALUES (?, 'photo_drop', ?)");
            $stmtEv->execute([$orderId, $photoPath]);
        }

        // 3. Update Order Status
        $stmtUpdate = $pdo->prepare("UPDATE delivery_orders SET 
            status = 'delivered',
            recipient_name = ?,
            recipient_role = ?,
            geo_lat = ?,
            geo_lng = ?,
            signature_path = ?,
            completion_time = CURRENT_TIMESTAMP
            WHERE id = ?");
        
        $stmtUpdate->execute([
            $_POST['recipient_name'],
            $_POST['recipient_role'],
            $_POST['geo_lat'],
            $_POST['geo_lng'],
            $signaturePath,
            $orderId
        ]);

        header("Location: view_trip.php?trip_id=" . $order['trip_id']);
        exit;

    } catch (Exception $e) {
        $error = "Error saving POD: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Process Delivery - <?= COMPANY_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; margin:0; padding-bottom: 40px; }
        .header { background: #2563eb; color:white; padding: 20px; }
        .container { padding: 15px; }
        .card { background: white; padding: 15px; border-radius: 0; margin-bottom: 15px; border: 1px solid #e5e7eb; }
        label { display:block; font-size:12px; color:#666; margin-bottom:5px; font-weight:600; }
        input[type="text"] { width:100%; padding:10px; border:1px solid #ddd; margin-bottom:15px; border-radius:0; font-family:inherit; }
        .btn-large { width:100%; padding:15px; background:#059669; color:white; border:none; font-weight:bold; font-size:16px; cursor:pointer; }
        
        /* Signature Canvas */
        #signature-pad { border: 1px dashed #ccc; background: #fafafa; width: 100%; touch-action: none; }
    </style>
</head>
<body>
    <div class="header">
        <a href="view_trip.php?trip_id=<?= $order['trip_id'] ?>" style="color:white; text-decoration:none; font-size:12px;">&larr; Cancel</a>
        <h2 style="margin:5px 0 0 0;">Drop-Off</h2>
        <p style="margin:0; opacity:0.8; font-size:14px;"><?= htmlspecialchars($order['client_name']) ?></p>
    </div>

    <form method="POST" enctype="multipart/form-data" class="container">
        <input type="hidden" name="action" value="complete_delivery">
        <input type="hidden" id="geo_lat" name="geo_lat">
        <input type="hidden" id="geo_lng" name="geo_lng">
        <input type="hidden" id="signature_data" name="signature_data">

        <!-- Info Card -->
        <div class="card">
            <label>DELIVERY ADDRESS</label>
            <div style="margin-bottom:10px;"><?= nl2br(htmlspecialchars($order['delivery_address'])) ?></div>
            <label>INVOICE REF</label>
            <div><?= htmlspecialchars($order['invoice_ref']) ?></div>
        </div>

        <!-- Geo Tag -->
        <div class="card">
            <label>LOCATION CHECK</label>
            <div id="geo-status" style="color:#d97706; font-size:13px;">Waiting for GPS...</div>
        </div>

        <!-- Recipient Info -->
        <div class="card">
            <label>RECEIVED BY (NAME)</label>
            <input type="text" name="recipient_name" required placeholder="John Doe">
            <label>ROLE / TITLE</label>
            <input type="text" name="recipient_role" required placeholder="Safety Officer">
        </div>

        <!-- Item Checklist (MVP: Simple confirmation) -->
        <div class="card">
            <label>ITEM VERIFICATION</label>
            <!-- In future, loop through items here -->
            <div style="display:flex; gap:10px; align-items:center; margin-bottom:10px;">
                <input type="checkbox" checked style="width:20px; height:20px;">
                <span>Verified correct batch & quantity</span>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <input type="checkbox" style="width:20px; height:20px;">
                <span>Invoice handed over</span>
            </div>
        </div>

        <!-- Evidence -->
        <div class="card">
            <label>PROOF OF DROP (PHOTO)</label>
            <input type="file" name="proof_photo" accept="image/*" capture="environment" required style="padding:10px 0;">
            
            <label style="margin-top:10px;">DIGITAL SIGNATURE</label>
            <canvas id="signature-pad" width="300" height="150"></canvas>
            <button type="button" onclick="clearSignature()" style="font-size:11px; padding:4px 8px; margin-top:5px;">Clear Signature</button>
        </div>

        <button type="submit" class="btn-large" onclick="saveSignature()">CONFIRM DELIVERY</button>
    </form>

    <script>
        // 1. Geo Location
        if ("geolocation" in navigator) {
            navigator.geolocation.getCurrentPosition(function(position) {
                document.getElementById('geo_lat').value = position.coords.latitude;
                document.getElementById('geo_lng').value = position.coords.longitude;
                document.getElementById('geo-status').innerHTML = "✅ GPS Captured";
                document.getElementById('geo-status').style.color = "#059669";
            }, function(error) {
                document.getElementById('geo-status').innerHTML = "❌ GPS Failed: " + error.message;
                document.getElementById('geo-status').style.color = "#ef4444";
            });
        }

        // 2. Signature Pad
        var canvas = document.getElementById('signature-pad');
        var ctx = canvas.getContext('2d');
        var drawing = false;

        // Adjust canvas size to parent width
        canvas.width = canvas.parentElement.clientWidth - 32;

        function startDraw(e) {
            drawing = true;
            ctx.beginPath();
            var {x, y} = getPos(e);
            ctx.moveTo(x, y);
        }
        function draw(e) {
            if(!drawing) return;
            var {x, y} = getPos(e);
            ctx.lineTo(x, y);
            ctx.stroke();
        }
        function endDraw() { drawing = false; }
        function getPos(e) {
            var rect = canvas.getBoundingClientRect();
            var touch = e.touches ? e.touches[0] : e;
            return { x: touch.clientX - rect.left, y: touch.clientY - rect.top };
        }

        // Mouse Events
        canvas.addEventListener('mousedown', startDraw);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', endDraw);
        // Touch Events
        canvas.addEventListener('touchstart', (e) => { e.preventDefault(); startDraw(e); });
        canvas.addEventListener('touchmove', (e) => { e.preventDefault(); draw(e); });
        canvas.addEventListener('touchend', endDraw);

        function clearSignature() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        function saveSignature() {
            var dataUrl = canvas.toDataURL();
            document.getElementById('signature_data').value = dataUrl;
        }
    </script>
</body>
</html>
