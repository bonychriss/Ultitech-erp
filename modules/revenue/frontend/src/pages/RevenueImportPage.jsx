import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowLeft,
  CheckCircle2,
  Download,
  HelpCircle,
  Info,
  Lightbulb,
  Loader2,
  Lock,
  UploadCloud,
  X,
} from 'lucide-react';
import {
  commitImportRows,
  deskPageUrl,
  fetchImportInit,
  importTemplateUrl,
  previewImportFile,
} from '../api/revenueDesk';

const MAX_BYTES = 10 * 1024 * 1024;
const FLAG_BASE = 'https://flagcdn.com/w40/';

function formatMoney(value) {
  const amount = Number(value) || 0;
  return amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function findCurrencyMeta(list, currency) {
  const iso = String(currency || 'TZS').toUpperCase();
  const found = (list || []).find((opt) => String(opt.iso || opt.code).toUpperCase() === iso);
  if (found) return found;
  if (iso === 'TZS') {
    return { code: 'TZS', iso: 'TZS', name: 'Tanzanian Shilling', flag: 'tz' };
  }
  return { code: currency, iso, name: currency, flag: '' };
}

function flagUrl(flagCode, currencyIso = '') {
  const code = String(flagCode || '').toLowerCase()
    || (String(currencyIso).toUpperCase() === 'TZS' ? 'tz' : 'un');
  return `${FLAG_BASE}${code}.png`;
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
    <div className="rev-imp-illus" aria-hidden="true">
      <span className="rev-imp-illus-blob rev-imp-illus-blob--1" />
      <span className="rev-imp-illus-blob rev-imp-illus-blob--2" />
      <span className="rev-imp-illus-dots rev-imp-illus-dots--left" />
      <span className="rev-imp-illus-dots rev-imp-illus-dots--right" />
      <div className="rev-imp-sheet">
        <div className="rev-imp-sheet-head">
          {Array.from({ length: 4 }).map((_, i) => (
            <span key={`h-${i}`} />
          ))}
        </div>
        <div className="rev-imp-sheet-body">
          {Array.from({ length: 16 }).map((_, i) => (
            <span key={`c-${i}`} />
          ))}
        </div>
      </div>
      <span className="rev-imp-xls">X</span>
    </div>
  );
}

function Modal({ title, onClose, children, className = '' }) {
  return (
    <div className="rev-imp-modal-overlay" role="dialog" aria-modal="true" onClick={onClose}>
      <div
        className={`rev-imp-modal${className ? ` ${className}` : ''}`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="rev-imp-modal-head">
          <h3>{title}</h3>
          <button type="button" className="rev-imp-modal-close" onClick={onClose} aria-label="Close">
            <X size={16} />
          </button>
        </div>
        <div className="rev-imp-modal-body">{children}</div>
      </div>
    </div>
  );
}

const TEMPLATE_HEADERS = [
  'CUSTOMER NAME',
  'PRODUCT NAME',
  'DATE',
  'TIN NUMBER',
  'VRN',
  'QUANTITY',
  'AMOUNT',
  'VAT RATE',
];

function TemplateExampleSheet() {
  const colLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
  const colWidths = ['9.5rem', '9rem', '4.5rem', '7rem', '6.5rem', '5rem', '6.5rem', '5.5rem'];
  const sampleRows = [
    ['ABC TRADING LTD', 'Office Chair', '7-Apr', '100-123-456', '40-012345-A', '2', '450000.00', '18'],
    ['Sunrise Hardware', 'Printer Paper A4', '9-Apr', '100-987-654', '', '10', '85000.00', '0'],
    ['ABC TRADING LTD', 'Consulting Hours', '10-Apr', '100-123-456', '40-012345-A', '5', '750000.00', '18'],
  ];

  return (
    <div className="rev-xls" aria-label="Example import template spreadsheet">
      <div className="rev-xls-titlebar">
        <span className="rev-xls-badge" aria-hidden="true">
          X
        </span>
        <div className="rev-xls-title-text">
          <strong>revenue-import-template.csv</strong>
          <span>Excel / CSV template</span>
        </div>
      </div>

      <div className="rev-xls-grid-wrap">
        <table className="rev-xls-grid rev-xls-grid--revenue">
          <colgroup>
            <col className="rev-xls-col-row" />
            {colWidths.map((width, index) => (
              <col key={colLetters[index]} style={{ width }} />
            ))}
          </colgroup>
          <thead>
            <tr>
              <th className="rev-xls-corner" scope="col" />
              {colLetters.map((letter) => (
                <th key={letter} className="rev-xls-colhead" scope="col">
                  {letter}
                </th>
              ))}
            </tr>
            <tr>
              <th className="rev-xls-rowhead" scope="row">
                1
              </th>
              {TEMPLATE_HEADERS.map((header) => (
                <th key={header} className="rev-xls-header-cell" scope="col">
                  {header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {sampleRows.map((cells, rowIndex) => (
              <tr key={`sample-${rowIndex}`}>
                <th className="rev-xls-rowhead" scope="row">
                  {rowIndex + 2}
                </th>
                {cells.map((cell, cellIndex) => (
                  <td
                    key={`${rowIndex}-${cellIndex}`}
                    className={cellIndex >= 5 ? 'rev-xls-num' : undefined}
                    title={cell || undefined}
                  >
                    {cell || ''}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="rev-xls-tabs" aria-hidden="true">
        <span className="rev-xls-tab is-active">Revenue</span>
        <span className="rev-xls-tab-add">+</span>
      </div>
    </div>
  );
}

export default function RevenueImportPage() {
  const fileInputRef = useRef(null);
  const downloadResetRef = useRef(null);
  const currencyRef = useRef(null);

  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(null);
  const [fileName, setFileName] = useState('');
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState(null);
  const [dragging, setDragging] = useState(false);
  const [showExample, setShowExample] = useState(false);
  const [showGuide, setShowGuide] = useState(false);
  const [downloadUi, setDownloadUi] = useState({ state: 'idle', progress: 0 });
  const [defaultYear, setDefaultYear] = useState(new Date().getFullYear());
  const [subAccountId, setSubAccountId] = useState('');
  const [currency, setCurrency] = useState('TZS');
  const [currencyOpen, setCurrencyOpen] = useState(false);

  const selectedCurrencyMeta = useMemo(
    () => findCurrencyMeta(init?.currencies, currency),
    [init, currency],
  );

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await fetchImportInit();
        if (cancelled) return;
        setInit(data);
        setDefaultYear(Number(data.default_year) || new Date().getFullYear());
        setCurrency(data.default_currency || 'TZS');
        if (data.default_sub_account_id) {
          setSubAccountId(String(data.default_sub_account_id));
        }
      } catch (err) {
        if (!cancelled) setError(err.message || 'Failed to load import options.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
      if (downloadResetRef.current) clearTimeout(downloadResetRef.current);
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

  const readyCount = useMemo(() => rows.filter((row) => row.ok).length, [rows]);
  const vatOptions = useMemo(
    () =>
      init?.vat_rates || [
        { value: '18', label: '18%' },
        { value: '10', label: '10%' },
        { value: '0', label: '0% (Exempt)' },
      ],
    [init],
  );

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
      if (!res.ok) throw new Error(`Download failed (${res.status})`);
      const dispo = res.headers.get('content-disposition') || '';
      const match = dispo.match(/filename="([^"]+)"/i);
      const filename = match?.[1] || 'revenue-import-template.csv';
      const blob = await res.blob();
      if (!blob || blob.size === 0) throw new Error('Template file was empty.');

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

  async function loadFile(file) {
    if (!file || !init?.csrf_token) return;
    const problem = validateFile(file);
    if (problem) {
      setError(problem);
      return;
    }

    setBusy(true);
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
      setRows(
        (preview.rows || []).map((row) => ({
          ...row,
          vat_rate: String(row.vat_rate ?? '18'),
        })),
      );
      setSummary(preview.summary || null);
      setFileName(preview.file_name || file.name);
    } catch (err) {
      setError(err.message || 'Could not read that file.');
      setFileName('');
    } finally {
      setBusy(false);
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

  function setRowVatRate(rowNumber, value) {
    setRows((prev) =>
      prev.map((row) =>
        Number(row.row) === Number(rowNumber) ? { ...row, vat_rate: String(value) } : row,
      ),
    );
  }

  async function handleImport() {
    if (!init?.csrf_token) return;
    const validRows = rows.filter((row) => row.ok);
    if (validRows.length === 0) {
      setError('No valid rows to import. Fix errors in your spreadsheet and try again.');
      return;
    }
    if (!subAccountId) {
      setError('Select a revenue sub-account for this import.');
      return;
    }

    setBusy(true);
    setError('');
    setSuccess(null);
    try {
      const result = await commitImportRows({
        csrf_token: init.csrf_token,
        rows: validRows.map((row) => ({
          ...row,
          vat_rate: String(row.vat_rate ?? '18'),
        })),
        payment_mode: 'Account Receivable',
        revenue_sub_account_id: Number(subAccountId) || 0,
        account_id: 0,
        currency,
        tax_treatment: 'Exclusive',
      });
      setSuccess(result);
      const message = result.message || 'Import complete. Record payments from the list below.';
      window.location.href = deskPageUrl('revenue_entries.php', {
        status: 'unpaid',
        success: message,
      });
    } catch (err) {
      setError(err.message || 'Import failed.');
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="rev-imp" style={{ padding: '3rem', textAlign: 'center', color: '#64748b' }}>
        <Loader2 className="rev-imp-spin" size={22} style={{ display: 'inline-block' }} /> Loading import...
      </div>
    );
  }

  return (
    <div className="rev-imp">
      <div className="rev-imp-head">
        <div>
          <a href={init?.list_url || deskPageUrl('revenue_entries.php')} className="rev-imp-back">
            <ArrowLeft size={14} /> Back to revenue
          </a>
          <h1 className="rev-imp-title">Import revenue</h1>
          <p className="rev-imp-subtitle">
            Import unpaid revenue entries, then record payments from the revenue list.
          </p>
        </div>
      </div>

      {error ? (
        <div className="rev-imp-alert rev-imp-alert--error" role="alert">
          <Info size={16} /> {error}
        </div>
      ) : null}

      {success ? (
        <div className="rev-imp-alert rev-imp-alert--ok rev-imp-alert--cta" role="status">
          <div className="rev-imp-alert-cta-body">
            <p className="rev-imp-alert-cta-msg">
              <CheckCircle2 size={16} style={{ display: 'inline', verticalAlign: '-3px', marginRight: 6 }} />
              {success.message || 'Import complete.'}
            </p>
            <p className="rev-imp-alert-cta-sub">Opening unpaid revenues so you can record payments...</p>
          </div>
        </div>
      ) : null}

      <div className="rev-imp-steps">
        <div className="rev-imp-card">
          <div className="rev-imp-card-head">
            <span className="rev-imp-step">1</span>
            <div>
              <h2 className="rev-imp-card-title">Download template</h2>
              <p className="rev-imp-card-sub">
                Fill customer name, product name, date, TIN, VRN, quantity, amount, and VAT rate.
              </p>
            </div>
          </div>
          <SpreadsheetIllustration />
          <div className={`rev-imp-dl${downloadUi.state === 'downloading' ? ' is-downloading' : ''}${downloadUi.state === 'success' ? ' is-success' : ''}`}>
            <button
              type="button"
              className={`rev-imp-btn-primary${downloadUi.state === 'downloading' ? ' is-busy' : ''}${downloadUi.state === 'success' ? ' is-success' : ''}${downloadUi.state === 'error' ? ' is-error' : ''}`}
              onClick={() => void handleDownloadTemplate()}
              disabled={downloadUi.state === 'downloading'}
            >
              <span className="rev-imp-dl-icon">
                {downloadUi.state === 'success' ? (
                  <CheckCircle2 size={16} />
                ) : (
                  <Download size={16} className={downloadUi.state === 'downloading' ? 'rev-imp-dl-bounce' : ''} />
                )}
              </span>
              {downloadUi.state === 'downloading'
                ? 'Downloading...'
                : downloadUi.state === 'success'
                  ? 'Downloaded'
                  : 'Download CSV template'}
              {downloadUi.state === 'downloading' ? (
                <span className="rev-imp-dl-pct">{Math.round(downloadUi.progress)}%</span>
              ) : null}
            </button>
            <div className={`rev-imp-dl-bar${downloadUi.state === 'downloading' || downloadUi.state === 'success' ? ' is-visible' : ''}`}>
              <span
                className={`rev-imp-dl-bar-fill${downloadUi.state === 'success' ? ' is-done' : ''}`}
                style={{ width: `${downloadUi.progress}%` }}
              />
            </div>
          </div>
          <p className="rev-imp-hint">
            <Lightbulb size={14} className="rev-imp-hint-icon" />
            <button type="button" className="rev-imp-link" onClick={() => setShowExample(true)}>
              See example sheet
            </button>
          </p>
        </div>

        <div className="rev-imp-connector" aria-hidden="true">
          <svg viewBox="0 0 64 120" fill="none">
            <path d="M8 20 C 40 40, 40 80, 8 100" stroke="#cbd5e1" strokeWidth="2" strokeDasharray="4 4" />
            <path d="M52 56 L60 60 L52 64" stroke="#94a3b8" strokeWidth="2" fill="none" />
          </svg>
        </div>

        <div className="rev-imp-card">
          <div className="rev-imp-card-head">
            <span className="rev-imp-step">2</span>
            <div>
              <h2 className="rev-imp-card-title">Upload completed sheet</h2>
              <p className="rev-imp-card-sub">Excel (.xlsx) or CSV up to 10MB.</p>
            </div>
          </div>
          <input
            ref={fileInputRef}
            type="file"
            className="rev-imp-file-input"
            accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
            onChange={handleFileChange}
          />
          <div
            className={`rev-imp-drop${dragging ? ' is-dragging' : ''}${busy ? ' is-busy' : ''}`}
            onDragOver={(e) => {
              e.preventDefault();
              setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={handleDrop}
            onClick={() => !busy && fileInputRef.current?.click()}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') fileInputRef.current?.click();
            }}
          >
            <div className="rev-imp-drop-icon">
              {busy ? <Loader2 className="rev-imp-spin" size={20} /> : <UploadCloud size={20} />}
            </div>
            <p className="rev-imp-drop-title">{busy ? 'Reading file...' : 'Drop file here'}</p>
            <p className="rev-imp-drop-sub">{fileName || 'or click to browse'}</p>
            <button
              type="button"
              className="rev-imp-drop-browse"
              disabled={busy}
              onClick={(e) => {
                e.stopPropagation();
                fileInputRef.current?.click();
              }}
            >
              Choose file
            </button>
          </div>
          <p className="rev-imp-secure">
            <Lock size={12} /> Your file is processed securely on this server
          </p>
        </div>
      </div>

      <div className="rev-imp-help">
        <div className="rev-imp-help-icon">
          <HelpCircle size={18} />
        </div>
        <div className="rev-imp-help-text">
          <p className="rev-imp-help-title">Need help preparing the file?</p>
          <p className="rev-imp-help-sub">
            Unknown customers and products are created automatically with TIN / VRN when provided.
          </p>
        </div>
        <button type="button" className="rev-imp-btn-outline" onClick={() => setShowGuide(true)}>
          Column guide
        </button>
      </div>

      {rows.length > 0 ? (
        <>
          <div className="rev-imp-card rev-imp-card--wide">
            <div className="rev-imp-card-head rev-imp-card-head--spread">
              <div className="rev-imp-card-head" style={{ marginBottom: 0 }}>
                <span className="rev-imp-step">3</span>
                <div>
                  <h2 className="rev-imp-card-title">Review &amp; import</h2>
                  <p className="rev-imp-card-sub">
                    {summary
                      ? `${summary.valid} ready | ${summary.invalid} with errors | ${summary.new_customers || 0} new customers | ${summary.new_products || 0} new products`
                      : `${readyCount} ready`}
                  </p>
                </div>
              </div>
              <div className="rev-imp-summary">
                Year for partial dates:{' '}
                <strong>{defaultYear}</strong>
              </div>
            </div>

            <div className="rev-imp-grid" style={{ marginBottom: '1rem' }}>
              <div className="rev-imp-field">
                <label htmlFor="rev-imp-sub">Revenue sub-account</label>
                <select
                  id="rev-imp-sub"
                  value={subAccountId}
                  onChange={(e) => setSubAccountId(e.target.value)}
                >
                  <option value="">Select account...</option>
                  {(init?.sub_accounts || []).map((opt) => (
                    <option key={opt.id} value={opt.id}>
                      {opt.label || opt.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="rev-imp-field">
                <span className="rev-imp-field-label" id="rev-imp-ccy-label">Currency</span>
                <div
                  className={`rev-create-currency rev-imp-currency${currencyOpen ? ' is-open' : ''}`}
                  ref={currencyRef}
                >
                  <button
                    type="button"
                    className="rev-create-currency-trigger"
                    onClick={() => setCurrencyOpen((open) => !open)}
                    aria-expanded={currencyOpen}
                    aria-labelledby="rev-imp-ccy-label"
                  >
                    <img
                      src={flagUrl(selectedCurrencyMeta.flag, selectedCurrencyMeta.iso || currency)}
                      alt=""
                      className="rev-create-currency-flag"
                      width={28}
                      height={20}
                    />
                    <span className="rev-create-currency-label">
                      <span className="code">{selectedCurrencyMeta.iso || currency}</span>
                      <span className="name">{selectedCurrencyMeta.name}</span>
                    </span>
                  </button>
                  {currencyOpen ? (
                    <div className="rev-create-currency-menu" role="listbox">
                      {(init?.currencies || [{ code: 'TZS', iso: 'TZS', name: 'Tanzanian Shilling', flag: 'tz' }]).map(
                        (opt) => {
                          const code = opt.iso || opt.code;
                          return (
                            <button
                              key={code}
                              type="button"
                              role="option"
                              className={`rev-create-currency-option${currency === code ? ' is-selected' : ''}`}
                              onClick={() => {
                                setCurrency(code);
                                setCurrencyOpen(false);
                              }}
                            >
                              <img
                                src={flagUrl(opt.flag, code)}
                                alt=""
                                className="rev-create-currency-flag"
                                width={28}
                                height={20}
                              />
                              <span className="code">{code}</span>
                              <span className="name">{opt.name}</span>
                            </button>
                          );
                        },
                      )}
                    </div>
                  ) : null}
                </div>
              </div>
            </div>
            <p className="rev-imp-summary" style={{ marginBottom: '0.85rem' }}>
              Entries are imported as unpaid. After import you will open the revenue list to record payments.
            </p>

            <div className="rev-imp-table-wrap">
              <table className="rev-imp-table">
                <thead>
                  <tr>
                    <th>Row</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Product</th>
                    <th>TIN</th>
                    <th>VRN</th>
                    <th>Qty</th>
                    <th>Amount</th>
                    <th>VAT rate</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.row} className={row.ok ? undefined : 'is-invalid'}>
                      <td>{row.row}</td>
                      <td>{row.date || row.date_raw || '-'}</td>
                      <td>
                        {row.customer_name || '-'}
                        {row.ok && row.will_create_customer ? (
                          <div style={{ fontSize: '0.72rem', color: '#2563eb' }}>New</div>
                        ) : null}
                      </td>
                      <td>
                        {row.product_name || '-'}
                        {row.ok && row.will_create_product ? (
                          <div style={{ fontSize: '0.72rem', color: '#2563eb' }}>New</div>
                        ) : null}
                      </td>
                      <td>{row.tin || '-'}</td>
                      <td>{row.vrn || '-'}</td>
                      <td>{row.quantity}</td>
                      <td>{formatMoney(row.amount)}</td>
                      <td>
                        <select
                          className="rev-imp-vat-select"
                          value={String(row.vat_rate ?? '18')}
                          disabled={!row.ok || busy}
                          onChange={(e) => setRowVatRate(row.row, e.target.value)}
                          aria-label={`VAT rate for row ${row.row}`}
                        >
                          {vatOptions.map((opt) => (
                            <option key={opt.value} value={String(opt.value)}>
                              {opt.label}
                            </option>
                          ))}
                          {!vatOptions.some((opt) => String(opt.value) === String(row.vat_rate ?? '18')) ? (
                            <option value={String(row.vat_rate)}>
                              {row.vat_rate}%
                            </option>
                          ) : null}
                        </select>
                      </td>
                      <td>
                        {row.ok ? (
                          <span className="rev-imp-status rev-imp-status--ready">Ready</span>
                        ) : (
                          <span className="rev-imp-status rev-imp-status--error">{row.error || 'Error'}</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="rev-imp-actions">
              <button
                type="button"
                className="rev-imp-btn-primary rev-imp-btn-primary--inline"
                disabled={busy || readyCount === 0}
                onClick={() => void handleImport()}
              >
                {busy ? <Loader2 className="rev-imp-spin" size={16} /> : <UploadCloud size={16} />}
                Import {readyCount} row{readyCount === 1 ? '' : 's'}
              </button>
            </div>
          </div>
        </>
      ) : null}

      {showExample ? (
        <Modal title="Example template" onClose={() => setShowExample(false)} className="rev-imp-modal--wide">
          <TemplateExampleSheet />
        </Modal>
      ) : null}

      {showGuide ? (
        <Modal title="Column guide" onClose={() => setShowGuide(false)} className="rev-imp-modal--guide">
          <p className="rev-imp-guide-lead">
            Use these column headers in your spreadsheet. Required columns must be filled on every row.
          </p>
          <div className="rev-imp-col-guide" role="table" aria-label="Import column reference">
            <div className="rev-imp-col-guide-head" role="row">
              <span role="columnheader">Column</span>
              <span role="columnheader">Status</span>
              <span role="columnheader">What to enter</span>
            </div>
            {(init?.template_columns || []).map((col) => (
              <div key={col.key} className="rev-imp-col-guide-row" role="row">
                <span className="rev-imp-col-guide-name" role="cell">
                  {col.label}
                </span>
                <span role="cell">
                  <span
                    className={`rev-imp-col-guide-badge${col.required ? ' is-required' : ' is-optional'}`}
                  >
                    {col.required ? 'Required' : 'Optional'}
                  </span>
                </span>
                <span className="rev-imp-col-guide-hint" role="cell">
                  {col.hint}
                </span>
              </div>
            ))}
          </div>
          <div className="rev-imp-guide-note">
            <Lightbulb size={16} />
            <p>
              New customers and products are created automatically. TIN and VRN are saved when provided.
            </p>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}
