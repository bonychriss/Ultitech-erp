import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { CalendarDays, FileDown, FileSpreadsheet, Loader2, X } from 'lucide-react';

export default function ExportRangeModal({
  open,
  format = 'pdf',
  exporting,
  error,
  onClose,
  onExport,
}) {
  const [allTime, setAllTime] = useState(false);
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [localError, setLocalError] = useState(null);
  const [missingStart, setMissingStart] = useState(false);
  const [missingEnd, setMissingEnd] = useState(false);

  const isExcel = format === 'excel';
  const title = isExcel ? 'Export Excel' : 'Export PDF';
  const titleId = isExcel ? 'exp-export-excel-title' : 'exp-export-pdf-title';
  const ActionIcon = isExcel ? FileSpreadsheet : FileDown;

  useEffect(() => {
    if (!open) return;
    setAllTime(false);
    setStartDate('');
    setEndDate('');
    setLocalError(null);
    setMissingStart(false);
    setMissingEnd(false);
  }, [open, format]);

  if (!open) return null;

  const handleAllTime = () => {
    setAllTime(true);
    setStartDate('');
    setEndDate('');
    setLocalError(null);
    setMissingStart(false);
    setMissingEnd(false);
  };

  const handleStartChange = (value) => {
    setStartDate(value);
    setAllTime(false);
    setLocalError(null);
    setMissingStart(false);
  };

  const handleEndChange = (value) => {
    setEndDate(value);
    setAllTime(false);
    setLocalError(null);
    setMissingEnd(false);
  };

  const handleSubmit = () => {
    if (allTime) {
      onExport({ allTime: true, startDate: '', endDate: '' });
      return;
    }

    const startMissing = !startDate.trim();
    const endMissing = !endDate.trim();
    setMissingStart(startMissing);
    setMissingEnd(endMissing);

    if (startMissing || endMissing) {
      setLocalError('Please fill in the From and To dates.');
      return;
    }

    if (startDate > endDate) {
      setLocalError('Start date must be on or before end date.');
      return;
    }

    onExport({ allTime: false, startDate, endDate });
  };

  const displayError = localError || error;

  return createPortal(
    <div className="exp-export-modal-backdrop" role="presentation" onClick={exporting ? undefined : onClose}>
      <div
        className="exp-export-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="exp-export-modal-head">
          <div>
            <h2 id={titleId} className="exp-export-modal-title">{title}</h2>
            <p className="exp-export-modal-sub">Choose a date range for the expenses report.</p>
          </div>
          <button
            type="button"
            className="exp-export-modal-close"
            onClick={onClose}
            disabled={exporting}
            aria-label="Close"
          >
            <X size={16} />
          </button>
        </div>

        <div className="exp-export-modal-body">
          <div className="exp-export-pdf-shortcuts">
            <button
              type="button"
              className={`exp-export-pdf-shortcut${allTime ? ' is-active' : ''}`}
              onClick={handleAllTime}
              disabled={exporting}
            >
              All time
            </button>
          </div>

          <div className="exp-export-pdf-date-row">
            <div className="exp-export-pdf-date-field">
              <label className="exp-export-field-label" htmlFor="exp-export-from">From</label>
              <input
                id="exp-export-from"
                type="date"
                className={`exp-export-date-input${missingStart ? ' is-invalid' : ''}`}
                value={startDate}
                onChange={(e) => handleStartChange(e.target.value)}
                disabled={exporting || allTime}
                required={!allTime}
                aria-invalid={missingStart}
              />
            </div>
            <div className="exp-export-pdf-date-sep" aria-hidden="true">to</div>
            <div className="exp-export-pdf-date-field">
              <label className="exp-export-field-label" htmlFor="exp-export-to">To</label>
              <input
                id="exp-export-to"
                type="date"
                className={`exp-export-date-input${missingEnd ? ' is-invalid' : ''}`}
                value={endDate}
                onChange={(e) => handleEndChange(e.target.value)}
                disabled={exporting || allTime}
                min={startDate || undefined}
                required={!allTime}
                aria-invalid={missingEnd}
              />
            </div>
          </div>

          {displayError && (
            <p className="exp-export-pdf-error" role="alert">{displayError}</p>
          )}
        </div>

        <div className="exp-export-modal-foot">
          <span className="exp-export-modal-foot-hint">
            <CalendarDays size={14} aria-hidden="true" />
            {allTime ? 'All expenses will be included.' : 'Only expenses in the selected range will be exported.'}
          </span>
          <div className="exp-export-pdf-actions">
            <button
              type="button"
              className="exp-export-btn exp-export-btn--secondary"
              onClick={onClose}
              disabled={exporting}
            >
              Cancel
            </button>
            <button
              type="button"
              className="exp-export-btn exp-export-btn--primary"
              onClick={handleSubmit}
              disabled={exporting}
            >
              {exporting ? <Loader2 size={16} className="exp-export-spin" /> : <ActionIcon size={16} />}
              <span>{title}</span>
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
