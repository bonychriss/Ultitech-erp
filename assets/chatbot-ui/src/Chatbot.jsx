import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { localSearch } from './guides';
import { createCallController } from './callRtc';
import CallDashboard from './CallDashboard';

const STORAGE_KEY_DESKTOP = 'chatbot_pos_v3';
const STORAGE_KEY_MOBILE = 'chatbot_pos_mobile_v3';
const DRAG_THRESHOLD = 6;
const FAB_SIZE = 48;

function clamp(n, min, max) {
  return Math.max(min, Math.min(n, max));
}

function isMobileViewport() {
  return typeof window !== 'undefined' && window.matchMedia('(max-width: 767.98px)').matches;
}

function storageKey() {
  return isMobileViewport() ? STORAGE_KEY_MOBILE : STORAGE_KEY_DESKTOP;
}

function mobileBottomClearance() {
  if (!isMobileViewport()) return 20;
  // Floating mobile nav (~54px) + inset + gap
  return 82;
}

function clampPos(x, y) {
  const maxX = Math.max(0, window.innerWidth - FAB_SIZE);
  const maxY = Math.max(0, window.innerHeight - FAB_SIZE - (isMobileViewport() ? mobileBottomClearance() - 20 : 0));
  return {
    x: clamp(x, 0, maxX),
    y: clamp(y, 0, Math.max(0, maxY)),
  };
}

function secureUltimateLoginUrl() {
  if (typeof window === 'undefined') return 'https://192.168.1.9/Ultitech-erp/ultimate/login.php';
  const { host, pathname } = window.location;
  const m = pathname.match(/^(.*?\/)?ultimate(?:\/|$)/i);
  if (m) {
    const prefix = String(m[1] || '/').replace(/\/$/, '');
    return `https://${host}${prefix}/ultimate/login.php`;
  }
  return `https://${host}/Ultitech-erp/ultimate/login.php`;
}

function sidebarBox() {
  if (typeof document === 'undefined') return null;
  const el = document.querySelector('.sidebar-container');
  if (!el) return null;
  const box = el.getBoundingClientRect();
  if (box.width < 64 || box.width > 420) return null;
  return box;
}

function defaultPos() {
  const box = sidebarBox();
  if (box && !isMobileViewport()) {
    return clampPos(box.right + 16, window.innerHeight - FAB_SIZE - 20);
  }
  return clampPos(16, window.innerHeight - FAB_SIZE - mobileBottomClearance());
}

function coversSidebarLabels(x) {
  const box = sidebarBox();
  if (!box || isMobileViewport()) return x < 96;
  return x < box.right + 8;
}

function isLegacyBottomRight(x, y) {
  const legacy = clampPos(
    window.innerWidth - FAB_SIZE - 16,
    window.innerHeight - FAB_SIZE - mobileBottomClearance()
  );
  return Math.abs(x - legacy.x) <= 28 && Math.abs(y - legacy.y) <= 28;
}

function readSavedPos() {
  try {
    const raw = localStorage.getItem(storageKey());
    if (!raw) return null;
    const pos = JSON.parse(raw);
    const x = parseFloat(pos?.left);
    const y = parseFloat(pos?.top);
    if (Number.isNaN(x) || Number.isNaN(y)) return null;
    const next = clampPos(x, y);
    // Desktop coords on a phone are usually off-screen — fall back to default
    if (isMobileViewport() && (x > window.innerWidth || y > window.innerHeight)) {
      return null;
    }
    // Old spots sat on the menu labels or the sidebar edge. Park it in the page gutter.
    if (isLegacyBottomRight(next.x, next.y) || coversSidebarLabels(next.x)) return null;
    return next;
  } catch {
    return null;
  }
}

function persistPos(point) {
  try {
    localStorage.setItem(
      storageKey(),
      JSON.stringify({ left: `${point.x}px`, top: `${point.y}px` })
    );
  } catch {
    /* ignore */
  }
}

function resolveApiUrl() {
  const cfg = window.__CHATBOT__ || {};
  if (cfg.apiUrl) return cfg.apiUrl;
  const path = window.location.pathname || '';
  if (path.includes('/admin/') || path.includes('/employee/')) return '../chatbot_api.php';
  return 'chatbot_api.php';
}

function aiAssistantHref() {
  const link = document.querySelector('a[href*="ai_assistant.php"]');
  if (link?.getAttribute('href')) return link.getAttribute('href');
  const path = window.location.pathname || '';
  if (path.includes('/admin/') || path.includes('/employee/')) return 'ai_assistant.php';
  return 'employee/ai_assistant.php';
}

function resolveCallUsersUrl() {
  const cfg = window.__CHATBOT__ || {};
  if (cfg.callUsersUrl) return cfg.callUsersUrl;
  const api = resolveApiUrl();
  return api.replace(/chatbot_api\.php(?:\?.*)?$/i, 'chatbot_call_users.php');
}

export default function Chatbot() {
  const panelId = useId();
  const bodyRef = useRef(null);
  const inputRef = useRef(null);
  const dockRef = useRef(null);
  const dragRef = useRef({
    active: false,
    moved: false,
    pointerId: null,
    startX: 0,
    startY: 0,
    offsetX: 0,
    offsetY: 0,
  });

  const [open, setOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [callOpen, setCallOpen] = useState(false);
  const [callUsers, setCallUsers] = useState([]);
  const [callLoading, setCallLoading] = useState(false);
  const [callError, setCallError] = useState('');
  const [callSearch, setCallSearch] = useState('');
  const [incomingCall, setIncomingCall] = useState(null);
  const [activeCall, setActiveCall] = useState(null);
  const [callBusy, setCallBusy] = useState(false);
  const [callMuted, setCallMuted] = useState(false);
  const [callHeld, setCallHeld] = useState(false);
  const [callSpeaker, setCallSpeaker] = useState(true);
  const [sharingScreen, setSharingScreen] = useState(false);
  const [remoteVideoStream, setRemoteVideoStream] = useState(null);
  const [addCallOpen, setAddCallOpen] = useState(false);
  const [addCallUsers, setAddCallUsers] = useState([]);
  const [addCallLoading, setAddCallLoading] = useState(false);
  const [callNotice, setCallNotice] = useState('');
  const [pos, setPos] = useState(() => readSavedPos() || defaultPos());
  const [dragging, setDragging] = useState(false);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [messages, setMessages] = useState([]);
  const remoteAudioRef = useRef(null);
  const callCtrlRef = useRef(null);
  const dismissedIncomingRef = useRef(new Set());
  const callEpochRef = useRef(0);
  const suppressCallUiRef = useRef(false);

  const panelStyle = useMemo(() => {
    const width = Math.min(350, window.innerWidth - 24);
    const preferAbove = pos.y > window.innerHeight * 0.45;
    const left = clamp(pos.x + FAB_SIZE - width, 12, Math.max(12, window.innerWidth - width - 12));
    const top = preferAbove
      ? clamp(pos.y - 12 - 420, 12, Math.max(12, window.innerHeight - 120))
      : clamp(pos.y + FAB_SIZE + 12, 12, Math.max(12, window.innerHeight - 120));
    return {
      left: `${left}px`,
      top: preferAbove ? 'auto' : `${top}px`,
      bottom: preferAbove ? `${Math.max(12, window.innerHeight - pos.y + 12)}px` : 'auto',
      right: 'auto',
    };
  }, [pos, open, callOpen]);

  useEffect(() => {
    const sync = () => {
      setPos((prev) => {
        if (coversSidebarLabels(prev.x)) return defaultPos();
        const next = clampPos(prev.x, prev.y);
        if (next.x === prev.x && next.y === prev.y) return prev;
        return next;
      });
    };
    sync();
    window.addEventListener('resize', sync);
    window.addEventListener('orientationchange', sync);
    return () => {
      window.removeEventListener('resize', sync);
      window.removeEventListener('orientationchange', sync);
    };
  }, []);

  useEffect(() => {
    if (!menuOpen) return undefined;
    const onDocPointer = (e) => {
      const dock = dockRef.current;
      if (!dock) return;
      if (e.target instanceof Node && dock.contains(e.target)) return;
      setMenuOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setMenuOpen(false);
    };
    document.addEventListener('pointerdown', onDocPointer, true);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onDocPointer, true);
      document.removeEventListener('keydown', onKey);
    };
  }, [menuOpen]);

  useEffect(() => {
    const ctrl = createCallController({
      remoteStream: (stream) => {
        const el = remoteAudioRef.current;
        if (el) {
          el.srcObject = stream;
          el.muted = !callSpeaker;
          el.volume = callSpeaker ? 1 : 0;
          el.play().catch(() => {});
        }
      },
      remoteVideo: (stream) => {
        setRemoteVideoStream(stream);
      },
      screenShare: ({ active } = {}) => {
        setSharingScreen(Boolean(active));
      },
      incoming: (call) => {
        if (suppressCallUiRef.current) return;
        if (!call?.id || dismissedIncomingRef.current.has(call.id)) return;
        setIncomingCall(call);
      },
      incomingCleared: () => {
        setIncomingCall(null);
      },
      callStatus: (call) => {
        if (suppressCallUiRef.current) return;
        if (!call || ['ended', 'rejected', 'missed'].includes(call.status)) return;
        if (call.id && dismissedIncomingRef.current.has(call.id)) return;
        setActiveCall(call);
        if (call.status === 'active') {
          setIncomingCall(null);
          setCallNotice('');
        }
      },
      ended: ({ reason } = {}) => {
        suppressCallUiRef.current = true;
        setIncomingCall(null);
        setActiveCall(null);
        setCallBusy(false);
        setCallMuted(false);
        setCallHeld(false);
        setCallSpeaker(true);
        setSharingScreen(false);
        setRemoteVideoStream(null);
        setAddCallOpen(false);
        if (remoteAudioRef.current) {
          remoteAudioRef.current.srcObject = null;
        }
        if (reason === 'rejected') setCallNotice('Call was declined.');
        else if (reason === 'missed') setCallNotice('Call was missed.');
        else if (reason === 'peer_hangup' || reason === 'ended' || reason === 'hangup') {
          setCallNotice('');
        } else setCallNotice('');
      },
    });
    callCtrlRef.current = ctrl;
    ctrl.startHeartbeat();
    ctrl.startPolling();
    return () => {
      ctrl.dispose();
      callCtrlRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- mount once
  }, []);

  useEffect(() => {
    if (!open) return;
    if (messages.length === 0) {
      setMessages([
        {
          id: 'intro',
          role: 'bot',
          text: 'Hi! Ask me anything about the system ? I use Ultimate Intelligence to answer.',
        },
      ]);
    }
    const t = window.setTimeout(() => inputRef.current?.focus(), 40);
    return () => window.clearTimeout(t);
  }, [open, messages.length]);

  useEffect(() => {
    const el = bodyRef.current;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
  }, [messages, busy]);

  const applyPos = useCallback((x, y) => {
    const next = clampPos(x, y);
    setPos(next);
    return next;
  }, []);

  const handleResults = useCallback((arr, fallbackMessage) => {
    if (!arr?.length) {
      setMessages((prev) => [
        ...prev,
        {
          id: `bot-${Date.now()}`,
          role: 'bot',
          text:
            fallbackMessage ||
            'I could not find an answer. Try rephrasing your question.',
        },
      ]);
      return;
    }

    setMessages((prev) => [
      ...prev,
      ...arr.map((guide, index) => {
        const isAi = Boolean(guide.is_ai) || guide.id === 'ai_answer' || guide.id === 'ai_fallback';
        const answer = String(guide.answer || guide.answer_short || '').trim();
        return {
          id: `bot-${Date.now()}-${index}`,
          role: 'bot',
          text: isAi || !guide.title ? answer : `${guide.title}: ${answer}`,
          isAi,
        };
      }),
    ]);
  }, []);

  const ask = useCallback(
    async (raw) => {
      const q = String(raw || '').trim();
      if (!q || busy) return;

      if (q.toLowerCase() === 'open ai assistant') {
        window.location.href = aiAssistantHref();
        return;
      }

      setBusy(true);
      setInput('');
      setMessages((prev) => [...prev, { id: `user-${Date.now()}`, role: 'user', text: q }]);

      try {
        const res = await fetch(`${resolveApiUrl()}?q=${encodeURIComponent(q)}`, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('bad status');
        const json = await res.json();
        if (json?.results?.length) {
          handleResults(json.results);
        } else {
          handleResults([], json?.message || null);
        }
      } catch {
        // Offline / API failure ? local guides only as last resort
        const local = localSearch(q);
        if (local.length) {
          handleResults(local);
        } else {
          handleResults([], 'Could not reach the assistant. Check your connection and try again.');
        }
      } finally {
        setBusy(false);
      }
    },
    [busy, handleResults]
  );

  const onPointerDown = (e) => {
    if (e.button != null && e.button !== 0) return;
    const rect = e.currentTarget.getBoundingClientRect();
    dragRef.current = {
      active: true,
      moved: false,
      pointerId: e.pointerId,
      startX: e.clientX,
      startY: e.clientY,
      offsetX: e.clientX - rect.left,
      offsetY: e.clientY - rect.top,
    };
    setDragging(true);
    try {
      e.currentTarget.setPointerCapture(e.pointerId);
    } catch {
      /* ignore */
    }
    e.preventDefault();
  };

  const onPointerMove = (e) => {
    const drag = dragRef.current;
    if (!drag.active || drag.pointerId !== e.pointerId) return;
    const dx = e.clientX - drag.startX;
    const dy = e.clientY - drag.startY;
    if (!drag.moved && Math.hypot(dx, dy) > DRAG_THRESHOLD) {
      drag.moved = true;
      setMenuOpen(false);
    }
    if (!drag.moved) return;
    applyPos(e.clientX - drag.offsetX, e.clientY - drag.offsetY);
    e.preventDefault();
  };

  const onPointerUp = (e) => {
    const drag = dragRef.current;
    if (!drag.active || drag.pointerId !== e.pointerId) return;
    drag.active = false;
    setDragging(false);

    if (drag.moved) {
      setPos((current) => {
        persistPos(current);
        return current;
      });
      return;
    }

    setMenuOpen((value) => !value);
  };

  const openHelp = () => {
    setMenuOpen(false);
    setCallOpen(false);
    setOpen(true);
  };

  const openCallDirectory = async () => {
    setMenuOpen(false);
    setOpen(false);
    setCallOpen(true);
    setCallError('');
    setCallSearch('');
    setCallNotice('');
    setCallLoading(true);
    try {
      const res = await fetch(resolveCallUsersUrl(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) throw new Error('bad status');
      const json = await res.json();
      if (!json?.ok) throw new Error(json?.error || 'Could not load users');
      setCallUsers(Array.isArray(json.users) ? json.users : []);
    } catch (err) {
      setCallUsers([]);
      setCallError(err?.message || 'Could not load users');
    } finally {
      setCallLoading(false);
    }
  };

  const startOnlineCall = async (user) => {
    if (!user?.id || callBusy || activeCall || incomingCall) return;
    if (typeof window !== 'undefined' && window.isSecureContext === false) {
      setCallNotice(
        'This page is not HTTPS, so the microphone is blocked. On phone open ' +
          secureUltimateLoginUrl() +
          ' (tap Advanced ? Proceed), then call again.'
      );
      return;
    }
    suppressCallUiRef.current = false;
    const epoch = callEpochRef.current;
    setCallBusy(true);
    setCallNotice('Calling ' + user.first_name + '...');
    setCallOpen(false);
    setActiveCall({
      id: null,
      peer_id: user.id,
      peer_name: user.first_name,
      status: 'ringing',
      role: 'caller',
    });
    try {
      const call = await callCtrlRef.current?.invite(user.id);
      if (epoch !== callEpochRef.current || suppressCallUiRef.current) {
        return;
      }
      if (call) {
        setActiveCall({
          ...call,
          peer_name: call.peer_name || user.first_name,
        });
      } else {
        setActiveCall(null);
        setCallBusy(false);
      }
    } catch (err) {
      if (epoch !== callEpochRef.current) return;
      setCallNotice(err?.message || 'Could not start call.');
      setCallBusy(false);
      setActiveCall(null);
    }
  };

  const acceptIncoming = async () => {
    if (!incomingCall?.id) return;
    if (typeof window !== 'undefined' && window.isSecureContext === false) {
      setCallNotice(
        'Cannot answer on HTTP. Open ' +
          secureUltimateLoginUrl() +
          ' on this phone (Advanced ? Proceed), then try again.'
      );
      dismissedIncomingRef.current.add(incomingCall.id);
      setIncomingCall(null);
      callCtrlRef.current?.reject(incomingCall).catch(() => {});
      return;
    }
    const incoming = incomingCall;
    suppressCallUiRef.current = false;
    setCallBusy(true);
    // Switch UI to in-call immediately so the user is not stuck on the slider screen
    setActiveCall({
      id: incoming.id,
      peer_id: incoming.peer_id,
      peer_name: incoming.peer_name || 'User',
      status: 'active',
      role: 'callee',
    });
    setIncomingCall(null);
    try {
      await callCtrlRef.current?.accept(incoming);
    } catch (err) {
      if (incoming?.id) dismissedIncomingRef.current.add(incoming.id);
      setCallNotice(err?.message || 'Could not answer call.');
      setCallBusy(false);
      setIncomingCall(null);
      setActiveCall(null);
    }
  };

  const rejectIncoming = () => {
    callEpochRef.current += 1;
    suppressCallUiRef.current = true;
    const incoming = incomingCall;
    if (incoming?.id) {
      dismissedIncomingRef.current.add(incoming.id);
      callCtrlRef.current?.dismiss?.(incoming.id);
    }
    setIncomingCall(null);
    setCallBusy(false);
    if (incoming?.id) {
      callCtrlRef.current?.reject(incoming).catch(() => {});
    }
  };

  const hangUpCall = () => {
    callEpochRef.current += 1;
    suppressCallUiRef.current = true;
    const incoming = incomingCall;
    const active = activeCall;
    if (incoming?.id) {
      dismissedIncomingRef.current.add(incoming.id);
      callCtrlRef.current?.dismiss?.(incoming.id);
    }
    if (active?.id) {
      dismissedIncomingRef.current.add(active.id);
      callCtrlRef.current?.dismiss?.(active.id);
    }
    // If we were only ringing inbound and never accepted, reject so it stops on server too.
    if (incoming?.id && !active?.id) {
      callCtrlRef.current?.reject(incoming).catch(() => {});
    } else {
      callCtrlRef.current?.hangup().catch(() => {});
    }
    setActiveCall(null);
    setIncomingCall(null);
    setCallBusy(false);
    setCallMuted(false);
    setCallHeld(false);
    setCallSpeaker(true);
    setSharingScreen(false);
    setRemoteVideoStream(null);
    setAddCallOpen(false);
    setCallNotice('');
    if (remoteAudioRef.current) {
      remoteAudioRef.current.srcObject = null;
    }
  };

  const toggleMute = () => {
    if (callHeld) return;
    const next = !callMuted;
    setCallMuted(next);
    callCtrlRef.current?.setMuted(next);
  };

  const toggleHold = () => {
    const next = !callHeld;
    setCallHeld(next);
    callCtrlRef.current?.setHeld(next);
  };

  const toggleSpeaker = () => {
    const next = !callSpeaker;
    setCallSpeaker(next);
    const el = remoteAudioRef.current;
    if (el) {
      el.muted = !next;
      el.volume = next ? 1 : 0;
    }
  };

  const toggleScreenShare = async () => {
    try {
      if (sharingScreen) {
        await callCtrlRef.current?.stopScreenShare();
        setSharingScreen(false);
      } else {
        await callCtrlRef.current?.startScreenShare();
        setSharingScreen(true);
      }
    } catch (err) {
      setCallNotice(err?.message || 'Screen share failed.');
    }
  };

  const openAddCall = async () => {
    setAddCallOpen(true);
    setAddCallLoading(true);
    try {
      const res = await fetch(resolveCallUsersUrl(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const json = await res.json();
      const list = Array.isArray(json?.users) ? json.users : [];
      const activePeer = activeCall?.peer_id;
      setAddCallUsers(list.filter((u) => u.id !== activePeer));
    } catch {
      setAddCallUsers([]);
    } finally {
      setAddCallLoading(false);
    }
  };

  const addCallUser = async (user) => {
    if (!user?.id) return;
    try {
      await callCtrlRef.current?.addCall(user.id);
      setAddCallOpen(false);
      setCallNotice('Calling ' + user.first_name + '...');
    } catch (err) {
      setCallNotice(err?.message || 'Could not add person to the call.');
    }
  };

  useEffect(() => {
    if (!activeCall && !incomingCall) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') hangUpCall();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [activeCall, incomingCall]);

  const filteredCallUsers = useMemo(() => {
    const q = callSearch.trim().toLowerCase();
    let list = callUsers;
    if (q) {
      list = callUsers.filter((u) => {
        const hay = [u.first_name, u.full_name, u.department, u.phone]
          .map((v) => String(v || '').toLowerCase())
          .join(' ');
        return hay.includes(q);
      });
    }
    return [...list].sort((a, b) => Number(!!b.online) - Number(!!a.online));
  }, [callUsers, callSearch]);

  return (
    <div
      className="erp-chatbot"
      data-open={open ? '1' : '0'}
      data-menu={menuOpen ? '1' : '0'}
      data-call={callOpen ? '1' : '0'}
    >
      <div
        ref={dockRef}
        className={`erp-chatbot-dock${menuOpen ? ' is-menu-open' : ''}${dragging ? ' is-dragging' : ''}${pos.x < window.innerWidth / 2 ? ' is-dock-left' : ''}`}
        style={{ left: pos.x, top: pos.y }}
      >
        <div className="erp-chatbot-speed" role="menu" aria-label="Support options">
          <button
            type="button"
            role="menuitem"
            className="erp-chatbot-speed-btn erp-chatbot-speed-btn--help"
            title="Help"
            aria-label="Help"
            onClick={(e) => {
              e.stopPropagation();
              openHelp();
            }}
            onPointerDown={(e) => e.stopPropagation()}
          >
            <svg viewBox="5.8 3.6 12.4 13.2" width="23" height="23" fill="none" aria-hidden="true">
              <path d="M7 10.5h10M8 10.5V9a4 4 0 0 1 8 0v1.5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
              <path d="M7 10.5v3.2A1.8 1.8 0 0 0 8.8 15.5H10" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
              <path d="M14.2 15.2h.2A2.6 2.6 0 0 0 17 12.6v-2" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            </svg>
          </button>
          <button
            type="button"
            role="menuitem"
            className="erp-chatbot-speed-btn erp-chatbot-speed-btn--call"
            title="Call"
            aria-label="Call"
            onClick={(e) => {
              e.stopPropagation();
              openCallDirectory();
            }}
            onPointerDown={(e) => e.stopPropagation()}
          >
            <svg viewBox="6.4 3.4 14.6 16" width="23" height="23" fill="none" aria-hidden="true">
              <path
                d="M8.2 4.8c.4-.4 1-.5 1.5-.3l2.1.8c.6.2 1 .8.9 1.4l-.3 2.1c-.1.5.1 1 .5 1.3l1.4 1.4c.3.4.8.6 1.3.5l2.1-.3c.6-.1 1.2.3 1.4.9l.8 2.1c.2.5.1 1.1-.3 1.5l-1.1 1.1c-.5.5-1.2.7-1.9.6-1.8-.3-3.9-1.5-5.9-3.5S7.8 11.3 7.5 9.5c-.1-.7.1-1.4.6-1.9l1.1-1.1z"
                stroke="currentColor"
                strokeWidth="1.6"
                strokeLinecap="round"
                strokeLinejoin="round"
              />
            </svg>
          </button>
        </div>

        <div
          role="button"
          tabIndex={0}
          className={`erp-chatbot-fab${dragging ? ' is-dragging' : ''}`}
          aria-label="Support"
          aria-expanded={menuOpen}
          aria-haspopup="menu"
          title="Support - click for options, drag to move"
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onPointerCancel={onPointerUp}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              setMenuOpen((value) => !value);
            }
            if (e.key === 'Escape') {
              setMenuOpen(false);
            }
          }}
        >
          <span className="erp-chatbot-fab-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="currentColor" d="M12 3.4 3.6 10.4V20a1.2 1.2 0 0 0 1.2 1.2h4.6v-5.5h5.2v5.5h4.6a1.2 1.2 0 0 0 1.2-1.2v-9.6L12 3.4z" />
            </svg>
          </span>
        </div>
      </div>

      {callOpen ? (
        <section
          className="erp-chatbot-panel erp-chatbot-panel--call"
          style={panelStyle}
          role="dialog"
          aria-label="Call directory"
        >
          <header className="erp-chatbot-header erp-chatbot-header--call">
            <h3>Call</h3>
            <button
              type="button"
              className="erp-chatbot-close"
              aria-label="Close"
              onClick={() => setCallOpen(false)}
            >
              &times;
            </button>
          </header>

          <div className="erp-chatbot-call-search">
            <input
              type="search"
              value={callSearch}
              onChange={(e) => setCallSearch(e.target.value)}
              placeholder="Search by first name..."
              aria-label="Search people"
            />
          </div>

          <div className="erp-chatbot-body erp-chatbot-call-list">
            {callNotice ? <div className="erp-chatbot-call-notice">{callNotice}</div> : null}
            {callLoading ? (
              <div className="erp-chatbot-call-empty">Loading people...</div>
            ) : null}
            {!callLoading && callError ? (
              <div className="erp-chatbot-call-empty">{callError}</div>
            ) : null}
            {!callLoading && !callError && filteredCallUsers.length === 0 ? (
              <div className="erp-chatbot-call-empty">No people found.</div>
            ) : null}
            {!callLoading && !callError
              ? filteredCallUsers.map((u) => (
                  <button
                    key={u.id}
                    type="button"
                    className={`erp-chatbot-call-item${u.online ? '' : ' is-offline'}`}
                    title={u.full_name || u.first_name}
                    onClick={() => startOnlineCall(u)}
                    disabled={callBusy || !!activeCall}
                  >
                    <span className="erp-chatbot-call-avatar" aria-hidden="true">
                      {String(u.first_name || '?').slice(0, 1).toUpperCase()}
                      <span className={`erp-chatbot-call-dot${u.online ? ' is-online' : ''}`} />
                    </span>
                    <span className="erp-chatbot-call-meta">
                      <span className="erp-chatbot-call-name">{u.first_name}</span>
                      <span className="erp-chatbot-call-dept">
                        {u.online ? (u.department || 'Online') : 'Offline'}
                      </span>
                    </span>
                    <span className="erp-chatbot-call-action" aria-hidden="true">
                      Call
                    </span>
                  </button>
                ))
              : null}
          </div>
        </section>
      ) : null}

      {incomingCall ? (
        <CallDashboard
          mode="incoming"
          peerName={incomingCall.peer_name || 'Someone'}
          status="ringing"
          muted={callMuted}
          held={callHeld}
          speakerOn={callSpeaker}
          sharingScreen={sharingScreen}
          remoteVideoStream={remoteVideoStream}
          addCallOpen={false}
          addCallUsers={[]}
          addCallLoading={false}
          onAccept={acceptIncoming}
          onReject={rejectIncoming}
          onHangup={hangUpCall}
          onToggleMute={toggleMute}
          onToggleHold={toggleHold}
          onToggleSpeaker={toggleSpeaker}
          onToggleScreenShare={toggleScreenShare}
          onOpenAddCall={openAddCall}
          onCloseAddCall={() => setAddCallOpen(false)}
          onAddCallUser={addCallUser}
        />
      ) : null}

      {activeCall && !incomingCall ? (
        <CallDashboard
          mode="active"
          peerName={activeCall.peer_name || 'User'}
          status={activeCall.status === 'active' ? 'active' : 'ringing'}
          muted={callMuted}
          held={callHeld}
          speakerOn={callSpeaker}
          sharingScreen={sharingScreen}
          remoteVideoStream={remoteVideoStream}
          addCallOpen={addCallOpen}
          addCallUsers={addCallUsers}
          addCallLoading={addCallLoading}
          onAccept={acceptIncoming}
          onReject={rejectIncoming}
          onHangup={hangUpCall}
          onToggleMute={toggleMute}
          onToggleHold={toggleHold}
          onToggleSpeaker={toggleSpeaker}
          onToggleScreenShare={toggleScreenShare}
          onOpenAddCall={openAddCall}
          onCloseAddCall={() => setAddCallOpen(false)}
          onAddCallUser={addCallUser}
        />
      ) : null}

      <audio ref={remoteAudioRef} autoPlay playsInline style={{ display: 'none' }} />

      {callNotice && !callOpen && !incomingCall && !activeCall ? (
        <div className="erp-chatbot-toast" role="status">{callNotice}</div>
      ) : null}

      {open ? (
        <section
          id={panelId}
          className="erp-chatbot-panel"
          style={panelStyle}
          role="dialog"
          aria-label="Help Assistant"
        >
          <header className="erp-chatbot-header">
            <h3>Help Assistant</h3>
            <button type="button" className="erp-chatbot-close" aria-label="Close" onClick={() => setOpen(false)}>
              &times;
            </button>
          </header>

          <div className="erp-chatbot-body" ref={bodyRef}>
            {messages.map((msg) => (
              <div
                key={msg.id}
                className={`erp-chatbot-msg erp-chatbot-msg--${msg.role}${msg.isAi ? ' erp-chatbot-msg--ai' : ''}`}
              >
                {msg.isAi ? <span className="erp-chatbot-msg-ai-label">AI</span> : null}
                {msg.text}
              </div>
            ))}

            {busy ? <div className="erp-chatbot-msg erp-chatbot-msg--bot erp-chatbot-msg--pending">Thinking with AI...</div> : null}
          </div>

          <footer className="erp-chatbot-footer">
            <input
              ref={inputRef}
              type="text"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  ask(input);
                }
              }}
              placeholder="Ask anything (e.g. create voucher)"
              aria-label="Chatbot question"
              disabled={busy}
            />
            <button
              type="button"
              className="erp-chatbot-send"
              onClick={() => ask(input)}
              disabled={busy || !input.trim()}
              aria-label="Send"
              title="Send"
            >
              <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
                <path
                  d="M5 12.5l4.5 4.5L19 7.5"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2.5"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </button>
          </footer>
        </section>
      ) : null}
    </div>
  );
}
