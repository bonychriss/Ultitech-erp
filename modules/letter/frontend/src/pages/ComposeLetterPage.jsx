import { useMemo, useState } from 'react';
import { FilePlus2, Printer, X } from 'lucide-react';
import LetterheadDocument from '../components/LetterheadDocument.jsx';

function readCfg() {
  if (typeof window !== 'undefined' && window.__LETTER_CFG__ && typeof window.__LETTER_CFG__ === 'object') {
    return window.__LETTER_CFG__;
  }
  return {};
}

function formatDisplayDate(iso) {
  if (!iso) return '';
  const d = new Date(`${iso}T12:00:00`);
  if (Number.isNaN(d.getTime())) return iso;
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}-${mm}-${d.getFullYear()}.`;
}

export default function ComposeLetterPage() {
  const cfg = useMemo(() => readCfg(), []);
  const branding = cfg.branding || {};

  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);
  const [editing, setEditing] = useState(false);

  const [form, setForm] = useState({
    letterDate: today,
    fromCompany: `${branding.companyName || 'ULTIMATE GENERAL TRADING'},`.toUpperCase(),
    fromBox: 'P.O.BOX 78004,',
    fromCity: 'DAR ES SALAAM, TANZANIA.',
    recipientName: '',
    recipientCompany: '',
    recipientAddress: '',
    recipientCity: '',
    subject: '',
    salutation: '',
    body: '',
    closing: '',
    signName: '',
    signTitle: '',
    companyName: branding.companyName || 'ULTIMATE GENERAL TRADING',
  });

  const accent = branding.accentColor || '#FBC51C';

  const update = (key) => (event) => {
    setForm((prev) => ({ ...prev, [key]: event.target.value }));
  };

  const doc = {
    ...form,
    letterDateLabel: formatDisplayDate(form.letterDate),
  };

  return (
    <div
      className={`letter-workspace${editing ? ' is-editing' : ''}`}
      style={{ '--lh-accent': accent }}
    >
      <div className="letter-topbar letter-toolbar">
        {!editing ? (
          <button
            type="button"
            className="letter-btn letter-btn-primary letter-btn--pill"
            onClick={() => setEditing(true)}
          >
            <FilePlus2 size={16} />
            Create letter
          </button>
        ) : null}
      </div>

      {editing ? (
        <aside className="letter-composer-sidebar letter-card">
          <div className="letter-card-head">
            <div className="letter-card-head-row">
              <div>
                <h2>Letter details</h2>
                <p>Official letter on Ultimate letterhead template.</p>
              </div>
              <button
                type="button"
                className="letter-icon-btn"
                aria-label="Close letter details"
                onClick={() => setEditing(false)}
              >
                <X size={18} />
              </button>
            </div>
          </div>

          <div className="letter-form-stack">
            <label className="letter-field">
              <span>Date</span>
              <input type="date" value={form.letterDate} onChange={update('letterDate')} />
            </label>
            <label className="letter-field">
              <span>From (company)</span>
              <input value={form.fromCompany} onChange={update('fromCompany')} />
            </label>
            <label className="letter-field">
              <span>From (P.O. Box)</span>
              <input value={form.fromBox} onChange={update('fromBox')} />
            </label>
            <label className="letter-field">
              <span>From (city)</span>
              <input value={form.fromCity} onChange={update('fromCity')} />
            </label>
            <label className="letter-field">
              <span>Recipient name / title</span>
              <input value={form.recipientName} onChange={update('recipientName')} placeholder="e.g. DIRECT GENERAL," />
            </label>
            <label className="letter-field">
              <span>Recipient company</span>
              <input value={form.recipientCompany} onChange={update('recipientCompany')} />
            </label>
            <label className="letter-field">
              <span>Recipient address</span>
              <input value={form.recipientAddress} onChange={update('recipientAddress')} placeholder="P.O.BOX ..." />
            </label>
            <label className="letter-field">
              <span>City, country</span>
              <input value={form.recipientCity} onChange={update('recipientCity')} placeholder="DAR ES SALAAM, TANZANIA." />
            </label>
            <label className="letter-field">
              <span>Subject / REF</span>
              <input value={form.subject} onChange={update('subject')} />
            </label>
            <label className="letter-field">
              <span>Salutation</span>
              <input value={form.salutation} onChange={update('salutation')} />
            </label>
            <label className="letter-field">
              <span>Letter body</span>
              <textarea rows={12} value={form.body} onChange={update('body')} />
            </label>
            <label className="letter-field">
              <span>Closing</span>
              <input value={form.closing} onChange={update('closing')} />
            </label>
            <label className="letter-field">
              <span>Signatory name</span>
              <input value={form.signName} onChange={update('signName')} />
            </label>
            <label className="letter-field">
              <span>Signatory title</span>
              <input value={form.signTitle} onChange={update('signTitle')} />
            </label>
          </div>

          <div className="letter-toolbar">
            <button type="button" className="letter-btn letter-btn-primary letter-btn--pill" onClick={() => window.print()}>
              <Printer size={16} />
              Print letter
            </button>
          </div>
        </aside>
      ) : null}

      <section className="letter-preview-pane">
        <div className="letter-preview-label">Official letter preview</div>
        <LetterheadDocument doc={doc} />
      </section>
    </div>
  );
}
