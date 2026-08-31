import { DotLottieReact } from '@lottiefiles/dotlottie-react'
import { CFG } from '../config.js'

const DEFAULT_DOWNLOAD_LOTTIE = '/loading/Book%20Loader.lottie'

export default function ExportDownloadOverlay({ open, message = 'Downloading...' }) {
  if (!open) return null

  const lottieUrl = CFG.urls?.downloadLottie || DEFAULT_DOWNLOAD_LOTTIE

  return (
    <div className="sr-export-overlay" role="status" aria-live="polite" aria-busy="true">
      <div className="sr-export-overlay-card">
        <div className="sr-export-lottie" aria-hidden="true">
          <DotLottieReact src={lottieUrl} loop autoplay />
        </div>
        <p className="sr-export-overlay-title">{message}</p>
      </div>
    </div>
  )
}
