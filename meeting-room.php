<?php
// Enable error display for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$userName = $_SESSION['full_name'];
$userRole = $_SESSION['role'];
$isAdmin = ($userRole === 'admin');

// Get meeting code from URL
$meeting_code = isset($_GET['code']) ? trim($_GET['code']) : '';

if (empty($meeting_code)) {
    header("Location: " . ($isAdmin ? 'admin/meetings.php' : 'employee/meetings.php'));
    exit;
}

// Get meeting details
try {
    $meeting = getMeetingByCode($meeting_code);
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

if (!$meeting) {
    header("Location: " . ($isAdmin ? 'admin/meetings.php' : 'employee/meetings.php') . "?error=invalid");
    exit;
}

// Check if meeting is locked
if ($meeting['is_locked'] && $meeting['created_by'] != $user_id && !$isAdmin) {
    header("Location: " . ($isAdmin ? 'admin/meetings.php' : 'employee/meetings.php') . "?error=locked");
    exit;
}

// Check if meeting has ended
if ($meeting['status'] === 'ended') {
    header("Location: " . ($isAdmin ? 'admin/meetings.php' : 'employee/meetings.php') . "?error=ended");
    exit;
}

// Initial join (will update with peer_id via AJAX later)
try {
    joinMeeting($meeting['id'], $user_id);
} catch (Exception $e) {
    die("Error joining meeting: " . $e->getMessage());
}

// Helper function for avatar colors
function getColor($name) {
    $colors = ['#3b82f6', '#ef4444', '#22c55e', '#eab308', '#a855f7', '#ec4899'];
    $hash = 0;
    for ($i = 0; $i < strlen($name); $i++) {
        $hash = ord($name[$i]) + (($hash << 5) - $hash);
    }
    return $colors[abs($hash) % count($colors)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($meeting['title']) ?> - Meeting</title>
    <!-- PeerJS Library -->
    <script src="https://unpkg.com/peerjs@1.5.2/dist/peerjs.min.js"></script>
    <!-- RNNoise for advanced noise cancellation -->
    <script src="https://cdn.jsdelivr.net/npm/@sapphi-red/web-noise-suppressor@0.3.5/dist/index.browser.js"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #121212;
            --bg-card: #1e1e1e;
            --text-primary: #ffffff;
            --text-secondary: #a0a0a0;
            --accent: #3b82f6;
            --danger: #ef4444;
            --success: #22c55e;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background-color: var(--bg-dark);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Header */
        .header {
            height: 60px;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(30, 30, 30, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            z-index: 10;
        }

        .meeting-info h1 {
            font-size: 18px;
            font-weight: 600;
        }

        .meeting-timer {
            font-size: 14px;
            color: var(--text-secondary);
            margin-left: 12px;
            background: rgba(255,255,255,0.1);
            padding: 4px 8px;
            border-radius: 4px;
        }

        .header-actions button {
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }

        .header-actions button:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Main Grid */
        .video-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            padding: 24px;
            overflow-y: auto;
            align-content: center;
        }

        .video-card {
            background: var(--bg-card);
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            aspect-ratio: 16/9;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        .video-card video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: #000;
        }

        .video-card .avatar {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-card);
            z-index: 1;
        }

        .avatar-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 600;
        }

        .user-label {
            position: absolute;
            bottom: 12px;
            left: 12px;
            background: rgba(0, 0, 0, 0.6);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Controls */
        .controls-bar {
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            background: var(--bg-card);
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .control-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,0.1);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .control-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: scale(1.05);
        }

        .control-btn.active {
            background: var(--bg-card);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .control-btn.danger {
            background: var(--danger);
        }
        
        .control-btn.danger:hover {
            background: #dc2626;
        }

        /* Badge for notifications */
        .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }

        /* Chat Panel */
        .chat-panel {
            position: fixed;
            right: -350px;
            top: 0;
            bottom: 0;
            width: 350px;
            background: var(--bg-card);
            border-left: 1px solid rgba(255,255,255,0.1);
            display: flex;
            flex-direction: column;
            transition: right 0.3s ease;
            z-index: 50;
        }

        .chat-panel.open {
            right: 0;
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .close-btn:hover {
            background: rgba(255,255,255,0.1);
            color: var(--text-primary);
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .chat-message {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .chat-message.own {
            align-items: flex-end;
        }

        .message-sender {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .message-bubble {
            background: rgba(255,255,255,0.1);
            padding: 8px 12px;
            border-radius: 12px;
            max-width: 80%;
            word-wrap: break-word;
        }

        .chat-message.own .message-bubble {
            background: var(--accent);
        }

        .message-time {
            font-size: 11px;
            color: var(--text-secondary);
        }

        .chat-input-container {
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex;
            gap: 8px;
        }

        .chat-input-container input {
            flex: 1;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        .chat-input-container input:focus {
            border-color: var(--accent);
        }

        .send-btn {
            background: var(--accent);
            border: none;
            color: white;
            padding: 10px 16px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .send-btn:hover {
            background: #2563eb;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            z-index: 100;
        }
        .toast.show { opacity: 1; }

        /* Mobile Optimizations - Teams Style */
        @media (max-width: 768px) {
            body {
                overflow: hidden;
            }
            
            /* Minimal Header - Teams Style */
            .header {
                height: 56px;
                padding: 0 16px;
                background: rgba(0, 0, 0, 0.8);
                backdrop-filter: blur(20px);
                position: relative;
                z-index: 20;
            }
            
            .meeting-info h1 {
                font-size: 16px;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .meeting-timer {
                font-size: 13px;
                padding: 4px 8px;
                margin-left: 0;
                background: rgba(255,255,255,0.15);
                border-radius: 6px;
            }
            
            .header-actions button {
                padding: 8px 14px;
                font-size: 13px;
                border-radius: 8px;
                font-weight: 500;
            }
            
            /* Full-Screen Video Grid - Teams Mobile Style */
            .video-grid {
                grid-template-columns: 1fr;
                gap: 0;
                padding: 0;
                padding-bottom: 100px; /* Space for floating controls */
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            /* Full-width video cards */
            .video-card {
                aspect-ratio: 9/16; /* Portrait for mobile */
                border-radius: 0;
                min-height: calc(100vh - 156px); /* Full screen minus header and controls */
                margin-bottom: 0;
            }
            
            /* When multiple participants, stack vertically */
            .video-grid:has(.video-card:nth-child(2)) .video-card {
                min-height: 50vh;
                aspect-ratio: 16/9;
            }
            
            .video-grid:has(.video-card:nth-child(3)) .video-card {
                min-height: 33vh;
                aspect-ratio: 16/9;
            }
            
            /* Larger avatars for full-screen */
            .avatar-circle {
                width: 80px;
                height: 80px;
                font-size: 32px;
            }
            
            /* Prominent user labels */
            .user-label {
                bottom: 16px;
                left: 16px;
                padding: 6px 12px;
                font-size: 14px;
                font-weight: 600;
                background: rgba(0, 0, 0, 0.75);
                backdrop-filter: blur(10px);
                border-radius: 8px;
            }
            
            /* Floating Controls Bar - Teams Style */
            .controls-bar {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                height: 90px;
                gap: 20px;
                padding: 0 20px;
                background: rgba(0, 0, 0, 0.95);
                backdrop-filter: blur(30px);
                border-top: 1px solid rgba(255,255,255,0.1);
                z-index: 30;
                box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
            }
            
            /* Large touch-friendly buttons */
            .control-btn {
                width: 60px;
                height: 60px;
                font-size: 22px;
                background: rgba(255,255,255,0.15);
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255,255,255,0.1);
            }
            
            .control-btn:active {
                transform: scale(0.95);
            }
            
            .control-btn svg {
                width: 28px;
                height: 28px;
            }
            
            .control-btn.danger {
                background: var(--danger);
                border: none;
            }
            
            /* Toast positioning above controls */
            .toast {
                bottom: 110px;
                font-size: 14px;
                padding: 10px 18px;
                border-radius: 10px;
                background: rgba(0, 0, 0, 0.9);
                backdrop-filter: blur(20px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            }
            
            /* Chat panel mobile */
            .chat-panel {
                width: 100%;
                right: -100%;
            }
            
            .chat-panel.open {
                right: 0;
            }
        }
        
        /* Small Mobile (< 480px) - Compact Mode */
        @media (max-width: 480px) {
            .header {
                height: 52px;
                padding: 0 12px;
            }
            
            .meeting-info h1 {
                font-size: 15px;
            }
            
            .meeting-timer {
                font-size: 12px;
                padding: 3px 6px;
            }
            
            .header-actions button {
                padding: 6px 10px;
                font-size: 12px;
            }
            
            .video-card {
                min-height: calc(100vh - 144px);
            }
            
            .controls-bar {
                height: 85px;
                gap: 16px;
                padding: 0 16px;
            }
            
            .control-btn {
                width: 56px;
                height: 56px;
            }
            
            .control-btn svg {
                width: 26px;
                height: 26px;
            }
            
            .avatar-circle {
                width: 70px;
                height: 70px;
                font-size: 28px;
            }
        }
        
        /* Landscape Mobile - Side-by-side layout */
        @media (max-width: 896px) and (orientation: landscape) {
            .header {
                height: 48px;
                padding: 0 12px;
            }
            
            .meeting-info h1 {
                font-size: 14px;
            }
            
            .meeting-timer {
                font-size: 11px;
            }
            
            /* 2-column grid in landscape */
            .video-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 4px;
                padding: 0;
                padding-bottom: 80px;
            }
            
            .video-card {
                aspect-ratio: 16/9;
                min-height: calc(100vh - 128px);
                border-radius: 0;
            }
            
            .video-grid:has(.video-card:nth-child(2)) .video-card {
                min-height: calc(100vh - 128px);
            }
            
            .video-grid:has(.video-card:nth-child(3)) .video-card {
                min-height: calc((100vh - 128px) / 2);
            }
            
            .controls-bar {
                height: 70px;
                gap: 16px;
                padding: 0 16px;
            }
            
            .control-btn {
                width: 52px;
                height: 52px;
            }
            
            .control-btn svg {
                width: 24px;
                height: 24px;
            }
            
            .avatar-circle {
                width: 60px;
                height: 60px;
                font-size: 24px;
            }
            
            .user-label {
                bottom: 12px;
                left: 12px;
                padding: 4px 10px;
                font-size: 12px;
            }
        }
        
        /* Tablet Portrait (768px - 1024px) */
        @media (min-width: 769px) and (max-width: 1024px) and (orientation: portrait) {
            .video-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                padding: 16px;
            }
            
            .video-card {
                aspect-ratio: 4/3;
            }
        }
    </style>
</head>
<body>

    <div class="header">
        <div class="meeting-info">
            <h1><?= htmlspecialchars($meeting['title']) ?> <span class="meeting-timer" id="timer">00:00</span></h1>
        </div>
        <div class="header-actions">
            <button onclick="copyLink()">Copy Link</button>
        </div>
    </div>

    <div class="video-grid" id="videoGrid">
        <!-- Local Video -->
        <div class="video-card" id="localCard">
            <div class="avatar">
                <div class="avatar-circle" style="background: <?= getColor($userName) ?>">
                    <?= strtoupper(substr($userName, 0, 1)) ?>
                </div>
            </div>
            <video id="localVideo" autoplay muted playsinline></video>
            <div class="user-label">You</div>
        </div>
    </div>

    <div class="controls-bar">
        <button class="control-btn" id="micBtn" onclick="toggleMic()" title="Toggle Microphone">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
        </button>
        <button class="control-btn" id="camBtn" onclick="toggleCam()" title="Toggle Camera">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 7l-7 5 7 5V7z"></path><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
        </button>
        <button class="control-btn" id="screenBtn" onclick="toggleScreenShare()" title="Share Screen">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
        </button>
        <button class="control-btn" id="chatBtn" onclick="toggleChat()" title="Chat">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <span class="badge" id="chatBadge" style="display:none;">0</span>
        </button>
        <button class="control-btn danger" onclick="leaveMeeting()" title="Leave Meeting">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
        </button>
    </div>

    <!-- Chat Panel -->
    <div class="chat-panel" id="chatPanel">
        <div class="chat-header">
            <h3>Meeting Chat</h3>
            <button onclick="toggleChat()" class="close-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="chat-messages" id="chatMessages"></div>
        <div class="chat-input-container">
            <input type="text" id="chatInput" placeholder="Type a message..." maxlength="1000">
            <button onclick="sendChatMessage()" class="send-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
            </button>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const MEETING_ID = <?= $meeting['id'] ?>;
        const USER_ID = <?= $user_id ?>;
        const USER_NAME = "<?= addslashes($userName) ?>";
        
        let localStream;
        let peer;
        let calls = {};
        let isMuted = false;
        let isVideoOff = false;

        let noiseSuppressor = null;
        let audioContext = null;
        let processedAudioStream = null;
        
        // Initialize
        async function init() {
            try {
                showToast('Requesting camera/mic access...');
                
                // Professional audio constraints (Teams/Meet quality)
                const rawStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        frameRate: { ideal: 30 }
                    },
                    audio: { 
                        echoCancellation: true,        // Remove echo from speakers
                        noiseSuppression: true,        // Remove background noise
                        autoGainControl: true,         // Normalize volume levels
                        sampleRate: 48000,             // High-quality audio (Opus codec)
                        channelCount: 1,               // Mono (better for voice)
                        latency: 0.01                  // Low latency for real-time
                    }
                });
                
                // Use raw stream directly for better compatibility
                // Advanced processing can cause audio transmission issues
                localStream = rawStream;
                showToast('✓ Audio/Video ready');
                console.log('Using direct stream for maximum compatibility');
                
                // Disable video by default (user must click camera button to enable)
                const videoTrack = localStream.getVideoTracks()[0];
                if (videoTrack) {
                    videoTrack.enabled = false;
                    isVideoOff = true;
                    
                    // Update camera button to show video is off
                    const camBtn = document.getElementById('camBtn');
                    camBtn.classList.add('danger');
                    camBtn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
                    
                    // Show avatar instead of video
                    document.querySelector('#localCard .avatar').style.display = 'flex';
                }
                
                const video = document.getElementById('localVideo');
                video.srcObject = localStream;
                video.onloadedmetadata = () => {
                    video.play();
                    // Don't hide avatar since video is off
                };

                // Verify audio is in the stream
                console.log('=== LOCAL STREAM READY ===');
                console.log('Audio tracks:', localStream.getAudioTracks().length);
                console.log('Video tracks:', localStream.getVideoTracks().length);
                console.log('Video initially OFF (user must enable)');
                if (localStream.getAudioTracks().length > 0) {
                    const audioTrack = localStream.getAudioTracks()[0];
                    console.log('Audio track label:', audioTrack.label);
                    console.log('Audio track enabled:', audioTrack.enabled);
                    console.log('Audio track muted:', audioTrack.muted);
                    console.log('Audio track readyState:', audioTrack.readyState);
                } else {
                    console.error('WARNING: No audio track in local stream!');
                    showToast('⚠️ No audio detected. Check microphone permissions.');
                }

                initPeer();
                startTimer();

            } catch (err) {
                console.error('Media access denied:', err);
                showToast('Camera/Mic access denied. Please allow access.');
                // Try audio only with professional settings
                try {
                    const audioStream = await navigator.mediaDevices.getUserMedia({ 
                        audio: {
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true,
                            sampleRate: 48000
                        }
                    });
                    localStream = await applyAudioFiltering(audioStream);
                    initPeer();
                    startTimer();
                } catch (e) {
                    showToast('Could not access any media devices.');
                }
            }
        }

        // Web Audio API filtering for noise reduction
        async function applyAudioFiltering(stream) {
            try {
                const audioTrack = stream.getAudioTracks()[0];
                if (!audioTrack) {
                    console.warn('No audio track found in stream');
                    return stream;
                }

                console.log('Applying audio filtering to track:', audioTrack.label);

                // Create audio context
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const source = audioContext.createMediaStreamSource(new MediaStream([audioTrack]));
                
                // High-pass filter to remove low-frequency noise (rumble, hum)
                const highPassFilter = audioContext.createBiquadFilter();
                highPassFilter.type = 'highpass';
                highPassFilter.frequency.value = 100; // Remove frequencies below 100Hz
                highPassFilter.Q.value = 0.7;
                
                // Low-pass filter to remove high-frequency noise (hiss)
                const lowPassFilter = audioContext.createBiquadFilter();
                lowPassFilter.type = 'lowpass';
                lowPassFilter.frequency.value = 8000; // Remove frequencies above 8kHz
                lowPassFilter.Q.value = 0.7;
                
                // Compressor for consistent volume
                const compressor = audioContext.createDynamicsCompressor();
                compressor.threshold.value = -50;
                compressor.knee.value = 40;
                compressor.ratio.value = 12;
                compressor.attack.value = 0.003;
                compressor.release.value = 0.25;
                
                // Gain node for final volume control
                const gainNode = audioContext.createGain();
                gainNode.gain.value = 1.0;
                
                // Connect the audio processing chain
                source.connect(highPassFilter);
                highPassFilter.connect(lowPassFilter);
                lowPassFilter.connect(compressor);
                compressor.connect(gainNode);
                
                // Create destination for processed audio
                const destination = audioContext.createMediaStreamDestination();
                gainNode.connect(destination);
                
                // Get the processed audio track
                const processedAudioTrack = destination.stream.getAudioTracks()[0];
                console.log('Processed audio track created:', processedAudioTrack.label, 'enabled:', processedAudioTrack.enabled);
                
                // Combine processed audio with video
                const videoTrack = stream.getVideoTracks()[0];
                
                if (videoTrack) {
                    return new MediaStream([processedAudioTrack, videoTrack]);
                } else {
                    return new MediaStream([processedAudioTrack]);
                }
            } catch (err) {
                console.error('Audio filtering failed:', err);
                return stream; // Fallback to original stream
            }
        }

        function initPeer() {
            // Professional ICE servers configuration (STUN/TURN for stable connections)
            const config = {
                iceServers: [
                    // Google's public STUN servers
                    { urls: 'stun:stun.l.google.com:19302' },
                    { urls: 'stun:stun1.l.google.com:19302' },
                    { urls: 'stun:stun2.l.google.com:19302' },
                    // Cloudflare STUN
                    { urls: 'stun:stun.cloudflare.com:3478' }
                    // Add TURN server here if you have one:
                    // {
                    //     urls: 'turn:your-turn-server.com:3478',
                    //     username: 'your-username',
                    //     credential: 'your-password'
                    // }
                ],
                iceTransportPolicy: 'all', // Use both STUN and TURN
                iceCandidatePoolSize: 10   // Pre-gather ICE candidates
            };

            // Create PeerJS instance with professional config
            peer = new Peer(undefined, {
                debug: 2,
                config: config // Pass ICE server configuration
            });

            peer.on('open', id => {
                console.log('My Peer ID:', id);
                // Send Peer ID to server
                fetch('api/join-meeting.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        meeting_id: MEETING_ID,
                        peer_id: id
                    })
                });
                
                showToast('Connected to meeting server');
                startPolling();
            });

            // Answer incoming calls
            peer.on('call', call => {
                console.log('Incoming call from:', call.peer);
                const callerName = call.metadata?.name || 'Participant';
                console.log('Caller name:', callerName);
                
                // Ensure audio is enabled before answering
                const audioTrack = localStream.getAudioTracks()[0];
                if (audioTrack) {
                    console.log('Answering with audio track:', audioTrack.label, 'enabled:', audioTrack.enabled);
                }
                
                call.answer(localStream);
                handleCall(call, callerName);
            });
            
            peer.on('error', err => {
                console.error('Peer error:', err);
                showToast('Connection error: ' + err.type);
            });
        }

        function startPolling() {
            // Check for new participants every 3 seconds
            setInterval(() => {
                fetch('api/get-participants.php?meeting_id=' + MEETING_ID)
                    .then(r => r.json())
                    .then(participants => {
                        participants.forEach(p => {
                            // If it's not me, has a peer_id, and I haven't called them yet
                            if (p.user_id != USER_ID && p.peer_id && !calls[p.peer_id]) {
                                console.log('Found new peer:', p.full_name, p.peer_id);
                                connectToPeer(p.peer_id, p.full_name);
                            }
                        });
                    });
            }, 3000);
        }

        function connectToPeer(peerId, name) {
            console.log('=== CALLING PEER ===');
            console.log('Peer ID:', peerId);
            console.log('Peer Name:', name);
            console.log('Sending stream with audio tracks:', localStream.getAudioTracks().length);
            console.log('Sending stream with video tracks:', localStream.getVideoTracks().length);
            
            const call = peer.call(peerId, localStream, {
                metadata: { name: USER_NAME }
            });
            handleCall(call, name);
        }

        function handleCall(call, name) {
            calls[call.peer] = call;
            
            const videoId = 'video-' + call.peer;
            if (document.getElementById(videoId)) return; // Already exists

            // Create video card
            const card = document.createElement('div');
            card.className = 'video-card';
            card.id = 'card-' + call.peer;
            card.innerHTML = `
                <div class="avatar">
                    <div class="avatar-circle" style="background: #3b82f6">?</div>
                </div>
                <video id="${videoId}" autoplay playsinline></video>
                <div class="user-label">${name || 'Participant'}</div>
            `;
            document.getElementById('videoGrid').appendChild(card);

            call.on('stream', remoteStream => {
                console.log('Received stream from:', call.peer);
                console.log('Remote stream audio tracks:', remoteStream.getAudioTracks().length);
                console.log('Remote stream video tracks:', remoteStream.getVideoTracks().length);
                
                const video = document.getElementById(videoId);
                if (video) {
                    video.srcObject = remoteStream;
                    
                    // Check if stream has audio
                    const audioTracks = remoteStream.getAudioTracks();
                    if (audioTracks.length > 0) {
                        console.log('Remote audio track:', audioTracks[0].label, 'enabled:', audioTracks[0].enabled);
                    }
                    
                    // Start muted to allow autoplay, then unmute immediately
                    video.muted = true;
                    video.volume = 1.0;
                    
                    video.onloadedmetadata = () => {
                        video.play().then(() => {
                            console.log('Remote video playing for:', call.peer);
                            // Unmute immediately after play starts
                            video.muted = false;
                            console.log('Remote video unmuted - audio should be audible');
                            card.querySelector('.avatar').style.display = 'none';
                        }).catch(err => {
                            console.error('Error playing remote video:', err);
                            // If autoplay fails, try with user interaction
                            showToast('Click to enable audio for ' + name);
                            video.onclick = () => {
                                video.play().then(() => {
                                    video.muted = false;
                                    showToast('Audio enabled');
                                });
                            };
                        });
                    };
                }
            });

            call.on('close', () => {
                card.remove();
                delete calls[call.peer];
            });
            
            call.on('error', () => {
                card.remove();
                delete calls[call.peer];
            });
        }

        // Controls
        function toggleMic() {
            const track = localStream.getAudioTracks()[0];
            if (!track) return;
            isMuted = !isMuted;
            track.enabled = !isMuted;
            
            const btn = document.getElementById('micBtn');
            if (isMuted) {
                btn.classList.add('danger');
                btn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="1" y1="1" x2="23" y2="23"></line><path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"></path><path d="M17 16.95A7 7 0 0 1 5 12v-2m14 0v2a7 7 0 0 1-.11 1.23"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>';
            } else {
                btn.classList.remove('danger');
                btn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>';
            }
        }

        function toggleCam() {
            const track = localStream.getVideoTracks()[0];
            if (!track) return;
            isVideoOff = !isVideoOff;
            track.enabled = !isVideoOff;
            
            const btn = document.getElementById('camBtn');
            if (isVideoOff) {
                btn.classList.add('danger');
                btn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
                document.querySelector('#localCard .avatar').style.display = 'flex';
            } else {
                btn.classList.remove('danger');
                btn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 7l-7 5 7 5V7z"></path><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>';
                document.querySelector('#localCard .avatar').style.display = 'none';
            }
        }

        function leaveMeeting() {
            if (confirm('Leave meeting?')) {
                if (localStream) localStream.getTracks().forEach(t => t.stop());
                if (peer) peer.destroy();
                fetch('api/leave-meeting.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({meeting_id: MEETING_ID})
                }).finally(() => {
                    window.location.href = '<?= $isAdmin ? 'admin/meetings.php' : 'employee/meetings.php' ?>';
                });
            }
        }

        // Utilities
        function showToast(msg) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 3000);
        }

        function copyLink() {
            const url = window.location.href;
            navigator.clipboard.writeText(url).then(() => showToast('Link copied!'));
        }

        function startTimer() {
            const start = new Date('<?= $meeting['created_at'] ?>').getTime();
            setInterval(() => {
                const diff = Math.floor((Date.now() - start) / 1000);
                const m = String(Math.floor(diff / 60)).padStart(2, '0');
                const s = String(diff % 60).padStart(2, '0');
                document.getElementById('timer').textContent = `${m}:${s}`;
            }, 1000);
        }

        // Chat functionality
        let chatOpen = false;
        let lastMessageId = 0;
        let screenStream = null;
        let isScreenSharing = false;

        function toggleChat() {
            chatOpen = !chatOpen;
            const panel = document.getElementById('chatPanel');
            const badge = document.getElementById('chatBadge');
            
            if (chatOpen) {
                panel.classList.add('open');
                badge.style.display = 'none';
                badge.textContent = '0';
                loadChatMessages();
                startChatPolling();
            } else {
                panel.classList.remove('open');
                stopChatPolling();
            }
        }

        async function loadChatMessages() {
            try {
                const res = await fetch(`api/meeting-chat.php?action=get_messages&meeting_id=${MEETING_ID}`);
                const data = await res.json();
                
                if (data.success) {
                    const container = document.getElementById('chatMessages');
                    container.innerHTML = '';
                    
                    data.messages.forEach(msg => {
                        appendMessage(msg);
                        lastMessageId = Math.max(lastMessageId, msg.id);
                    });
                    
                    scrollChatToBottom();
                }
            } catch (err) {
                console.error('Error loading chat:', err);
            }
        }

        async function sendChatMessage() {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            
            if (!message) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'send_message');
                formData.append('meeting_id', MEETING_ID);
                formData.append('message', message);
                
                const res = await fetch('api/meeting-chat.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await res.json();
                
                if (data.success) {
                    input.value = '';
                    appendMessage(data.message);
                    lastMessageId = data.message.id;
                    scrollChatToBottom();
                } else {
                    showToast(data.error || 'Failed to send message');
                }
            } catch (err) {
                console.error('Error sending message:', err);
                showToast('Failed to send message');
            }
        }

        function appendMessage(msg) {
            const container = document.getElementById('chatMessages');
            const isOwn = msg.user_id == USER_ID;
            
            const messageDiv = document.createElement('div');
            messageDiv.className = 'chat-message' + (isOwn ? ' own' : '');
            
            const time = new Date(msg.sent_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            
            messageDiv.innerHTML = `
                ${!isOwn ? `<div class="message-sender">${msg.full_name}</div>` : ''}
                <div class="message-bubble">${escapeHtml(msg.message)}</div>
                <div class="message-time">${time}</div>
            `;
            
            container.appendChild(messageDiv);
        }

        function scrollChatToBottom() {
            const container = document.getElementById('chatMessages');
            container.scrollTop = container.scrollHeight;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Poll for new chat messages
        let chatPollInterval;
        function startChatPolling() {
            chatPollInterval = setInterval(async () => {
                try {
                    const res = await fetch(`api/meeting-chat.php?action=get_messages&meeting_id=${MEETING_ID}`);
                    const data = await res.json();
                    
                    if (data.success) {
                        const newMessages = data.messages.filter(m => m.id > lastMessageId);
                        newMessages.forEach(msg => {
                            appendMessage(msg);
                            lastMessageId = msg.id;
                            
                            // Show badge if chat is closed
                            if (!chatOpen && msg.user_id != USER_ID) {
                                const badge = document.getElementById('chatBadge');
                                badge.style.display = 'block';
                                const count = parseInt(badge.textContent) || 0;
                                badge.textContent = count + 1;
                            }
                        });
                        
                        if (newMessages.length > 0 && chatOpen) {
                            scrollChatToBottom();
                        }
                    }
                } catch (err) {
                    console.error('Error polling chat:', err);
                }
            }, 2000);
        }

        function stopChatPolling() {
            if (chatPollInterval) {
                clearInterval(chatPollInterval);
            }
        }

        // Enter key to send message
        document.addEventListener('DOMContentLoaded', () => {
            const chatInput = document.getElementById('chatInput');
            if (chatInput) {
                chatInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        sendChatMessage();
                    }
                });
            }
        });

        // Screen sharing
        async function toggleScreenShare() {
            if (isScreenSharing) {
                stopScreenShare();
            } else {
                await startScreenShare();
            }
        }

        async function startScreenShare() {
            try {
                screenStream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        cursor: 'always'
                    },
                    audio: false
                });

                // Replace video track in local stream
                const screenTrack = screenStream.getVideoTracks()[0];
                const videoTrack = localStream.getVideoTracks()[0];
                
                // Replace track in all peer connections
                Object.values(calls).forEach(call => {
                    const sender = call.peerConnection.getSenders().find(s => s.track && s.track.kind === 'video');
                    if (sender) {
                        sender.replaceTrack(screenTrack);
                    }
                });

                // Update local video
                const localVideo = document.getElementById('localVideo');
                localVideo.srcObject = new MediaStream([screenTrack, localStream.getAudioTracks()[0]]);

                // Update button
                const btn = document.getElementById('screenBtn');
                btn.classList.add('active');
                isScreenSharing = true;

                // Handle screen share stop
                screenTrack.onended = () => {
                    stopScreenShare();
                };

                showToast('Screen sharing started');
            } catch (err) {
                console.error('Screen share error:', err);
                showToast('Failed to share screen');
            }
        }

        function stopScreenShare() {
            if (screenStream) {
                screenStream.getTracks().forEach(track => track.stop());
                screenStream = null;
            }

            // Restore camera video
            const videoTrack = localStream.getVideoTracks()[0];
            
            Object.values(calls).forEach(call => {
                const sender = call.peerConnection.getSenders().find(s => s.track && s.track.kind === 'video');
                if (sender && videoTrack) {
                    sender.replaceTrack(videoTrack);
                }
            });

            // Update local video
            const localVideo = document.getElementById('localVideo');
            localVideo.srcObject = localStream;

            // Update button
            const btn = document.getElementById('screenBtn');
            btn.classList.remove('active');
            isScreenSharing = false;

            showToast('Screen sharing stopped');
        }

        window.addEventListener('load', init);
    </script>
</body>
</html>
