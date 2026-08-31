export default function DeleteModal({ report, onCancel, onConfirm, busy }) {
  if (!report) return null

  return (
    <div className="sr-modal-backdrop" role="dialog" aria-modal="true">
      <div className="sr-modal">
        <div className="sr-modal-header">
          <h5>Delete Sales Report?</h5>
          <button type="button" className="sr-modal-close" onClick={onCancel} aria-label="Close">
            &times;
          </button>
        </div>
        <div className="sr-modal-body">
          <p>This action cannot be undone.</p>
          <p className="sr-modal-report-name">{report.report_name}</p>
        </div>
        <div className="sr-modal-footer">
          <button type="button" className="sr-btn sr-btn-outline" onClick={onCancel} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="sr-btn sr-btn-danger" onClick={onConfirm} disabled={busy}>
            {busy ? 'Deleting...' : 'Delete Report'}
          </button>
        </div>
      </div>
    </div>
  )
}
