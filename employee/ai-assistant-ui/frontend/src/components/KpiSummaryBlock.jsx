import { useCallback, useRef } from 'react'
import { Eye, FileText, Sheet, Share2 } from 'lucide-react'
import { CFG } from '../config.js'

const ICONS = {
  eye: Eye,
  file: FileText,
  sheet: Sheet,
  share: Share2,
}

function toneClass(tone) {
  if (tone === 'success') return 'ea-kpi-card--success'
  if (tone === 'warning') return 'ea-kpi-card--warning'
  if (tone === 'danger') return 'ea-kpi-card--danger'
  return ''
}

async function postExport(format, report) {
  if (!CFG.exportUrl) return
  const res = await fetch(CFG.exportUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: '*/*' },
    body: JSON.stringify({ format, report }),
  })
  if (!res.ok) throw new Error('Export failed')
  const blob = await res.blob()
  const ext = format === 'csv' || format === 'excel' ? 'csv' : 'html'
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${(report.title || 'report').replace(/\s+/g, '-').toLowerCase()}-${new Date().toISOString().slice(0, 10)}.${ext}`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
  if (format === 'pdf') {
    const html = await blob.text()
    const win = window.open('', '_blank')
    if (win) {
      win.document.write(html)
      win.document.close()
    }
  }
}

function exportPngFromElement(el, rich) {
  if (!el || !rich) return
  const canvas = document.createElement('canvas')
  const width = 720
  let height = 120
  const cards = rich.cards || []
  const table = rich.table || {}
  const rowCount = (table.rows || []).length
  height += 100 + (rowCount + 2) * 28

  canvas.width = width * 2
  canvas.height = height * 2
  const ctx = canvas.getContext('2d')
  if (!ctx) return
  ctx.scale(2, 2)
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, width, height)

  let y = 28
  ctx.fillStyle = '#111827'
  ctx.font = 'bold 18px Inter, Arial, sans-serif'
  ctx.fillText(rich.title || 'KPI Summary', 24, y)
  y += 24
  if (rich.periodLabel) {
    ctx.fillStyle = '#6b7280'
    ctx.font = '13px Inter, Arial, sans-serif'
    ctx.fillText(rich.periodLabel, 24, y)
    y += 28
  }

  const cardWidth = Math.min(128, (width - 48 - 12 * Math.max(cards.length - 1, 0)) / Math.max(cards.length, 1))
  let x = 24
  cards.forEach((card) => {
    ctx.fillStyle = '#f9fafb'
    ctx.strokeStyle = '#e5e7eb'
    ctx.fillRect(x, y, cardWidth, 68)
    ctx.strokeRect(x, y, cardWidth, 68)
    ctx.fillStyle = '#6b7280'
    ctx.font = '10px Inter, Arial, sans-serif'
    ctx.fillText(String(card.label || '').toUpperCase(), x + 10, y + 18)
    ctx.fillStyle = '#111827'
    ctx.font = 'bold 14px Inter, Arial, sans-serif'
    ctx.fillText(String(card.value || ''), x + 10, y + 42)
    x += cardWidth + 12
  })
  y += 84

  const cols = table.columns || []
  if (cols.length > 0) {
    ctx.fillStyle = '#111827'
    ctx.font = 'bold 13px Inter, Arial, sans-serif'
    ctx.fillText('Breakdown by Budget Type', 24, y)
    y += 20
    const colWidth = (width - 48) / cols.length
    cols.forEach((col, i) => {
      ctx.fillStyle = '#f3f4f6'
      ctx.fillRect(24 + i * colWidth, y, colWidth, 24)
      ctx.fillStyle = '#6b7280'
      ctx.font = '10px Inter, Arial, sans-serif'
      ctx.fillText(String(col), 28 + i * colWidth, y + 16)
    })
    y += 24
    ;(table.rows || []).forEach((row) => {
      row.forEach((cell, i) => {
        ctx.fillStyle = '#ffffff'
        ctx.fillRect(24 + i * colWidth, y, colWidth, 24)
        ctx.strokeStyle = '#e5e7eb'
        ctx.strokeRect(24 + i * colWidth, y, colWidth, 24)
        ctx.fillStyle = '#111827'
        ctx.font = '12px Inter, Arial, sans-serif'
        ctx.fillText(String(cell), 28 + i * colWidth, y + 16)
      })
      y += 24
    })
  }

  canvas.toBlob((blob) => {
    if (!blob) return
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${(rich.title || 'kpi-summary').replace(/\s+/g, '-').toLowerCase()}.png`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }, 'image/png')
}

export default function KpiSummaryBlock({ rich }) {
  const blockRef = useRef(null)

  const handleAction = useCallback(async (action) => {
    if (!rich) return
    if (action.id === 'view_details') {
      window.location.href = CFG.vouchersUrl || '../my-vouchers.php?module=voucher'
      return
    }
    if (action.type === 'export') {
      const format = action.format === 'excel' ? 'csv' : action.format
      try {
        if (format === 'png') {
          exportPngFromElement(blockRef.current, rich)
          return
        }
        if (format === 'pdf') {
          const res = await fetch(CFG.exportUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ format: 'pdf', report: rich }),
          })
          const html = await res.text()
          const win = window.open('', '_blank')
          if (win) {
            win.document.write(html)
            win.document.close()
          }
          return
        }
        await postExport(format, rich)
      } catch {
        /* ignore */
      }
    }
  }, [rich])

  if (!rich || rich.type !== 'kpi_summary') return null

  const { title, periodLabel, cards = [], table = {}, actions = [] } = rich

  return (
    <div className="ea-kpi-block" ref={blockRef}>
      <div className="ea-kpi-head">
        <h3 className="ea-kpi-title">{title}</h3>
        {periodLabel && <p className="ea-kpi-period">{periodLabel}</p>}
      </div>

      <div className="ea-kpi-cards">
        {cards.map((card) => (
          <div key={card.key || card.label} className={`ea-kpi-card${toneClass(card.tone) ? ` ${toneClass(card.tone)}` : ''}`}>
            <div className="ea-kpi-card-label">{card.label}</div>
            <div className="ea-kpi-card-value">{card.value}</div>
          </div>
        ))}
      </div>

      {table.rows?.length > 0 && (
        <div className="ea-kpi-table-wrap">
          <table className="ea-kpi-table">
            <thead>
              <tr>
                {(table.columns || []).map((col) => <th key={col}>{col}</th>)}
              </tr>
            </thead>
            <tbody>
              {table.rows.map((row, i) => (
                <tr key={`row-${i}`}>
                  {row.map((cell, j) => <td key={`${i}-${j}`}>{cell}</td>)}
                </tr>
              ))}
            </tbody>
            {table.footer?.length > 0 && (
              <tfoot>
                <tr>
                  {table.footer.map((cell, j) => <td key={`f-${j}`}>{cell}</td>)}
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}

      {actions.length > 0 && (
        <div className="ea-kpi-actions">
          {actions.map((action) => {
            const Icon = ICONS[action.icon] || FileText
            return (
              <button
                key={action.id}
                type="button"
                className="ea-kpi-action-btn"
                onClick={() => handleAction(action)}
              >
                <Icon size={14} aria-hidden="true" />
                {action.label}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
