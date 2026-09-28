import { useCallback, useEffect, useRef, useState } from 'react';

function formatDuration(totalSeconds) {
  const s = Math.max(0, Math.floor(totalSeconds || 0));
  const m = Math.floor(s / 60);
  const r = s % 60;
  return `${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`;
}

function IconMute({ muted }) {
  return muted ? (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2Z"
      />
      <path stroke="currentColor" strokeWidth="2" strokeLinecap="round" d="M4 4l16 16" />
    </svg>
  ) : (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2Z"
      />
    </svg>
  );
}

function IconSpeaker() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M3 10v4h3l4 3V7L6 10H3Zm11.5 2a2.5 2.5 0 0 0-1.5-2.3v4.6a2.5 2.5 0 0 0 1.5-2.3Zm-1.5-6.1v1.55A4.5 4.5 0 0 1 17 12a4.5 4.5 0 0 1-4 4.55V18.1A6 6 0 0 0 19 12a6 6 0 0 0-6-6.1Z"
      />
    </svg>
  );
}

function IconPlus() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z" />
    </svg>
  );
}

function IconScreen() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M4 5h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-5v2h2v2H7v-2h2v-2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm0 2v9h16V7H4Z"
      />
    </svg>
  );
}

function IconHold() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path fill="currentColor" d="M7 5h3v14H7V5Zm7 0h3v14h-3V5Z" />
    </svg>
  );
}

function IconPhone() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.4 11.4 0 0 0 .57 3.6 1 1 0 0 1-.25 1l-2.22 2.2Z"
      />
    </svg>
  );
}

function IconHangup() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"
        transform="rotate(135 12 12)"
      />
    </svg>
  );
}

function CallSlider({ direction, label, tone, onComplete }) {
  const trackRef = useRef(null);
  const [offset, setOffset] = useState(0);
  const offsetRef = useRef(0);
  const dragRef = useRef({ active: false, startX: 0, width: 0, done: false });

  const reset = useCallback(() => {
    offsetRef.current = 0;
    setOffset(0);
  }, []);

  const triggerComplete = useCallback(() => {
    const drag = dragRef.current;
    if (drag.done) return;
    drag.done = true;
    drag.active = false;
    onComplete?.();
    reset();
  }, [onComplete, reset]);

  const onPointerDown = (e) => {
    const track = trackRef.current;
    if (!track) return;
    dragRef.current = {
      active: true,
      done: false,
      startX: e.clientX,
      width: Math.max(80, track.clientWidth - 56),
    };
    try {
      e.currentTarget.setPointerCapture(e.pointerId);
    } catch {
      /* ignore */
    }
    e.preventDefault();
  };

  const onPointerMove = (e) => {
    const drag = dragRef.current;
    if (!drag.active || drag.done) return;
    const dx = e.clientX - drag.startX;
    const next =
      direction === 'right'
        ? Math.max(0, Math.min(drag.width, dx))
        : Math.min(0, Math.max(-drag.width, dx));
    offsetRef.current = next;
    setOffset(next);
    // Accept/decline as soon as the knob is dragged far enough � no need to release
    if (Math.abs(next) / drag.width >= 0.38) {
      triggerComplete();
      try {
        e.currentTarget.releasePointerCapture?.(e.pointerId);
      } catch {
        /* ignore */
      }
    }
  };

  const finish = (e) => {
    const drag = dragRef.current;
    if (!drag.active || drag.done) return;
    drag.active = false;
    if (Math.abs(offsetRef.current) / drag.width >= 0.38) {
      triggerComplete();
    } else {
      reset();
    }
    try {
      e.currentTarget.releasePointerCapture?.(e.pointerId);
    } catch {
      /* ignore */
    }
  };

  return (
    <div ref={trackRef} className={`erp-call-dash-slider erp-call-dash-slider--${tone}`}>
      <span className="erp-call-dash-slider-label">{label}</span>
      <button
        type="button"
        className="erp-call-dash-slider-knob"
        style={{ transform: `translateX(${offset}px)` }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={finish}
        onPointerCancel={finish}
        aria-label={label}
      >
        <IconPhone />
      </button>
    </div>
  );
}

function ControlButton({ label, active, disabled, onClick, children }) {
  return (
    <button
      type="button"
      className={`erp-call-dash-ctrl${active ? ' is-active' : ''}${disabled ? ' is-disabled' : ''}`}
      onClick={disabled ? undefined : onClick}
      disabled={disabled}
      aria-label={label}
      title={disabled ? 'Not available yet' : label}
    >
      <span className="erp-call-dash-ctrl-icon">{children}</span>
      <span className="erp-call-dash-ctrl-label">{label}</span>
    </button>
  );
}

function IconWinMin() {
  return (
    <svg viewBox="0 0 12 12" aria-hidden="true">
      <path fill="currentColor" d="M2 6.5h8v1.2H2z" />
    </svg>
  );
}

function IconWinMax() {
  return (
    <svg viewBox="0 0 12 12" aria-hidden="true">
      <path fill="none" stroke="currentColor" strokeWidth="1.2" d="M2.5 2.5h7v7h-7z" />
    </svg>
  );
}

function IconWinRestore() {
  return (
    <svg viewBox="0 0 12 12" aria-hidden="true">
      <path fill="none" stroke="currentColor" strokeWidth="1.1" d="M3.5 4h5.5v5.5H3.5z" />
      <path fill="none" stroke="currentColor" strokeWidth="1.1" d="M2.5 2.5H8V4M2.5 2.5V8H4" />
    </svg>
  );
}

function IconWinClose() {
  return (
    <svg viewBox="0 0 12 12" aria-hidden="true">
      <path
        fill="currentColor"
        d="M2.4 1.6 6 5.2l3.6-3.6.8.8L6.8 6l3.6 3.6-.8.8L6 6.8l-3.6 3.6-.8-.8L5.2 6 1.6 2.4z"
      />
    </svg>
  );
}

function clampPhonePos(x, y, w, h) {
  const maxX = Math.max(8, window.innerWidth - w - 8);
  const maxY = Math.max(8, window.innerHeight - h - 8);
  return {
    x: Math.max(8, Math.min(x, maxX)),
    y: Math.max(8, Math.min(y, maxY)),
  };
}

export default function CallDashboard({
  mode,
  peerName,
  status,
  muted,
  held,
  speakerOn,
  sharingScreen,
  remoteVideoStream,
  addCallOpen,
  addCallUsers,
  addCallLoading,
  onAccept,
  onReject,
  onHangup,
  onToggleMute,
  onToggleHold,
  onToggleSpeaker,
  onToggleScreenShare,
  onOpenAddCall,
  onCloseAddCall,
  onAddCallUser,
}) {
  const [seconds, setSeconds] = useState(0);
  const [winState, setWinState] = useState('normal'); // normal | minimized | maximized
  const [pos, setPos] = useState(() => ({
    x: typeof window !== 'undefined' ? Math.max(8, window.innerWidth - 320) : 40,
    y: typeof window !== 'undefined' ? Math.max(8, window.innerHeight - 580) : 40,
  }));
  const videoRef = useRef(null);
  const dragRef = useRef({ active: false, moved: false, ox: 0, oy: 0, startX: 0, startY: 0 });
  const name = String(peerName || 'User').trim() || 'User';

  useEffect(() => {
    setSeconds(0);
    if (status !== 'active') return undefined;
    const t = window.setInterval(() => setSeconds((v) => v + 1), 1000);
    return () => window.clearInterval(t);
  }, [status, mode]);

  useEffect(() => {
    const el = videoRef.current;
    if (!el) return;
    el.srcObject = remoteVideoStream || null;
    if (remoteVideoStream) el.play().catch(() => {});
  }, [remoteVideoStream]);

  useEffect(() => {
    const onResize = () => {
      setPos((p) => {
        const w = winState === 'maximized' ? Math.min(420, window.innerWidth - 24) : 300;
        const h = winState === 'minimized' ? 56 : winState === 'maximized' ? Math.min(720, window.innerHeight - 24) : 520;
        return clampPhonePos(p.x, p.y, w, h);
      });
    };
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, [winState]);

  const statusText =
    mode === 'incoming'
      ? 'Incoming VoIP call'
      : status === 'active'
        ? formatDuration(seconds)
        : 'Calling via ERP';

  const onTitlePointerDown = (e) => {
    if (e.button != null && e.button !== 0) return;
    if (e.target.closest?.('.erp-call-win-btn')) return;
    if (winState === 'maximized') return;
    dragRef.current = {
      active: true,
      moved: false,
      ox: e.clientX - pos.x,
      oy: e.clientY - pos.y,
      startX: e.clientX,
      startY: e.clientY,
    };
    try {
      e.currentTarget.setPointerCapture(e.pointerId);
    } catch {
      /* ignore */
    }
    e.preventDefault();
  };

  const onTitlePointerMove = (e) => {
    const drag = dragRef.current;
    if (!drag.active) return;
    if (!drag.moved && Math.hypot(e.clientX - drag.startX, e.clientY - drag.startY) > 4) {
      drag.moved = true;
    }
    if (!drag.moved) return;
    const w = winState === 'minimized' ? 260 : 300;
    const h = winState === 'minimized' ? 56 : 520;
    setPos(clampPhonePos(e.clientX - drag.ox, e.clientY - drag.oy, w, h));
  };

  const onTitlePointerUp = (e) => {
    const drag = dragRef.current;
    if (!drag.active) return;
    drag.active = false;
    try {
      e.currentTarget.releasePointerCapture?.(e.pointerId);
    } catch {
      /* ignore */
    }
  };

  const closeWindow = () => {
    if (mode === 'incoming') onReject?.();
    else onHangup?.();
  };

  const toggleMaximize = () => {
    setWinState((s) => (s === 'maximized' ? 'normal' : 'maximized'));
  };

  const minimize = () => setWinState('minimized');
  const restoreFromMini = () => setWinState('normal');

  if (winState === 'minimized') {
    return (
      <section
        className="erp-call-phone erp-call-phone--mini"
        style={{ left: pos.x, top: pos.y, right: 'auto', bottom: 'auto' }}
        role="dialog"
        aria-label="Call minimized"
      >
        <div
          className="erp-call-phone-mini"
          onPointerDown={onTitlePointerDown}
          onPointerMove={onTitlePointerMove}
          onPointerUp={onTitlePointerUp}
          onPointerCancel={onTitlePointerUp}
        >
          <button type="button" className="erp-call-phone-mini-main" onClick={restoreFromMini}>
            <span className="erp-call-phone-mini-name">{name}</span>
            <span className="erp-call-phone-mini-status">{statusText}</span>
          </button>
          <div className="erp-call-win-controls">
            <button type="button" className="erp-call-win-btn" aria-label="Restore" onClick={restoreFromMini} title="Restore">
              <IconWinRestore />
            </button>
            <button type="button" className="erp-call-win-btn erp-call-win-btn--close" aria-label="Close" onClick={closeWindow} title="Close">
              <IconWinClose />
            </button>
          </div>
        </div>
      </section>
    );
  }

  return (
    <section
      className={`erp-call-phone${winState === 'maximized' ? ' erp-call-phone--max' : ''}`}
      style={
        winState === 'maximized'
          ? undefined
          : { left: pos.x, top: pos.y, right: 'auto', bottom: 'auto' }
      }
      role="dialog"
      aria-label={mode === 'incoming' ? 'Incoming call' : 'Call'}
    >
      <div className="erp-call-phone-shell">
        <div className="erp-call-phone-bg" aria-hidden="true" />
        <div
          className="erp-call-win-bar"
          onPointerDown={onTitlePointerDown}
          onPointerMove={onTitlePointerMove}
          onPointerUp={onTitlePointerUp}
          onPointerCancel={onTitlePointerUp}
        >
          <span className="erp-call-win-title">Call � {name}</span>
          <div className="erp-call-win-controls">
            <button type="button" className="erp-call-win-btn" aria-label="Minimize" title="Minimize" onClick={minimize}>
              <IconWinMin />
            </button>
            <button
              type="button"
              className="erp-call-win-btn"
              aria-label={winState === 'maximized' ? 'Restore' : 'Maximize'}
              title={winState === 'maximized' ? 'Restore' : 'Maximize'}
              onClick={toggleMaximize}
            >
              {winState === 'maximized' ? <IconWinRestore /> : <IconWinMax />}
            </button>
            <button type="button" className="erp-call-win-btn erp-call-win-btn--close" aria-label="Close" title="Close" onClick={closeWindow}>
              <IconWinClose />
            </button>
          </div>
        </div>
        <div className="erp-call-phone-inner">
          <header className="erp-call-dash-hero">
            <p className="erp-call-dash-name">{name}</p>
            <p className="erp-call-dash-status">{statusText}</p>
          </header>

          {remoteVideoStream ? (
            <div className="erp-call-phone-video-wrap">
              <video ref={videoRef} className="erp-call-phone-video" autoPlay playsInline muted={false} />
            </div>
          ) : null}

          {mode === 'incoming' ? (
            <div className="erp-call-dash-incoming">
              <CallSlider direction="right" label="Slide right to answer" tone="accept" onComplete={onAccept} />
              <CallSlider direction="left" label="Slide left to decline" tone="reject" onComplete={onReject} />
            </div>
          ) : addCallOpen ? (
            <div className="erp-call-phone-add">
              <div className="erp-call-phone-add-head">
                <p>Add person</p>
                <button type="button" onClick={onCloseAddCall} aria-label="Close">
                  &times;
                </button>
              </div>
              <div className="erp-call-phone-add-list">
                {addCallLoading ? <div className="erp-call-phone-add-empty">Loading...</div> : null}
                {!addCallLoading && (!addCallUsers || addCallUsers.length === 0) ? (
                  <div className="erp-call-phone-add-empty">No people found.</div>
                ) : null}
                {(addCallUsers || []).map((u) => (
                  <button
                    key={u.id}
                    type="button"
                    className="erp-call-phone-add-item"
                    onClick={() => onAddCallUser?.(u)}
                  >
                    <span className="erp-call-phone-add-avatar">{String(u.first_name || '?').slice(0, 1)}</span>
                    <span>
                      <strong>{u.first_name}</strong>
                      <small>{u.online ? 'Online' : 'Away'}</small>
                    </span>
                  </button>
                ))}
              </div>
            </div>
          ) : (
            <div className="erp-call-dash-active">
              <div className="erp-call-dash-grid">
                <ControlButton label={muted ? 'Unmute' : 'Mute'} active={muted} onClick={onToggleMute}>
                  <IconMute muted={muted} />
                </ControlButton>
                <ControlButton
                  label={sharingScreen ? 'Stop Share' : 'Share'}
                  active={sharingScreen}
                  onClick={onToggleScreenShare}
                >
                  <IconScreen />
                </ControlButton>
                <ControlButton label="Speaker" active={speakerOn} onClick={onToggleSpeaker}>
                  <IconSpeaker />
                </ControlButton>
                <ControlButton label="Add Call" onClick={onOpenAddCall}>
                  <IconPlus />
                </ControlButton>
                <ControlButton label="Video" disabled>
                  <IconScreen />
                </ControlButton>
                <ControlButton label={held ? 'Resume' : 'Hold'} active={held} onClick={onToggleHold}>
                  <IconHold />
                </ControlButton>
              </div>
              {held ? <p className="erp-call-dash-hold-note">Call on hold</p> : null}
              <button type="button" className="erp-call-dash-hangup" onClick={onHangup} aria-label="Hang up">
                <IconHangup />
              </button>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
