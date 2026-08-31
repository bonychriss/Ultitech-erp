import { X } from 'lucide-react';

interface PayModalHeaderProps {
  onClose: () => void;
}

export default function PayModalHeader({ onClose }: PayModalHeaderProps) {
  return (
    <div className="sppd-pay-modal-head">
      <h2 id="sppd-pay-modal-title" className="sppd-pay-modal-title">Pay purchase</h2>
      <button type="button" className="sppd-pay-modal-close" onClick={onClose} aria-label="Close">
        <X className="w-5 h-5" />
      </button>
    </div>
  );
}
