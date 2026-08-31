import { createPortal } from 'react-dom'
import { Upload } from 'lucide-react'

export default function UploadOverlay({ active, message = 'Uploading...', hint = '' }) {
  if (!active || typeof document === 'undefined') return null

  return createPortal(
    <div className="cv-upload-overlay" role="status" aria-live="polite" aria-busy="true">
      <div className="cv-upload-panel">
        <div className="cv-upload-icon-wrap" aria-hidden="true">
          <Upload size={26} className="cv-upload-icon" />
          <span className="cv-upload-pulse" />
        </div>
        <p className="cv-upload-msg">{message}</p>
        {hint ? <p className="cv-upload-hint">{hint}</p> : null}
        <div className="cv-upload-bar">
          <div className="cv-upload-bar-fill" />
        </div>
      </div>
    </div>,
    document.body,
  )
}

/** Let React paint the overlay before starting a fast network request. */
export function waitForOverlayPaint() {
  return new Promise((resolve) => {
    requestAnimationFrame(() => {
      requestAnimationFrame(resolve)
    })
  })
}
