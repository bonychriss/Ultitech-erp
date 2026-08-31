import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowLeft,
  Banknote,
  CheckCircle2,
  Download,
  ExternalLink,
  FolderOpen,
  HelpCircle,
  Info,
  Landmark,
  Lightbulb,
  Loader2,
  Lock,
  Sparkles,
  UploadCloud,
  X,
} from 'lucide-react';
import {
  classifyImportRows,
  commitImportRows,
  deskPageUrl,
  fetchImportInit,
  importTemplateUrl,
  previewImportFile,
} from '../api/expensesDesk';

const MAX_BYTES = 10 * 1024 * 1024;
const FLAG_BASE = 'https://flagcdn.com/w40/';

function normalizeCurrencyIso(code) {
  const value = String(code || '').trim().toUpperCase();
  if (value === 'TSH') return 'TZS';
  return value;
}

function findCurrencyMeta(list, currency) {
  const iso = normalizeCurrencyIso(currency);
  const found = (list || []).find((opt) => normalizeCurrencyIso(opt.iso || opt.code) === iso);
  if (found) return found;
  if (iso === 'TZS') {
    return { code: 'TSh', iso: 'TZS', name: 'Tanzanian Shilling', flag: 'tz' };
  }
  return { code: currency, iso, name: currency, flag: '' };
}

function currencyMatchesOption(opt, currency) {
  return normalizeCurrencyIso(opt.iso || opt.code) === normalizeCurrencyIso(currency);
}

function flagUrl(flagCode, currencyIso = '') {
  let code = String(flagCode || '').toLowerCase();
  if (!code) {
    code = normalizeCurrencyIso(currencyIso) === 'TZS' ? 'tz' : 'un';
  }
  return `${FLAG_BASE}${code}.png`;
}

function formatMoney(value) {
  const amount = Number(value) || 0;
  return amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function validateFile(file) {
  const ext = String(file.name || '').split('.').pop().toLowerCase();
  if (ext === 'xls') {
    return 'Legacy .xls is not supported. Re-save the sheet as .xlsx or .csv and try again.';
  }
  if (!['xlsx', 'csv', 'txt'].includes(ext)) {
    return 'Unsupported file type. Upload an Excel (.xlsx) or CSV (.csv) file.';
  }
  if (file.size > MAX_BYTES) {
    return 'That file is larger than 10MB. Split it into smaller files and try again.';
  }
  return '';
}

function SpreadsheetIllustration() {
  return (
    <div className="exp-imp-illus" aria-hidden="true">
      <span className="exp-imp-illus-blob exp-imp-illus-blob--1" />
      <span className="exp-imp-illus-blob exp-imp-illus-blob--2" />
      <span className="exp-imp-illus-dots exp-imp-illus-dots--left" />
      <span className="exp-imp-illus-dots exp-imp-illus-dots--right" />
      <div className="exp-imp-sheet">
        <div className="exp-imp-sheet-head">
          {Array.from({ length: 4 }).map((_, i) => (
            <span key={`h-${i}`} />
          ))}
        </div>
        <div className="exp-imp-sheet-body">
          {Array.from({ length: 16 }).map((_, i) => (
            <span key={`c-${i}`} />
          ))}
        </div>
      </div>
      <span className="exp-imp-xls">X</span>
    </div>
  );
}

function Modal({ title, onClose, children, className = '' }) {
  return (
    <div className="exp-imp-modal-overlay" role="dialog" aria-modal="true" onClick={onClose}>
      <div
        className={`exp-imp-modal${className ? ` ${className}` : ''}`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="exp-imp-modal-head">
          <h3>{title}</h3>
          <button type="button" className="exp-imp-modal-close" onClick={onClose} aria-label="Close">
            <X size={16} />
          </button>
        </div>
        <div className="exp-imp-modal-body">{children}</div>
      </div>
    </div>
  );
}

function buildTemplateExampleRows(expenseOptions) {
  const names = (expenseOptions || [])
    .map((opt) => String(opt.name || '').trim())
    .filter(Boolean);
  const samples = [
    names[0] || 'FUEL',
    names[1] || names[0] || 'TRANSPORT',
    names[2] || names[0] || 'AIRTIME',
  ];
  return [
    ['7-Apr', samples[0], '4000.00', '4000.00'],
    ['9-Apr', samples[1], '20000.00', '16949.15'],
    ['10-Apr', samples[2], '10000.00', '10000.00'],
  ];
}

function TemplateExampleSheet({ expenseOptions }) {
  const headers = ['DATE', 'EXPENSE ACCOUNT', 'AMOUNT', 'VAT EXCLUSIVE'];
  const colLetters = ['A', 'B', 'C', 'D'];
  const sampleRows = buildTemplateExampleRows(expenseOptions);
  const emptyRows = 2;

  return (
    <div className="exp-xls" aria-label="Example import template spreadsheet">
      <div className="exp-xls-titlebar">
        <span className="exp-xls-badge" aria-hidden="true">
          X
        </span>
        <div className="exp-xls-title-text">
          <strong>expenses-import-template.csv</strong>
          <span>Excel / CSV template</span>
        </div>
      </div>

      <div className="exp-xls-grid-wrap">
        <table className="exp-xls-grid">
          <thead>
            <tr>
              <th className="exp-xls-corner" scope="col" />
              {colLetters.map((letter) => (
                <th key={letter} className="exp-xls-colhead" scope="col">
                  {letter}
                </th>
              ))}
            </tr>
            <tr>
              <th className="exp-xls-rowhead" scope="row">
                1
              </th>
              {headers.map((header) => (
                <th key={header} className="exp-xls-header-cell" scope="col">
                  {header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {sampleRows.map((cells, rowIndex) => (
              <tr key={`sample-${rowIndex}`}>
                <th className="exp-xls-rowhead" scope="row">
                  {rowIndex + 2}
                </th>
                {cells.map((cell, cellIndex) => (
                  <td
                    key={`${rowIndex}-${cellIndex}`}
                    className={cellIndex >= 2 ? 'exp-xls-num' : undefined}
                  >
                    {cell}
                  </td>
                ))}
              </tr>
            ))}
            {Array.from({ length: emptyRows }).map((_, rowIndex) => (
              <tr key={`empty-${rowIndex}`} className="exp-xls-empty">
                <th className="exp-xls-rowhead" scope="row">
                  {sampleRows.length + rowIndex + 2}
                </th>
                {headers.map((_, cellIndex) => (
                  <td key={`empty-${rowIndex}-${cellIndex}`} />
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="exp-xls-tabs" aria-hidden="true">
        <span className="exp-xls-tab is-active">Expenses</span>
        <span className="exp-xls-tab-add">+</span>
      </div>
    </div>
  );
}

export default function ExpenseImportPage() {
  const fileInputRef = useRef(null);
  const downloadResetRef = useRef(null);
  const currencyRef = useRef(null);
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [classifying, setClassifying] = useState(false);
  const [classifyNote, setClassifyNote] = useState('');
  const [viaAi, setViaAi] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(null);
  const [fileName, setFileName] = useState('');
  const redirectTimerRef = useRef(null);
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState(null);
  const [dragging, setDragging] = useState(false);
  const [showExample, setShowExample] = useState(false);
  const [showGuide, setShowGuide] = useState(false);
  const [downloadUi, setDownloadUi] = useState({ state: 'idle', progress: 0 });
  const [currencyOpen, setCurrencyOpen] = useState(false);

  const [defaultYear, setDefaultYear] = useState(new Date().getFullYear());
  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [mainPaymentId, setMainPaymentId] = useState('');
  const [sourceAccountId, setSourceAccountId] = useState('');
  const [currency, setCurrency] = useState('TZS');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await fetchImportInit();
        if (cancelled) return;
        setInit(data);
        setDefaultYear(Number(data.default_year) || new Date().getFullYear());
        setCurrency(data.default_currency || 'TZS');
        const flat = data.payment?.flat || [];
        const hasCash = flat.some((row) => String(row.kind || '').toLowerCase() === 'cash');
        setPaymentMethod(hasCash ? 'cash' : 'bank_transfer');
      } catch (err) {
        if (!cancelled) setError(err.message || 'Failed to load import options.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
      if (downloadResetRef.current) {
        clearTimeout(downloadResetRef.current);
      }
      if (redirectTimerRef.current) {
        clearTimeout(redirectTimerRef.current);
      }
    };
  }, []);

  useEffect(() => {
    if (!currencyOpen) return undefined;
    function onDocClick(event) {
      if (currencyRef.current && !currencyRef.current.contains(event.target)) {
        setCurrencyOpen(false);
      }
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [currencyOpen]);

  async function handleDownloadTemplate() {
    if (downloadUi.state === 'downloading') return;

    if (downloadResetRef.current) {
      clearTimeout(downloadResetRef.current);
      downloadResetRef.current = null;
    }

    setDownloadUi({ state: 'downloading', progress: 8 });
    setError('');

    let soft = 8;
    const softTimer = setInterval(() => {
      soft = Math.min(88, soft + 7 + Math.random() * 8);
      setDownloadUi((prev) =>
        prev.state === 'downloading' ? { ...prev, progress: Math.max(prev.progress, soft) } : prev,
      );
    }, 90);

    try {
      const res = await fetch(importTemplateUrl(), { credentials: 'same-origin' });
      if (!res.ok) {
        throw new Error(`Download failed (${res.status})`);
      }

      const dispo = res.headers.get('content-disposition') || '';
      const match = dispo.match(/filename="([^"]+)"/i);
      const filename = match?.[1] || 'expenses-import-template.csv';
      const blob = await res.blob();
      if (!blob || blob.size === 0) {
        throw new Error('Template file was empty.');
      }

      clearInterval(softTimer);
      setDownloadUi({ state: 'downloading', progress: 100 });

      const objectUrl = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = objectUrl;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      setTimeout(() => URL.revokeObjectURL(objectUrl), 2500);

      await new Promise((resolve) => setTimeout(resolve, 220));
      setDownloadUi({ state: 'success', progress: 100 });
      downloadResetRef.current = setTimeout(() => {
        setDownloadUi({ state: 'idle', progress: 0 });
        downloadResetRef.current = null;
      }, 2600);
    } catch (err) {
      clearInterval(softTimer);
      setDownloadUi({ state: 'error', progress: 0 });
      setError(err.message || 'Could not download the template.');
      downloadResetRef.current = setTimeout(() => {
        setDownloadUi({ state: 'idle', progress: 0 });
        downloadResetRef.current = null;
      }, 2200);
    }
  }

  const paymentHierarchical = Boolean(init?.payment?.hierarchical);

  const expenseOptions = useMemo(() => init?.expense?.flat || [], [init]);
  const selectedCurrencyMeta = useMemo(
    () => findCurrencyMeta(init?.currencies || [], currency),
    [init, currency],
  );

  const paymentChildren = useMemo(() => {
    if (!init) return [];
    const wantCash = paymentMethod === 'cash';
    const all = paymentHierarchical
      ? init.payment?.childrenByParent?.[String(mainPaymentId)] || []
      : init.payment?.flat || [];
    return all.filter((row) => {
      const kind = String(row.kind || '').toLowerCase();
      return wantCash ? kind === 'cash' : kind !== 'cash';
    });
  }, [init, paymentHierarchical, mainPaymentId, paymentMethod]);

  const readyCount = rows.filter((row) => row.ok).length;
  const missingAccountCount = rows.filter((row) => row.ok && !(Number(row.account_id) > 0)).length;
  const importReadyCount = rows.filter((row) => row.ok && Number(row.account_id) > 0).length;

  function setRowAccount(rowNumber, accountIdValue) {
    const id = Number(accountIdValue) || 0;
    const opt = expenseOptions.find((o) => Number(o.id) === id);
    const label = opt ? opt.label || opt.name || '' : '';
    setRows((prev) =>
      prev.map((row) => {
        if (Number(row.row) !== Number(rowNumber)) return row;
        return {
          ...row,
          account_id: id,
          account_label: label,
          description: label || row.account_raw || row.description || '',
          needs_account: id <= 0,
          ai_reason: id > 0 ? 'Chosen by you' : '',
          ai_confidence: id > 0 ? 1 : 0,
        };
      }),
    );
  }

  async function loadFile(file) {
    if (!file || !init?.csrf_token) return;

    const problem = validateFile(file);
    if (problem) {
      setError(problem);
      return;
    }

    setBusy(true);
    setClassifying(false);
    setClassifyNote('');
    setViaAi(false);
    setError('');
    setSuccess(null);
    setRows([]);
    setSummary(null);
    setFileName(file.name);

    try {
      const preview = await previewImportFile(file, {
        csrfToken: init.csrf_token,
        defaultYear,
      });
      const previewRows = preview.rows || [];
      setRows(previewRows);
      setSummary(preview.summary || null);
      setFileName(preview.file_name || file.name);
      setBusy(false);

      if (previewRows.length === 0) {
        return;
      }

      setClassifying(true);
      setClassifyNote(
        init.ai_available
          ? 'Matching expense accounts (and AI for any that do not match exactly)…'
          : 'Matching expense account names to the Chart of Accounts…',
      );
      try {
        const classified = await classifyImportRows({
          csrfToken: init.csrf_token,
          rows: previewRows,
        });
        setRows(classified.rows || previewRows);
        setViaAi(Boolean(classified.via_ai));
        setClassifyNote(
          classified.message
            || (classified.via_ai
              ? 'Expense accounts matched from your sheet (AI filled gaps). Review before importing.'
              : 'Expense accounts matched from your sheet. Review before importing.'),
        );
        const nextSummary = { ...(preview.summary || {}) };
        const mapped = (classified.rows || []).filter((r) => r.ok && Number(r.account_id) > 0).length;
        nextSummary.classified = mapped;
        setSummary(nextSummary);
      } catch (classifyErr) {
        setClassifyNote(classifyErr.message || 'Could not auto-classify accounts. Pick them manually below.');
      } finally {
        setClassifying(false);
      }
    } catch (err) {
      setError(err.message || 'Could not read that file.');
      setFileName('');
      setBusy(false);
      setClassifying(false);
    }
  }

  function handleFileChange(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    void loadFile(file);
  }

  function handleDrop(event) {
    event.preventDefault();
    setDragging(false);
    const file = event.dataTransfer?.files?.[0];
    void loadFile(file);
  }

  async function handleImport() {
    if (!init?.csrf_token) return;
    const validRows = rows.filter((row) => row.ok && Number(row.account_id) > 0);
    if (validRows.length === 0) {
      setError('No rows ready to import. Fix errors and choose an expense account for each row.');
      return;
    }
    if (!sourceAccountId) {
      setError('Select the Paid from (bank or cash) account for this import.');
      return;
    }

    const skipCount = rows.filter((row) => !(row.ok && Number(row.account_id) > 0)).length;

    setBusy(true);
    setError('');
    setSuccess(null);
    try {
      const result = await commitImportRows({
        csrf_token: init.csrf_token,
        rows: validRows.map((row) => ({
          row: row.row,
          ok: true,
          date: row.date,
          description: row.description,
          account_id: row.account_id,
          account_raw: row.account_raw,
          amount: row.amount,
          tax_amount: row.tax_amount,
          payee: row.payee ?? '',
          reference: row.reference ?? '',
        })),
        account_id: 0,
        main_account_id: null,
        source_account_id: sourceAccountId,
        payment_method: paymentMethod,
        currency,
        post_to_ledger: false,
      });
      const imported = result.imported || validRows.length;
      let message =
        result.message
        || `Imported ${imported} drafts. Balances are unchanged until you post.`;
      if (skipCount > 0) {
        message += ` Skipped ${skipCount} row${skipCount === 1 ? '' : 's'} with errors or missing accounts.`;
      }
      const deskUrl = deskPageUrl('index.php', {
        status: 'draft',
        imported: '1',
        count: String(imported),
      });
      setSuccess({ message, imported, deskUrl });
      setRows([]);
      setSummary(null);
      setFileName('');
      setClassifyNote('');
      setViaAi(false);
      if (redirectTimerRef.current) {
        clearTimeout(redirectTimerRef.current);
      }
      redirectTimerRef.current = setTimeout(() => {
        window.location.href = deskUrl;
      }, 1600);
    } catch (err) {
      setError(err.message || 'Import failed.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading import...</span>
      </div>
    );
  }

  const dropBusy = busy || classifying;

  return (
    <div className="exp-imp">
      <header className="exp-imp-head">
        <a className="exp-imp-back" href={deskPageUrl('index.php')}>
          <ArrowLeft size={14} aria-hidden="true" />
          <span>Back to Expenses</span>
        </a>
      </header>

      <div className="exp-imp-strip">
        <div className="exp-imp-strip-lead">
          <Info size={18} className="exp-imp-strip-icon" aria-hidden="true" />
          <span>
            Imports save as drafts. Bank and cash balances do not change until you post each expense.
          </span>
        </div>
        <ul className="exp-imp-strip-items">
          <li>
            <CheckCircle2 size={14} aria-hidden="true" />
            Supports Excel (.xlsx)
          </li>
          <li>
            <CheckCircle2 size={14} aria-hidden="true" />
            Supports CSV (.csv)
          </li>
          <li>
            <CheckCircle2 size={14} aria-hidden="true" />
            Max file size: 10MB
          </li>
        </ul>
      </div>

      {error ? <div className="exp-imp-alert exp-imp-alert--error">{error}</div> : null}
      {success ? (
        <div className="exp-imp-alert exp-imp-alert--ok exp-imp-alert--cta" role="status">
          <div className="exp-imp-alert-cta-body">
            <p className="exp-imp-alert-cta-msg">{success.message}</p>
            <p className="exp-imp-alert-cta-sub">Opening your draft expenses…</p>
          </div>
          <a className="exp-imp-btn-primary exp-imp-btn-primary--pill" href={success.deskUrl}>
            View imported drafts
            <ExternalLink size={14} aria-hidden="true" />
          </a>
        </div>
      ) : null}

      <div className="exp-imp-steps">
        <section className="exp-imp-card">
          <div className="exp-imp-card-head">
            <span className="exp-imp-step">1</span>
            <div>
              <h2 className="exp-imp-card-title">Download template</h2>
              <p className="exp-imp-card-sub">
                Four columns: DATE, EXPENSE ACCOUNT, AMOUNT, VAT EXCLUSIVE. Put the Chart of Accounts
                expense account name in EXPENSE ACCOUNT (e.g. Fuel, Transport).
              </p>
            </div>
          </div>

          <SpreadsheetIllustration />

          <div className={`exp-imp-dl${downloadUi.state !== 'idle' ? ` is-${downloadUi.state}` : ''}`}>
            <button
              type="button"
              className={`exp-imp-btn-primary${downloadUi.state === 'success' ? ' is-success' : ''}${downloadUi.state === 'error' ? ' is-error' : ''}`}
              onClick={handleDownloadTemplate}
              disabled={downloadUi.state === 'downloading'}
              aria-live="polite"
            >
              <span className="exp-imp-dl-icon" aria-hidden="true">
                {downloadUi.state === 'downloading' ? (
                  <Loader2 className="exp-imp-spin" size={16} />
                ) : downloadUi.state === 'success' ? (
                  <CheckCircle2 size={16} />
                ) : (
                  <Download size={16} className={downloadUi.state === 'idle' ? 'exp-imp-dl-bounce' : ''} />
                )}
              </span>
              <span>
                {downloadUi.state === 'downloading'
                  ? 'Downloading…'
                  : downloadUi.state === 'success'
                    ? 'Downloaded!'
                    : downloadUi.state === 'error'
                      ? 'Try again'
                      : 'Download Template'}
              </span>
              {downloadUi.state === 'downloading' ? (
                <span className="exp-imp-dl-pct">{Math.round(downloadUi.progress)}%</span>
              ) : null}
            </button>

            <div
              className={`exp-imp-dl-bar${downloadUi.state === 'downloading' || downloadUi.state === 'success' ? ' is-visible' : ''}`}
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={Math.round(downloadUi.progress)}
              aria-hidden={downloadUi.state === 'idle' || downloadUi.state === 'error'}
            >
              <span
                className={`exp-imp-dl-bar-fill${downloadUi.state === 'success' ? ' is-done' : ''}`}
                style={{ width: `${Math.max(0, Math.min(100, downloadUi.progress))}%` }}
              />
            </div>

            {downloadUi.state === 'success' ? (
              <p className="exp-imp-dl-toast" role="status">
                Template saved — fill DATE, EXPENSE ACCOUNT, AMOUNT, VAT EXCLUSIVE.
              </p>
            ) : null}
          </div>

          <p className="exp-imp-hint">
            <Lightbulb size={13} className="exp-imp-hint-icon" aria-hidden="true" />
            Need help?{' '}
            <button type="button" className="exp-imp-link" onClick={() => setShowExample(true)}>
              View template example
            </button>
          </p>
        </section>

        <div className="exp-imp-connector" aria-hidden="true">
          <svg viewBox="0 0 64 120" preserveAspectRatio="none">
            <path
              d="M2 96 C 30 96, 34 34, 58 26"
              fill="none"
              stroke="#93c5fd"
              strokeWidth="2"
              strokeDasharray="5 5"
              strokeLinecap="round"
            />
            <path d="M52 18 L62 25 L51 32" fill="none" stroke="#93c5fd" strokeWidth="2" strokeLinecap="round" />
          </svg>
        </div>

        <section className="exp-imp-card">
          <div className="exp-imp-card-head">
            <span className="exp-imp-step">2</span>
            <div>
              <h2 className="exp-imp-card-title">Upload file</h2>
              <p className="exp-imp-card-sub">
                Upload your completed file. Rows are matched to expense accounts by name.
              </p>
            </div>
          </div>

          <input
            ref={fileInputRef}
            type="file"
            accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            className="exp-imp-file-input"
            onChange={handleFileChange}
            disabled={dropBusy}
          />

          <div
            className={`exp-imp-drop${dragging ? ' is-dragging' : ''}${dropBusy ? ' is-busy' : ''}`}
            role="button"
            tabIndex={0}
            onClick={() => !dropBusy && fileInputRef.current?.click()}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (!dropBusy) fileInputRef.current?.click();
              }
            }}
            onDragOver={(e) => {
              e.preventDefault();
              setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={handleDrop}
          >
            <span className="exp-imp-drop-icon">
              {dropBusy ? <Loader2 className="exp-imp-spin" size={22} /> : <UploadCloud size={22} />}
            </span>
            <p className="exp-imp-drop-title">
              {classifying ? 'Classifying with AI…' : busy ? 'Reading file...' : 'Drop file here'}
            </p>
            <p className="exp-imp-drop-sub">{fileName || 'CSV or XLSX'}</p>
            <button
              type="button"
              className="exp-imp-btn-outline"
              disabled={dropBusy}
              onClick={(e) => {
                e.stopPropagation();
                fileInputRef.current?.click();
              }}
            >
              <FolderOpen size={15} aria-hidden="true" />
              Browse files
            </button>
          </div>

          <p className="exp-imp-secure">
            <Lock size={12} aria-hidden="true" />
            Your data is secure and will not be shared.
          </p>
        </section>
      </div>

      {rows.length > 0 ? (
        <>
          {classifyNote ? (
            <div className={`exp-imp-alert ${viaAi ? 'exp-imp-alert--ai' : 'exp-imp-alert--ok'}`} role="status">
              <Sparkles size={16} aria-hidden="true" />
              <span>{classifying ? 'Classifying… ' : ''}{classifyNote}</span>
            </div>
          ) : null}

          <section className="exp-imp-card exp-imp-card--wide">
            <div className="exp-imp-card-head">
              <span className="exp-imp-step">3</span>
              <div>
                <h2 className="exp-imp-card-title">Paid from</h2>
                <p className="exp-imp-card-sub">
                  Choose one bank or cash account for the whole file. Each row already carries its
                  expense account from the sheet (editable below). Balances stay unchanged until you
                  post later.
                </p>
              </div>
            </div>

            <div className="exp-imp-grid">
              <div className="exp-imp-field">
                <label htmlFor="importYear">Year for dates like 7-Apr</label>
                <input
                  id="importYear"
                  type="number"
                  min="2000"
                  max="2100"
                  value={defaultYear}
                  onChange={(e) => setDefaultYear(Number(e.target.value) || new Date().getFullYear())}
                />
              </div>

              <div className="exp-imp-field">
                <label id="importCurrencyLabel">Currency</label>
                <div
                  className={`exp-create-currency exp-imp-currency${currencyOpen ? ' is-open' : ''}`}
                  ref={currencyRef}
                >
                  <button
                    type="button"
                    id="importCurrency"
                    className="exp-create-currency-trigger"
                    aria-labelledby="importCurrencyLabel"
                    aria-haspopup="listbox"
                    aria-expanded={currencyOpen}
                    onClick={() => setCurrencyOpen((open) => !open)}
                  >
                    <img
                      src={flagUrl(selectedCurrencyMeta.flag, selectedCurrencyMeta.iso || currency)}
                      alt=""
                      className="exp-create-currency-flag"
                      width={28}
                      height={20}
                    />
                    <span className="exp-create-currency-label">
                      <span className="code">{selectedCurrencyMeta.code}</span>
                      <span className="name">{selectedCurrencyMeta.name}</span>
                    </span>
                  </button>
                  {currencyOpen ? (
                    <div className="exp-create-currency-menu" role="listbox">
                      {(init?.currencies || [{ iso: 'TZS', code: 'TSh', name: 'Tanzanian Shilling', flag: 'tz' }]).map(
                        (opt) => (
                          <button
                            key={opt.iso || opt.code}
                            type="button"
                            role="option"
                            aria-selected={currencyMatchesOption(opt, currency)}
                            className={`exp-create-currency-option${currencyMatchesOption(opt, currency) ? ' is-selected' : ''}`}
                            onClick={() => {
                              setCurrency(opt.iso || opt.code);
                              setCurrencyOpen(false);
                            }}
                          >
                            <img
                              src={flagUrl(opt.flag, opt.iso || opt.code)}
                              alt=""
                              className="exp-create-currency-flag"
                              width={28}
                              height={20}
                            />
                            <span className="code">{opt.code}</span>
                            <span className="name">{opt.name}</span>
                          </button>
                        ),
                      )}
                    </div>
                  ) : null}
                </div>
              </div>

              <div className="exp-imp-field exp-imp-field--pay">
                <span className="exp-imp-field-label" id="importPayMethodLabel">
                  How was it paid?
                </span>
                <div className="exp-imp-pay-method" role="group" aria-labelledby="importPayMethodLabel">
                  <button
                    type="button"
                    className={`exp-imp-pay-chip${paymentMethod === 'cash' ? ' is-active' : ''}`}
                    aria-pressed={paymentMethod === 'cash'}
                    onClick={() => {
                      setPaymentMethod('cash');
                      setSourceAccountId('');
                    }}
                  >
                    <Banknote size={16} aria-hidden="true" />
                    <span>Cash</span>
                  </button>
                  <button
                    type="button"
                    className={`exp-imp-pay-chip${paymentMethod === 'bank_transfer' ? ' is-active' : ''}`}
                    aria-pressed={paymentMethod === 'bank_transfer'}
                    onClick={() => {
                      setPaymentMethod('bank_transfer');
                      setSourceAccountId('');
                    }}
                  >
                    <Landmark size={16} aria-hidden="true" />
                    <span>Bank transfer</span>
                  </button>
                </div>
              </div>

              {paymentHierarchical ? (
                <>
                  <div className="exp-imp-field">
                    <label htmlFor="importPayGroup">Payment group</label>
                    <select
                      id="importPayGroup"
                      value={mainPaymentId}
                      onChange={(e) => {
                        setMainPaymentId(e.target.value);
                        setSourceAccountId('');
                      }}
                    >
                      <option value="">Select group</option>
                      {(init?.payment?.mains || []).map((main) => (
                        <option key={main.id} value={String(main.id)}>
                          {main.label || main.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="exp-imp-field">
                    <label htmlFor="importPayAccount">
                      {paymentMethod === 'cash' ? 'Cash account' : 'Bank / mobile account'}
                    </label>
                    <select
                      id="importPayAccount"
                      value={sourceAccountId}
                      onChange={(e) => setSourceAccountId(e.target.value)}
                      disabled={!mainPaymentId}
                    >
                      <option value="">Select account</option>
                      {paymentChildren.map((row) => (
                        <option key={row.id} value={String(row.id)}>
                          {row.label || row.name}
                        </option>
                      ))}
                    </select>
                  </div>
                </>
              ) : (
                <div className="exp-imp-field">
                  <label htmlFor="importPayAccount">
                    {paymentMethod === 'cash' ? 'Cash account' : 'Bank / mobile account'}
                  </label>
                  <select
                    id="importPayAccount"
                    value={sourceAccountId}
                    onChange={(e) => setSourceAccountId(e.target.value)}
                  >
                    <option value="">Select account</option>
                    {paymentChildren.map((row) => (
                      <option key={row.id} value={String(row.id)}>
                        {row.label || row.name}
                      </option>
                    ))}
                  </select>
                </div>
              )}
            </div>
          </section>

          <section className="exp-imp-card exp-imp-card--wide">
            <div className="exp-imp-card-head exp-imp-card-head--spread">
              <div className="exp-imp-card-head">
                <span className="exp-imp-step">4</span>
                <div>
                  <h2 className="exp-imp-card-title">Review and import drafts</h2>
                  <p className="exp-imp-card-sub">{fileName}</p>
                </div>
              </div>
              {summary ? (
                <span className="exp-imp-summary">
                  {importReadyCount} ready · {missingAccountCount} need account · {summary.invalid || 0}{' '}
                  errors · {summary.total || rows.length} total
                </span>
              ) : null}
            </div>

            <div className="exp-imp-table-wrap">
              <table className="exp-imp-table">
                <thead>
                  <tr>
                    <th>Row</th>
                    <th>Date</th>
                    <th>From sheet</th>
                    <th>Expense account</th>
                    <th>Amount</th>
                    <th>VAT excl.</th>
                    <th>Tax</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr
                      key={`${row.row}-${row.description}`}
                      className={row.ok && Number(row.account_id) > 0 ? '' : 'is-invalid'}
                    >
                      <td>{row.row}</td>
                      <td>{row.date || row.date_raw || '—'}</td>
                      <td>
                        <div>{row.account_raw || row.description || '—'}</div>
                        {row.ai_reason && Number(row.account_id) > 0 ? (
                          <div className="exp-imp-ai-hint">{row.ai_reason}</div>
                        ) : null}
                      </td>
                      <td>
                        {row.ok ? (
                          <select
                            className="exp-imp-row-select"
                            value={Number(row.account_id) > 0 ? String(row.account_id) : ''}
                            onChange={(e) => setRowAccount(row.row, e.target.value)}
                            disabled={busy || classifying}
                          >
                            <option value="">Select expense account</option>
                            {expenseOptions.map((opt) => (
                              <option key={opt.id} value={String(opt.id)}>
                                {opt.label || opt.name}
                              </option>
                            ))}
                          </select>
                        ) : (
                          <span className="exp-imp-cell-muted">—</span>
                        )}
                      </td>
                      <td>{formatMoney(row.amount)}</td>
                      <td>{formatMoney(row.vat_exclusive)}</td>
                      <td>{formatMoney(row.tax_amount)}</td>
                      <td>
                        {!row.ok ? (
                          <span className="exp-imp-status exp-imp-status--error">{row.error}</span>
                        ) : Number(row.account_id) > 0 ? (
                          <span className="exp-imp-status exp-imp-status--ready">Ready</span>
                        ) : (
                          <span className="exp-imp-status exp-imp-status--pending">Pick expense account</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="exp-imp-actions">
              <button
                type="button"
                className={`exp-imp-btn-primary exp-imp-btn-primary--pill${busy ? ' is-busy' : ''}`}
                disabled={busy || classifying || importReadyCount === 0 || !sourceAccountId}
                aria-busy={busy}
                onClick={handleImport}
              >
                {busy ? <Loader2 className="exp-imp-spin" size={16} /> : null}
                {busy
                  ? `Importing ${importReadyCount}…`
                  : `Import ${importReadyCount} draft${importReadyCount === 1 ? '' : 's'}`}
              </button>
              {!sourceAccountId ? (
                <p className="exp-imp-actions-note exp-imp-actions-note--warn">
                  Select Paid from above before importing.
                </p>
              ) : (
                <p className="exp-imp-actions-note">
                  {missingAccountCount > 0 || (summary?.invalid || 0) > 0
                    ? `Imports the ${importReadyCount} ready row${importReadyCount === 1 ? '' : 's'} only. Rows with errors are skipped.`
                    : 'Saves drafts only — balances update when you post from the expenses desk.'}
                </p>
              )}
            </div>
          </section>
        </>
      ) : null}

      <div className="exp-imp-help">
        <span className="exp-imp-help-icon" aria-hidden="true">
          <HelpCircle size={18} />
        </span>
        <div className="exp-imp-help-text">
          <p className="exp-imp-help-title">Need help?</p>
          <p className="exp-imp-help-sub">Read our guide on how to prepare your file for import.</p>
        </div>
        <button type="button" className="exp-imp-btn-outline" onClick={() => setShowGuide(true)}>
          View Import Guide
          <ExternalLink size={13} aria-hidden="true" />
        </button>
      </div>

      {showExample ? (
        <Modal
          title="Template example"
          className="exp-imp-modal--example"
          onClose={() => setShowExample(false)}
        >
          <p className="exp-imp-modal-sub">
            This is how your file should look. Row 1 is the header. Put Chart of Accounts expense account
            names in EXPENSE ACCOUNT.
          </p>
          <TemplateExampleSheet expenseOptions={expenseOptions} />
        </Modal>
      ) : null}

      {showGuide ? (
        <Modal title="Import guide" className="exp-imp-modal--guide" onClose={() => setShowGuide(false)}>
          <p className="exp-imp-guide-lead">
            Upload <strong>DATE</strong>, <strong>EXPENSE ACCOUNT</strong>, <strong>AMOUNT</strong> and{' '}
            <strong>VAT EXCLUSIVE</strong> — each row saves under that Chart of Accounts expense account.
          </p>

          <div className="exp-imp-guide-steps">
            <article className="exp-imp-guide-step">
              <span className="exp-imp-guide-num" aria-hidden="true">
                1
              </span>
              <div>
                <h4>Keep the headers</h4>
                <p>Download the template and leave the four column names exactly as provided.</p>
              </div>
            </article>

            <article className="exp-imp-guide-step">
              <span className="exp-imp-guide-num" aria-hidden="true">
                2
              </span>
              <div>
                <h4>Dates</h4>
                <p>
                  Use <code>7-Apr</code>, <code>07/04/2026</code>, or <code>2026-04-07</code>. Day-month
                  dates use the year you pick in step 3.
                </p>
              </div>
            </article>

            <article className="exp-imp-guide-step">
              <span className="exp-imp-guide-num" aria-hidden="true">
                3
              </span>
              <div>
                <h4>Expense account</h4>
                <p>
                  Put a Chart of Accounts expense account name (or a close match). Older files that still
                  say DESCRIPTION or EXPENSE SUB-ACCOUNT are accepted the same way.
                </p>
              </div>
            </article>

            <article className="exp-imp-guide-step">
              <span className="exp-imp-guide-num" aria-hidden="true">
                4
              </span>
              <div>
                <h4>Amounts</h4>
                <p>
                  Thousands separators are fine (<code>149,500.00</code>). Currency symbols are stripped
                  automatically.
                </p>
              </div>
            </article>

            <article className="exp-imp-guide-step">
              <span className="exp-imp-guide-num" aria-hidden="true">
                5
              </span>
              <div>
                <h4>VAT exclusive</h4>
                <p>
                  Enter the amount before tax. Tax is stored as AMOUNT minus VAT EXCLUSIVE. Leave it blank
                  when there is no tax.
                </p>
              </div>
            </article>

            <article className="exp-imp-guide-step">
              <span className="exp-imp-guide-num" aria-hidden="true">
                6
              </span>
              <div>
                <h4>Paid from</h4>
                <p>
                  Pick one bank or cash account for the whole file. Every imported row uses that Paid from
                  account.
                </p>
              </div>
            </article>
          </div>

          <aside className="exp-imp-guide-note" role="note">
            <Lock size={15} aria-hidden="true" />
            <p>
              Import saves <strong>drafts only</strong>. Bank and cash balances do not change until you
              post each expense from the expenses desk.
            </p>
          </aside>
        </Modal>
      ) : null}
    </div>
  );
}
