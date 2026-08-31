<?php
require_once 'includes/functions.php';
requireLogin();

// Room Handling
$roomRequest = isset($_GET['room']) ? trim($_GET['room']) : '';
if (empty($roomRequest)) {
    header("Location: meeting-lobby.php");
    exit();
}

// Generate a consistent, safe Jitsi Room ID (CASE-INSENSITIVE + UNIQUE NAMESPACE)
$safeRoomName = preg_replace('/[^a-zA-Z0-9]/', '', $roomRequest);
$companyHash = substr(md5(COMPANY_NAME), 0, 10);
$jitsiRoomId = "UltimateMeeting_" . $companyHash . "_" . strtolower($safeRoomName);

$userName = $_SESSION['full_name'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'employee';

// Avatar handling
$userAvatar = $_SESSION['profile_photo'] ?? null;

// Signaling: Register/Heartbeat
try {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO active_meetings (room_name, created_by, status, last_activity) 
                           VALUES (?, ?, 'active', CURRENT_TIMESTAMP) 
                           ON DUPLICATE KEY UPDATE status='active', last_activity=CURRENT_TIMESTAMP");
    $stmt->execute([$roomRequest, $userName]);
} catch (PDOException $e) {
    error_log("Meeting signaling error: " . $e->getMessage());
}

if (isset($_GET['heartbeat'])) exit();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting: <?= htmlspecialchars($roomRequest) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://meet.jit.si/external_api.js"></script>
    <style>
        :root {
            --bg-dark: #0f172a;
            --text-light: #f1f5f9;
            --brand-blue: #3b82f6;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-light);
            margin: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Top Bar */
        .meeting-header {
            height: 60px;
            padding: 0 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #1e293b;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            z-index: 10;
        }

        .header-left { display: flex; align-items: center; gap: 15px; }
        .room-tag {
            background: var(--brand-blue);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .room-title { 
            font-weight: 600; 
            font-size: 18px; 
            display: flex; 
            align-items: center; 
            gap: 10px;
        }

        .btn-close {
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-close:hover { background: rgba(255,255,255,0.2); }

        /* Main Stage - Jitsi Container */
        .main-container { 
            flex: 1; 
            position: relative;
            background: #000;
        }

        #jitsi-container {
            width: 100%;
            height: 100%;
            border: none;
        }
    </style>
</head>
<body>

    <header class="meeting-header">
        <div class="header-left">
            <span class="room-tag" title="ID: <?= $jitsiRoomId ?>">Live Room</span>
            <div class="room-title">
                <?= htmlspecialchars($roomRequest) ?>
                <i class="far fa-copy" onclick="copyLink()" style="font-size:14px; cursor:pointer; opacity:0.6;" title="Copy Room Link"></i>
            </div>
        </div>
        <div>
            <a href="meeting-lobby.php" class="btn-close">
                <i class="fas fa-arrow-left"></i> Return to Lobby
            </a>
        </div>
    </header>

    <div class="main-container">
        <div id="jitsi-container"></div>
    </div>

    <script>
        const domain = 'meet.jit.si';
        const options = {
            roomName: '<?= $jitsiRoomId ?>',
            parentNode: document.querySelector('#jitsi-container'),
            userInfo: { 
                displayName: '<?= addslashes($userName) ?>' 
            },
            configOverwrite: {
                startWithAudioMuted: false,   // Start with Mic ON
                startWithVideoMuted: false,   // Start with Cam ON
                prejoinPageEnabled: false,    // FORCE SKIP "Hair Check"
                disableDeepLinking: true,     // No mobile app prompts
                enableWelcomePage: false,     // No "Welcome" screen
                toolbarButtons: [
                    'microphone',
                    'camera',
                    'desktop',    // Screen Share
                    'chat',
                    'participants-pane',
                    'raisehand',
                    'tileview',
                    'fullscreen',
                    'hangup'
                ]
            },
            interfaceConfigOverwrite: {
                SHOW_JITSI_WATERMARK: false,
                SHOW_WATERMARK_FOR_GUESTS: false,
                DEFAULT_BACKGROUND: '#0f172a',
                TOOLBAR_ALWAYS_VISIBLE: true,
                HIDE_INVITE_MORE_HEADER: true
            }
        };

        const api = new JitsiMeetExternalAPI(domain, options);

        // Copy Link Feature
        function copyLink() {
            const url = window.location.href;
            navigator.clipboard.writeText(url).then(() => {
                alert("Room link copied to clipboard!");
            });
        }

        // Heartbeat to keep room active in DB
        setInterval(() => {
            fetch(window.location.href + '&heartbeat=1').catch(()=>{});
        }, 30000);

        // Handle Hangup
        api.addEventListener('videoConferenceLeft', () => {
            window.location.href = 'meeting-lobby.php';
        });
    </script>
</body>
</html>
