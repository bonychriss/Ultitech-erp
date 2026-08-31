import React from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, CheckCircle2, Info, X } from 'lucide-react';

export type StatusPopupTone = 'success' | 'error' | 'info';

interface StatusPopupProps {
  title: string;
  message: string;
  tone?: StatusPopupTone;
  confirmLabel?: string;
  onClose: () => void;
}

const toneIcon = {
  success: CheckCircle2,
  error: AlertTriangle,
  info: Info,
} as const;

export default function StatusPopup({
  title,
  message,
  tone = 'success',
  confirmLabel = 'OK',
  onClose,
}: StatusPopupProps) {
  const Icon = toneIcon[tone];

  return createPortal(
    <div className="sms-modal-backdrop" role="presentation" onClick={onClose}>
      <div
        className={`sms-status-popup sms-status-popup--${tone}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="sms-status-popup-title"
        onClick={(e) => e.stopPropagation()}
      >
        <button type="button" className="sms-modal-close" onClick={onClose} aria-label="Close">
          <X className="w-4 h-4" />
        </button>

        <div className={`sms-status-popup-icon sms-status-popup-icon--${tone}`}>
          <Icon className="w-7 h-7" aria-hidden="true" />
        </div>

        <h2 id="sms-status-popup-title" className="sms-status-popup-title">
          {title}
        </h2>
        <p className="sms-status-popup-message">{message}</p>

        <button type="button" className="sms-btn-primary sms-btn-rounded" onClick={onClose}>
          {confirmLabel}
        </button>
      </div>
    </div>,
    document.body
  );
}
