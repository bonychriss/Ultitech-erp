import React, { useCallback, useDeferredValue, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  HiOutlineArchiveBox,
  HiOutlineArrowLeft,
  HiOutlineArrowPath,
  HiOutlineArrowUturnLeft,
  HiOutlineArrowUturnRight,
  HiOutlineChevronDown,
  HiOutlineEnvelope,
  HiOutlineExclamationTriangle,
  HiOutlineFolder,
  HiOutlineMagnifyingGlass,
  HiOutlinePaperClip,
  HiOutlineStar,
  HiOutlineTrash,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './email-desk.css';

const FOLDER_TITLES = {
  inbox: 'Inbox',
  starred: 'Starred',
  sent: 'Sent',
  drafts: 'Drafts',
  archive: 'Archive',
  spam: 'Spam',
  trash: 'Trash',
};

/** Background mail pull while the inbox is open */
const AUTO_SYNC_MS = 60_000;

function parseApiJson(text) {
  const raw = String(text || '');
  try {
    return JSON.parse(raw);
  } catch (_) {
    /* continue */
  }
  // Strip BOM / leading PHP warnings and recover embedded JSON object.
  let cleaned = raw.replace(/^\uFEFF/, '');
  const start = cleaned.indexOf('{');
  const end = cleaned.lastIndexOf('}');
  if (start >= 0 && end > start) {
    try {
      return JSON.parse(cleaned.slice(start, end + 1));
    } catch (_) {
      /* continue */
    }
  }
  return null;
}

function displayName(raw) {
  const s = String(raw || '').trim();
  if (!s) return 'Unknown';
  const m = s.match(/^"?([^"<]+)"?\s*</);
  if (m) return m[1].trim();
  return s.split('@')[0] || s;
}

function extractEmail(raw) {
  const s = String(raw || '').trim();
  if (!s) return '';
  const m = s.match(/<([^>]+)>/);
  if (m) return m[1].trim().toLowerCase();
  if (s.includes('@')) return s.toLowerCase();
  return '';
}

function initialOf(raw) {
  const name = displayName(raw);
  return (name.charAt(0) || 'E').toUpperCase();
}

function isCompanyAddress(raw, mailbox, slug) {
  const email = extractEmail(raw);
  const box = String(mailbox || '').trim().toLowerCase();
  if (box && email && email === box) return true;
  const s = String(slug || '').toLowerCase();
  if (s === 'ultimate' && email.endsWith('@ultimate.co.tz')) return true;
  if (s === 'roadmaster' && (email.endsWith('@roadmasterspares.com') || email.includes('roadmaster'))) {
    return true;
  }
  return false;
}

function SenderAvatar({ raw, companyLogo, companyMailbox, companySlug, companyName, forceCompany }) {
  const useLogo =
    !!companyLogo &&
    (forceCompany || isCompanyAddress(raw, companyMailbox, companySlug));
  if (useLogo) {
    return (
      <span className="email-avatar email-avatar--logo" title={companyName || displayName(raw)}>
        <img src={companyLogo} alt="" />
      </span>
    );
  }
  return (
    <span className="email-avatar" aria-hidden="true">
      {initialOf(raw)}
    </span>
  );
}

function formatMessageDate(dateStr) {
  if (!dateStr) return '';
  const normalized = String(dateStr).includes('T')
    ? String(dateStr)
    : String(dateStr).replace(' ', 'T');
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return String(dateStr);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

function ListSkeleton({ rows = 10 }) {
  return (
    <div className="email-skel-list" aria-busy="true" aria-label="Loading messages">
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="email-skel-row">
          <span className="email-skel-block email-skel-check" />
          <span className="email-skel-block email-skel-star" />
          <span className="email-skel-block email-skel-from" />
          <span className="email-skel-block email-skel-preview" />
          <span className="email-skel-block email-skel-date" />
        </div>
      ))}
    </div>
  );
}

function MessageSkeleton({ onBack }) {
  return (
    <div className="email-msg email-msg--skel" aria-busy="true" aria-label="Opening message">
      <div className="email-msg-toolbar">
        <button type="button" className="email-msg-back" onClick={onBack}>
          <HiOutlineArrowLeft style={{ width: 18, height: 18 }} />
          Back to list
        </button>
      </div>
      <div className="email-skel-msg">
        <span className="email-skel-block email-skel-msg-title" />
        <span className="email-skel-block email-skel-msg-meta" />
        <span className="email-skel-block email-skel-msg-meta email-skel-msg-meta--short" />
        <span className="email-skel-block email-skel-msg-line" />
        <span className="email-skel-block email-skel-msg-line" />
        <span className="email-skel-block email-skel-msg-line email-skel-msg-line--mid" />
        <span className="email-skel-block email-skel-msg-line email-skel-msg-line--short" />
        <span className="email-skel-block email-skel-msg-line" />
        <span className="email-skel-block email-skel-msg-line email-skel-msg-line--mid" />
      </div>
    </div>
  );
}

function sanitizeEmailHtml(html) {
  let s = String(html || '');
  // Remove embedded styles/scripts so they cannot restyle the ERP sidebar.
  s = s.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
  s = s.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
  s = s.replace(/<link\b[^>]*>/gi, '');
  return s || '<p>(empty)</p>';
}

function withEmbedParam(href) {
  if (!href) return '';
  try {
    const u = new URL(href, window.location.href);
    u.searchParams.set('embed', '1');
    return u.toString();
  } catch (_) {
    return `${href}${href.includes('?') ? '&' : '?'}embed=1`;
  }
}

function showEmailToast(title, icon = 'success') {
  if (typeof window !== 'undefined' && window.Swal) {
    window.Swal.fire({
      toast: true,
      position: 'top-end',
      icon,
      title,
      showConfirmButton: false,
      timer: 2800,
      timerProgressBar: true,
    });
    return;
  }
  // Fallback toast if SweetAlert isn't on the page
  const el = document.createElement('div');
  el.className = `email-toast email-toast--${icon}`;
  el.setAttribute('role', 'status');
  el.textContent = title;
  document.body.appendChild(el);
  window.setTimeout(() => {
    el.classList.add('is-out');
    window.setTimeout(() => el.remove(), 280);
  }, 2600);
}

function ComposeModal({ src, title, onClose, onSent }) {
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!src) return undefined;
    const onMessage = (event) => {
      const data = event?.data;
      if (!data || data.source !== 'email-compose') return;
      if (data.type === 'ready') setLoading(false);
      if (data.type === 'close') onClose();
      if (data.type === 'sent') onSent();
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [src, onClose, onSent]);

  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [onClose]);

  if (!src) return null;

  return createPortal(
    <div className="email-compose-modal-backdrop" role="presentation" onClick={onClose}>
      <div
        className="email-compose-modal"
        role="dialog"
        aria-modal="true"
        aria-label={title || 'Compose'}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="email-compose-modal-bar">
          <h2 className="email-compose-modal-title">{title || 'Compose'}</h2>
          <button type="button" className="email-compose-modal-close" aria-label="Close" onClick={onClose}>
            <HiOutlineXMark style={{ width: 18, height: 18 }} />
          </button>
        </div>
        <div className={`email-compose-modal-frame-wrap${loading ? ' is-loading' : ''}`}>
          {loading ? <div className="email-compose-modal-skel" aria-hidden="true" /> : null}
          <iframe
            key={src}
            className="email-compose-modal-frame"
            title={title || 'Compose'}
            src={src}
            onLoad={() => setLoading(false)}
          />
        </div>
      </div>
    </div>,
    document.body
  );
}

function truncateFileName(name = '', max = 14) {
  const s = String(name || 'file');
  if (s.length <= max) return s;
  return `${s.slice(0, max - 1)}\u2026`;
}

function attachmentMeta(fileName = '') {
  const ext = String(fileName).split('.').pop()?.toLowerCase() || '';
  if (ext === 'pdf') return { badge: 'PDF', peel: '#ea4335', kind: 'pdf' };
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'].includes(ext)) {
    return { badge: 'IMG', peel: '#ea4335', kind: 'image' };
  }
  if (['xls', 'xlsx', 'csv'].includes(ext)) return { badge: 'XLS', peel: '#0f9d58', kind: 'sheet' };
  if (['doc', 'docx'].includes(ext)) return { badge: 'DOC', peel: '#1a73e8', kind: 'doc' };
  if (['zip', 'rar', '7z'].includes(ext)) return { badge: 'ZIP', peel: '#9334e6', kind: 'zip' };
  return { badge: (ext || 'FILE').toUpperCase().slice(0, 4), peel: '#70757a', kind: 'file' };
}

function MessageView({
  active,
  loading,
  onBack,
  onToggleStar,
  errorNote,
  replyOpen,
  replyText,
  replySending,
  replyNote,
  replyFiles,
  onReplyOpen,
  onReplyClose,
  onReplyTextChange,
  onReplyFilesChange,
  onReplyNote,
  onReplySend,
  onForward,
  onStatusChange,
  statusBusy,
  attachmentApi,
  companyLogo,
  companyMailbox,
  companySlug,
  companyName,
}) {
  const [moveOpen, setMoveOpen] = useState(false);
  const moveRef = useRef(null);

  useEffect(() => {
    if (!moveOpen) return undefined;
    const onDoc = (e) => {
      if (moveRef.current && !moveRef.current.contains(e.target)) {
        setMoveOpen(false);
      }
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, [moveOpen]);

  if (loading) {
    return <MessageSkeleton onBack={onBack} />;
  }

  if (!active) {
    return (
      <div className="email-msg">
        <button type="button" className="email-msg-back" onClick={onBack}>
          <HiOutlineArrowLeft style={{ width: 18, height: 18 }} />
          Back to list
        </button>
        <div className="email-msg-loading">{errorNote || 'Could not load this message.'}</div>
      </div>
    );
  }

  const canReply = (active.direction || 'inbound') !== 'outbound';
  const canForward = typeof onForward === 'function';
  const canStatus = typeof onStatusChange === 'function';
  const attachments = Array.isArray(active.attachments) ? active.attachments : [];
  const busy = !!statusBusy;

  const runStatus = (status) => {
    if (!canStatus || busy) return;
    setMoveOpen(false);
    onStatusChange([active.id], status);
  };

  return (
    <article className="email-msg">
      <div className="email-msg-toolbar">
        <div className="email-msg-toolbar-left">
          <button type="button" className="email-tool-btn" onClick={onBack} title="Back" aria-label="Back">
            <HiOutlineArrowLeft style={{ width: 18, height: 18 }} />
          </button>
          {canStatus ? (
            <>
              <button
                type="button"
                className="email-tool-btn"
                onClick={() => runStatus('archived')}
                disabled={busy}
                title="Archive"
                aria-label="Archive"
              >
                <HiOutlineArchiveBox style={{ width: 18, height: 18 }} />
              </button>
              <button
                type="button"
                className="email-tool-btn"
                onClick={() => runStatus('spam')}
                disabled={busy}
                title="Report spam"
                aria-label="Report spam"
              >
                <HiOutlineExclamationTriangle style={{ width: 18, height: 18 }} />
              </button>
              <button
                type="button"
                className="email-tool-btn"
                onClick={() => runStatus('trash')}
                disabled={busy}
                title="Delete"
                aria-label="Delete"
              >
                <HiOutlineTrash style={{ width: 18, height: 18 }} />
              </button>
              <span className="email-tool-divider" aria-hidden="true" />
              <button
                type="button"
                className="email-tool-btn"
                onClick={() => runStatus('unread')}
                disabled={busy}
                title="Mark as unread"
                aria-label="Mark as unread"
              >
                <HiOutlineEnvelope style={{ width: 18, height: 18 }} />
              </button>
              <div className="email-tool-menu" ref={moveRef}>
                <button
                  type="button"
                  className="email-tool-btn email-tool-btn--label"
                  onClick={() => setMoveOpen((v) => !v)}
                  disabled={busy}
                  title="Move to"
                  aria-expanded={moveOpen}
                  aria-haspopup="menu"
                >
                  <HiOutlineFolder style={{ width: 18, height: 18 }} />
                  <span>Move to</span>
                  <HiOutlineChevronDown style={{ width: 14, height: 14 }} />
                </button>
                {moveOpen ? (
                  <div className="email-tool-dropdown" role="menu">
                    <button type="button" role="menuitem" onClick={() => runStatus('inbox')}>
                      Inbox
                    </button>
                    <button type="button" role="menuitem" onClick={() => runStatus('archived')}>
                      Archive
                    </button>
                    <button type="button" role="menuitem" onClick={() => runStatus('spam')}>
                      Spam
                    </button>
                    <button type="button" role="menuitem" onClick={() => runStatus('trash')}>
                      Trash
                    </button>
                  </div>
                ) : null}
              </div>
            </>
          ) : null}
          {canForward ? (
            <button
              type="button"
              className="email-tool-btn email-tool-btn--label"
              onClick={onForward}
              title="Forward"
              aria-label="Forward"
            >
              <HiOutlineArrowUturnRight style={{ width: 18, height: 18 }} />
              <span>Forward</span>
            </button>
          ) : null}
        </div>
        <button
          type="button"
          className={`email-desk-star${active.is_starred ? ' is-on' : ''}`}
          onClick={(e) => onToggleStar(e, active.id, !!active.is_starred)}
          aria-label={active.is_starred ? 'Unstar' : 'Star'}
        >
          <HiOutlineStar style={{ width: 18, height: 18 }} />
        </button>
      </div>

      <header className="email-msg-header">
        <h1 className="email-msg-subject">{active.subject || '(no subject)'}</h1>
        <div className="email-msg-identity">
          <SenderAvatar
            raw={active.direction === 'outbound' ? active.sender || companyMailbox : active.sender}
            companyLogo={companyLogo}
            companyMailbox={companyMailbox}
            companySlug={companySlug}
            companyName={companyName}
            forceCompany={active.direction === 'outbound'}
          />
          <div className="email-msg-identity-text">
            <div className="email-msg-row">
              <span className="email-msg-label">From</span>
              <span className="email-msg-value">{active.sender}</span>
            </div>
            <div className="email-msg-row email-msg-row--to">
              <span className="email-msg-label">To</span>
              <span className="email-msg-value">{active.recipient}</span>
              <span className="email-msg-date">{formatMessageDate(active.created_at)}</span>
            </div>
          </div>
        </div>
      </header>

      {attachments.length > 0 ? (
        <div className="email-msg-attachments">
          <div className="email-msg-attachments-bar">
            <span className="email-msg-attachments-title">
              {attachments.length === 1 ? 'One attachment' : `${attachments.length} attachments`}
              <span className="email-msg-attachments-dot">{'\u00B7'}</span>
              <span className="email-msg-attachments-note">Scanned</span>
            </span>
          </div>
          <div className="email-msg-attach-grid">
            {attachments.map((att) => {
              const id = att.id || att.attachment_id;
              const name = att.file_name || 'file';
              const href = attachmentApi && id ? `${attachmentApi}${id}` : '';
              const meta = attachmentMeta(name);
              const card = (
                <>
                  <div className={`email-att-thumb email-att-thumb--${meta.kind}`}>
                    <span className="email-att-thumb-badge" style={{ background: meta.peel }}>
                      {meta.badge}
                    </span>
                    {href ? (
                      <div className="email-att-overlay">
                        <a
                          className="email-att-action"
                          href={href}
                          title="Download"
                          onClick={(e) => e.stopPropagation()}
                        >
                          {'\u2193'}
                        </a>
                      </div>
                    ) : null}
                  </div>
                  <div className="email-att-footer">
                    <span className="email-att-type" style={{ background: meta.peel }}>
                      {meta.badge}
                    </span>
                    <span className="email-att-name" title={name}>
                      {name}
                    </span>
                    <span className="email-att-peel-bg" style={{ background: meta.peel }} aria-hidden="true" />
                    <span className="email-att-peel-fold" aria-hidden="true" />
                  </div>
                </>
              );
              return href ? (
                <a key={id || name} className="email-att-card" href={href}>
                  {card}
                </a>
              ) : (
                <div key={id || name} className="email-att-card">
                  {card}
                </div>
              );
            })}
          </div>
        </div>
      ) : null}

      <div
        className="email-msg-body"
        dangerouslySetInnerHTML={{ __html: sanitizeEmailHtml(active.body) }}
      />

      {(canReply || canForward) && !replyOpen ? (
        <div className="email-reply-launch">
          {canReply ? (
            <button type="button" className="email-reply-pill" onClick={onReplyOpen}>
              <HiOutlineArrowUturnLeft style={{ width: 16, height: 16 }} />
              Reply
            </button>
          ) : null}
          {canForward ? (
            <button type="button" className="email-forward-pill" onClick={onForward}>
              <HiOutlineArrowUturnRight style={{ width: 16, height: 16 }} />
              Forward
            </button>
          ) : null}
        </div>
      ) : null}

      {canReply && replyOpen ? (
        <div className="email-reply">
          <div className="email-reply-to">
            Replying to <strong>{displayName(active.sender)}</strong>
          </div>
          <textarea
            className="email-reply-input"
            rows={4}
            placeholder="Write your reply..."
            value={replyText}
            onChange={(e) => onReplyTextChange(e.target.value)}
            disabled={replySending}
          />
          {Array.isArray(replyFiles) && replyFiles.length > 0 ? (
            <div className="email-reply-files">
              {replyFiles.map((file, idx) => (
                <span key={`${file.name}-${idx}`} className="email-reply-file-chip">
                  <HiOutlinePaperClip style={{ width: 14, height: 14 }} />
                  <span title={file.name}>{truncateFileName(file.name, 28)}</span>
                  <button
                    type="button"
                    aria-label={`Remove ${file.name}`}
                    disabled={replySending}
                    onClick={() =>
                      onReplyFilesChange(replyFiles.filter((_, i) => i !== idx))
                    }
                  >
                    <HiOutlineXMark style={{ width: 14, height: 14 }} />
                  </button>
                </span>
              ))}
            </div>
          ) : null}
          {replyNote ? <p className="email-reply-note">{replyNote}</p> : null}
          <div className="email-reply-actions">
            <label className={`email-reply-attach${replySending ? ' is-disabled' : ''}`}>
              <HiOutlinePaperClip style={{ width: 16, height: 16 }} />
              Attach
              <input
                type="file"
                multiple
                hidden
                disabled={replySending}
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar"
                onChange={(e) => {
                  const picked = Array.from(e.target.files || []);
                  e.target.value = '';
                  if (!picked.length) return;
                  const next = [...(Array.isArray(replyFiles) ? replyFiles : []), ...picked];
                  const total = next.reduce((sum, f) => sum + (f.size || 0), 0);
                  if (total > 25 * 1024 * 1024) {
                    if (typeof onReplyNote === 'function') {
                      onReplyNote('Total attachments must stay under 25MB.');
                    }
                    return;
                  }
                  onReplyFilesChange(next);
                }}
              />
            </label>
            <div className="email-reply-actions-right">
              <button
                type="button"
                className="email-desk-btn email-desk-btn--text"
                onClick={onReplyClose}
                disabled={replySending}
              >
                Cancel
              </button>
              <button
                type="button"
                className="email-reply-pill"
                onClick={onReplySend}
                disabled={replySending || !String(replyText || '').trim()}
              >
                {replySending ? 'Sending...' : 'Send reply'}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </article>
  );
}

function formatWhen(dateStr) {
  if (!dateStr) return '';
  const normalized = String(dateStr).includes('T')
    ? String(dateStr)
    : String(dateStr).replace(' ', 'T');
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return String(dateStr);
  const now = new Date();
  const sameDay =
    d.getFullYear() === now.getFullYear() &&
    d.getMonth() === now.getMonth() &&
    d.getDate() === now.getDate();
  if (sameDay) {
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
  }
  const sameYear = d.getFullYear() === now.getFullYear();
  if (sameYear) {
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
  }
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: '2-digit' });
}

export default function Inbox({ data = {} }) {
  const links = data.links || {};
  const companyName = String(data.company_name || '');
  const companySlug = String(data.company_slug || '');
  const companyLogo = String(data.company_logo || '');
  const companyMailbox = String(data.company_mailbox || '');
  const folder = String(data.folder || 'inbox').toLowerCase();
  const folderTitle = FOLDER_TITLES[folder] || 'Inbox';
  const [emails, setEmails] = useState(Array.isArray(data.emails) ? data.emails : []);
  const [counts, setCounts] = useState(data.counts || {});
  const [configured, setConfigured] = useState(data.configured !== false);
  const [selectedId, setSelectedId] = useState(null);
  const [active, setActive] = useState(null);
  const [loadingList, setLoadingList] = useState(false);
  const [loadingMsg, setLoadingMsg] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [query, setQuery] = useState('');
  const deferredQuery = useDeferredValue(query);
  const [statusNote, setStatusNote] = useState('');

  const [selectedRows, setSelectedRows] = useState(() => new Set());
  const [replyOpen, setReplyOpen] = useState(false);
  const [replyText, setReplyText] = useState('');
  const [replySending, setReplySending] = useState(false);
  const [replyNote, setReplyNote] = useState('');
  const [replyFiles, setReplyFiles] = useState([]);
  const [composeModal, setComposeModal] = useState(null);
  const [statusBusy, setStatusBusy] = useState(false);
  const [listMoveOpen, setListMoveOpen] = useState(false);
  const listMoveRef = useRef(null);
  const syncInFlight = useRef(false);
  const folderRef = useRef(folder);
  const queryRef = useRef(deferredQuery);
  folderRef.current = folder;
  queryRef.current = deferredQuery;

  const loadList = useCallback(
    async (nextFolder = folder, q = deferredQuery, opts = {}) => {
      if (!links.inbox_api) return;
      const quiet = !!opts.quiet;
      if (!quiet) setLoadingList(true);
      try {
        const url = new URL(links.inbox_api, window.location.origin);
        url.searchParams.set('folder', nextFolder);
        if (q) url.searchParams.set('q', q);
        const res = await fetch(url.toString(), { credentials: 'same-origin' });
        const json = await res.json();
        if (json.status === 'success') {
          setEmails(json.emails || []);
          setCounts(json.counts || {});
          setConfigured(!!json.configured);
        } else if (!quiet) {
          setStatusNote(json.message || 'Could not load mail');
        }
      } catch (e) {
        if (!quiet) setStatusNote(e.message || 'Network error');
      } finally {
        if (!quiet) setLoadingList(false);
      }
    },
    [folder, deferredQuery, links.inbox_api]
  );

  useEffect(() => {
    loadList(folder, deferredQuery);
  }, [folder, deferredQuery, loadList]);

  const syncMail = useCallback(
    async (opts = {}) => {
      const silent = !!opts.silent;
      if (!links.sync_api || syncInFlight.current) return;
      syncInFlight.current = true;
      if (!silent) {
        setSyncing(true);
        setStatusNote('');
      }
      try {
        const url = new URL(links.sync_api, window.location.origin);
        if (silent) url.searchParams.set('quick', '1');
        const res = await fetch(url.toString(), { method: 'POST', credentials: 'same-origin' });
        const json = await res.json();
        if (!silent && json.status !== 'success') {
          setStatusNote(json.message || 'Sync failed');
        }
        await loadList(folderRef.current, queryRef.current, { quiet: silent });
      } catch (e) {
        if (!silent) setStatusNote('Sync failed. Try again.');
      } finally {
        syncInFlight.current = false;
        if (!silent) setSyncing(false);
      }
    },
    [links.sync_api, loadList]
  );

  useEffect(() => {
    if (!configured || !links.sync_api) return undefined;

    // Let the page paint from cached DB first, then pull quietly in the background.
    const startId = window.setTimeout(() => syncMail({ silent: true }), 1200);

    const tick = () => {
      if (document.visibilityState === 'visible') {
        syncMail({ silent: true });
      }
    };
    const intervalId = window.setInterval(tick, AUTO_SYNC_MS);

    const onVisibility = () => {
      if (document.visibilityState === 'visible') {
        syncMail({ silent: true });
      }
    };
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
      window.clearTimeout(startId);
      window.clearInterval(intervalId);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [configured, links.sync_api, syncMail]);

  const openMessage = async (id) => {
    const numericId = Number(id);
    const target = emails.find((m) => Number(m.id) === numericId);
    const wasUnread = !!(target && target.unread);

    if (wasUnread) {
      setEmails((prev) =>
        prev.map((m) =>
          Number(m.id) === numericId ? { ...m, unread: false, status: 'read' } : m
        )
      );
      setCounts((c) => ({
        ...c,
        unread: Math.max(0, (c.unread || 0) - 1),
      }));
    }

    setSelectedId(numericId);
    setLoadingMsg(true);
    setActive(null);
    setReplyOpen(false);
    setReplyText('');
    setReplyFiles([]);
    setReplyNote('');
    try {
      if (!links.message_api) {
        setStatusNote('Message API not configured');
        return;
      }
      const url = new URL(links.message_api, window.location.href);
      url.searchParams.set('id', String(numericId));
      const res = await fetch(url.toString(), { credentials: 'same-origin' });
      const text = await res.text();
      const json = parseApiJson(text);
      if (!json) {
        setStatusNote(res.ok ? 'Invalid message response' : `Could not open message (${res.status})`);
        return;
      }
      if (json.status === 'success' && json.email) {
        setActive({ ...json.email, unread: false, status: 'read' });
        setEmails((prev) =>
          prev.map((m) =>
            Number(m.id) === numericId ? { ...m, unread: false, status: 'read' } : m
          )
        );
        if (json.was_unread && !wasUnread) {
          setCounts((c) => ({
            ...c,
            unread: Math.max(0, (c.unread || 0) - 1),
          }));
        }
      } else {
        setStatusNote(json.message || 'Could not open message');
      }
    } catch (e) {
      setStatusNote(e.message || 'Network error');
    } finally {
      setLoadingMsg(false);
    }
  };

  const closeComposeModal = useCallback(() => setComposeModal(null), []);

  const openCompose = useCallback((href, title = 'Compose') => {
    const src = withEmbedParam(href);
    if (!src) return;
    setComposeModal({ src, title });
  }, []);

  const onComposeSent = useCallback(() => {
    setComposeModal(null);
    setStatusNote('');
    showEmailToast('Message sent');
    loadList(folderRef.current, queryRef.current, { quiet: true });
  }, [loadList]);

  const forwardHrefFor = useCallback(
    (id) => {
      if (!id) return '';
      if (links.compose_forward) return `${links.compose_forward}${id}`;
      if (links.compose) return `${links.compose}?forward=${id}`;
      return '';
    },
    [links.compose, links.compose_forward]
  );

  const sendReply = async () => {
    if (!links.reply_api || !active?.id) return;
    const message = String(replyText || '').trim();
    if (!message) {
      setReplyNote('Write a reply first.');
      return;
    }
    const total = (replyFiles || []).reduce((sum, f) => sum + (f.size || 0), 0);
    if (total > 25 * 1024 * 1024) {
      setReplyNote('Total attachments must stay under 25MB.');
      return;
    }
    setReplySending(true);
    setReplyNote('');
    try {
      const body = new FormData();
      body.append('id', String(active.id));
      body.append('message', message);
      (replyFiles || []).forEach((file) => {
        body.append('attachments[]', file, file.name);
      });
      const res = await fetch(links.reply_api, {
        method: 'POST',
        body,
        credentials: 'same-origin',
      });
      const text = await res.text();
      const json = parseApiJson(text);
      if (json?.status === 'success') {
        setReplyNote('');
        setReplyText('');
        setReplyFiles([]);
        setReplyOpen(false);
        showEmailToast(
          json.attachment_count > 0
            ? `Reply sent with ${json.attachment_count} attachment${json.attachment_count === 1 ? '' : 's'}`
            : 'Reply sent'
        );
        loadList(folderRef.current, queryRef.current, { quiet: true });
      } else {
        setReplyNote(json?.message || 'Could not send reply.');
        showEmailToast(json?.message || 'Could not send reply', 'error');
      }
    } catch (e) {
      setReplyNote(e.message || 'Network error');
    } finally {
      setReplySending(false);
    }
  };

  const toggleStar = async (e, id, isStarred) => {
    e.stopPropagation();
    if (!links.toggle_star_api) return;
    try {
      const body = new FormData();
      body.append('id', String(id));
      body.append('starred', isStarred ? '0' : '1');
      const res = await fetch(links.toggle_star_api, {
        method: 'POST',
        body,
        credentials: 'same-origin',
      });
      const json = await res.json();
      if (json.status === 'success') {
        setEmails((prev) =>
          prev.map((m) => (m.id === id ? { ...m, is_starred: !isStarred } : m))
        );
        if (active?.id === id) setActive((a) => ({ ...a, is_starred: !isStarred }));
      }
    } catch (_) {
      /* ignore */
    }
  };

  const updateEmailStatus = useCallback(
    async (ids, status) => {
      if (!links.update_status_api || !ids?.length || statusBusy) return;
      setStatusBusy(true);
      setListMoveOpen(false);
      try {
        const body = new FormData();
        body.append('ids', JSON.stringify(ids.map((id) => Number(id))));
        body.append('status', status);
        const res = await fetch(links.update_status_api, {
          method: 'POST',
          body,
          credentials: 'same-origin',
        });
        const json = await parseApiJson(await res.text());
        if (json?.status !== 'success') {
          showEmailToast(json?.message || 'Could not update mail', 'error');
          return;
        }

        const idSet = new Set(ids.map((id) => Number(id)));
        // unread stays in folder; inbox/archive/spam/trash may leave current view
        const removeFromList =
          status === 'trash' ||
          status === 'spam' ||
          status === 'archived' ||
          (status === 'inbox' && folder !== 'inbox') ||
          (status === 'read' && folder === 'spam') ||
          (status === 'read' && folder === 'trash') ||
          (status === 'read' && folder === 'archive');

        if (status === 'unread') {
          setEmails((prev) =>
            prev.map((m) =>
              idSet.has(Number(m.id)) ? { ...m, unread: true, status: 'unread' } : m
            )
          );
          if (active && idSet.has(Number(active.id))) {
            setSelectedId(null);
            setActive(null);
            setReplyOpen(false);
            setReplyText('');
            setReplyFiles([]);
            setReplyNote('');
          }
        } else if (removeFromList) {
          setEmails((prev) => prev.filter((m) => !idSet.has(Number(m.id))));
          if (active && idSet.has(Number(active.id))) {
            setSelectedId(null);
            setActive(null);
            setReplyOpen(false);
            setReplyText('');
            setReplyFiles([]);
            setReplyNote('');
          }
        } else {
          setEmails((prev) =>
            prev.map((m) =>
              idSet.has(Number(m.id)) ? { ...m, unread: false, status: 'read' } : m
            )
          );
        }

        setSelectedRows((prev) => {
          const next = new Set(prev);
          idSet.forEach((id) => next.delete(id));
          return next;
        });
        showEmailToast(json.message || 'Updated');
        // Refresh counts quietly
        loadList(folderRef.current, queryRef.current, { quiet: true });
      } catch (e) {
        showEmailToast(e.message || 'Could not update mail', 'error');
      } finally {
        setStatusBusy(false);
      }
    },
    [links.update_status_api, statusBusy, active, folder, loadList]
  );

  useEffect(() => {
    if (!listMoveOpen) return undefined;
    const onDoc = (e) => {
      if (listMoveRef.current && !listMoveRef.current.contains(e.target)) {
        setListMoveOpen(false);
      }
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, [listMoveOpen]);

  const toggleRowSelect = (e, id) => {
    e.stopPropagation();
    setSelectedRows((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const selectedIds = Array.from(selectedRows);
  const selectedCount = selectedIds.length;

  const unread = counts.unread || 0;
  const lede =
    folder === 'inbox'
      ? unread > 0
        ? `${unread} unread`
        : 'Inbox up to date'
      : statusNote || `${emails.length} message${emails.length === 1 ? '' : 's'}`;

  if (!configured) {
    return (
      <div className="email-desk">
        <div className="email-desk-main">
          <header className="email-desk-hero">
            <div>
              <h1 className="email-desk-title">Mail</h1>
              <p className="email-desk-lede">Connect a remote bridge or personal IMAP account to sync mail.</p>
            </div>
          </header>
          <div className="email-desk-setup">
            <h3>Setup required</h3>
            <p>Enable the Ultimate / Roadmaster bridge in Email Settings, or add IMAP in My Account.</p>
            <div className="email-desk-setup-actions">
              {links.settings ? (
                <a className="email-desk-btn email-desk-btn--primary" href={links.settings}>
                  Email settings
                </a>
              ) : null}
              {links.account ? (
                <a className="email-desk-btn email-desk-btn--ghost" href={links.account}>
                  My account
                </a>
              ) : null}
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="email-desk">
      <div className="email-desk-main">
        {selectedId ? (
          <MessageView
            active={active}
            loading={loadingMsg}
            errorNote={statusNote}
            replyOpen={replyOpen}
            replyText={replyText}
            replySending={replySending}
            replyNote={replyNote}
            replyFiles={replyFiles}
            onReplyOpen={() => {
              setReplyOpen(true);
              setReplyNote('');
            }}
            onReplyClose={() => {
              setReplyOpen(false);
              setReplyNote('');
              setReplyFiles([]);
            }}
            onReplyTextChange={setReplyText}
            onReplyFilesChange={setReplyFiles}
            onReplyNote={setReplyNote}
            onReplySend={sendReply}
            onForward={
              forwardHrefFor(active?.id)
                ? () => openCompose(forwardHrefFor(active.id), 'Forward')
                : undefined
            }
            onStatusChange={links.update_status_api ? updateEmailStatus : undefined}
            statusBusy={statusBusy}
            attachmentApi={links.attachment_api || ''}
            companyLogo={companyLogo}
            companyMailbox={companyMailbox}
            companySlug={companySlug}
            companyName={companyName}
            onBack={() => {
              setSelectedId(null);
              setActive(null);
              setStatusNote('');
              setReplyOpen(false);
              setReplyText('');
              setReplyFiles([]);
              setReplyNote('');
            }}
            onToggleStar={toggleStar}
          />
        ) : (
          <>
            <header className="email-desk-hero">
              <div className="email-desk-hero-left">
                <h1 className="email-desk-title">{folderTitle}</h1>
                <p className="email-desk-lede">{lede}</p>
              </div>
              <div className="email-desk-search">
                <HiOutlineMagnifyingGlass className="email-desk-search-icon" style={{ width: 15, height: 15 }} />
                <input
                  type="search"
                  placeholder="Search mail..."
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  aria-label="Search mail"
                />
              </div>
              <div className="email-desk-hero-cta">
                <button
                  type="button"
                  className="email-desk-btn email-desk-btn--text"
                  onClick={() => syncMail({ silent: false })}
                  disabled={syncing}
                >
                  <HiOutlineArrowPath style={{ width: 16, height: 16 }} className={syncing ? 'animate-spin' : ''} />
                  {syncing ? 'Syncing...' : 'Sync'}
                </button>
                {links.compose ? (
                  <button
                    type="button"
                    className="email-desk-btn email-desk-btn--primary email-desk-btn--pill"
                    onClick={() => openCompose(links.compose, 'New message')}
                  >
                    New mail
                  </button>
                ) : null}
              </div>
            </header>

            {selectedCount > 0 && links.update_status_api ? (
              <div className="email-select-toolbar" role="toolbar" aria-label="Selection actions">
                <button
                  type="button"
                  className="email-tool-btn"
                  title="Clear selection"
                  aria-label="Clear selection"
                  onClick={() => setSelectedRows(new Set())}
                >
                  <HiOutlineXMark style={{ width: 18, height: 18 }} />
                </button>
                <span className="email-select-count">
                  {selectedCount} selected
                </span>
                <span className="email-tool-divider" aria-hidden="true" />
                <button
                  type="button"
                  className="email-tool-btn"
                  disabled={statusBusy}
                  title="Archive"
                  aria-label="Archive"
                  onClick={() => updateEmailStatus(selectedIds, 'archived')}
                >
                  <HiOutlineArchiveBox style={{ width: 18, height: 18 }} />
                </button>
                <button
                  type="button"
                  className="email-tool-btn"
                  disabled={statusBusy}
                  title="Report spam"
                  aria-label="Report spam"
                  onClick={() => updateEmailStatus(selectedIds, 'spam')}
                >
                  <HiOutlineExclamationTriangle style={{ width: 18, height: 18 }} />
                </button>
                <button
                  type="button"
                  className="email-tool-btn"
                  disabled={statusBusy}
                  title="Delete"
                  aria-label="Delete"
                  onClick={() => updateEmailStatus(selectedIds, 'trash')}
                >
                  <HiOutlineTrash style={{ width: 18, height: 18 }} />
                </button>
                <span className="email-tool-divider" aria-hidden="true" />
                <button
                  type="button"
                  className="email-tool-btn"
                  disabled={statusBusy}
                  title="Mark as unread"
                  aria-label="Mark as unread"
                  onClick={() => updateEmailStatus(selectedIds, 'unread')}
                >
                  <HiOutlineEnvelope style={{ width: 18, height: 18 }} />
                </button>
                <div className="email-tool-menu" ref={listMoveRef}>
                  <button
                    type="button"
                    className="email-tool-btn email-tool-btn--label"
                    disabled={statusBusy}
                    title="Move to"
                    aria-expanded={listMoveOpen}
                    aria-haspopup="menu"
                    onClick={() => setListMoveOpen((v) => !v)}
                  >
                    <HiOutlineFolder style={{ width: 18, height: 18 }} />
                    <span>Move to</span>
                    <HiOutlineChevronDown style={{ width: 14, height: 14 }} />
                  </button>
                  {listMoveOpen ? (
                    <div className="email-tool-dropdown" role="menu">
                      <button type="button" role="menuitem" onClick={() => updateEmailStatus(selectedIds, 'inbox')}>
                        Inbox
                      </button>
                      <button type="button" role="menuitem" onClick={() => updateEmailStatus(selectedIds, 'archived')}>
                        Archive
                      </button>
                      <button type="button" role="menuitem" onClick={() => updateEmailStatus(selectedIds, 'spam')}>
                        Spam
                      </button>
                      <button type="button" role="menuitem" onClick={() => updateEmailStatus(selectedIds, 'trash')}>
                        Trash
                      </button>
                    </div>
                  ) : null}
                </div>
                {selectedCount === 1 && forwardHrefFor(selectedIds[0]) ? (
                  <button
                    type="button"
                    className="email-tool-btn email-tool-btn--label"
                    title="Forward"
                    aria-label="Forward"
                    onClick={() => openCompose(forwardHrefFor(selectedIds[0]), 'Forward')}
                  >
                    <HiOutlineArrowUturnRight style={{ width: 18, height: 18 }} />
                    <span>Forward</span>
                  </button>
                ) : null}
              </div>
            ) : null}

            <div className="email-desk-shell">
              <div className="email-desk-list">
                <div className="email-desk-list-scroll">
                  {loadingList && emails.length === 0 ? (
                    <ListSkeleton rows={12} />
                  ) : emails.length === 0 ? (
                    <div className="email-desk-empty-list">No messages in this folder</div>
                  ) : (
                    emails.map((mail) => {
                      const party = displayName(
                        mail.direction === 'outbound' ? mail.recipient : mail.sender
                      );
                      const subject = mail.subject || '(no subject)';
                      const snippet = mail.snippet || '';
                      const listAtts = Array.isArray(mail.attachments) ? mail.attachments.slice(0, 3) : [];
                      const hasAtts = listAtts.length > 0 || !!mail.has_attachments;
                      return (
                        <div
                          key={mail.id}
                          role="button"
                          tabIndex={0}
                          className={`email-desk-item${mail.unread ? ' is-unread' : ''}${
                            selectedRows.has(mail.id) ? ' is-checked' : ''
                          }${hasAtts ? ' has-atts' : ''}`}
                          onClick={() => openMessage(mail.id)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                              e.preventDefault();
                              openMessage(mail.id);
                            }
                          }}
                        >
                          <input
                            type="checkbox"
                            className="email-desk-check"
                            checked={selectedRows.has(mail.id)}
                            onChange={(e) => toggleRowSelect(e, mail.id)}
                            onClick={(e) => e.stopPropagation()}
                            aria-label={`Select ${party}`}
                          />
                          <button
                            type="button"
                            className={`email-desk-star${mail.is_starred ? ' is-on' : ''}`}
                            onClick={(e) => toggleStar(e, mail.id, !!mail.is_starred)}
                            aria-label={mail.is_starred ? 'Unstar' : 'Star'}
                          >
                            <HiOutlineStar style={{ width: 18, height: 18 }} />
                          </button>
                          <span
                            className={`email-desk-unread-dot${mail.unread ? ' is-on' : ''}`}
                            aria-label={mail.unread ? 'Unread' : undefined}
                            title={mail.unread ? 'Unread' : undefined}
                          />
                          <span className="email-desk-item-from" title={party}>
                            {party}
                          </span>
                          <div className="email-desk-item-main">
                            <div className="email-desk-item-preview">
                              <span className="email-desk-item-subject">{subject}</span>
                              {snippet ? (
                                <>
                                  <span className="email-desk-item-sep"> - </span>
                                  <span className="email-desk-item-snippet">{snippet}</span>
                                </>
                              ) : null}
                            </div>
                            {listAtts.length > 0 ? (
                              <div className="email-desk-item-chips">
                                {listAtts.map((att) => {
                                  const name = att.file_name || 'file';
                                  const meta = attachmentMeta(name);
                                  return (
                                    <span
                                      key={att.id || name}
                                      className="email-desk-att-chip"
                                      title={name}
                                    >
                                      <span
                                        className={`email-desk-att-chip-ico email-desk-att-chip-ico--${meta.kind}`}
                                        style={{ background: meta.peel }}
                                        aria-hidden="true"
                                      >
                                        {meta.kind === 'image' ? '' : meta.badge.slice(0, 1)}
                                      </span>
                                      <span className="email-desk-att-chip-name">
                                        {truncateFileName(name, 16)}
                                      </span>
                                    </span>
                                  );
                                })}
                              </div>
                            ) : null}
                          </div>
                          <span className="email-desk-item-date">{formatWhen(mail.created_at)}</span>
                        </div>
                      );
                    })
                  )}
                </div>
              </div>
            </div>
          </>
        )}
      </div>
      {composeModal ? (
        <ComposeModal
          src={composeModal.src}
          title={composeModal.title}
          onClose={closeComposeModal}
          onSent={onComposeSent}
        />
      ) : null}
    </div>
  );
}
