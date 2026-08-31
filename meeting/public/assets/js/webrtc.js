let localStream;
const peers = {}; // Store peer connections: { userId: RTCPeerConnection }

const rtcConfig = {
    iceServers: [
        { urls: "stun:stun.l.google.com:19302" },
        // Add TURN server here
        // { urls: "turn:yourserver:3478", username: "user", credential: "pass" }
    ]
};

async function startLocalStream() {
    try {
        localStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            },
            video: true
        });
        document.getElementById('local-video').srcObject = localStream;
    } catch (e) {
        console.error('Error accessing media devices:', e);
        alert('Could not access camera/microphone');
    }
}

function createPeerConnection(userId) {
    const pc = new RTCPeerConnection(rtcConfig);

    pc.onicecandidate = (event) => {
        if (event.candidate) {
            sendSignal('candidate', {
                target: userId,
                candidate: event.candidate
            });
        }
    };

    pc.ontrack = (event) => {
        addRemoteVideo(userId, event.streams[0]);
    };

    localStream.getTracks().forEach(track => {
        pc.addTrack(track, localStream);
    });

    peers[userId] = pc;
    return pc;
}

async function initiateConnection(userId) {
    const pc = createPeerConnection(userId);
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    sendSignal('offer', {
        target: userId,
        offer: offer
    });
}

async function handleOffer(offer, senderId) {
    const pc = createPeerConnection(senderId);
    await pc.setRemoteDescription(new RTCSessionDescription(offer));
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    sendSignal('answer', {
        target: senderId,
        answer: answer
    });
}

async function handleAnswer(answer, senderId) {
    const pc = peers[senderId];
    if (pc) {
        await pc.setRemoteDescription(new RTCSessionDescription(answer));
    }
}

async function handleCandidate(candidate, senderId) {
    const pc = peers[senderId];
    if (pc) {
        await pc.addIceCandidate(new RTCIceCandidate(candidate));
    }
}

function addRemoteVideo(userId, stream) {
    let videoDiv = document.getElementById(`video-${userId}`);
    if (!videoDiv) {
        videoDiv = document.createElement('div');
        videoDiv.className = 'video-card';
        videoDiv.id = `video-${userId}`;

        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        video.srcObject = stream;

        const label = document.createElement('div');
        label.className = 'user-label';
        label.innerText = `User ${userId}`;

        videoDiv.appendChild(video);
        videoDiv.appendChild(label);
        document.getElementById('video-grid').appendChild(videoDiv);
    }
}

function removeRemoteVideo(userId) {
    const videoDiv = document.getElementById(`video-${userId}`);
    if (videoDiv) {
        videoDiv.remove();
    }
    if (peers[userId]) {
        peers[userId].close();
        delete peers[userId];
    }
}

// Controls
function toggleMic() {
    const track = localStream.getAudioTracks()[0];
    track.enabled = !track.enabled;
    document.getElementById('mic-btn').classList.toggle('active', !track.enabled); // Visual feedback
    document.getElementById('mic-btn').querySelector('i').innerText = track.enabled ? 'mic' : 'mic_off';
}

function toggleCam() {
    const track = localStream.getVideoTracks()[0];
    track.enabled = !track.enabled;
    document.getElementById('cam-btn').classList.toggle('active', !track.enabled);
    document.getElementById('cam-btn').querySelector('i').innerText = track.enabled ? 'videocam' : 'videocam_off';
}

async function toggleScreenShare() {
    // Basic implementation: replace video track
    try {
        const screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
        const screenTrack = screenStream.getVideoTracks()[0];

        Object.values(peers).forEach(pc => {
            const sender = pc.getSenders().find(s => s.track.kind === 'video');
            if (sender) {
                sender.replaceTrack(screenTrack);
            }
        });

        document.getElementById('local-video').srcObject = screenStream;

        screenTrack.onended = () => {
            // Revert to camera
            const camTrack = localStream.getVideoTracks()[0];
            Object.values(peers).forEach(pc => {
                const sender = pc.getSenders().find(s => s.track.kind === 'video');
                if (sender) {
                    sender.replaceTrack(camTrack);
                }
            });
            document.getElementById('local-video').srcObject = localStream;
        };
    } catch (e) {
        console.error("Error sharing screen", e);
    }
}

function leaveMeeting() {
    window.location.href = 'index.php';
}

// Recording
let mediaRecorder;
let isRecording = false;

function toggleRecording() {
    const btn = document.getElementById('record-btn');
    if (!isRecording) {
        startRecording();
        btn.classList.add('danger'); // Red color
    } else {
        stopRecording();
        btn.classList.remove('danger');
    }
    isRecording = !isRecording;
}

function startRecording() {
    // Record local stream for now (or mix streams if possible)
    const options = { mimeType: 'video/webm; codecs=vp9' };
    mediaRecorder = new MediaRecorder(localStream, options);

    mediaRecorder.ondataavailable = handleDataAvailable;
    mediaRecorder.start(1000); // Collect 1s chunks
    console.log('Recording started');
}

function stopRecording() {
    mediaRecorder.stop();
    console.log('Recording stopped');
}

function handleDataAvailable(event) {
    if (event.data.size > 0) {
        // Send chunk to server
        fetch(`upload_recording.php?room=${ROOM_ID}`, {
            method: 'POST',
            body: event.data
        });
    }
}

// Start
startLocalStream();
