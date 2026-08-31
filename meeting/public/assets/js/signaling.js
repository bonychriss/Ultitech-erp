const ws = new WebSocket('ws://localhost:8080');

ws.onopen = () => {
    console.log('Connected to WebSocket');
    ws.send(JSON.stringify({
        type: 'join',
        room: ROOM_ID
    }));
};

ws.onmessage = (event) => {
    const data = JSON.parse(event.data);
    handleSignalingData(data);
};

function sendSignal(type, payload) {
    ws.send(JSON.stringify({
        type: type,
        room: ROOM_ID,
        ...payload
    }));
}

function handleSignalingData(data) {
    switch (data.type) {
        case 'user-joined':
            console.log('User joined:', data.userId);
            initiateConnection(data.userId);
            break;
        case 'user-left':
            removeRemoteVideo(data.userId);
            break;
        case 'offer':
            handleOffer(data.offer, data.sender);
            break;
        case 'answer':
            handleAnswer(data.answer, data.sender);
            break;
        case 'candidate':
            handleCandidate(data.candidate, data.sender);
            break;
        case 'chat':
            appendChatMessage(data.sender, data.message);
            break;
    }
}

// Chat functions
function toggleChat() {
    document.getElementById('side-panel').classList.toggle('open');
}

function sendMessage() {
    const input = document.getElementById('chat-input');
    const msg = input.value;
    if (msg) {
        sendSignal('chat', { message: msg });
        appendChatMessage('You', msg, true);
        input.value = '';
    }
}

function appendChatMessage(sender, msg, isSelf = false) {
    const div = document.createElement('div');
    div.className = `chat-msg ${isSelf ? 'self' : ''}`;
    div.innerText = `${sender}: ${msg}`;
    document.getElementById('chat-messages').appendChild(div);
}
