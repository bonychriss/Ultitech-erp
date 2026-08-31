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
$deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle daily task creation (submitted before sign-out)
    // The old daily task creation logic (action=create_task) is replaced by the new modal flow.

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

        // Validate signature
        // If using stored signature, we fetch it here
        if (isset($_POST['use_stored_signature']) && $_POST['use_stored_signature'] === '1') {
            $storedPath = getUserSignaturePathById($_SESSION['user_id']);
            if ($storedPath && file_exists(__DIR__ . '/../' . $storedPath)) {
                // Read and convert to base64 to match existing schema
                $imgData = file_get_contents(__DIR__ . '/../' . $storedPath);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($imgData);
                $signatureData = 'data:' . $mime . ';base64,' . base64_encode($imgData);
            } else {
                $error = 'No stored signature found in your account. Please upload one in My Account.';
            }
        }

        if (empty($error) && empty($signatureData)) {
            $error = 'Signature is required. Please ensure you have a signature saved in your account.';
        }

        if (empty($error) && ($latitude == 0 || $longitude == 0)) {
            $error = 'Unable to get your location. Please enable location services and try again.';
        }

        if (empty($error)) {
            // Calculate distance from office
            $distance = calculateDistance($latitude, $longitude, OFFICE_LAT, OFFICE_LON);

            // Check IP Address (Office Wi-Fi)
            // Only check if enabled AND IPs are defined
            if (defined('OFFICE_IP_ENABLED') && OFFICE_IP_ENABLED && defined('OFFICE_IPS') && OFFICE_IPS !== '') {
                $allowedIps = array_map('trim', explode(',', OFFICE_IPS));
                $userIp = $_SERVER['REMOTE_ADDR'];

                // Allow exact match
                if (!in_array($userIp, $allowedIps)) {
                    $error = "Access Denied: You must be connected to the Office Wi-Fi to sign in.<br><small>(Your IP: $userIp)</small>";
                }
            }

            // Check Location (GPS)
            // Only check if enabled (default true)
            $isLocationCheckEnabled = (!defined('OFFICE_LOCATION_ENABLED') || OFFICE_LOCATION_ENABLED);

            if (empty($error) && $isLocationCheckEnabled && $distance > OFFICE_RADIUS_M) {
                // If IP check passed (or wasn't enabled), check GPS distance
                $error = "You are too far from the office. Distance: " . number_format($distance, 2) . "m (Max allowed: " . OFFICE_RADIUS_M . "m).";
            }
        }

        // Process Sign In/Out if no errors
        if (empty($error)) {
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
                        $_SERVER['REMOTE_ADDR'],
                        $sysTime->format('Y-m-d H:i:s')
                    ]);

                    // Handle Tasks Workflow
                    if ($signType === 'sign_in') {
                        if (!empty($_POST['new_tasks']) && is_array($_POST['new_tasks'])) {
                            $insertTaskStmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_description, is_completed, task_date, created_at) VALUES (?, ?, 0, ?, ?)");
                            foreach ($_POST['new_tasks'] as $taskDesc) {
                                $taskDesc = trim($taskDesc);
                                if (!empty($taskDesc)) {
                                    $insertTaskStmt->execute([
                                        $_SESSION['user_id'],
                                        $taskDesc,
                                        $sysTime->format('Y-m-d'),
                                        $sysTime->format('Y-m-d H:i:s')
                                    ]);
                                }
                            }
                        }
                    } elseif ($signType === 'sign_out') {
                        if (!empty($_POST['completed_task_ids']) && is_array($_POST['completed_task_ids'])) {
                            $updateTaskStmt = $pdo->prepare("UPDATE user_tasks SET is_completed = 1 WHERE id = ? AND user_id = ?");
                            foreach ($_POST['completed_task_ids'] as $taskId) {
                                $updateTaskStmt->execute([(int)$taskId, $_SESSION['user_id']]);
                            }
                        }
                    }

                    // Redirect to Overtime Report if signing out after 17:00 (5 PM)
                    // We check the signed_at time we just inserted
                    if ($signType === 'sign_out' && !isAdmin()) {
                        $signedAtTime = $sysTime->format('H:i:s');
                        if ($signedAtTime > '17:00:00') {
                            header('Location: overtime.php?welcome=1');
                            exit;
                        }
                    }

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

// Fetch pending tasks
$pendingTasksStmt = $pdo->prepare("SELECT id, task_description, task_date FROM user_tasks WHERE user_id = ? AND is_completed = 0 ORDER BY task_date ASC");
$pendingTasksStmt->execute([$_SESSION['user_id']]);
$pendingTasks = $pendingTasksStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Fetch stored signature
$storedSignaturePath = getUserSignaturePathById($_SESSION['user_id']);
$storedSignatureUrl = $storedSignaturePath ? '../' . $storedSignaturePath : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Attendance - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        body {
            margin: 0;
            padding: 0;
        }

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

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e9ecef;
        }
        .modal-body {
            margin-bottom: 24px;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .task-list {
            list-style: none;
            padding: 0;
            margin: 0 0 16px 0;
        }
        .task-item {
            padding: 12px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            margin-bottom: 8px;
            border-radius: 4px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .task-item input[type="checkbox"] {
            margin-top: 4px;
        }
        .task-input-group {
            margin-bottom: 12px;
        }
        .task-input-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-primary {
            background: #4caf50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
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

            <!-- Task requirement is now handled by modals -->f; ?>



            <!-- Office Information -->
            <div class="office-info">
                <strong>Office Location:</strong> Latitude: <?= OFFICE_LAT ?>, Longitude: <?= OFFICE_LON ?><br>
                <strong>Allowed Radius:</strong> <?= OFFICE_RADIUS_M ?> meters
                <?php if (!defined('OFFICE_LOCATION_ENABLED') || OFFICE_LOCATION_ENABLED): ?>
                    <span style="color: green; font-weight: bold;">(Active)</span>
                <?php else: ?>
                    <span style="color: #666;">(Disabled)</span>
                <?php endif; ?><br>

                <?php if (defined('OFFICE_IP_ENABLED') && OFFICE_IP_ENABLED && defined('OFFICE_IPS') && OFFICE_IPS !== ''): ?>
                    <strong style="color: #d32f2f;">Restriction:</strong> Must be connected to Office Wi-Fi.<br>
                <?php endif; ?>

                <strong>Note:</strong> You must be physically at the office to sign in/out.
            </div>

            <!-- Location Status -->
            <div id="locationInfo" class="location-info" style="display: none;">
                <p id="locationText">Getting your location...</p>
            </div>

            <div class="sign-buttons">
                <!-- We intercept submission via JS to show modals first -->
                <button type="button" class="btn-sign btn-sign-in" <?= (!$hasSignedIn) ? '' : 'disabled' ?> onclick="openSignInModal()">
                    Sign In
                </button>

                <button type="button" class="btn-sign btn-sign-out" <?= ($hasSignedIn && !$hasSignedOut) ? '' : 'disabled' ?> onclick="openSignOutModal()">
                    Sign Out
                </button>
            </div>

            <!-- Sign In Form (Hidden) -->
            <form method="POST" id="signInForm" style="display:none;">
                <input type="hidden" name="sign_type" value="sign_in">
                <input type="hidden" name="latitude" id="lat_in">
                <input type="hidden" name="longitude" id="lon_in">
                <input type="hidden" name="signature" id="sig_in">
                <input type="hidden" name="use_stored_signature" value="1">
                <div id="new_tasks_container"></div>
            </form>

            <!-- Sign Out Form (Hidden) -->
            <form method="POST" id="signOutForm" style="display:none;">
                <input type="hidden" name="sign_type" value="sign_out">
                <input type="hidden" name="latitude" id="lat_out">
                <input type="hidden" name="longitude" id="lon_out">
                <input type="hidden" name="signature" id="sig_out">
                <input type="hidden" name="use_stored_signature" value="1">
                <div id="completed_tasks_container"></div>
            </form>

            <!-- Sign In Modal -->
            <div id="signInModal" class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">Plan Your Day</div>
                    <div class="modal-body">
                        <?php if (count($pendingTasks) > 0): ?>
                            <p style="margin-top:0;"><strong>Carried Over Tasks:</strong></p>
                            <ul class="task-list">
                                <?php foreach ($pendingTasks as $task): ?>
                                    <li class="task-item">
                                        <span style="color:#666;">â—</span>
                                        <div><?= htmlspecialchars($task['task_description']) ?> <br><small style="color:#888;">From: <?= htmlspecialchars($task['task_date']) ?></small></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <p><strong>Add New Tasks For Today:</strong></p>
                        <div id="taskListInputs">
                            <div class="task-input-group">
                                <input type="text" class="new-task-input" placeholder="e.g. Prepare monthly report...">
                            </div>
                        </div>
                        <button type="button" onclick="addMoreTaskInput()" style="background:none; border:none; color:#007bff; cursor:pointer; padding:0;">+ Add another task</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('signInModal')">Cancel</button>
                        <button type="button" class="btn-primary" onclick="submitSignIn()">Confirm & Sign In</button>
                    </div>
                </div>
            </div>

            <!-- Sign Out Modal -->
            <div id="signOutModal" class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">Review Your Day</div>
                    <div class="modal-body">
                        <p style="margin-top:0;">Check the tasks you completed today. Unchecked tasks will carry over to tomorrow.</p>
                        <?php if (count($pendingTasks) > 0): ?>
                            <ul class="task-list">
                                <?php foreach ($pendingTasks as $task): ?>
                                    <li class="task-item">
                                        <input type="checkbox" class="completed-task-checkbox" value="<?= $task['id'] ?>" id="task_<?= $task['id'] ?>">
                                        <label for="task_<?= $task['id'] ?>"><?= htmlspecialchars($task['task_description']) ?></label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p style="color:#666; font-style:italic;">No pending tasks.</p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('signOutModal')">Cancel</button>
                        <button type="button" class="btn-primary" onclick="submitSignOut()" style="background:#ff9800;">Confirm & Sign Out</button>
                    </div>
                </div>
            </div>

            <!-- Attendance Charts -->
            <div class="attendance-card" style="margin-top: 24px;">
                <h2 style="margin-bottom: 16px; font-size: 18px;">Team Status (<?= date('F Y') ?>)</h2>
                <canvas id="teamAttendanceChart" width="400" height="200"></canvas>
            </div>
        </div>
    </main>

    <?php


    // Calculate working days elapsed (excluding weekends)
    $workingDaysElapsed = 0;
    $currentDay = (int) date('d');
    $currentYear = (int) date('Y');
    $currentMonth = (int) date('m');

    for ($d = 1; $d < $currentDay; $d++) {
        $timestamp = mktime(0, 0, 0, $currentMonth, $d, $currentYear);
        $weekday = date('N', $timestamp); // 1 (Mon) to 7 (Sun)
        // Assume working days are Mon (1) to Fri (5)
        // Modify this if Saturdays are working days (e.g., $weekday <= 6)
        if ($weekday <= 5) {
            $workingDaysElapsed++;
        }
    }

    $stmt = $pdo->prepare("
        SELECT 
            u.full_name,
            COUNT(DISTINCT CASE WHEN a.sign_type = 'sign_in' THEN DATE(a.signed_at) END) as days_present,
            SUM(CASE WHEN a.sign_type = 'sign_in' AND TIME(a.signed_at) > '08:30:00' THEN 1 ELSE 0 END) as count_late,
            SUM(CASE WHEN a.sign_type = 'sign_in' AND TIME(a.signed_at) <= '08:30:00' THEN 1 ELSE 0 END) as count_early,
            SUM(CASE WHEN a.sign_type = 'sign_out' AND TIME(a.signed_at) > '17:00:00' THEN 1 ELSE 0 END) as count_overtime
        FROM users u
        LEFT JOIN attendance a ON u.id = a.user_id 
            AND DATE(a.signed_at) BETWEEN ? AND ?
        WHERE u.is_active = 1 AND u.role != 'admin'
        GROUP BY u.id
        ORDER BY u.full_name
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $teamStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $teamLabels = [];
    $dataEarly = [];
    $dataLate = [];
    $dataOvertime = [];
    $dataAbsent = [];

    foreach ($teamStats as $row) {
        $teamLabels[] = $row['full_name'];
        $dataEarly[] = (int) $row['count_early'];
        $dataLate[] = (int) $row['count_late'];
        $dataOvertime[] = (int) $row['count_overtime'];
        $dataAbsent[] = max(0, $workingDaysElapsed - (int) $row['days_present']);
    }
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>


        // Team Chart
        const ctxTeam = document.getElementById('teamAttendanceChart').getContext('2d');
        new Chart(ctxTeam, {
            type: 'bar',
            data: {
                labels: <?= json_encode($teamLabels) ?>,
                datasets: [
                    { label: 'Early', data: <?= json_encode($dataEarly) ?>, backgroundColor: '#17a2b8' },
                    { label: 'Late', data: <?= json_encode($dataLate) ?>, backgroundColor: '#ffc107' },
                    { label: 'Overtime', data: <?= json_encode($dataOvertime) ?>, backgroundColor: '#007bff' },
                    { label: 'Absent', data: <?= json_encode($dataAbsent) ?>, backgroundColor: '#dc3545' }
                ]
            },
            options: {
                responsive: true,
                // indexAxis: 'x', // Default is x (vertical bars)
                scales: {
                    x: { stacked: false },
                    y: { beginAtZero: true, title: { display: true, text: 'Count (Days)' } }
                }
            }
        });

        function openSignInModal() {
            document.getElementById('signInModal').classList.add('active');
        }

        function openSignOutModal() {
            document.getElementById('signOutModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function addMoreTaskInput() {
            const container = document.getElementById('taskListInputs');
            const div = document.createElement('div');
            div.className = 'task-input-group';
            div.innerHTML = '<input type="text" class="new-task-input" placeholder="New task...">';
            container.appendChild(div);
        }

        function submitSignIn() {
            const inputs = document.querySelectorAll('.new-task-input');
            let hasValidTask = false;
            let container = document.getElementById('new_tasks_container');
            container.innerHTML = ''; // clear

            inputs.forEach(input => {
                if (input.value.trim() !== '') {
                    hasValidTask = true;
                    let hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'new_tasks[]';
                    hidden.value = input.value.trim();
                    container.appendChild(hidden);
                }
            });

            // If no tasks provided and no carry over tasks, require at least one
            const hasPendingTasks = <?= count($pendingTasks) > 0 ? 'true' : 'false' ?>;
            if (!hasValidTask && !hasPendingTasks) {
                alert('Please enter at least one task for today.');
                return;
            }

            closeModal('signInModal');
            handleSign(document.getElementById('signInForm'), 'sign_in');
        }

        function submitSignOut() {
            const checkboxes = document.querySelectorAll('.completed-task-checkbox:checked');
            let container = document.getElementById('completed_tasks_container');
            container.innerHTML = ''; // clear

            checkboxes.forEach(cb => {
                let hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'completed_task_ids[]';
                hidden.value = cb.value;
                container.appendChild(hidden);
            });

            closeModal('signOutModal');
            handleSign(document.getElementById('signOutForm'), 'sign_out');
        }

        function handleSign(form, type) {
            // Triggered programmatically from modal confirmation
            const originalBtn = type === 'sign_in' ? document.querySelector('.btn-sign-in') : document.querySelector('.btn-sign-out');
            const originalText = originalBtn.innerText;
            originalBtn.disabled = true;
            originalBtn.innerText = 'Processing...';

            // Get location
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function (position) {
                        form.querySelector('input[name="latitude"]').value = position.coords.latitude;
                        form.querySelector('input[name="longitude"]').value = position.coords.longitude;
                        // Submit
                        form.submit();
                    },
                    function (error) {
                        alert("Error getting location: " + error.message);
                        originalBtn.disabled = false;
                        originalBtn.innerText = originalText;
                    }
                );
            } else {
                alert("Geolocation is not supported by this browser.");
                originalBtn.disabled = false;
                originalBtn.innerText = originalText;
            }
        }
    </script>
</body>

</html>