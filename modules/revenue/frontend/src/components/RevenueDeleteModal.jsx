import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, Loader2, Trash2, X } from 'lucide-react';
import { formatVoucherRef } from '../utils/formatDisplay';

function formatCurrency(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function RevenueDeleteModal({ entry, busy, error, onCancel, onConfirm }) {
  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event) {
      if (event.key === 'Escape' && !busy) onCancel();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [busy, onCancel]);

  const label = formatVoucherRef(entry.voucher_number, `REV #${entry.id}`);

  return createPortal(
    <div className="rev-desk-modal-backdrop" onClick={busy ? undefined : onCancel} role="presentation">
      <div
        className="rev-desk-modal rev-desk-delete-modal"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="rev-desk-delete-title"
        aria-describedby="rev-desk-delete-desc"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="rev-desk-delete-head">
          <span className="rev-desk-delete-icon" aria-hidden="true">
            <AlertTriangle size={20} />
          </span>
          <h2 id="rev-desk-delete-title" className="rev-desk-delete-title">Delete revenue entry?</h2>
          <button type="button" className="rev-desk-quick-close" onClick={onCancel} disabled={busy} aria-label="Close">
            <X size={20} aria-hidden="true" />
          </button>
        </div>

        <div className="rev-desk-delete-body">
          <p id="rev-desk-delete-desc">
            <strong>{label}</strong>
            {' for '}
            <strong>{entry.customer_display || entry.customer_name || 'this customer'}</strong>
            {' ('}
            {formatCurrency(entry.amount_total)}
            {') will be removed together with its ledger journal. This cannot be undone.'}
          </p>
          {error ? <div className="rev-desk-delete-error" role="alert">{error}</div> : null}
        </div>

        <div className="rev-desk-delete-actions">
          <button type="button" className="rev-desk-btn rev-desk-btn-ghost" onClick={onCancel} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="rev-desk-btn rev-desk-delete-confirm" onClick={onConfirm} disabled={busy}>
            {busy ? <Loader2 size={16} className="rev-desk-spin" aria-hidden="true" /> : <Trash2 size={16} aria-hidden="true" />}
            {busy ? 'Deleting�' : 'Delete entry'}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  );
}
