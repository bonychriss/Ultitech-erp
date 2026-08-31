<?php
require_once 'includes/functions.php';
requireAdmin();

// Current settings
$currentLat = defined('OFFICE_LAT') ? OFFICE_LAT : 0.0;
$currentLon = defined('OFFICE_LON') ? OFFICE_LON : 0.0;
$currentRadius = defined('OFFICE_RADIUS_M') ? OFFICE_RADIUS_M : 500;

// Handle form submission to update radius or disable geofence
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_radius') {
            $newRadius = isset($_POST['radius']) ? (int)$_POST['radius'] : 500;
            $configContent = "<?php\n";
            $configContent .= "// Auto-generated office location settings\n";
            $configContent .= "// Last updated: " . date('Y-m-d H:i:s') . "\n";
            $configContent .= "define('OFFICE_LAT', " . (float)$currentLat . ");\n";
            $configContent .= "define('OFFICE_LON', " . (float)$currentLon . ");\n";
            $configContent .= "define('OFFICE_RADIUS_M', " . $newRadius . ");\n";
            
            $configFile = __DIR__ . '/includes/env.office.php';
            if (@file_put_contents($configFile, $configContent, LOCK_EX)) {
                header('Location: geofence-settings.php?success=radius_updated');
                exit;
            }
        } elseif ($action === 'disable_geofence') {
            // Set coordinates to 0,0 to disable
            $configContent = "<?php\n";
            $configContent .= "// Auto-generated office location settings\n";
            $configContent .= "// Geofence DISABLED - Last updated: " . date('Y-m-d H:i:s') . "\n";
            $configContent .= "define('OFFICE_LAT', 0.0);\n";
            $configContent .= "define('OFFICE_LON', 0.0);\n";
            $configContent .= "define('OFFICE_RADIUS_M', 500);\n";
            
            $configFile = __DIR__ . '/includes/env.office.php';
            if (@file_put_contents($configFile, $configContent, LOCK_EX)) {
                header('Location: geofence-settings.php?success=geofence_disabled');
                exit;
            }
        }
    }
}

$feedback = '';
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'radius_updated') {
        $feedback = 'Geofence radius updated successfully!';
    } elseif ($_GET['success'] === 'geofence_disabled') {
        $feedback = 'Geofence disabled successfully! Employees can now sign in from anywhere.';
    }
}

// Get recent attendance with distances
$stmt = $pdo->query("
    SELECT 
        a.distance_from_office,
        a.latitude,
        a.longitude,
        a.signed_at,
        u.full_name
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    ORDER BY a.signed_at DESC
    LIMIT 10
");
$recentAttendance = $stmt->fetchAll();

$geofenceEnabled = ($currentLat != 0.0 && $currentLon != 0.0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geofence Settings - Ultimate General Trading</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .settings-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
        }
        
        .settings-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        
        .settings-card h2 {
            margin-top: 0;
            color: #111827;
            font-size: 24px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .status-badge.enabled {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-badge.disabled {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }
        
        .info-item {
            background: #f9fafb;
            padding: 16px;
            border-radius: 8px;
        }
        
        .info-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .btn-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563eb;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .recent-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        
        .recent-table th,
        .recent-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .recent-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        
        .distance-ok {
            color: #059669;
            font-weight: 600;
        }
        
        .distance-far {
            color: #dc2626;
            font-weight: 600;
        }
        
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }
    </style>
</head>
<body class="dashboard">
<?php require_once __DIR__ . '/includes/header_admin.php'; ?>

<main class="main-content">
    <div class="settings-container">
        <h1>ðŸŒ Geofence Settings</h1>
        
        <?php if ($feedback): ?>
        <div class="alert alert-success">
            âœ… <?= htmlspecialchars($feedback) ?>
        </div>
        <?php endif; ?>
        
        <div class="settings-card">
            <h2>Current Configuration</h2>
            <p>
                Status: 
                <span class="status-badge <?= $geofenceEnabled ? 'enabled' : 'disabled' ?>">
                    <?= $geofenceEnabled ? 'âœ… Enabled' : 'âŒ Disabled' ?>
                </span>
            </p>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Latitude</div>
                    <div class="info-value"><?= number_format($currentLat, 6) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Longitude</div>
                    <div class="info-value"><?= number_format($currentLon, 6) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Radius</div>
                    <div class="info-value"><?= number_format($currentRadius) ?>m</div>
                </div>
            </div>
        </div>
        
        <?php if ($geofenceEnabled): ?>
        <div class="settings-card">
            <h2>âš™ï¸ Adjust Geofence Radius</h2>
            <p style="color: #6b7280; margin-bottom: 20px;">
                If employees are being rejected even though they're at the office, increase the radius.
            </p>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_radius">
                <div class="form-group">
                    <label for="radius">Geofence Radius (meters)</label>
                    <input type="number" id="radius" name="radius" value="<?= $currentRadius ?>" min="100" max="5000" step="100" required>
                    <small style="color: #6b7280; display: block; margin-top: 4px;">
                        Recommended: 500m for office, 1000m for larger areas, 5000m for remote work
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Update Radius</button>
            </form>
        </div>
        <?php endif; ?>
        
        <div class="settings-card">
            <h2>ðŸ”“ Disable Geofence</h2>
            <p style="color: #6b7280; margin-bottom: 20px;">
                Allow employees to sign in from anywhere (useful for remote work or testing).
            </p>
            
            <?php if ($geofenceEnabled): ?>
            <form method="POST" onsubmit="return confirm('Are you sure you want to disable geofence? Employees will be able to sign in from anywhere.');">
                <input type="hidden" name="action" value="disable_geofence">
                <button type="submit" class="btn btn-danger">Disable Geofence</button>
            </form>
            <?php else: ?>
            <div class="alert alert-warning">
                âš ï¸ Geofence is currently disabled. Go to Admin â†’ Settings to re-enable and set office coordinates.
            </div>
            <?php endif; ?>
        </div>
        
        <div class="settings-card">
            <h2>ðŸ“ Recent Attendance Distances</h2>
            <p style="color: #6b7280;">
                This shows the actual distance employees were from the office when they signed in.
            </p>
            
            <table class="recent-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Date/Time</th>
                        <th>Distance</th>
                        <th>Location</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentAttendance)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #9ca3af; padding: 40px;">
                            No attendance records yet
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentAttendance as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars($record['full_name']) ?></td>
                            <td><?= date('M j, Y H:i', strtotime($record['signed_at'])) ?></td>
                            <td class="<?= $record['distance_from_office'] <= $currentRadius ? 'distance-ok' : 'distance-far' ?>">
                                <?= number_format($record['distance_from_office']) ?>m
                            </td>
                            <td>
                                <small><?= number_format($record['latitude'], 6) ?>, <?= number_format($record['longitude'], 6) ?></small>
                            </td>
                            <td>
                                <?php if ($record['distance_from_office'] <= $currentRadius): ?>
                                    <span style="color: #059669;">âœ… Within range</span>
                                <?php else: ?>
                                    <span style="color: #dc2626;">âŒ Too far</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="settings-card" style="background: #eff6ff; border: 1px solid #3b82f6;">
            <h2 style="color: #1e40af;">ðŸ’¡ Quick Fixes</h2>
            <ul style="color: #1e3a8a; line-height: 1.8;">
                <li><strong>Employees too far?</strong> Increase the radius to 1000m or 2000m</li>
                <li><strong>Testing the system?</strong> Temporarily disable geofence</li>
                <li><strong>Remote work?</strong> Disable geofence or set radius to 5000m</li>
                <li><strong>Wrong office location?</strong> Go to Admin â†’ Settings to update coordinates</li>
            </ul>
        </div>
    </div>
</main>

</body>
</html>

