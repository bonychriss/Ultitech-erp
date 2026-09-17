import { useEffect, useMemo, useState } from 'react';
import { CheckCircle2, FilePlus2, Lock, Share2, Trash2 } from 'lucide-react';
import ShareLetterModal from '../components/ShareLetterModal.jsx';
import {
  composeHref,
  consumeLetterFlash,
  deleteLetter,
  formatListDate,
  listLetters,
  normalizeApprovalStatus,
  normalizeVisibility,
  readLetterCfg,
  syncLettersFromServer,
  upsertLetter,
} from '../utils/letterStore.js';

const EMPTY_LOTTIE_FALLBACK = '/assets/animations/nothing.lottie';

function ensureDotLottiePlayer() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (customElements.get('dotlottie-wc')) return;
  if (document.getElementById('letter-dotlottie-wc')) return;
  const script = document.createElement('script');
  script.id = 'letter-dotlottie-wc';
  script.type = 'module';
  script.src = 'https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js';
  document.head.appendChild(script);
}

function recipientLabel(form = {}) {
  return String(form.recipientName || form.recipientCompany || '').trim() || '-';
}

function authorLabel(row, cfg) {
  return String(
    row.authorName
    || row.form?.signName
    || cfg.user?.name
    || ''
  ).trim() || '-';
}

function statusPill(status) {
  const s = normalizeApprovalStatus(status);
  if (s === 'approved') return { label: 'Approved', className: 'is-approved' };
  if (s === 'pending') return { label: 'Pending', className: 'is-pending' };
  if (s === 'rejected') return { label: 'Rejected', className: 'is-rejected' };
  return { label: 'Draft', className: 'is-draft' };
}

export default function LettersListPage() {
  const cfg = useMemo(() => readLetterCfg(), []);
  const accent = cfg.branding?.accentColor || '#FBC51C';
  const [letters, setLetters] = useState(() => listLetters(cfg));
  const [pending, setPending] = useState([]);
  const [isAdmin, setIsAdmin] = useState(Boolean(cfg.isAdmin));
  const [shareRow, setShareRow] = useState(null);
  const [notice, setNotice] = useState('');
  const emptyAnimSrc = String(cfg.emptyAnimationUrl || EMPTY_LOTTIE_FALLBACK).trim() || EMPTY_LOTTIE_FALLBACK;

  const refresh = () => setLetters(listLetters(cfg));

  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  useEffect(() => {
    const flash = consumeLetterFlash();
    if (flash) setNotice(flash);
  }, []);

  useEffect(() => {
    let cancelled = false;
    syncLettersFromServer(cfg).then((result) => {
      if (cancelled) return;
      setLetters(result.letters || listLetters(cfg));
      setPending(Array.isArray(result.pending) ? result.pending : []);
      setIsAdmin(Boolean(result.isAdmin ?? cfg.isAdmin));
    });
    return () => { cancelled = true; };
  }, [cfg]);

  const showSentNotice = (count = 1) => {
    const n = Math.max(1, Number(count) || 1);
    setNotice(n === 1 ? 'Letter sent.' : `Letter sent to ${n} employees.`);
  };

  const handleNew = () => {
    window.location.href = composeHref(cfg);
  };

  const handleOpen = (id) => {
    window.location.href = composeHref(cfg, id);
  };

  const handleDelete = (event, id, title) => {
    event.stopPropagation();
    const label = title || 'this letter';
    if (!window.confirm(`Delete ${label}?`)) return;
    deleteLetter(id, cfg);
    refresh();
    setPending((rows) => rows.filter((r) => String(r.id) !== String(id)));
  };

  const handleToggleVisibility = (event, row) => {
    event.stopPropagation();
    const nextVisibility = normalizeVisibility(row.visibility) === 'public' ? 'private' : 'public';
    upsertLetter(
      {
        ...row,
        visibility: nextVisibility,
      },
      cfg
    );
    refresh();
  };

  const handleShare = (event, row) => {
    event.stopPropagation();
    setShareRow(row);
  };

  const pendingOnly = pending.filter(
    (row) => !letters.some((mine) => String(mine.id) === String(row.id))
  );

  return (
    <div className="letter-list-page" style={{ '--lh-accent': accent }}>
      {notice ? (
        <div className="letter-flash-ok" role="status">
          {notice}
        </div>
      ) : null}

      <div className="letter-list-head">
        <div>
          <p>Create a letter, then submit it for admin approval. The company stamp appears only after approval.</p>
        </div>
        <button type="button" className="letter-btn letter-btn-primary letter-btn--pill" onClick={handleNew}>
          <FilePlus2 size={16} />
          New letter
        </button>
      </div>

      {isAdmin && pending.length > 0 ? (
        <div className="letter-table-wrap letter-card" style={{ marginBottom: '1rem' }}>
          <div className="letter-list-section-title">
            <CheckCircle2 size={16} />
            Awaiting your approval ({pending.length})
          </div>
          <table className="letter-table">
            <thead>
              <tr>
                <th className="letter-col-sn">S/N</th>
                <th>From</th>
                <th>To</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {pending.map((row, index) => {
                const pill = statusPill(row.status);
                return (
                  <tr key={`pending-${row.id}`} onClick={() => handleOpen(row.id)}>
                    <td className="letter-col-sn">{index + 1}</td>
                    <td className="letter-col-name">{authorLabel(row, cfg)}</td>
                    <td>{recipientLabel(row.form)}</td>
                    <td>
                      <span className={`letter-approval-pill ${pill.className}`}>{pill.label}</span>
                    </td>
                    <td>{formatListDate(row.updatedAt || row.createdAt)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : null}

      {letters.length === 0 && pendingOnly.length === 0 ? (
        <div className="letter-list-empty letter-card">
          <div className="letter-empty-lottie" aria-hidden="true">
            <dotlottie-wc
              src={emptyAnimSrc}
              autoplay
              loop
              speed="1"
              style={{ width: '220px', height: '220px' }}
            />
          </div>
          <h2>No letters yet</h2>
          <p>Create a letter and it will appear here as you type.</p>
          <button type="button" className="letter-btn letter-btn-primary letter-btn--pill" onClick={handleNew}>
            <FilePlus2 size={16} />
            Create letter
          </button>
        </div>
      ) : letters.length > 0 ? (
        <div className="letter-table-wrap letter-card">
          <table className="letter-table">
            <thead>
              <tr>
                <th className="letter-col-sn">S/N</th>
                <th>Name</th>
                <th>To</th>
                <th>Status</th>
                <th>Date</th>
                <th className="letter-col-actions" aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {letters.map((row, index) => {
                const visibility = normalizeVisibility(row.visibility);
                const isPrivate = visibility === 'private';
                const pill = statusPill(row.status);
                return (
                  <tr key={row.id} onClick={() => handleOpen(row.id)}>
                    <td className="letter-col-sn">{index + 1}</td>
                    <td className="letter-col-name">{authorLabel(row, cfg)}</td>
                    <td>{recipientLabel(row.form)}</td>
                    <td>
                      <span className={`letter-approval-pill ${pill.className}`}>{pill.label}</span>
                    </td>
                    <td>{formatListDate(row.updatedAt || row.createdAt)}</td>
                    <td className="letter-col-actions">
                      <div className="letter-row-actions">
                        {isPrivate ? (
                          <button
                            type="button"
                            className="letter-status-pill is-private"
                            title="Private - click to make public"
                            aria-label="Private"
                            onClick={(event) => handleToggleVisibility(event, row)}
                          >
                            <Lock size={14} />
                          </button>
                        ) : (
                          <button
                            type="button"
                            className="letter-status-pill is-public letter-status-pill--empty"
                            title="Public - click to make private"
                            aria-label="Public"
                            onClick={(event) => handleToggleVisibility(event, row)}
                          />
                        )}
                        <button
                          type="button"
                          className="letter-share-btn"
                          title="Share letter"
                          aria-label="Share letter"
                          onClick={(event) => handleShare(event, row)}
                        >
                          <Share2 size={16} />
                        </button>
                        <button
                          type="button"
                          className="letter-list-delete"
                          aria-label={`Delete ${row.title || 'letter'}`}
                          onClick={(event) => handleDelete(event, row.id, row.title)}
                        >
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : null}

      {shareRow ? (
        <ShareLetterModal
          letter={shareRow}
          onClose={() => setShareRow(null)}
          onShared={(result) => {
            refresh();
            if (result?.type === 'employees') {
              showSentNotice(result.count);
            }
          }}
        />
      ) : null}
    </div>
  );
}
