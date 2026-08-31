import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  Ban,
  CheckCircle2,
  CreditCard,
  Loader2,
  Lock,
  MessageCircle,
  MoreVertical,
  Paperclip,
  Pencil,
  Search,
  SlidersHorizontal,
  Sparkles,
  Star,
  Trash2,
  Upload,
  X,
} from 'lucide-react'
import {
  aiSearch,
  fetchVouchers,
  getConfig,
  getModule,
  toggleReference,
} from '../api/vouchers.js'
import PaymentModal from '../components/PaymentModal.jsx'

const MODULE = getModule()
const APPEND_MODULE = MODULE ? `&module=${encodeURIComponent(MODULE)}` : ''
const PREPEND_MODULE = MODULE ? `?module=${encodeURIComponent(MODULE)}` : ''

// Deployment config (admin vs employee). Defaults reproduce the admin behaviour.
const CFG = getConfig()
const URLS = {
  view: CFG.viewUrl || '../employee/view-voucher.php',
  edit: CFG.editUrl || '../employee/edit-voucher.php',
  create: CFG.createUrl || '../employee/create-voucher.php',
  dashboard: CFG.dashboardUrl || 'dashboard.php',
}
const FEAT = {
  inlineApproveReject: CFG.inlineApproveReject !== false,
  share: Boolean(CFG.share),
  pageTitle: CFG.pageTitle || 'All Vouchers',
  pageSubtitle: CFG.pageSubtitle || 'Manage and track all payment vouchers',
}
const MOUNT_SEARCH_IN_HEADER = CFG.mountSearchInHeader === true
const ACT = {
  approveUrl: CFG.approveUrl || 'dashboard.php',
  deleteUrl: CFG.deleteUrl || 'dashboard.php',
  deleteMode: CFG.deleteMode || 'action', // 'action' -> {voucher_id, action:'delete'}; 'simple' -> {voucher_id}
  markPostedUrl: CFG.markPostedUrl || 'all-vouchers.php',
  markPostedMode: CFG.markPostedMode || 'query', // 'query' -> include GET params + mark_posted=1; 'simple' -> {voucher_id}
  markPaidUrl: CFG.markPaidUrl || 'all-vouchers.php',
  markPaidMode: CFG.markPaidMode || 'swift', // 'swift' or 'account_swift'
}

const emptyFilters = {
  search: '',
  status: '',
  from_date: '',
  to_date: '',
  prefix: 'all',
  sort: 'newest',
  page: 1,
}

const ALLOWED_STATUS = new Set(['pending', 'approved', 'rejected', 'paid', 'posted', 'draft'])

const STATUS_LABELS = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  paid: 'Paid',
  posted: 'Posted',
  draft: 'Needs Info',
}

const SORT_LABELS = {
  newest: 'Newest First',
  asc: 'Oldest First',
  voucher_no: 'Voucher No.',
}

function filtersFromUrl() {
  const usp = new URLSearchParams(window.location.search)
  const statusRaw = String(usp.get('status') || '').toLowerCase()
  const sortRaw = String(usp.get('sort') || '').toLowerCase()
  const prefixRaw = String(usp.get('prefix') || '')
  const pageRaw = Number(usp.get('page') || 1)
  return {
    ...emptyFilters,
    search: String(usp.get('search') || usp.get('q') || ''),
    status: ALLOWED_STATUS.has(statusRaw) ? statusRaw : '',
    from_date: String(usp.get('from_date') || ''),
    to_date: String(usp.get('to_date') || ''),
    prefix: prefixRaw || 'all',
    sort: ['newest', 'asc', 'voucher_no'].includes(sortRaw) ? sortRaw : 'newest',
    page: Number.isFinite(pageRaw) && pageRaw > 0 ? Math.floor(pageRaw) : 1,
  }
}


function formatAmount(currency, amount) {
  const value = Number(amount) || 0
  return `${currency || ''} ${value.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`.trim()
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  const normalized = dateStr.includes('T') ? dateStr : dateStr.replace(' ', 'T')
  const d = new Date(normalized)
  if (Number.isNaN(d.getTime())) return dateStr
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  return `${dd}/${mm}/${d.getFullYear()}`
}

function formatTime(dateStr) {
  if (!dateStr) return ''
  const normalized = dateStr.includes('T') ? dateStr : dateStr.replace(' ', 'T')
  const d = new Date(normalized)
  if (Number.isNaN(d.getTime())) return ''
  const hh = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  return `${hh}:${min}`
}

function toInputDate(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function formatFilterDateLabel(value) {
  if (!value) return 'Any'
  const normalized = value.includes('T') ? value : `${value}T12:00:00`
  const d = new Date(normalized)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function statusBadgeClass(voucher) {
  if (voucher.is_posted) return 'pv-badge pv-badge--posted'
  if (voucher.is_paid) return 'pv-badge pv-badge--paid'
  const s = String(voucher.derived_status || '').toLowerCase()
  if (s === 'approved') return 'pv-badge pv-badge--approved'
  if (s === 'rejected') return 'pv-badge pv-badge--rejected'
  if (s === 'confirming') return 'pv-badge pv-badge--confirming'
  if (s === 'draft') return 'pv-badge pv-badge--draft'
  return 'pv-badge pv-badge--pending'
}

// Reproduce the legacy hidden-form POST behaviour so backend logic is reused verbatim.
function submitForm(action, fields) {
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = action
  Object.entries(fields).forEach(([name, value]) => {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = String(value)
    form.appendChild(input)
  })
  document.body.appendChild(form)
  form.submit()
}

function currentGetParams() {
  const params = {}
  const usp = new URLSearchParams(window.location.search)
  usp.forEach((value, key) => {
    params[key] = value
  })
  if (MODULE && !params.module) params.module = MODULE
  return params
}

function hasAdvancedFilters(f) {
  return Boolean(
    f.status
      || f.from_date
      || f.to_date
      || (f.prefix && f.prefix !== 'all' && f.prefix !== '')
      || (f.sort && f.sort !== 'newest'),
  )
}

function RowActionsMenu({
  voucher: v,
  open,
  onToggle,
  onClose,
  togglingRef,
  onToggleReference,
  onMarkPaid,
  onMarkPosted,
  canEdit,
  canDelete,
}) {
  const btnRef = useRef(null)
  const menuRef = useRef(null)
  const [pos, setPos] = useState({ top: 0, left: 0 })

  useLayoutEffect(() => {
    if (!open || !btnRef.current) return undefined
    const rect = btnRef.current.getBoundingClientRect()
    const menuW = 200
    const left = Math.min(rect.right - menuW, window.innerWidth - menuW - 8)
    const spaceBelow = window.innerHeight - rect.bottom
    const openUp = spaceBelow < 240 && rect.top > spaceBelow
    setPos({
      top: openUp ? undefined : rect.bottom + 6,
      bottom: openUp ? window.innerHeight - rect.top + 6 : undefined,
      left: Math.max(8, left),
    })
    return undefined
  }, [open])

  useEffect(() => {
    if (!open) return undefined
    function onDoc(e) {
      if (btnRef.current?.contains(e.target) || menuRef.current?.contains(e.target)) return
      onClose()
    }
    function onKey(e) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    window.addEventListener('scroll', onClose, true)
    window.addEventListener('resize', onClose)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
      window.removeEventListener('scroll', onClose, true)
      window.removeEventListener('resize', onClose)
    }
  }, [open, onClose])

  const items = []
  if (v.my_pending_approval_id) {
    items.push({
      key: 'approve-link',
      label: 'Approve',
      icon: <CheckCircle2 size={14} aria-hidden="true" />,
      className: 'pv-menu-ok',
      href: `${URLS.view}?id=${v.id}${APPEND_MODULE}&approve=1`,
    })
  } else if (FEAT.inlineApproveReject && (v.status === 'pending' || v.status === 'confirming') && !v.looks_draft) {
    items.push({
      key: 'approve',
      label: 'Approve',
      icon: <CheckCircle2 size={14} aria-hidden="true" />,
      className: 'pv-menu-ok',
      onClick: () => {
        if (window.confirm('Are you sure you want to approve this voucher?')) {
          submitForm(ACT.approveUrl, { voucher_id: v.id, action: 'approved' })
        }
        onClose()
      },
    })
  }
  if (FEAT.inlineApproveReject && (v.status === 'pending' || v.status === 'confirming') && !v.looks_draft) {
    items.push({
      key: 'reject',
      label: 'Reject',
      icon: <Ban size={14} aria-hidden="true" />,
      className: 'pv-menu-danger',
      onClick: () => {
        if (window.confirm('Are you sure you want to reject this voucher?')) {
          submitForm(ACT.approveUrl, { voucher_id: v.id, action: 'rejected' })
        }
        onClose()
      },
    })
  } else if (FEAT.inlineApproveReject && (v.status === 'pending' || v.status === 'confirming') && v.looks_draft) {
    items.push({
      key: 'draft-note',
      label: 'Draft (complete first)',
      icon: <Ban size={14} aria-hidden="true" />,
      disabled: true,
    })
  }
  if (v.can_mark_paid) {
    items.push({
      key: 'paid',
      label: 'Mark paid',
      icon: <CreditCard size={14} aria-hidden="true" />,
      className: 'pv-menu-pay',
      onClick: () => { onMarkPaid(v); onClose() },
    })
  }
  if (v.can_post) {
    items.push({
      key: 'post',
      label: 'Mark posted',
      icon: <Upload size={14} aria-hidden="true" />,
      className: 'pv-menu-post',
      onClick: () => { onMarkPosted(v.id); onClose() },
    })
  }
  if (canEdit) {
    items.push({
      key: 'edit',
      label: 'Edit',
      icon: <Pencil size={14} aria-hidden="true" />,
      href: `${URLS.edit}?id=${v.id}${APPEND_MODULE}`,
    })
  }
  if (canDelete) {
    items.push({
      key: 'delete',
      label: 'Delete',
      icon: <Trash2 size={14} aria-hidden="true" />,
      className: 'pv-menu-danger',
      onClick: () => {
        if (window.confirm('Delete this voucher permanently? This cannot be undone.')) {
          const fields = ACT.deleteMode === 'simple'
            ? { voucher_id: v.id }
            : { voucher_id: v.id, action: 'delete' }
          submitForm(ACT.deleteUrl, fields)
        }
        onClose()
      },
    })
  }

  const menuStyle = {
    left: pos.left,
    ...(pos.bottom != null ? { bottom: pos.bottom, top: 'auto' } : { top: pos.top }),
  }

  return (
    <div className="pv-actions-menu">
      <button
        type="button"
        className={`pv-star${v.is_reference ? ' is-marked' : ''}`}
        title={v.is_reference ? 'Reference voucher (click to unmark)' : 'Mark as reference'}
        aria-label={v.is_reference ? 'Unmark reference' : 'Mark as reference'}
        aria-pressed={v.is_reference}
        disabled={togglingRef === v.id}
        onClick={() => onToggleReference(v)}
      >
        <Star size={14} fill={v.is_reference ? 'currentColor' : 'none'} aria-hidden="true" />
      </button>
      {items.length > 0 ? (
        <button
          ref={btnRef}
          type="button"
          className={`pv-icon-btn pv-menu-trigger${open ? ' is-open' : ''}`}
          title="Actions"
          aria-label="Open actions menu"
          aria-haspopup="menu"
          aria-expanded={open}
          onClick={onToggle}
        >
          <MoreVertical size={16} aria-hidden="true" />
        </button>
      ) : null}
      {open && items.length > 0
        ? createPortal(
            <div ref={menuRef} className="pv-menu-pop" role="menu" style={menuStyle}>
              {items.map((item) =>
                item.href ? (
                  <a
                    key={item.key}
                    href={item.href}
                    className={`pv-menu-item ${item.className || ''}`}
                    role="menuitem"
                    onClick={onClose}
                  >
                    {item.icon}
                    <span>{item.label}</span>
                  </a>
                ) : (
                  <button
                    key={item.key}
                    type="button"
                    className={`pv-menu-item ${item.className || ''}`}
                    role="menuitem"
                    disabled={item.disabled}
                    onClick={item.onClick}
                  >
                    {item.icon}
                    <span>{item.label}</span>
                  </button>
                ),
              )}
            </div>,
            document.body,
          )
        : null}
    </div>
  )
}

export default function VouchersDeskPage() {
  const [filters, setFilters] = useState(() => filtersFromUrl())
  const [draftFilters, setDraftFilters] = useState(() => filtersFromUrl())
  const prefixTouchedRef = useRef(false)
  const [vouchers, setVouchers] = useState([])
  const [pagination, setPagination] = useState({ page: 1, total_pages: 1, total_records: 0 })
  const [prefixOptions, setPrefixOptions] = useState([])
  const [payAccounts, setPayAccounts] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [flash, setFlash] = useState(() =>
    typeof window !== 'undefined' && window.__VOUCHERS_FLASH__ ? String(window.__VOUCHERS_FLASH__) : '',
  )
  const [highlightId, setHighlightId] = useState(() =>
    typeof window !== 'undefined' && window.__VOUCHERS_HIGHLIGHT__ ? Number(window.__VOUCHERS_HIGHLIGHT__) : 0,
  )

  const [searchInput, setSearchInput] = useState(() => filtersFromUrl().search)
  const [previewVouchers, setPreviewVouchers] = useState([])
  const [previewLoading, setPreviewLoading] = useState(false)
  const [suggestOpen, setSuggestOpen] = useState(false)
  const suggestWrapRef = useRef(null)
  const suggestTimer = useRef(null)
  const [aiLoading, setAiLoading] = useState(false)
  const [aiNote, setAiNote] = useState('')

  const [filtersOpen, setFiltersOpen] = useState(false)
  const filterDropdownRef = useRef(null)
  const filterBtnRef = useRef(null)
  const filterPanelRef = useRef(null)
  const [filterPanelStyle, setFilterPanelStyle] = useState(null)

  const [payModal, setPayModal] = useState(null)
  const [togglingRef, setTogglingRef] = useState(null)
  const [openMenuId, setOpenMenuId] = useState(null)
  const [headerSearchSlot, setHeaderSearchSlot] = useState(null)
  const [filterSlot, setFilterSlot] = useState(null)

  useLayoutEffect(() => {
    if (!MOUNT_SEARCH_IN_HEADER) return undefined
    setHeaderSearchSlot(document.getElementById('ed-dashboard-search-slot'))
    setFilterSlot(document.getElementById('pv-filter-slot'))
    return undefined
  }, [])

  const loadData = useCallback(async (activeFilters, silent = false) => {
    if (!silent) setLoading(true)
    setError('')
    try {
      const data = await fetchVouchers(activeFilters)
      setVouchers(Array.isArray(data.vouchers) ? data.vouchers : [])
      setPagination(data.pagination || { page: 1, total_pages: 1, total_records: 0 })
      setPrefixOptions(Array.isArray(data.prefix_options) ? data.prefix_options : [])
      if (Array.isArray(data.pay_accounts)) setPayAccounts(data.pay_accounts)
      // Do not auto-apply the server's default (current) prefix; default to All Prefixes.
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load vouchers.')
    } finally {
      if (!silent) setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadData(filters)
  }, [filters, loadData])

  useEffect(() => {
    if (!flash) return undefined
    const t = window.setTimeout(() => setFlash(''), 4000)
    return () => window.clearTimeout(t)
  }, [flash])

  // Scroll to and briefly highlight a newly created voucher (from create-voucher redirect).
  useEffect(() => {
    if (!highlightId || loading) return undefined
    if (!vouchers.some((v) => Number(v.id) === highlightId)) return undefined
    const el = document.querySelector(`[data-vid="${highlightId}"]`)
    if (el && typeof el.scrollIntoView === 'function') {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
    const t = window.setTimeout(() => setHighlightId(0), 5000)
    return () => window.clearTimeout(t)
  }, [highlightId, vouchers, loading])

  // Suggestions dropdown outside-click
  useEffect(() => {
    if (!suggestOpen) return undefined
    function handleClick(e) {
      if (!suggestWrapRef.current?.contains(e.target)) {
        setSuggestOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [suggestOpen])

  // Filter panel outside-click / escape
  useEffect(() => {
    if (!filtersOpen) return undefined
    function handlePointerDown(event) {
      if (filterDropdownRef.current?.contains(event.target)) return
      if (filterPanelRef.current?.contains(event.target)) return
      setFiltersOpen(false)
    }
    function handleKeyDown(event) {
      if (event.key === 'Escape') setFiltersOpen(false)
    }
    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [filtersOpen])

  // Position the floating filter panel near the button (matches expenses desk)
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

  function onSearchChange(value) {
    setSearchInput(value)
    window.clearTimeout(suggestTimer.current)
    const q = value.trim()
    if (q === '') {
      setPreviewVouchers([])
      setPreviewLoading(false)
      setSuggestOpen(false)
      return
    }
    setPreviewLoading(true)
    setSuggestOpen(true)
    suggestTimer.current = window.setTimeout(async () => {
      try {
        const data = await fetchVouchers({ search: q, page: 1 })
        setPreviewVouchers(Array.isArray(data.vouchers) ? data.vouchers.slice(0, 6) : [])
      } catch {
        setPreviewVouchers([])
      } finally {
        setPreviewLoading(false)
      }
    }, 250)
  }

  function applySearch(value) {
    const term = value !== undefined ? value : searchInput
    setSuggestOpen(false)
    setFilters((cur) => ({ ...cur, search: term, page: 1 }))
  }

  async function runAiSearch() {
    const query = searchInput.trim()
    if (query === '' || aiLoading) return
    setSuggestOpen(false)
    setAiLoading(true)
    setAiNote('')
    setError('')
    try {
      const data = await aiSearch(query)
      const f = data.filters || {}
      prefixTouchedRef.current = true
      setSearchInput(f.search || '')
      setDraftFilters((cur) => ({
        ...cur,
        search: f.search || '',
        status: f.status || '',
        from_date: f.from_date || '',
        to_date: f.to_date || '',
        prefix: f.prefix || '',
        sort: f.sort || 'newest',
      }))
      setFilters((cur) => ({
        ...cur,
        search: f.search || '',
        status: f.status || '',
        from_date: f.from_date || '',
        to_date: f.to_date || '',
        prefix: f.prefix || '',
        sort: f.sort || 'newest',
        page: 1,
      }))
      setAiNote(data.note || 'Applied AI filters.')
    } catch (err) {
      // Fall back to a plain search so the button always does something useful.
      setFilters((cur) => ({ ...cur, search: query, page: 1 }))
      setError(err instanceof Error ? err.message : 'AI search failed.')
    } finally {
      setAiLoading(false)
    }
  }

  function updateDraft(key, value) {
    if (key === 'prefix') prefixTouchedRef.current = true
    setDraftFilters((cur) => ({ ...cur, [key]: value }))
  }

  function setDatePreset(preset) {
    const now = new Date()
    if (preset === 'month') {
      const from = new Date(now.getFullYear(), now.getMonth(), 1)
      setDraftFilters((cur) => ({ ...cur, from_date: toInputDate(from), to_date: toInputDate(now) }))
      return
    }
    if (preset === '30d') {
      const from = new Date(now)
      from.setDate(from.getDate() - 30)
      setDraftFilters((cur) => ({ ...cur, from_date: toInputDate(from), to_date: toInputDate(now) }))
      return
    }
    setDraftFilters((cur) => ({ ...cur, from_date: '', to_date: '' }))
  }

  function openFilters() {
    setDraftFilters({ ...filters })
    setFiltersOpen(true)
  }

  function toggleFilters() {
    if (filtersOpen) {
      setFiltersOpen(false)
      return
    }
    openFilters()
  }

  function applyFilters() {
    prefixTouchedRef.current = true
    setFilters((cur) => ({
      ...cur,
      status: draftFilters.status,
      from_date: draftFilters.from_date,
      to_date: draftFilters.to_date,
      prefix: draftFilters.prefix,
      sort: draftFilters.sort,
      page: 1,
    }))
    setFiltersOpen(false)
  }

  function resetDraftFilters() {
    prefixTouchedRef.current = true
    setDraftFilters((cur) => ({ ...cur, status: '', from_date: '', to_date: '', prefix: 'all', sort: 'newest' }))
  }

  function resetFilters() {
    prefixTouchedRef.current = true
    const cleared = { status: '', from_date: '', to_date: '', prefix: 'all', sort: 'newest' }
    setDraftFilters((cur) => ({ ...cur, ...cleared }))
    setFilters((cur) => ({ ...cur, ...cleared, page: 1 }))
    setFiltersOpen(false)
  }

  function clearFilterChip(key) {
    prefixTouchedRef.current = true
    if (key === 'date') {
      setDraftFilters((cur) => ({ ...cur, from_date: '', to_date: '' }))
      setFilters((cur) => ({ ...cur, from_date: '', to_date: '', page: 1 }))
      return
    }
    if (key === 'prefix') {
      setDraftFilters((cur) => ({ ...cur, prefix: 'all' }))
      setFilters((cur) => ({ ...cur, prefix: 'all', page: 1 }))
      return
    }
    if (key === 'sort') {
      setDraftFilters((cur) => ({ ...cur, sort: 'newest' }))
      setFilters((cur) => ({ ...cur, sort: 'newest', page: 1 }))
      return
    }
    setDraftFilters((cur) => ({ ...cur, [key]: '' }))
    setFilters((cur) => ({ ...cur, [key]: '', page: 1 }))
  }

  function gotoPage(page) {
    setFilters((cur) => ({ ...cur, page }))
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function goView(id) {
    window.location.href = `${URLS.view}?id=${id}${APPEND_MODULE}`
  }

  function quickApprove(id, action) {
    if (window.confirm(`Are you sure you want to ${action} this voucher?`)) {
      submitForm(ACT.approveUrl, { voucher_id: id, action })
    }
  }

  function quickDelete(id) {
    if (window.confirm('Delete this voucher permanently? This cannot be undone.')) {
      const fields = ACT.deleteMode === 'simple'
        ? { voucher_id: id }
        : { voucher_id: id, action: 'delete' }
      submitForm(ACT.deleteUrl, fields)
    }
  }

  function markPosted(id) {
    if (!window.confirm('Mark this voucher as POSTED?')) return
    const fields = ACT.markPostedMode === 'simple'
      ? { voucher_id: id }
      : { voucher_id: id, mark_posted: 1, ...currentGetParams() }
    submitForm(ACT.markPostedUrl, fields)
  }

  function shareOnWhatsApp(voucher) {
    const viewUrl = new URL(`${URLS.view}?id=${voucher.id}`, window.location.href).toString()
    const lines = [
      'Voucher Details',
      `Voucher No: ${voucher.voucher_no}`,
      `Payee: ${voucher.payee_name}`,
      `Amount: ${formatAmount(voucher.currency, voucher.total_amount)}`,
      `Status: ${voucher.display_status}`,
      `Details: ${viewUrl}`,
    ]
    const text = encodeURIComponent(lines.join('\n'))
    window.open(`https://wa.me/?text=${text}`, '_blank', 'noopener')
  }

  async function handleToggleReference(voucher) {
    setTogglingRef(voucher.id)
    setVouchers((cur) => cur.map((v) => (v.id === voucher.id ? { ...v, is_reference: !v.is_reference } : v)))
    try {
      const data = await toggleReference(voucher.id)
      const marked = parseInt(String(data.is_reference ?? '0'), 10) === 1
      setVouchers((cur) => cur.map((v) => (v.id === voucher.id ? { ...v, is_reference: marked } : v)))
    } catch (err) {
      setVouchers((cur) => cur.map((v) => (v.id === voucher.id ? { ...v, is_reference: voucher.is_reference } : v)))
      setError(err instanceof Error ? err.message : 'Could not update reference mark.')
    } finally {
      setTogglingRef(null)
    }
  }

  function buildActiveFilterChips(f) {
    const chips = []
    if (f.status) {
      chips.push({ key: 'status', label: `Status: ${STATUS_LABELS[f.status] || f.status}` })
    }
    if (f.prefix && f.prefix !== 'all' && f.prefix !== '') {
      const opt = prefixOptions.find((o) => o.value === f.prefix)
      chips.push({ key: 'prefix', label: `Prefix: ${opt?.label || f.prefix}` })
    }
    if (f.from_date || f.to_date) {
      chips.push({ key: 'date', label: `Date: ${formatFilterDateLabel(f.from_date)} - ${formatFilterDateLabel(f.to_date)}` })
    }
    if (f.sort && f.sort !== 'newest') {
      chips.push({ key: 'sort', label: `Sort: ${SORT_LABELS[f.sort] || f.sort}` })
    }
    return chips
  }

  const showingPage = pagination.page || 1
  const totalPages = pagination.total_pages || 1
  const activeFilterChips = buildActiveFilterChips(filters)

  function renderFiltersPanel() {
    if (!filtersOpen || !filterPanelStyle) return null
    const panel = (
      <div
        ref={filterPanelRef}
        className="pv-filters-panel"
        style={filterPanelStyle}
        role="dialog"
        aria-label="Filter options"
      >
        <div className="pv-filters-head">
          <div>
            <h2 className="pv-filters-title">Filters</h2>
            <p className="pv-filters-sub">Narrow the list by prefix, date, status, and order.</p>
          </div>
          <button type="button" className="pv-filters-close" onClick={() => setFiltersOpen(false)} aria-label="Close filters">
            <X size={16} aria-hidden="true" />
          </button>
        </div>

        <div className="pv-filters-body">
          <div className="pv-filters-section">
            <div className="pv-filters-section-label">Date range</div>
            <div className="pv-date-presets" role="group" aria-label="Quick date ranges">
              <button type="button" className="pv-date-preset" onClick={() => setDatePreset('month')}>
                This month
              </button>
              <button type="button" className="pv-date-preset" onClick={() => setDatePreset('30d')}>
                Last 30 days
              </button>
              <button type="button" className="pv-date-preset" onClick={() => setDatePreset('clear')}>
                Clear dates
              </button>
            </div>
            <div className="pv-filters-grid pv-filters-grid--dates">
              <div className="pv-field">
                <label htmlFor="pvFilterFrom">From</label>
                <input
                  id="pvFilterFrom"
                  type="date"
                  value={draftFilters.from_date}
                  onChange={(e) => updateDraft('from_date', e.target.value)}
                />
              </div>
              <div className="pv-field">
                <label htmlFor="pvFilterTo">To</label>
                <input
                  id="pvFilterTo"
                  type="date"
                  value={draftFilters.to_date}
                  onChange={(e) => updateDraft('to_date', e.target.value)}
                />
              </div>
            </div>
          </div>

          <div className="pv-filters-section">
            <div className="pv-filters-section-label">Details</div>
            <div className="pv-filters-grid">
              <div className="pv-field">
                <label htmlFor="pvFilterStatus">Status</label>
                <select
                  id="pvFilterStatus"
                  value={draftFilters.status}
                  onChange={(e) => updateDraft('status', e.target.value)}
                >
                  <option value="">All statuses</option>
                  <option value="pending">Pending</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                  <option value="paid">Paid</option>
                  <option value="posted">Posted</option>
                  <option value="draft">Needs Info</option>
                </select>
              </div>
              <div className="pv-field">
                <label htmlFor="pvFilterSort">Sort</label>
                <select
                  id="pvFilterSort"
                  value={draftFilters.sort}
                  onChange={(e) => updateDraft('sort', e.target.value)}
                >
                  <option value="newest">Newest First</option>
                  <option value="asc">Oldest First</option>
                  <option value="voucher_no">Voucher No.</option>
                </select>
              </div>
              <div className="pv-field pv-field--full">
                <label htmlFor="pvFilterPrefix">Prefix</label>
                <select
                  id="pvFilterPrefix"
                  value={draftFilters.prefix || 'all'}
                  onChange={(e) => updateDraft('prefix', e.target.value)}
                >
                  <option value="all">All Prefixes</option>
                  {prefixOptions.map((opt) => (
                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                  ))}
                </select>
              </div>
            </div>
          </div>
        </div>

        <div className="pv-filters-footer">
          <button type="button" className="pv-btn pv-btn-ghost" onClick={resetDraftFilters}>Clear</button>
          <button type="button" className="pv-btn pv-btn-primary" onClick={applyFilters}>Apply filters</button>
        </div>
      </div>
    )
    return createPortal(panel, document.body)
  }

  return (
    <div className="pv-page">
      {payModal && (
        <PaymentModal
          voucher={payModal}
          getParams={currentGetParams()}
          actionUrl={ACT.markPaidUrl}
          mode={ACT.markPaidMode}
          accounts={payAccounts}
          onClose={() => setPayModal(null)}
        />
      )}
      {renderFiltersPanel()}

      {(() => {
        const searchBlock = (
          <div className={`pv-top-search${headerSearchSlot ? ' pv-search--in-header' : ''}`} ref={suggestWrapRef}>
            <div className="pv-search-wrap">
              <Search className="pv-search-icon" size={15} aria-hidden="true" />
              <input
                type="search"
                className="pv-search-input"
                placeholder="Search name, voucher no., month, description, date..."
                value={searchInput}
                autoComplete="off"
                onChange={(e) => onSearchChange(e.target.value)}
                onFocus={() => previewVouchers.length > 0 && setSuggestOpen(true)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    applySearch()
                  }
                }}
              />
              <button
                type="button"
                className={`pv-ai-btn${aiLoading ? ' is-loading' : ''}`}
                onClick={runAiSearch}
                disabled={aiLoading || searchInput.trim() === ''}
                title="Ask AI to find vouchers (e.g. approved PV from last month for John)"
                aria-label="AI assisted search"
              >
                {aiLoading ? <Loader2 size={15} className="pv-spin" aria-hidden="true" /> : <Sparkles size={15} aria-hidden="true" />}
              </button>
            </div>
            {aiNote && (
              <div className="pv-ai-note">
                <Sparkles size={12} aria-hidden="true" />
                <span>{aiNote}</span>
                <button type="button" className="pv-ai-note-close" onClick={() => setAiNote('')} aria-label="Dismiss">
                  <X size={12} aria-hidden="true" />
                </button>
              </div>
            )}
            {suggestOpen && searchInput.trim() !== '' && (
              <div className="pv-suggestions">
                {previewLoading ? (
                  <div className="pv-suggest-loading">
                    <Loader2 size={15} className="pv-spin" aria-hidden="true" />
                    <span>Searching...</span>
                  </div>
                ) : previewVouchers.length === 0 ? (
                  <div className="pv-suggest-empty">No matching vouchers</div>
                ) : (
                  <>
                    {previewVouchers.map((v) => (
                      <button
                        key={v.id}
                        type="button"
                        className="pv-suggest-card"
                        onMouseDown={(e) => {
                          e.preventDefault()
                          goView(v.id)
                        }}
                      >
                        <div className="pv-suggest-left">
                          <span className="pv-suggest-card-payee">{v.payee_name || '?'}</span>
                          <span className="pv-suggest-vno">{v.voucher_no}</span>
                          <span className="pv-suggest-card-date">{formatDate(v.date_created)}</span>
                          {v.description ? <div className="pv-suggest-card-desc">{v.description}</div> : null}
                        </div>
                        <div className="pv-suggest-right">
                          <span className={statusBadgeClass(v)}>{v.display_status}</span>
                          <span className="pv-suggest-card-amt">{formatAmount(v.currency, v.total_amount)}</span>
                        </div>
                      </button>
                    ))}
                    <button
                      type="button"
                      className="pv-suggest-all"
                      onMouseDown={(e) => {
                        e.preventDefault()
                        applySearch()
                      }}
                    >
                      See all results for "{searchInput.trim()}"
                    </button>
                  </>
                )}
              </div>
            )}
          </div>
        )

        const filterBlock = (
          <div
            className={`pv-filter-dropdown${filtersOpen ? ' is-open' : ''}`}
            ref={filterDropdownRef}
          >
            <button
              ref={filterBtnRef}
              type="button"
              className={`pv-filter-btn${filtersOpen ? ' is-active' : ''}`}
              onClick={toggleFilters}
              aria-expanded={filtersOpen}
              aria-haspopup="dialog"
              title="Filters"
            >
              <SlidersHorizontal size={18} aria-hidden="true" />
              {hasAdvancedFilters(filters) && <span className="pv-filter-dot" aria-hidden="true" />}
            </button>
          </div>
        )

        if (headerSearchSlot) {
          return (
            <>
              {createPortal(searchBlock, headerSearchSlot)}
              {filterSlot ? createPortal(filterBlock, filterSlot) : (
                <div className="pv-toolbar">{filterBlock}</div>
              )}
            </>
          )
        }

        return (
          <div className="pv-top">
            <div className="pv-top-left">
              <h3 className="pv-title">{FEAT.pageTitle}</h3>
              <p className="pv-subtitle">{FEAT.pageSubtitle}</p>
            </div>
            {searchBlock}
            <div className="pv-actions">{filterBlock}</div>
          </div>
        )
      })()}

      {activeFilterChips.length > 0 && (
        <div className="pv-active-filters" aria-label="Active filters">
          {activeFilterChips.map((chip) => (
            <button
              key={chip.key}
              type="button"
              className="pv-filter-chip"
              onClick={() => clearFilterChip(chip.key)}
              title="Remove filter"
            >
              <span>{chip.label}</span>
              <X size={12} aria-hidden="true" />
            </button>
          ))}
          <button type="button" className="pv-filter-chip pv-filter-chip--clear" onClick={resetFilters}>
            Clear all
          </button>
        </div>
      )}

      <div className="pv-card">
        {flash && <div className="pv-flash pv-flash-success" role="status">{flash}</div>}
        {error && <div className="pv-flash pv-flash-error" role="alert">{error}</div>}

        {loading ? (
          <div className="pv-loading" role="status">
            <Loader2 className="pv-spin" aria-hidden="true" />
            <span>Loading vouchers...</span>
          </div>
        ) : vouchers.length === 0 ? (
          <div className="pv-empty" role="status">
            <div className="pv-empty-anim" aria-hidden="true">
              <span className="pv-empty-pulse" />
              <span className="pv-empty-pulse pv-empty-pulse--2" />
              <svg className="pv-empty-glass" width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                <circle cx="11" cy="11" r="7" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                <line className="pv-empty-glass-x1" x1="8" y1="11" x2="14" y2="11" />
              </svg>
            </div>
            <p className="pv-empty-title">No vouchers found</p>
            <p className="pv-empty-sub">Try adjusting your search or filters.</p>
          </div>
        ) : (
          <>
            <div className="pv-table-wrap">
              <table className="pv-table pv-table--full">
                <thead>
                  <tr>
                    <th>S/N</th>
                    <th>Voucher No.</th>
                    <th>Payee</th>
                    <th>Prepared By</th>
                    <th>Description</th>
                    <th className="pv-col-amount">Amount</th>
                    <th>Date Created</th>
                    <th>Status</th>
                    <th>Docs</th>
                    {FEAT.share && <th>Share</th>}
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {vouchers.map((v) => {
                    const canView = v.can_view !== false
                    const canEdit = v.can_edit !== false
                    const canDelete = v.can_delete !== false
                    return (
                    <tr
                      key={v.id}
                      data-vid={v.id}
                      className={`pv-row${canView ? '' : ' pv-row--locked'}${Number(v.id) === highlightId ? ' pv-row--highlight' : ''}`}
                      onClick={canView ? () => goView(v.id) : undefined}
                      style={canView ? undefined : { cursor: 'default' }}
                    >
                      <td>{v.sn}</td>
                      <td className="pv-voucher-no">
                        {v.voucher_no}
                        {v.is_restricted ? <Lock size={12} aria-label="Restricted" style={{ marginLeft: 4, verticalAlign: 'middle' }} /> : null}
                      </td>
                      {canView ? (
                        <>
                          <td>{v.payee_name}</td>
                          <td>
                            {v.prepared_by}
                            {v.department ? <><br /><small>{v.department}</small></> : null}
                          </td>
                          <td className="pv-desc">{v.description}</td>
                          <td className="pv-col-amount">{formatAmount(v.currency, v.total_amount)}</td>
                        </>
                      ) : (
                        <>
                          <td colSpan={4} className="pv-restricted">(Restricted Content)</td>
                        </>
                      )}
                      <td>
                        {formatDate(v.date_created)}
                        <br /><small>{formatTime(v.created_at)}</small>
                      </td>
                      <td className="pv-status-cell">
                        <span className={statusBadgeClass(v)}>{v.display_status}</span>
                      </td>
                      <td onClick={(e) => e.stopPropagation()}>
                        {canView && v.attachment_count > 0 ? (
                          <a
                            href={`${URLS.view}?id=${v.id}${APPEND_MODULE}#attachments`}
                            className="pv-doc-link"
                            title={`View ${v.attachment_count} attachment(s)`}
                          >
                            <Paperclip size={13} aria-hidden="true" />
                            <span>{v.attachment_count}</span>
                          </a>
                        ) : (
                          <span className="pv-muted">0</span>
                        )}
                      </td>
                      {FEAT.share && (
                        <td onClick={(e) => e.stopPropagation()}>
                          {canView ? (
                            <button
                              type="button"
                              className="pv-icon-link pv-icon-wa"
                              title="Share on WhatsApp"
                              aria-label="Share on WhatsApp"
                              onClick={() => shareOnWhatsApp(v)}
                            >
                              <MessageCircle size={15} aria-hidden="true" />
                            </button>
                          ) : null}
                        </td>
                      )}
                      <td className="pv-row-actions" onClick={(e) => e.stopPropagation()}>
                        {canView ? (
                          <RowActionsMenu
                            voucher={v}
                            open={openMenuId === v.id}
                            onToggle={() => setOpenMenuId((cur) => (cur === v.id ? null : v.id))}
                            onClose={() => setOpenMenuId(null)}
                            togglingRef={togglingRef}
                            onToggleReference={handleToggleReference}
                            onMarkPaid={setPayModal}
                            onMarkPosted={markPosted}
                            canEdit={canEdit}
                            canDelete={canDelete}
                          />
                        ) : null}
                      </td>
                    </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            {totalPages > 1 && (
              <div className="pv-pagination">
                <span className="pv-muted">Showing Page {showingPage} of {totalPages}</span>
                <div className="pv-pagination-btns">
                  <button
                    type="button"
                    className="pv-btn pv-btn-outline pv-btn-sm"
                    disabled={showingPage <= 1}
                    onClick={() => gotoPage(showingPage - 1)}
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    className="pv-btn pv-btn-outline pv-btn-sm"
                    disabled={showingPage >= totalPages}
                    onClick={() => gotoPage(showingPage + 1)}
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
