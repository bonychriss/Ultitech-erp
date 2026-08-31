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

        /* Mobile */
        @media (max-width: 768px) {
            .video-grid {
                grid-template-columns: 1fr;
                padding: 16px;
            }
            .controls-bar {
                height: 70px;
                gap: 12px;
            }
            .control-btn {
                width: 44px;
                height: 44px;
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
        <button class="control-btn danger" onclick="leaveMeeting()" title="Leave Meeting">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
        </button>
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
        
        // Initialize
        async function init() {
            try {
                showToast('Requesting camera/mic access...');
                
                // Get raw stream first
                const rawStream = await navigator.mediaDevices.getUserMedia({
                    video: true,
                    audio: { 
                        echoCancellation: true,
                        noiseSuppression: false, // We'll use RNNoise instead
                        autoGainControl: true,
                        sampleRate: 48000
                    }
                });
                
                // Apply advanced noise cancellation using RNNoise
                showToast('Applying noise cancellation...');
                try {
                    noiseSuppressor = await webNoiseSuppressor.load();
                    const audioTrack = rawStream.getAudioTracks()[0];
                    const processedTrack = await noiseSuppressor.createStream(audioTrack);
                    
                    // Create new stream with processed audio and original video
                    const videoTrack = rawStream.getVideoTracks()[0];
                    localStream = new MediaStream([processedTrack, videoTrack]);
                    
                    showToast('✓ Professional noise cancellation enabled');
                } catch (err) {
                    console.warn('Advanced noise cancellation failed, using basic:', err);
                    localStream = rawStream; // Fallback to raw stream
                    showToast('Using basic noise suppression');
                }
                
                const video = document.getElementById('localVideo');
                video.srcObject = localStream;
                video.onloadedmetadata = () => {
                    video.play();
                    document.querySelector('#localCard .avatar').style.display = 'none';
                };

                initPeer();
                startTimer();

            } catch (err) {
                console.error('Media access denied:', err);
                showToast('Camera/Mic access denied. Please allow access.');
                // Try audio only
                try {
                    localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    initPeer();
                    startTimer();
                } catch (e) {
                    showToast('Could not access any media devices.');
                }
            }
        }

        function initPeer() {
            // Create PeerJS instance
            peer = new Peer(undefined, {
                debug: 2
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
                call.answer(localStream);
                handleCall(call);
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
                const video = document.getElementById(videoId);
                video.srcObject = remoteStream;
                video.onloadedmetadata = () => {
                    video.play();
                    card.querySelector('.avatar').style.display = 'none';
                };
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

        window.addEventListener('load', init);
    </script>
</body>
</html>
