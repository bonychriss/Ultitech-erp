<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Room</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <script src="https://webrtc.github.io/adapter/adapter-latest.js"></script>
</head>
<body class="meeting-body">
    <div class="meeting-container">
        <div class="video-grid" id="video-grid">
            <!-- Local Video -->
            <div class="video-card local">
                <video id="local-video" autoplay muted playsinline></video>
                <div class="user-label">You</div>
            </div>
            <!-- Remote videos will be added here -->
        </div>

        <div class="controls-bar">
            <button class="control-btn" id="mic-btn" onclick="toggleMic()">
                <i class="material-icons">mic</i>
            </button>
            <button class="control-btn" id="cam-btn" onclick="toggleCam()">
                <i class="material-icons">videocam</i>
            </button>
            <button class="control-btn" id="screen-btn" onclick="toggleScreenShare()">
                <i class="material-icons">screen_share</i>
            </button>
            <button class="control-btn" id="chat-btn" onclick="toggleChat()">
                <i class="material-icons">chat</i>
            </button>
            <button class="control-btn" id="record-btn" onclick="toggleRecording()">
                <i class="material-icons">fiber_manual_record</i>
            </button>
            <button class="control-btn danger" onclick="leaveMeeting()">
                <i class="material-icons">call_end</i>
            </button>
        </div>

        <div class="side-panel" id="side-panel">
            <div class="panel-header">
                <h3>Chat</h3>
                <button onclick="toggleChat()"><i class="material-icons">close</i></button>
            </div>
            <div class="chat-messages" id="chat-messages"></div>
            <div class="chat-input">
                <input type="text" id="chat-input" placeholder="Type a message...">
                <button onclick="sendMessage()"><i class="material-icons">send</i></button>
            </div>
        </div>
    </div>

    <script>
        const ROOM_ID = "<?php echo isset($_GET['room']) ? htmlspecialchars($_GET['room']) : 'default'; ?>";
        const USER_ID = "<?php session_start(); echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : (isset($_GET['user']) ? htmlspecialchars($_GET['user']) : 'Guest'); ?>";
    </script>
    <script src="assets/js/signaling.js"></script>
    <script src="assets/js/webrtc.js"></script>
</body>
</html>

