import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  List, Search, Loader2, CheckCircle2, AlertCircle, Package,
  Clock, Truck, AlertTriangle, Sparkles, X,
} from 'lucide-react'
import { CFG } from '../config.js'
import { aiSearchDeliveries } from '../api/myDeliveries.js'
import DeliveryKpiTraceModal from '../components/DeliveryKpiTraceModal.jsx'
import { resolveKpiTrace } from '../utils/kpiTrace.js'

const STATUS_GROUPS = {
  pending: ['request_pending', 'accepted', 'pending'],
  in_transit: ['in_transit', 'loading'],
  delivered: ['delivered', 'completed'],
  exception: ['returned', 'failed', 'rejected'],
}

function orderDateKey(value) {
  if (!value) return ''
  const d = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return ''
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
}

function matchesStatusFilter(statusValue, orderStatus) {
  const normalized = String(orderStatus || '').toLowerCase()
  const filter = String(statusValue || '').toLowerCase()
  if (!filter) return true
  const group = STATUS_GROUPS[filter]
  if (group) return group.includes(normalized)
  return normalized === filter
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

function statusPill(status) {
  const s = String(status || '').toLowerCase()
  if (s === 'delivered' || s === 'completed') return { label: 'Delivered', cls: 'dlv-vbadge dlv-vbadge--completed' }
  if (s === 'in_transit' || s === 'loading') return { label: s.replace(/_/g, ' '), cls: 'dlv-vbadge dlv-vbadge--transit' }
  if (s === 'accepted' || s === 'pending') return { label: s.replace(/_/g, ' '), cls: 'dlv-vbadge dlv-vbadge--planned' }
  if (s === 'request_pending') return { label: 'Pending', cls: 'dlv-vbadge dlv-vbadge--loading' }
  if (s === 'rejected' || s === 'failed' || s === 'returned') return { label: s.replace(/_/g, ' '), cls: 'dlv-vbadge dlv-vbadge--default' }
  return { label: s ? s.replace(/_/g, ' ') : 'Unknown', cls: 'dlv-vbadge dlv-vbadge--default' }
}

function typePill(deliveryType) {
  const type = String(deliveryType || '').toLowerCase()
  if (type === 'office trip') {
    return { label: 'Office Trip', cls: 'dlv-vbadge dlv-vbadge--office-trip' }
  }
  return { label: 'Dispatch', cls: 'dlv-vbadge dlv-vbadge--dispatch' }
}

export default function MyDeliveriesPage() {
  const initial = CFG.data || {}
  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial.orders)
  const [flash, setFlash] = useState(initial.flash || null)
  const [searchInput, setSearchInput] = useState('')
  const [searchOpen, setSearchOpen] = useState(false)
  const [aiFilters, setAiFilters] = useState({ status: '', from_date: '', to_date: '' })
  const [aiLoading, setAiLoading] = useState(false)
  const [aiNote, setAiNote] = useState('')
  const [activeKpiTrace, setActiveKpiTrace] = useState(null)
  const highlightRef = useRef(null)
  const searchInputRef = useRef(null)
  const mobileSearchInputRef = useRef(null)
  const searchExpandRef = useRef(null)
  const [headerSearchMount, setHeaderSearchMount] = useState(null)

  const urls = data.urls || {}
  const orders = data.orders || []
  const stats = data.stats || {}
  const highlightId = Number(data.highlightId || 0)

  const kpis = [
    {
      key: 'total',
      label: 'Total deliveries',
      value: Number(stats.total || 0).toLocaleString(),
      sub: 'All your deliveries',
      color: 'blue',
      icon: <Package size={17} aria-hidden="true" />,
    },
    {
      key: 'pending',
      label: 'Pending',
      value: Number(stats.pending || 0).toLocaleString(),
      sub: 'Awaiting dispatch',
      color: 'amber',
      icon: <Clock size={17} aria-hidden="true" />,
    },
    {
      key: 'inTransit',
      label: 'In transit',
      value: Number(stats.inTransit || 0).toLocaleString(),
      sub: 'Loading or on the way',
      color: 'blue',
      icon: <Truck size={17} aria-hidden="true" />,
    },
    {
      key: 'delivered',
      label: 'Delivered',
      value: Number(stats.delivered || 0).toLocaleString(),
      sub: 'Completed successfully',
      color: 'green',
      icon: <CheckCircle2 size={17} aria-hidden="true" />,
    },
    {
      key: 'exceptions',
      label: 'Exceptions',
      value: Number(stats.exceptions || 0).toLocaleString(),
      sub: 'Failed or returned',
      color: 'red',
      icon: <AlertTriangle size={17} aria-hidden="true" />,
    },
  ]

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

  useEffect(() => {
    if (initial.orders) return undefined
    if (!CFG.myDeliveriesInitUrl) {
      setLoading(false)
      return undefined
    }
    let alive = true
    ;(async () => {
      try {
        const qs = window.location.search || ''
        const res = await fetch(`${CFG.myDeliveriesInitUrl}${qs}`, { headers: { Accept: 'application/json' } })
        const payload = await res.json()
        if (alive && payload?.ok && payload.data) {
          setData(payload.data)
          if (payload.data.flash) setFlash(payload.data.flash)
        }
      } catch {
        if (alive) setFlash({ type: 'error', message: 'Could not load deliveries.' })
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => { alive = false }
  }, [initial.orders])

  useEffect(() => {
    setHeaderSearchMount(document.getElementById('dlv-header-search-mount'))
  }, [])

  useEffect(() => {
    if (!highlightId || !highlightRef.current) return undefined
    const t = window.setTimeout(() => {
      highlightRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }, 300)
    return () => window.clearTimeout(t)
  }, [highlightId, loading, orders.length])

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

  async function runAiSearch() {
    const query = searchInput.trim()
    if (query === '' || aiLoading) return
    setAiLoading(true)
    setAiNote('')
    try {
      const data = await aiSearchDeliveries(query)
      const f = data.filters || {}
      setSearchInput(f.search || query)
      setAiFilters({
        status: f.status || '',
        from_date: f.from_date || '',
        to_date: f.to_date || '',
      })
      setAiNote(data.note || 'Applied AI filters.')
      setSearchOpen(true)
    } catch (err) {
      setAiFilters({ status: '', from_date: '', to_date: '' })
      setSearchInput(query)
      setAiNote(err instanceof Error ? err.message : 'AI search failed. Showing text matches instead.')
    } finally {
      setAiLoading(false)
    }
  }

  function handleSearchChange(value) {
    setSearchInput(value)
    if (aiFilters.status || aiFilters.from_date || aiFilters.to_date) {
      setAiFilters({ status: '', from_date: '', to_date: '' })
      setAiNote('')
    }
  }

  function renderSearchField(inputRef, id = 'dlv-search') {
    return (
      <div className="dlv-search-field">
        <Search className="dlv-search-icon" size={15} aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="search"
          className="dlv-search-input"
          placeholder="Search delivery, client, destination..."
          value={searchInput}
          autoComplete="off"
          onChange={(e) => handleSearchChange(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              runAiSearch()
            }
          }}
          aria-label="Search deliveries"
        />
        <button
          type="button"
          className={`dlv-ai-btn${aiLoading ? ' is-loading' : ''}`}
          onClick={runAiSearch}
          disabled={aiLoading || searchInput.trim() === ''}
          title="Ask AI to find deliveries (e.g. delivered to Lagos last month)"
          aria-label="AI assisted search"
        >
          {aiLoading ? <Loader2 size={15} className="dlv-spin" aria-hidden="true" /> : <Sparkles size={15} aria-hidden="true" />}
        </button>
      </div>
    )
  }

  const hasSearchValue = searchInput.trim() !== ''
  const desktopSearchField = renderSearchField(searchInputRef, 'dlv-search-desktop')

  const filtered = useMemo(() => {
    const q = searchInput.trim().toLowerCase()
    return orders.filter((o) => {
      if (!matchesStatusFilter(aiFilters.status, o.status)) return false

      const createdKey = orderDateKey(o.created_at)
      if (aiFilters.from_date && createdKey && createdKey < aiFilters.from_date) return false
      if (aiFilters.to_date && createdKey && createdKey > aiFilters.to_date) return false

      if (!q) return true

      const text = [
        o.id,
        o.client_name,
        o.client_phone,
        o.delivery_address,
        o.invoice_ref,
        o.delivery_type,
        o.delivery_number,
        o.status,
        o.description,
        o.receipt_name,
      ].join(' ').toLowerCase()
      return q.split(/\s+/).every((term) => text.includes(term))
    })
  }, [orders, searchInput, aiFilters])

  if (loading && !data.orders) {
    return (
      <div className="dlv-page">
        <div className="dlv-loading" role="status">
          <Loader2 className="dlv-spin" aria-hidden="true" />
          <span>Loading deliveries...</span>
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
                aria-controls="dlv-search-mobile-panel"
                title="Search deliveries"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div
                id="dlv-search-mobile-panel"
                className={`dlv-search-panel${searchOpen ? ' is-open' : ''}`}
              >
                {renderSearchField(mobileSearchInputRef, 'dlv-search-mobile')}
              </div>
            </div>
            <a
              href={urls.createDelivery || 'create_delivery.php'}
              className="dlv-btn dlv-btn--primary dlv-btn--create"
              aria-label="New delivery"
            >
              <span className="dlv-btn-label-desktop">New Delivery</span>
              <span className="dlv-btn-label-mobile">New</span>
            </a>
          </div>
        </div>
      </div>

      <div className="dlv-sticky-top">
        <div className="dlv-kpis dlv-kpis--5">
          {kpis.map((k) => (
            <button
              key={k.key}
              type="button"
              className="dlv-kpi-card dlv-kpi--clickable"
              onClick={() => openKpiTrace(k.key)}
              aria-label={`View how ${k.label} is calculated`}
              title="Click to see how this KPI is calculated"
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
            My Deliveries
          </h3>
          <span className="dlv-card-meta">{filtered.length} record{filtered.length === 1 ? '' : 's'}</span>
        </div>

        {filtered.length === 0 ? (
          <div className="dlv-empty">
            <Package size={32} aria-hidden="true" />
            <p>No deliveries yet.</p>
            <a href={urls.createDelivery || 'create_delivery.php'} className="dlv-btn dlv-btn--primary">Record a delivery</a>
          </div>
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
                {filtered.map((order, idx) => {
                  const pill = statusPill(order.status)
                  const type = typePill(order.delivery_type)
                  const isHighlight = highlightId > 0 && Number(order.id) === highlightId
                  const detailsUrl = `${urls.orderDetails || 'order_details.php'}&order_id=${order.id}`
                  return (
                    <tr
                      key={order.id}
                      ref={isHighlight ? highlightRef : null}
                      className={`dlv-row${isHighlight ? ' dlv-row--highlight' : ''}`}
                      onClick={() => { window.location.href = detailsUrl }}
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
