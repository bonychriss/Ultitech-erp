import React from 'react';
import { createPortal } from 'react-dom';
import { TriangleAlert } from 'lucide-react';

interface WarningConfirmPopupProps {
  title?: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  confirming?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

export default function WarningConfirmPopup({
  title = 'Warning!',
  message,
  confirmLabel = 'Delete',
  cancelLabel = 'Cancel',
  confirming = false,
  onConfirm,
  onCancel,
}: WarningConfirmPopupProps) {
  return createPortal(
    <div className="sms-modal-backdrop" role="presentation" onClick={confirming ? undefined : onCancel}>
      <div
        className="sms-warn-popup"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="sms-warn-popup-title"
        aria-describedby="sms-warn-popup-message"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sms-warn-popup-head">
          <TriangleAlert className="sms-warn-popup-icon" strokeWidth={1.8} aria-hidden="true" />
        </div>

        <div className="sms-warn-popup-body">
          <h2 id="sms-warn-popup-title" className="sms-warn-popup-title">
            {title}
          </h2>
          <p id="sms-warn-popup-message" className="sms-warn-popup-message">
            {message}
          </p>
          <div className="sms-warn-popup-actions">
            <button
              type="button"
              className="sms-warn-popup-btn sms-warn-popup-btn--ghost"
              disabled={confirming}
              onClick={onCancel}
            >
              {cancelLabel}
            </button>
            <button
              type="button"
              className="sms-warn-popup-btn sms-warn-popup-btn--danger"
              disabled={confirming}
              onClick={onConfirm}
            >
              {confirming ? 'Deleting...' : confirmLabel}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}
