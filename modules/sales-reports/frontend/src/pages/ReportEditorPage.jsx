import { useCallback, useEffect, useRef, useState } from 'react'
import { CFG, apiUrl } from '../config.js'
import { prepareHtmlForEditor, loadHtmlIntoEditor } from '../lib/prepareHtmlForEditor.js'
import WordDocument from '../components/WordDocument.jsx'
import WordRibbon from '../components/WordRibbon.jsx'
import CreateReportTypeModal from '../components/CreateReportTypeModal.jsx'
import ReportInfoPanel from '../components/ReportInfoPanel.jsx'
import EditorUndoRedo from '../components/EditorUndoRedo.jsx'
import EditorErrorState from '../components/EditorErrorState.jsx'
import LeaveSiteModal from '../components/LeaveSiteModal.jsx'
import ExportDownloadOverlay from '../components/ExportDownloadOverlay.jsx'
import { useUnsavedLeaveGuard } from '../lib/useUnsavedLeaveGuard.js'
import { useReportExport } from '../lib/useReportExport.js'
import './word-editor.css'

function buildSectionsPayload(html, existingSections) {
  const visible = (existingSections || []).filter((s) => s.visible !== false).sort((a, b) => (a.order || 0) - (b.order || 0))
  if (visible.length === 0) {
    return [{ id: `doc_${Date.now()}`, key: 'body', title: 'Document', order: 0, visible: true, content: html }]
  }
  const parts = html.split(/(?=<h2[\s>])/i).filter(Boolean)
  if (parts.length <= 1) {
    return visible.map((s, i) => ({ ...s, content: i === 0 ? html : s.content || '' }))
  }
  return visible.map((s, i) => ({ ...s, content: parts[i]?.trim() || '' }))
}

function buildContentHtml(sections) {
  return sections
    .filter((s) => s.visible !== false)
    .sort((a, b) => (a.order || 0) - (b.order || 0))
    .map((s) => s.content || '')
    .join('\n')
}

const DOMAIN_LABELS = {
  sales: 'Sales Report',
  procurement: 'Stock Report',
  finance: 'Finance Report',
  fleet: 'Driver / Fleet Report',
  store_warehouse: 'Store / Warehouse Report',
}

function resolveReportTypeLabel(report, defaults = null) {
  if (report?.domain_label) return report.domain_label
  const domainKey =
    report?.report_domain
    || defaults?.report_domain
    || CFG.defaults?.report_domain
    || CFG.report?.report_domain
    || new URLSearchParams(window.location.search).get('report_domain')
    || 'sales'
  const fromCatalog = (CFG.reportDomains || []).find((d) => d.key === domainKey)?.label
  return fromCatalog || DOMAIN_LABELS[domainKey] || 'Report'
}

function reportTypePhrase(report, defaults = null) {
  return resolveReportTypeLabel(report, defaults).toLowerCase()
}

function SaveStatusDisplay({ status }) {
  const label = status.replace(/\uFFFD/g, '-')
  const isBusy = /Saving|Creating|Refreshing|preparing/i.test(label)
  const isError = /failed|Error/i.test(label)
  const isUnsaved = label.includes('Unsaved')
  const isSavedNotice = /^Saved - /.test(label) || label.includes('Autofilled')
  const isIdle = label === 'Saved'

  const shouldShow = !isIdle && (isBusy || isError || isUnsaved || isSavedNotice || label !== 'Saved')
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    if (isIdle) {
      setVisible(false)
      return undefined
    }

    if (isSavedNotice && !isBusy) {
      setVisible(true)
      const timer = setTimeout(() => setVisible(false), 2800)
      return () => clearTimeout(timer)
    }

    setVisible(shouldShow)
    return undefined
  }, [label, isIdle, isBusy, isSavedNotice, shouldShow])

  if (!visible) return null

  const isSaved = label.includes('Saved') || label.includes('Autofilled')

  let icon = 'bi-cloud-check'
  if (isBusy) icon = 'bi-arrow-repeat word-icon-spin'
  else if (isError) icon = 'bi-exclamation-circle'
  else if (isUnsaved) icon = 'bi-pencil-square'
  else if (label.includes('Autofilled')) icon = 'bi-database-check'

  return (
    <span
      className={`word-save-status word-save-status--toast${isSaved ? ' saved' : ''}${isError ? ' error' : ''}${isUnsaved ? ' unsaved' : ''}`}
      title={label}
      role="status"
      aria-live="polite"
    >
      <i className={`bi ${icon}`} aria-hidden="true" />
      <span>{label}</span>
    </span>
  )
}

function applyEditorInitData(data, { setReport, setSections, applyDocumentContent, setSectionCatalog, setNeedsAutofill }) {
  setReport(data.report)
  setSections(data.document?.sections || [])
  applyDocumentContent(data.document?.content_html || '')
  setSectionCatalog(data.sectionCatalog || {})
  setNeedsAutofill(Boolean(data.document?.needs_autofill))
}

export default function ReportEditorPage() {
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(null)
  const [report, setReport] = useState(CFG.report || null)
  const [sections, setSections] = useState([])
  const [content, setContent] = useState('')
  const [editor, setEditor] = useState(null)
  const [ribbonTab, setRibbonTab] = useState('Home')
  const [saveStatus, setSaveStatus] = useState('Saved')
  const [dirty, setDirty] = useState(false)
  const [showInfo, setShowInfo] = useState(false)
  const [sectionCatalog, setSectionCatalog] = useState(CFG.sectionCatalog || {})
  const autosaveRef = useRef(null)
  const pendingInfoAfterSaveRef = useRef(Boolean(CFG.isNew))
  const infoPromptedRef = useRef(false)
  const autofillAttemptedRef = useRef(false)
  const [isFirstInfoPrompt, setIsFirstInfoPrompt] = useState(false)
  const [autofilling, setAutofilling] = useState(false)
  const [needsAutofill, setNeedsAutofill] = useState(Boolean(CFG.document?.needs_autofill))
  const [exportOpen, setExportOpen] = useState(false)
  const exportRef = useRef(null)
  const loadedReportRef = useRef(null)
  const editorInstanceRef = useRef(null)
  const contentRef = useRef('')
  const createDefaultsRef = useRef(CFG.defaults || null)
  const periodChosenRef = useRef(Boolean(CFG.selectedPeriod))
  const hasCreateDefaults = Boolean(CFG.defaults?.start_date && CFG.defaults?.end_date)
  const [showPeriodPicker, setShowPeriodPicker] = useState(Boolean(CFG.isNew && !CFG.selectedPeriod && !hasCreateDefaults))

  const reportId = report?.id
  const listUrl = CFG.urls?.list || '../index.php?module=analytics'
  const {
    leavePromptOpen,
    requestNavigation,
    confirmLeave,
    cancelLeave,
  } = useUnsavedLeaveGuard(dirty, listUrl)
  const { exporting, exportMessage, runExport } = useReportExport()

  const handleExport = useCallback(async (format) => {
    if (!reportId || exporting) return
    setExportOpen(false)
    await runExport(reportId, format)
  }, [exporting, reportId, runExport])

  const applyDocumentContent = useCallback((html) => {
    const next = prepareHtmlForEditor(html || '')
    contentRef.current = next
    setContent(next)
    if (editorInstanceRef.current) {
      loadHtmlIntoEditor(editorInstanceRef.current, next)
    }
  }, [])

  const handleEditorInit = useCallback((ed) => {
    editorInstanceRef.current = ed
    setEditor(ed)
    if (contentRef.current) {
      loadHtmlIntoEditor(ed, contentRef.current)
    }
  }, [])

  useEffect(() => {
    if (!editor) return undefined

    function handleKeyDown(e) {
      if (!(e.ctrlKey || e.metaKey)) return
      if (e.target instanceof HTMLElement && e.target.closest('.word-doc-title-input')) return

      const key = e.key.toLowerCase()
      if (key === 'z' && !e.shiftKey) {
        if (!editor.undoManager?.hasUndo()) return
        e.preventDefault()
        editor.undoManager.undo()
        editor.focus()
      } else if (key === 'y' || (key === 'z' && e.shiftKey)) {
        if (!editor.undoManager?.hasRedo()) return
        e.preventDefault()
        editor.undoManager.redo()
        editor.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [editor])

  const runAutofill = useCallback(async (force = false, { silent = false, id = null } = {}) => {
    const targetId = id || reportId
    if (!targetId) return
    if (!silent) setAutofilling(true)
    setSaveStatus(force ? 'Refreshing from ERP...' : 'AI preparing report from ERP data...')
    try {
      const fd = new FormData()
      fd.append('report_id', targetId)
      if (force) fd.append('force', '1')
      const r = await fetch(apiUrl('autofill.php'), {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
      })
      const j = await r.json()
      if (!j.success) throw new Error(j.error || 'Autofill failed')
      if (!j.skipped) {
        setSections(j.sections || [])
        applyDocumentContent(j.content_html || '')
        setDirty(false)
        const t = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        setSaveStatus(`Autofilled from ERP - ${t}`)
      } else {
        setSaveStatus('Saved')
      }
      setNeedsAutofill(false)
    } catch (e) {
      setSaveStatus('Autofill failed')
      setNeedsAutofill(false)
      if (force) alert(e.message || 'Could not refresh from ERP')
      throw e
    } finally {
      if (!silent) setAutofilling(false)
    }
  }, [reportId, applyDocumentContent])

  const loadExistingReport = useCallback(async (id) => {
    setLoading(true)
    setLoadError(null)
    try {
      const initR = await fetch(apiUrl('editor-init.php', { id }))
      const initJ = await initR.json()
      if (!initJ.success) throw new Error(initJ.error || 'Report not found')
      applyEditorInitData(initJ.data, {
        setReport,
        setSections,
        applyDocumentContent,
        setSectionCatalog,
        setNeedsAutofill,
      })
      setDirty(false)
      setSaveStatus('Saved')
      if (initJ.data.document?.needs_autofill) {
        try {
          await runAutofill(false, { silent: true, id: initJ.data.report.id })
        } catch {
          // Error already surfaced in runAutofill
        }
      }
    } catch (e) {
      setLoadError(e.message || 'Could not load report')
      setSaveStatus('Error')
    } finally {
      setLoading(false)
    }
  }, [applyDocumentContent, runAutofill])

  const bootstrapNewReport = useCallback(async (defaultsOverride = null) => {
    const defaults = defaultsOverride || createDefaultsRef.current || CFG.defaults
    if (!defaults) return
    setLoading(true)
    setSaveStatus(`Creating ${reportTypePhrase(null, defaults)}...`)
    try {
      const r = await fetch(apiUrl('create.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          report_domain: defaults.report_domain || 'sales',
          report_name: defaults.report_name,
          report_type: defaults.report_type || 'monthly',
          start_date: defaults.start_date,
          end_date: defaults.end_date,
          template_key: defaults.template_key || (defaults.report_domain && defaults.report_domain !== 'sales' ? 'standard' : 'monthly'),
          prepared_by: defaults.prepared_by || '',
          department: defaults.department || CFG.user?.department,
          filters: defaults.filters || {},
        }),
      })
      const j = await r.json()
      if (!j.success || !j.id) throw new Error(j.error || 'Create failed')

      const initR = await fetch(apiUrl('editor-init.php', { id: j.id }))
      const initJ = await initR.json()
      if (!initJ.success) throw new Error(initJ.error || 'Load failed')

      const data = initJ.data
      applyEditorInitData(data, {
        setReport,
        setSections,
        applyDocumentContent,
        setSectionCatalog,
        setNeedsAutofill,
      })
      setDirty(false)
      if (data.document?.needs_autofill) {
        try {
          await runAutofill(false, { silent: true, id: data.report.id })
        } catch {
          // Error already surfaced in runAutofill
        }
      }
      setSaveStatus('Unsaved changes')
      pendingInfoAfterSaveRef.current = true

      const url = new URL(window.location.href)
      url.searchParams.delete('new')
      url.searchParams.set('id', String(j.id))
      url.searchParams.set('module', CFG.module || 'analytics')
      window.history.replaceState({}, '', url.toString())
    } catch (e) {
      setSaveStatus('Error')
      alert(e.message || 'Could not create report')
    } finally {
      setLoading(false)
    }
  }, [applyDocumentContent, runAutofill])

  const handlePeriodSelect = useCallback((option) => {
    const defaults = option.defaults || option
    createDefaultsRef.current = defaults
    periodChosenRef.current = true
    setShowPeriodPicker(false)
    const url = new URL(window.location.href)
    url.searchParams.set('module', CFG.module || 'analytics')
    const domain = defaults.report_domain || option.domain || option.key
    if (domain && domain !== 'sales' && !['monthly', 'quarterly', 'annual'].includes(option.key)) {
      url.searchParams.delete('period')
      url.searchParams.set('report_domain', domain)
    } else {
      url.searchParams.set('period', option.key)
      url.searchParams.delete('report_domain')
    }
    if (defaults.start_date) url.searchParams.set('start_date', defaults.start_date)
    if (defaults.end_date) url.searchParams.set('end_date', defaults.end_date)
    window.history.replaceState({}, '', url.toString())
    bootstrapNewReport(defaults)
  }, [bootstrapNewReport])

  useEffect(() => {
    if (CFG.isNew) {
      if ((CFG.selectedPeriod && CFG.defaults) || hasCreateDefaults) {
        createDefaultsRef.current = CFG.defaults
        periodChosenRef.current = true
      }
      if (!periodChosenRef.current) {
        setShowPeriodPicker(true)
        setLoading(false)
        return
      }
      if (!report) bootstrapNewReport()
      return
    }
    const id = CFG.reportId || CFG.report?.id
    if (!id) {
      setLoadError('Missing report ID')
      setLoading(false)
      return
    }
    if (loadedReportRef.current === id) {
      return
    }
    loadedReportRef.current = id

    if (CFG.loadDocumentViaApi || !CFG.document?.content_html) {
      loadExistingReport(id)
      return
    }
    setSections(CFG.document?.sections || [])
    applyDocumentContent(CFG.document?.content_html || buildContentHtml(CFG.document?.sections || []))
    setSectionCatalog(CFG.sectionCatalog || {})
    setNeedsAutofill(Boolean(CFG.document?.needs_autofill))
    setLoading(false)
  }, [bootstrapNewReport, loadExistingReport, applyDocumentContent, report])

  useEffect(() => {
    autofillAttemptedRef.current = false
  }, [reportId])

  const saveDocument = useCallback(
    async (isAutosave = false) => {
      if (!reportId) return
      setSaveStatus(isAutosave ? 'Saving...' : 'Saving...')
      const secPayload = buildSectionsPayload(content, sections)
      const fd = new FormData()
      fd.append('report_id', reportId)
      fd.append('sections', JSON.stringify(secPayload))
      fd.append('content_html', content)
      if (isAutosave) fd.append('autosave', '1')

      try {
        const r = await fetch(apiUrl(isAutosave ? 'autosave.php' : 'save.php'), { method: 'POST', body: fd })
        const j = await r.json()
        if (j.success) {
          setSections(secPayload)
          setDirty(false)
          const t = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
          setSaveStatus(`Saved - ${t}`)

          if (!isAutosave && pendingInfoAfterSaveRef.current && !infoPromptedRef.current) {
            infoPromptedRef.current = true
            pendingInfoAfterSaveRef.current = false
            setIsFirstInfoPrompt(true)
            setShowInfo(true)
          }
        } else {
          setSaveStatus('Save failed')
        }
      } catch {
        setSaveStatus('Save failed')
      }
    },
    [reportId, content, sections],
  )

  useEffect(() => {
    if (!dirty || !reportId) return undefined
    clearTimeout(autosaveRef.current)
    autosaveRef.current = setTimeout(() => saveDocument(true), 6000)
    return () => clearTimeout(autosaveRef.current)
  }, [dirty, content, reportId, saveDocument])

  const handleContentChange = (html) => {
    contentRef.current = html
    setContent(html)
    setDirty(true)
    setSaveStatus('Unsaved changes')
  }

  const insertHtml = (html) => {
    if (editor) {
      editor.insertContent(html)
      setDirty(true)
    }
  }

  const addSection = (key) => {
    const title = sectionCatalog[key] || key
    const html = `<h2>${title}</h2><p></p>`
    insertHtml(html)
    setSections((prev) => [
      ...prev,
      { id: `${key}_${Date.now()}`, key, title, order: prev.length, visible: true, content: html },
    ])
  }

  useEffect(() => {
    if (!exportOpen) return undefined
    const onDocClick = (e) => {
      if (exportRef.current && !exportRef.current.contains(e.target)) {
        setExportOpen(false)
      }
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [exportOpen])

  if (loadError) {
    return (
      <EditorErrorState
        title="Could not open this report"
        message={loadError}
        action={(
          <a href={CFG.urls?.list || '../index.php?module=analytics'} className="btn btn-primary btn-sm mt-3">
            Back to reports
          </a>
        )}
      />
    )
  }

  if (showPeriodPicker) {
    return (
      <>
        <div className="word-app-loading">
          <div className="word-app-loading-inner">
            <p>Select a report type to continue.</p>
          </div>
        </div>
        <CreateReportTypeModal
          open
          onClose={() => {
            window.location.href = CFG.urls?.list || '../index.php?module=analytics'
          }}
          onSelect={handlePeriodSelect}
        />
      </>
    )
  }

  if (loading) {
    const openingLabel = reportTypePhrase(report, createDefaultsRef.current || CFG.defaults)
    return (
      <div className="word-app-loading">
        <div className="word-app-loading-inner">
          <div className="word-spinner" />
          <p>Opening {openingLabel}...</p>
        </div>
      </div>
    )
  }

  return (
    <div className="word-app">
      <header className="word-titlebar">
        <div className="word-titlebar-nav">
          <a
            href={listUrl}
            className="word-back"
            onClick={(e) => {
              e.preventDefault()
              requestNavigation(listUrl)
            }}
          >
            <i className="bi bi-arrow-left" aria-hidden="true" />
            <span>Reports</span>
          </a>
        </div>

        <span className="word-titlebar-divider" aria-hidden="true" />

        <div className="word-titlebar-doc">
          <input
            type="text"
            className="word-doc-title-input"
            value={report?.report_name || ''}
            onChange={(e) => {
              setReport((r) => ({ ...r, report_name: e.target.value }))
              setDirty(true)
            }}
            onBlur={async () => {
              if (!reportId) return
              const fd = new FormData()
              fd.append('id', reportId)
              fd.append('report_name', report?.report_name || '')
              await fetch(apiUrl('rename.php'), { method: 'POST', body: fd })
            }}
            placeholder="Untitled Sales Report"
          />
        </div>

        <div className="word-titlebar-tools">
          <div className="word-titlebar-group word-titlebar-group--history">
            <EditorUndoRedo editor={editor} showLabels={false} />
          </div>

          <span className="word-titlebar-divider word-titlebar-divider--status" aria-hidden="true" />

          <div className="word-titlebar-group word-titlebar-group--status">
            <SaveStatusDisplay status={saveStatus} />
          </div>

          <span className="word-titlebar-divider" aria-hidden="true" />

          <div className="word-titlebar-group word-titlebar-group--actions">
            <button type="button" className="word-title-icon-btn" title="Info" aria-label="Info" onClick={() => setShowInfo(true)}>
              <i className="bi bi-info-circle" aria-hidden="true" />
            </button>
            <button type="button" className="word-title-icon-btn" title="Save" aria-label="Save" onClick={() => saveDocument(false)}>
              <i className="bi bi-floppy" aria-hidden="true" />
            </button>
            <div className="word-export-wrap" ref={exportRef}>
              <button
                type="button"
                className="word-title-icon-btn"
                title="Export"
                aria-label="Export"
                aria-expanded={exportOpen}
                onClick={() => setExportOpen((v) => !v)}
              >
                <i className="bi bi-download" aria-hidden="true" />
              </button>
              {exportOpen && (
                <div className="word-export-dropdown">
                  <button type="button" onClick={() => handleExport('pdf')}>
                    <i className="bi bi-file-earmark-pdf" aria-hidden="true" /> Download PDF
                  </button>
                  <button type="button" onClick={() => handleExport('word')}>
                    <i className="bi bi-file-earmark-word" aria-hidden="true" /> Download Word
                  </button>
                  <button type="button" onClick={() => handleExport('print')}>
                    <i className="bi bi-printer" aria-hidden="true" /> Print
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      </header>

      <WordRibbon
        editor={editor}
        activeTab={ribbonTab}
        onTabChange={setRibbonTab}
      />

      <div className="word-workspace">
        {autofilling && (
          <div className="word-autofill-overlay">
            <div className="word-spinner" />
            <p>Populating {reportTypePhrase(report, createDefaultsRef.current || CFG.defaults)} from ERP data...</p>
          </div>
        )}
        <main className="word-canvas-scroll">
          <div className="word-canvas">
            {reportId && !loading ? (
              <WordDocument
                initialContent={content}
                onChange={handleContentChange}
                onInit={handleEditorInit}
                readOnly={!report?.can_edit}
              />
            ) : null}
          </div>
        </main>
      </div>

      <footer className="word-statusbar">
        <span>{report?.status_label || 'Draft'}</span>
        <span>{report?.period_label}</span>
        <span>Group Sales Report</span>
      </footer>

      {showInfo && (
        <ReportInfoPanel
          report={report}
          reportId={reportId}
          sectionCatalog={sectionCatalog}
          isFirstSave={isFirstInfoPrompt}
          onClose={() => {
            setShowInfo(false)
            setIsFirstInfoPrompt(false)
          }}
          onUpdate={(meta) => {
            setReport((r) => ({ ...r, ...meta }))
          }}
          onAddSection={addSection}
        />
      )}

      <LeaveSiteModal
        open={leavePromptOpen}
        onLeave={confirmLeave}
        onCancel={cancelLeave}
      />

      <ExportDownloadOverlay open={exporting} message={exportMessage} />
    </div>
  )
}
