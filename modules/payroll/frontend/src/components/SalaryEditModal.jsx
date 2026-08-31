import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import SalaryEditPage from '../pages/SalaryEditPage.jsx';

export default function SalaryEditModal({ employeeId, onClose, onSaved }) {
  useEffect(() => {
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
  }, [onClose]);

  if (!employeeId) return null;

  return createPortal(
    <div className="pay-desk-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="pay-desk-modal pay-salary-edit-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pay-salary-edit-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="pay-salary-edit-modal-head">
          <h2 id="pay-salary-edit-title" className="pay-salary-edit-modal-title">
            Edit Salary
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
          <SalaryEditPage
            employeeId={employeeId}
            variant="modal"
            onClose={onClose}
            onSaved={onSaved}
          />
        </div>
      </div>
    </div>,
    document.body,
  );
}
