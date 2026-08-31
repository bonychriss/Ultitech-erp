<?php
require_once '../includes/functions.php';
requireAdmin();

$feedback = '';

// Handle IP Settings Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ip_settings'])) {
    $allowedIps = isset($_POST['allowed_ips']) ? trim($_POST['allowed_ips']) : '';
    $enableIp = isset($_POST['enable_ip']) ? (int) $_POST['enable_ip'] : 0;

    // PRESERVE existing location settings
    $currentLat = defined('OFFICE_LAT') ? OFFICE_LAT : 0.0;
    $currentLon = defined('OFFICE_LON') ? OFFICE_LON : 0.0;
    $currentRadius = defined('OFFICE_RADIUS_M') ? OFFICE_RADIUS_M : 500;
    $locationEnabled = defined('OFFICE_LOCATION_ENABLED') ? OFFICE_LOCATION_ENABLED : 1;

    // Write to simple config override file
    $configContent = "<?php\n";
    $configContent .= "// Auto-generated office location settings\n";
    $configContent .= "// Last updated: " . date('Y-m-d H:i:s') . "\n";
    $configContent .= "define('OFFICE_LAT', " . (float) $currentLat . ");\n";
    $configContent .= "define('OFFICE_LON', " . (float) $currentLon . ");\n";
    $configContent .= "define('OFFICE_RADIUS_M', " . (int) $currentRadius . ");\n";
    $configContent .= "define('OFFICE_LOCATION_ENABLED', " . $locationEnabled . ");\n";
    $configContent .= "define('OFFICE_IPS', '" . addslashes($allowedIps) . "');\n";
    $configContent .= "define('OFFICE_IP_ENABLED', " . $enableIp . ");\n";

    $envFile = __DIR__ . '/../includes/env.office.php';
    if (file_put_contents($envFile, $configContent)) {
        $_SESSION['flash_message'] = 'Network settings saved successfully!';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_message'] = 'Error: Could not write to configuration file.';
        $_SESSION['flash_type'] = 'error';
    }
    header("Location: network-settings.php");
    exit();
}

// Read current settings
$currentIps = defined('OFFICE_IPS') ? OFFICE_IPS : '';
$ipEnabled = defined('OFFICE_IP_ENABLED') ? OFFICE_IP_ENABLED : 0;
$adminIp = $_SERVER['REMOTE_ADDR'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Settings - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-violet: #8b5cf6;
            --brand-violet-dark: #6d28d9;
            --brand-emerald: #10b981;
            --bg-gray: #f9fafb;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-gray);
        }

        .settings-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }

        /* Hero Header Section */
        .settings-header-banner {
            background: linear-gradient(135deg, var(--brand-violet-dark) 0%, var(--brand-violet) 100%);
            border-radius: 16px 16px 0 0;
            padding: 40px;
            color: white;
            display: flex;
            align-items: center;
            gap: 24px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .header-icon-circle {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .header-text h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.025em;
        }

        .header-text p {
            margin: 8px 0 0;
            opacity: 0.9;
            font-size: 16px;
        }

        /* Main Content Card */
        .settings-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-top: none;
            border-radius: 0 0 16px 16px;
            padding: 40px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .section-intro {
            display: flex;
            gap: 20px;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid #f3f4f6;
        }

        .intro-illustration {
            flex-shrink: 0;
            width: 120px;
            height: 120px;
            background: #f5f3ff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand-violet);
            font-size: 48px;
        }

        .intro-text h3 {
            margin: 0 0 8px;
            color: #111827;
            font-size: 18px;
        }

        .intro-text p {
            margin: 0;
            color: #6b7280;
            line-height: 1.6;
            font-size: 15px;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .input-wrapper {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.2s;
            background-color: #f9fafb;
            color: #111827;
            position: relative;
            z-index: 1;
        }

        .form-control:focus {
            border-color: var(--brand-violet);
            outline: none;
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1);
        }

        .help-text {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #6b7280;
            margin-top: 10px;
        }

        /* Toggle Switch */
        .restriction-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 24px;
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 32px;
            transition: border-color 0.2s;
        }

        .restriction-box.active {
            border-color: var(--brand-violet-dark);
            background: #f5f3ff;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #d1d5db;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        input:checked + .slider {
            background-color: var(--brand-violet);
        }

        input:checked + .slider:before {
            transform: translateX(24px);
        }

        /* Actions Bar */
        .actions-bar {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn-back {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6b7280;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: color 0.2s;
        }

        .btn-back:hover {
            color: #111827;
        }

        .btn-save {
            background-color: var(--brand-violet);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 6px -1px rgba(139, 92, 246, 0.3);
        }

        .btn-save:hover {
            background-color: var(--brand-violet-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 10px -1px rgba(139, 92, 246, 0.4);
        }

        .btn-secondary-outline {
            background: white;
            color: #374151;
            border: 2px solid #e5e7eb;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.2s;
            cursor: pointer;
        }

        .btn-secondary-outline:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        @media (max-width: 640px) {
            .settings-header-banner {
                padding: 24px;
                flex-direction: column;
                text-align: center;
            }
            .settings-card {
                padding: 24px;
            }
            .section-intro {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
        }
    </style>
</head>

<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

    <main class="main-content">
        <div class="settings-container">
            
            <header class="settings-header-banner">
                <div class="header-icon-circle">
                    <i class="fas fa-network-wired"></i>
                </div>
                <div class="header-text">
                    <h1>Network Security</h1>
                    <p>Restrict system access to verified office networks.</p>
                </div>
            </header>

            <div class="settings-card">
                <div class="section-intro">
                    <div class="intro-illustration">
                        <i class="fas fa-microchip"></i>
                    </div>
                    <div class="intro-text">
                        <h3>IP Access Control</h3>
                        <p>Protect your attendance records by ensuring staff only check in from authorized office IP addresses. This prevents remote clock-ins and enhances record integrity.</p>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="update_ip_settings" value="1">

                    <div class="restriction-box" id="restrictionBox">
                        <div>
                            <h4 style="margin: 0 0 4px; font-size: 16px; color: #111827;">Enable IP Restriction</h4>
                            <p style="margin: 0; font-size: 13px; color: #6b7280;">Enforce network validation for all attendance logs.</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="enable_ip_toggle" name="enable_ip" value="1" <?= $ipEnabled ? 'checked' : '' ?> onchange="updateBoxStyle()">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="allowed_ips">Authorized Public IPs</label>
                        <div class="input-wrapper">
                            <input type="text" name="allowed_ips" id="allowed_ips" class="form-control"
                                placeholder="e.g. 192.168.1.1, 10.2.3.4" value="<?= htmlspecialchars($currentIps) ?>">
                        </div>
                        <div class="help-text">
                            <i class="fas fa-circle-info"></i>
                            <span>Enter the public IP addresses of your office router. Comma-separated for multiple locations.</span>
                        </div>
                    </div>

                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 20px; display: flex; align-items: center; justify-content: space-between; margin-top: 32px;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 40px; height: 40px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--brand-emerald);">
                                <i class="fas fa-location-dot"></i>
                            </div>
                            <div>
                                <p style="margin: 0; font-size: 12px; color: #166534; font-weight: 600; text-transform: uppercase;">Your Current IP</p>
                                <p style="margin: 0; font-size: 18px; font-weight: 700; color: #064e3b;"><?= $adminIp ?></p>
                            </div>
                        </div>
                        <button type="button" class="btn-secondary-outline" onclick="useMyIP()">
                            <i class="fas fa-copy"></i>
                            Use Current IP
                        </button>
                    </div>

                    <div class="actions-bar">
                        <a href="settings.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i>
                            Back to Settings Hub
                        </a>
                        <button type="submit" class="btn-save">
                            <i class="fas fa-save"></i>
                            Save Network Rules
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        function useMyIP() {
            document.getElementById('allowed_ips').value = '<?= $adminIp ?>';
        }

        function updateBoxStyle() {
            const chk = document.getElementById('enable_ip_toggle');
            const box = document.getElementById('restrictionBox');
            if (chk.checked) {
                box.classList.add('active');
            } else {
                box.classList.remove('active');
            }
        }
        
        // Initial style
        document.addEventListener('DOMContentLoaded', updateBoxStyle);
    </script>
</body>

</html>
