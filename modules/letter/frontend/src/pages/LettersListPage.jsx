import { useMemo, useState } from 'react';
import { FilePlus2, FileText, Trash2 } from 'lucide-react';
import {
  composeHref,
  deleteLetter,
  formatListDate,
  listLetters,
  readLetterCfg,
} from '../utils/letterStore.js';

export default function LettersListPage() {
  const cfg = useMemo(() => readLetterCfg(), []);
  const accent = cfg.branding?.accentColor || '#FBC51C';
  const [letters, setLetters] = useState(() => listLetters(cfg));

  const refresh = () => setLetters(listLetters(cfg));

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

  return (
    <div className="letter-list-page" style={{ '--lh-accent': accent }}>
      <div className="letter-list-head">
        <div>
          <h1>Letters</h1>
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
        <ul className="letter-list">
          {letters.map((row) => (
            <li key={row.id}>
              <button type="button" className="letter-list-row" onClick={() => handleOpen(row.id)}>
                <span className="letter-list-row-main">
                  <span className="letter-list-title">{row.title || 'Untitled letter'}</span>
                  <span className="letter-list-meta">
                    {row.form?.recipientName || row.form?.recipientCompany
                      ? `To: ${row.form.recipientName || row.form.recipientCompany}`
                      : 'No recipient yet'}
                    {' · '}
                    Updated {formatListDate(row.updatedAt)}
                  </span>
                </span>
                <span
                  className="letter-list-delete"
                  role="button"
                  tabIndex={0}
                  aria-label={`Delete ${row.title || 'letter'}`}
                  onClick={(event) => handleDelete(event, row.id, row.title)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      handleDelete(event, row.id, row.title);
                    }
                  }}
                >
                  <Trash2 size={16} />
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
