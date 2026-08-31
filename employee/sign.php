    <?php
    require_once '../includes/functions.php';
    forceHttps();
    requireLogin();

    $feedback = '';
    $attendanceLocked = isAttendanceLocked($_SESSION['user_id'] ?? null);
    // Fetch last attendance and today's sign-in status to drive UI states
    $last = getLastAttendanceForUser($_SESSION['user_id']);
    $lastType = $last['sign_type'] ?? null;
    $lastAt = $last['signed_at'] ?? null;
    $lastDist = isset($last['distance_from_office']) ? (int)$last['distance_from_office'] : null;
    $hasSignedInToday = false;
    try {
    global $pdo;
    $q = $pdo->prepare("SELECT COUNT(*) AS c FROM attendance WHERE user_id = ? AND sign_type = 'sign_in' AND DATE(signed_at) = CURDATE()");
    $q->execute([ (int)$_SESSION['user_id'] ]);
    $hasSignedInToday = ((int)($q->fetch()['c'] ?? 0)) > 0;
    } catch (Exception $e) { /* ignore */ }

    $canSignIn = ($lastType !== 'sign_in');
    $canSignOut = ($lastType !== 'sign_out') && $hasSignedInToday;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $type = $action === 'sign_out' ? 'sign_out' : 'sign_in';
        $lat = isset($_POST['lat']) ? (float)$_POST['lat'] : 0.0;
        $lon = isset($_POST['lon']) ? (float)$_POST['lon'] : 0.0;
        $dist = isset($_POST['dist']) ? (float)$_POST['dist'] : 0.0;
        $device = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $result = recordAttendanceWithAccountSignature($_SESSION['user_id'], $type, $lat, $lon, $dist, $device, $ip);
        if (!empty($result['ok'])) {
            // Redirect to daily task if signing in and haven't done it yet
            if ($type === 'sign_in' && !hasDailyTaskToday($_SESSION['user_id'])) {
                header("Location: daily-task.php");
                exit;
            }
            
            // Redirect to self to prevent resubmission and refresh state
            header("Location: sign.php?success=" . urlencode(($type === 'sign_in' ? 'Signed in' : 'Signed out') . ' successfully.'));
            exit;
        } else {
            $feedback = 'Error: ' . ($result['error'] ?? 'Unable to record attendance');
        }
    }

    // Fetch recent history (grouped by date)
    $recentHistory = [];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(signed_at) as date,
                MIN(CASE WHEN sign_type = 'sign_in' THEN signed_at END) as sign_in_time,
                MAX(CASE WHEN sign_type = 'sign_out' THEN signed_at END) as sign_out_time,
                MAX(distance_from_office) as distance_from_office
            FROM attendance 
            WHERE user_id = ? 
            GROUP BY DATE(signed_at)
            ORDER BY date DESC 
            LIMIT 10
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $recentHistory = $stmt->fetchAll();
    } catch (Exception $e) { /* ignore */ }

    if (isset($_GET['success'])) {
        $feedback = $_GET['success'];
    }

    // Prepare some convenience URLs
    $accountUrl = 'account.php';
    $loginUrl = '../login.php'; 
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Attendance - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>" />
    <style>
        .attendance-dashboard {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .attendance-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .digital-clock {
            font-family: 'Courier New', Courier, monospace;
            font-size: 3rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 5px;
            letter-spacing: 2px;
        }
        .current-date {
            color: #6b7280;
            font-size: 1.1rem;
            font-weight: 500;
        }
        .attendance-status-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
            border: 1px solid #e5e7eb;
            position: relative;
            overflow: hidden;
        }
        .status-indicator {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .status-indicator.signed-in {
            background-color: #dcfce7;
            color: #166534;
        }
        .status-indicator.signed-out {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .status-indicator.neutral {
            background-color: #f3f4f6;
            color: #4b5563;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
            background-color: currentColor;
        }
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .btn-attendance {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 600;
            font-size: 1.1rem;
            position: relative;
            overflow: hidden;
        }
        .btn-attendance svg {
            width: 32px;
            height: 32px;
            margin-bottom: 12px;
        }
        .btn-sign-in {
            background-color: #10b981;
            color: white;
            box-shadow: 0 4px 14px 0 rgba(16, 185, 129, 0.39);
        }
        .btn-sign-in:hover:not(:disabled) {
            background-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.23);
        }
        .btn-sign-out {
            background-color: #ef4444;
            color: white;
            box-shadow: 0 4px 14px 0 rgba(239, 68, 68, 0.39);
        }
        .btn-sign-out:hover:not(:disabled) {
            background-color: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.23);
        }
        .btn-attendance:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
            background-color: #e5e7eb;
            color: #9ca3af;
        }
        .history-section {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            padding: 24px;
        }
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .history-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #111827;
        }
        .history-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .history-item {
            display: flex;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .history-item:last-child {
            border-bottom: none;
        }
        .history-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 16px;
        }
        .history-icon.in {
            background-color: #dcfce7;
            color: #166534;
        }
        .history-icon.out {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .history-details {
            flex: 1;
        }
        .history-action {
            font-weight: 600;
            color: #1f2937;
            display: block;
        }
        .history-time {
            color: #6b7280;
            font-size: 0.85rem;
        }
        .history-meta {
            text-align: right;
            font-size: 0.85rem;
            color: #6b7280;
        }
        .distance-tag {
            display: inline-block;
            padding: 2px 6px;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-top: 4px;
        }
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 10;
            backdrop-filter: blur(2px);
            border-radius: 16px;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e5e7eb;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 10px;
        }
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        @media (max-width: 640px) {
            .action-buttons {
                grid-template-columns: 1fr;
            }
            .digital-clock {
                font-size: 2.5rem;
            }
        }
    </style>
    </head>
    <body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content">
    <div class="attendance-dashboard">
        


        <div style="display: flex; gap: 12px; justify-content: center; margin-bottom: 20px; flex-wrap: wrap;">
            <a href="attendance-analytics.php" style="padding: 10px 20px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px;">
                ðŸ“Š Analytics
            </a>
        </div>

        <div class="attendance-status-card">
            <div id="loadingOverlay" class="loading-overlay" style="display: none;">
                <div class="spinner"></div>
                <div>Processing location...</div>
            </div>
            
            <div style="text-align:center; margin-bottom:15px;">
                <button id="btnRetryLocation" type="button" onclick="retryLocation()" style="display:none; background-color:#3b82f6; color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600;">
                    Retry Location
                </button>
            </div>

            <?php if ($lastType === 'sign_in'): ?>
                <div class="status-indicator signed-in">
                    <div class="status-dot"></div> Signed In
                </div>
                <p style="color: #6b7280; margin-bottom: 20px;">You are currently clocked in. Don't forget to sign out when you leave.</p>
            <?php elseif ($lastType === 'sign_out'): ?>
                <div class="status-indicator signed-out">
                    <div class="status-dot"></div> Signed Out
                </div>
                <p style="color: #6b7280; margin-bottom: 20px;">You are currently clocked out. Have a great day!</p>
            <?php else: ?>
                <div class="status-indicator neutral">
                    <div class="status-dot"></div> No Status
                </div>
                <p style="color: #6b7280; margin-bottom: 20px;">Welcome! Please sign in to start your day.</p>
            <?php endif; ?>

            <!-- Office Information -->
            <div class="office-info" style="margin-bottom: 20px; font-size: 0.9rem; color: #4b5563; background: #f9fafb; padding: 12px; border-radius: 6px; border: 1px solid #e5e7eb;">
                <strong>Office Location:</strong> <?= defined('OFFICE_LAT') ? OFFICE_LAT : 'Not Set' ?>, <?= defined('OFFICE_LON') ? OFFICE_LON : 'Not Set' ?><br>
                <strong>Allowed Radius:</strong> <?= defined('OFFICE_RADIUS_M') ? OFFICE_RADIUS_M : 500 ?> meters 
                <?php if (!defined('OFFICE_LOCATION_ENABLED') || OFFICE_LOCATION_ENABLED): ?>
                    <span style="color: green; font-weight: bold;">(Active)</span>
                <?php else: ?>
                    <span style="color: #666;">(Disabled)</span>
                <?php endif; ?><br>
                
                <?php if (defined('OFFICE_IP_ENABLED') && OFFICE_IP_ENABLED && defined('OFFICE_IPS') && OFFICE_IPS !== ''): ?>
                    <strong style="color: #d32f2f;">Restriction:</strong> Must be connected to Office Wi-Fi.<br>
                <?php endif; ?>
                
                <strong>Note:</strong> You must be physically at the office (or on Office Wi-Fi) to sign in.
            </div>

            <div class="action-buttons">
                <form method="post" onsubmit="return prepareGeo(this)" style="display: contents;">
                    <input type="hidden" name="action" value="sign_in" />
                    <input type="hidden" name="lat" value="0" />
                    <input type="hidden" name="lon" value="0" />
                    <input type="hidden" name="dist" value="0" />
                    <button class="btn-attendance btn-sign-in" type="submit" id="btnSignIn" <?= $canSignIn ? '' : 'disabled' ?>>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                        Sign In
                    </button>
                </form>

                <form method="post" onsubmit="return prepareGeo(this)" style="display: contents;">
                    <input type="hidden" name="action" value="sign_out" />
                    <input type="hidden" name="lat" value="0" />
                    <input type="hidden" name="lon" value="0" />
                    <input type="hidden" name="dist" value="0" />
                    <button class="btn-attendance btn-sign-out" type="submit" id="btnSignOut" <?= $canSignOut ? '' : 'disabled' ?>>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                        Sign Out
                    </button>
                </form>
            </div>
        </div>

        <div class="history-section">
            <div class="history-header">
                <div class="history-title">Recent Activity</div>
            </div>
            
            <?php if (empty($recentHistory)): ?>
                <p style="color: #9ca3af; text-align: center; padding: 20px;">No recent activity.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                        <thead>
                            <tr style="border-bottom: 2px solid #e5e7eb; text-align: left;">
                                <th style="padding: 12px 8px; color: #374151;">Date</th>
                                <th style="padding: 12px 8px; color: #374151;">Sign In</th>
                                <th style="padding: 12px 8px; color: #374151;">Sign Out</th>
                                <th style="padding: 12px 8px; color: #374151;">Status</th>
                                <th style="padding: 12px 8px; color: #374151;">Distance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentHistory as $rec): 
                                $dateObj = new DateTime($rec['date']);
                                $formattedDate = $dateObj->format('M j, Y');
                                $dayName = $dateObj->format('D');
                                
                                $signIn = $rec['sign_in_time'] ? date('H:i', strtotime($rec['sign_in_time'])) : 'â€”';
                                $signOut = $rec['sign_out_time'] ? date('H:i', strtotime($rec['sign_out_time'])) : 'â€”';
                                
                                // Calculate Status
                                $status = 'â€”';
                                $statusColor = '#6b7280'; // gray
                                $bgColor = '#f3f4f6';
                                
                                if ($rec['sign_in_time']) {
                                    $signInTime = date('H:i:s', strtotime($rec['sign_in_time']));
                                    if ($signInTime > WORK_START_TIME) {
                                        $status = 'Late';
                                        $statusColor = '#991b1b'; // red
                                        $bgColor = '#fee2e2';
                                    } else {
                                        $status = 'On Time';
                                        $statusColor = '#065f46'; // green
                                        $bgColor = '#d1fae5';
                                    }
                                }
                            ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 12px 8px;">
                                        <div style="font-weight: 600; color: #111827;"><?= $formattedDate ?></div>
                                        <div style="font-size: 0.8rem; color: #6b7280;"><?= $dayName ?></div>
                                    </td>
                                    <td style="padding: 12px 8px; color: #111827;"><?= $signIn ?></td>
                                    <td style="padding: 12px 8px; color: #111827;"><?= $signOut ?></td>
                                    <td style="padding: 12px 8px;">
                                        <span style="background-color: <?= $bgColor ?>; color: <?= $statusColor ?>; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
                                            <?= $status ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px 8px; color: #6b7280;">
                                        <?= isset($rec['distance_from_office']) ? number_format($rec['distance_from_office']) . 'm' : 'â€”' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
    </main>

    <script>
    // Clock - Synced with Server (EAT)
    <?php 
    $serverTime = getSystemTime(); 
    // Pass initial server millisecond timestamp to JS
    ?>
    const initialServerTime = <?= $serverTime->getTimestamp() * 1000 ?>;
    const clientStartTime = Date.now();

    function updateClock() {
        const nowClient = Date.now();
        const elapsed = nowClient - clientStartTime;
        const currentServerTime = new Date(initialServerTime + elapsed);
        
        // Force East Africa Time display
        document.getElementById('digitalClock').textContent = currentServerTime.toLocaleTimeString('en-US', { 
            hour12: false, 
            timeZone: 'Africa/Dar_es_Salaam' 
        });
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Office coordinates
    const OFFICE_LAT = <?php echo defined('OFFICE_LAT') ? json_encode((float)OFFICE_LAT) : 'null'; ?>;
    const OFFICE_LON = <?php echo defined('OFFICE_LON') ? json_encode((float)OFFICE_LON) : 'null'; ?>;
    
    function haversine(lat1, lon1, lat2, lon2){
        function toRad(d){ return d * Math.PI/180; }
        const R=6371000; 
        const dLat=toRad(lat2-lat1), dLon=toRad(lon2-lon1);
        const a=Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)**2;
        return 2*R*Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    function prepareGeo(form){
        if (!navigator.geolocation) {
            alert("Geolocation is not supported by your browser. You may not be able to sign in.");
            return true;
        }
        
        const overlay = document.getElementById('loadingOverlay');
        const retryBtn = document.getElementById('btnRetryLocation');
        
        // Reset UI
        overlay.style.display = 'flex';
        if(retryBtn) retryBtn.style.display = 'none';
        
        navigator.geolocation.getCurrentPosition(function(pos){
            try {
            const lat=pos.coords.latitude, lon=pos.coords.longitude;
            form.querySelector('input[name="lat"]').value = String(lat);
            form.querySelector('input[name="lon"]').value = String(lon);
            let dist = 0;
            if (typeof OFFICE_LAT === 'number' && typeof OFFICE_LON === 'number') {
                dist = haversine(OFFICE_LAT, OFFICE_LON, lat, lon);
            }
            form.querySelector('input[name="dist"]').value = String(dist);
            } catch(e) {}
            form.submit();
        }, function(err){ 
            overlay.style.display = 'none';
            let msg = "Unable to get your location.";
            let showRetry = false;

            if (err.code === 1) {
                msg = "Location access denied.\n\nPLEASE FIX: Click the lock icon ðŸ”’ or settings icon in your browser address bar, find 'Location', and set it to 'Allow' or 'Ask'. Then click 'Retry Location'.";
                showRetry = true;
            }
            else if (err.code === 2) msg = "Location unavailable. Please check your GPS.";
            else if (err.code === 3) {
                msg = "Location request timed out. Please try again.";
                showRetry = true;
            }
            
            alert(msg);
            if(showRetry && retryBtn) {
                retryBtn.style.display = 'inline-block';
            }
        }, { enableHighAccuracy:true, timeout:10000, maximumAge:0 });
        
        return false; // Prevent default form submit
    }

    function retryLocation() {
        // Find the sign-in form and try to submit it again, which triggers prepareGeo
        const signInForm = document.querySelector('form input[value="sign_in"]').form;
        if(signInForm) prepareGeo(signInForm);
    }
    </script>
    </body>
    </html>

