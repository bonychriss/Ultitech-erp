import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import EditPayslipForm from './EditPayslipForm.jsx';

export default function EditPayslipModal({ open, payslipId, onClose, onSaved, onLoaded }) {
  useEffect(() => {
    if (!open) return undefined;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event) {
      if (event.key === 'Escape') onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [open, onClose]);

  if (!open || !payslipId) return null;

  return createPortal(
    <div className="pay-desk-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="pay-desk-modal pay-slip-edit-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pay-slip-edit-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="pay-salary-edit-modal-head">
          <h2 id="pay-slip-edit-title" className="pay-salary-edit-modal-title">
            Edit payslip
          </h2>
          <button
            type="button"
            className="pay-salary-edit-modal-close"
            onClick={onClose}
            aria-label="Close"
          >
            <X size={18} aria-hidden="true" />
          </button>
        </div>
        <div className="pay-salary-edit-modal-body">
          <EditPayslipForm
            payslipId={payslipId}
            onClose={onClose}
            onSaved={onSaved}
            onLoaded={onLoaded}
          />
        </div>
      </div>
    </div>,
    document.body,
  );
}
