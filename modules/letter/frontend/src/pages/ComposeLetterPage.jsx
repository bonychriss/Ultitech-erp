import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, Download, Save } from 'lucide-react';
import LetterheadDocument from '../components/LetterheadDocument.jsx';
import {
  createLetterId,
  getLetter,
  letterDisplayTitle,
  listHref,
  readLetterCfg,
  upsertLetter,
} from '../utils/letterStore.js';

function formatDisplayDate(iso) {
  if (!iso) return '';
  const d = new Date(`${iso}T12:00:00`);
  if (Number.isNaN(d.getTime())) return iso;
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}-${mm}-${d.getFullYear()}.`;
}

function buildDefaults(cfg, today) {
  const branding = cfg.branding || {};
  const user = cfg.user || {};
  return {
    letterDate: today,
    letterDateLabel: formatDisplayDate(today),
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
    signName: user.name || '',
    signTitle: user.title || '',
    companyName: branding.companyName || 'ULTIMATE GENERAL TRADING',
  };
}

function readLetterIdFromUrl() {
  try {
    return String(new URLSearchParams(window.location.search).get('id') || '').trim();
  } catch {
    return '';
  }
}

function ensureLetterIdInUrl(letterId) {
  try {
    const url = new URL(window.location.href);
    if (url.searchParams.get('id') === letterId) return;
    url.searchParams.set('id', letterId);
    window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString() + url.hash);
  } catch {
    /* ignore */
  }
}

export default function ComposeLetterPage() {
  const cfg = useMemo(() => readLetterCfg(), []);
  const branding = cfg.branding || {};
  const user = cfg.user || {};
  const signatureUrl = String(cfg.signatureUrl || user.signatureUrl || '').trim();
  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);
  const defaults = useMemo(() => buildDefaults(cfg, today), [cfg, today]);

  const boot = useMemo(() => {
    let id = readLetterIdFromUrl();
    let createdAt = new Date().toISOString();
    let form = defaults;
    if (id) {
      const existing = getLetter(id, cfg);
      if (existing?.form) {
        form = { ...defaults, ...existing.form };
        createdAt = existing.createdAt || createdAt;
      }
    } else {
      id = createLetterId();
    }
    return { id, form, createdAt };
  }, [cfg, defaults]);

  const [letterId] = useState(boot.id);
  const [createdAt] = useState(boot.createdAt);
  const [form, setForm] = useState(boot.form);
  const [saveState, setSaveState] = useState('idle');
  const [downloading, setDownloading] = useState(false);
  const savedTimerRef = useRef(null);
  const formRef = useRef(form);
  formRef.current = form;

  const accent = branding.accentColor || '#FBC51C';

  const persist = (nextForm) => {
    return upsertLetter(
      {
        id: letterId,
        form: nextForm,
        createdAt,
        title: letterDisplayTitle(nextForm),
      },
      cfg
    );
  };

  useEffect(() => {
    ensureLetterIdInUrl(letterId);
    persist(formRef.current);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [letterId]);

  useEffect(() => {
    return () => {
      if (savedTimerRef.current) {
        window.clearTimeout(savedTimerRef.current);
      }
    };
  }, []);

  useEffect(() => {
    const flush = () => {
      persist(formRef.current);
    };
    const onHide = () => {
      if (document.visibilityState === 'hidden') flush();
    };
    window.addEventListener('beforeunload', flush);
    document.addEventListener('visibilitychange', onHide);
    return () => {
      window.removeEventListener('beforeunload', flush);
      document.removeEventListener('visibilitychange', onHide);
      flush();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [letterId, createdAt, cfg]);

  const onChange = (key, value) => {
    setForm((prev) => {
      const next = { ...prev, [key]: value };
      persist(next);
      return next;
    });
    setSaveState('typing');
    if (savedTimerRef.current) {
      window.clearTimeout(savedTimerRef.current);
    }
    savedTimerRef.current = window.setTimeout(() => {
      setSaveState('saved');
    }, 700);
  };

  const doc = {
    ...form,
    showUltimateStamp: Boolean(cfg.showUltimateStamp || cfg.isUltimateCompany),
    signatureUrl,
  };

  const statusLabel =
    saveState === 'typing' ? 'Typing...' : saveState === 'saved' ? 'Saved' : '';

  const handleSaveLetter = () => {
    const ok = persist(formRef.current);
    if (savedTimerRef.current) {
      window.clearTimeout(savedTimerRef.current);
    }
    setSaveState(ok ? 'saved' : 'idle');
  };

  const handleDownloadLetter = async () => {
    if (downloading) return;
    persist(formRef.current);
    setDownloading(true);
    try {
      const { downloadLetterAsPdf } = await import('../utils/letterPdf.js');
      const subject = String(formRef.current.subject || '').trim();
      const stamp = String(formRef.current.letterDateLabel || today).replace(/\./g, '');
      const filename = subject
        ? `letter-${subject}`
        : `letter-${stamp || today}`;
      await downloadLetterAsPdf(document.querySelector('.lh-page'), { filename });
    } catch (err) {
      const msg = err && err.message ? err.message : 'Failed to download PDF.';
      window.alert(msg);
    } finally {
      setDownloading(false);
    }
  };

  return (
    <div className="letter-workspace" style={{ '--lh-accent': accent }}>
      <div className="letter-topbar letter-toolbar">
        <a className="letter-back-link" href={listHref(cfg)}>
          <ArrowLeft size={16} />
          Letters
        </a>
        {statusLabel ? (
          <span
            className={`letter-save-status${saveState === 'typing' ? ' is-typing' : ' is-saved'}`}
            aria-live="polite"
          >
            {statusLabel}
          </span>
        ) : (
          <span className="letter-topbar-spacer" aria-hidden="true" />
        )}
        <div className="letter-topbar-actions">
          <button
            type="button"
            className="letter-btn letter-btn-primary letter-btn--pill"
            onClick={handleSaveLetter}
          >
            <Save size={16} />
            Save letter
          </button>
          <button
            type="button"
            className="letter-btn letter-btn--pill"
            onClick={handleDownloadLetter}
            disabled={downloading}
          >
            <Download size={16} />
            {downloading ? 'Preparing PDF...' : 'Download letter'}
          </button>
        </div>
      </div>

      <section className="letter-preview-pane">
        <div className="letter-preview-label">Click any line on the letter to edit</div>
        <LetterheadDocument doc={doc} editable onChange={onChange} />
      </section>
    </div>
  );
}
