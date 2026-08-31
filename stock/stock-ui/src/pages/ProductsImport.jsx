import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlineArrowDownTray,
  HiOutlineCloudArrowUp,
  HiOutlineCheckCircle,
  HiOutlineInformationCircle,
  HiOutlineLightBulb,
  HiOutlineArrowPath,
  HiOutlineXMark,
  HiOutlineExclamationTriangle,
} from 'react-icons/hi2';
import './products-import.css';

const MAX_BYTES = 10 * 1024 * 1024;

function validateFile(file) {
  const ext = String(file.name || '').split('.').pop().toLowerCase();
  if (ext === 'xlsx') return 'Please save as .xls or .csv (.xlsx is not supported yet).';
  if (!['xls', 'csv', 'txt', 'html', 'htm'].includes(ext)) {
    return 'Unsupported file type. Upload an Excel (.xls) or CSV (.csv) file.';
  }
  if (file.size > MAX_BYTES) return 'That file is larger than 10MB. Split it and try again.';
  return '';
}

function SpreadsheetIllustration() {
  return (
    <div className="prod-imp-illus" aria-hidden="true">
      <span className="prod-imp-illus-blob prod-imp-illus-blob--1" />
      <span className="prod-imp-illus-blob prod-imp-illus-blob--2" />
      <div className="prod-imp-sheet">
        <div className="prod-imp-sheet-head">
          {[0, 1, 2, 3].map((i) => (
            <span key={i} />
          ))}
        </div>
        <div className="prod-imp-sheet-body">
          {Array.from({ length: 12 }).map((_, i) => (
            <span key={i} />
          ))}
        </div>
      </div>
      <span className="prod-imp-xls">X</span>
    </div>
  );
}

export default function ProductsImport({ data }) {
  const {
    apiBase = 'api/',
    templateUrl = 'download_import_template.php',
    productsUrl = 'index.php',
    isUltimate = false,
  } = data;

  const fileInputRef = useRef(null);
  const downloadResetRef = useRef(null);
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [committing, setCommitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(null);
  const [fileName, setFileName] = useState('');
  const [rows, setRows] = useState([]);
  const [summary, setSummary] = useState(null);
  const [dragging, setDragging] = useState(false);
  const [mode, setMode] = useState(
    data.modeHint || (isUltimate ? 'general' : 'spare_part')
  );
  const [downloadUi, setDownloadUi] = useState({ state: 'idle', progress: 0 });
  const [showGuide, setShowGuide] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await fetch(`${apiBase}import-init.php`, { credentials: 'same-origin' });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || 'Failed to load import options.');
        if (cancelled) return;
        setInit(json);
        setMode(json.defaultMode || (json.isUltimate ? 'general' : 'spare_part'));
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
  }, [apiBase]);

  const templateType = mode === 'truck' ? 'truck' : mode === 'general' || isUltimate ? 'general' : 'spare_part';
  const nameColumnLabel = mode === 'truck' ? 'Truck name' : mode === 'general' || isUltimate ? 'Product name' : 'Part name';
  const readyRows = useMemo(() => rows.filter((r) => r.ok), [rows]);
  const readyCount = readyRows.length;

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
        prev.state === 'downloading' ? { ...prev, progress: Math.max(prev.progress, soft) } : prev
      );
    }, 90);
    try {
      const url = `${templateUrl}?type=${encodeURIComponent(templateType)}&format=xls`;
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error(`Download failed (${res.status})`);
      const dispo = res.headers.get('content-disposition') || '';
      const match = dispo.match(/filename="([^"]+)"/i) || dispo.match(/filename=([^;]+)/i);
      const fallback =
        templateType === 'truck'
          ? `trucks-import-template-${new Date().toISOString().slice(0, 10)}.xls`
          : `products-import-template-${new Date().toISOString().slice(0, 10)}.xls`;
      const filename = (match?.[1] || fallback).replace(/['"]/g, '').trim();
      const blob = await res.blob();
      if (!blob || blob.size === 0) throw new Error('Template file was empty.');
      clearInterval(softTimer);
      setDownloadUi({ state: 'downloading', progress: 100 });
      const objectUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = objectUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(objectUrl), 2500);
      await new Promise((r) => setTimeout(r, 220));
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
      const fd = new FormData();
      fd.append('csrf_token', init.csrf_token);
      fd.append('mode', mode);
      fd.append('file', file);
      const res = await fetch(`${apiBase}import-preview.php`, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'Preview failed.');
      setRows(json.rows || []);
      setSummary(json.summary || null);
      setFileName(json.file_name || file.name);
    } catch (err) {
      setError(err.message || 'Could not preview file.');
    } finally {
      setBusy(false);
    }
  }

  async function handleCommit() {
    if (!init?.csrf_token || readyCount < 1 || committing) return;
    setCommitting(true);
    setError('');
    try {
      const res = await fetch(`${apiBase}import-commit.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: init.csrf_token,
          mode,
          rows: readyRows,
        }),
      });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || 'Import failed.');
      setSuccess({
        message: json.message || `Imported ${json.imported} new, updated ${json.updated}.`,
        deskUrl: json.redirect || productsUrl,
      });
      setRows([]);
      setSummary(null);
      setTimeout(() => {
        window.location.href = json.redirect || productsUrl;
      }, 1600);
    } catch (err) {
      setError(err.message || 'Import failed.');
    } finally {
      setCommitting(false);
    }
  }

  if (loading) {
    return (
      <div className="prod-imp" aria-busy="true">
        <p className="prod-imp-subtitle">Loading import�</p>
      </div>
    );
  }

  return (
    <div className="prod-imp">
      <header className="prod-imp-head">
        <a className="prod-imp-back" href={productsUrl}>
          <HiOutlineArrowLeft size={14} aria-hidden="true" />
          <span>Back to Products</span>
        </a>
      </header>

      <div className="prod-imp-strip">
        <div className="prod-imp-strip-lead">
          <HiOutlineInformationCircle size={18} className="prod-imp-strip-icon" aria-hidden="true" />
          <span>Download the template, fill your rows, then upload to preview and import.</span>
        </div>
        <ul className="prod-imp-strip-items">
          <li>
            <HiOutlineCheckCircle size={14} aria-hidden="true" /> Supports Excel (.xls)
          </li>
          <li>
            <HiOutlineCheckCircle size={14} aria-hidden="true" /> Supports CSV (.csv)
          </li>
          <li>
            <HiOutlineCheckCircle size={14} aria-hidden="true" /> Max file size: 10MB
          </li>
        </ul>
      </div>

      {error ? <div className="prod-imp-alert prod-imp-alert--error">{error}</div> : null}
      {success ? (
        <div className="prod-imp-alert prod-imp-alert--ok prod-imp-alert--cta" role="status">
          <div className="prod-imp-alert-cta-body">
            <p className="prod-imp-alert-cta-msg">{success.message}</p>
            <p className="prod-imp-alert-cta-sub">Opening products list�</p>
          </div>
          <a className="prod-imp-btn-primary prod-imp-btn-primary--pill" href={success.deskUrl}>
            View products
          </a>
        </div>
      ) : null}

      {!isUltimate && init?.modes?.length > 1 ? (
        <div className="prod-imp-pay-method" role="group" aria-label="Import type">
          <button
            type="button"
            className={`prod-imp-pay-chip${mode === 'spare_part' ? ' is-active' : ''}`}
            onClick={() => {
              setMode('spare_part');
              setRows([]);
              setSummary(null);
              setFileName('');
            }}
          >
            Spare parts
          </button>
          <button
            type="button"
            className={`prod-imp-pay-chip${mode === 'truck' ? ' is-active' : ''}`}
            onClick={() => {
              setMode('truck');
              setRows([]);
              setSummary(null);
              setFileName('');
            }}
          >
            Trucks
          </button>
        </div>
      ) : null}

      <div className="prod-imp-steps">
        <section className="prod-imp-card">
          <div className="prod-imp-card-head">
            <span className="prod-imp-step">1</span>
            <div>
              <h2 className="prod-imp-card-title">Download template</h2>
              <p className="prod-imp-card-sub">
                {mode === 'truck'
                  ? 'Truck columns include name, category, VIN, engine, and pricing.'
                  : `Required columns include ${nameColumnLabel} and Category. Leave Product Code blank to auto-generate.`}
              </p>
            </div>
          </div>
          <SpreadsheetIllustration />
          <div className={`prod-imp-dl${downloadUi.state !== 'idle' ? ` is-${downloadUi.state}` : ''}`}>
            <button
              type="button"
              className={`prod-imp-btn-primary${downloadUi.state === 'success' ? ' is-success' : ''}${
                downloadUi.state === 'error' ? ' is-error' : ''
              }`}
              onClick={handleDownloadTemplate}
              disabled={downloadUi.state === 'downloading'}
            >
              <span className="prod-imp-dl-icon" aria-hidden="true">
                {downloadUi.state === 'downloading' ? (
                  <HiOutlineArrowPath className="prod-imp-spin" size={16} />
                ) : downloadUi.state === 'success' ? (
                  <HiOutlineCheckCircle size={16} />
                ) : (
                  <HiOutlineArrowDownTray size={16} className={downloadUi.state === 'idle' ? 'prod-imp-dl-bounce' : ''} />
                )}
              </span>
              <span>
                {downloadUi.state === 'downloading'
                  ? 'Downloading...'
                  : downloadUi.state === 'success'
                    ? 'Downloaded!'
                    : downloadUi.state === 'error'
                      ? 'Try again'
                      : 'Download Template'}
              </span>
              {downloadUi.state === 'downloading' ? (
                <span className="prod-imp-dl-pct">{Math.round(downloadUi.progress)}%</span>
              ) : null}
            </button>
            <div
              className={`prod-imp-dl-bar${
                downloadUi.state === 'downloading' || downloadUi.state === 'success' ? ' is-visible' : ''
              }`}
            >
              <div
                className={`prod-imp-dl-bar-fill${downloadUi.state === 'success' ? ' is-done' : ''}`}
                style={{ width: `${downloadUi.progress}%` }}
              />
            </div>
          </div>
          <p className="prod-imp-hint">
            <HiOutlineLightBulb size={13} className="prod-imp-hint-icon" aria-hidden="true" />
            Need help?{' '}
            <button type="button" className="prod-imp-link" onClick={() => setShowGuide(true)}>
              View import guide
            </button>
          </p>
        </section>

        <section className="prod-imp-card">
          <div className="prod-imp-card-head">
            <span className="prod-imp-step">2</span>
            <div>
              <h2 className="prod-imp-card-title">Upload file</h2>
              <p className="prod-imp-card-sub">We check every row before importing anything.</p>
            </div>
          </div>
          <input
            ref={fileInputRef}
            type="file"
            accept=".csv,.xls,.txt,.html,.htm"
            className="prod-imp-file-input"
            onChange={(e) => {
              const f = e.target.files?.[0];
              if (f) loadFile(f);
              e.target.value = '';
            }}
          />
          <div
            className={`prod-imp-drop${dragging ? ' is-dragging' : ''}${busy ? ' is-busy' : ''}`}
            onDragEnter={(e) => {
              e.preventDefault();
              setDragging(true);
            }}
            onDragOver={(e) => {
              e.preventDefault();
              setDragging(true);
            }}
            onDragLeave={(e) => {
              e.preventDefault();
              setDragging(false);
            }}
            onDrop={(e) => {
              e.preventDefault();
              setDragging(false);
              const f = e.dataTransfer.files?.[0];
              if (f) loadFile(f);
            }}
            onClick={() => !busy && fileInputRef.current?.click()}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') fileInputRef.current?.click();
            }}
          >
            <span className="prod-imp-drop-icon">
              {busy ? (
                <HiOutlineArrowPath className="prod-imp-spin" size={22} />
              ) : (
                <HiOutlineCloudArrowUp size={22} />
              )}
            </span>
            <p className="prod-imp-drop-title">{busy ? 'Checking file...' : 'Drop file or click to browse'}</p>
            <p className="prod-imp-drop-sub">{fileName || 'CSV or XLS'}</p>
          </div>
        </section>
      </div>

      {rows.length > 0 ? (
        <section className="prod-imp-card prod-imp-card--wide">
          <div className="prod-imp-card-head prod-imp-card-head--spread">
            <div className="prod-imp-card-head">
              <span className="prod-imp-step">3</span>
              <div>
                <h2 className="prod-imp-card-title">Review & import</h2>
                <p className="prod-imp-card-sub">
                  {summary
                    ? `${summary.valid} ready, ${summary.invalid} with issues (of ${summary.total})`
                    : `${readyCount} ready`}
                </p>
              </div>
            </div>
          </div>

          <div className="prod-imp-table-wrap">
            <table className="prod-imp-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>Code</th>
                  <th>Category</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.row_no} className={!row.ok ? 'is-invalid' : ''}>
                    <td>{row.row_no}</td>
                    <td>{row.name || '�'}</td>
                    <td className="prod-imp-cell-muted">{row.product_code || 'auto'}</td>
                    <td>{row.category || '�'}</td>
                    <td>
                      {!row.ok ? (
                        <span className="prod-imp-status prod-imp-status--error" title={(row.issues || []).map((i) => i.issue).join('; ')}>
                          <HiOutlineExclamationTriangle size={14} aria-hidden="true" /> Error
                        </span>
                      ) : row.will_update ? (
                        <span className="prod-imp-status prod-imp-status--pending">Update</span>
                      ) : (
                        <span className="prod-imp-status prod-imp-status--ready">Ready</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="prod-imp-actions">
            <p className={`prod-imp-actions-note${readyCount < 1 ? ' prod-imp-actions-note--warn' : ''}`}>
              {readyCount < 1
                ? 'Fix the highlighted rows, then upload again.'
                : `${readyCount} row${readyCount === 1 ? '' : 's'} will be imported.`}
            </p>
            <button
              type="button"
              className="prod-imp-btn-primary"
              disabled={readyCount < 1 || committing}
              onClick={handleCommit}
            >
              {committing ? (
                <>
                  <HiOutlineArrowPath className="prod-imp-spin" size={16} aria-hidden="true" />
                  Importing...
                </>
              ) : (
                `Import ${readyCount} row${readyCount === 1 ? '' : 's'}`
              )}
            </button>
          </div>
        </section>
      ) : null}

      {showGuide ? (
        <div className="prod-imp-modal-overlay" role="dialog" aria-modal="true" onClick={() => setShowGuide(false)}>
          <div className="prod-imp-modal prod-imp-modal--guide" onClick={(e) => e.stopPropagation()}>
            <div className="prod-imp-modal-head">
              <h3>Import guide</h3>
              <button type="button" className="prod-imp-modal-close" onClick={() => setShowGuide(false)} aria-label="Close">
                <HiOutlineXMark size={16} />
              </button>
            </div>
            <div className="prod-imp-modal-body">
              <div className="prod-imp-guide">
                <p className="prod-imp-guide-lead">Use the downloaded template so column names match exactly.</p>
                <div className="prod-imp-guide-steps">
                  <div className="prod-imp-guide-step">
                    <span className="prod-imp-guide-num">1</span>
                    <div>
                      <h4>Fill required fields</h4>
                      <p>
                        <code>{nameColumnLabel}</code> and <code>Category</code> are required.
                      </p>
                    </div>
                  </div>
                  <div className="prod-imp-guide-step">
                    <span className="prod-imp-guide-num">2</span>
                    <div>
                      <h4>Product codes</h4>
                      <p>Leave blank to auto-generate. Existing codes update that product.</p>
                    </div>
                  </div>
                  <div className="prod-imp-guide-step">
                    <span className="prod-imp-guide-num">3</span>
                    <div>
                      <h4>Prices</h4>
                      <p>Use plain numbers only (example: <code>25000</code>), no currency symbols.</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
