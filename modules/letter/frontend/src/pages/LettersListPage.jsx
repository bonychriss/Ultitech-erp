import { useEffect, useMemo, useState } from 'react';
import { FilePlus2, FileText, Lock, Share2, Trash2 } from 'lucide-react';
import ShareLetterModal from '../components/ShareLetterModal.jsx';
import {
  composeHref,
  consumeLetterFlash,
  deleteLetter,
  formatListDate,
  listLetters,
  normalizeVisibility,
  readLetterCfg,
  upsertLetter,
} from '../utils/letterStore.js';

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

export default function LettersListPage() {
  const cfg = useMemo(() => readLetterCfg(), []);
  const accent = cfg.branding?.accentColor || '#FBC51C';
  const [letters, setLetters] = useState(() => listLetters(cfg));
  const [shareRow, setShareRow] = useState(null);
  const [notice, setNotice] = useState('');

  const refresh = () => setLetters(listLetters(cfg));

  useEffect(() => {
    const flash = consumeLetterFlash();
    if (flash) setNotice(flash);
  }, []);

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

  return (
    <div className="letter-list-page" style={{ '--lh-accent': accent }}>
      {notice ? (
        <div className="letter-flash-ok" role="status">
          {notice}
        </div>
      ) : null}

      <div className="letter-list-head">
        <div>
          <p>Your letters are saved automatically. Open one to keep editing.</p>
        </div>
        <button type="button" className="letter-btn letter-btn-primary letter-btn--pill" onClick={handleNew}>
          <FilePlus2 size={16} />
          New letter
        </button>
      </div>

      {letters.length === 0 ? (
        <div className="letter-list-empty letter-card">
          <FileText size={28} />
          <h2>No letters yet</h2>
          <p>Create a letter and it will appear here as you type.</p>
          <button type="button" className="letter-btn letter-btn-primary letter-btn--pill" onClick={handleNew}>
            <FilePlus2 size={16} />
            Create letter
          </button>
        </div>
      ) : (
        <div className="letter-table-wrap letter-card">
          <table className="letter-table">
            <thead>
              <tr>
                <th className="letter-col-sn">S/N</th>
                <th>Name</th>
                <th>To</th>
                <th>Date</th>
                <th className="letter-col-actions" aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {letters.map((row, index) => {
                const visibility = normalizeVisibility(row.visibility);
                const isPrivate = visibility === 'private';
                return (
                  <tr key={row.id} onClick={() => handleOpen(row.id)}>
                    <td className="letter-col-sn">{index + 1}</td>
                    <td className="letter-col-name">{authorLabel(row, cfg)}</td>
                    <td>{recipientLabel(row.form)}</td>
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
      )}

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
