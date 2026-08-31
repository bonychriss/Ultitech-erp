import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  ClipboardList, Search, Loader2, CheckCircle2, AlertCircle,
  FileText, CalendarDays, Users, Plus, Sparkles, X,
} from 'lucide-react'
import { CFG } from '../config.js'
import { aiSearchDeliveryNotes } from '../api/deliveryNotes.js'
import DeliveryKpiTraceModal from '../components/DeliveryKpiTraceModal.jsx'
import { resolveKpiTrace } from '../utils/kpiTrace.js'

function formatDate(value) {
  if (!value) return '-'
  const d = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return '-'
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  return `${dd}/${mm}/${d.getFullYear()}`
}

function formatTime(value) {
  if (!value) return ''
  const d = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return ''
  const hh = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  return `${hh}:${min}`
}

function noteDateKey(value) {
  if (!value) return ''
  return String(value).slice(0, 10)
}

function noteViewUrl(urls, noteId) {
  const base = urls.viewNote || 'view_delivery_note.php?module=deliveries'
  const join = base.includes('?') ? '&' : '?'
  return `${base}${join}id=${noteId}`
}

export default function DeliveryNotesPage() {
  const initial = CFG.data || {}
  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial.notes)
  const [flash, setFlash] = useState(initial.flash || null)
  const [searchInput, setSearchInput] = useState('')
  const [searchOpen, setSearchOpen] = useState(false)
  const [aiFilters, setAiFilters] = useState({ search: '', creator: '', from_date: '', to_date: '', min_items: 0 })
  const [aiLoading, setAiLoading] = useState(false)
  const [aiNote, setAiNote] = useState('')
  const [activeKpiTrace, setActiveKpiTrace] = useState(null)
  const [activeKpiTraceKey, setActiveKpiTraceKey] = useState('')
  const searchInputRef = useRef(null)
  const mobileSearchInputRef = useRef(null)
  const searchExpandRef = useRef(null)
  const [headerSearchMount, setHeaderSearchMount] = useState(null)

  const urls = data.urls || {}
  const notes = data.notes || []
  const stats = data.stats || {}

  const kpis = [
    {
      key: 'total',
      label: 'Total notes',
      value: Number(stats.total || 0).toLocaleString(),
      sub: 'All delivery notes',
      color: 'blue',
      icon: <FileText size={17} aria-hidden="true" />,
    },
    {
      key: 'thisMonth',
      label: 'This month',
      value: Number(stats.thisMonth || 0).toLocaleString(),
      sub: 'Created this month',
      color: 'amber',
      icon: <CalendarDays size={17} aria-hidden="true" />,
    },
    {
      key: 'thisWeek',
      label: 'This week',
      value: Number(stats.thisWeek || 0).toLocaleString(),
      sub: 'Created last 7 days',
      color: 'blue',
      icon: <ClipboardList size={17} aria-hidden="true" />,
    },
    {
      key: 'customers',
      label: 'Customers',
      value: Number(stats.customers || 0).toLocaleString(),
      sub: 'Unique customers',
      color: 'green',
      icon: <Users size={17} aria-hidden="true" />,
    },
  ]

  function openKpiTrace(key) {
    const trace = resolveKpiTrace(key, data)
    if (!trace) return
    setActiveKpiTraceKey(key)
    setActiveKpiTrace(trace)
  }

  useEffect(() => {
    if (initial.notes) return undefined
    if (!CFG.deliveryNotesInitUrl) {
      setLoading(false)
      return undefined
    }
    let alive = true
    ;(async () => {
      try {
        const qs = window.location.search || ''
        const res = await fetch(`${CFG.deliveryNotesInitUrl}${qs}`, { headers: { Accept: 'application/json' } })
        const payload = await res.json()
        if (alive && payload?.ok && payload.data) {
          setData(payload.data)
          if (payload.data.flash) setFlash(payload.data.flash)
        }
      } catch {
        if (alive) setFlash({ type: 'error', message: 'Could not load delivery notes.' })
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => { alive = false }
  }, [initial.notes])

  useEffect(() => {
    setHeaderSearchMount(document.getElementById('dlv-header-search-mount'))
  }, [])

  useEffect(() => {
    if (searchInput.trim() !== '') setSearchOpen(true)
  }, [searchInput])

  useEffect(() => {
    if (!searchOpen) return undefined

    function handlePointerDown(event) {
      if (!searchExpandRef.current?.contains(event.target) && searchInput.trim() === '') {
        setSearchOpen(false)
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') setSearchOpen(false)
    }

    const focusTimer = window.setTimeout(() => {
      mobileSearchInputRef.current?.focus()
    }, 180)

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      window.clearTimeout(focusTimer)
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [searchOpen, searchInput])

  function toggleMobileSearch() {
    setSearchOpen((open) => !open)
  }

  function handleSearchChange(value) {
    setSearchInput(value)
    if (aiFilters.search || aiFilters.creator || aiFilters.from_date || aiFilters.to_date || aiFilters.min_items) {
      setAiFilters({ search: '', creator: '', from_date: '', to_date: '', min_items: 0 })
      setAiNote('')
    }
  }

  async function runAiSearch() {
    const query = searchInput.trim()
    if (query === '' || aiLoading) return
    setAiLoading(true)
    setAiNote('')
    try {
      const result = await aiSearchDeliveryNotes(query)
      const f = result.filters || {}
      setSearchInput(f.search || query)
      setAiFilters({
        search: f.search || '',
        creator: f.creator || '',
        from_date: f.from_date || '',
        to_date: f.to_date || '',
        min_items: Number(f.min_items) || 0,
      })
      setAiNote(result.note || 'Applied AI filters.')
      setSearchOpen(true)
    } catch (err) {
      setAiFilters({ search: '', creator: '', from_date: '', to_date: '', min_items: 0 })
      setSearchInput(query)
      setAiNote(err instanceof Error ? err.message : 'AI search failed. Showing text matches instead.')
    } finally {
      setAiLoading(false)
    }
  }

  function renderSearchField(inputRef, id = 'dlv-notes-search') {
    return (
      <div className="dlv-search-field dlv-search-field--with-ai">
        <Search className="dlv-search-icon" size={15} aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="search"
          className="dlv-search-input"
          placeholder="Search note, customer, destination..."
          value={searchInput}
          autoComplete="off"
          onChange={(e) => handleSearchChange(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              runAiSearch()
            }
          }}
          aria-label="Search delivery notes"
        />
        <button
          type="button"
          className={`dlv-ai-btn${aiLoading ? ' is-loading' : ''}`}
          onClick={runAiSearch}
          disabled={aiLoading}
          title="Ask AI to find notes (e.g. notes for Mbagala last month)"
          aria-label="AI assisted search"
        >
          {aiLoading ? <Loader2 size={15} className="dlv-spin" aria-hidden="true" /> : <Sparkles size={15} aria-hidden="true" />}
        </button>
      </div>
    )
  }

  const hasSearchValue = searchInput.trim() !== ''
  const desktopSearchField = renderSearchField(searchInputRef, 'dlv-notes-search-desktop')

  const filtered = useMemo(() => {
    const q = searchInput.trim().toLowerCase()
    return notes.filter((note) => {
      const dateKey = noteDateKey(note.delivery_date || note.created_at)
      if (aiFilters.from_date && dateKey && dateKey < aiFilters.from_date) return false
      if (aiFilters.to_date && dateKey && dateKey > aiFilters.to_date) return false
      if (aiFilters.min_items > 0 && Number(note.item_count || 0) < aiFilters.min_items) return false
      if (aiFilters.creator) {
        const creator = String(note.creator_name || '').toLowerCase()
        if (!creator.includes(aiFilters.creator.toLowerCase())) return false
      }

      const aiSearch = aiFilters.search.trim().toLowerCase()
      const activeQuery = aiSearch || q
      if (!activeQuery) return true

      const text = [
        note.id,
        note.note_number,
        note.customer_name,
        note.customer_phone,
        note.delivery_address,
        note.creator_name,
        note.delivery_date,
      ].join(' ').toLowerCase()
      return activeQuery.split(/\s+/).every((term) => text.includes(term))
    })
  }, [notes, searchInput, aiFilters])

  if (loading && !data.notes) {
    return (
      <div className="dlv-page">
        <div className="dlv-loading" role="status">
          <Loader2 className="dlv-spin" aria-hidden="true" />
          <span>Loading delivery notes...</span>
        </div>
      </div>
    )
  }

  return (
    <div className="dlv-page">
      {activeKpiTrace && (
        <DeliveryKpiTraceModal
          trace={activeKpiTrace}
          traceKey={activeKpiTraceKey}
          onClose={() => {
            setActiveKpiTrace(null)
            setActiveKpiTraceKey('')
          }}
        />
      )}

      {flash && (
        <div className={`dlv-flash dlv-flash--${flash.type === 'success' ? 'ok' : 'err'}`} role="alert">
          {flash.type === 'success' ? <CheckCircle2 size={18} aria-hidden="true" /> : <AlertCircle size={18} aria-hidden="true" />}
          <span>{flash.message}</span>
        </div>
      )}

      {headerSearchMount ? createPortal(desktopSearchField, headerSearchMount) : null}

      {aiNote && (
        <div className="dlv-ai-note" role="status">
          <Sparkles size={12} aria-hidden="true" />
          <span>{aiNote}</span>
          <button type="button" className="dlv-ai-note-close" onClick={() => setAiNote('')} aria-label="Dismiss">
            <X size={12} aria-hidden="true" />
          </button>
        </div>
      )}

      <div className="dlv-page-header">
        <div className="dlv-page-header-search dlv-page-header-search--desktop">
          {!headerSearchMount ? desktopSearchField : null}
        </div>

        <div className="dlv-page-header-actions">
          <div className="dlv-toolbar-secondary">
            <div
              className={`dlv-search-expand${searchOpen ? ' is-open' : ''}`}
              ref={searchExpandRef}
            >
              <button
                type="button"
                className={`dlv-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
                onClick={toggleMobileSearch}
                aria-expanded={searchOpen}
                aria-controls="dlv-notes-search-mobile-panel"
                title="Search delivery notes"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div
                id="dlv-notes-search-mobile-panel"
                className={`dlv-search-panel${searchOpen ? ' is-open' : ''}`}
              >
                {renderSearchField(mobileSearchInputRef, 'dlv-notes-search-mobile')}
              </div>
            </div>
            <a
              href={urls.createNote || 'create_delivery_note.php'}
              className="dlv-btn dlv-btn--primary dlv-btn--create"
              aria-label="Create delivery note"
            >
              <Plus size={18} aria-hidden="true" />
              <span className="dlv-btn-label-desktop">New Note</span>
              <span className="dlv-btn-label-mobile">New</span>
            </a>
          </div>
        </div>
      </div>

      <div className="dlv-sticky-top">
        <div className="dlv-kpis dlv-kpis--4">
          {kpis.map((k) => (
            <button
              key={k.key}
              type="button"
              className="dlv-kpi-card dlv-kpi--clickable"
              onClick={() => openKpiTrace(k.key)}
              aria-label={`View details for ${k.label}`}
              title="Click to see contributing records"
            >
              <div className="dlv-kpi-text">
                <span className="dlv-kpi-label">{k.label}</span>
                <div className="dlv-kpi-value">{k.value}</div>
                <div className="dlv-kpi-sub">{k.sub}</div>
              </div>
              <span className={`dlv-kpi-badge dlv-badge--${k.color}`}>{k.icon}</span>
            </button>
          ))}
        </div>
      </div>

      <div className="dlv-card">
        <div className="dlv-card-head">
          <h3 className="dlv-card-title">
            <ClipboardList size={18} aria-hidden="true" />
            Delivery Notes
          </h3>
          <span className="dlv-card-meta">{filtered.length} record{filtered.length === 1 ? '' : 's'}</span>
        </div>

        {filtered.length === 0 ? (
          <div className="dlv-empty">
            <ClipboardList size={32} aria-hidden="true" />
            <p>No delivery notes found.</p>
            <a href={urls.createNote || 'create_delivery_note.php'} className="dlv-btn dlv-btn--primary">Create a note</a>
          </div>
        ) : (
          <div className="dlv-table-wrap">
            <table className="dlv-table dlv-table--full">
              <thead>
                <tr>
                  <th>S/N</th>
                  <th>Date</th>
                  <th>Note #</th>
                  <th>Created By</th>
                  <th>Customer</th>
                  <th>Destination</th>
                  <th>Items</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((note, idx) => {
                  const viewUrl = noteViewUrl(urls, note.id)
                  return (
                    <tr
                      key={note.id}
                      className="dlv-row"
                      onClick={() => { window.open(viewUrl, '_blank', 'noopener,noreferrer') }}
                    >
                      <td className="dlv-sn">{idx + 1}</td>
                      <td className="dlv-muted">
                        {formatDate(note.delivery_date || note.created_at)}
                        {note.created_at ? (
                          <>
                            <br /><small>{formatTime(note.created_at)}</small>
                          </>
                        ) : null}
                      </td>
                      <td className="dlv-ref">{note.note_number || '-'}</td>
                      <td>{note.creator_name || '-'}</td>
                      <td>
                        {note.customer_name || '-'}
                        {note.customer_phone ? (
                          <>
                            <br />
                            <small className="dlv-muted">{note.customer_phone}</small>
                          </>
                        ) : null}
                      </td>
                      <td>{note.delivery_address || '-'}</td>
                      <td>{note.item_count} item{note.item_count === 1 ? '' : 's'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
