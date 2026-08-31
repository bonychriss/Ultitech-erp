import { useState } from 'react'
import { apiUrl } from '../config.js'

export default function ReportInfoPanel({ report, reportId, sectionCatalog, onClose, onUpdate, onAddSection, isFirstSave = false }) {
  const [meta, setMeta] = useState({
    start_date: report?.start_date || '',
    end_date: report?.end_date || '',
    prepared_by: report?.prepared_by || '',
    department: report?.department || '',
    branch: report?.branch || '',
    status: report?.status || 'draft',
    description: report?.description || '',
  })
  const [newSection, setNewSection] = useState('executive_summary')
  const [saving, setSaving] = useState(false)

  async function saveMeta() {
    setSaving(true)
    try {
      const fd = new FormData()
      fd.append('report_id', reportId)
      Object.entries(meta).forEach(([k, v]) => fd.append(k, v))
      await fetch(apiUrl('update-meta.php'), { method: 'POST', body: fd })
      onUpdate(meta)
      onClose()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="word-modal-backdrop" onClick={isFirstSave ? undefined : onClose}>
      <div className="word-modal word-modal-lg" onClick={(e) => e.stopPropagation()}>
        <div className="word-modal-header">
          <h3>Report Information</h3>
          {!isFirstSave && (
            <button type="button" className="word-modal-close" onClick={onClose} aria-label="Close">&times;</button>
          )}
        </div>
        {isFirstSave && (
          <p className="word-modal-intro">Your report has been saved. Confirm the report details below, then continue editing.</p>
        )}
        <div className="word-modal-body word-info-grid">
          <label className="word-field">Start Date<input type="date" value={meta.start_date} onChange={(e) => setMeta({ ...meta, start_date: e.target.value })} /></label>
          <label className="word-field">End Date<input type="date" value={meta.end_date} onChange={(e) => setMeta({ ...meta, end_date: e.target.value })} /></label>
          <label className="word-field">Prepared By<input type="text" value={meta.prepared_by} onChange={(e) => setMeta({ ...meta, prepared_by: e.target.value })} /></label>
          <label className="word-field">Department<input type="text" value={meta.department} onChange={(e) => setMeta({ ...meta, department: e.target.value })} /></label>
          <label className="word-field">Branch<input type="text" value={meta.branch} onChange={(e) => setMeta({ ...meta, branch: e.target.value })} /></label>
          <label className="word-field">Status
            <select value={meta.status} onChange={(e) => setMeta({ ...meta, status: e.target.value })}>
              <option value="draft">Draft</option>
              <option value="under_review">Under Review</option>
              <option value="approved">Approved</option>
              <option value="final">Final</option>
              <option value="archived">Archived</option>
            </select>
          </label>
          <label className="word-field word-field-full">Description<textarea rows={2} value={meta.description} onChange={(e) => setMeta({ ...meta, description: e.target.value })} /></label>

          <div className="word-field word-field-full word-add-section">
            <span>Add Section to Document</span>
            <div className="word-add-section-row">
              <select value={newSection} onChange={(e) => setNewSection(e.target.value)}>
                {Object.entries(sectionCatalog).map(([key, label]) => (
                  <option key={key} value={key}>{label}</option>
                ))}
              </select>
              <button type="button" onClick={() => { onAddSection(newSection); onClose() }}>Add</button>
            </div>
          </div>
        </div>
        <div className="word-modal-footer">
          {!isFirstSave && (
            <button type="button" className="word-title-btn" onClick={onClose}>Close</button>
          )}
          <button type="button" className="word-title-btn word-title-btn-primary" disabled={saving} onClick={saveMeta}>
            {saving ? 'Saving...' : isFirstSave ? 'Save & Continue' : 'Save Info'}
          </button>
        </div>
      </div>
    </div>
  )
}
