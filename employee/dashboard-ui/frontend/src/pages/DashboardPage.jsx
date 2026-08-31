import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  Ban,
  BookOpen,
  CheckCircle2,
  Clock,
  CreditCard,
  DollarSign,
  FileText,
  Loader2,
  Lock,
  MoreVertical,
  Paperclip,
  Pencil,
  Plus,
  Search,
  Sparkles,
  Star,
  Trash2,
  Upload,
  X,
  XCircle,
} from 'lucide-react'
import { aiSearch, fetchDashboard, getConfig, toggleReference } from '../api/dashboard.js'

function WhatsAppIcon({ size = 16 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} fill="currentColor" aria-hidden="true">
      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.884 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
    </svg>
  )
}

const CFG = getConfig()
const IS_ADMIN = CFG.role === 'admin' || CFG.inlineApproveReject === true
const MOUNT_SEARCH_IN_HEADER = CFG.mountSearchInHeader === true || IS_ADMIN
const HIDE_KPIS = CFG.hideKpis === true
const LIST_TITLE = CFG.listTitle || 'All Vouchers'
const URLS = {
  myVouchers: CFG.myVouchersUrl || (IS_ADMIN ? 'all-vouchers.php' : 'my-vouchers.php'),
  create: CFG.createUrl || 'create-voucher.php',
  view: CFG.viewUrl || '../view-voucher.php',
  edit: CFG.editUrl || 'edit-voucher.php',
  delete: CFG.deleteUrl || 'delete-voucher.php',
  approve: CFG.approveUrl || 'dashboard.php',
  markPaid: CFG.markPaidUrl || 'dashboard.php',
  reports: CFG.reportsUrl || 'reports.php',
  userManual: CFG.userManualUrl || '../employee/user-manual.php',
}
const MODULE = CFG.module || (typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('module') || '' : '')
const APPEND_MODULE = MODULE ? `&module=${encodeURIComponent(MODULE)}` : ''
const PREPEND_MODULE = MODULE ? `?module=${encodeURIComponent(MODULE)}` : ''
// Share column is opt-in (admin dashboard table has star + ⋮ only).
const SHARE_ENABLED = CFG.share === true
const DELETE_MODE = CFG.deleteMode || 'simple' // 'simple' | 'action'

function num(v) {
  return Number(v || 0)
}

function formatInt(v) {
  return num(v).toLocaleString('en-US')
}

function formatMoney(v) {
  return num(v).toLocaleString('en-US', { maximumFractionDigits: 0 })
}

function formatAmount(currency, amount) {
  const value = num(amount)
  return `${currency || ''} ${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim()
}

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

function rememberListAndView(id, listState) {
  if (typeof window !== 'undefined') {
    const state = { ...(listState || {}), selectedId: id }
    writeListStateToUrl(state.search, state.filters, id)
    if (window.erpNavBack && typeof window.erpNavBack.push === 'function') {
      window.erpNavBack.push({
        href: window.location.href,
        state,
      })
    }
    const dest = new URL(URLS.view, window.location.href)
    dest.search = ''
    dest.searchParams.set('id', String(id))
    if (MODULE) dest.searchParams.set('module', MODULE)
    window.location.href = dest.href
    return
  }
  window.location.href = `${URLS.view}?id=${id}${APPEND_MODULE}`
}

function readListStateFromUrl() {
  if (typeof window === 'undefined') {
    return { search: '', selectedId: 0, filters: { status: '', from_date: '', to_date: '' } }
  }
  const p = new URLSearchParams(window.location.search)
  return {
    search: p.get('q') || '',
    selectedId: Number(p.get('sel') || 0) || 0,
    filters: {
      status: p.get('status') || '',
      from_date: p.get('from_date') || '',
      to_date: p.get('to_date') || '',
    },
  }
}

function writeListStateToUrl(search, filters, selectedId) {
  if (typeof window === 'undefined' || !window.history || typeof window.history.replaceState !== 'function') return
  const url = new URL(window.location.href)
  const setOrDel = (key, value) => {
    const v = String(value || '').trim()
    if (v) url.searchParams.set(key, v)
    else url.searchParams.delete(key)
  }
  setOrDel('q', search)
  setOrDel('status', filters && filters.status)
  setOrDel('from_date', filters && filters.from_date)
  setOrDel('to_date', filters && filters.to_date)
  setOrDel('sel', selectedId ? String(selectedId) : '')
  const next = url.pathname + url.search + url.hash
  const cur = window.location.pathname + window.location.search + window.location.hash
  if (next !== cur) {
    window.history.replaceState(window.history.state, '', next)
  }
}

let consumedRestore = undefined
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

function quickDelete(id) {
  if (window.confirm('Delete this voucher permanently? This cannot be undone.')) {
    if (DELETE_MODE === 'action') {
      submitForm(URLS.delete, { voucher_id: id, action: 'delete' })
    } else {
      submitForm(URLS.delete, { voucher_id: id })
    }
  }
}

function quickApprove(id, action) {
  const label = action === 'approved' ? 'approve' : 'reject'
  if (!window.confirm(`Are you sure you want to ${label} this voucher?`)) return
  submitForm(URLS.approve, { voucher_id: id, action })
}

function RowActionsMenu({
  voucher: r,
  open,
  onToggle,
  onClose,
  togglingRef,
  onToggleReference,
  onMarkPaid,
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
    const openUp = spaceBelow < 220 && rect.top > spaceBelow
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
  if (IS_ADMIN && r.can_approve) {
    items.push({
      key: 'approve',
      label: 'Approve',
      icon: <CheckCircle2 size={14} aria-hidden="true" />,
      className: 'ed-menu-ok',
      onClick: () => { quickApprove(r.id, 'approved'); onClose() },
    })
  }
  if (IS_ADMIN && r.can_reject) {
    items.push({
      key: 'reject',
      label: 'Reject',
      icon: <Ban size={14} aria-hidden="true" />,
      className: 'ed-menu-danger',
      onClick: () => { quickApprove(r.id, 'rejected'); onClose() },
    })
  }
  if (IS_ADMIN && r.can_mark_paid) {
    items.push({
      key: 'paid',
      label: 'Mark paid',
      icon: <CreditCard size={14} aria-hidden="true" />,
      className: 'ed-menu-pay',
      onClick: () => { onMarkPaid(r); onClose() },
    })
  }
  if (r.can_edit) {
    items.push({
      key: 'edit',
      label: 'Edit',
      icon: <Pencil size={14} aria-hidden="true" />,
      href: `${URLS.edit}?id=${r.id}${APPEND_MODULE}`,
    })
  }
  if (r.can_delete) {
    items.push({
      key: 'delete',
      label: 'Delete',
      icon: <Trash2 size={14} aria-hidden="true" />,
      className: 'ed-menu-danger',
      onClick: () => { quickDelete(r.id); onClose() },
    })
  }

  const menuStyle = {
    left: pos.left,
    ...(pos.bottom != null ? { bottom: pos.bottom, top: 'auto' } : { top: pos.top }),
  }

  return (
    <div className="ed-actions-menu">
      <button
        type="button"
        className={`ed-star${r.is_reference ? ' is-marked' : ''}`}
        title={r.is_reference ? 'Reference voucher (click to unmark)' : 'Mark as reference'}
        aria-label={r.is_reference ? 'Unmark reference' : 'Mark as reference'}
        aria-pressed={r.is_reference}
        disabled={togglingRef === r.id}
        onClick={() => onToggleReference(r)}
      >
        <Star size={14} fill={r.is_reference ? 'currentColor' : 'none'} aria-hidden="true" />
      </button>
      {items.length > 0 ? (
        <button
          ref={btnRef}
          type="button"
          className={`ed-icon-btn ed-menu-trigger${open ? ' is-open' : ''}`}
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
            <div
              ref={menuRef}
              className="ed-menu-pop"
              role="menu"
              style={menuStyle}
            >
              {items.map((item) =>
                item.href ? (
                  <a
                    key={item.key}
                    href={item.href}
                    className={`ed-menu-item ${item.className || ''}`}
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
                    className={`ed-menu-item ${item.className || ''}`}
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

function AdminPayModal({ voucher, accounts, onClose }) {
  if (!voucher) return null
  return (
    <div className="ed-modal-overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="ed-modal" role="dialog" aria-modal="true" aria-label="Confirm payment">
        <div className="ed-modal-head">
          <h3>Confirm Payment</h3>
          <button type="button" className="ed-modal-close" onClick={onClose} aria-label="Close">
            <X size={18} aria-hidden="true" />
          </button>
        </div>
        <div className="ed-modal-details">
          <div><strong>Voucher:</strong> {voucher.voucher_no}</div>
          <div><strong>Payee:</strong> {voucher.payee_name}</div>
          <div><strong>Amount:</strong> {formatAmount(voucher.currency, voucher.total_amount)}</div>
        </div>
        <form method="POST" action={URLS.markPaid} encType="multipart/form-data" className="ed-modal-form">
          <input type="hidden" name="voucher_id" value={voucher.id} />
          <input type="hidden" name="mark_paid" value="1" />
          {MODULE ? <input type="hidden" name="module" value={MODULE} /> : null}
          <label className="ed-modal-label">
            Payment Account
            <select name="account_id" required defaultValue="">
              <option value="" disabled>Select account</option>
              {(accounts || []).map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name} ({a.currency} {Number(a.current_balance || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })})
                </option>
              ))}
            </select>
          </label>
          <label className="ed-modal-label">
            SWIFT Proof (required)
            <input type="file" name="swift_file" required accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx" />
          </label>
          <div className="ed-modal-actions">
            <button type="button" className="ed-btn ed-btn--ghost" onClick={onClose}>Cancel</button>
            <button type="submit" className="ed-btn ed-btn--primary">Confirm & Mark Paid</button>
          </div>
        </form>
      </div>
    </div>
  )
}

function shareOnWhatsApp(v) {
  const viewUrl = new URL(`${URLS.view}?id=${v.id}`, window.location.href).toString()
  const lines = [
    'Voucher Details',
    `Voucher No: ${v.voucher_no}`,
    `Payee: ${v.payee_name}`,
    `Amount: ${formatAmount(v.currency, v.total_amount)}`,
    `Status: ${v.display_status}`,
    `Details: ${viewUrl}`,
  ]
  window.open(`https://wa.me/?text=${encodeURIComponent(lines.join('\n'))}`, '_blank', 'noopener')
}

function pct(part, total) {
  if (!total) return '0.00'
  return ((num(part) / num(total)) * 100).toFixed(2)
}

function cap(s) {
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''
}

function statusPill(row) {
  if (row.is_posted) return { label: 'Posted', cls: 'ed-vbadge ed-vbadge--posted' }
  if (row.is_paid) return { label: 'Paid', cls: 'ed-vbadge ed-vbadge--paid' }
  const s = String(row.derived_status || row.status || '').toLowerCase()
  if (s === 'approved') return { label: 'Approved', cls: 'ed-vbadge ed-vbadge--approved' }
  if (s === 'rejected') return { label: 'Rejected', cls: 'ed-vbadge ed-vbadge--rejected' }
  if (s === 'confirming') return { label: 'Confirming', cls: 'ed-vbadge ed-vbadge--confirming' }
  if (s === 'draft') return { label: 'Draft', cls: 'ed-vbadge ed-vbadge--draft' }
  return { label: s ? cap(s) : 'Pending', cls: 'ed-vbadge ed-vbadge--pending' }
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

const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']

// Searchable text blob for a voucher (voucher no, payee, preparer, description, status, date, month).
function voucherText(v) {
  const d = v.date_created ? new Date((v.date_created.includes('T') ? v.date_created : v.date_created.replace(' ', 'T'))) : null
  const monthName = d && !Number.isNaN(d.getTime()) ? MONTHS[d.getMonth()] : ''
  return [
    v.voucher_no,
    v.payee_name,
    v.prepared_by,
    v.department,
    v.description,
    v.display_status,
    v.status,
    formatDate(v.date_created),
    monthName,
    d && !Number.isNaN(d.getTime()) ? String(d.getFullYear()) : '',
  ].join(' ').toLowerCase()
}

function matchesSearch(v, query) {
  const q = query.trim().toLowerCase()
  if (q === '') return true
  const text = voucherText(v)
  return q.split(/\s+/).every((term) => text.includes(term))
}

function effectiveStatus(v) {
  if (v.is_posted) return 'posted'
  if (v.is_paid) return 'paid'
  return String(v.derived_status || v.status || '').toLowerCase()
}

function matchesStatus(v, status) {
  if (!status) return true
  const raw = String(v.derived_status || v.status || '').toLowerCase()
  if (status === 'paid') return Boolean(v.is_paid) && !v.is_posted
  if (status === 'posted') return Boolean(v.is_posted)
  if (status === 'draft') return raw === 'draft'
  if (status === 'pending') return (raw === 'pending' || raw === 'confirming') && !v.is_paid && !v.is_posted
  if (status === 'approved') {
    return String(v.status || '').toLowerCase() === 'approved' && !v.is_paid && !v.is_posted
  }
  if (status === 'rejected') return raw === 'rejected' || String(v.status || '').toLowerCase() === 'rejected'
  return effectiveStatus(v) === status
}

function dateOnly(dateStr) {
  if (!dateStr) return ''
  return String(dateStr).slice(0, 10)
}

function matchesDate(v, from, to) {
  const d = dateOnly(v.date_created)
  if (from && d < from) return false
  if (to && d > to) return false
  return true
}

export default function DashboardPage() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [flash, setFlash] = useState(() => (CFG.flash ? String(CFG.flash) : ''))

  const restoredList = takeRestoredListState()
  const urlList = readListStateFromUrl()
  const [searchInput, setSearchInput] = useState(() => (
    restoredList && typeof restoredList.search === 'string'
      ? restoredList.search
      : urlList.search
  ))
  const [togglingRef, setTogglingRef] = useState(null)
  const [aiFilters, setAiFilters] = useState(() => (
    restoredList && restoredList.filters
      ? {
          status: restoredList.filters.status || '',
          from_date: restoredList.filters.from_date || '',
          to_date: restoredList.filters.to_date || '',
        }
      : {
          status: urlList.filters.status || '',
          from_date: urlList.filters.from_date || '',
          to_date: urlList.filters.to_date || '',
        }
  ))
  const [suggestOpen, setSuggestOpen] = useState(false)
  const [aiLoading, setAiLoading] = useState(false)
  const [aiNote, setAiNote] = useState(() => (
    restoredList && typeof restoredList.note === 'string' ? restoredList.note : ''
  ))
  const [highlightedId, setHighlightedId] = useState(() => {
    const fromRestore = restoredList && restoredList.selectedId
    return Number(fromRestore || urlList.selectedId || 0) || 0
  })
  const [payVoucher, setPayVoucher] = useState(null)
  const [headerSearchSlot, setHeaderSearchSlot] = useState(null)
  const [openMenuId, setOpenMenuId] = useState(null)
  const searchWrapRef = useRef(null)

  useLayoutEffect(() => {
    if (!MOUNT_SEARCH_IN_HEADER) return undefined
    const slot = document.getElementById('ed-dashboard-search-slot')
    setHeaderSearchSlot(slot)
    return undefined
  }, [])

  useEffect(() => {
    if (!flash) return undefined
    const t = window.setTimeout(() => setFlash(''), 4000)
    return () => window.clearTimeout(t)
  }, [flash])

  useEffect(() => {
    writeListStateToUrl(searchInput, aiFilters, highlightedId)
  }, [searchInput, aiFilters, highlightedId])

  useEffect(() => {
    if (!highlightedId) return undefined
    const timer = window.setTimeout(() => {
      const el = document.querySelector(`[data-voucher-id="${highlightedId}"]`)
      if (el && typeof el.scrollIntoView === 'function') {
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
      }
    }, 80)
    return () => window.clearTimeout(timer)
  }, [highlightedId, data])

  useEffect(() => {
    if (!suggestOpen) return undefined
    function onClick(e) {
      if (!searchWrapRef.current?.contains(e.target)) setSuggestOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [suggestOpen])

  async function runAiSearch() {
    const q = searchInput.trim()
    if (q === '' || aiLoading) return
    setSuggestOpen(false)
    const searchEl = searchWrapRef.current?.querySelector('.ed-search-input')
    if (searchEl && typeof searchEl.blur === 'function') searchEl.blur()
    setAiLoading(true)
    setAiNote('')
    try {
      const res = await aiSearch(q)
      if (res && res.ok && res.filters) {
        setAiFilters({
          status: res.filters.status || '',
          from_date: res.filters.from_date || '',
          to_date: res.filters.to_date || '',
        })
        if (typeof res.filters.search === 'string') setSearchInput(res.filters.search)
        setAiNote(res.note || 'Showing AI-filtered results.')
      } else {
        setAiNote(res?.error || 'Could not interpret that. Using standard search.')
      }
    } catch {
      setAiNote('AI search is unavailable right now. Using standard search.')
    } finally {
      setAiLoading(false)
    }
  }

  function onSearchChange(value) {
    setSearchInput(value)
    setAiFilters({ status: '', from_date: '', to_date: '' })
    setAiNote('')
    setHighlightedId(0)
    setSuggestOpen(value.trim() !== '')
  }

  function goView(id) {
    rememberListAndView(id, {
      search: searchInput,
      filters: aiFilters,
      note: aiNote,
      selectedId: id,
    })
  }

  function rememberListBeforeOpen(id) {
    if (typeof window === 'undefined' || !window.erpNavBack || typeof window.erpNavBack.push !== 'function') return
    if (id) setHighlightedId(id)
    writeListStateToUrl(searchInput, aiFilters, id)
    window.erpNavBack.push({
      href: window.location.href,
      state: { search: searchInput, filters: aiFilters, note: aiNote, selectedId: id || 0 },
    })
  }

  function setVoucherReference(id, value) {
    setData((cur) => {
      if (!cur || !Array.isArray(cur.recent)) return cur
      return { ...cur, recent: cur.recent.map((v) => (v.id === id ? { ...v, is_reference: value } : v)) }
    })
  }

  async function handleToggleReference(voucher) {
    setTogglingRef(voucher.id)
    setVoucherReference(voucher.id, !voucher.is_reference)
    try {
      const res = await toggleReference(voucher.id)
      const marked = parseInt(String(res.is_reference ?? '0'), 10) === 1
      setVoucherReference(voucher.id, marked)
    } catch (err) {
      setVoucherReference(voucher.id, voucher.is_reference)
      setError(err instanceof Error ? err.message : 'Could not update reference mark.')
    } finally {
      setTogglingRef(null)
    }
  }

  useEffect(() => {
    let alive = true
    ;(async () => {
      setLoading(true)
      setError('')
      try {
        const res = await fetchDashboard()
        if (alive) setData(res)
      } catch (err) {
        if (alive) setError(err instanceof Error ? err.message : 'Failed to load dashboard.')
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => {
      alive = false
    }
  }, [])

  const stats = data?.stats || {}
  const currencyTotals = data?.currency_totals || {}
  const accounts = data?.accounts || []
  const total = num(stats.total)

  const allVouchers = useMemo(() => data?.recent || [], [data])
  const filteredVouchers = useMemo(
    () => allVouchers.filter(
      (v) => matchesSearch(v, searchInput) && matchesStatus(v, aiFilters.status) && matchesDate(v, aiFilters.from_date, aiFilters.to_date),
    ),
    [allVouchers, searchInput, aiFilters],
  )
  const suggestions = useMemo(() => filteredVouchers.slice(0, 6), [filteredVouchers])

  const totalAmountLabel = useMemo(() => {
    if (IS_ADMIN && (stats.approved_amount_tzs || stats.approved_amount_usd)) {
      return `TZS ${formatMoney(stats.approved_amount_tzs)} / USD ${formatMoney(stats.approved_amount_usd)}`
    }
    const entries = Object.entries(currencyTotals)
    if (entries.length === 0) return 'TZS 0'
    const [cur, val] = entries[0]
    return `${cur} ${formatMoney(val)}`
  }, [currencyTotals, stats])

  const listBase = `${URLS.myVouchers}${URLS.myVouchers.includes('?') ? '&' : '?'}`
  const kpis = IS_ADMIN
    ? [
        { key: 'total', label: 'Total Vouchers', value: formatInt(stats.total), sub: 'All time', subCls: '', color: 'indigo', icon: <FileText size={17} />, href: `${URLS.myVouchers}${PREPEND_MODULE}` },
        { key: 'pending', label: 'Pending', value: formatInt(stats.pending), sub: 'Action needed', subCls: 'ed-sub--amber', color: 'amber', icon: <Clock size={17} />, href: `${listBase}status=pending${APPEND_MODULE}` },
        { key: 'approved', label: 'Approved', value: formatInt(stats.approved), sub: `${pct(stats.approved, total)}% of total`, subCls: 'ed-sub--green', color: 'green', icon: <CheckCircle2 size={17} />, href: `${listBase}status=approved${APPEND_MODULE}` },
        { key: 'rejected', label: 'Rejected', value: formatInt(stats.rejected), sub: 'Review', subCls: 'ed-sub--amber', color: 'red', icon: <XCircle size={17} />, href: `${listBase}status=rejected${APPEND_MODULE}` },
        { key: 'paid', label: 'Paid', value: formatInt(stats.paid), sub: `${pct(stats.paid, total)}% of total`, subCls: 'ed-sub--blue', color: 'blue', icon: <CreditCard size={17} />, href: `${listBase}status=paid${APPEND_MODULE}` },
        { key: 'posted', label: 'Posted', value: formatInt(stats.posted), sub: 'Ledger sync', subCls: 'ed-sub--green', color: 'indigo', icon: <Upload size={17} />, href: `${listBase}status=posted${APPEND_MODULE}` },
        { key: 'draft', label: 'Needs Info', value: formatInt(stats.draft), sub: 'Drafts', subCls: '', color: 'amber', icon: <FileText size={17} />, href: `${listBase}status=draft${APPEND_MODULE}` },
        { key: 'amount', label: 'Approved Volume', value: totalAmountLabel, sub: 'TZS / USD', subCls: '', color: 'blue', icon: <DollarSign size={17} />, href: `${listBase}status=approved${APPEND_MODULE}`, wide: true },
      ]
    : [
        { key: 'total', label: 'Total Vouchers', value: formatInt(stats.total), sub: 'All time', subCls: '', color: 'indigo', icon: <FileText size={17} />, href: URLS.myVouchers },
        { key: 'pending', label: 'Pending Approval', value: formatInt(stats.pending), sub: `${pct(stats.pending, total)}% of total`, subCls: 'ed-sub--amber', color: 'amber', icon: <Clock size={17} />, href: `${URLS.myVouchers}?status=pending` },
        { key: 'approved', label: 'Approved', value: formatInt(stats.approved), sub: `${pct(stats.approved, total)}% of total`, subCls: 'ed-sub--green', color: 'green', icon: <CheckCircle2 size={17} />, href: `${URLS.myVouchers}?status=approved` },
        { key: 'paid', label: 'Paid', value: formatInt(stats.paid), sub: `${pct(stats.paid, total)}% of total`, subCls: 'ed-sub--blue', color: 'blue', icon: <CreditCard size={17} />, href: `${URLS.myVouchers}?status=paid` },
        { key: 'amount', label: 'Total Amount', value: totalAmountLabel, sub: 'All vouchers', subCls: '', color: 'indigo', icon: <DollarSign size={17} />, href: URLS.myVouchers, wide: true },
      ]

  if (loading) {
    return (
      <div className="ed-page">
        <div className="ed-loading" role="status">
          <Loader2 className="ed-spin" aria-hidden="true" />
          <span>Loading dashboard...</span>
        </div>
      </div>
    )
  }

  const searchBar = (
    <div className={`ed-search${headerSearchSlot ? ' ed-search--in-header' : ''}`} ref={searchWrapRef}>
      <div className="ed-search-wrap">
        <Search className="ed-search-icon" size={15} aria-hidden="true" />
        <input
          type="search"
          className="ed-search-input"
          placeholder="Search name, voucher no., month, description, date..."
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
        />
        <button
          type="button"
          className={`ed-ai-btn${aiLoading ? ' is-loading' : ''}`}
          onClick={runAiSearch}
          disabled={aiLoading || searchInput.trim() === ''}
          title="Ask AI to find vouchers (e.g. approved PV from last month for John)"
          aria-label="AI assisted search"
        >
          {aiLoading ? <Loader2 size={15} className="ed-spin" aria-hidden="true" /> : <Sparkles size={15} aria-hidden="true" />}
        </button>
      </div>
      {aiNote && (
        <div className="ed-ai-note">
          <Sparkles size={12} aria-hidden="true" />
          <span>{aiNote}</span>
          <button type="button" className="ed-ai-note-close" onClick={() => setAiNote('')} aria-label="Dismiss">
            <X size={12} aria-hidden="true" />
          </button>
        </div>
      )}
      {suggestOpen && searchInput.trim() !== '' && (
        <div className="ed-suggestions">
          {suggestions.length === 0 ? (
            <div className="ed-suggest-empty">No matching vouchers</div>
          ) : (
            suggestions.map((v) => (
              <button
                key={v.id}
                type="button"
                data-voucher-id={v.id}
                className={`ed-suggest-card${highlightedId === v.id ? ' is-selected' : ''}`}
                onMouseDown={(e) => { e.preventDefault(); if (v.can_view !== false) goView(v.id) }}
              >
                <div className="ed-suggest-left">
                  <span className="ed-suggest-payee">{v.can_view === false ? '(Restricted)' : (v.payee_name || '(No payee)')}</span>
                  <span className="ed-suggest-vno">{v.voucher_no}</span>
                  <span className="ed-suggest-date">{formatDate(v.date_created)}</span>
                </div>
                <div className="ed-suggest-right">
                  <span className={statusPill(v).cls}>{statusPill(v).label}</span>
                  <span className="ed-suggest-amt">{formatAmount(v.currency, v.total_amount)}</span>
                </div>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )

  return (
    <div className="ed-page">
      {flash && <div className="ed-flash ed-flash--success" role="status">{flash}</div>}
      {error && <div className="ed-flash ed-flash--error" role="alert">{error}</div>}
      {headerSearchSlot ? createPortal(searchBar, headerSearchSlot) : null}

        {/* Top band: KPI cards / in-page actions (admin chrome lives in PHP header) */}
        {(!HIDE_KPIS || !headerSearchSlot) && (
        <div className={`ed-sticky-top${HIDE_KPIS ? ' ed-sticky-top--no-kpis' : ''}`}>
          {!headerSearchSlot && (
            <div className="ed-header">
              {IS_ADMIN && (
                <div className="ed-header-left">
                  <a href={`${URLS.reports}${PREPEND_MODULE}`} className="ed-btn ed-btn--ghost">
                    <DollarSign size={16} aria-hidden="true" /> Reports
                  </a>
                </div>
              )}
              {searchBar}
              <div className="ed-header-actions">
                {IS_ADMIN && (
                  <a href={`${URLS.userManual}${PREPEND_MODULE}`} className="ed-btn ed-btn--ghost">
                    <BookOpen size={16} aria-hidden="true" /> User Guide
                  </a>
                )}
                <a href={`${URLS.create}${PREPEND_MODULE}`} className="ed-btn ed-btn--primary">
                  <Plus size={18} aria-hidden="true" /> Create Voucher
                </a>
              </div>
            </div>
          )}

        {!HIDE_KPIS && (
        <div className={`ed-kpis${IS_ADMIN ? ' ed-kpis--admin' : ''}`}>
          {kpis.map((k) => (
            <a key={k.key} href={k.href} className={`ed-card ed-kpi${k.wide ? ' ed-kpi--wide' : ''}`}>
              <div className="ed-kpi-text">
                <span className="ed-kpi-label">{k.label}</span>
                <div className="ed-kpi-value">{k.value}</div>
                <div className={`ed-kpi-sub ${k.subCls}`}>{k.sub}</div>
              </div>
              <span className={`ed-kpi-badge ed-badge--${k.color}`}>{k.icon}</span>
            </a>
          ))}
        </div>
        )}
      </div>
        )}

      {/* Voucher table */}
      <div className={`ed-card${HIDE_KPIS ? ' ed-card--table-only' : ''}`}>
        {!HIDE_KPIS && (
        <div className="ed-card-head">
          <h3 className="ed-card-title">
            <a href={`${URLS.myVouchers}${PREPEND_MODULE}`} className="ed-card-title-link">
              {LIST_TITLE}
            </a>
          </h3>
          {IS_ADMIN && (
            <a href={`${URLS.myVouchers}${PREPEND_MODULE}`} className="ed-card-link">View All</a>
          )}
        </div>
        )}
        {allVouchers.length === 0 ? (
          <div className="ed-empty">No vouchers created yet.</div>
        ) : filteredVouchers.length === 0 ? (
          <div className="ed-empty">No vouchers match your search.</div>
        ) : (
          <div className="ed-table-wrap">
            <table className="ed-table ed-table--full">
              <thead>
                <tr>
                  <th className="ed-col-sn">S/N</th>
                  <th className="ed-col-vno">Voucher No.</th>
                  <th className="ed-col-payee">Payee</th>
                  <th className="ed-col-prep ed-hide-mobile">Prepared By</th>
                  <th className="ed-col-desc ed-hide-mobile">Description</th>
                  <th className="ed-ta-right ed-col-amt">Amount</th>
                  <th className="ed-col-date">Date Created</th>
                  <th className="ed-col-status">Status</th>
                  <th className="ed-col-docs ed-hide-mobile">Docs</th>
                  {SHARE_ENABLED && <th className="ed-col-share ed-hide-mobile">Share</th>}
                  <th className="ed-col-actions">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredVouchers.map((r) => {
                  const pill = statusPill(r)
                  const canView = r.can_view !== false
                  return (
                    <tr
                      key={r.id}
                      data-voucher-id={r.id}
                      className={`ed-row${canView ? '' : ' ed-row--locked'}${highlightedId === r.id ? ' is-selected' : ''}`}
                      onClick={canView ? () => goView(r.id) : undefined}
                      style={canView ? undefined : { cursor: 'default' }}
                    >
                      <td className="ed-sn">{r.sn}</td>
                      <td className="ed-vno">
                        {r.voucher_no}
                        {r.is_restricted ? <Lock size={12} aria-label="Restricted" style={{ marginLeft: 4, verticalAlign: 'middle' }} /> : null}
                      </td>
                      {canView ? (
                        <>
                          <td className="ed-payee-cell" title={r.payee_name || '(No payee)'}>{r.payee_name || '(No payee)'}</td>
                          <td className="ed-prep-cell ed-hide-mobile">
                            <span title={r.prepared_by}>{r.prepared_by}</span>
                            {r.department ? <><br /><small className="ed-muted">{r.department}</small></> : null}
                          </td>
                          <td className="ed-desc ed-hide-mobile" title={r.description}>{r.description}</td>
                          <td className="ed-ta-right ed-amt">{formatAmount(r.currency, r.total_amount)}</td>
                        </>
                      ) : (
                        <td colSpan={4} className="ed-restricted ed-restricted-span">(Restricted Content)</td>
                      )}
                      <td className="ed-muted ed-date-cell">
                        {formatDate(r.date_created)}
                        <br /><small>{formatTime(r.created_at)}</small>
                      </td>
                      <td className="ed-status-cell"><span className={pill.cls}>{pill.label}</span></td>
                      <td className="ed-doc-cell ed-hide-mobile" onClick={(e) => e.stopPropagation()}>
                        {canView && r.attachment_count > 0 ? (
                          <a
                            href={`${URLS.view}?id=${r.id}${APPEND_MODULE}#attachments`}
                            className="ed-doc-link"
                            title={`View ${r.attachment_count} attachment(s)`}
                            onClick={() => rememberListBeforeOpen(r.id)}
                          >
                            <Paperclip size={13} aria-hidden="true" />
                            <span>{r.attachment_count}</span>
                          </a>
                        ) : (
                          <span className="ed-muted">0</span>
                        )}
                      </td>
                      {SHARE_ENABLED && (
                        <td className="ed-share-cell ed-hide-mobile" onClick={(e) => e.stopPropagation()}>
                          {canView ? (
                            <button
                              type="button"
                              className="ed-icon-btn ed-icon-wa"
                              title="Share on WhatsApp"
                              aria-label="Share on WhatsApp"
                              onClick={() => shareOnWhatsApp(r)}
                            >
                              <WhatsAppIcon size={16} />
                            </button>
                          ) : null}
                        </td>
                      )}
                      <td className="ed-row-actions" onClick={(e) => e.stopPropagation()}>
                        {canView ? (
                          <RowActionsMenu
                            voucher={r}
                            open={openMenuId === r.id}
                            onToggle={() => setOpenMenuId((cur) => (cur === r.id ? null : r.id))}
                            onClose={() => setOpenMenuId(null)}
                            togglingRef={togglingRef}
                            onToggleReference={handleToggleReference}
                            onMarkPaid={setPayVoucher}
                          />
                        ) : null}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {payVoucher && (
        <AdminPayModal
          voucher={payVoucher}
          accounts={accounts}
          onClose={() => setPayVoucher(null)}
        />
      )}
    </div>
  )
}
