import { useEffect } from 'react';
import { createPortal } from 'react-dom';

/**
 * Choice sheet: View/Edit, Print, or Download the delivery note.
 */
export default function InvoiceDeliveryNoteModal({
  open,
  busy = false,
  onClose,
  onView,
  onPrint,
  onDownload,
}) {
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (event) => {
      if (event.key === 'Escape' && !busy) onClose?.();
    };
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
  }, [open, busy, onClose]);

  if (!open) return null;

  return createPortal(
    <div
      className="inv-dn-modal ov-no-print"
      role="dialog"
      aria-modal="true"
      aria-labelledby="inv-dn-modal-title"
    >
      <button
        type="button"
        className="inv-dn-modal__backdrop"
        aria-label="Close"
        disabled={busy}
        onClick={busy ? undefined : onClose}
      />
      <div className="inv-dn-modal__card">
        <header className="inv-dn-modal__head">
          <div className="inv-dn-modal__head-text">
            <h2 id="inv-dn-modal-title" className="inv-dn-modal__title">
              Delivery Note
            </h2>
            <p className="inv-dn-modal__sub">View, print, or download as PDF</p>
          </div>
          <button
            type="button"
            className="inv-dn-modal__close"
            aria-label="Close"
            disabled={busy}
            onClick={onClose}
          >
            <i className="fas fa-times" aria-hidden="true" />
          </button>
        </header>

        <div className="inv-dn-modal__actions" role="group" aria-label="Delivery note options">
          <button
            type="button"
            className="inv-dn-modal__option inv-dn-modal__option--view"
            disabled={busy}
            onClick={onView}
          >
            <i className="fas fa-edit" aria-hidden="true" />
            <span className="inv-dn-modal__option-label">View</span>
          </button>

          <button
            type="button"
            className="inv-dn-modal__option inv-dn-modal__option--print"
            disabled={busy}
            onClick={onPrint}
          >
            <i className="fas fa-print" aria-hidden="true" />
            <span className="inv-dn-modal__option-label">Print</span>
          </button>

          <button
            type="button"
            className="inv-dn-modal__option inv-dn-modal__option--download"
            disabled={busy}
            onClick={onDownload}
          >
            <i className={`fas ${busy ? 'fa-spinner fa-spin' : 'fa-download'}`} aria-hidden="true" />
            <span className="inv-dn-modal__option-label">
              {busy ? '…' : 'Download'}
            </span>
          </button>
        </div>

        <footer className="inv-dn-modal__foot">
          <button
            type="button"
            className="inv-dn-modal__cancel"
            disabled={busy}
            onClick={onClose}
          >
            Cancel
          </button>
        </footer>
      </div>
    </div>,
    document.body,
  );
}
