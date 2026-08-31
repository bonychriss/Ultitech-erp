export default function LeaveSiteModal({ open, onLeave, onCancel }) {
  if (!open) return null

  return (
    <div className="word-leave-backdrop" onClick={onCancel}>
      <div
        className="word-leave-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="word-leave-title"
        onClick={(e) => e.stopPropagation()}
      >
        <h3 id="word-leave-title" className="word-leave-title">Unsaved changes</h3>
        <p className="word-leave-message">Changes you made may not be saved.</p>
        <div className="word-leave-actions">
          <button type="button" className="word-leave-btn word-leave-btn-leave" onClick={onLeave}>
            Leave
          </button>
          <button type="button" className="word-leave-btn word-leave-btn-cancel" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </div>
    </div>
  )
}
