import { useState, useRef, useEffect } from 'react'
import { pageUrl } from '../config.js'
import ExportDownloadOverlay from './ExportDownloadOverlay.jsx'
import { useReportExport } from '../lib/useReportExport.js'

function ExportMenu({ reportId }) {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  const { exporting, exportMessage, runExport } = useReportExport()

  useEffect(() => {
    function onDocClick(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('click', onDocClick)
    return () => document.removeEventListener('click', onDocClick)
  }, [])

  const formats = [
    { key: 'pdf', label: 'PDF' },
    { key: 'word', label: 'Word' },
    { key: 'excel', label: 'Excel' },
    { key: 'print', label: 'Print' },
  ]

  async function handleExport(format) {
    setOpen(false)
    await runExport(reportId, format)
  }

  return (
    <>
      <div className="sr-dropdown" ref={ref}>
        <button
          type="button"
          className="sr-card-icon-action"
          title="Export"
          aria-label="Export"
          aria-expanded={open}
          onClick={() => setOpen((v) => !v)}
        >
          <i className="bi bi-download" aria-hidden="true" />
        </button>
        {open && (
          <ul className="sr-dropdown-menu">
            {formats.map((f) => (
              <li key={f.key}>
                <button type="button" onClick={() => handleExport(f.key)}>
                  {f.label}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
      <ExportDownloadOverlay open={exporting} message={exportMessage} />
    </>
  )
}

export default function ReportCard({ report, permissions, onDuplicate, onDelete }) {
  const statusClass = `sr-status-${String(report.status).replace(/[^a-z]/g, '')}`
  const editorUrl = pageUrl('editor', report.id)

  function openReport() {
    window.location.href = editorUrl
  }

  function handleRowClick(e) {
    if (e.target.closest('a, button, .sr-dropdown')) return
    openReport()
  }

  function handleRowKeyDown(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return
    if (e.target.closest('a, button, .sr-dropdown')) return
    e.preventDefault()
    openReport()
  }

  return (
    <tr
      className="sr-report-row sr-report-row-clickable"
      onClick={handleRowClick}
      onKeyDown={handleRowKeyDown}
      tabIndex={0}
      role="link"
      aria-label={`Open ${report.report_name}`}
    >
      <td className="sr-report-cell sr-report-cell-name" data-label="Report">
        <span className="sr-report-name">{report.report_name}</span>
        {report.domain_label && (
          <span className="sr-report-domain-badge" style={{ color: report.domain_color || '#6366f1' }}>
            {report.domain_label}
          </span>
        )}
      </td>
      <td className="sr-report-cell" data-label="Period">
        <span className="sr-report-cell-value">
          <i className="bi bi-calendar3" aria-hidden="true" />
          {report.period_label}
        </span>
      </td>
      <td className="sr-report-cell" data-label="Created by">
        <span className="sr-report-cell-value">
          <i className="bi bi-person" aria-hidden="true" />
          {report.creator_name}
        </span>
      </td>
      <td className="sr-report-cell" data-label="Last modified">
        <span className="sr-report-cell-value">
          <i className="bi bi-clock" aria-hidden="true" />
          {report.updated_label}
        </span>
      </td>
      <td className="sr-report-cell sr-report-cell-status" data-label="Status">
        <span className={`sr-status-badge ${statusClass}`}>{report.status_label}</span>
      </td>
      <td
        className="sr-report-cell sr-report-cell-actions"
        data-label="Actions"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sr-card-actions">
          {report.can_edit && (
            <a
              href={editorUrl}
              className="sr-card-icon-action sr-card-icon-action--primary"
              title="Edit"
              aria-label="Edit"
            >
              <i className="bi bi-pencil" aria-hidden="true" />
            </a>
          )}
          {permissions.duplicate && (
            <button
              type="button"
              className="sr-card-icon-action"
              title="Duplicate"
              aria-label="Duplicate"
              onClick={() => onDuplicate(report.id)}
            >
              <i className="bi bi-copy" aria-hidden="true" />
            </button>
          )}
          {permissions.delete && (
            <button
              type="button"
              className="sr-card-icon-action sr-card-icon-action--danger"
              title="Delete"
              aria-label="Delete"
              onClick={() => onDelete(report)}
            >
              <i className="bi bi-trash" aria-hidden="true" />
            </button>
          )}
          {permissions.export && <ExportMenu reportId={report.id} />}
        </div>
      </td>
    </tr>
  )
}
