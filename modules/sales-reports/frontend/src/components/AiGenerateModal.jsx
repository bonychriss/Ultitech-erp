import { useState } from 'react'
import { apiUrl } from '../config.js'

export default function AiGenerateModal({ reportId, sectionCatalog, onClose, onInsert }) {
  const [section, setSection] = useState('executive_summary')
  const [instruction, setInstruction] = useState('')
  const [busy, setBusy] = useState(false)

  async function generate() {
    setBusy(true)
    try {
      const fd = new FormData()
      fd.append('report_id', reportId)
      fd.append('section', section)
      fd.append('instruction', instruction)
      const r = await fetch(apiUrl('ai-generate.php'), { method: 'POST', body: fd })
      const j = await r.json()
      if (j.success && j.text) {
        onInsert(j.text)
        onClose()
      } else {
        alert(j.error || 'AI generation failed')
      }
    } catch {
      alert('AI generation failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="word-modal-backdrop" onClick={onClose}>
      <div className="word-modal" onClick={(e) => e.stopPropagation()}>
        <div className="word-modal-header">
          <h3>AI Generate Content</h3>
          <button type="button" className="word-modal-close" onClick={onClose} aria-label="Close">&times;</button>
        </div>
        <div className="word-modal-body">
          <p className="word-modal-note">Generates narrative using exact ERP team figures. Group-wise report for all sales personnel.</p>
          <label className="word-field">
            Section
            <select value={section} onChange={(e) => setSection(e.target.value)}>
              {Object.entries(sectionCatalog).map(([key, label]) => (
                <option key={key} value={key}>{label}</option>
              ))}
            </select>
          </label>
          <label className="word-field">
            Additional instruction (optional)
            <textarea rows={3} value={instruction} onChange={(e) => setInstruction(e.target.value)} placeholder="e.g. Focus on team target achievement" />
          </label>
        </div>
        <div className="word-modal-footer">
          <button type="button" className="word-title-btn" onClick={onClose}>Cancel</button>
          <button type="button" className="word-title-btn word-title-btn-primary" disabled={busy} onClick={generate}>
            {busy ? 'Generating...' : 'Generate'}
          </button>
        </div>
      </div>
    </div>
  )
}
