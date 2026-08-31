<?php
require_once 'includes/functions.php';
requireLogin();

// Check if Notice Page is Enabled
$noticeEnabled = 1; // Default ON
try {
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'notice_enabled'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val !== false) {
        $noticeEnabled = (int)$val;
    }
} catch (Exception $e) {}

// If disabled, skip to hub
if ($noticeEnabled === 0) {
    $params = isset($_GET['login_success']) ? 'login_success=1' : '';
    header("Location: " . company_url('select-module') . ($params ? '?' . $params : ''));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urgent Action Required - <?= COMPANY_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --danger: #ef4444;
            --warning: #f59e0b;
            --text-light: #f8fafc;
            --text-muted: #94a3b8;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-light);
            margin: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .container {
            background: var(--card-bg);
            padding: 40px;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            position: relative;
            overflow: hidden;
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--danger);
        }

        .icon {
            font-size: 64px;
            color: var(--danger);
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }

        h1 {
            color: var(--danger);
            font-size: 24px;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        p {
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .highlight {
            color: white;
            font-weight: 600;
        }

        .countdown-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--danger);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .countdown-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--danger);
            font-weight: 700;
            margin-bottom: 5px;
        }

        .timer {
            font-size: 32px;
            font-family: monospace;
            font-weight: 700;
            color: white;
        }

        .btn-proceed {
            background: var(--danger);
            color: white;
            border: none;
            padding: 16px 32px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            width: 100%;
            box-sizing: border-box;
        }

        .btn-proceed:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(220, 38, 38, 0.4);
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body>

    <div class="container">
        <i class="fas fa-exclamation-triangle icon"></i>
        <h1>Official Name Policy</h1>
        
        <p>
            Attention <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Employee') ?></strong>,<br>
            All staff accounts must use <span class="highlight">Official Government Names</span> strictly.
            Nicknames or abbreviations are prohibited.
        </p>

        <div class="countdown-box">
            <div class="countdown-label">Account Lockdown In</div>
            <div class="timer" id="timer">23:59:59</div>
        </div>

        <p style="font-size: 13px;">
            Failure to update your profile within this timeframe will result in an 
            <span class="highlight">Automatic account Block</span>.
        </p>

        <a href="<?= company_url('select-module') ?><?= isset($_GET['login_success']) ? '?login_success=1' : '' ?>" class="btn-proceed">
            OK, I Understand
        </a>
    </div>

    <script>
        // Simple 24h countdown simulation
        // In a real scenario, this would ideally be calculated from a server timestamp
        let totalSeconds = 24 * 60 * 60; 
        
        const timerElement = document.getElementById('timer');

        setInterval(() => {
            if (totalSeconds <= 0) {
                timerElement.innerText = "00:00:00";
                return;
            }
            totalSeconds--;
            
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            timerElement.innerText = 
                `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }, 1000);
    </script>
</body>
</html>
