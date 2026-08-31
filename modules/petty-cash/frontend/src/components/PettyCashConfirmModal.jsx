import { createPortal } from 'react-dom';
import { Loader2, X } from 'lucide-react';

export default function PettyCashConfirmModal({
  open,
  title = 'Confirm',
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  busy = false,
  onConfirm,
  onClose,
}) {
  if (!open || typeof document === 'undefined') return null;

  return createPortal(
    <div
      className="exp-desk-modal-backdrop"
      onClick={() => {
        if (!busy) onClose?.();
      }}
      role="presentation"
    >
      <div
        className="exp-desk-modal pc-confirm-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pc-confirm-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="exp-desk-quick-head">
          <div className="exp-desk-quick-head-main">
            <h2 id="pc-confirm-title" className="exp-desk-quick-title">
              {title}
            </h2>
          </div>
          <button
            type="button"
            className="exp-desk-quick-close"
            aria-label="Close"
            disabled={busy}
            onClick={() => onClose?.()}
          >
            <X size={18} aria-hidden />
          </button>
        </div>
        <div className="exp-desk-quick-body">
          <p className="pc-confirm-modal__message">{message}</p>
          <div className="pc-confirm-modal__actions">
            <button
              type="button"
              className="pc-confirm-modal__btn pc-confirm-modal__btn--ghost"
              disabled={busy}
              onClick={() => onClose?.()}
            >
              {cancelLabel}
            </button>
            <button
              type="button"
              className="pc-confirm-modal__btn pc-confirm-modal__btn--primary"
              disabled={busy}
              onClick={() => onConfirm?.()}
            >
              {busy ? (
                <>
                  <Loader2 size={15} className="exp-create-spinner" aria-hidden />
                  Working...
                </>
              ) : (
                confirmLabel
              )}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
