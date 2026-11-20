import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { buildInvoicePrintSrc, printInvoiceFrame } from '../utils/invoiceDirectPrint.js';

export default function InvoicePrintModal({ open, printUrl, displayNumber, onClose }) {
  const frameRef = useRef(null);
  const printedRef = useRef(false);
  const [frameKey, setFrameKey] = useState(0);
  const [loading, setLoading] = useState(true);
  const previewSrc = useMemo(
    () => (open ? buildInvoicePrintSrc(printUrl, { autoprint: false }) : ''),
    [open, printUrl],
  );

  useEffect(() => {
    if (!open) return undefined;

    printedRef.current = false;
    setLoading(true);
    setFrameKey((key) => key + 1);
    document.body.classList.add('ov-print-modal-open');

    const onKey = (event) => {
      if (event.key === 'Escape') onClose?.();
    };
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
      document.body.classList.remove('ov-print-modal-open');
    };
  }, [open, onClose]);

  if (!open) return null;

  const title = displayNumber ? `Print invoice ${displayNumber}` : 'Print invoice';

  const handlePrint = () => {
    printInvoiceFrame(frameRef.current);
  };

  const handleFrameLoad = () => {
    setLoading(false);
    // Open the system print dialog over this same tab (no new browser window).
    if (!printedRef.current) {
      printedRef.current = true;
      window.setTimeout(() => {
        printInvoiceFrame(frameRef.current);
      }, 450);
    }
  };

  return createPortal(
    <div
      className="ov-print-modal ov-no-print"
      role="dialog"
      aria-modal="true"
      aria-labelledby="ov-print-modal-title"
    >
      <button
        type="button"
        className="ov-print-modal-backdrop"
        aria-label="Close print preview"
        onClick={onClose}
      />
      <div className="ov-print-modal-card">
        <header className="ov-print-modal-header">
          <div>
            <h2 id="ov-print-modal-title" className="ov-print-modal-title">{title}</h2>
            <p className="ov-print-modal-sub">
              Preview on this page — use Print to send only this invoice to your printer.
            </p>
          </div>
          <div className="ov-print-modal-actions">
            <button type="button" className="ov-btn ov-btn-primary" onClick={handlePrint}>
              <i className="fas fa-print" aria-hidden="true" />
              {' '}
              Print
            </button>
            <button type="button" className="ov-print-modal-close" onClick={onClose} aria-label="Close">
              <i className="fas fa-times" aria-hidden="true" />
            </button>
          </div>
        </header>

        <div className="ov-print-modal-body">
          {loading ? (
            <div className="ov-print-modal-loading" role="status">
              <span className="ov-boot-spinner" aria-hidden="true" />
              <span>Loading print preview...</span>
            </div>
          ) : null}
          {previewSrc ? (
            <iframe
              key={frameKey}
              ref={frameRef}
              className="ov-print-modal-frame"
              title={title}
              src={previewSrc}
              onLoad={handleFrameLoad}
            />
          ) : (
            <div className="ov-print-modal-empty">Print preview is unavailable for this invoice.</div>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
