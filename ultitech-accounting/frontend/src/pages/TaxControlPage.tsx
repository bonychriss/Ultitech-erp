import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { createPortal } from 'react-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Button, EmptyState, ErrorBanner, Input, Label, PageHeader, Select } from '../components/ui'

type TaxType = { id: number; code: string; name: string }
type TaxRate = {
  id: number
  code?: string
  name?: string
  rate_percent?: string | number
  effective_from?: string
  effective_to?: string | null
  tax_type?: TaxType | null
}
type TaxCode = {
  id: number
  code: string
  name: string
  applies_to?: string
  tax_rate?: { rate_percent?: string | number; tax_type?: TaxType | null } | null
}
type TaxTxn = {
  id: number
  tax_date?: string
  direction?: string
  taxable_amount?: string | number
  tax_amount?: string | number
  rate_percent?: string | number
  source_type?: string
  source_id?: number | string
  tax_code?: { code?: string; name?: string } | null
}
type TaxReturnRow = {
  id: number
  return_number?: string
  period_start?: string
  period_end?: string
  output_tax?: string | number
  input_tax?: string | number
  net_tax?: string | number
  status?: string
  filing_reference?: string | null
  filing_date?: string | null
  payment_amount?: string | number | null
  payment_date?: string | null
  period_locked?: boolean
}

type DeskTab = 'returns' | 'transactions' | 'rates' | 'codes'
type StatusFilter = 'all' | 'draft' | 'reconciled' | 'filed' | 'paid' | 'other'
type BankAccount = { id: number; code: string; name: string }

function money(value: string | number | undefined) {
  const n = Number(value || 0)
  if (!Number.isFinite(n)) return '0.00'
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function tzs(value: string | number | undefined) {
  return `TZS ${money(value)}`
}

function formatPeriodDate(value: string | null | undefined) {
  const raw = String(value || '').slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) return ''
  const d = new Date(`${raw}T12:00:00`)
  if (Number.isNaN(d.getTime())) return raw
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

function quarterRangeIso(ref = new Date()) {
  const y = ref.getFullYear()
  const q = Math.floor(ref.getMonth() / 3)
  const from = new Date(y, q * 3, 1)
  const to = new Date(y, q * 3 + 3, 0)
  const iso = (d: Date) => {
    const yy = d.getFullYear()
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    return `${yy}-${mm}-${dd}`
  }
  return { from: iso(from), to: iso(to) }
}

function statusBucket(status: string | undefined): StatusFilter {
  const s = String(status || '').toLowerCase()
  if (s === 'draft') return 'draft'
  if (s === 'reconciled' || s === 'reconcile') return 'reconciled'
  if (s === 'filed' || s === 'submitted') return 'filed'
  if (s === 'paid' || s === 'closed') return 'paid'
  return 'other'
}

function statusLabel(status: string | undefined) {
  const s = String(status || '').toLowerCase()
  if (!s) return '—'
  return s.charAt(0).toUpperCase() + s.slice(1)
}

function statusClass(status: string | undefined) {
  const bucket = statusBucket(status)
  if (bucket === 'paid' || bucket === 'filed') return 'ua-revenue-status ua-revenue-status--paid'
  if (bucket === 'reconciled') return 'ua-revenue-status ua-revenue-status--partial'
  if (bucket === 'draft') return 'ua-revenue-status ua-revenue-status--unpaid'
  return 'ua-revenue-status'
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
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

function OutputIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M12 19V5" />
      <path d="M5 12l7-7 7 7" />
    </svg>
  )
}

function InputIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M12 5v14" />
      <path d="M19 12l-7 7-7-7" />
    </svg>
  )
}

function NetIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M4 12h16" />
      <path d="M12 4v16" />
      <circle cx="12" cy="12" r="9" />
    </svg>
  )
}

function ReturnsIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <path d="M14 2v6h6" />
      <path d="M8 13h8" />
      <path d="M8 17h5" />
    </svg>
  )
}

export function TaxControlPage() {
  const { token, companyId } = useAuth()
  const client = useQueryClient()
  const ready = Boolean(token && companyId)

  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [tab, setTab] = useState<DeskTab>('transactions')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [filterPanelPos, setFilterPanelPos] = useState<{ top: number; right: number } | null>(null)
  const filterBtnRef = useRef<HTMLButtonElement | null>(null)
  const filterRef = useRef<HTMLDivElement | null>(null)

  const quarter = quarterRangeIso()
  const [prepareOpen, setPrepareOpen] = useState(false)
  const [taxTypeId, setTaxTypeId] = useState('')
  const [periodStart, setPeriodStart] = useState(quarter.from)
  const [periodEnd, setPeriodEnd] = useState(quarter.to)

  const [fileOpen, setFileOpen] = useState(false)
  const [fileTarget, setFileTarget] = useState<TaxReturnRow | null>(null)
  const [filingDate, setFilingDate] = useState(todayIso())
  const [filingReference, setFilingReference] = useState('')

  const [payOpen, setPayOpen] = useState(false)
  const [payTarget, setPayTarget] = useState<TaxReturnRow | null>(null)
  const [paymentDate, setPaymentDate] = useState(todayIso())
  const [paymentAmount, setPaymentAmount] = useState('')
  const [bankAccountId, setBankAccountId] = useState('')

  const [adjustOpen, setAdjustOpen] = useState(false)
  const [adjDirection, setAdjDirection] = useState<'output' | 'input'>('output')
  const [adjDate, setAdjDate] = useState(todayIso())
  const [adjTaxable, setAdjTaxable] = useState('')
  const [adjTax, setAdjTax] = useState('')
  const [adjMemo, setAdjMemo] = useState('')

  const types = useQuery({
    queryKey: ['tax-types', companyId],
    enabled: ready,
    queryFn: () => apiRequest<{ data: TaxType[] }>('/tax-types', { token, companyId }),
  })
  const rates = useQuery({
    queryKey: ['tax-rates', companyId],
    enabled: ready,
    queryFn: () => apiRequest<{ data: TaxRate[] }>('/tax-rates', { token, companyId }),
  })
  const codes = useQuery({
    queryKey: ['tax-codes', companyId],
    enabled: ready,
    queryFn: () => apiRequest<{ data: TaxCode[] }>('/tax-codes', { token, companyId }),
  })
  const txns = useQuery({
    queryKey: ['tax-transactions', companyId],
    enabled: ready,
    queryFn: () =>
      apiRequest<{
        data: TaxTxn[]
        summary?: {
          output_tax?: number
          input_tax?: number
          net_tax?: number
          count?: number
          period_from?: string | null
          period_to?: string | null
        }
      }>('/tax-transactions', { token, companyId }),
  })
  const returns = useQuery({
    queryKey: ['tax-returns', companyId],
    enabled: ready,
    queryFn: () => apiRequest<{ data: TaxReturnRow[] }>('/tax-returns', { token, companyId }),
  })

  const prepare = useMutation({
    mutationFn: () =>
      apiRequest('/tax-returns', {
        method: 'POST',
        token,
        companyId,
        body: {
          tax_type_id: Number(taxTypeId),
          period_start: periodStart,
          period_end: periodEnd,
        },
      }),
    onSuccess: async () => {
      setError('')
      setPrepareOpen(false)
      setTab('returns')
      await client.invalidateQueries({ queryKey: ['tax-returns', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const reconcile = useMutation({
    mutationFn: (id: number) =>
      apiRequest(`/tax-returns/${id}/reconcile`, { method: 'POST', token, companyId, body: {} }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({ queryKey: ['tax-returns', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const banks = useQuery({
    queryKey: ['bank-accounts', companyId],
    enabled: ready && (payOpen || fileOpen),
    queryFn: () => apiRequest<{ data: BankAccount[] }>('/bank-accounts', { token, companyId }),
  })

  // Keep the tax register current without a manual Sync button.
  useEffect(() => {
    if (!ready || !token || !companyId) return
    let cancelled = false
    ;(async () => {
      try {
        await apiRequest('/tax/sync-register', { method: 'POST', token, companyId, body: {} })
        if (cancelled) return
        await Promise.all([
          client.invalidateQueries({ queryKey: ['tax-transactions', companyId] }),
          client.invalidateQueries({ queryKey: ['tax-returns', companyId] }),
          client.invalidateQueries({ queryKey: ['tax-codes', companyId] }),
          client.invalidateQueries({ queryKey: ['tax-rates', companyId] }),
        ])
      } catch (err) {
        if (!cancelled) {
          setError(`Tax register sync failed: ${(err as Error).message}`)
        }
      }
    })()
    return () => {
      cancelled = true
    }
  }, [ready, token, companyId, client])

  const fileReturn = useMutation({
    mutationFn: () =>
      apiRequest(`/tax-returns/${fileTarget?.id}/file`, {
        method: 'POST',
        token,
        companyId,
        body: { filing_date: filingDate, filing_reference: filingReference },
      }),
    onSuccess: async () => {
      setError('')
      setFileOpen(false)
      setFileTarget(null)
      await client.invalidateQueries({ queryKey: ['tax-returns', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const payReturn = useMutation({
    mutationFn: () =>
      apiRequest(`/tax-returns/${payTarget?.id}/pay`, {
        method: 'POST',
        token,
        companyId,
        body: {
          payment_date: paymentDate,
          amount: Number(paymentAmount),
          bank_account_id: Number(bankAccountId),
          currency_code: 'TZS',
        },
      }),
    onSuccess: async () => {
      setError('')
      setPayOpen(false)
      setPayTarget(null)
      await client.invalidateQueries({ queryKey: ['tax-returns', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const adjust = useMutation({
    mutationFn: () =>
      apiRequest('/tax/adjustments', {
        method: 'POST',
        token,
        companyId,
        body: {
          direction: adjDirection,
          tax_date: adjDate,
          taxable_amount: Number(adjTaxable || 0),
          tax_amount: Number(adjTax),
          memo: adjMemo || 'VAT adjustment',
          supply_category: 'adjustment',
        },
      }),
    onSuccess: async () => {
      setError('')
      setAdjustOpen(false)
      setAdjTaxable('')
      setAdjTax('')
      setAdjMemo('')
      setTab('transactions')
      await client.invalidateQueries({ queryKey: ['tax-transactions', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const returnsList = returns.data?.data ?? []
  const txnsList = txns.data?.data ?? []
  const ratesList = rates.data?.data ?? []
  const codesList = codes.data?.data ?? []

  const kpi = useMemo(() => {
    const summary = txns.data?.summary
    let output = 0
    let input = 0
    let periodFrom = summary?.period_from ? String(summary.period_from).slice(0, 10) : ''
    let periodTo = summary?.period_to ? String(summary.period_to).slice(0, 10) : ''
    if (summary && (summary.output_tax != null || summary.input_tax != null)) {
      output = Number(summary.output_tax || 0)
      input = Number(summary.input_tax || 0)
    } else {
      for (const t of txnsList) {
        const amt = Number(t.tax_amount || 0)
        if (!Number.isFinite(amt)) continue
        const dir = String(t.direction || '').toLowerCase()
        if (dir === 'input' || dir === 'purchase' || dir === 'purchases') input += amt
        else output += amt
        const d = String(t.tax_date || '').slice(0, 10)
        if (/^\d{4}-\d{2}-\d{2}$/.test(d)) {
          if (!periodFrom || d < periodFrom) periodFrom = d
          if (!periodTo || d > periodTo) periodTo = d
        }
      }
    }
    return {
      output,
      input,
      net: summary?.net_tax != null ? Number(summary.net_tax) : output - input,
      returns: returnsList.length,
      drafts: returnsList.filter((r) => statusBucket(r.status) === 'draft').length,
      txns: summary?.count != null ? Number(summary.count) : txnsList.length,
      periodFrom,
      periodTo,
    }
  }, [returnsList, txnsList, txns.data?.summary])

  const kpiPeriodLabel =
    kpi.periodFrom && kpi.periodTo
      ? kpi.periodFrom === kpi.periodTo
        ? `Register totals for ${formatPeriodDate(kpi.periodFrom)}`
        : `Register totals from ${formatPeriodDate(kpi.periodFrom)} → ${formatPeriodDate(kpi.periodTo)}`
      : 'Register totals'

  const q = search.trim().toLowerCase()

  const filteredReturns = useMemo(() => {
    return returnsList.filter((r) => {
      if (statusFilter !== 'all' && statusBucket(r.status) !== statusFilter) return false
      if (!q) return true
      const hay = `${r.return_number || ''} ${r.period_start || ''} ${r.period_end || ''} ${r.status || ''}`.toLowerCase()
      return hay.includes(q)
    })
  }, [returnsList, statusFilter, q])

  const filteredTxns = useMemo(() => {
    if (!q) return txnsList
    return txnsList.filter((t) => {
      const hay = `${t.tax_date || ''} ${t.direction || ''} ${t.source_type || ''} ${t.source_id || ''} ${t.tax_code?.code || ''} ${t.tax_code?.name || ''}`.toLowerCase()
      return hay.includes(q)
    })
  }, [txnsList, q])

  const filteredRates = useMemo(() => {
    if (!q) return ratesList
    return ratesList.filter((r) => {
      const hay = `${r.code || ''} ${r.name || ''} ${r.tax_type?.code || ''} ${r.tax_type?.name || ''}`.toLowerCase()
      return hay.includes(q)
    })
  }, [ratesList, q])

  const filteredCodes = useMemo(() => {
    if (!q) return codesList
    return codesList.filter((c) => {
      const hay = `${c.code || ''} ${c.name || ''} ${c.applies_to || ''}`.toLowerCase()
      return hay.includes(q)
    })
  }, [codesList, q])

  const hasActiveFilters = statusFilter !== 'all' || tab !== 'transactions'

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
    if (!filtersOpen) return
    const onDoc = (e: MouseEvent) => {
      const t = e.target as Node
      if (filterRef.current?.contains(t) || filterBtnRef.current?.contains(t)) return
      setFiltersOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setFiltersOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [filtersOpen])

  useEffect(() => {
    if (!prepareOpen) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !prepare.isPending) setPrepareOpen(false)
    }
    document.addEventListener('keydown', onKey)
    document.documentElement.classList.add('ua-modal-open')
    document.body.classList.add('ua-modal-open')
    return () => {
      document.removeEventListener('keydown', onKey)
      document.documentElement.classList.remove('ua-modal-open')
      document.body.classList.remove('ua-modal-open')
    }
  }, [prepareOpen, prepare.isPending])

  const openPrepare = () => {
    setError('')
    const range = quarterRangeIso()
    setPeriodStart(range.from)
    setPeriodEnd(range.to)
    const first = types.data?.data?.[0]
    setTaxTypeId(first ? String(first.id) : '')
    setPrepareOpen(true)
  }

  const onPrepareSubmit = (e: FormEvent) => {
    e.preventDefault()
    prepare.mutate()
  }

  const searchField = (
    <input
      type="search"
      className="ua-revenue-search"
      value={search}
      onChange={(e) => setSearch(e.target.value)}
      placeholder="Search returns, rates, codes…"
      aria-label="Search tax control"
    />
  )

  const tabs: Array<{ id: DeskTab; label: string; count: number }> = [
    { id: 'returns', label: 'Returns', count: filteredReturns.length },
    { id: 'transactions', label: 'Transactions', count: filteredTxns.length },
    { id: 'rates', label: 'Rates', count: filteredRates.length },
    { id: 'codes', label: 'Codes', count: filteredCodes.length },
  ]
  const activeTab = tabs.find((t) => t.id === tab) ?? tabs[0]

  return (
    <div className="ua-revenue ua-tax">
      {/* ERP shell already shows Home / Accounting / VAT Control — no in-app title/breadcrumb */}
      <PageHeader
        crumbExtra={<div className="ua-revenue-search-wrap ua-revenue-search-wrap--crumb">{searchField}</div>}
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
            <Button type="button" pill onClick={openPrepare} disabled={!ready}>
              Prepare return
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
              aria-label="Filter VAT control"
              style={{ top: filterPanelPos.top, right: filterPanelPos.right }}
            >
              <div className="ua-revenue-filter-panel__head">
                <div className="ua-revenue-filter-panel__heading">Filters</div>
                <button
                  type="button"
                  className="ua-revenue-filter-clear"
                  disabled={!hasActiveFilters}
                  onClick={() => {
                    setStatusFilter('all')
                    setTab('transactions')
                  }}
                >
                  Reset
                </button>
              </div>

              <div className="ua-revenue-filter-panel__title">View</div>
              <div className="ua-revenue-filter-chips" role="tablist" aria-label="Tax sections">
                {tabs.map((t) => (
                  <button
                    key={t.id}
                    type="button"
                    role="tab"
                    aria-selected={tab === t.id}
                    className={`ua-revenue-chip${tab === t.id ? ' is-active' : ''}`}
                    onClick={() => setTab(t.id)}
                  >
                    {t.label}
                    <span>{t.count}</span>
                  </button>
                ))}
              </div>

              <div className="ua-revenue-filter-panel__title">Draft → Reconcile → File → Pay</div>
              <div className="ua-revenue-filter-chips">
                {(
                  [
                    ['all', 'All', returnsList.length],
                    ['draft', 'Draft', returnsList.filter((r) => statusBucket(r.status) === 'draft').length],
                    ['reconciled', 'Reconcile', returnsList.filter((r) => statusBucket(r.status) === 'reconciled').length],
                    ['filed', 'File', returnsList.filter((r) => statusBucket(r.status) === 'filed').length],
                    ['paid', 'Pay', returnsList.filter((r) => statusBucket(r.status) === 'paid').length],
                  ] as const
                ).map(([value, label, count]) => (
                  <button
                    key={value}
                    type="button"
                    className={`ua-revenue-chip${statusFilter === value ? ' is-active' : ''}`}
                    onClick={() => {
                      setStatusFilter(value)
                      setTab('returns')
                    }}
                  >
                    {label}
                    <span>{count}</span>
                  </button>
                ))}
              </div>

              <div className="ua-revenue-filter-panel__footer ua-tax-filter-footer">
                <Button
                  type="button"
                  variant="secondary"
                  className="w-full"
                  disabled={!ready}
                  onClick={() => {
                    setFiltersOpen(false)
                    setAdjustOpen(true)
                  }}
                >
                  Add adjustment
                </Button>
                <Button type="button" className="w-full" onClick={() => setFiltersOpen(false)}>
                  Done
                </Button>
              </div>
            </div>,
            document.body,
          )
        : null}

      {!ready ? <ErrorBanner message="Company session not ready. Re-open Accounting from ERP." /> : null}
      {error ? <ErrorBanner message={error} /> : null}

      <section className="ua-tax-kpi-block" aria-label="VAT summary">
        <p className="ua-tax-kpi-period">{kpiPeriodLabel}</p>
        <div className="ua-revenue-kpis">
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Output VAT</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.output)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--teal" aria-hidden="true">
            <OutputIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Input VAT</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.input)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--amber" aria-hidden="true">
            <InputIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Net VAT</span>
            <strong className={`ua-revenue-kpi__value${kpi.net < 0 ? ' ua-revenue-kpi__value--rose' : ''}`}>
              {tzs(kpi.net)}
            </strong>
          </div>
          <span
            className={`ua-revenue-kpi__badge ${kpi.net < 0 ? 'ua-revenue-kpi__badge--rose' : 'ua-revenue-kpi__badge--blue'}`}
            aria-hidden="true"
          >
            <NetIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Returns</span>
            <strong className="ua-revenue-kpi__value">{kpi.returns.toLocaleString('en-US')}</strong>
            <span className="ua-revenue-kpi__sub">{kpi.drafts} draft</span>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--violet" aria-hidden="true">
            <ReturnsIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Transactions</span>
            <strong className="ua-revenue-kpi__value">{kpi.txns.toLocaleString('en-US')}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--indigo" aria-hidden="true">
            <ReturnsIcon />
          </span>
        </article>
        </div>
      </section>

      <section className="ua-revenue-desk" aria-label="VAT control desk">
        <div className="ua-revenue-desk__toolbar">
          <div className="ua-revenue-desk__heading">
            <h2 className="ua-revenue-desk__title">{activeTab.label}</h2>
            <p className="ua-revenue-desk__meta">
              {activeTab.count.toLocaleString('en-US')} shown
              {tab === 'returns' && statusFilter !== 'all'
                ? ` · ${statusFilter === 'reconciled' ? 'Reconcile' : statusFilter === 'filed' ? 'File' : statusFilter === 'paid' ? 'Pay' : 'Draft'}`
                : ''}
            </p>
          </div>
        </div>

        <div className="ua-revenue-desk__body">
          {tab === 'returns' ? (
            returns.isLoading ? (
              <div className="ua-revenue-table-card ua-revenue-loading">Loading returns…</div>
            ) : filteredReturns.length ? (
              <div className="ua-revenue-table-card">
                <table className="ua-revenue-table ua-revenue-table--full">
                  <thead>
                    <tr>
                      <th scope="col">Number</th>
                      <th scope="col">Period</th>
                      <th scope="col" className="is-num">
                        Output
                      </th>
                      <th scope="col" className="is-num">
                        Input
                      </th>
                      <th scope="col" className="is-num">
                        Net
                      </th>
                      <th scope="col">Status</th>
                      <th scope="col">
                        <span className="sr-only">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredReturns.map((r) => {
                      const bucket = statusBucket(r.status)
                      return (
                        <tr key={r.id} className="ua-revenue-row ua-tax-return-row">
                          <td className="ua-revenue-cell ua-tax-cell-number">
                            <div className="ua-tax-return-num">{r.return_number || `Return #${r.id}`}</div>
                            {r.filing_reference ? (
                              <div className="ua-tax-return-ref">Ref {r.filing_reference}</div>
                            ) : null}
                          </td>
                          <td className="ua-revenue-cell ua-tax-cell-period">
                            {String(r.period_start || '').slice(0, 10)} → {String(r.period_end || '').slice(0, 10)}
                          </td>
                          <td className="ua-revenue-cell is-num ua-tax-cell-out">{tzs(r.output_tax)}</td>
                          <td className="ua-revenue-cell is-num ua-tax-cell-in">{tzs(r.input_tax)}</td>
                          <td className="ua-revenue-cell ua-tax-cell-io" aria-hidden="true">
                            Out {tzs(r.output_tax)} · In {tzs(r.input_tax)}
                          </td>
                          <td className="ua-revenue-cell is-num ua-tax-cell-net">{tzs(r.net_tax)}</td>
                          <td className="ua-revenue-cell ua-tax-cell-status">
                            <span className={statusClass(r.status)}>{statusLabel(r.status)}</span>
                          </td>
                          <td className="ua-revenue-cell ua-tax-cell-actions">
                            {bucket === 'draft' ? (
                              <Button
                                type="button"
                                variant="secondary"
                                pill
                                disabled={reconcile.isPending}
                                onClick={() => reconcile.mutate(r.id)}
                              >
                                Reconcile
                              </Button>
                            ) : null}
                            {bucket === 'reconciled' ? (
                              <Button
                                type="button"
                                variant="secondary"
                                pill
                                onClick={() => {
                                  setFileTarget(r)
                                  setFilingDate(todayIso())
                                  setFilingReference('')
                                  setFileOpen(true)
                                }}
                              >
                                File
                              </Button>
                            ) : null}
                            {bucket === 'filed' ? (
                              <Button
                                type="button"
                                pill
                                onClick={() => {
                                  setPayTarget(r)
                                  setPaymentDate(todayIso())
                                  setPaymentAmount(String(Math.abs(Number(r.net_tax || 0))))
                                  setBankAccountId('')
                                  setPayOpen(true)
                                }}
                              >
                                Pay
                              </Button>
                            ) : null}
                            {bucket === 'paid' ? <span className="ua-tax-return-ref">Locked</span> : null}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="ua-revenue-table-card ua-revenue-table-card--empty">
                <EmptyState
                  title="No VAT returns"
                  body="Prepare a return for a period that has tax transactions."
                />
              </div>
            )
          ) : null}

          {tab === 'transactions' ? (
            txns.isLoading ? (
              <div className="ua-revenue-table-card ua-revenue-loading">Loading transactions…</div>
            ) : filteredTxns.length ? (
              <div className="ua-revenue-table-card">
                <table className="ua-revenue-table ua-revenue-table--full">
                  <thead>
                    <tr>
                      <th scope="col">Date</th>
                      <th scope="col">Direction</th>
                      <th scope="col">Code</th>
                      <th scope="col" className="is-num">
                        Taxable
                      </th>
                      <th scope="col" className="is-num">
                        Tax
                      </th>
                      <th scope="col" className="is-num">
                        Rate %
                      </th>
                      <th scope="col">Source</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredTxns.map((t) => {
                      const code = t.tax_code?.code || '—'
                      const rate =
                        t.rate_percent != null && t.rate_percent !== '' ? `${t.rate_percent}%` : '—'
                      return (
                        <tr key={t.id} className="ua-revenue-row ua-tax-txn-row">
                          <td className="ua-revenue-cell ua-tax-cell-date">
                            {String(t.tax_date || '').slice(0, 10)}
                          </td>
                          <td
                            className={`ua-revenue-cell capitalize ua-tax-cell-direction ua-tax-direction ua-tax-direction--${String(t.direction || '').toLowerCase() === 'input' ? 'input' : String(t.direction || '').toLowerCase() === 'output' ? 'output' : 'unknown'}`}
                          >
                            {t.direction || '—'}
                          </td>
                          <td className="ua-revenue-cell ua-tax-cell-code">{code}</td>
                          <td className="ua-revenue-cell ua-revenue-cell--money is-num ua-tax-cell-taxable">
                            {tzs(t.taxable_amount)}
                          </td>
                          <td className="ua-revenue-cell ua-revenue-cell--money ua-revenue-cell--total is-num ua-tax-cell-tax">
                            {tzs(t.tax_amount)}
                          </td>
                          <td className="ua-revenue-cell is-num ua-tax-cell-rate">{rate}</td>
                          <td className="ua-revenue-cell ua-tax-cell-meta" aria-hidden="true">
                            {code} · taxable {tzs(t.taxable_amount)} · {rate}
                          </td>
                          <td className="ua-revenue-cell ua-tax-source ua-tax-cell-source">
                            {t.source_type || '—'}
                            {t.source_id != null ? ` #${t.source_id}` : ''}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="ua-revenue-table-card ua-revenue-table-card--empty">
                <EmptyState
                  title="No tax transactions"
                  body="Post invoices or bills with a tax code to record VAT."
                />
              </div>
            )
          ) : null}

          {tab === 'rates' ? (
            rates.isLoading ? (
              <div className="ua-revenue-table-card ua-revenue-loading">Loading rates…</div>
            ) : filteredRates.length ? (
              <div className="ua-revenue-table-card">
                <table className="ua-revenue-table ua-revenue-table--full">
                  <thead>
                    <tr>
                      <th scope="col">Code</th>
                      <th scope="col">Name</th>
                      <th scope="col" className="is-num">
                        Rate %
                      </th>
                      <th scope="col">From</th>
                      <th scope="col">To</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredRates.map((r) => (
                      <tr key={r.id} className="ua-revenue-row">
                        <td className="ua-revenue-cell ua-revenue-cell--doc">
                          <div className="ua-revenue-docnum">{r.code || r.tax_type?.code || '—'}</div>
                        </td>
                        <td className="ua-revenue-cell">{r.name || r.tax_type?.name || '—'}</td>
                        <td className="ua-revenue-cell is-num">{r.rate_percent ?? '—'}</td>
                        <td className="ua-revenue-cell">{String(r.effective_from || '').slice(0, 10) || '—'}</td>
                        <td className="ua-revenue-cell">
                          {r.effective_to ? String(r.effective_to).slice(0, 10) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="ua-revenue-table-card ua-revenue-table-card--empty">
                <EmptyState title="No rates" body="No tax rates for this book jurisdiction." />
              </div>
            )
          ) : null}

          {tab === 'codes' ? (
            codes.isLoading ? (
              <div className="ua-revenue-table-card ua-revenue-loading">Loading codes…</div>
            ) : filteredCodes.length ? (
              <div className="ua-revenue-table-card">
                <table className="ua-revenue-table ua-revenue-table--full">
                  <thead>
                    <tr>
                      <th scope="col">Code</th>
                      <th scope="col">Name</th>
                      <th scope="col" className="is-num">
                        Rate %
                      </th>
                      <th scope="col">Applies</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredCodes.map((c) => (
                      <tr key={c.id} className="ua-revenue-row">
                        <td className="ua-revenue-cell ua-revenue-cell--doc">
                          <div className="ua-revenue-docnum">{c.code}</div>
                        </td>
                        <td className="ua-revenue-cell">{c.name}</td>
                        <td className="ua-revenue-cell is-num">{c.tax_rate?.rate_percent ?? '—'}</td>
                        <td className="ua-revenue-cell capitalize">{c.applies_to || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="ua-revenue-table-card ua-revenue-table-card--empty">
                <EmptyState title="No codes" body="No tax codes seeded for this company." />
              </div>
            )
          ) : null}
        </div>
      </section>

      {prepareOpen
        ? createPortal(
            <div
              className="ua-journals-modal-overlay ua-tax-sheet-overlay"
              role="presentation"
              onClick={() => !prepare.isPending && setPrepareOpen(false)}
            >
              <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="ua-tax-prepare-title"
                className="ua-journals-modal ua-tax-sheet"
                onClick={(e) => e.stopPropagation()}
              >
                <form className="ua-journals-modal__form" onSubmit={onPrepareSubmit}>
                  <div className="ua-tax-sheet__grab" aria-hidden="true">
                    <span className="ua-tax-sheet__handle" />
                  </div>
                  <div className="ua-journals-modal__head">
                    <h2 id="ua-tax-prepare-title">Prepare VAT return</h2>
                    <button
                      type="button"
                      className="ua-journals-modal__close"
                      aria-label="Close"
                      disabled={prepare.isPending}
                      onClick={() => setPrepareOpen(false)}
                    >
                      ×
                    </button>
                  </div>

                  <div className="ua-journals-modal__body">
                    {error ? <ErrorBanner message={error} /> : null}
                    <div className="ua-journals-form-grid">
                      <div className="ua-journals-form-grid__wide">
                        <Label>Tax type</Label>
                        <Select value={taxTypeId} onChange={(e) => setTaxTypeId(e.target.value)} required>
                          <option value="">Select</option>
                          {(types.data?.data ?? []).map((t) => (
                            <option key={t.id} value={t.id}>
                              {t.code} — {t.name}
                            </option>
                          ))}
                        </Select>
                      </div>
                      <div>
                        <Label>Period start</Label>
                        <Input
                          type="date"
                          value={periodStart}
                          onChange={(e) => setPeriodStart(e.target.value)}
                          required
                        />
                      </div>
                      <div>
                        <Label>Period end</Label>
                        <Input
                          type="date"
                          value={periodEnd}
                          onChange={(e) => setPeriodEnd(e.target.value)}
                          required
                        />
                      </div>
                    </div>
                  </div>

                  <div className="ua-journals-modal__actions">
                    <Button
                      type="button"
                      variant="secondary"
                      pill
                      disabled={prepare.isPending}
                      onClick={() => setPrepareOpen(false)}
                    >
                      Cancel
                    </Button>
                    <Button type="submit" pill disabled={prepare.isPending || !taxTypeId}>
                      {prepare.isPending ? 'Preparing…' : 'Prepare return'}
                    </Button>
                  </div>
                </form>
              </div>
            </div>,
            document.body,
          )
        : null}

      {fileOpen && fileTarget
        ? createPortal(
            <div
              className="ua-journals-modal-overlay ua-tax-sheet-overlay"
              role="presentation"
              onClick={() => !fileReturn.isPending && setFileOpen(false)}
            >
              <div
                className="ua-journals-modal ua-tax-sheet"
                role="dialog"
                aria-modal="true"
                onClick={(e) => e.stopPropagation()}
              >
                <form
                  className="ua-journals-modal__form"
                  onSubmit={(e) => {
                    e.preventDefault()
                    fileReturn.mutate()
                  }}
                >
                  <div className="ua-tax-sheet__grab" aria-hidden="true">
                    <span className="ua-tax-sheet__handle" />
                  </div>
                  <div className="ua-journals-modal__head">
                    <h2>File return {fileTarget.return_number}</h2>
                    <button type="button" className="ua-journals-modal__close" onClick={() => setFileOpen(false)}>
                      ×
                    </button>
                  </div>
                  <div className="ua-journals-modal__body">
                    <div className="ua-journals-form-grid">
                      <div>
                        <Label>Filing date</Label>
                        <Input type="date" value={filingDate} onChange={(e) => setFilingDate(e.target.value)} required />
                      </div>
                      <div className="ua-journals-form-grid__wide">
                        <Label>TRA / filing reference</Label>
                        <Input
                          value={filingReference}
                          onChange={(e) => setFilingReference(e.target.value)}
                          placeholder="e.g. TRA-VAT-2026-10"
                          required
                        />
                      </div>
                    </div>
                  </div>
                  <div className="ua-journals-modal__actions">
                    <Button type="button" variant="secondary" pill onClick={() => setFileOpen(false)}>
                      Cancel
                    </Button>
                    <Button type="submit" pill disabled={fileReturn.isPending || !filingReference.trim()}>
                      {fileReturn.isPending ? 'Filing…' : 'Mark filed'}
                    </Button>
                  </div>
                </form>
              </div>
            </div>,
            document.body,
          )
        : null}

      {payOpen && payTarget
        ? createPortal(
            <div
              className="ua-journals-modal-overlay ua-tax-sheet-overlay"
              role="presentation"
              onClick={() => !payReturn.isPending && setPayOpen(false)}
            >
              <div
                className="ua-journals-modal ua-tax-sheet"
                role="dialog"
                aria-modal="true"
                onClick={(e) => e.stopPropagation()}
              >
                <form
                  className="ua-journals-modal__form"
                  onSubmit={(e) => {
                    e.preventDefault()
                    payReturn.mutate()
                  }}
                >
                  <div className="ua-tax-sheet__grab" aria-hidden="true">
                    <span className="ua-tax-sheet__handle" />
                  </div>
                  <div className="ua-journals-modal__head">
                    <h2>Pay / settle {payTarget.return_number}</h2>
                    <button type="button" className="ua-journals-modal__close" onClick={() => setPayOpen(false)}>
                      ×
                    </button>
                  </div>
                  <div className="ua-journals-modal__body">
                    <p className="ua-revenue-desk__meta" style={{ marginBottom: 12 }}>
                      Net on return: {tzs(payTarget.net_tax)}. This posts VAT settlement to the bank GL and locks the
                      period.
                    </p>
                    <div className="ua-journals-form-grid">
                      <div>
                        <Label>Payment date</Label>
                        <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} required />
                      </div>
                      <div>
                        <Label>Amount</Label>
                        <Input
                          inputMode="decimal"
                          value={paymentAmount}
                          onChange={(e) => setPaymentAmount(e.target.value)}
                          required
                        />
                      </div>
                      <div className="ua-journals-form-grid__wide">
                        <Label>Bank account</Label>
                        <Select value={bankAccountId} onChange={(e) => setBankAccountId(e.target.value)} required>
                          <option value="">Select bank</option>
                          {(banks.data?.data ?? []).map((b) => (
                            <option key={b.id} value={b.id}>
                              {b.code} — {b.name}
                            </option>
                          ))}
                        </Select>
                      </div>
                    </div>
                  </div>
                  <div className="ua-journals-modal__actions">
                    <Button type="button" variant="secondary" pill onClick={() => setPayOpen(false)}>
                      Cancel
                    </Button>
                    <Button type="submit" pill disabled={payReturn.isPending || !bankAccountId}>
                      {payReturn.isPending ? 'Posting…' : 'Record payment'}
                    </Button>
                  </div>
                </form>
              </div>
            </div>,
            document.body,
          )
        : null}

      {adjustOpen
        ? createPortal(
            <div
              className="ua-journals-modal-overlay ua-tax-sheet-overlay"
              role="presentation"
              onClick={() => !adjust.isPending && setAdjustOpen(false)}
            >
              <div
                className="ua-journals-modal ua-tax-sheet"
                role="dialog"
                aria-modal="true"
                onClick={(e) => e.stopPropagation()}
              >
                <form
                  className="ua-journals-modal__form"
                  onSubmit={(e) => {
                    e.preventDefault()
                    adjust.mutate()
                  }}
                >
                  <div className="ua-tax-sheet__grab" aria-hidden="true">
                    <span className="ua-tax-sheet__handle" />
                  </div>
                  <div className="ua-journals-modal__head">
                    <h2>VAT adjustment</h2>
                    <button type="button" className="ua-journals-modal__close" onClick={() => setAdjustOpen(false)}>
                      ×
                    </button>
                  </div>
                  <div className="ua-journals-modal__body">
                    <p className="ua-revenue-desk__meta" style={{ marginBottom: 12 }}>
                      Use for credit/debit note VAT. Negative tax amount reduces output or input.
                    </p>
                    <div className="ua-journals-form-grid">
                      <div>
                        <Label>Direction</Label>
                        <Select
                          value={adjDirection}
                          onChange={(e) => setAdjDirection(e.target.value as 'output' | 'input')}
                        >
                          <option value="output">Output (sales)</option>
                          <option value="input">Input (purchases)</option>
                        </Select>
                      </div>
                      <div>
                        <Label>Date</Label>
                        <Input type="date" value={adjDate} onChange={(e) => setAdjDate(e.target.value)} required />
                      </div>
                      <div>
                        <Label>Taxable amount</Label>
                        <Input value={adjTaxable} onChange={(e) => setAdjTaxable(e.target.value)} placeholder="0.00" />
                      </div>
                      <div>
                        <Label>Tax amount</Label>
                        <Input value={adjTax} onChange={(e) => setAdjTax(e.target.value)} placeholder="-36000" required />
                      </div>
                      <div className="ua-journals-form-grid__wide">
                        <Label>Memo</Label>
                        <Input
                          value={adjMemo}
                          onChange={(e) => setAdjMemo(e.target.value)}
                          placeholder="Credit note CN-…"
                        />
                      </div>
                    </div>
                  </div>
                  <div className="ua-journals-modal__actions">
                    <Button type="button" variant="secondary" pill onClick={() => setAdjustOpen(false)}>
                      Cancel
                    </Button>
                    <Button type="submit" pill disabled={adjust.isPending || !adjTax.trim()}>
                      {adjust.isPending ? 'Saving…' : 'Save adjustment'}
                    </Button>
                  </div>
                </form>
              </div>
            </div>,
            document.body,
          )
        : null}
    </div>
  )
}
