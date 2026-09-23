import { useCallback, useEffect, useRef, useState } from 'react'
import {
  HiOutlineClipboardDocumentList,
  HiOutlineUserGroup,
  HiOutlineChartBar,
  HiOutlinePlus,
  HiOutlineXMark,
  HiOutlineChevronLeft,
  HiOutlineChevronRight,
  HiOutlineCalendarDays,
  HiOutlineInformationCircle,
} from 'react-icons/hi2'
import { CFG } from '../config.js'

const VEHICLE_CARE_TASK_HINT =
  'e.g. Oil change due, morning checks, tyre pressure'

function SearchableSelect({
  label,
  value,
  options = [],
  onChange,
  multiple = false,
  placeholder = 'Select from system',
  searchPlaceholder = 'Type to search...',
  emptyText = 'No matches',
}) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const rootRef = useRef(null)
  const selectedIds = multiple
    ? (Array.isArray(value) ? value.map(String) : [])
    : value
      ? [String(value)]
      : []
  const selectedOptions = options.filter((o) => selectedIds.includes(String(o.id)))
  const filtered = options.filter((o) => {
    const q = query.trim().toLowerCase()
    if (!q) return true
    return String(o.label || '').toLowerCase().includes(q)
  })

  useEffect(() => {
    if (!open) return undefined
    const onDoc = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) {
        setOpen(false)
      }
    }
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const triggerLabel = (() => {
    if (selectedOptions.length === 0) return placeholder
    if (!multiple) return selectedOptions[0].label
    if (selectedOptions.length === 1) return selectedOptions[0].label
    return `${selectedOptions.length} vouchers selected`
  })()

  const clearAll = () => {
    onChange(multiple ? [] : '')
    setQuery('')
  }

  const toggleOption = (id) => {
    const sid = String(id)
    if (!multiple) {
      onChange(sid)
      setOpen(false)
      setQuery('')
      return
    }
    const next = selectedIds.includes(sid)
      ? selectedIds.filter((x) => x !== sid)
      : [...selectedIds, sid]
    onChange(next)
  }

  return (
    <div className="dkpi-field" ref={rootRef}>
      {label ? <span>{label}</span> : null}
      <div className={`dkpi-search-select${open ? ' is-open' : ''}${multiple ? ' is-multi' : ''}`}>
        <button
          type="button"
          className="dkpi-search-select__trigger"
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-multiselectable={multiple || undefined}
          onClick={() => setOpen((v) => !v)}
        >
          <span className={selectedOptions.length ? '' : 'is-placeholder'}>{triggerLabel}</span>
          {selectedOptions.length > 0 ? (
            <span
              className="dkpi-search-select__clear"
              role="button"
              tabIndex={0}
              onClick={(e) => {
                e.preventDefault()
                e.stopPropagation()
                clearAll()
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault()
                  e.stopPropagation()
                  clearAll()
                }
              }}
            >
              Clear
            </span>
          ) : null}
        </button>
        {multiple && selectedOptions.length > 0 ? (
          <div className="dkpi-search-select__chips">
            {selectedOptions.map((o) => (
              <button
                key={o.id}
                type="button"
                className="dkpi-search-select__chip"
                onClick={() => toggleOption(o.id)}
                title="Remove"
              >
                <span>{o.voucher_no || o.label}</span>
                <em aria-hidden="true">x</em>
              </button>
            ))}
          </div>
        ) : null}
        {open ? (
          <div className="dkpi-search-select__panel">
            <input
              type="search"
              className="dkpi-search-select__search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={searchPlaceholder}
              autoFocus
            />
            <ul className="dkpi-search-select__list" role="listbox">
              {filtered.length === 0 ? (
                <li className="dkpi-search-select__empty">{emptyText}</li>
              ) : (
                filtered.slice(0, 80).map((o) => {
                  const active = selectedIds.includes(String(o.id))
                  return (
                    <li key={o.id}>
                      <button
                        type="button"
                        role="option"
                        aria-selected={active}
                        className={`dkpi-search-select__option${active ? ' is-active' : ''}`}
                        onClick={() => toggleOption(o.id)}
                      >
                        {multiple ? (
                          <span className={`dkpi-search-select__check${active ? ' is-on' : ''}`} aria-hidden="true" />
                        ) : null}
                        <span>{o.label}</span>
                      </button>
                    </li>
                  )
                })
              )}
            </ul>
            {multiple ? (
              <div className="dkpi-search-select__footer">
                <small>{selectedOptions.length} selected</small>
                <button type="button" className="dkpi-search-select__done" onClick={() => setOpen(false)}>
                  Done
                </button>
              </div>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  )
}

function fmtPct(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '-'
  return `${v.toFixed(1)}%`
}

function fmtTarget(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return ''
  return Number.isInteger(v) ? String(v) : String(parseFloat(v.toFixed(1)))
}

function weekLabel(week) {
  if (!week?.week_start || !week?.week_end) return ''
  const start = new Date(`${week.week_start}T12:00:00`)
  const end = new Date(`${week.week_end}T12:00:00`)
  const optsStart = { day: 'numeric', month: 'short' }
  const optsEnd = { day: 'numeric', month: 'short', year: 'numeric' }
  return `${start.toLocaleDateString('en-GB', optsStart)} - ${end.toLocaleDateString('en-GB', optsEnd)}`
}

function formatDay(iso) {
  if (!iso) return ''
  const d = new Date(`${iso}T12:00:00`)
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}

function newWorkId() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID().replace(/-/g, '').slice(0, 16)
  }
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`
}

async function apiRequest(apiUrl, { action, method = 'GET', body } = {}) {
  const url = new URL(apiUrl, window.location.href)
  url.searchParams.set('action', action)
  const opts = {
    method,
    credentials: 'same-origin',
    headers: {},
  }
  if (method === 'GET') {
    if (body && typeof body === 'object') {
      Object.entries(body).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v))
      })
    }
  } else {
    opts.headers['Content-Type'] = 'application/json'
    opts.body = JSON.stringify({ action, ...(body || {}) })
  }
  const res = await fetch(url.toString(), opts)
  const data = await res.json().catch(() => ({}))
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`)
  }
  return data
}

async function apiUpload(apiUrl, { userId, service, weekOffset, file }) {
  const url = new URL(apiUrl, window.location.href)
  url.searchParams.set('action', 'upload')
  const fd = new FormData()
  fd.append('action', 'upload')
  fd.append('user_id', String(userId || 0))
  if (service) fd.append('service', service)
  if (weekOffset != null) fd.append('week_offset', String(weekOffset))
  if (file) fd.append('file', file)
  const res = await fetch(url.toString(), {
    method: 'POST',
    credentials: 'same-origin',
    body: fd,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Upload failed (${res.status})`)
  }
  return data
}

function attachmentHref(attOrPath) {
  if (!attOrPath) return '#'
  if (typeof attOrPath === 'string') {
    if (/^https?:\/\//i.test(attOrPath) || attOrPath.startsWith('/')) return attOrPath
    return `/${String(attOrPath).replace(/^\/+/, '')}`
  }
  if (attOrPath.url) return attOrPath.url
  if (attOrPath.path) {
    const path = String(attOrPath.path)
    if (/^https?:\/\//i.test(path) || path.startsWith('/')) return path
    return `/${path.replace(/^\/+/, '')}`
  }
  return '#'
}

function workLogAttachments(item) {
  const vouchers =
    Array.isArray(item.vouchers) && item.vouchers.length > 0
      ? item.vouchers
      : item.voucher
        ? [item.voucher]
        : []
  return { vouchers, letter: item.letter || null, document: item.document || null }
}

function WorkLogTable({ items, onRemove }) {
  if (!items.length) return null
  return (
    <div className="dkpi-desk-table-wrap">
      <table className="dkpi-desk-table">
        <thead>
          <tr>
            <th>Type</th>
            <th>Date</th>
            <th>Description</th>
            <th>Payment voucher</th>
            <th>Letter</th>
            <th>Upload</th>
            {onRemove ? <th style={{ textAlign: 'right' }}>Actions</th> : null}
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const files = workLogAttachments(item)
            return (
              <tr key={item.id}>
                <td>
                  <span className={`dkpi-work-tag dkpi-work-tag--${item.type || 'vehicle_care'}`}>
                    {item.label || 'Vehicle Care'}
                  </span>
                </td>
                <td className="dkpi-desk-table__muted">{formatDay(item.at) || '-'}</td>
                <td>
                  <div className="dkpi-desk-table__desc">{item.task_description || item.text || '-'}</div>
                </td>
                <td>
                  {files.vouchers.length > 0 ? (
                    <div className="dkpi-desk-table__links">
                      {files.vouchers.map((v, idx) => (
                        <a
                          key={`${item.id}-voucher-${v.id || idx}`}
                          className="dkpi-file-link"
                          href={attachmentHref(v)}
                          target="_blank"
                          rel="noreferrer"
                        >
                          {v.name || 'View voucher'}
                        </a>
                      ))}
                    </div>
                  ) : (
                    <span className="dkpi-desk-table__muted">-</span>
                  )}
                </td>
                <td>
                  {files.letter ? (
                    <a
                      className="dkpi-file-link"
                      href={attachmentHref(files.letter)}
                      target="_blank"
                      rel="noreferrer"
                    >
                      {files.letter.name || 'View letter'}
                    </a>
                  ) : (
                    <span className="dkpi-desk-table__muted">-</span>
                  )}
                </td>
                <td>
                  {files.document ? (
                    <a
                      className="dkpi-file-link"
                      href={attachmentHref(files.document)}
                      target="_blank"
                      rel="noreferrer"
                    >
                      {files.document.name || 'View file'}
                    </a>
                  ) : (
                    <span className="dkpi-desk-table__muted">-</span>
                  )}
                </td>
                {onRemove ? (
                  <td style={{ textAlign: 'right' }}>
                    <button
                      type="button"
                      className="dkpi-desk-table__action"
                      onClick={() => onRemove(item.id)}
                    >
                      Remove
                    </button>
                  </td>
                ) : null}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function entryWorkCount(entry) {
  const log = Array.isArray(entry?.work_log) ? entry.work_log : []
  return log.length
}

function EntriesTable({ entries, selectedUserId, onSelect }) {
  if (!entries.length) return null
  return (
    <div className="dkpi-desk-table-wrap">
      <table className="dkpi-desk-table">
        <thead>
          <tr>
            <th>Driver</th>
            <th>Work items</th>
            <th>On-time</th>
            <th>Vehicle care</th>
            <th>Documentation</th>
            <th>Score</th>
          </tr>
        </thead>
        <tbody>
          {entries.map((row) => {
            const uid = Number(row.user_id || row.id || 0)
            const selected = uid === Number(selectedUserId)
            const score = row.weighted_score != null ? Number(row.weighted_score) : null
            return (
              <tr
                key={`${uid}-${row.service_type || 'entry'}`}
                className={selected ? 'is-selected' : ''}
                onClick={() => onSelect(uid)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault()
                    onSelect(uid)
                  }
                }}
                tabIndex={0}
                role="button"
                aria-pressed={selected}
              >
                <td>
                  <strong>{row.full_name || `Driver #${uid}`}</strong>
                </td>
                <td>{entryWorkCount(row)}</td>
                <td>{fmtPct(row.on_time_pct ?? 0)}</td>
                <td>{fmtPct(row.vehicle_care_pct ?? 0)}</td>
                <td>{fmtPct(row.documentation_pct ?? 0)}</td>
                <td>
                  <strong>{score != null ? fmtPct(score) : '-'}</strong>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default function DriverKpiPage() {
  const boot = CFG.data || {}
  const urls = boot.urls || {}
  const apiUrl = CFG.apiUrl || urls.api || '../driver-kpi/api/index.php'

  const [service, setService] = useState(boot.service || 'ride')
  const [weekOffset, setWeekOffset] = useState(Number(boot.weekOffset || 0))
  const [week, setWeek] = useState(boot.week || null)
  const [metrics, setMetrics] = useState(boot.metrics || [])
  const [employees, setEmployees] = useState(boot.employees || [])
  const [entries, setEntries] = useState(boot.entries || [])
  const [selectedUserId, setSelectedUserId] = useState(
    Number(boot.selectedUserId || boot.viewer?.id || 0)
  )
  const [scores, setScores] = useState({
    on_time_pct: boot.entry?.on_time_pct ?? '',
    vehicle_care_pct: boot.entry?.vehicle_care_pct ?? '',
    documentation_pct: boot.entry?.documentation_pct ?? '',
  })
  const [workLog, setWorkLog] = useState(
    Array.isArray(boot.entry?.work_log) ? boot.entry.work_log : []
  )
  const [draftTaskDescription, setDraftTaskDescription] = useState('')
  const [draftUploadFile, setDraftUploadFile] = useState(null)
  const [draftVoucherIds, setDraftVoucherIds] = useState([])
  const [draftLetterId, setDraftLetterId] = useState('')
  const [systemVouchers, setSystemVouchers] = useState([])
  const [systemLetters, setSystemLetters] = useState([])
  const [scorePreview, setScorePreview] = useState(
    boot.entry?.weighted_score != null ? Number(boot.entry.weighted_score) : null
  )
  const [analysis, setAnalysis] = useState(null)
  const [analyzing, setAnalyzing] = useState(false)
  const [flash, setFlash] = useState(null)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(false)
  const [modalOpen, setModalOpen] = useState(false)
  const [scoreModalOpen, setScoreModalOpen] = useState(false)
  const [aboutOpen, setAboutOpen] = useState(false)

  const viewer = boot.viewer || { id: 0, name: 'User', isAdmin: false, isDriver: false }
  const isAdmin = !!viewer.isAdmin
  const isDriver = !!viewer.isDriver
  const canUseWorkLog = isAdmin || isDriver

  const applyEntry = (entry) => {
    setScores({
      on_time_pct: entry?.on_time_pct ?? '',
      vehicle_care_pct: entry?.vehicle_care_pct ?? '',
      documentation_pct: entry?.documentation_pct ?? '',
    })
    setWorkLog(Array.isArray(entry?.work_log) ? entry.work_log : [])
    setScorePreview(entry?.weighted_score != null ? Number(entry.weighted_score) : null)
  }

  const applyAnalysis = (next, entry) => {
    if (entry) applyEntry(entry)
    if (next && typeof next === 'object') {
      setAnalysis(next)
      if (next.weighted_score != null) {
        setScorePreview(Number(next.weighted_score))
      }
      if (entry == null) {
        setScores({
          on_time_pct: next.on_time_pct ?? '',
          vehicle_care_pct: next.vehicle_care_pct ?? '',
          documentation_pct: next.documentation_pct ?? '',
        })
      }
    }
  }

  const loadDesk = useCallback(
    async (nextService, nextWeek, nextUserId) => {
      setLoading(true)
      setError(null)
      try {
        const list = await apiRequest(apiUrl, {
          action: 'list',
          body: {
            service: nextService,
            week_offset: nextWeek,
          },
        })
        const uid = isAdmin
          ? Number(nextUserId || selectedUserId || viewer.id || 0)
          : Number(viewer.id || 0)
        const got = await apiRequest(apiUrl, {
          action: 'get',
          body: {
            service: nextService,
            week_offset: nextWeek,
            user_id: uid,
          },
        })

        setService(list.service || nextService)
        setWeek(list.week || got.week || null)
        setWeekOffset(nextWeek)
        setMetrics(list.metrics || got.metrics || [])
        setEntries(list.entries || [])
        setSelectedUserId(uid)
        applyEntry(got.entry || null)
      } catch (e) {
        setError(e.message || 'Failed to load')
      } finally {
        setLoading(false)
      }
    },
    [apiUrl, isAdmin, selectedUserId, viewer.id]
  )

  useEffect(() => {
    if (isAdmin && employees.length === 0) {
      apiRequest(apiUrl, { action: 'employees' })
        .then((data) => {
          const list = data.employees || []
          setEmployees(list)
          if (list.length > 0) {
            const ids = list.map((e) => Number(e.id))
            if (!ids.includes(Number(selectedUserId))) {
              loadDesk(service, weekOffset, Number(list[0].id))
            }
          }
        })
        .catch(() => {})
    }
  }, [apiUrl, employees.length, isAdmin, loadDesk, selectedUserId, service, weekOffset])

  useEffect(() => {
    if (!aboutOpen) return undefined
    const onDoc = (e) => {
      const root = document.querySelector('.dkpi-about')
      if (root && !root.contains(e.target)) setAboutOpen(false)
    }
    const onKey = (e) => {
      if (e.key === 'Escape') setAboutOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [aboutOpen])

  useEffect(() => {
    if (!modalOpen && !scoreModalOpen) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape') {
        setModalOpen(false)
        setScoreModalOpen(false)
      }
    }
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
    }
  }, [modalOpen, scoreModalOpen])

  useEffect(() => {
    if (!flash || modalOpen || scoreModalOpen) return undefined
    const timer = window.setTimeout(() => setFlash(null), 4000)
    return () => window.clearTimeout(timer)
  }, [flash, modalOpen, scoreModalOpen])

  const openModal = () => {
    setFlash(null)
    setError(null)
    setScoreModalOpen(false)
    setModalOpen(true)
    if (canUseWorkLog) {
      runAnalyze(workLog, { quiet: true })
      apiRequest(apiUrl, { action: 'attach_options' })
        .then((data) => {
          setSystemVouchers(Array.isArray(data.vouchers) ? data.vouchers : [])
          setSystemLetters(Array.isArray(data.letters) ? data.letters : [])
        })
        .catch(() => {
          setSystemVouchers([])
          setSystemLetters([])
        })
    }
  }

  const closeModal = () => {
    setModalOpen(false)
    setFlash(null)
    setError(null)
  }

  const runAnalyze = async (nextWorkLog, { quiet = false } = {}) => {
    setAnalyzing(true)
    if (!quiet) {
      setFlash(null)
      setError(null)
    }
    try {
      const uid = isAdmin ? selectedUserId : viewer.id
      const result = await apiRequest(apiUrl, {
        action: 'analyze',
        method: 'POST',
        body: {
          service,
          week_offset: weekOffset,
          week_start: week?.week_start,
          user_id: uid,
          work_log: nextWorkLog,
        },
      })
      applyAnalysis(result.analysis || null, result.entry || null)
      if (result.metrics) setMetrics(result.metrics)
      if (!quiet) {
        setFlash(result.message || 'Performance calculated from this week\'s work log.')
      }
      return true
    } catch (err) {
      setError(err.message || 'Could not calculate performance')
      return false
    } finally {
      setAnalyzing(false)
    }
  }

  const openScoreModal = () => {
    setFlash(null)
    setError(null)
    setModalOpen(false)
    setAboutOpen(false)
    setScoreModalOpen(true)
    runAnalyze(workLog, { quiet: true })
  }

  const closeScoreModal = () => {
    setScoreModalOpen(false)
    setFlash(null)
    setError(null)
  }

  const onWeekChange = (delta) => {
    setFlash(null)
    setAnalysis(null)
    loadDesk(service, weekOffset + delta, selectedUserId)
  }

  const onUserChange = (uid) => {
    setFlash(null)
    setAnalysis(null)
    loadDesk(service, weekOffset, Number(uid))
  }

  const persistEntry = async (nextWorkLog, { closeForm = false } = {}) => {
    setSaving(true)
    setFlash(null)
    setError(null)
    try {
      const ok = await runAnalyze(nextWorkLog, { quiet: true })
      if (ok) {
        setFlash('Work log saved. Performance updated automatically.')
        await loadDesk(service, weekOffset, selectedUserId)
        if (closeForm) {
          setModalOpen(false)
        }
        return true
      }
      setError('Could not save work log or calculate performance.')
      return false
    } catch (err) {
      setError(err.message || 'Save failed')
      return false
    } finally {
      setSaving(false)
    }
  }

  const addWorkItem = async (e) => {
    e.preventDefault()
    if (!canUseWorkLog) {
      setError('Only accounts with department Driver can use the Work log.')
      return
    }
    const taskDescription = draftTaskDescription.trim()
    if (!taskDescription) {
      setError('Enter a short task description.')
      return
    }
    const uid = isAdmin ? selectedUserId : viewer.id
    setSaving(true)
    setFlash(null)
    setError(null)
    try {
      let attachments = {}
      if (draftUploadFile) {
        const uploaded = await apiUpload(apiUrl, {
          userId: uid,
          service,
          weekOffset,
          file: draftUploadFile,
        })
        attachments = uploaded.attachments || {}
      }

      const selectedVouchers = []
      if (draftVoucherIds.length > 0) {
        draftVoucherIds.forEach((vid) => {
          if (selectedVouchers.some((v) => String(v.id) === String(vid))) return
          const selected = systemVouchers.find((v) => String(v.id) === String(vid))
          if (selected) {
            selectedVouchers.push({
              source: 'system',
              id: String(selected.id),
              name: selected.label || selected.voucher_no || `Voucher #${selected.id}`,
              url: selected.url || '',
            })
          }
        })
      }
      if (draftLetterId) {
        const selected = systemLetters.find((l) => String(l.id) === String(draftLetterId))
        if (selected) {
          attachments.letter = {
            source: 'system',
            id: String(selected.id),
            name: selected.label || selected.title || 'Letter',
            url: selected.url || '',
          }
        }
      }

      const today = new Date()
      const at = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
      const item = {
        id: newWorkId(),
        type: 'vehicle_care',
        label: 'Vehicle Care',
        task_description: taskDescription,
        text: taskDescription,
        at,
      }
      if (selectedVouchers.length > 0) {
        item.vouchers = selectedVouchers
        item.voucher = selectedVouchers[0]
      }
      if (attachments.letter) item.letter = attachments.letter
      if (attachments.document) item.document = attachments.document
      const next = [item, ...workLog]
      setWorkLog(next)
      setDraftTaskDescription('')
      setDraftUploadFile(null)
      setDraftVoucherIds([])
      setDraftLetterId('')
      const uploadInput = document.getElementById('dkpi-upload-file')
      if (uploadInput) uploadInput.value = ''
      await persistEntry(next, { closeForm: true })
    } catch (err) {
      setError(err.message || 'Could not save work log')
      setSaving(false)
    }
  }

  const removeWorkItem = async (id) => {
    const next = workLog.filter((item) => item.id !== id)
    setWorkLog(next)
    await persistEntry(next)
  }

  const logCount = workLog.length
  const entryCount = entries.length
  const driverName = isAdmin
    ? (employees.find((e) => Number(e.id) === Number(selectedUserId))?.full_name || viewer.name)
    : viewer.name

  return (
    <div className={`dkpi-page${loading ? ' is-loading' : ''}`}>
      {!modalOpen && !scoreModalOpen && flash ? (
        <div className="dkpi-flash dkpi-flash--success dkpi-flash--page" role="status">
          {flash}
        </div>
      ) : null}
      {!modalOpen && !scoreModalOpen && error ? (
        <div className="dkpi-flash dkpi-flash--error dkpi-flash--page" role="alert">
          {error}
        </div>
      ) : null}
      <section className="dkpi-dashboard" aria-label="Dashboard">
        <div className="dkpi-dashboard__top">
          <div className="dkpi-dashboard__leading">
            <div className="dkpi-week-nav" aria-label="Week">
              <button type="button" className="dkpi-week-nav__btn" onClick={() => onWeekChange(-1)} aria-label="Previous week">
                <HiOutlineChevronLeft size={18} aria-hidden="true" />
              </button>
              <div className="dkpi-week-nav__center">
                <span className="dkpi-week-nav__icon" aria-hidden="true">
                  <HiOutlineCalendarDays size={16} />
                </span>
                <div className="dkpi-week-nav__text">
                  <span className="dkpi-week-nav__caption">This week</span>
                  <span className="dkpi-week-nav__range">{weekLabel(week)}</span>
                </div>
              </div>
              <button type="button" className="dkpi-week-nav__btn" onClick={() => onWeekChange(1)} aria-label="Next week">
                <HiOutlineChevronRight size={18} aria-hidden="true" />
              </button>
            </div>

            <div className="dkpi-about">
              <button
                type="button"
                className={`dkpi-about__btn${aboutOpen ? ' is-open' : ''}`}
                aria-label="About this session"
                aria-expanded={aboutOpen}
                aria-controls="dkpi-about-panel"
                onClick={() => setAboutOpen((v) => !v)}
              >
                <HiOutlineInformationCircle size={18} aria-hidden="true" />
              </button>
              {aboutOpen ? (
                <div
                  id="dkpi-about-panel"
                  className="dkpi-about__panel"
                  role="dialog"
                  aria-label="About this session"
                >
                  <div className="dkpi-about__panel-head">
                    <strong>About this session</strong>
                    <button type="button" className="dkpi-about__close" onClick={() => setAboutOpen(false)} aria-label="Close">
                      <HiOutlineXMark size={16} />
                    </button>
                  </div>
                  <p className="dkpi-about__lede">
                    Log vehicle care for the selected week: scheduled maintenance progress, daily inspection progress, and work performed. Performance is calculated automatically.
                  </p>
                  <dl className="dkpi-about__meta">
                    <div>
                      <dt>Week</dt>
                      <dd>{weekLabel(week) || '-'}</dd>
                    </div>
                    <div>
                      <dt>Driver</dt>
                      <dd>{driverName}</dd>
                    </div>
                    <div>
                      <dt>Work logged</dt>
                      <dd>{logCount}</dd>
                    </div>
                    <div>
                      <dt>Score</dt>
                      <dd>{scorePreview != null ? fmtPct(scorePreview) : 'Not set'}</dd>
                    </div>
                  </dl>
                </div>
              ) : null}
            </div>
          </div>

          <div className="dkpi-dash-links">
            <button type="button" className="dkpi-btn dkpi-btn--pill-purple" onClick={openModal}>
              <HiOutlinePlus size={16} aria-hidden="true" />
              Work log
            </button>
          </div>
        </div>

        <div className="dkpi-dashboard__stats" role="list">
          <article className="dkpi-kpi-card" role="listitem">
            <div className="dkpi-kpi-card__text">
              <span className="dkpi-kpi-card__label">Work logged</span>
              <strong className="dkpi-kpi-card__value">{logCount}</strong>
            </div>
            <span className="dkpi-kpi-card__badge dkpi-kpi-card__badge--teal" aria-hidden="true">
              <HiOutlineClipboardDocumentList size={18} />
            </span>
          </article>
          <article className="dkpi-kpi-card" role="listitem">
            <div className="dkpi-kpi-card__text">
              <span className="dkpi-kpi-card__label">Drivers this week</span>
              <strong className="dkpi-kpi-card__value">{entryCount}</strong>
            </div>
            <span className="dkpi-kpi-card__badge dkpi-kpi-card__badge--blue" aria-hidden="true">
              <HiOutlineUserGroup size={18} />
            </span>
          </article>
          <article
            className="dkpi-kpi-card dkpi-kpi-card--clickable"
            role="button"
            tabIndex={0}
            onClick={openScoreModal}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                openScoreModal()
              }
            }}
            aria-label="Open performance score"
          >
            <div className="dkpi-kpi-card__text">
              <span className="dkpi-kpi-card__label">Score</span>
              <strong className="dkpi-kpi-card__value">{scorePreview != null ? fmtPct(scorePreview) : '0.0%'}</strong>
            </div>
            <span className="dkpi-kpi-card__badge dkpi-kpi-card__badge--amber" aria-hidden="true">
              <HiOutlineChartBar size={18} />
            </span>
          </article>
        </div>
      </section>

      <section className="dkpi-week-log" aria-label="This week recordings">
        <div className="dkpi-week-log__head">
          <h2>{isAdmin ? 'Drivers this week' : 'This week'}</h2>
          <span>
            {isAdmin
              ? `${entryCount} driver${entryCount === 1 ? '' : 's'}`
              : `${logCount} logged`}
          </span>
        </div>

        {isAdmin ? (
          entries.length > 0 ? (
            <EntriesTable
              entries={entries}
              selectedUserId={selectedUserId}
              onSelect={(uid) => onUserChange(uid)}
            />
          ) : (
            <p className="dkpi-empty">No driver recordings for this week yet.</p>
          )
        ) : null}

        {isAdmin && entries.length > 0 ? (
          <div className="dkpi-week-log__subhead">
            <h3>Work log — {driverName}</h3>
            <span>{logCount} item{logCount === 1 ? '' : 's'}</span>
          </div>
        ) : null}

        {workLog.length > 0 ? (
          <WorkLogTable items={workLog} onRemove={canUseWorkLog ? removeWorkItem : null} />
        ) : (
          <p className="dkpi-empty">
            {canUseWorkLog
              ? `No work logged yet for ${driverName}. Click Work log to add.`
              : `No work logged yet for ${driverName}.`}
          </p>
        )}
      </section>

      {modalOpen ? (
        <div className="dkpi-modal" role="dialog" aria-modal="true" aria-labelledby="dkpi-modal-title">
          <button type="button" className="dkpi-modal__backdrop" aria-label="Close" onClick={closeModal} />
          <div className="dkpi-modal__panel">
            <div className="dkpi-modal__head">
              <div className="dkpi-modal__title-row">
                <h2 id="dkpi-modal-title">Work log</h2>
              </div>
              <button type="button" className="dkpi-modal__close" onClick={closeModal} aria-label="Close">
                <HiOutlineXMark size={18} />
              </button>
            </div>

            <div className="dkpi-modal__body">
              {flash ? <div className="dkpi-flash dkpi-flash--success">{flash}</div> : null}
              {error ? <div className="dkpi-flash dkpi-flash--error">{error}</div> : null}

              {!canUseWorkLog ? (
                <div className="dkpi-flash dkpi-flash--error">
                  Only accounts with department Driver can use the Work log.
                </div>
              ) : null}

              {canUseWorkLog && isAdmin ? (
                <label className="dkpi-field">
                  <span>Driver</span>
                  <select
                    value={selectedUserId || ''}
                    onChange={(e) => onUserChange(e.target.value)}
                    required
                  >
                    {employees.length === 0 ? (
                      <option value="">No drivers found</option>
                    ) : (
                      employees.map((emp) => (
                        <option key={emp.id} value={emp.id}>
                          {emp.full_name}
                        </option>
                      ))
                    )}
                  </select>
                </label>
              ) : null}

              {canUseWorkLog && !isAdmin ? (
                <p className="dkpi-self">
                  Recording for <strong>{viewer.name}</strong>
                </p>
              ) : null}

              {canUseWorkLog && isAdmin && employees.length === 0 ? (
                <p className="dkpi-score-summary">
                  No active users with department Driver. Set the employee department to Driver first.
                </p>
              ) : null}

              {canUseWorkLog && (!isAdmin || employees.length > 0) ? (
              <form className="dkpi-work-form" onSubmit={addWorkItem}>
                <section className="dkpi-work-section" aria-labelledby="dkpi-vehicle-care-title">
                  <h3 id="dkpi-vehicle-care-title" className="dkpi-work-section__title">
                    Vehicle Care
                  </h3>

                  <label className="dkpi-field">
                    <span>Task Description</span>
                    <textarea
                      rows={3}
                      value={draftTaskDescription}
                      onChange={(e) => setDraftTaskDescription(e.target.value)}
                      placeholder={VEHICLE_CARE_TASK_HINT}
                      maxLength={500}
                      required
                    />
                  </label>

                  <div className="dkpi-attach-grid">
                    <div className="dkpi-attach-block">
                      <SearchableSelect
                        label="Payment voucher"
                        multiple
                        value={draftVoucherIds}
                        options={systemVouchers}
                        placeholder="Select one or more vouchers"
                        searchPlaceholder="Search voucher no. or payee..."
                        emptyText="No vouchers match your search"
                        onChange={(ids) => {
                          setDraftVoucherIds(Array.isArray(ids) ? ids.map(String) : [])
                        }}
                      />
                    </div>
                    <div className="dkpi-attach-block">
                      <SearchableSelect
                        label="Letter"
                        value={draftLetterId}
                        options={systemLetters}
                        placeholder="Select from system"
                        searchPlaceholder="Search letter title..."
                        emptyText="No letters match your search"
                        onChange={(id) => {
                          setDraftLetterId(id)
                        }}
                      />
                    </div>
                  </div>

                  <label className="dkpi-field dkpi-field--file">
                    <span>Or upload file</span>
                    <input
                      id="dkpi-upload-file"
                      type="file"
                      accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,application/pdf,image/*"
                      onChange={(e) => setDraftUploadFile(e.target.files?.[0] || null)}
                    />
                    <small>
                      {draftUploadFile ? draftUploadFile.name : 'PDF, image, or Word - optional'}
                    </small>
                  </label>
                </section>

                <div className="dkpi-form-actions">
                  <button type="submit" className="dkpi-btn dkpi-btn--pill-purple" disabled={saving || analyzing}>
                    {saving || analyzing ? 'Saving...' : 'Add to This Week'}
                  </button>
                </div>
              </form>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}

      {scoreModalOpen ? (
        <div className="dkpi-modal" role="dialog" aria-modal="true" aria-labelledby="dkpi-score-title">
          <button type="button" className="dkpi-modal__backdrop" aria-label="Close" onClick={closeScoreModal} />
          <div className="dkpi-modal__panel dkpi-modal__panel--score">
            <div className="dkpi-modal__head">
              <div className="dkpi-modal__title-row">
                <h2 id="dkpi-score-title">Performance</h2>
                <span className="dkpi-score-pill">{scorePreview != null ? fmtPct(scorePreview) : '0.0%'}</span>
              </div>
              <button type="button" className="dkpi-modal__close" onClick={closeScoreModal} aria-label="Close">
                <HiOutlineXMark size={18} />
              </button>
            </div>

            <div className="dkpi-modal__body">
              {flash ? <div className="dkpi-flash dkpi-flash--success">{flash}</div> : null}
              {error ? <div className="dkpi-flash dkpi-flash--error">{error}</div> : null}

              <p className="dkpi-score-summary">
                {driverName} - {weekLabel(week) || 'This week'}
                {analyzing ? ' - Calculating...' : ''}
              </p>

              <div className="dkpi-perf-list">
                {metrics.map((m) => {
                  const field = m.column
                  const actual = scores[field]
                  const actualNum = actual === '' || actual == null ? null : Number(actual)
                  return (
                    <div className="dkpi-perf-row" key={m.key || field}>
                      <div className="dkpi-perf-row__main">
                        <strong>{m.label}</strong>
                        <span>
                          Target {fmtTarget(m.target)}% - Weight {fmtTarget(m.weight)}%
                        </span>
                      </div>
                      <strong className="dkpi-perf-row__value">
                        {actualNum != null && Number.isFinite(actualNum) ? fmtPct(actualNum) : '-'}
                      </strong>
                    </div>
                  )
                })}
              </div>

              {analysis?.message || analysis?.congratulations ? (
                <div
                  className={`dkpi-insight${
                    analysis.tone === 'congrats'
                      ? ' dkpi-insight--congrats'
                      : analysis.tone === 'empty'
                        ? ' dkpi-insight--empty'
                        : ''
                  }`}
                >
                  <h3>{analysis.headline || (analysis.tone === 'congrats' ? 'Congratulations' : 'Status')}</h3>
                  <p>{analysis.message || analysis.congratulations}</p>
                </div>
              ) : null}

              {Array.isArray(analysis?.calculation) && analysis.calculation.length > 0 ? (
                <details className="dkpi-insight dkpi-insight--dropdown">
                  <summary>How it is calculated</summary>
                  <ol className="dkpi-insight__list">
                    {analysis.calculation.map((line, idx) => (
                      <li key={`calc-${idx}`}>{line}</li>
                    ))}
                  </ol>
                </details>
              ) : null}

              {Array.isArray(analysis?.suggestions) && analysis.suggestions.length > 0 ? (
                <details className="dkpi-insight dkpi-insight--dropdown">
                  <summary>Suggestions to improve</summary>
                  <ul className="dkpi-insight__list">
                    {analysis.suggestions.map((line, idx) => (
                      <li key={`tip-${idx}`}>{line}</li>
                    ))}
                  </ul>
                </details>
              ) : null}

              {!analyzing && !analysis ? (
                <p className="dkpi-score-summary">Open this panel again if scores did not load.</p>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
