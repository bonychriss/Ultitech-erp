import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { CalendarDays, FileDown, Loader2, X } from 'lucide-react';

export interface ExportPdfRange {
  allTime: boolean;
  startDate: string;
  endDate: string;
}

interface ExportPdfModalProps {
  open: boolean;
  exporting: boolean;
  error?: string | null;
  onClose: () => void;
  onExport: (range: ExportPdfRange) => void;
}

export default function ExportPdfModal({
  open,
  exporting,
  error,
  onClose,
  onExport,
}: ExportPdfModalProps) {
  const [allTime, setAllTime] = useState(false);
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [localError, setLocalError] = useState<string | null>(null);
  const [missingStart, setMissingStart] = useState(false);
  const [missingEnd, setMissingEnd] = useState(false);

  useEffect(() => {
    if (!open) return;
    setAllTime(false);
    setStartDate('');
    setEndDate('');
    setLocalError(null);
    setMissingStart(false);
    setMissingEnd(false);
  }, [open]);

  if (!open) return null;

  const handleAllTime = () => {
    setAllTime(true);
    setStartDate('');
    setEndDate('');
    setLocalError(null);
    setMissingStart(false);
    setMissingEnd(false);
  };

  const handleStartChange = (value: string) => {
    setStartDate(value);
    setAllTime(false);
    setLocalError(null);
    setMissingStart(false);
  };

  const handleEndChange = (value: string) => {
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
    <div className="sms-modal-backdrop" role="presentation" onClick={exporting ? undefined : onClose}>
      <div
        className="sms-modal sms-export-pdf-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sms-export-pdf-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sms-modal-head">
          <div>
            <h2 id="sms-export-pdf-title" className="sms-modal-title">Export PDF</h2>
            <p className="sms-modal-sub">Choose a date range for the movements report.</p>
          </div>
          <button
            type="button"
            className="sms-modal-close sms-modal-close-circle"
            onClick={onClose}
            disabled={exporting}
            aria-label="Close"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="sms-modal-body sms-export-pdf-body">
          <div className="sms-export-pdf-shortcuts">
            <button
              type="button"
              className={`sms-export-pdf-shortcut${allTime ? ' is-active' : ''}`}
              onClick={handleAllTime}
              disabled={exporting}
            >
              All time
            </button>
          </div>

          <div className="sms-export-pdf-date-row">
            <div className="sms-export-pdf-date-field">
              <label className="sms-field-label" htmlFor="sms-export-from">From</label>
              <input
                id="sms-export-from"
                type="date"
                className={`sms-input sms-date-input${missingStart ? ' sms-input-invalid' : ''}`}
                value={startDate}
                onChange={(e) => handleStartChange(e.target.value)}
                disabled={exporting || allTime}
                required={!allTime}
                aria-invalid={missingStart}
              />
            </div>
            <div className="sms-export-pdf-date-sep" aria-hidden="true">to</div>
            <div className="sms-export-pdf-date-field">
              <label className="sms-field-label" htmlFor="sms-export-to">To</label>
              <input
                id="sms-export-to"
                type="date"
                className={`sms-input sms-date-input${missingEnd ? ' sms-input-invalid' : ''}`}
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
            <p className="sms-export-pdf-error" role="alert">{displayError}</p>
          )}
        </div>

        <div className="sms-modal-foot">
          <span className="sms-modal-foot-hint">
            <CalendarDays className="w-3.5 h-3.5" aria-hidden="true" />
            {allTime ? 'All recorded movements will be included.' : 'Only movements in the selected range will be exported.'}
          </span>
          <div className="sms-export-pdf-actions">
            <button
              type="button"
              className="sms-desk-btn sms-desk-btn-secondary sms-btn-rounded"
              onClick={onClose}
              disabled={exporting}
            >
              Cancel
            </button>
            <button
              type="button"
              className="sms-desk-btn sms-desk-btn-primary sms-btn-rounded"
              onClick={handleSubmit}
              disabled={exporting}
            >
              {exporting ? <Loader2 className="w-4 h-4 animate-spin" /> : <FileDown className="w-4 h-4" />}
              <span>Export PDF</span>
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}
