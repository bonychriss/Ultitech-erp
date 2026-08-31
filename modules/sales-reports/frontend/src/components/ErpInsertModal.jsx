import { useState } from 'react'
import { apiUrl } from '../config.js'

export default function ErpInsertModal({ erpMenu, reportId, onClose, onInsert }) {
  const [mode, setMode] = useState('live')
  const [busy, setBusy] = useState(false)

  async function insert(source) {
    setBusy(true)
    try {
      const r = await fetch(apiUrl('erp-data.php', { report_id: reportId, source, mode }))
      const j = await r.json()
      if (j.success && j.html) {
        onInsert(j.html)
        onClose()
      } else {
        alert(j.error || 'Failed to load ERP data')
      }
    } catch {
      alert('Failed to load ERP data')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="word-modal-backdrop" onClick={onClose}>
      <div className="word-modal word-modal-lg" onClick={(e) => e.stopPropagation()}>
        <div className="word-modal-header">
          <h3>Insert ERP Data</h3>
          <button type="button" className="word-modal-close" onClick={onClose} aria-label="Close">&times;</button>
        </div>
        <div className="word-modal-body">
          <div className="word-mode-picker">
            <label><input type="radio" name="erpMode" checked={mode === 'live'} onChange={() => setMode('live')} /> <strong>Live Data</strong> - updates from ERP</label>
            <label><input type="radio" name="erpMode" checked={mode === 'snapshot'} onChange={() => setMode('snapshot')} /> <strong>Snapshot</strong> - editable copy</label>
          </div>
          <div className="word-erp-grid">
            {Object.entries(erpMenu).map(([group, items]) => (
              <div key={group} className="word-erp-group">
                <h4>{group}</h4>
                {Object.entries(items).map(([key, label]) => (
                  <button key={key} type="button" disabled={busy} onClick={() => insert(key)}>{label}</button>
                ))}
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
