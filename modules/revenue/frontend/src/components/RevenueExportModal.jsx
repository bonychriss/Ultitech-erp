import { Check, Download, Loader2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { downloadRevenueCsv, triggerBrowserDownload } from '../api/revenueDesk';

const INCLUDE_OPTIONS = [
  { value: '', label: 'All entries' },
  { value: 'paid', label: 'Paid only' },
  { value: 'unpaid', label: 'Unpaid only' },
  { value: 'partial', label: 'Partial only' },
];

function monthStartIso() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  return `${y}-${m}-01`;
}

function todayIso() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export default function RevenueExportModal({
  open,
  onClose,
  defaults,
}) {
  const [status, setStatus] = useState(defaults?.status || '');
  const [dateFrom, setDateFrom] = useState(defaults?.date_from || monthStartIso());
  const [dateTo, setDateTo] = useState(defaults?.date_to || todayIso());
  const [error, setError] = useState('');
  const [infoMessage, setInfoMessage] = useState('');
  const [downloadPhase, setDownloadPhase] = useState('idle');
  const [progress, setProgress] = useState(0);
  const closeTimerRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    setStatus(defaults?.status || '');
    setDateFrom(defaults?.date_from || monthStartIso());
    setDateTo(defaults?.date_to || todayIso());
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
      const { blob, filename } = await downloadRevenueCsv(
        {
          date_from: dateFrom,
          date_to: dateTo,
          status: status || '',
        },
        setProgress,
      );

      if (!blob || blob.size === 0) {
        throw new Error('Nothing to export for these dates. Try choosing different dates.');
      }

      triggerBrowserDownload(blob, filename);
      setDownloadPhase('success');
      setProgress(100);

      closeTimerRef.current = window.setTimeout(() => {
        onClose();
      }, 1400);
    } catch (err) {
      setDownloadPhase('idle');
      setProgress(0);
      const message = err instanceof Error ? err.message : 'Failed to export CSV.';
      const isEmpty = /nothing to export|no entries|empty/i.test(message);
      if (isEmpty) {
        setInfoMessage(message);
        setError('');
      } else {
        setError(message);
        setInfoMessage('');
      }
    }
  };

  const statusText = downloadPhase === 'success'
    ? 'Download complete'
    : progress >= 85
      ? 'Saving CSV...'
      : progress >= 35
        ? 'Building your export...'
        : 'Preparing download...';

  return (
    <div className="rev-export-backdrop" onClick={isBusy ? undefined : onClose} role="presentation">
      <div
        className="rev-export-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rev-export-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="rev-export-header">
          <h2 id="rev-export-title" className="rev-export-title">Export revenues</h2>
          <button
            type="button"
            className="rev-export-close"
            onClick={onClose}
            aria-label="Close"
            disabled={isBusy}
          >
            <X size={18} />
          </button>
        </div>

        <div className="rev-export-body">
          <fieldset className="rev-export-fieldset" disabled={isBusy}>
            <legend className="rev-export-label">Include in export</legend>
            <div className="rev-export-options">
              {INCLUDE_OPTIONS.map((option) => (
                <label key={option.value || 'all'} className="rev-export-option">
                  <input
                    type="radio"
                    name="rev-export-include"
                    value={option.value}
                    checked={status === option.value}
                    onChange={() => {
                      setStatus(option.value);
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

          <div className="rev-export-dates">
            <label className="rev-export-date-field">
              <span className="rev-export-label">From</span>
              <input
                type="date"
                className="rev-export-input"
                value={dateFrom}
                onChange={(event) => {
                  setDateFrom(event.target.value);
                  setInfoMessage('');
                  setError('');
                }}
                disabled={isBusy}
              />
            </label>
            <label className="rev-export-date-field">
              <span className="rev-export-label">To</span>
              <input
                type="date"
                className="rev-export-input"
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

          {infoMessage ? <div className="rev-export-info" role="status">{infoMessage}</div> : null}
          {error ? <div className="rev-export-error" role="alert">{error}</div> : null}
        </div>

        <div className="rev-export-footer">
          <button type="button" className="rev-export-btn rev-export-btn--ghost" onClick={onClose} disabled={isBusy}>
            Cancel
          </button>
          <button type="button" className="rev-export-btn rev-export-btn--primary" onClick={handleDownload} disabled={isBusy}>
            {downloadPhase === 'downloading' ? (
              <>
                <Loader2 size={14} className="rev-export-spin" />
                Exporting...
              </>
            ) : (
              'Export CSV'
            )}
          </button>
        </div>

        {isBusy ? (
          <div className="rev-export-overlay" aria-live="polite" aria-busy={downloadPhase === 'downloading'}>
            <div className={`rev-export-stage ${downloadPhase === 'success' ? 'is-success' : ''}`}>
              <div className="rev-export-icon-wrap">
                {downloadPhase === 'success' ? (
                  <Check size={28} className="rev-export-check" />
                ) : (
                  <>
                    <Download size={24} className="rev-export-arrow" />
                    <span className="rev-export-tray" />
                  </>
                )}
              </div>
              <p className="rev-export-status">{statusText}</p>
              <div className="rev-export-progress-track">
                <div
                  className="rev-export-progress-bar"
                  style={{ width: `${Math.max(0, Math.min(100, progress))}%` }}
                />
              </div>
              <p className="rev-export-percent">{Math.round(progress)}%</p>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}
