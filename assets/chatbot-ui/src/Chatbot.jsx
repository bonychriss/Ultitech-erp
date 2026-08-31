import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { localSearch } from './guides';

const STORAGE_KEY_DESKTOP = 'chatbot_pos';
const STORAGE_KEY_MOBILE = 'chatbot_pos_mobile';
const DRAG_THRESHOLD = 6;
const FAB_SIZE = 56;

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

function readSavedPos() {
  try {
    const raw = localStorage.getItem(storageKey()) || localStorage.getItem(STORAGE_KEY_DESKTOP);
    if (!raw) return null;
    const pos = JSON.parse(raw);
    const x = parseFloat(pos?.left);
    const y = parseFloat(pos?.top);
    if (Number.isNaN(x) || Number.isNaN(y)) return null;
    const next = clampPos(x, y);
    // Desktop coords on a phone are usually off-screen ù fall back to default
    if (isMobileViewport() && (x > window.innerWidth || y > window.innerHeight)) {
      return null;
    }
    return next;
  } catch {
    return null;
  }
}

function defaultPos() {
  return clampPos(
    window.innerWidth - FAB_SIZE - 16,
    window.innerHeight - FAB_SIZE - mobileBottomClearance()
  );
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

function ChatIcon() {
  // Nested bordered spans ù works on iOS stock where <button>+SVG stays blank
  return (
    <span className="erp-chatbot-fab-bubble" aria-hidden="true">
      <span className="erp-chatbot-fab-bubble-tail" />
    </span>
  );
}

export default function Chatbot() {
  const panelId = useId();
  const bodyRef = useRef(null);
  const inputRef = useRef(null);
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
  const [pos, setPos] = useState(() => readSavedPos() || defaultPos());
  const [dragging, setDragging] = useState(false);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [messages, setMessages] = useState([]);

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
  }, [pos, open]);

  useEffect(() => {
    const sync = () => {
      setPos((prev) => {
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
    if (!open) return;
    if (messages.length === 0) {
      setMessages([
        {
          id: 'intro',
          role: 'bot',
          text: 'Hi! Ask me anything about the system ù I use Ultimate Intelligence to answer.',
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
        // Offline / API failure ù local guides only as last resort
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

    setOpen((value) => !value);
  };

  return (
    <div className="erp-chatbot" data-open={open ? '1' : '0'}>
      <div
        role="button"
        tabIndex={0}
        className={`erp-chatbot-fab${dragging ? ' is-dragging' : ''}`}
        style={{ left: pos.x, top: pos.y }}
        aria-label="Help Assistant"
        aria-expanded={open}
        aria-controls={panelId}
        title="Help - drag to move"
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            setOpen((value) => !value);
          }
        }}
      >
        <span className="erp-chatbot-fab-icon">
          <ChatIcon />
        </span>
      </div>

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
