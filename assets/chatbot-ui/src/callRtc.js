const ICE_SERVERS = [{ urls: 'stun:stun.l.google.com:19302' }];

export function resolveWebrtcCallUrl() {
  const cfg = window.__CHATBOT__ || {};
  if (cfg.webrtcCallUrl) return cfg.webrtcCallUrl;

  const base = String(cfg.appBase || '/');
  try {
    const originBase = base.startsWith('http')
      ? base.replace(/\/?$/, '/')
      : `${window.location.origin}${base.startsWith('/') ? base : `/${base}`}`.replace(/\/?$/, '/');
    return new URL('webrtc_call.php', originBase).pathname;
  } catch {
    const api = cfg.apiUrl || 'chatbot_api.php';
    if (typeof api === 'string' && api.includes('/')) {
      return api.replace(/[^/]+$/, 'webrtc_call.php');
    }
    return 'webrtc_call.php';
  }
}

async function postAction(action, payload = {}) {
  const ctrl = new AbortController();
  const timer = window.setTimeout(() => ctrl.abort(), 10000);
  try {
    const res = await fetch(resolveWebrtcCallUrl(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ action, ...payload }),
      signal: ctrl.signal,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json?.ok) {
      throw new Error(json?.error || 'Call request failed');
    }
    return json;
  } catch (err) {
    if (err?.name === 'AbortError') {
      throw new Error('Call request timed out. Try again.');
    }
    throw err;
  } finally {
    window.clearTimeout(timer);
  }
}

function stopTracks(stream) {
  if (!stream) return;
  stream.getTracks().forEach((t) => {
    try {
      t.stop();
    } catch {
      /* ignore */
    }
  });
}

/**
 * Multi-leg WebRTC call controller (1 primary + optional extra peers).
 */
export function createCallController(handlers = {}) {
  /** @type {Map<number, {callId:number, peerId:number, role:string, pc:RTCPeerConnection|null, sinceSignalId:number, makingOffer:boolean, peerName:string}>} */
  const legs = new Map();
  const dismissedIds = new Set();
  let localStream = null;
  let screenStream = null;
  let screenTrack = null;
  let pollTimer = null;
  let heartbeatTimer = null;
  let disposed = false;
  let ending = false;
  let callEpoch = 0;
  let suppressUi = false;
  let mutedFlag = false;
  let heldFlag = false;

  const emit = (name, payload) => {
    if (suppressUi && (name === 'incoming' || name === 'callStatus')) return;
    const fn = handlers[name];
    if (typeof fn === 'function') fn(payload);
  };

  function applyOutgoingAudio() {
    if (!localStream) return;
    const enabled = !mutedFlag && !heldFlag;
    localStream.getAudioTracks().forEach((t) => {
      t.enabled = enabled;
    });
  }

  function primaryLeg() {
    for (const leg of legs.values()) return leg;
    return null;
  }

  function dismiss(id) {
    const n = Number(id) || 0;
    if (n > 0) dismissedIds.add(n);
  }

  async function ensureLocalAudio() {
    if (localStream) return localStream;
    if (typeof window !== 'undefined' && window.isSecureContext === false) {
      const err = new Error(
        'Calls require HTTPS on this device. Open the site with https:// and continue past the certificate warning.'
      );
      err.name = 'SecurityError';
      throw err;
    }
    try {
      localStream = await navigator.mediaDevices.getUserMedia({
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true,
        },
        video: false,
      });
    } catch (err) {
      const wrapped = new Error(
        err?.name === 'NotAllowedError' || err?.name === 'PermissionDeniedError'
          ? 'Microphone permission was denied. Allow the mic and try again.'
          : err?.name === 'NotFoundError'
            ? 'No microphone was found on this device.'
            : err?.message || 'Could not access microphone.'
      );
      wrapped.name = err?.name || 'Error';
      throw wrapped;
    }
    emit('localStream', localStream);
    return localStream;
  }

  function rebuildRemoteMix() {
    const mix = new MediaStream();
    for (const leg of legs.values()) {
      const receivers = leg.pc?.getReceivers?.() || [];
      receivers.forEach((r) => {
        if (r.track && r.track.readyState !== 'ended') {
          mix.addTrack(r.track);
        }
      });
    }
    emit('remoteStream', mix);
    const videoTrack = mix.getVideoTracks()[0] || null;
    emit('remoteVideo', videoTrack ? new MediaStream([videoTrack]) : null);
  }

  async function createPcForLeg(leg) {
    if (leg.pc) return leg.pc;
    await ensureLocalAudio();
    const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
    localStream.getTracks().forEach((track) => pc.addTrack(track, localStream));
    if (screenTrack && screenTrack.readyState === 'live') {
      pc.addTrack(screenTrack, screenStream || new MediaStream([screenTrack]));
    }

    pc.onicecandidate = (ev) => {
      if (!ev.candidate || !leg.callId || !leg.peerId) return;
      postAction('send_signal', {
        call_id: leg.callId,
        to_user_id: leg.peerId,
        signal_type: 'ice',
        signal_data: ev.candidate,
      }).catch(() => {});
    };

    pc.ontrack = () => {
      rebuildRemoteMix();
    };

    pc.onconnectionstatechange = () => {
      emit('connectionState', pc.connectionState);
    };

    leg.pc = pc;
    return pc;
  }

  async function sendOfferForLeg(leg) {
    if (!leg.pc || leg.makingOffer) return;
    leg.makingOffer = true;
    try {
      const offer = await leg.pc.createOffer();
      await leg.pc.setLocalDescription(offer);
      await postAction('send_signal', {
        call_id: leg.callId,
        to_user_id: leg.peerId,
        signal_type: 'offer',
        signal_data: offer,
      });
    } finally {
      leg.makingOffer = false;
    }
  }

  async function handleSignal(leg, signal) {
    if (!signal || disposed || !leg) return;
    const type = signal.signal_type;
    const data = signal.signal_data;

    if (type === 'hangup') {
      dismiss(leg.callId);
      await removeLeg(leg.callId, false);
      if (legs.size === 0) {
        emit('ended', { reason: 'peer_hangup' });
        await cleanupMedia(false);
      } else {
        emit(
          'callStatus',
          summarizeStatus(primaryLeg())
        );
      }
      return;
    }

    await createPcForLeg(leg);

    if (type === 'offer') {
      await leg.pc.setRemoteDescription(new RTCSessionDescription(data));
      const answer = await leg.pc.createAnswer();
      await leg.pc.setLocalDescription(answer);
      await postAction('send_signal', {
        call_id: leg.callId,
        to_user_id: leg.peerId,
        signal_type: 'answer',
        signal_data: answer,
      });
    } else if (type === 'answer') {
      if (leg.pc.signalingState === 'have-local-offer') {
        await leg.pc.setRemoteDescription(new RTCSessionDescription(data));
      }
    } else if (type === 'ice' && data) {
      try {
        await leg.pc.addIceCandidate(new RTCIceCandidate(data));
      } catch {
        /* ignore race */
      }
    }
  }

  function summarizeStatus(leg) {
    if (!leg) return null;
    return {
      id: leg.callId,
      status: leg.status || 'ringing',
      role: leg.role,
      peer_id: leg.peerId,
      peer_name: leg.peerName || 'User',
      participants: [...legs.values()].map((l) => ({
        id: l.callId,
        peer_id: l.peerId,
        peer_name: l.peerName || 'User',
        status: l.status || 'ringing',
      })),
    };
  }

  async function removeLeg(callId, notifyServer) {
    const leg = legs.get(callId);
    if (!leg) return;
    if (notifyServer) {
      try {
        await postAction('hangup', { call_id: callId });
      } catch {
        /* ignore */
      }
    }
    if (leg.pc) {
      try {
        leg.pc.close();
      } catch {
        /* ignore */
      }
    }
    legs.delete(callId);
    dismiss(callId);
    rebuildRemoteMix();
  }

  async function cleanupMedia(stopHeartbeat = true) {
    for (const leg of [...legs.values()]) {
      if (leg.pc) {
        try {
          leg.pc.close();
        } catch {
          /* ignore */
        }
      }
      dismiss(leg.callId);
    }
    legs.clear();
    stopTracks(localStream);
    localStream = null;
    mutedFlag = false;
    heldFlag = false;
    stopScreenShareInternal(false);
    if (stopHeartbeat) {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
      if (heartbeatTimer) {
        clearInterval(heartbeatTimer);
        heartbeatTimer = null;
      }
    }
  }

  function stopScreenShareInternal(renegotiate) {
    if (screenTrack) {
      try {
        screenTrack.stop();
      } catch {
        /* ignore */
      }
    }
    stopTracks(screenStream);
    screenStream = null;
    screenTrack = null;
    emit('screenShare', { active: false });
    if (renegotiate) {
      for (const leg of legs.values()) {
        if (!leg.pc) continue;
        const sender = leg.pc.getSenders().find((s) => s.track && s.track.kind === 'video');
        if (sender) {
          try {
            leg.pc.removeTrack(sender);
          } catch {
            /* ignore */
          }
        }
        if (leg.role === 'caller' || leg.pc.signalingState === 'stable') {
          sendOfferForLeg(leg).catch(() => {});
        }
      }
    }
  }

  async function pollOnce() {
    if (disposed || ending) return;
    const callIds = [...legs.keys()];
    const sinceMap = {};
    for (const leg of legs.values()) {
      sinceMap[leg.callId] = leg.sinceSignalId;
    }

    const json = await postAction('poll', {
      call_id: callIds[0] || 0,
      call_ids: callIds,
      since_signal_id: callIds[0] ? legs.get(callIds[0])?.sinceSignalId || 0 : 0,
      since_map: sinceMap,
    });

    if (json.incoming?.id && !dismissedIds.has(json.incoming.id) && !legs.has(json.incoming.id)) {
      emit('incoming', json.incoming);
    }

    const callList = Array.isArray(json.calls) ? json.calls : json.call ? [json.call] : [];
    for (const call of callList) {
      if (!call?.id) continue;
      if (['ended', 'rejected', 'missed'].includes(call.status)) {
        dismiss(call.id);
        await removeLeg(call.id, false);
        continue;
      }
      const leg = legs.get(call.id);
      if (leg) {
        leg.status = call.status;
        leg.peerName = call.peer_name || leg.peerName;
        if (call.status === 'active' && leg.role === 'caller' && leg.pc && !leg.pc.localDescription) {
          try {
            await sendOfferForLeg(leg);
          } catch {
            /* ignore offer race */
          }
        }
      }
    }

    if (legs.size === 0 && callList.some((c) => ['ended', 'rejected', 'missed'].includes(c.status))) {
      emit('ended', { reason: callList[0]?.status || 'ended' });
      await cleanupMedia(false);
      return;
    }

    if (legs.size > 0) {
      emit('callStatus', summarizeStatus(primaryLeg()));
    }

    const signals = json.signals || [];
    for (const signal of signals) {
      const leg = legs.get(signal.call_id);
      if (!leg) continue;
      leg.sinceSignalId = Math.max(leg.sinceSignalId, signal.id || 0);
      await handleSignal(leg, signal);
    }
  }

  function startPolling() {
    if (pollTimer) return;
    pollTimer = window.setInterval(() => {
      pollOnce().catch(() => {});
    }, 1100);
    pollOnce().catch(() => {});
  }

  function startHeartbeat() {
    if (heartbeatTimer) return;
    const beat = () => postAction('heartbeat').catch(() => {});
    beat();
    heartbeatTimer = window.setInterval(beat, 15000);
  }

  async function attachLegFromInvite(jsonCall, roleName) {
    const leg = {
      callId: jsonCall.id,
      peerId: jsonCall.peer_id,
      role: roleName,
      pc: null,
      sinceSignalId: 0,
      makingOffer: false,
      peerName: jsonCall.peer_name || 'User',
      status: jsonCall.status || 'ringing',
    };
    legs.set(leg.callId, leg);
    emit('callStatus', summarizeStatus(leg));
    startPolling();
    await createPcForLeg(leg);
    return jsonCall;
  }

  async function invite(calleeId, { preserveActive = false } = {}) {
    if (!preserveActive) {
      suppressUi = false;
    }
    const epoch = callEpoch;
    const json = await postAction('invite', {
      callee_id: calleeId,
      preserve_active: preserveActive ? 1 : 0,
    });

    // User cancelled while the invite request was in flight
    if (epoch !== callEpoch || ending || disposed) {
      dismiss(json.call.id);
      try {
        await postAction('hangup', { call_id: json.call.id });
      } catch {
        /* ignore */
      }
      return null;
    }

    try {
      return await attachLegFromInvite(json.call, 'caller');
    } catch (err) {
      try {
        await postAction('hangup', { call_id: json.call.id });
      } catch {
        /* ignore */
      }
      await removeLeg(json.call.id, false);
      if (legs.size === 0) {
        emit('ended', { reason: 'error' });
        await cleanupMedia(false);
      }
      throw err;
    }
  }

  async function addCall(calleeId) {
    if (legs.size === 0) {
      throw new Error('Start a call before adding another person.');
    }
    if (legs.size >= 3) {
      throw new Error('This call already has the maximum number of people.');
    }
    return invite(calleeId, { preserveActive: true });
  }

  async function accept(incomingCall) {
    if (!incomingCall?.id) throw new Error('Invalid incoming call.');
    if (dismissedIds.has(incomingCall.id)) {
      throw new Error('This call already ended.');
    }
    suppressUi = false;
    const epoch = callEpoch;
    await postAction('accept', { call_id: incomingCall.id });
    if (epoch !== callEpoch || ending || disposed) {
      dismiss(incomingCall.id);
      try {
        await postAction('hangup', { call_id: incomingCall.id });
      } catch {
        /* ignore */
      }
      return;
    }
    const call = {
      id: incomingCall.id,
      peer_id: incomingCall.peer_id,
      peer_name: incomingCall.peer_name,
      status: 'active',
    };
    try {
      await attachLegFromInvite(call, 'callee');
      if (epoch !== callEpoch) {
        await removeLeg(call.id, true);
        return;
      }
      const leg = legs.get(call.id);
      if (leg) leg.status = 'active';
      emit('callStatus', summarizeStatus(leg));
    } catch (err) {
      try {
        await postAction('hangup', { call_id: incomingCall.id });
      } catch {
        /* ignore */
      }
      dismiss(incomingCall.id);
      emit('ended', { reason: 'error' });
      throw err;
    }
  }

  async function reject(incomingCall) {
    const id = incomingCall?.id;
    suppressUi = true;
    callEpoch += 1;
    if (id) {
      dismiss(id);
      try {
        await postAction('reject', { call_id: id });
      } catch {
        /* ignore */
      }
    }
    emit('incomingCleared');
    emit('ended', { reason: 'rejected' });
  }

  async function hangup() {
    callEpoch += 1;
    suppressUi = true;
    if (ending) {
      return;
    }
    ending = true;
    const ids = [...legs.keys()];
    ids.forEach((id) => dismiss(id));
    // Tell server first so the peer is notified before we tear down.
    await Promise.all(
      ids.map((id) =>
        postAction('hangup', { call_id: id }).catch(() => {})
      )
    );
    await cleanupMedia(false);
    emit('ended', { reason: 'hangup' });
    ending = false;
  }

  function setMuted(muted) {
    mutedFlag = Boolean(muted);
    applyOutgoingAudio();
  }

  function setHeld(held) {
    heldFlag = Boolean(held);
    applyOutgoingAudio();
    emit('held', { active: heldFlag });
  }

  async function startScreenShare() {
    if (typeof window !== 'undefined' && window.isSecureContext === false) {
      throw new Error('Screen share requires HTTPS.');
    }
    if (!legs.size) throw new Error('Join a call before sharing your screen.');
    const stream = await navigator.mediaDevices.getDisplayMedia({
      video: { frameRate: 15 },
      audio: false,
    });
    const track = stream.getVideoTracks()[0];
    if (!track) throw new Error('No screen track available.');
    stopScreenShareInternal(false);
    screenStream = stream;
    screenTrack = track;
    track.onended = () => {
      stopScreenShareInternal(true);
    };
    emit('screenShare', { active: true });

    for (const leg of legs.values()) {
      await createPcForLeg(leg);
      const existing = leg.pc.getSenders().find((s) => s.track && s.track.kind === 'video');
      if (existing) {
        await existing.replaceTrack(track);
      } else {
        leg.pc.addTrack(track, stream);
      }
      await sendOfferForLeg(leg);
    }
  }

  async function stopScreenShare() {
    stopScreenShareInternal(true);
  }

  function dispose() {
    disposed = true;
    ending = true;
    const ids = [...legs.keys()];
    ids.forEach((id) => dismiss(id));
    Promise.all(ids.map((id) => postAction('hangup', { call_id: id }).catch(() => {}))).finally(() => {
      cleanupMedia(true);
    });
  }

  return {
    startHeartbeat,
    startPolling,
    invite,
    addCall,
    accept,
    reject,
    hangup,
    setMuted,
    setHeld,
    startScreenShare,
    stopScreenShare,
    dispose,
    pollOnce,
    dismiss,
    getCallEpoch: () => callEpoch,
  };
}
