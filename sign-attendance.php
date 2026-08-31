<?php
require_once '../includes/functions.php';
requireLogin();
ensureAttendanceTable();

// Use global constants defined in config.php (loaded from env.office.php)
// Fallback if not defined (though config.php handles defaults)
if (!defined('OFFICE_LAT'))
    define('OFFICE_LAT', -6.7924);
if (!defined('OFFICE_LON'))
    define('OFFICE_LON', 39.2083);
if (!defined('OFFICE_RADIUS_M'))
    define('OFFICE_RADIUS_M', 100);

// Handle form submission
$success = '';
$error = '';
$locationStatus = '';
$taskError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle daily task creation (submitted before sign-out)
    if (isset($_POST['action']) && $_POST['action'] === 'create_task') {
        $taskDescription = trim($_POST['task_description'] ?? '');
        if (empty($taskDescription)) {
            $taskError = 'Please describe your daily task.';
        } elseif (strlen($taskDescription) < 10) {
            $taskError = 'Task description must be at least 10 characters.';
        } else {
            if (createTask($_SESSION['user_id'], 'daily', $taskDescription)) {
                $success = 'Daily task created successfully! You can now sign out.';
            } else {
                $taskError = 'Error creating task. Please try again.';
            }
        }
    }

    // Handle attendance sign-in/sign-out
    if (isset($_POST['sign_type'])) {
        $signType = $_POST['sign_type'];
        $signatureData = isset($_POST['signature']) ? $_POST['signature'] : '';
        $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : 0;
        $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : 0;

        // For sign-out, check if user has created daily task (only for non-admin employees)
        // TEMPORARILY DISABLED until tasks table is created on server
        /*
        if ($signType === 'sign_out' && !isAdmin()) {
            if (function_exists('hasDailyTaskToday') && !hasDailyTaskToday($_SESSION['user_id'])) {
                $error = 'You must create a daily task before signing out.';
            }
        }
        */

        // Validate IP Address (if enabled)
        if (defined('OFFICE_IP_ENABLED') && OFFICE_IP_ENABLED == 1) {
            $currentIp = $_SERVER['REMOTE_ADDR'];
            $allowedIps = defined('OFFICE_IPS') ? array_map('trim', explode(',', OFFICE_IPS)) : [];

            // Should usually allow localhost if we are dev, but let's stick to the config
            // Note: If you locked yourself out, disable OFFICE_IP_ENABLED in env.office.php manually
            if (!in_array($currentIp, $allowedIps)) {
                $error = 'Network restriction active. You must be connected to the office Wi-Fi (' . htmlspecialchars($currentIp) . ' not allowed).';
            }
        }

        // Validate signature
        if (empty($error) && empty($signatureData)) {
            $error = 'Please provide your signature.';
        } elseif (empty($error) && ($latitude == 0 || $longitude == 0)) {
            $error = 'Unable to get your location. Please enable location services and try again.';
        } elseif (empty($error)) {
            // Calculate distance from office
            $distance = calculateDistance($latitude, $longitude, OFFICE_LAT, OFFICE_LON);

            // Check if within allowed radius
            if ($distance > OFFICE_RADIUS_M) {
                $error = 'Sign-in restricted. You must be within the office area (' . OFFICE_RADIUS_M . 'm radius) to sign. Your current distance: ' . number_format($distance, 2) . ' meters.';
                $locationStatus = 'outside';
            } else {
                // Get device info
                $deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

                // Check if user already signed in today (for sign_out validation)
                if ($signType === 'sign_out') {
                    $stmt = $pdo->prepare("
                        SELECT id FROM attendance 
                        WHERE user_id = ? 
                        AND sign_type = 'sign_in' 
                        AND DATE(signed_at) = DATE(?)
                        ORDER BY signed_at DESC 
                        LIMIT 1
                    ");
                    $sysTime = getSystemTime();
                    $stmt->execute([$_SESSION['user_id'], $sysTime->format('Y-m-d H:i:s')]);
                    $signInRecord = $stmt->fetch();

                    if (!$signInRecord) {
                        $error = 'You must sign in before signing out.';
                    }
                }

                // Check if user already signed in/out today (prevent duplicates)
                if (empty($error)) {
                    $stmt = $pdo->prepare("
                        SELECT id FROM attendance 
                        WHERE user_id = ? 
                        AND sign_type = ? 
                        AND DATE(signed_at) = DATE(?)
                        LIMIT 1
                    ");
                    $sysTime = getSystemTime();
                    $stmt->execute([$_SESSION['user_id'], $signType, $sysTime->format('Y-m-d H:i:s')]);
                    $existingRecord = $stmt->fetch();

                    if ($existingRecord) {
                        $actionText = $signType === 'sign_in' ? 'signed in' : 'signed out';
                        $error = "You have already {$actionText} today.";
                    }
                }

                // Save attendance record
                if (empty($error)) {
                    try {
                        $sysTime = getSystemTime();
                        $stmt = $pdo->prepare("
                            INSERT INTO attendance (user_id, signature_image, latitude, longitude, distance_from_office, sign_type, device_info, ip_address, signed_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $_SESSION['user_id'],
                            $signatureData,
                            $latitude,
                            $longitude,
                            $distance,
                            $signType,
                            $deviceInfo,
                            $ipAddress,
                            $sysTime->format('Y-m-d H:i:s')
                        ]);

                        $actionText = $signType === 'sign_in' ? 'signed in' : 'signed out';
                        $success = "Successfully {$actionText} at " . $sysTime->format(getSystemTimeFormat()) . ". Distance from office: " . number_format($distance, 2) . " meters.";
                        $locationStatus = 'inside';
                    } catch (PDOException $e) {
                        $error = 'Error saving attendance: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Get today's attendance status
$sysTime = getSystemTime();
$stmt = $pdo->prepare("
    SELECT sign_type, signed_at 
    FROM attendance 
    WHERE user_id = ? 
    AND DATE(signed_at) = DATE(?)
    ORDER BY signed_at DESC
");
$stmt->execute([$_SESSION['user_id'], $sysTime->format('Y-m-d H:i:s')]);
$todayRecords = $stmt->fetchAll();

$hasSignedIn = false;
$hasSignedOut = false;
$lastSignIn = null;
$lastSignOut = null;

foreach ($todayRecords as $record) {
    if ($record['sign_type'] === 'sign_in' && !$hasSignedIn) {
        $hasSignedIn = true;
        $lastSignIn = $record['signed_at'];
    } elseif ($record['sign_type'] === 'sign_out' && !$hasSignedOut) {
        $hasSignedOut = true;
        $lastSignOut = $record['signed_at'];
    }
}

// Calculate distance helper function (Haversine formula)
function calculateDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371000; // Earth's radius in meters

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c; // Distance in meters
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Attendance - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .signature-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            color: #212529;
            text-decoration: none;
            border: 1px solid #dee2e6;
            border-radius: 0;
            background: #ffffff;
            transition: all 0.2s;
            font-size: 14px;
        }

        .back-button:hover {
            background: #f8f9fa;
            border-color: #adb5bd;
            color: #212529;
        }

        .back-button .icon {
            width: 20px;
            height: 20px;
        }

        .page-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            margin: 0;
            flex: 1;
        }

        .attendance-card {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 0;
            padding: 24px;
            margin-bottom: 24px;
        }

        .attendance-status {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .status-item {
            flex: 1;
            min-width: 150px;
            padding: 16px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0;
            text-align: center;
        }

        .status-item.signed-in {
            background: #e8f5e9;
            border-color: #4caf50;
        }

        .status-item.signed-out {
            background: #fff3e0;
            border-color: #ff9800;
        }

        .status-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .status-time {
            font-size: 18px;
            font-weight: 600;
            color: #212529;
        }

        .signature-section {
            margin-bottom: 24px;
        }

        .signature-section h3 {
            font-size: 16px;
            margin-bottom: 12px;
            color: #212529;
        }

        #signatureCanvas {
            border: 2px solid #dee2e6;
            border-radius: 0;
            cursor: crosshair;
            background: #ffffff;
            display: block;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
        }

        .signature-controls {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .btn-clear-signature {
            padding: 8px 16px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-clear-signature:hover {
            background: #5a6268;
        }

        .location-info {
            padding: 12px;
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 0;
            margin-bottom: 16px;
        }

        .location-info.error {
            background: #ffebee;
            border-color: #ef5350;
        }

        .location-info.success {
            background: #e8f5e9;
            border-color: #4caf50;
        }

        .location-info p {
            margin: 4px 0;
            font-size: 14px;
        }

        .sign-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        }

        .btn-sign {
            padding: 12px 32px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 0;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-sign-in {
            background: #4caf50;
            color: white;
        }

        .btn-sign-in:hover:not(:disabled) {
            background: #45a049;
        }

        .btn-sign-out {
            background: #ff9800;
            color: white;
        }

        .btn-sign-out:hover:not(:disabled) {
            background: #f57c00;
        }

        .btn-sign:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .office-info {
            padding: 12px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0;
            margin-bottom: 16px;
            font-size: 13px;
            color: #6c757d;
        }

        @media (max-width: 640px) {
            .signature-container {
                padding: 16px;
            }

            .page-header {
                margin-bottom: 16px;
            }

            .page-header h1 {
                font-size: 20px;
            }

            .back-button {
                padding: 6px 10px;
                font-size: 13px;
            }

            .attendance-card {
                padding: 16px;
            }

            .status-item {
                min-width: 100%;
            }

            #signatureCanvas {
                max-width: 100%;
            }

            .sign-buttons {
                flex-direction: column;
            }

            .btn-sign {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content">
        <div class="signature-container">
            <div class="page-header">
                <a class="back-button" href="dashboard.php" title="Back to Dashboard">
                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Back
                </a>
                <h1>Sign Attendance</h1>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"
                    style="margin-bottom: 20px; padding: 12px; background: #e8f5e9; border: 1px solid #4caf50; color: #2e7d32; border-radius: 0;">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"
                    style="margin-bottom: 20px; padding: 12px; background: #ffebee; border: 1px solid #ef5350; color: #c62828; border-radius: 0;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($taskError): ?>
                <div class="alert alert-error"
                    style="margin-bottom: 20px; padding: 12px; background: #ffebee; border: 1px solid #ef5350; color: #c62828; border-radius: 0;">
                    <?= htmlspecialchars($taskError) ?>
                </div>
            <?php endif; ?>

            <?php
            // Check if user needs to create a daily task (for sign-out)
            // TEMPORARILY DISABLED until tasks table is created
            $needsDailyTask = false; // !isAdmin() && $hasSignedIn && !$hasSignedOut && function_exists('hasDailyTaskToday') && !hasDailyTaskToday($_SESSION['user_id']);
            ?>

            <?php if ($needsDailyTask): ?>
                <!-- Daily Task Creation Form -->
                <div class="attendance-card" style="margin-bottom: 20px; background: #fff3cd; border: 2px solid #ffc107;">
                    <h2 style="margin-bottom: 12px; font-size: 18px; color: #856404;">ðŸ“ Daily Task Required</h2>
                    <p style="margin-bottom: 16px; color: #856404;">Before you can sign out, please describe what you
                        accomplished today.</p>

                    <form method="POST" style="margin-bottom: 0;">
                        <input type="hidden" name="action" value="create_task">
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label for="task_description" style="display: block; margin-bottom: 6px; font-weight: 600;">Task
                                Description:</label>
                            <textarea name="task_description" id="task_description" rows="4" class="form-control"
                                placeholder="Describe your daily tasks and accomplishments (minimum 10 characters)..."
                                required
                                style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 0; font-size: 14px;"></textarea>
                        </div>
                        <button type="submit" class="btn"
                            style="background: #ffc107; color: #000; border: none; padding: 10px 20px; cursor: pointer; font-weight: 600;">
                            Submit Daily Task
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Today's Status -->
            <div class="attendance-card">
                <h2 style="margin-bottom: 16px; font-size: 18px;">Today's Status</h2>
                <div class="attendance-status">
                    <div class="status-item <?= $hasSignedIn ? 'signed-in' : '' ?>">
                        <div class="status-label">Sign In</div>
                        <div class="status-time">
                            <?= $hasSignedIn ? date('H:i:s', strtotime($lastSignIn)) : 'Not signed' ?>
                        </div>
                    </div>
                    <div class="status-item <?= $hasSignedOut ? 'signed-out' : '' ?>">
                        <div class="status-label">Sign Out</div>
                        <div class="status-time">
                            <?= $hasSignedOut ? date('H:i:s', strtotime($lastSignOut)) : 'Not signed' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Office Information -->
            <div class="office-info">
                <strong>Office Location:</strong> Latitude: <?= OFFICE_LAT ?>, Longitude: <?= OFFICE_LON ?><br>
                <strong>Allowed Radius:</strong> <?= OFFICE_RADIUS_M ?> meters<br>
                <strong>Note:</strong> You must be physically at the office to sign in/out.
            </div>

            <!-- Location Status -->
            <div id="locationInfo" class="location-info" style="display: none;">
                <p id="locationText">Getting your location...</p>
            </div>

            <!-- Signature Form -->
            <div class="attendance-card">
                <form method="POST" id="attendanceForm" onsubmit="return validateAndSubmit(event)">
                    <input type="hidden" name="signature" id="signatureInput">
                    <input type="hidden" name="latitude" id="latitudeInput">
                    <input type="hidden" name="longitude" id="longitudeInput">
                    <input type="hidden" name="sign_type" id="signTypeInput">

                    <div class="signature-section">
                        <h3>Digital Signature</h3>
                        <canvas id="signatureCanvas" width="600" height="200"></canvas>
                        <div class="signature-controls">
                            <button type="button" class="btn-clear-signature" onclick="clearSignature()">Clear
                                Signature</button>
                        </div>
                    </div>

                    <div class="sign-buttons">
                        <button type="button" class="btn-sign btn-sign-in" onclick="submitSign('sign_in')"
                            id="btnSignIn" <?= $hasSignedIn ? 'disabled' : '' ?>>
                            Sign In
                        </button>
                        <button type="button" class="btn-sign btn-sign-out" onclick="submitSign('sign_out')"
                            id="btnSignOut" <?= !$hasSignedIn || $hasSignedOut ? 'disabled' : '' ?>>
                            Sign Out
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Signature canvas setup
        const canvas = document.getElementById('signatureCanvas');
        const ctx = canvas.getContext('2d');
        let isDrawing = false;
        let lastX = 0;
        let lastY = 0;

        // Set canvas size
        function resizeCanvas() {
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width;
            canvas.height = 200;
            ctx.strokeStyle = '#000000';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
        }

        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);

        // Drawing functions
        function startDrawing(e) {
            isDrawing = true;
            const rect = canvas.getBoundingClientRect();
            lastX = e.clientX - rect.left;
            lastY = e.clientY - rect.top;
        }

        function draw(e) {
            if (!isDrawing) return;

            const rect = canvas.getBoundingClientRect();
            const currentX = e.clientX - rect.left;
            const currentY = e.clientY - rect.top;

            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(currentX, currentY);
            ctx.stroke();

            lastX = currentX;
            lastY = currentY;
        }

        function stopDrawing() {
            isDrawing = false;
        }

        // Mouse events
        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseout', stopDrawing);

        // Touch events for mobile
        canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();
            const touch = e.touches[0];
            const mouseEvent = new MouseEvent('mousedown', {
                clientX: touch.clientX,
                clientY: touch.clientY
            });
            canvas.dispatchEvent(mouseEvent);
        });

        canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();
            const touch = e.touches[0];
            const mouseEvent = new MouseEvent('mousemove', {
                clientX: touch.clientX,
                clientY: touch.clientY
            });
            canvas.dispatchEvent(mouseEvent);
        });

        canvas.addEventListener('touchend', (e) => {
            e.preventDefault();
            const mouseEvent = new MouseEvent('mouseup', {});
            canvas.dispatchEvent(mouseEvent);
        });

        function clearSignature() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        // GPS Location
        let currentLatitude = 0;
        let currentLongitude = 0;
        let locationError = null;

        function getLocation() {
            const locationInfo = document.getElementById('locationInfo');
            const locationText = document.getElementById('locationText');

            if (!navigator.geolocation) {
                locationInfo.style.display = 'block';
                locationInfo.className = 'location-info error';
                locationText.textContent = 'Geolocation is not supported by your browser.';
                return;
            }

            locationInfo.style.display = 'block';
            locationText.textContent = 'Getting your location... Please allow location access.';

            navigator.geolocation.getCurrentPosition(
                function (position) {
                    currentLatitude = position.coords.latitude;
                    currentLongitude = position.coords.longitude;

                    // Calculate distance (client-side check)
                    const officeLat = <?= OFFICE_LAT ?>;
                    const officeLon = <?= OFFICE_LON ?>;
                    const radius = <?= OFFICE_RADIUS_M ?>;

                    document.getElementById('latitudeInput').value = currentLatitude;
                    document.getElementById('longitudeInput').value = currentLongitude;

                    if (officeLat === 0 && officeLon === 0) {
                        locationInfo.className = 'location-info success';
                        locationText.innerHTML = `âœ“ Location detected. Office location not configured (Sign-in allowed from anywhere).`;
                    } else {
                        const distance = calculateDistance(currentLatitude, currentLongitude, officeLat, officeLon);
                        if (distance <= radius) {
                            locationInfo.className = 'location-info success';
                            locationText.innerHTML = `âœ“ Location detected. Distance from office: <strong>${distance.toFixed(2)} meters</strong>. You can sign in/out.`;
                        } else {
                            locationInfo.className = 'location-info error';
                            locationText.innerHTML = `âœ— You are ${distance.toFixed(2)} meters away from the office. You must be within ${radius} meters to sign in/out.`;
                        }
                    }
                },
                function (error) {
                    locationError = error;
                    locationInfo.className = 'location-info error';
                    switch (error.code) {
                        case error.PERMISSION_DENIED:
                            locationText.textContent = 'Location access denied. Please enable location services and allow access.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            locationText.textContent = 'Location information unavailable.';
                            break;
                        case error.TIMEOUT:
                            locationText.textContent = 'Location request timed out.';
                            break;
                        default:
                            locationText.textContent = 'An unknown error occurred while getting your location.';
                            break;
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }

        // Calculate distance (Haversine formula)
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371000; // Earth's radius in meters
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return R * c;
        }

        // Get location on page load
        window.addEventListener('DOMContentLoaded', getLocation);

        // Submit sign
        function submitSign(signType) {
            // Validate signature - check if canvas has been drawn on
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = imageData.data;
            let hasDrawing = false;

            // Check if any pixel has non-white/non-transparent content
            for (let i = 0; i < data.length; i += 4) {
                const r = data[i];
                const g = data[i + 1];
                const b = data[i + 2];
                const a = data[i + 3];

                // If pixel is not fully white (255,255,255) or has some opacity
                if (a > 0 && (r < 250 || g < 250 || b < 250)) {
                    hasDrawing = true;
                    break;
                }
            }

            if (!hasDrawing) {
                alert('Please provide your signature before signing.');
                return;
            }

            const signatureData = canvas.toDataURL('image/png');

            // Validate location
            if (currentLatitude === 0 || currentLongitude === 0) {
                alert('Unable to get your location. Please enable location services and try again.');
                getLocation();
                return;
            }

            // Calculate distance
            const officeLat = <?= OFFICE_LAT ?>;
            const officeLon = <?= OFFICE_LON ?>;
            const radius = <?= OFFICE_RADIUS_M ?>;

            // Only validate distance if office location is configured
            if (officeLat !== 0 || officeLon !== 0) {
                const distance = calculateDistance(currentLatitude, currentLongitude, officeLat, officeLon);

                if (distance > radius) {
                    alert(`Sign-in restricted. You must be within the office area (${radius}m radius) to sign. Your current distance: ${distance.toFixed(2)} meters.`);
                    return;
                }
            }

            // Set form values and submit
            document.getElementById('signatureInput').value = signatureData;
            document.getElementById('signTypeInput').value = signType;
            document.getElementById('attendanceForm').submit();
        }

        function validateAndSubmit(e) {
            e.preventDefault();
            return false;
        }
    </script>
</body>

</html>