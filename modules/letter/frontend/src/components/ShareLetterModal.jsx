import { useMemo, useState } from 'react';
import { Mail, Search, Send, Share2, X } from 'lucide-react';
import {
  emailShareUrl,
  publishLetterPublic,
  readLetterCfg,
  sendLetterToEmployees,
  whatsappShareUrl,
} from '../utils/letterStore.js';

function WhatsAppIcon({ size = 16 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-1.99.522.668-1.94-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
    </svg>
  );
}

export default function ShareLetterModal({ letter, onClose, onShared }) {
  const cfg = useMemo(() => readLetterCfg(), []);
  const employees = Array.isArray(cfg.employees) ? cfg.employees : [];
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState(() => new Set());
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return employees;
    return employees.filter((emp) => {
      const hay = `${emp.name || ''} ${emp.email || ''} ${emp.department || ''} ${emp.phone || ''}`.toLowerCase();
      return hay.includes(q);
    });
  }, [employees, query]);

  const toggleEmployee = (id) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const handleSendToEmployees = async () => {
    if (!selected.size) {
      setMessage('Select at least one employee.');
      return;
    }
    setBusy(true);
    setMessage('');
    try {
      const ok = sendLetterToEmployees(letter, [...selected], cfg);
      if (!ok) {
        setMessage('Could not send to selected employees.');
        return;
      }
      onShared?.({ type: 'employees', count: selected.size });
      onClose?.();
    } finally {
      setBusy(false);
    }
  };

  const handleWhatsApp = () => {
    const first = [...selected]
      .map((id) => employees.find((emp) => Number(emp.id) === Number(id)))
      .find((emp) => emp && String(emp.phone || '').trim());
    const url = whatsappShareUrl(letter, cfg, first?.phone || '');
    window.open(url, '_blank', 'noopener,noreferrer');
    onShared?.({ type: 'whatsapp' });
  };

  const handleEmail = () => {
    const first = [...selected]
      .map((id) => employees.find((emp) => Number(emp.id) === Number(id)))
      .find((emp) => emp && String(emp.email || '').trim());
    window.location.href = emailShareUrl(letter, cfg, first?.email || '');
    onShared?.({ type: 'email' });
  };

  const handleCopyPublic = async () => {
    setBusy(true);
    try {
      const ok = await publishLetterPublic(letter, cfg);
      setMessage(ok ? 'Made public and link copied.' : 'Could not share letter.');
      if (ok) {
        onShared?.({ type: 'public' });
        window.setTimeout(() => onClose?.(), 700);
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="letter-share-backdrop" role="presentation" onClick={onClose}>
      <div
        className="letter-share-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Share letter"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="letter-share-modal-head">
          <div>
            <h2>Share letter</h2>
            <p>Send to employees, WhatsApp, or email.</p>
          </div>
          <button type="button" className="letter-icon-btn" aria-label="Close" onClick={onClose}>
            <X size={18} />
          </button>
        </div>

        <label className="letter-share-search">
          <Search size={16} />
          <input
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search employees"
          />
        </label>

        <div className="letter-share-section-label">Send to fellow employees</div>
        <div className="letter-share-employees">
          {filtered.length === 0 ? (
            <p className="letter-share-empty">No employees found.</p>
          ) : (
            filtered.map((emp) => {
              const id = Number(emp.id);
              const checked = selected.has(id);
              return (
                <label key={id} className={`letter-share-employee${checked ? ' is-selected' : ''}`}>
                  <input
                    type="checkbox"
                    checked={checked}
                    onChange={() => toggleEmployee(id)}
                  />
                  <span className="letter-share-employee-copy">
                    <strong>{emp.name || `User #${id}`}</strong>
                    <small>
                      {[emp.department, emp.email, emp.phone].filter(Boolean).join(' - ') || 'No contact details'}
                    </small>
                  </span>
                </label>
              );
            })
          )}
        </div>

        {message ? <p className="letter-share-message">{message}</p> : null}

        <div className="letter-share-actions">
          <button
            type="button"
            className={`letter-share-action-btn letter-share-action-btn--primary${selected.size > 0 ? ' is-ready' : ''}`}
            onClick={handleSendToEmployees}
            disabled={busy}
            title="Send to inbox"
            aria-label="Send to inbox"
          >
            <Send size={18} />
          </button>
          <button
            type="button"
            className="letter-share-action-btn"
            onClick={handleWhatsApp}
            title="WhatsApp"
            aria-label="WhatsApp"
          >
            <WhatsAppIcon size={18} />
          </button>
          <button
            type="button"
            className="letter-share-action-btn"
            onClick={handleEmail}
            title="Email"
            aria-label="Email"
          >
            <Mail size={18} />
          </button>
          <button
            type="button"
            className="letter-share-action-btn"
            onClick={handleCopyPublic}
            disabled={busy}
            title="Public link"
            aria-label="Public link"
          >
            <Share2 size={18} />
          </button>
        </div>
      </div>
    </div>
  );
}
