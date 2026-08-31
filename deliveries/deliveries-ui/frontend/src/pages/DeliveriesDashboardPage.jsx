import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  Truck, List, Plus, Search, SlidersHorizontal, X,
  Loader2, CheckCircle2, AlertCircle, Clock, AlertTriangle, Sparkles,
} from 'lucide-react'
import { CFG } from '../config.js'
import { aiSearchDeliveries } from '../api/myDeliveries.js'
import DeliveryKpiTraceModal from '../components/DeliveryKpiTraceModal.jsx'
import { resolveKpiTrace } from '../utils/kpiTrace.js'
import { resolveDeliveryType, typePill } from '../utils/deliveryType.js'

const URLS = CFG.data?.urls || {}

const STATUS_GROUPS = {
  pending: ['request_pending', 'accepted', 'pending'],
  in_transit: ['in_transit', 'loading'],
  delivered: ['delivered', 'completed'],
  exception: ['returned', 'failed', 'rejected'],
}

function formatDate(value) {
  if (!value) return '-'
  const d = new Date(value.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return '-'
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  return `${dd}/${mm}/${d.getFullYear()}`
}

function formatTime(value) {
  if (!value) return ''
  const d = new Date(value.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return ''
  const hh = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  return `${hh}:${min}`
}

function dateOnly(dateStr) {
  if (!dateStr) return ''
  return String(dateStr).slice(0, 10)
}

function orderText(order) {
  return [
    order.id,
    order.client_name,
    order.client_phone,
    order.delivery_address,
    order.invoice_ref,
    order.delivery_type,
    order.delivery_number,
    order.status,
    order.description,
    order.receipt_name,
  ].join(' ').toLowerCase()
}

function matchesSearch(order, query) {
  const q = query.trim().toLowerCase()
  if (q === '') return true
  const text = orderText(order)
  return q.split(/\s+/).every((term) => text.includes(term))
}

function matchesStatus(order, status) {
  if (!status || status === 'all') return true
  const normalized = String(order.status || '').toLowerCase()
  const filter = String(status).toLowerCase()
  const group = STATUS_GROUPS[filter]
  if (group) return group.includes(normalized)
  return normalized === filter
}

function matchesDate(order, from, to, month) {
  const d = dateOnly(order.created_at)
  if (from && d < from) return false
  if (to && d > to) return false
  if (month && month !== 'all') {
    const orderMonth = d.slice(5, 7)
    if (orderMonth !== month) return false
  }
  return true
}

function statusPill(status) {
  const s = String(status || '').toLowerCase()
  if (s === 'delivered' || s === 'completed') return { label: 'Delivered', cls: 'dlv-vbadge dlv-vbadge--completed' }
  if (s === 'in_transit' || s === 'loading') return { label: s.replace(/_/g, ' '), cls: 'dlv-vbadge dlv-vbadge--transit' }
  if (s === 'accepted' || s === 'pending') return { label: s.replace(/_/g, ' '), cls: 'dlv-vbadge dlv-vbadge--planned' }
  if (s === 'request_pending') return { label: 'Pending', cls: 'dlv-vbadge dlv-vbadge--loading' }
  if (s === 'rejected' || s === 'failed' || s === 'returned') return { label: s.replace(/_/g, ' '), cls: 'dlv-vbadge dlv-vbadge--default' }
  return { label: s ? s.replace(/_/g, ' ') : 'Unknown', cls: 'dlv-vbadge dlv-vbadge--default' }
}

const EMPTY_FILTERS = { status: 'all', from_date: '', to_date: '', month: 'all' }

const MONTH_LABELS = {
  '01': 'January', '02': 'February', '03': 'March', '04': 'April',
  '05': 'May', '06': 'June', '07': 'July', '08': 'August',
  '09': 'September', '10': 'October', '11': 'November', '12': 'December',
}

function sameId(a, b) {
  return Number(a) > 0 && Number(a) === Number(b)
}

function hasAdvancedFilters(f) {
  if (f.status && f.status !== 'all') return true
  if (f.from_date) return true
  if (f.to_date) return true
  if (f.month && f.month !== 'all') return true
  return false
}

function readListStateFromUrl() {
  if (typeof window === 'undefined') {
    return { search: '', selectedId: 0, filters: { ...EMPTY_FILTERS } }
  }
  const p = new URLSearchParams(window.location.search)
  return {
    search: p.get('q') || '',
    selectedId: Number(p.get('sel') || 0) || 0,
    filters: {
      status: p.get('status') || 'all',
      from_date: p.get('from_date') || '',
      to_date: p.get('to_date') || '',
      month: p.get('month') || 'all',
    },
  }
}

function writeListStateToUrl(search, filters, selectedId) {
  if (typeof window === 'undefined' || !window.history || typeof window.history.replaceState !== 'function') return
  const url = new URL(window.location.href)
  const setOrDel = (key, value) => {
    const v = String(value || '').trim()
    if (v && v !== 'all') url.searchParams.set(key, v)
    else url.searchParams.delete(key)
  }
  setOrDel('q', search)
  setOrDel('status', filters && filters.status)
  setOrDel('from_date', filters && filters.from_date)
  setOrDel('to_date', filters && filters.to_date)
  setOrDel('month', filters && filters.month)
  setOrDel('sel', selectedId ? String(selectedId) : '')
  const next = url.pathname + url.search + url.hash
  const cur = window.location.pathname + window.location.search + window.location.hash
  if (next !== cur) {
    window.history.replaceState(window.history.state, '', next)
  }
}

let consumedRestore
function takeRestoredListState() {
  if (consumedRestore !== undefined) return consumedRestore
  consumedRestore = null
  if (typeof window === 'undefined' || !window.erpNavBack || typeof window.erpNavBack.consumeRestore !== 'function') {
    return null
  }
  const restored = window.erpNavBack.consumeRestore()
  consumedRestore = restored && restored.state ? restored.state : null
  return consumedRestore
}

export default function DeliveriesDashboardPage() {
  const initial = CFG.data || {}
  const restoredList = takeRestoredListState()
  const urlList = readListStateFromUrl()
  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial.stats)
  const [flash, setFlash] = useState(initial.flash || null)
  const [toast, setToast] = useState(null)
  const [searchInput, setSearchInput] = useState(() => (
    restoredList && typeof restoredList.search === 'string' ? restoredList.search : urlList.search
  ))
  const [aiLoading, setAiLoading] = useState(false)
  const [aiNote, setAiNote] = useState(() => (
    restoredList && typeof restoredList.note === 'string' ? restoredList.note : ''
  ))
  const [activeKpiTrace, setActiveKpiTrace] = useState(null)
  const [filters, setFilters] = useState(() => (
    restoredList && restoredList.filters
      ? { ...EMPTY_FILTERS, ...restoredList.filters }
      : { ...EMPTY_FILTERS, ...urlList.filters }
  ))
  const [draftFilters, setDraftFilters] = useState(() => (
    restoredList && restoredList.filters
      ? { ...EMPTY_FILTERS, ...restoredList.filters }
      : { ...EMPTY_FILTERS, ...urlList.filters }
  ))
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [filterPanelStyle, setFilterPanelStyle] = useState(null)
  const [suggestOpen, setSuggestOpen] = useState(false)
  const [highlightedId, setHighlightedId] = useState(() => {
    const fromRestore = restoredList && restoredList.selectedId
    const fromUrl = urlList.selectedId
    let fromStore = 0
    try {
      fromStore = Number(sessionStorage.getItem('dlv.lastSelectedId') || 0) || 0
    } catch {
      fromStore = 0
    }
    return Number(fromRestore || fromUrl || fromStore || 0) || 0
  })
  const [searchOpen, setSearchOpen] = useState(false)
  const [headerSearchMount, setHeaderSearchMount] = useState(null)
  const toastTimer = useRef(null)
  const searchWrapRef = useRef(null)
  const searchInputRef = useRef(null)
  const mobileSearchInputRef = useRef(null)
  const searchExpandRef = useRef(null)
  const filterDropdownRef = useRef(null)
  const filterBtnRef = useRef(null)
  const filterPanelRef = useRef(null)

  const showToast = useCallback((type, text) => {
    setToast({ type, text })
    if (toastTimer.current) window.clearTimeout(toastTimer.current)
    toastTimer.current = window.setTimeout(() => setToast(null), 3200)
  }, [])

  const loadDashboard = useCallback(async () => {
    if (!CFG.apiUrl) return
    setLoading(true)
    try {
      const res = await fetch(CFG.apiUrl, { headers: { Accept: 'application/json' } })
      const payload = await res.json()
      if (payload?.ok && payload.data) {
        setData(payload.data)
        if (payload.data.flash) setFlash(payload.data.flash)
      }
    } catch {
      showToast('err', 'Could not refresh dashboard.')
    } finally {
      setLoading(false)
    }
  }, [showToast])

  useEffect(() => {
    if (!initial.stats) loadDashboard()
  }, [initial.stats, loadDashboard])

  useEffect(() => {
    if (flash) {
      const t = window.setTimeout(() => setFlash(null), 5000)
      return () => window.clearTimeout(t)
    }
    return undefined
  }, [flash])

  useEffect(() => {
    setHeaderSearchMount(document.getElementById('dlv-header-search-mount'))
  }, [])

  useEffect(() => {
    writeListStateToUrl(searchInput, filters, highlightedId)
  }, [searchInput, filters, highlightedId])

  useEffect(() => {
    if (!highlightedId) return undefined
    const timer = window.setTimeout(() => {
      const el = document.querySelector(`[data-delivery-id="${highlightedId}"]`)
      if (el && typeof el.scrollIntoView === 'function') {
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
      }
    }, 80)
    return () => window.clearTimeout(timer)
  }, [highlightedId, data])

  useEffect(() => {
    function syncSelected() {
      const fromUrl = readListStateFromUrl().selectedId
      let fromStore = 0
      try {
        fromStore = Number(sessionStorage.getItem('dlv.lastSelectedId') || 0) || 0
      } catch {
        fromStore = 0
      }
      const next = Number(fromUrl || fromStore || 0) || 0
      if (next) setHighlightedId(next)
    }
    window.addEventListener('pageshow', syncSelected)
    syncSelected()
    return () => window.removeEventListener('pageshow', syncSelected)
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

  useEffect(() => {
    if (!suggestOpen) return undefined
    function onClick(e) {
      if (!searchWrapRef.current?.contains(e.target)) setSuggestOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [suggestOpen])

  useEffect(() => {
    if (!filtersOpen) return undefined
    function onPointerDown(e) {
      if (filterDropdownRef.current?.contains(e.target)) return
      if (filterPanelRef.current?.contains(e.target)) return
      setFiltersOpen(false)
    }
    function onKeyDown(e) {
      if (e.key === 'Escape') setFiltersOpen(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [filtersOpen])

  useLayoutEffect(() => {
    if (!filtersOpen) {
      setFilterPanelStyle(null)
      return undefined
    }
    function syncPosition() {
      const btn = filterBtnRef.current
      if (!btn) return
      const margin = 12
      const rect = btn.getBoundingClientRect()
      const top = Math.round(rect.bottom + 6)
      const isMobile = window.matchMedia('(max-width: 767.98px)').matches
      const maxHeight = Math.max(220, window.innerHeight - top - margin)
      if (isMobile) {
        setFilterPanelStyle({ top: `${top}px`, left: `${margin}px`, right: `${margin}px`, width: 'auto', maxHeight: `${maxHeight}px` })
        return
      }
      const panelWidth = Math.min(384, window.innerWidth - margin * 2)
      let left = rect.right - panelWidth
      left = Math.max(margin, Math.min(left, window.innerWidth - panelWidth - margin))
      setFilterPanelStyle({ top: `${top}px`, left: `${left}px`, width: `${panelWidth}px`, maxHeight: `${maxHeight}px` })
    }
    syncPosition()
    window.addEventListener('resize', syncPosition)
    window.addEventListener('scroll', syncPosition, true)
    return () => {
      window.removeEventListener('resize', syncPosition)
      window.removeEventListener('scroll', syncPosition, true)
    }
  }, [filtersOpen])

  const stats = data.stats || { activeTrips: 0, pending: 0, exceptions: 0 }
  const allOrders = useMemo(
    () => (data.orders || []).map((order) => ({
      ...order,
      delivery_type: resolveDeliveryType(order),
    })),
    [data.orders],
  )
  const urls = data.urls || URLS

  const filteredOrders = useMemo(
    () => allOrders.filter(
      (o) => matchesSearch(o, searchInput)
        && matchesStatus(o, filters.status)
        && matchesDate(o, filters.from_date, filters.to_date, filters.month),
    ),
    [allOrders, searchInput, filters],
  )

  const suggestions = useMemo(() => filteredOrders.slice(0, 6), [filteredOrders])

  const activeFilterChips = useMemo(() => {
    const chips = []
    if (filters.status && filters.status !== 'all') {
      chips.push({ key: 'status', label: `Status: ${statusPill(filters.status).label}` })
    }
    if (filters.month && filters.month !== 'all') {
      chips.push({ key: 'month', label: `Month: ${MONTH_LABELS[filters.month] || filters.month}` })
    }
    if (filters.from_date || filters.to_date) {
      const from = filters.from_date || 'Any'
      const to = filters.to_date || 'Any'
      chips.push({ key: 'date', label: `Date: ${from} - ${to}` })
    }
    return chips
  }, [filters])

  function clearFilterChip(key) {
    if (key === 'date') {
      setFilters((f) => ({ ...f, from_date: '', to_date: '' }))
      setDraftFilters((f) => ({ ...f, from_date: '', to_date: '' }))
      return
    }
    setFilters((f) => ({ ...f, [key]: 'all' }))
    setDraftFilters((f) => ({ ...f, [key]: 'all' }))
  }

  function resetDraftFilters() {
    setDraftFilters(EMPTY_FILTERS)
  }

  function renderFiltersPanel() {
    if (!filtersOpen || !filterPanelStyle) return null
    const panel = (
      <div
        ref={filterPanelRef}
        className="dlv-filters-panel"
        role="dialog"
        aria-label="Filter options"
        style={filterPanelStyle}
      >
        <div className="dlv-filters-head">
          <div>
            <h2 className="dlv-filters-title">Filters</h2>
            <p className="dlv-filters-sub">Narrow deliveries by status, month, and date range.</p>
          </div>
          <button type="button" className="dlv-filters-close" onClick={() => setFiltersOpen(false)} aria-label="Close filters">
            <X size={16} aria-hidden="true" />
          </button>
        </div>
        <div className="dlv-filters-section">
          <div className="dlv-filters-grid">
            <div className="dlv-field">
              <label htmlFor="dlv-filter-status">Status</label>
              <select
                id="dlv-filter-status"
                value={draftFilters.status}
                onChange={(e) => setDraftFilters((f) => ({ ...f, status: e.target.value }))}
              >
                <option value="all">All statuses</option>
                <option value="pending">Pending</option>
                <option value="in_transit">In transit</option>
                <option value="delivered">Delivered</option>
                <option value="exception">Exceptions</option>
                <option value="request_pending">Request pending</option>
                <option value="accepted">Accepted</option>
                <option value="loading">Loading</option>
                <option value="returned">Returned</option>
                <option value="failed">Failed</option>
              </select>
            </div>
            <div className="dlv-field">
              <label htmlFor="dlv-filter-month">Month</label>
              <select
                id="dlv-filter-month"
                value={draftFilters.month}
                onChange={(e) => setDraftFilters((f) => ({ ...f, month: e.target.value }))}
              >
                <option value="all">All months</option>
                {Object.entries(MONTH_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>
            <div className="dlv-field">
              <label htmlFor="dlv-filter-from">From</label>
              <input
                id="dlv-filter-from"
                type="date"
                value={draftFilters.from_date}
                onChange={(e) => setDraftFilters((f) => ({ ...f, from_date: e.target.value }))}
              />
            </div>
            <div className="dlv-field">
              <label htmlFor="dlv-filter-to">To</label>
              <input
                id="dlv-filter-to"
                type="date"
                value={draftFilters.to_date}
                onChange={(e) => setDraftFilters((f) => ({ ...f, to_date: e.target.value }))}
              />
            </div>
          </div>
        </div>
        <div className="dlv-filters-footer">
          <button type="button" className="dlv-btn dlv-btn--ghost" onClick={resetDraftFilters}>Clear</button>
          <button type="button" className="dlv-btn dlv-btn--primary" onClick={applyFilters}>Apply filters</button>
        </div>
      </div>
    )
    return createPortal(panel, document.body)
  }

  function toggleMobileSearch() {
    setSearchOpen((open) => !open)
  }

  function renderSearchField(inputRef, id = 'dlv-dashboard-search', showSuggestions = false) {
    return (
      <div className="dlv-search-field dlv-search-field--with-ai" ref={showSuggestions ? searchWrapRef : null}>
        <Search className="dlv-search-icon" size={15} aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="search"
          className="dlv-search-input"
          placeholder="Search delivery, client, destination, description..."
          value={searchInput}
          autoComplete="off"
          onChange={(e) => onSearchChange(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              setSuggestOpen(false)
              e.currentTarget.blur()
            }
          }}
          aria-label="Search deliveries"
        />
        <button
          type="button"
          className={`dlv-ai-btn${aiLoading ? ' is-loading' : ''}`}
          onClick={runAiSearch}
          disabled={aiLoading}
          title="Ask AI to find deliveries (e.g. delivered to Lagos last month)"
          aria-label="AI assisted search"
        >
          {aiLoading ? <Loader2 size={15} className="dlv-spin" aria-hidden="true" /> : <Sparkles size={15} aria-hidden="true" />}
        </button>
        {showSuggestions && suggestOpen && searchInput.trim() !== '' && (
          <div className="dlv-suggestions">
            {suggestions.length === 0 ? (
              <div className="dlv-suggest-empty">No matching deliveries</div>
            ) : (
              suggestions.map((order) => {
                const pill = statusPill(order.status)
                return (
                  <a
                    key={order.id}
                    href={`${urls.orderDetails || 'order_details.php'}&order_id=${order.id}`}
                    data-delivery-id={order.id}
                    className={`dlv-suggest-card${sameId(highlightedId, order.id) ? ' is-selected' : ''}`}
                    onMouseDown={(e) => { e.preventDefault(); openOrder(order.id) }}
                  >
                    <div className="dlv-suggest-top">
                      <span className="dlv-suggest-ref">{order.delivery_number || '-'}</span>
                      <span className={pill.cls}>{pill.label}</span>
                    </div>
                    <div className="dlv-suggest-driver">{order.client_name || '-'}</div>
                    <div className="dlv-suggest-meta">
                      <span>{order.delivery_address || '-'}</span>
                      <span>{formatDate(order.created_at)}</span>
                    </div>
                  </a>
                )
              })
            )}
          </div>
        )}
      </div>
    )
  }

  function onSearchChange(value) {
    setSearchInput(value)
    setSuggestOpen(value.trim() !== '')
    setHighlightedId(0)
    try { sessionStorage.removeItem('dlv.lastSelectedId') } catch { /* ignore */ }
    if (aiNote) setAiNote('')
  }

  function openOrder(id) {
    const selected = Number(id) || 0
    setHighlightedId(selected)
    const state = {
      search: searchInput,
      filters,
      note: aiNote,
      selectedId: selected,
    }
    writeListStateToUrl(searchInput, filters, selected)
    try {
      sessionStorage.setItem('dlv.lastSelectedId', String(selected))
    } catch { /* ignore */ }
    if (typeof window !== 'undefined' && window.erpNavBack && typeof window.erpNavBack.push === 'function') {
      window.erpNavBack.push({
        href: window.location.href,
        state,
      })
    }
    const base = urls.orderDetails || 'order_details.php'
    const dest = new URL(base, window.location.href)
    dest.searchParams.set('order_id', String(id))
    window.location.href = dest.href
  }

  async function runAiSearch() {
    const query = searchInput.trim()
    if (aiLoading) return
    setSuggestOpen(false)
    const searchEl = searchWrapRef.current?.querySelector('.dlv-search-input')
    if (searchEl && typeof searchEl.blur === 'function') searchEl.blur()
    if (query === '') {
      setAiNote('Type what you are looking for, then click the sparkle icon.')
      return
    }
    setAiLoading(true)
    setAiNote('')
    try {
      const data = await aiSearchDeliveries(query)
      const f = data.filters || {}
      const nextStatus = f.status ? f.status : 'all'
      setSearchInput(f.search || query)
      setFilters((cur) => ({
        ...cur,
        status: nextStatus,
        from_date: f.from_date || '',
        to_date: f.to_date || '',
      }))
      setDraftFilters((cur) => ({
        ...cur,
        status: nextStatus,
        from_date: f.from_date || '',
        to_date: f.to_date || '',
      }))
      setAiNote(data.note || 'Applied AI filters.')
    } catch (err) {
      setSearchInput(query)
      setAiNote(err instanceof Error ? err.message : 'AI search failed. Showing text matches instead.')
    } finally {
      setAiLoading(false)
    }
  }

  function applyFilters() {
    setFilters({ ...draftFilters })
    setFiltersOpen(false)
  }

  function clearAllFilters() {
    setFilters(EMPTY_FILTERS)
    setDraftFilters(EMPTY_FILTERS)
    setSearchInput('')
    setAiNote('')
    setFiltersOpen(false)
  }

  function toggleFilters() {
    if (filtersOpen) {
      setFiltersOpen(false)
      return
    }
    setDraftFilters({ ...filters })
    setFiltersOpen(true)
  }

  function openKpiTrace(key) {
    if (key === 'exceptions') {
      setActiveKpiTrace({
        title: 'Exceptions',
        headline: Number(stats.exceptions || 0).toLocaleString(),
        comingSoon: true,
      })
      return
    }
    const trace = resolveKpiTrace(key, data)
    if (trace) setActiveKpiTrace(trace)
  }

  const kpis = [
    { key: 'active', label: 'Active trips', value: Number(stats.activeTrips || 0).toLocaleString(), sub: 'Loading or in transit', color: 'blue', icon: <Truck size={17} /> },
    { key: 'pending', label: 'Pending deliveries', value: Number(stats.pending || 0).toLocaleString(), sub: 'Awaiting dispatch', color: 'amber', icon: <Clock size={17} /> },
    { key: 'exceptions', label: 'Exceptions', value: Number(stats.exceptions || 0).toLocaleString(), sub: 'Failed or returned', color: 'red', icon: <AlertTriangle size={17} /> },
  ]

  const hasSearchValue = searchInput.trim() !== ''
  const desktopSearchField = renderSearchField(searchInputRef, 'dlv-dashboard-search-desktop', true)

  if (loading && !data.stats) {
    return (
      <div className="dlv-page">
        <div className="dlv-loading" role="status">
          <Loader2 className="dlv-spin" aria-hidden="true" />
          <span>Loading dashboard...</span>
        </div>
      </div>
    )
  }

  return (
    <div className="dlv-page">
      {activeKpiTrace && (
        <DeliveryKpiTraceModal
          trace={activeKpiTrace}
          onClose={() => setActiveKpiTrace(null)}
        />
      )}

      {renderFiltersPanel()}
      {flash && (
        <div className={`dlv-flash dlv-flash--${flash.type === 'success' ? 'ok' : 'err'}`} role="alert">
          {flash.type === 'success' ? <CheckCircle2 size={18} aria-hidden="true" /> : <AlertCircle size={18} aria-hidden="true" />}
          <span>{flash.message}</span>
        </div>
      )}

      {toast && (
        <div className={`dlv-flash dlv-flash--${toast.type === 'ok' ? 'ok' : 'err'}`} role="status">
          {toast.type === 'ok' ? <CheckCircle2 size={18} aria-hidden="true" /> : <AlertCircle size={18} aria-hidden="true" />}
          <span>{toast.text}</span>
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

      <div className="dlv-dashboard-toolbar" aria-label="Search and actions">
        <div
          className={`dlv-search-expand${searchOpen ? ' is-open' : ''}`}
          ref={searchExpandRef}
        >
          <button
            type="button"
            className={`dlv-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
            onClick={toggleMobileSearch}
            aria-expanded={searchOpen}
            aria-controls="dlv-dashboard-search-mobile-panel"
            title="Search deliveries"
          >
            <Search size={18} aria-hidden="true" />
          </button>
          <div
            id="dlv-dashboard-search-mobile-panel"
            className={`dlv-search-panel${searchOpen ? ' is-open' : ''}`}
          >
            {renderSearchField(mobileSearchInputRef, 'dlv-dashboard-search-mobile', true)}
          </div>
        </div>

        <div className={`dlv-filter-dropdown${filtersOpen ? ' is-open' : ''}`} ref={filterDropdownRef}>
          <button
            ref={filterBtnRef}
            type="button"
            className={`dlv-filter-btn${filtersOpen ? ' is-active' : ''}`}
            onClick={toggleFilters}
            aria-expanded={filtersOpen}
            aria-haspopup="dialog"
            title="Filters"
          >
            <SlidersHorizontal size={18} aria-hidden="true" />
            {hasAdvancedFilters(filters) && <span className="dlv-filter-dot" aria-hidden="true" />}
          </button>
        </div>

        <a href={urls.createDelivery || 'create_delivery.php'} className="dlv-btn dlv-btn--primary dlv-btn--create" aria-label="New delivery">
          <Plus size={18} aria-hidden="true" />
          <span className="dlv-btn-label-desktop">New Delivery</span>
          <span className="dlv-btn-label-mobile">New</span>
        </a>
      </div>

      <div className="dlv-sticky-top">
        {(activeFilterChips.length > 0 || searchInput.trim() !== '') && (
          <div className="dlv-active-filters" aria-label="Active filters">
            {activeFilterChips.map((chip) => (
              <button
                key={chip.key}
                type="button"
                className="dlv-filter-chip"
                onClick={() => clearFilterChip(chip.key)}
                title="Remove filter"
              >
                <span>{chip.label}</span>
                <X size={12} aria-hidden="true" />
              </button>
            ))}
            {(activeFilterChips.length > 0 || searchInput.trim() !== '') && (
              <button type="button" className="dlv-filter-clear" onClick={clearAllFilters}>Clear all</button>
            )}
          </div>
        )}

        <div className="dlv-kpis dlv-kpis--3">
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
            <List size={18} aria-hidden="true" />
            All Deliveries
          </h3>
          <span className="dlv-card-meta">
            {filteredOrders.length} record{filteredOrders.length === 1 ? '' : 's'}
          </span>
        </div>
        {allOrders.length === 0 ? (
          <div className="dlv-empty">No deliveries recorded yet.</div>
        ) : filteredOrders.length === 0 ? (
          <div className="dlv-empty">No deliveries match your search or filters.</div>
        ) : (
          <div className="dlv-table-wrap">
            <table className="dlv-table dlv-table--full">
              <thead>
                <tr>
                  <th>S/N</th>
                  <th>Delivery</th>
                  <th>Client</th>
                  <th>Destination</th>
                  <th>Description</th>
                  <th>Status</th>
                  <th>Type</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                {filteredOrders.map((order, idx) => {
                  const pill = statusPill(order.status)
                  const type = typePill(order.delivery_type)
                  return (
                    <tr
                      key={order.id}
                      data-delivery-id={order.id}
                      className={`dlv-row${sameId(highlightedId, order.id) ? ' is-selected' : ''}`}
                      onClick={() => openOrder(order.id)}
                    >
                      <td className="dlv-sn">{idx + 1}</td>
                      <td className="dlv-ref">{order.delivery_number || '-'}</td>
                      <td>
                        {order.client_name || '-'}
                        {order.client_phone ? (
                          <>
                            <br />
                            <small className="dlv-muted">{order.client_phone}</small>
                          </>
                        ) : null}
                      </td>
                      <td>{order.delivery_address || '-'}</td>
                      <td>{order.description || '-'}</td>
                      <td className="dlv-status-cell"><span className={pill.cls}>{pill.label}</span></td>
                      <td className="dlv-type-cell"><span className={type.cls}>{type.label}</span></td>
                      <td className="dlv-muted">
                        {formatDate(order.created_at)}
                        <br /><small>{formatTime(order.created_at)}</small>
                      </td>
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
