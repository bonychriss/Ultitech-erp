import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Button, EmptyState, ErrorBanner, Input, Label, PageHeader } from '../components/ui'
import {
  PurchaseMarkPaidModal,
  type PayFinAccount,
  type PayPriorPayment,
  type PayVoucherPayload,
} from '../components/PurchaseMarkPaidModal'

type Supplier = { id: number; code: string; name: string }
type PurchaseVoucher = {
  id: number
  pv_id?: number
  bill_number: string
  voucher_number?: string
  voucher_no_official?: string
  bill_date: string
  due_date?: string | null
  description?: string | null
  currency_code?: string
  subtotal?: string
  tax_total?: string
  total: string
  amount_paid?: string
  balance_due: string
  status: string
  display_status?: string
  is_paid?: number
  is_posted?: number
  is_voucher?: number
  payment_method_label?: string
  linked_po_ids?: number[]
  linked_po_numbers?: string[]
  linked_po_label?: string
  supplier?: Supplier
}
type PayFilter = 'all' | 'unpaid' | 'partial' | 'paid'

function resolveCompanySlug(embedSlug: string): string {
  const fromEmbed = String(embedSlug || '').trim().replace(/^\/+|\/+$/g, '')
  if (fromEmbed) return fromEmbed
  const parts = window.location.pathname.split('/').filter(Boolean)
  const skip = new Set(['api', 'accounting', 'ultitech-accounting', 'ultitech-bridge', 'public_html', 'modules'])
  const found = parts.find((p) => !skip.has(p.toLowerCase()))
  return found || ''
}

/** Company root including APP_BASE_PATH (e.g. /public_html/ultimate or /ultimate). */
function companyRootPath(slug: string, hintUrl = ''): string {
  const cleanSlug = String(slug || '').trim().replace(/^\/+|\/+$/g, '')
  const candidates = [window.location.pathname, hintUrl]
  for (const raw of candidates) {
    const path = String(raw || '').split(/[?#]/)[0]
    if (!path || !cleanSlug) continue
    const marker = `/${cleanSlug}/`
    const idx = path.toLowerCase().indexOf(marker.toLowerCase())
    if (idx >= 0) return path.slice(0, idx + marker.length - 1)
    const end = `/${cleanSlug}`
    if (path.toLowerCase().endsWith(end.toLowerCase())) return path
  }
  return cleanSlug ? `/${cleanSlug}` : ''
}

/** App root without company slug (e.g. /public_html). view-voucher-ui lives here, not under /ultimate. */
function appRootPath(slug: string, hintUrl = ''): string {
  const cleanSlug = String(slug || '').trim().replace(/^\/+|\/+$/g, '')
  const company = companyRootPath(cleanSlug, hintUrl).replace(/\/+$/, '')
  if (company && cleanSlug) {
    const suffix = `/${cleanSlug}`
    if (company.toLowerCase().endsWith(suffix.toLowerCase())) {
      return company.slice(0, -suffix.length)
    }
  }
  const path = window.location.pathname
  const m = path.match(/^(\/public_html)(?=\/|$)/i)
  if (m) return m[1]
  return ''
}

type DeskMode = 'purchases' | 'expenses'

type DeskConfig = {
  title: string
  apiPath: string
  queryKey: string
  module: string
  from: string
  purpose: string
  voucherType: string
  showPoLinks: boolean
  crumbRowId: string
  crumbSearchId: string
  filterStoragePrefix: string
  emptyTitle: string
  emptyBody: string
  createLabel: string
  loadingLabel: string
  searchAria: string
  filterAria: string
  deskAria: string
  kpiAria: string
}

const DESKS: Record<DeskMode, DeskConfig> = {
  purchases: {
    title: 'Purchases',
    apiPath: '/purchase-vouchers',
    queryKey: 'purchase-vouchers',
    module: 'purchases',
    from: 'purchases',
    purpose: 'stock_purchase',
    voucherType: 'stock',
    showPoLinks: true,
    crumbRowId: 'ua-purchases-crumb-row',
    crumbSearchId: 'ua-purchases-crumb-search',
    filterStoragePrefix: 'ua.purchases.filters.v1',
    emptyTitle: 'No purchase vouchers',
    emptyBody: 'Stock purchase payment vouchers from classic Purchases will appear here.',
    createLabel: 'Create voucher',
    loadingLabel: 'Loading purchase vouchers…',
    searchAria: 'Search purchase vouchers',
    filterAria: 'Filter purchase vouchers',
    deskAria: 'Purchase vouchers',
    kpiAria: 'Purchases summary',
  },
  expenses: {
    title: 'Expenses',
    apiPath: '/expense-vouchers',
    queryKey: 'expense-vouchers',
    // Stay on Accounting sidebar (Expenses), not Payment Voucher / classic expenses.
    module: 'accounting',
    from: 'expenses',
    purpose: 'general',
    voucherType: 'general',
    showPoLinks: false,
    crumbRowId: 'ua-expenses-crumb-row',
    crumbSearchId: 'ua-expenses-crumb-search',
    filterStoragePrefix: 'ua.expenses.filters.v1',
    emptyTitle: 'No expense vouchers',
    emptyBody: 'General payment vouchers will appear here.',
    createLabel: 'Create expense',
    loadingLabel: 'Loading expense vouchers…',
    searchAria: 'Search expense vouchers',
    filterAria: 'Filter expense vouchers',
    deskAria: 'Expense vouchers',
    kpiAria: 'Expenses summary',
  },
}

function erpVoucherViewUrl(
  slug: string,
  voucherId: number,
  hintUrl = '',
  desk: DeskConfig,
  extra: Record<string, string> = {},
): string {
  const base = companyRootPath(slug, hintUrl).replace(/\/+$/, '')
  const params = new URLSearchParams({
    id: String(voucherId),
    from: desk.from,
    module: desk.module,
    ...extra,
  })
  return `${base}/employee/view-voucher.php?${params.toString()}`
}

function erpCreateVoucherUrl(slug: string, hintUrl = '', desk: DeskConfig): string {
  const base = companyRootPath(slug, hintUrl).replace(/\/+$/, '')
  return `${base}/employee/create-voucher.php?module=${encodeURIComponent(desk.module)}&from=${encodeURIComponent(desk.from)}&purpose=${encodeURIComponent(desk.purpose)}`
}

function erpVoucherPayContextUrl(slug: string, voucherId: number, hintUrl = '', desk: DeskConfig): string {
  const appRoot = appRootPath(slug, hintUrl).replace(/\/+$/, '')
  const params = new URLSearchParams({
    id: String(voucherId),
    from: desk.from,
    module: desk.module,
  })
  const cleanSlug = String(slug || '').trim().replace(/^\/+|\/+$/g, '')
  if (cleanSlug) params.set('company_slug', cleanSlug)
  return `${appRoot}/view-voucher-ui/api/init.php?${params.toString()}`
}

function resolveErpActionUrl(raw: string, slug: string, hintUrl = ''): string {
  const value = String(raw || '').trim()
  const companyRoot = companyRootPath(slug, hintUrl).replace(/\/+$/, '')
  const appRoot = appRootPath(slug, hintUrl).replace(/\/+$/, '')
  const cleanSlug = String(slug || '').trim().replace(/^\/+|\/+$/g, '')

  // Prefer tenant-scoped mark-paid when the company alias exists (e.g. /ultimate/mark-paid.php).
  if (!value) {
    return companyRoot ? `${companyRoot}/mark-paid.php` : `${appRoot}/employee/mark-paid.php`
  }

  let path = value
  if (/^https?:\/\//i.test(value)) {
    try {
      path = new URL(value).pathname
    } catch {
      return value
    }
  }

  const isEmployeeMarkPaid = /\/employee\/mark-paid\.php$/i.test(path)
  const isMarkPaid = /\/mark-paid\.php$/i.test(path)
  if (companyRoot && isMarkPaid) {
    // Ultimate ships /mark-paid.php alias; employee endpoint stays on app root + company_slug.
    if (isEmployeeMarkPaid) {
      const qs = cleanSlug ? `?company_slug=${encodeURIComponent(cleanSlug)}` : ''
      return `${appRoot}/employee/mark-paid.php${qs}`
    }
    return `${companyRoot}/mark-paid.php`
  }

  if (path.startsWith('/')) return path
  return `${appRoot}/${path.replace(/^\/+/, '')}`
}

type PurchasePayModalState = {
  voucher: PayVoucherPayload
  accounts: PayFinAccount[]
  priorPayments: PayPriorPayment[]
  actionUrl: string
  module: string
}

function MenuIcon({ children }: { children: React.ReactNode }) {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {children}
    </svg>
  )
}

function OpenIcon() {
  return (
    <MenuIcon>
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
      <circle cx="12" cy="12" r="3" />
    </MenuIcon>
  )
}

function PayIcon() {
  return (
    <MenuIcon>
      <rect x="2" y="5" width="20" height="14" rx="2" />
      <path d="M2 10h20" />
      <path d="M6 15h4" />
    </MenuIcon>
  )
}

function PrintIcon() {
  return (
    <MenuIcon>
      <path d="M6 9V2h12v7" />
      <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
      <path d="M6 14h12v8H6z" />
    </MenuIcon>
  )
}

function DownloadIcon() {
  return (
    <MenuIcon>
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <path d="M7 10l5 5 5-5" />
      <path d="M12 15V3" />
    </MenuIcon>
  )
}

function money(value: string | number | undefined) {
  const n = Number(value || 0)
  if (!Number.isFinite(n)) return '0.00'
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function currencyCode(value: string | null | undefined): string {
  const code = String(value || '').trim().toUpperCase()
  return code || 'TZS'
}

function moneyWithCurrency(
  value: string | number | undefined,
  currency: string | null | undefined = 'TZS',
) {
  return `${currencyCode(currency)} ${money(value)}`
}

function tzs(value: string | number | undefined) {
  return moneyWithCurrency(value, 'TZS')
}

function startOfToday(): Date {
  const d = new Date()
  d.setHours(0, 0, 0, 0)
  return d
}

function parseDateOnly(raw: string | null | undefined): Date | null {
  const s = String(raw || '').trim().slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return null
  const d = new Date(`${s}T12:00:00`)
  if (Number.isNaN(d.getTime())) return null
  d.setHours(0, 0, 0, 0)
  return d
}

function paymentBucket(voucher: PurchaseVoucher): 'unpaid' | 'partial' | 'paid' | 'other' {
  const status = String(voucher.status || voucher.display_status || '').toLowerCase()
  if (
    status === 'void' ||
    status === 'cancelled' ||
    status === 'canceled' ||
    status === 'draft' ||
    status === 'rejected'
  ) {
    return 'other'
  }
  if (Number(voucher.is_paid) === 1 || status === 'paid') {
    return 'paid'
  }
  const total = Number(voucher.total || 0)
  const paid = Number(voucher.amount_paid ?? 0)
  const balance = Number(voucher.balance_due ?? total - paid)
  if (total > 0 && balance <= 0.009) return 'paid'
  if (paid > 0.009 && balance > 0.009) return 'partial'
  return 'unpaid'
}

function paymentLabel(bucket: ReturnType<typeof paymentBucket>) {
  if (bucket === 'paid') return 'Paid'
  if (bucket === 'partial') return 'Partially paid'
  if (bucket === 'unpaid') return 'Unpaid'
  return '—'
}

function statusClass(bucket: ReturnType<typeof paymentBucket>) {
  if (bucket === 'paid') return 'ua-revenue-status ua-revenue-status--paid'
  if (bucket === 'partial') return 'ua-revenue-status ua-revenue-status--partial'
  if (bucket === 'unpaid') return 'ua-revenue-status ua-revenue-status--unpaid'
  return 'ua-revenue-status'
}

function monthRangeIso(ref = new Date()) {
  const y = ref.getFullYear()
  const m = ref.getMonth()
  const from = new Date(y, m, 1)
  const to = new Date(y, m + 1, 0)
  const iso = (d: Date) => {
    const yy = d.getFullYear()
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    return `${yy}-${mm}-${dd}`
  }
  return { from: iso(from), to: iso(to) }
}

type PurchasesFilterPrefs = {
  payFilter?: PayFilter
  dateFrom?: string
  dateTo?: string
  billQuery?: string
}

function deskFilterStorageKey(prefix: string, companyId: number | string | null | undefined) {
  return `${prefix}.${companyId || 'default'}`
}

function isPayFilter(value: unknown): value is PayFilter {
  return value === 'all' || value === 'unpaid' || value === 'partial' || value === 'paid'
}

function isIsoDate(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
}

function loadDeskFilterPrefs(
  prefix: string,
  companyId: number | string | null | undefined,
): PurchasesFilterPrefs {
  try {
    const raw = localStorage.getItem(deskFilterStorageKey(prefix, companyId))
    if (!raw) return {}
    const parsed = JSON.parse(raw) as PurchasesFilterPrefs
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    return {}
  }
}

function saveDeskFilterPrefs(
  prefix: string,
  companyId: number | string | null | undefined,
  prefs: PurchasesFilterPrefs,
) {
  try {
    localStorage.setItem(deskFilterStorageKey(prefix, companyId), JSON.stringify(prefs))
  } catch {
    // ignore quota / private mode
  }
}

function KpiIcon({ children }: { children: React.ReactNode }) {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      {children}
    </svg>
  )
}

function CoinsIcon() {
  return (
    <KpiIcon>
      <circle cx="8" cy="8" r="6" />
      <path d="M18.09 10.37A6 6 0 1 1 10.34 18" />
      <path d="M7 6h1v4" />
      <path d="M16.71 13.88l.7.71-2.82 2.82" />
    </KpiIcon>
  )
}

function PercentIcon() {
  return (
    <KpiIcon>
      <line x1="19" y1="5" x2="5" y2="19" />
      <circle cx="6.5" cy="6.5" r="2.5" />
      <circle cx="17.5" cy="17.5" r="2.5" />
    </KpiIcon>
  )
}

function WalletIcon() {
  return (
    <KpiIcon>
      <path d="M19 7V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1" />
      <path d="M3 10h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-8Z" />
      <path d="M17 14h.01" />
    </KpiIcon>
  )
}

function FileTextIcon() {
  return (
    <KpiIcon>
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <path d="M14 2v6h6" />
      <path d="M16 13H8" />
      <path d="M16 17H8" />
      <path d="M10 9H8" />
    </KpiIcon>
  )
}

function ClockIcon() {
  return (
    <KpiIcon>
      <circle cx="12" cy="12" r="10" />
      <path d="M12 6v6l4 2" />
    </KpiIcon>
  )
}

function AlertIcon() {
  return (
    <KpiIcon>
      <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
      <path d="M12 9v4" />
      <path d="M12 17h.01" />
    </KpiIcon>
  )
}

function FilterIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
      <line x1="4" y1="6" x2="20" y2="6" />
      <line x1="4" y1="12" x2="20" y2="12" />
      <line x1="4" y1="18" x2="20" y2="18" />
      <circle cx="8" cy="6" r="2.25" fill="#fff" />
      <circle cx="16" cy="12" r="2.25" fill="#fff" />
      <circle cx="12" cy="18" r="2.25" fill="#fff" />
    </svg>
  )
}

function PaymentVouchersDesk({ mode }: { mode: DeskMode }) {
  const desk = DESKS[mode]
  const { token, companyId, bookId, embed, classicAccountingUrl, hostedInErp } = useAuth()
  const queryClient = useQueryClient()
  const [error, setError] = useState('')
  const [crumbSearchHost, setCrumbSearchHost] = useState<HTMLElement | null>(null)

  const [payFilter, setPayFilter] = useState<PayFilter>('all')
  const [dateFrom, setDateFrom] = useState(() => monthRangeIso().from)
  const [dateTo, setDateTo] = useState(() => monthRangeIso().to)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [billQuery, setBillQuery] = useState('')
  const [filtersHydrated, setFiltersHydrated] = useState(false)
  const [openMenuId, setOpenMenuId] = useState<number | null>(null)
  const [payModal, setPayModal] = useState<PurchasePayModalState | null>(null)
  const [payLoadingId, setPayLoadingId] = useState<number | null>(null)
  const menuRef = useRef<HTMLDivElement | null>(null)
  const filterRef = useRef<HTMLDivElement | null>(null)
  const filterBtnRef = useRef<HTMLButtonElement | null>(null)
  const [filterPanelPos, setFilterPanelPos] = useState<{ top: number; right: number } | null>(null)

  useEffect(() => {
    const month = monthRangeIso()
    const saved = loadDeskFilterPrefs(desk.filterStoragePrefix, companyId)
    setPayFilter(isPayFilter(saved.payFilter) ? saved.payFilter : 'all')
    setDateFrom(isIsoDate(saved.dateFrom) ? saved.dateFrom : month.from)
    setDateTo(isIsoDate(saved.dateTo) ? saved.dateTo : month.to)
    setBillQuery(typeof saved.billQuery === 'string' ? saved.billQuery : '')
    setFiltersHydrated(true)
  }, [companyId, desk.filterStoragePrefix])

  useEffect(() => {
    if (!filtersHydrated) return
    saveDeskFilterPrefs(desk.filterStoragePrefix, companyId, {
      payFilter,
      dateFrom,
      dateTo,
      billQuery,
    })
  }, [filtersHydrated, companyId, desk.filterStoragePrefix, payFilter, dateFrom, dateTo, billQuery])

  useEffect(() => {
    if (!filtersOpen) {
      setFilterPanelPos(null)
      return
    }
    const place = () => {
      const btn = filterBtnRef.current
      if (!btn) return
      const rect = btn.getBoundingClientRect()
      setFilterPanelPos({
        top: rect.bottom + 8,
        right: Math.max(12, window.innerWidth - rect.right),
      })
    }
    place()
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => {
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [filtersOpen])

  useEffect(() => {
    if (openMenuId == null && !filtersOpen) return
    const onDoc = (e: MouseEvent) => {
      if (openMenuId != null && menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setOpenMenuId(null)
      }
      if (
        filtersOpen &&
        filterRef.current &&
        !filterRef.current.contains(e.target as Node) &&
        filterBtnRef.current &&
        !filterBtnRef.current.contains(e.target as Node)
      ) {
        setFiltersOpen(false)
      }
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return
      if (openMenuId != null) setOpenMenuId(null)
      else if (filtersOpen) setFiltersOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [openMenuId, filtersOpen])

  useEffect(() => {
    if (!hostedInErp) {
      setCrumbSearchHost(null)
      return
    }

    const crumb = document.getElementById('erp-auto-breadcrumb')
    if (!crumb?.parentElement) {
      setCrumbSearchHost(null)
      return
    }

    const crumbVisible = () => {
      const style = window.getComputedStyle(crumb)
      return style.display !== 'none' && style.visibility !== 'hidden'
    }

    let row = document.getElementById(desk.crumbRowId)
    let slot = document.getElementById(desk.crumbSearchId) as HTMLElement | null
    let createdRow = false

    if (!row) {
      row = document.createElement('div')
      row.id = desk.crumbRowId
      row.className = 'ua-revenue-crumb-row'
      crumb.parentElement.insertBefore(row, crumb)
      row.appendChild(crumb)
      slot = document.createElement('div')
      slot.id = desk.crumbSearchId
      slot.className = 'ua-revenue-crumb-search'
      row.appendChild(slot)
      createdRow = true
    } else if (!slot) {
      slot = document.createElement('div')
      slot.id = desk.crumbSearchId
      slot.className = 'ua-revenue-crumb-search'
      row.appendChild(slot)
    }

    const syncHost = () => setCrumbSearchHost(crumbVisible() && slot ? slot : null)
    syncHost()
    window.addEventListener('resize', syncHost)

    return () => {
      window.removeEventListener('resize', syncHost)
      if (createdRow && row?.parentElement && crumb.parentElement === row) {
        row.parentElement.insertBefore(crumb, row)
        row.remove()
      }
      setCrumbSearchHost(null)
    }
  }, [hostedInErp, desk.crumbRowId, desk.crumbSearchId])

  const vouchers = useQuery({
    queryKey: [desk.queryKey, companyId, bookId],
    enabled: Boolean(token && companyId),
    queryFn: () =>
      apiRequest<{ data: PurchaseVoucher[] }>(desk.apiPath, {
        token,
        companyId,
        bookId,
      }),
  })

  useEffect(() => {
    if (vouchers.isError) {
      setError((vouchers.error as Error)?.message || `Failed to load ${desk.title.toLowerCase()} vouchers.`)
    } else if (vouchers.isSuccess) {
      setError('')
    }
  }, [vouchers.isError, vouchers.isSuccess, vouchers.error, desk.title])

  const payableVouchers = useMemo(
    () => (vouchers.data?.data ?? []).filter((v) => paymentBucket(v) !== 'other'),
    [vouchers.data],
  )
  const dateFilteredVouchers = useMemo(() => {
    const from = dateFrom.trim()
    const to = dateTo.trim()
    if (!from && !to) return payableVouchers
    return payableVouchers.filter((voucher) => {
      const d = String(voucher.bill_date || '').slice(0, 10)
      if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return false
      if (from && d < from) return false
      if (to && d > to) return false
      return true
    })
  }, [payableVouchers, dateFrom, dateTo])
  const unpaidCount = useMemo(
    () => dateFilteredVouchers.filter((v) => paymentBucket(v) === 'unpaid').length,
    [dateFilteredVouchers],
  )
  const partialCount = useMemo(
    () => dateFilteredVouchers.filter((v) => paymentBucket(v) === 'partial').length,
    [dateFilteredVouchers],
  )
  const paidCount = useMemo(
    () => dateFilteredVouchers.filter((v) => paymentBucket(v) === 'paid').length,
    [dateFilteredVouchers],
  )
  const thisMonth = useMemo(() => monthRangeIso(), [])
  const hasDateFilter = Boolean(dateFrom.trim() || dateTo.trim())
  const isDefaultMonth = dateFrom === thisMonth.from && dateTo === thisMonth.to
  const hasActiveFilters = payFilter !== 'all' || (hasDateFilter && !isDefaultMonth)
  const filteredVouchers = useMemo(() => {
    const byStatus =
      payFilter === 'all'
        ? dateFilteredVouchers
        : dateFilteredVouchers.filter((v) => paymentBucket(v) === payFilter)
    const q = billQuery.trim().toLowerCase()
    if (!q) return byStatus
    return byStatus.filter((voucher) => {
      const hay = [
        voucher.bill_number,
        voucher.voucher_number,
        voucher.voucher_no_official,
        voucher.supplier?.name,
        voucher.description,
        voucher.payment_method_label,
        voucher.linked_po_label,
        ...(voucher.linked_po_numbers || []),
        String(voucher.bill_date || '').slice(0, 10),
        String(voucher.total || ''),
        String(voucher.balance_due || ''),
        paymentLabel(paymentBucket(voucher)),
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return hay.includes(q)
    })
  }, [dateFilteredVouchers, payFilter, billQuery])

  const kpi = useMemo(() => {
    let exclusive = 0
    let vat = 0
    let gross = 0
    let outstanding = 0
    let overdue = 0
    const today = startOfToday()

    for (const row of filteredVouchers) {
      const total = Number(row.total) || 0
      let vatAmt = Math.max(0, Number(row.tax_total) || 0)
      let exclusiveAmt = Number(row.subtotal)
      if (!Number.isFinite(exclusiveAmt) || (exclusiveAmt <= 0.009 && total > 0.009)) {
        exclusiveAmt = Math.max(0, total - vatAmt)
      }
      if (vatAmt <= 0.009 && total > exclusiveAmt + 0.009) {
        vatAmt = Math.max(0, total - exclusiveAmt)
      }
      exclusive += exclusiveAmt
      vat += vatAmt
      gross += total

      const bal = Number(row.balance_due) || 0
      if (bal > 0.009) {
        outstanding += bal
        const due = parseDateOnly(row.due_date)
        if (due && due < today) overdue += bal
      }
    }

    return {
      exclusive,
      vat,
      gross,
      voucherTotal: gross,
      voucherCount: filteredVouchers.length,
      outstanding,
      overdue,
    }
  }, [filteredVouchers])

  function voucherHref(voucher: PurchaseVoucher, extra: Record<string, string> = {}) {
    const pvId = Number(voucher.pv_id || voucher.id || 0)
    if (pvId <= 0) return ''
    const slug = resolveCompanySlug(embed.erpCompanySlug)
    const hint = classicAccountingUrl || embed.backUrl || ''
    return erpVoucherViewUrl(slug, pvId, hint, desk, extra)
  }

  function goToVoucher(voucher: PurchaseVoucher, extra: Record<string, string> = {}) {
    setOpenMenuId(null)
    const href = voucherHref(voucher, extra)
    if (href) window.location.href = href
  }

  function openVoucher(voucher: PurchaseVoucher) {
    goToVoucher(voucher)
  }

  async function payVoucher(voucher: PurchaseVoucher) {
    setOpenMenuId(null)
    const pvId = Number(voucher.pv_id || voucher.id || 0)
    if (pvId <= 0) {
      setError('Could not open payment for this voucher.')
      return
    }
    const slug = resolveCompanySlug(embed.erpCompanySlug)
    const hint = classicAccountingUrl || embed.backUrl || ''
    setPayLoadingId(pvId)
    setError('')
    try {
      const res = await fetch(erpVoucherPayContextUrl(slug, pvId, hint, desk), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
      const rawText = await res.text()
      let json: { ok?: boolean; data?: Record<string, unknown>; error?: string } | null = null
      try {
        json = JSON.parse(rawText)
      } catch {
        const looksHtml = /^\s*</.test(rawText)
        throw new Error(
          looksHtml
            ? res.status === 401 || /login/i.test(rawText)
              ? 'Please sign in again, then retry Pay.'
              : 'Could not load payment details from the voucher service.'
            : 'Could not load payment details.',
        )
      }
      if (!json?.ok || !json?.data) {
        throw new Error(json?.error || 'Could not load payment details.')
      }
      const data = json.data as {
        permissions?: { canMarkPaid?: boolean }
        voucher?: {
          id?: number
          voucher_no?: string
          payee_name?: string
          currency?: string
          total_amount?: number | string
          paid_so_far?: number | string
          voucher_type?: string
          is_paid?: boolean | number
        }
        finAccounts?: PayFinAccount[]
        paymentDetails?: PayPriorPayment[]
        actions?: { markPaidUrl?: string }
      }
      if (!data.permissions?.canMarkPaid) {
        throw new Error(
          data.voucher?.is_paid
            ? 'This voucher is already paid.'
            : 'This voucher must be approved before it can be marked paid.',
        )
      }
      const payload: PayVoucherPayload = {
        id: Number(data.voucher?.id || pvId),
        voucher_no: String(
          data.voucher?.voucher_no ||
            voucher.voucher_no_official ||
            voucher.voucher_number ||
            voucher.bill_number ||
            `PV-${pvId}`,
        ),
        payee_name: String(data.voucher?.payee_name || voucher.supplier?.name || '—'),
        currency: String(data.voucher?.currency || voucher.currency_code || 'TZS'),
        total_amount: data.voucher?.total_amount ?? voucher.total,
        paid_so_far: data.voucher?.paid_so_far ?? voucher.amount_paid,
        voucher_type: String(data.voucher?.voucher_type || desk.voucherType),
      }
      const accounts = Array.isArray(data.finAccounts) ? data.finAccounts : []
      if (!accounts.length) {
        throw new Error('No active cash, bank, or mobile-money accounts are available to pay from.')
      }
      const priorPayments = Array.isArray(data.paymentDetails) ? data.paymentDetails : []
      const actionUrl = resolveErpActionUrl(String(data.actions?.markPaidUrl || ''), slug, hint)
      if (!actionUrl) {
        throw new Error('Could not resolve the mark-paid endpoint for this company.')
      }
      setPayModal({ voucher: payload, accounts, priorPayments, actionUrl, module: desk.module })
    } catch (err) {
      setError((err as Error).message || 'Could not open payment dialog.')
    } finally {
      setPayLoadingId(null)
    }
  }

  function printVoucher(voucher: PurchaseVoucher) {
    goToVoucher(voucher, { print: '1' })
  }

  function downloadVoucher(voucher: PurchaseVoucher) {
    goToVoucher(voucher, { download: '1' })
  }

  function createVoucher() {
    const slug = resolveCompanySlug(embed.erpCompanySlug)
    const hint = classicAccountingUrl || embed.backUrl || ''
    window.location.href = erpCreateVoucherUrl(slug, hint, desk)
  }

  const searchField = (
    <input
      type="search"
      className="ua-revenue-search"
      value={billQuery}
      onChange={(e) => setBillQuery(e.target.value)}
      placeholder="Search number, payee, date…"
      aria-label={desk.searchAria}
    />
  )

  const filterOptions = [
    ['all', 'All', dateFilteredVouchers.length],
    ['unpaid', 'Unpaid', unpaidCount],
    ['partial', 'Partial', partialCount],
    ['paid', 'Paid', paidCount],
  ] as const

  const clearFilters = () => {
    const month = monthRangeIso()
    setPayFilter('all')
    setDateFrom(month.from)
    setDateTo(month.to)
  }

  return (
    <div className="ua-revenue">
      <PageHeader
        title={desk.title}
        breadcrumb={
          crumbSearchHost ? undefined : (
            <>
              <span>Accounting</span>
              <span className="ua-breadcrumb__sep" aria-hidden="true">
                /
              </span>
              <span className="ua-breadcrumb__current">{desk.title}</span>
            </>
          )
        }
        crumbExtra={
          crumbSearchHost ? undefined : (
            <div className="ua-revenue-search-wrap ua-revenue-search-wrap--crumb">{searchField}</div>
          )
        }
        actions={
          <div className="ua-revenue-header-actions">
            <div className={`ua-revenue-filter-dropdown${filtersOpen ? ' is-open' : ''}`}>
              <button
                ref={filterBtnRef}
                type="button"
                className={`ua-revenue-filter-btn${filtersOpen || hasActiveFilters ? ' is-active' : ''}`}
                aria-label="Filters"
                title="Filters"
                aria-expanded={filtersOpen}
                aria-haspopup="dialog"
                onClick={() => setFiltersOpen((open) => !open)}
              >
                <FilterIcon />
                {hasActiveFilters ? <span className="ua-revenue-filter-dot" aria-hidden="true" /> : null}
              </button>
            </div>
            <Button type="button" onClick={createVoucher}>
              {desk.createLabel}
            </Button>
          </div>
        }
      />
      {filtersOpen && filterPanelPos
        ? createPortal(
            <div
              ref={filterRef}
              className="ua-revenue-filter-panel"
              role="dialog"
              aria-label={desk.filterAria}
              style={{ top: filterPanelPos.top, right: filterPanelPos.right }}
            >
              <div className="ua-revenue-filter-panel__head">
                <div className="ua-revenue-filter-panel__heading">Filters</div>
                <button
                  type="button"
                  className="ua-revenue-filter-clear"
                  disabled={!hasActiveFilters && isDefaultMonth}
                  onClick={clearFilters}
                >
                  Reset
                </button>
              </div>

              <div className="ua-revenue-filter-panel__title">Date range</div>
              <div className="ua-revenue-filter-dates">
                <div>
                  <Label>From</Label>
                  <Input
                    type="date"
                    value={dateFrom}
                    max={dateTo || undefined}
                    onChange={(e) => setDateFrom(e.target.value)}
                  />
                </div>
                <div>
                  <Label>To</Label>
                  <Input
                    type="date"
                    value={dateTo}
                    min={dateFrom || undefined}
                    onChange={(e) => setDateTo(e.target.value)}
                  />
                </div>
              </div>

              <div className="ua-revenue-filter-panel__title">Payment status</div>
              <div className="ua-revenue-filter-panel__options">
                {filterOptions.map(([key, label, count]) => (
                  <button
                    key={key}
                    type="button"
                    className={`ua-revenue-filter-option${payFilter === key ? ' is-active' : ''}`}
                    onClick={() => setPayFilter(key)}
                  >
                    <span>{label}</span>
                    <span className="ua-revenue-filter-option__count">{count}</span>
                  </button>
                ))}
              </div>

              <div className="ua-revenue-filter-panel__footer">
                <Button type="button" className="w-full" onClick={() => setFiltersOpen(false)}>
                  Done
                </Button>
              </div>
            </div>,
            document.body,
          )
        : null}
      {crumbSearchHost
        ? createPortal(
            <div className="ua-revenue-search-wrap ua-revenue-search-wrap--crumb">{searchField}</div>,
            crumbSearchHost,
          )
        : null}
      {error ? <ErrorBanner message={error} /> : null}

      <section className="ua-revenue-kpis" aria-label={desk.kpiAria}>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Exclusive</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.exclusive)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--violet" aria-hidden="true">
            <CoinsIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">VAT</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.vat)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--indigo" aria-hidden="true">
            <PercentIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Gross</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.gross)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--teal" aria-hidden="true">
            <WalletIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">
              Vouchers{kpi.voucherCount ? ` · ${kpi.voucherCount.toLocaleString('en-US')}` : ''}
            </span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.voucherTotal)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--blue" aria-hidden="true">
            <FileTextIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Outstanding</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.outstanding)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--amber" aria-hidden="true">
            <ClockIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Overdue</span>
            <strong className="ua-revenue-kpi__value ua-revenue-kpi__value--rose">{tzs(kpi.overdue)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--rose" aria-hidden="true">
            <AlertIcon />
          </span>
        </article>
      </section>

      <section className="ua-revenue-desk" aria-label={desk.deskAria}>
        <div className="ua-revenue-desk__toolbar">
          <div className="ua-revenue-desk__heading">
            <h2 className="ua-revenue-desk__title">Payment vouchers</h2>
            <p className="ua-revenue-desk__meta">
              {filteredVouchers.length.toLocaleString('en-US')} shown
              {payFilter !== 'all' ? ` · ${paymentLabel(payFilter === 'partial' ? 'partial' : payFilter)}` : ''}
              {hasDateFilter
                ? ` · ${dateFrom || '…'} → ${dateTo || '…'}`
                : payFilter === 'all' && !billQuery.trim()
                  ? ` · ${unpaidCount} unpaid · ${partialCount} partial · ${paidCount} paid`
                  : ''}
            </p>
          </div>
        </div>

        <div className="ua-revenue-desk__body">
          {vouchers.isLoading ? (
            <div className="ua-revenue-table-card ua-revenue-loading">{desk.loadingLabel}</div>
          ) : null}
          {!vouchers.isLoading && filteredVouchers.length ? (
            <div className="ua-revenue-table-card">
              <table className="ua-revenue-table ua-revenue-table--full">
                <colgroup>
                  <col className="ua-revenue-col-number" />
                  <col className="ua-revenue-col-customer" />
                  <col className="ua-revenue-col-money" />
                  <col className="ua-revenue-col-money" />
                  <col className="ua-revenue-col-status" />
                  <col className="ua-revenue-col-actions" />
                </colgroup>
                <thead>
                  <tr>
                    <th scope="col">Number</th>
                    <th scope="col">Payee</th>
                    <th scope="col" className="is-num">
                      Total
                    </th>
                    <th scope="col" className="is-num">
                      Balance
                    </th>
                    <th scope="col">Status</th>
                    <th scope="col">
                      <span className="sr-only">Actions</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {filteredVouchers.map((voucher) => {
                    const bucket = paymentBucket(voucher)
                    const voucherStatus = String(voucher.status || voucher.display_status || '').toLowerCase()
                    const canPay =
                      (bucket === 'unpaid' || bucket === 'partial') && voucherStatus === 'approved'
                    const payBlockedReason =
                      bucket === 'unpaid' || bucket === 'partial'
                        ? voucherStatus === 'approved'
                          ? ''
                          : voucherStatus
                            ? `Awaiting approval (${voucherStatus})`
                            : 'Awaiting approval'
                        : ''
                    const menuOpen = openMenuId === voucher.id
                    const number = voucher.voucher_number || voucher.bill_number
                    return (
                      <tr key={voucher.id} className="ua-revenue-row" onClick={() => openVoucher(voucher)}>
                        <td className="ua-revenue-cell ua-revenue-cell--doc">
                          <div className="ua-revenue-docnum">{number}</div>
                          <div className="ua-revenue-docdate">{String(voucher.bill_date).slice(0, 10)}</div>
                        </td>
                        <td className="ua-revenue-cell ua-revenue-cell--customer" title={voucher.supplier?.name || ''}>
                          <div className="ua-revenue-payee">{voucher.supplier?.name || '—'}</div>
                          {desk.showPoLinks && voucher.linked_po_label ? (
                            <div className="ua-revenue-po-link" title={voucher.linked_po_label}>
                              PO · {voucher.linked_po_label}
                            </div>
                          ) : null}
                        </td>
                        <td className="ua-revenue-cell ua-revenue-cell--money ua-revenue-cell--total" data-label="Total">
                          <div className="ua-revenue-amount-stack">
                            <span className="ua-revenue-amount">
                              {moneyWithCurrency(voucher.total, voucher.currency_code)}
                            </span>
                            <span className="ua-revenue-amount-sub ua-revenue-amount-sub--mobile">
                              Balance{' '}
                              <span
                                className={
                                  Number(voucher.balance_due) > 0.009
                                    ? 'ua-revenue-cell--balance-due'
                                    : 'ua-revenue-cell--balance-clear'
                                }
                              >
                                {moneyWithCurrency(voucher.balance_due, voucher.currency_code)}
                              </span>
                            </span>
                          </div>
                        </td>
                        <td
                          className={`ua-revenue-cell ua-revenue-cell--money ua-revenue-cell--balance${
                            Number(voucher.balance_due) > 0.009
                              ? ' ua-revenue-cell--balance-due'
                              : ' ua-revenue-cell--balance-clear'
                          }`}
                          data-label="Balance"
                        >
                          {moneyWithCurrency(voucher.balance_due, voucher.currency_code)}
                        </td>
                        <td className="ua-revenue-cell ua-revenue-cell--status">
                          <span className={statusClass(bucket)}>{paymentLabel(bucket)}</span>
                        </td>
                        <td
                          className="ua-revenue-cell ua-revenue-cell--actions"
                          onClick={(e) => e.stopPropagation()}
                        >
                          <div className="ua-revenue-menu" ref={menuOpen ? menuRef : undefined}>
                            <button
                              type="button"
                              className="ua-revenue-menu__btn"
                              aria-label={`Actions for ${number}`}
                              aria-expanded={menuOpen}
                              aria-haspopup="menu"
                              onClick={() => setOpenMenuId(menuOpen ? null : voucher.id)}
                            >
                              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <circle cx="12" cy="5" r="1.8" />
                                <circle cx="12" cy="12" r="1.8" />
                                <circle cx="12" cy="19" r="1.8" />
                              </svg>
                            </button>
                            {menuOpen ? (
                              <div role="menu" className="ua-revenue-menu__panel ua-revenue-menu__panel--rich" aria-label={`${number} actions`}>
                                <button
                                  type="button"
                                  role="menuitem"
                                  className="ua-revenue-menu__item ua-revenue-menu__item--rich"
                                  onClick={() => openVoucher(voucher)}
                                >
                                  <span className="ua-revenue-menu__icon">
                                    <OpenIcon />
                                  </span>
                                  <span className="ua-revenue-menu__copy">
                                    <span className="ua-revenue-menu__label">Open</span>
                                    <span className="ua-revenue-menu__hint">View voucher</span>
                                  </span>
                                </button>

                                <div className="ua-revenue-menu__sep" role="separator" />

                                {canPay ? (
                                  <button
                                    type="button"
                                    role="menuitem"
                                    className="ua-revenue-menu__item ua-revenue-menu__item--rich ua-revenue-menu__item--pay"
                                    disabled={payLoadingId === voucher.id}
                                    onClick={() => payVoucher(voucher)}
                                  >
                                    <span className="ua-revenue-menu__icon">
                                      <PayIcon />
                                    </span>
                                    <span className="ua-revenue-menu__copy">
                                      <span className="ua-revenue-menu__label">
                                        {payLoadingId === voucher.id ? 'Opening…' : 'Pay'}
                                      </span>
                                      <span className="ua-revenue-menu__hint">Mark as paid</span>
                                    </span>
                                  </button>
                                ) : (
                                  <div className="ua-revenue-menu__item ua-revenue-menu__item--rich ua-revenue-menu__item--muted" role="menuitem" aria-disabled="true">
                                    <span className="ua-revenue-menu__icon">
                                      <PayIcon />
                                    </span>
                                    <span className="ua-revenue-menu__copy">
                                      <span className="ua-revenue-menu__label">
                                        {bucket === 'paid' ? 'Already paid' : payBlockedReason || 'Pay unavailable'}
                                      </span>
                                      {payBlockedReason ? (
                                        <span className="ua-revenue-menu__hint">Approve voucher before paying</span>
                                      ) : null}
                                    </span>
                                  </div>
                                )}

                                <button
                                  type="button"
                                  role="menuitem"
                                  className="ua-revenue-menu__item ua-revenue-menu__item--rich"
                                  onClick={() => printVoucher(voucher)}
                                >
                                  <span className="ua-revenue-menu__icon">
                                    <PrintIcon />
                                  </span>
                                  <span className="ua-revenue-menu__copy">
                                    <span className="ua-revenue-menu__label">Print</span>
                                    <span className="ua-revenue-menu__hint">Print voucher</span>
                                  </span>
                                </button>

                                <button
                                  type="button"
                                  role="menuitem"
                                  className="ua-revenue-menu__item ua-revenue-menu__item--rich"
                                  onClick={() => downloadVoucher(voucher)}
                                >
                                  <span className="ua-revenue-menu__icon">
                                    <DownloadIcon />
                                  </span>
                                  <span className="ua-revenue-menu__copy">
                                    <span className="ua-revenue-menu__label">Download</span>
                                    <span className="ua-revenue-menu__hint">Save PDF</span>
                                  </span>
                                </button>
                              </div>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          ) : null}
          {!vouchers.isLoading && !filteredVouchers.length ? (
            <div className="ua-revenue-empty-wrap">
              <EmptyState
                title={desk.emptyTitle}
                body={
                  payFilter !== 'all' || billQuery.trim() || (hasDateFilter && !isDefaultMonth)
                    ? 'Try clearing filters or widening the date range.'
                    : desk.emptyBody
                }
              />
              <div className="ua-revenue-empty-actions">
                <Button type="button" onClick={createVoucher}>
                  {desk.createLabel}
                </Button>
              </div>
            </div>
          ) : null}
        </div>
      </section>

      {payModal ? (
        <PurchaseMarkPaidModal
          voucher={payModal.voucher}
          accounts={payModal.accounts}
          priorPayments={payModal.priorPayments}
          actionUrl={payModal.actionUrl}
          module={payModal.module}
          onClose={() => setPayModal(null)}
          onSuccess={async () => {
            setPayModal(null)
            await queryClient.invalidateQueries({ queryKey: [desk.queryKey, companyId, bookId] })
          }}
        />
      ) : null}
    </div>
  )
}

export function PurchasesPage() {
  return <PaymentVouchersDesk mode="purchases" />
}

export function ExpensesPage() {
  return <PaymentVouchersDesk mode="expenses" />
}
