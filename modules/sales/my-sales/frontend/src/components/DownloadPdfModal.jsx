import { Check, Download, Loader2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { downloadMySalesPdf, toSimpleDownloadMessage, triggerBrowserDownload } from '../api/mySalesDesk.js';

const INCLUDE_OPTIONS = [
  { value: 'both', label: 'Quotes and Invoices' },
  { value: 'quotes', label: 'Quotes only' },
  { value: 'invoices', label: 'Invoices only' },
];

export default function DownloadPdfModal({
  open,
  onClose,
  downloadBaseUrl,
  module,
  defaults,
}) {
  const [include, setInclude] = useState(defaults?.include || 'both');
  const [dateFrom, setDateFrom] = useState(defaults?.date_from || '');
  const [dateTo, setDateTo] = useState(defaults?.date_to || '');
  const [error, setError] = useState('');
  const [infoMessage, setInfoMessage] = useState('');
  const [downloadPhase, setDownloadPhase] = useState('idle');
  const [progress, setProgress] = useState(0);
  const closeTimerRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    setInclude(defaults?.include || 'both');
    setDateFrom(defaults?.date_from || '');
    setDateTo(defaults?.date_to || '');
    setError('');
    setInfoMessage('');
    setDownloadPhase('idle');
    setProgress(0);

    return () => {
      if (closeTimerRef.current) {
        window.clearTimeout(closeTimerRef.current);
        closeTimerRef.current = null;
      }
    };
  }, [open, defaults]);

  useEffect(() => {
    if (!open || downloadPhase !== 'idle') return undefined;
    const onKey = (event) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose, downloadPhase]);

  if (!open) return null;

  const isBusy = downloadPhase === 'downloading' || downloadPhase === 'success';

  const handleDownload = async () => {
    if (isBusy) return;

    if (!dateFrom || !dateTo) {
      setError('Please select both start and end dates.');
      return;
    }
    if (dateFrom > dateTo) {
      setError('Start date cannot be after end date.');
      return;
    }

    setError('');
    setInfoMessage('');
    setDownloadPhase('downloading');
    setProgress(6);

    try {
      const { blob, filename } = await downloadMySalesPdf(
        downloadBaseUrl,
        { module, include, dateFrom, dateTo },
        setProgress,
      );

      triggerBrowserDownload(blob, filename);
      setDownloadPhase('success');
      setProgress(100);

      closeTimerRef.current = window.setTimeout(() => {
        onClose();
      }, 1400);
    } catch (err) {
      setDownloadPhase('idle');
      setProgress(0);
      const rawMessage = err instanceof Error ? err.message : 'Failed to download PDF.';
      const simpleMessage = toSimpleDownloadMessage(rawMessage, include);
      const isEmptyResult = /no quotes|no invoices|nothing to download|try choosing different dates/i.test(simpleMessage);

      if (isEmptyResult) {
        setInfoMessage(simpleMessage);
        setError('');
      } else {
        setError(simpleMessage);
        setInfoMessage('');
      }
    }
  };

  const statusText = downloadPhase === 'success'
    ? 'Download complete'
    : progress >= 85
      ? 'Saving PDF...'
      : progress >= 35
        ? 'Building your report...'
        : 'Preparing download...';

  return (
    <div className="ms-modal-backdrop" onClick={isBusy ? undefined : onClose} role="presentation">
      <div
        className="ms-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ms-download-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="ms-modal-header">
          <h2 id="ms-download-title" className="ms-modal-title">Download My Sales PDF</h2>
          <button
            type="button"
            className="ms-modal-close"
            onClick={onClose}
            aria-label="Close"
            disabled={isBusy}
          >
            <X size={18} />
          </button>
        </div>

        <div className="ms-modal-body">
          <fieldset className="ms-modal-fieldset" disabled={isBusy}>
            <legend className="ms-modal-label">Include in document</legend>
            <div className="ms-modal-options">
              {INCLUDE_OPTIONS.map((option) => (
                <label key={option.value} className="ms-modal-option">
                  <input
                    type="radio"
                    name="ms-export-include"
                    value={option.value}
                    checked={include === option.value}
                    onChange={() => {
                      setInclude(option.value);
                      setInfoMessage('');
                      setError('');
                    }}
                    disabled={isBusy}
                  />
                  <span>{option.label}</span>
                </label>
              ))}
            </div>
          </fieldset>

          <div className="ms-modal-dates">
            <label className="ms-modal-date-field">
              <span className="ms-modal-label">From</span>
              <input
                type="date"
                className="ms-modal-input"
                value={dateFrom}
                onChange={(event) => {
                  setDateFrom(event.target.value);
                  setInfoMessage('');
                  setError('');
                }}
                disabled={isBusy}
              />
            </label>
            <label className="ms-modal-date-field">
              <span className="ms-modal-label">To</span>
              <input
                type="date"
                className="ms-modal-input"
                value={dateTo}
                onChange={(event) => {
                  setDateTo(event.target.value);
                  setInfoMessage('');
                  setError('');
                }}
                disabled={isBusy}
              />
            </label>
          </div>

          {infoMessage ? <div className="ms-modal-info" role="status">{infoMessage}</div> : null}
          {error ? <div className="ms-modal-error" role="alert">{error}</div> : null}
        </div>

        <div className="ms-modal-footer">
          <button type="button" className="ms-btn ms-btn--ghost" onClick={onClose} disabled={isBusy}>
            Cancel
          </button>
          <button type="button" className="ms-btn ms-btn--purple" onClick={handleDownload} disabled={isBusy}>
            {downloadPhase === 'downloading' ? (
              <>
                <Loader2 size={14} className="ms-spin" />
                Downloading...
              </>
            ) : (
              'Download PDF'
            )}
          </button>
        </div>

        {isBusy ? (
          <div className="ms-download-overlay" aria-live="polite" aria-busy={downloadPhase === 'downloading'}>
            <div className={`ms-download-stage ${downloadPhase === 'success' ? 'is-success' : ''}`}>
              <div className="ms-download-icon-wrap">
                {downloadPhase === 'success' ? (
                  <Check size={28} className="ms-download-check" />
                ) : (
                  <>
                    <Download size={24} className="ms-download-arrow" />
                    <span className="ms-download-tray" />
                  </>
                )}
              </div>
              <p className="ms-download-status">{statusText}</p>
              <div className="ms-download-progress-track">
                <div
                  className="ms-download-progress-bar"
                  style={{ width: `${Math.max(0, Math.min(100, progress))}%` }}
                />
              </div>
              <p className="ms-download-percent">{Math.round(progress)}%</p>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}
