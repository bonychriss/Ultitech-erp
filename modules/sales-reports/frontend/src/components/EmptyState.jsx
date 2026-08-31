import { DotLottieReact } from '@lottiefiles/dotlottie-react'
import { CFG } from '../config.js'

export default function EmptyState({ canCreate, onCreate }) {
  const lottieUrl = CFG.urls?.emptyLottie || '/assets/animations/nothing.lottie'

  return (
    <div className="sr-empty-state">
      <div className="sr-empty-lottie">
        <DotLottieReact src={lottieUrl} loop autoplay />
      </div>
      <h3>No reports yet</h3>
      <p className="sr-muted">Create your first report from Sales, Procurement, Finance, Fleet, or Store/Warehouse.</p>
      {canCreate && onCreate && (
        <button type="button" className="sr-btn sr-btn-primary sr-btn-lg sr-btn-rounded" onClick={onCreate}>
          + Create New Report
        </button>
      )}
    </div>
  )
}
