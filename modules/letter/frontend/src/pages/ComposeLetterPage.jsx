import { useEffect, useMemo, useRef, useState } from 'react';
import { Download, Save } from 'lucide-react';
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

function draftStorageKey(cfg) {
  const slug = String(cfg.companySlug || 'company').trim() || 'company';
  const uid = Number(cfg.user?.id || 0) || 0;
  return `letter-draft:v1:${slug}:${uid}`;
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

function loadDraft(key, defaults) {
  if (typeof window === 'undefined') return defaults;
  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) return defaults;
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return defaults;
    return { ...defaults, ...parsed };
  } catch {
    return defaults;
  }
}

function saveDraft(key, form) {
  if (typeof window === 'undefined') return false;
  try {
    window.localStorage.setItem(key, JSON.stringify(form));
    return true;
  } catch {
    return false;
  }
}

export default function ComposeLetterPage() {
  const cfg = useMemo(() => readCfg(), []);
  const branding = cfg.branding || {};
  const user = cfg.user || {};
  const signatureUrl = String(cfg.signatureUrl || user.signatureUrl || '').trim();
  const storageKey = useMemo(() => draftStorageKey(cfg), [cfg]);
  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);

  const [form, setForm] = useState(() => loadDraft(storageKey, buildDefaults(cfg, today)));
  const [saveState, setSaveState] = useState('idle');
  const [downloading, setDownloading] = useState(false);
  const savedTimerRef = useRef(null);
  const formRef = useRef(form);
  formRef.current = form;

  const accent = branding.accentColor || '#FBC51C';

  useEffect(() => {
    return () => {
      if (savedTimerRef.current) {
        window.clearTimeout(savedTimerRef.current);
      }
    };
  }, []);

  useEffect(() => {
    const flush = () => {
      saveDraft(storageKey, formRef.current);
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
  }, [storageKey]);

  const onChange = (key, value) => {
    setForm((prev) => {
      const next = { ...prev, [key]: value };
      saveDraft(storageKey, next);
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
    const ok = saveDraft(storageKey, formRef.current);
    if (savedTimerRef.current) {
      window.clearTimeout(savedTimerRef.current);
    }
    setSaveState(ok ? 'saved' : 'idle');
  };

  const handleDownloadLetter = async () => {
    if (downloading) return;
    saveDraft(storageKey, formRef.current);
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
