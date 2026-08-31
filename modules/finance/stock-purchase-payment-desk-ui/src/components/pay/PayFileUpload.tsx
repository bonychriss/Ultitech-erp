import { Upload } from 'lucide-react';
import type { RefObject } from 'react';

interface PayFileUploadProps {
  inputRef: RefObject<HTMLInputElement | null>;
  file: File | null;
  onFileChange: (file: File | null) => void;
}

export default function PayFileUpload({ inputRef, file, onFileChange }: PayFileUploadProps) {
  return (
    <div className="sppd-pay-field sppd-pay-field--full">
      <label htmlFor="pay-proof">SWIFT / bank slip *</label>
      <button
        type="button"
        className={`sppd-pay-upload${file ? ' has-file' : ''}`}
        onClick={() => inputRef.current?.click()}
      >
        <Upload className="sppd-pay-upload-icon" aria-hidden="true" />
        <span className="sppd-pay-upload-text">
          {file ? file.name : 'Click to choose PDF, image, or document'}
        </span>
        <span className="sppd-pay-upload-action">{file ? 'Change file' : 'Browse'}</span>
      </button>
      <input
        ref={inputRef}
        id="pay-proof"
        type="file"
        className="sppd-pay-upload-input"
        accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx"
        onChange={(e) => onFileChange(e.target.files?.[0] ?? null)}
        required
      />
    </div>
  );
}
