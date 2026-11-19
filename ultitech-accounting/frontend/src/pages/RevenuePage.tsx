import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { createPortal } from 'react-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table, SectionTitle } from '../components/ui'

type Customer = { id: number; code: string; name: string }
type Account = { id: number; code: string; name: string }
type InvoiceLine = {
  id?: number
  line_no?: number
  description: string
  quantity?: string | number
  unit_price?: string | number
  line_total?: string | number
}

type Invoice = {
  id: number
  invoice_number: string
  invoice_date: string
  due_date?: string | null
  description?: string | null
  currency_code?: string
  subtotal?: string
  tax_total?: string
  total: string
  amount_paid?: string
  balance_due: string
  status: string
  customer?: Customer
  lines?: InvoiceLine[]
  erp_invoice_id?: string | null
  erp_order_id?: number | null
}

type BankAccount = { id: number; code: string; name: string; account_type?: string }
type CashAccount = { id: number; code: string; name: string }
type PayFilter = 'all' | 'unpaid' | 'partial' | 'paid'
type DepositKind = 'bank' | 'cash'

function resolveCompanySlug(embedSlug: string): string {
  const fromEmbed = String(embedSlug || '').trim().replace(/^\/+|\/+$/g, '')
  if (fromEmbed) return fromEmbed
  const parts = window.location.pathname.split('/').filter(Boolean)
  const skip = new Set(['api', 'accounting', 'ultitech-accounting', 'public_html', 'modules'])
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

function erpOrderViewUrl(slug: string, orderId: number, hintUrl = ''): string {
  const base = companyRootPath(slug, hintUrl).replace(/\/+$/, '')
  return `${base}/sales/order-view?module=sales&id=${orderId}`
}

function money(value: string | number | undefined) {
  const n = Number(value || 0)
  if (!Number.isFinite(n)) return '0.00'
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function tzs(value: string | number | undefined) {
  return `TZS ${money(value)}`
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

function paymentBucket(invoice: Invoice): 'unpaid' | 'partial' | 'paid' | 'other' {
  const status = String(invoice.status || '').toLowerCase()
  if (status === 'void' || status === 'cancelled' || status === 'canceled' || status === 'draft') {
    return 'other'
  }
  const total = Number(invoice.total || 0)
  const paid = Number(invoice.amount_paid ?? 0)
  const balance = Number(invoice.balance_due ?? (total - paid))
  if (status === 'paid' || (total > 0 && balance <= 0.009) || (total > 0 && paid + 0.009 >= total)) {
    return 'paid'
  }
  if (status === 'partial' || paid > 0.009) {
    return 'partial'
  }
  if (['posted', 'sent', 'viewed', 'overdue', 'unpaid'].includes(status) || balance > 0.009) {
    return 'unpaid'
  }
  return 'other'
}

function paymentLabel(bucket: ReturnType<typeof paymentBucket>) {
  if (bucket === 'paid') return 'Paid'
  if (bucket === 'partial') return 'Partially paid'
  if (bucket === 'unpaid') return 'Unpaid'
  return '—'
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
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

export function RevenuePage() {
  const { token, companyId, bookId, embed, classicAccountingUrl, hostedInErp } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [crumbSearchHost, setCrumbSearchHost] = useState<HTMLElement | null>(null)

  const [revenueDate, setRevenueDate] = useState(todayIso)
  const [customerId, setCustomerId] = useState('')
  const [description, setDescription] = useState('')
  const [amount, setAmount] = useState('')
  const [revenueAccountId, setRevenueAccountId] = useState('')
  const [depositKind, setDepositKind] = useState<DepositKind>('bank')
  const [bankAccountId, setBankAccountId] = useState('')
  const [cashAccountId, setCashAccountId] = useState('')
  const [reference, setReference] = useState('')

  const [payInvoiceId, setPayInvoiceId] = useState<number | null>(null)
  const [payAmount, setPayAmount] = useState('')
  const [payBankAccountId, setPayBankAccountId] = useState('')
  const [payDepositKind, setPayDepositKind] = useState<DepositKind>('bank')
  const [payCashAccountId, setPayCashAccountId] = useState('')

  const [payFilter, setPayFilter] = useState<PayFilter>('all')
  const [invoiceQuery, setInvoiceQuery] = useState('')
  const [openMenuId, setOpenMenuId] = useState<number | null>(null)
  const [viewInvoiceId, setViewInvoiceId] = useState<number | null>(null)
  const menuRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (openMenuId == null && viewInvoiceId == null && payInvoiceId == null && !formOpen) return
    const onDoc = (e: MouseEvent) => {
      if (openMenuId != null && menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setOpenMenuId(null)
      }
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return
      if (payInvoiceId != null) setPayInvoiceId(null)
      else if (viewInvoiceId != null) setViewInvoiceId(null)
      else if (openMenuId != null) setOpenMenuId(null)
      else if (formOpen) setFormOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [openMenuId, viewInvoiceId, payInvoiceId, formOpen])

  const customers = useQuery({
    queryKey: ['customers', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{ data: Customer[] }>('/customers', { token, companyId }),
  })
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{ data: Account[] }>('/accounts', { token, companyId }),
  })
  const invoices = useQuery({
    queryKey: ['customer-invoices', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{ data: Invoice[] }>('/customer-invoices', { token, companyId }),
  })
  const banks = useQuery({
    queryKey: ['bank-accounts', companyId, bookId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{ data: BankAccount[] }>('/bank-accounts', { token, companyId, bookId }),
  })
  const cashAccounts = useQuery({
    queryKey: ['cash-accounts', companyId, bookId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{ data: CashAccount[] }>('/cash-accounts', { token, companyId, bookId }),
  })

  const revenueAccounts = useMemo(
    () => (accounts.data?.data ?? []).filter((a) => a.code.startsWith('4')),
    [accounts.data],
  )
  const otherIncomeAccount = useMemo(
    () => revenueAccounts.find((a) => a.code === '4003') ?? revenueAccounts[0] ?? null,
    [revenueAccounts],
  )

  useEffect(() => {
    if (!revenueAccountId && otherIncomeAccount) setRevenueAccountId(String(otherIncomeAccount.id))
  }, [revenueAccountId, otherIncomeAccount])

  useEffect(() => {
    if (!bankAccountId && (banks.data?.data?.length ?? 0) > 0) {
      setBankAccountId(String(banks.data!.data[0].id))
    }
    if (!payBankAccountId && (banks.data?.data?.length ?? 0) > 0) {
      setPayBankAccountId(String(banks.data!.data[0].id))
    }
  }, [bankAccountId, payBankAccountId, banks.data])

  useEffect(() => {
    if (!cashAccountId && (cashAccounts.data?.data?.length ?? 0) > 0) {
      setCashAccountId(String(cashAccounts.data!.data[0].id))
    }
    if (!payCashAccountId && (cashAccounts.data?.data?.length ?? 0) > 0) {
      setPayCashAccountId(String(cashAccounts.data!.data[0].id))
    }
  }, [cashAccountId, payCashAccountId, cashAccounts.data])

  const receivableInvoices = useMemo(
    () => (invoices.data?.data ?? []).filter((i) => paymentBucket(i) !== 'other'),
    [invoices.data],
  )
  const unpaidCount = useMemo(
    () => receivableInvoices.filter((i) => paymentBucket(i) === 'unpaid').length,
    [receivableInvoices],
  )
  const partialCount = useMemo(
    () => receivableInvoices.filter((i) => paymentBucket(i) === 'partial').length,
    [receivableInvoices],
  )
  const paidCount = useMemo(
    () => receivableInvoices.filter((i) => paymentBucket(i) === 'paid').length,
    [receivableInvoices],
  )
  const filteredInvoices = useMemo(() => {
    const byStatus =
      payFilter === 'all'
        ? receivableInvoices
        : receivableInvoices.filter((i) => paymentBucket(i) === payFilter)
    const q = invoiceQuery.trim().toLowerCase()
    if (!q) return byStatus
    return byStatus.filter((invoice) => {
      const hay = [
        invoice.invoice_number,
        invoice.customer?.name,
        invoice.customer?.code,
        invoice.description,
        invoice.erp_invoice_id,
        String(invoice.invoice_date || '').slice(0, 10),
        String(invoice.total || ''),
        String(invoice.balance_due || ''),
        paymentLabel(paymentBucket(invoice)),
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return hay.includes(q)
    })
  }, [receivableInvoices, payFilter, invoiceQuery])

  /** Summary cards match rows currently shown in the table (classic Revenue desk). */
  const kpi = useMemo(() => {
    let exclusive = 0
    let vat = 0
    let gross = 0
    let outstanding = 0
    let overdue = 0
    const today = startOfToday()

    for (const row of filteredInvoices) {
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
      invoiceTotal: gross,
      invoiceCount: filteredInvoices.length,
      outstanding,
      overdue,
    }
  }, [filteredInvoices])

  const viewedInvoice = useMemo(
    () => (invoices.data?.data ?? []).find((i) => i.id === viewInvoiceId) ?? null,
    [invoices.data, viewInvoiceId],
  )
  const viewedBucket = viewedInvoice ? paymentBucket(viewedInvoice) : null
  const viewedCanPay = viewedBucket === 'unpaid' || viewedBucket === 'partial'

  const payingInvoice = useMemo(
    () => (invoices.data?.data ?? []).find((i) => i.id === payInvoiceId) ?? null,
    [invoices.data, payInvoiceId],
  )

  function openInvoice(invoice: Invoice) {
    setOpenMenuId(null)
    const orderId = Number(invoice.erp_order_id || 0)
    if (orderId > 0) {
      const slug = resolveCompanySlug(embed.erpCompanySlug)
      const hint = classicAccountingUrl || embed.backUrl || ''
      window.location.href = erpOrderViewUrl(slug, orderId, hint)
      return
    }
    setViewInvoiceId(invoice.id)
  }

  function startPayInvoice(invoice: Invoice) {
    setOpenMenuId(null)
    setViewInvoiceId(null)
    setError('')
    setPayInvoiceId(invoice.id)
    setPayAmount(String(invoice.balance_due || ''))
    setPayDepositKind('bank')
    if (!payBankAccountId && (banks.data?.data?.length ?? 0) > 0) {
      setPayBankAccountId(String(banks.data!.data[0].id))
    }
  }

  const invalidateRevenue = async () => {
    await Promise.all([
      client.invalidateQueries({ queryKey: ['customer-invoices', companyId] }),
      client.invalidateQueries({ queryKey: ['customers', companyId] }),
      client.invalidateQueries({ queryKey: ['trial-balance', companyId] }),
      client.invalidateQueries({ queryKey: ['journals', companyId] }),
      client.invalidateQueries({ queryKey: ['bank-transactions', companyId] }),
    ])
  }

  const createRevenue = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = {
        revenue_date: revenueDate || todayIso(),
        currency_code: 'TZS',
        description: description.trim(),
        amount,
        revenue_account_id: Number(revenueAccountId),
        reference: reference.trim() || undefined,
      }
      if (customerId) body.customer_id = Number(customerId)
      if (depositKind === 'bank') body.bank_account_id = Number(bankAccountId)
      else body.cash_account_id = Number(cashAccountId)
      return apiRequest<{ data: { invoice: Invoice } }>('/manual-revenues', {
        method: 'POST',
        token,
        companyId,
        bookId,
        body,
      })
    },
    onSuccess: async (res) => {
      setError('')
      const number = res?.data?.invoice?.invoice_number
      setNotice(number ? `Recorded ${number} (paid).` : 'Revenue recorded.')
      setDescription('')
      setAmount('')
      setReference('')
      setCustomerId('')
      setRevenueDate(todayIso())
      await invalidateRevenue()
      setPayFilter('paid')
    },
    onError: (err) => {
      setNotice('')
      setError((err as Error).message)
    },
  })

  const createReceipt = useMutation({
    mutationFn: () => {
      if (!payingInvoice) throw new Error('Select an invoice')
      const body: Record<string, unknown> = {
        customer_id: payingInvoice.customer?.id,
        receipt_date: todayIso(),
        currency_code: 'TZS',
        amount: payAmount || payingInvoice.balance_due,
        allocations: [
          {
            invoice_id: payingInvoice.id,
            amount: payAmount || payingInvoice.balance_due,
          },
        ],
      }
      if (payDepositKind === 'bank') body.bank_account_id = Number(payBankAccountId)
      else body.cash_account_id = Number(payCashAccountId)
      return apiRequest('/customer-receipts', {
        method: 'POST',
        token,
        companyId,
        bookId,
        body,
      })
    },
    onSuccess: async () => {
      setError('')
      setNotice(`Payment recorded for ${payingInvoice?.invoice_number || 'invoice'}.`)
      setPayInvoiceId(null)
      await invalidateRevenue()
    },
    onError: (err) => setError((err as Error).message),
  })

  const onRecordRevenue = (e: FormEvent) => {
    e.preventDefault()
    setNotice('')
    if (depositKind === 'bank' && !bankAccountId) {
      setError('Select a bank or mobile account.')
      return
    }
    if (depositKind === 'cash' && !cashAccountId) {
      setError('Select a cash account.')
      return
    }
    createRevenue.mutate()
  }

  const onPayReceipt = (e: FormEvent) => {
    e.preventDefault()
    if (payDepositKind === 'bank' && !payBankAccountId) {
      setError('Select a bank or mobile account.')
      return
    }
    if (payDepositKind === 'cash' && !payCashAccountId) {
      setError('Select a cash account.')
      return
    }
    createReceipt.mutate()
  }

  return (
    <div>
      <PageHeader
        title="Revenue"
        subtitle="Sales invoices come from ERP. Create revenue for other income, or pay open invoices from the list."
        actions={
          <Button
            type="button"
            aria-expanded={formOpen}
            aria-controls="ua-record-revenue-panel"
            onClick={() => {
              setFormOpen((open) => !open)
              if (formOpen) setError('')
            }}
          >
            {formOpen ? 'Close form' : 'Create revenue'}
          </Button>
        }
      />
      {error ? <ErrorBanner message={error} /> : null}
      {notice && !formOpen ? (
        <div className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          {notice}
        </div>
      ) : null}

      <div className="ua-revenue-search-wrap mb-4">
        <input
          type="search"
          className="ua-revenue-search"
          value={invoiceQuery}
          onChange={(e) => setInvoiceQuery(e.target.value)}
          placeholder="Search number, customer, date…"
          aria-label="Search invoices"
        />
      </div>

      <div className="ua-revenue-kpis mb-4" aria-label="Revenue summary">
        <div className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Total Exclusive</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.exclusive)}</strong>
            <span className="ua-revenue-kpi__sub">Before VAT</span>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--violet" aria-hidden="true">
            <CoinsIcon />
          </span>
        </div>
        <div className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">VAT</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.vat)}</strong>
            <span className="ua-revenue-kpi__sub">Total VAT included</span>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--indigo" aria-hidden="true">
            <PercentIcon />
          </span>
        </div>
        <div className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Gross Total</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.gross)}</strong>
            <span className="ua-revenue-kpi__sub">After tax</span>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--teal" aria-hidden="true">
            <WalletIcon />
          </span>
        </div>
        <div className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Total Invoices</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.invoiceTotal)}</strong>
            <span className="ua-revenue-kpi__sub">
              {kpi.invoiceCount ? `${kpi.invoiceCount.toLocaleString('en-US')} in view` : 'No invoices in view'}
            </span>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--blue" aria-hidden="true">
            <FileTextIcon />
          </span>
        </div>
        <div className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Outstanding</span>
            <strong className="ua-revenue-kpi__value">{tzs(kpi.outstanding)}</strong>
            <span className="ua-revenue-kpi__sub">Balance due</span>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--amber" aria-hidden="true">
            <ClockIcon />
          </span>
        </div>
        <div className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Overdue</span>
            <strong className="ua-revenue-kpi__value ua-revenue-kpi__value--rose">{tzs(kpi.overdue)}</strong>
            <span className="ua-revenue-kpi__sub">Past due date</span>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--rose" aria-hidden="true">
            <AlertIcon />
          </span>
        </div>
      </div>

      {formOpen ? (
        <div id="ua-record-revenue-panel" className="mb-2">
        <Card>
          <SectionTitle>Record revenue</SectionTitle>
          <p className="mb-3 text-sm text-[var(--muted)]">
            Cash sale or other income paid now. Leave customer blank for walk-in.
          </p>
          {notice ? (
            <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
              {notice}
            </div>
          ) : null}
          <form className="grid gap-3 md:grid-cols-2" onSubmit={onRecordRevenue}>
            <div>
              <Label>Date</Label>
              <Input type="date" value={revenueDate} onChange={(e) => setRevenueDate(e.target.value)} required />
            </div>
            <div>
              <Label>Customer (optional)</Label>
              <Select value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
                <option value="">Walk-in / Other income</option>
                {(customers.data?.data ?? []).map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.code} — {c.name}
                  </option>
                ))}
              </Select>
            </div>
            <div className="md:col-span-2">
              <Label>Description</Label>
              <Input
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="e.g. Scrap sale, service fee, interest"
                required
              />
            </div>
            <div>
              <Label>Revenue account</Label>
              <Select value={revenueAccountId} onChange={(e) => setRevenueAccountId(e.target.value)} required>
                <option value="">Select account</option>
                {revenueAccounts.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} — {a.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Amount (TZS)</Label>
              <Input value={amount} onChange={(e) => setAmount(e.target.value)} required />
            </div>
            <div>
              <Label>Deposit to</Label>
              <Select value={depositKind} onChange={(e) => setDepositKind(e.target.value as DepositKind)}>
                <option value="bank">Bank / mobile</option>
                <option value="cash">Cash</option>
              </Select>
            </div>
            <div>
              {depositKind === 'bank' ? (
                <>
                  <Label>Bank / mobile account</Label>
                  <Select value={bankAccountId} onChange={(e) => setBankAccountId(e.target.value)} required>
                    <option value="">Select account</option>
                    {(banks.data?.data ?? []).map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.code} — {b.name}
                      </option>
                    ))}
                  </Select>
                </>
              ) : (
                <>
                  <Label>Cash account</Label>
                  <Select value={cashAccountId} onChange={(e) => setCashAccountId(e.target.value)} required>
                    <option value="">Select cash account</option>
                    {(cashAccounts.data?.data ?? []).map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.code} — {c.name}
                      </option>
                    ))}
                  </Select>
                </>
              )}
            </div>
            <div className="md:col-span-2">
              <Label>Reference (optional)</Label>
              <Input
                value={reference}
                onChange={(e) => setReference(e.target.value)}
                placeholder="Receipt no. / narration"
              />
            </div>
            <div className="md:col-span-2 flex flex-wrap gap-2">
              <Button disabled={createRevenue.isPending}>
                {createRevenue.isPending ? 'Posting…' : 'Record revenue'}
              </Button>
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  setFormOpen(false)
                  setError('')
                }}
              >
                Cancel
              </Button>
            </div>
          </form>
        </Card>
        </div>
      ) : null}

      <div className="mt-6">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <SectionTitle>
            Invoices ({filteredInvoices.length}
            {payFilter === 'all' && !invoiceQuery.trim()
              ? ` · ${unpaidCount} unpaid · ${partialCount} partial · ${paidCount} paid`
              : ''}
            )
          </SectionTitle>
          <div className="flex flex-wrap items-center gap-1.5">
            {([
              ['all', `All (${receivableInvoices.length})`],
              ['unpaid', `Unpaid (${unpaidCount})`],
              ['partial', `Partially paid (${partialCount})`],
              ['paid', `Paid (${paidCount})`],
            ] as const).map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => setPayFilter(key)}
                className={`rounded-full border px-3 py-1 text-xs transition ${
                  payFilter === key
                    ? 'border-[var(--brand)] bg-[var(--brand)] text-white'
                    : 'border-[var(--line)] bg-white text-[var(--ink)] hover:bg-[var(--surface)]'
                }`}
              >
                {label}
              </button>
            ))}
          </div>
        </div>

        {invoices.isLoading ? <Card>Loading invoices…</Card> : null}
        {!invoices.isLoading && filteredInvoices.length ? (
          <Table headers={['Number', 'Customer', 'Total', 'Balance', 'Status', '']}>
            {filteredInvoices.map((invoice) => {
              const bucket = paymentBucket(invoice)
              const canPay = bucket === 'unpaid' || bucket === 'partial'
              const menuOpen = openMenuId === invoice.id
              return (
                <tr
                  key={invoice.id}
                  className="cursor-pointer border-t border-[var(--line)] hover:bg-[var(--surface)]"
                  onClick={() => openInvoice(invoice)}
                >
                  <td className="px-4 py-3">
                    <div className="font-normal text-[var(--ink)]">{invoice.invoice_number}</div>
                    <div className="mt-0.5 text-xs text-[var(--muted)]">
                      {String(invoice.invoice_date).slice(0, 10)}
                    </div>
                  </td>
                  <td className="max-w-[280px] truncate px-4 py-3" title={invoice.customer?.name || ''}>
                    {invoice.customer?.name}
                  </td>
                  <td className="px-4 py-3 whitespace-nowrap">{money(invoice.total)}</td>
                  <td className="px-4 py-3 whitespace-nowrap">{money(invoice.balance_due)}</td>
                  <td className="px-4 py-3">
                    <span
                      className={
                        bucket === 'paid'
                          ? 'text-emerald-700'
                          : bucket === 'partial'
                            ? 'text-amber-700'
                            : 'text-rose-700'
                      }
                    >
                      {paymentLabel(bucket)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                    <div className="relative inline-flex justify-end" ref={menuOpen ? menuRef : undefined}>
                      <button
                        type="button"
                        className="inline-flex h-8 w-8 items-center justify-center text-[var(--muted)] hover:text-[var(--ink)]"
                        aria-label={`Actions for ${invoice.invoice_number}`}
                        aria-haspopup="menu"
                        aria-expanded={menuOpen}
                        onClick={() => setOpenMenuId(menuOpen ? null : invoice.id)}
                      >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                          <circle cx="12" cy="5" r="1.8" />
                          <circle cx="12" cy="12" r="1.8" />
                          <circle cx="12" cy="19" r="1.8" />
                        </svg>
                      </button>
                      {menuOpen ? (
                        <div
                          role="menu"
                          className="absolute right-0 top-full z-20 mt-1 min-w-[160px] rounded-md border border-[var(--line)] bg-white py-1 shadow-md"
                        >
                          {canPay ? (
                            <button
                              type="button"
                              role="menuitem"
                              className="block w-full px-3 py-2 text-left text-sm text-[var(--ink)] hover:bg-[var(--surface)]"
                              onClick={() => startPayInvoice(invoice)}
                            >
                              Pay invoice
                            </button>
                          ) : (
                            <div className="px-3 py-2 text-sm text-[var(--muted)]">Already paid</div>
                          )}
                        </div>
                      ) : null}
                    </div>
                  </td>
                </tr>
              )
            })}
          </Table>
        ) : (
          !invoices.isLoading && (
            <EmptyState
              title={
                invoiceQuery.trim()
                  ? 'No matching invoices'
                  : payFilter === 'all'
                    ? 'No invoices yet'
                    : `No ${paymentLabel(payFilter === 'partial' ? 'partial' : payFilter).toLowerCase()} invoices`
              }
              body={
                invoiceQuery.trim()
                  ? 'Try a different search, or clear the search box.'
                  : payFilter === 'all'
                    ? 'ERP sales invoices appear here after sync. Use Record revenue above for other income.'
                    : 'Try another filter to see unpaid, partially paid, or paid invoices.'
              }
            />
          )
        )}
      </div>

      {payingInvoice ? (
        <div
          className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-900/45 p-4 sm:items-center"
          role="presentation"
          onClick={() => setPayInvoiceId(null)}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="ua-pay-title"
            className="w-full max-w-md rounded-lg border border-[var(--line)] bg-white p-4 shadow-xl"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="mb-3 flex items-start justify-between gap-3">
              <div>
                <div id="ua-pay-title" className="text-base font-normal text-[var(--ink)]">
                  Pay {payingInvoice.invoice_number}
                </div>
                <div className="mt-1 text-sm text-[var(--muted)]">
                  {payingInvoice.customer?.name || '—'} · due {money(payingInvoice.balance_due)}
                </div>
              </div>
              <Button type="button" variant="secondary" onClick={() => setPayInvoiceId(null)}>
                Close
              </Button>
            </div>
            <form className="space-y-3" onSubmit={onPayReceipt}>
              <div>
                <Label>Deposit to</Label>
                <Select value={payDepositKind} onChange={(e) => setPayDepositKind(e.target.value as DepositKind)}>
                  <option value="bank">Bank / mobile</option>
                  <option value="cash">Cash</option>
                </Select>
              </div>
              <div>
                {payDepositKind === 'bank' ? (
                  <>
                    <Label>Bank / mobile account</Label>
                    <Select value={payBankAccountId} onChange={(e) => setPayBankAccountId(e.target.value)} required>
                      <option value="">Select account</option>
                      {(banks.data?.data ?? []).map((b) => (
                        <option key={b.id} value={b.id}>
                          {b.code} — {b.name}
                        </option>
                      ))}
                    </Select>
                  </>
                ) : (
                  <>
                    <Label>Cash account</Label>
                    <Select value={payCashAccountId} onChange={(e) => setPayCashAccountId(e.target.value)} required>
                      <option value="">Select cash account</option>
                      {(cashAccounts.data?.data ?? []).map((c) => (
                        <option key={c.id} value={c.id}>
                          {c.code} — {c.name}
                        </option>
                      ))}
                    </Select>
                  </>
                )}
              </div>
              <div>
                <Label>Amount (optional = full balance)</Label>
                <Input value={payAmount} onChange={(e) => setPayAmount(e.target.value)} />
              </div>
              <Button disabled={createReceipt.isPending}>
                {createReceipt.isPending ? 'Posting…' : 'Post payment'}
              </Button>
            </form>
          </div>
        </div>
      ) : null}

      {viewedInvoice ? (
        <div
          className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-900/45 p-4 sm:items-center"
          role="presentation"
          onClick={() => setViewInvoiceId(null)}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="ua-invoice-title"
            className="w-full max-w-3xl rounded-lg border border-[var(--line)] bg-white p-4 shadow-xl"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
              <div>
                <div id="ua-invoice-title" className="text-base font-normal text-[var(--ink)]">
                  {viewedInvoice.invoice_number}
                </div>
                <div className="mt-1 text-sm text-[var(--muted)]">
                  {viewedInvoice.customer?.name || '—'}
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <span
                  className={
                    viewedBucket === 'paid'
                      ? 'text-emerald-700'
                      : viewedBucket === 'partial'
                        ? 'text-amber-700'
                        : 'text-rose-700'
                  }
                >
                  {paymentLabel(viewedBucket || 'other')}
                </span>
                <Button type="button" variant="secondary" onClick={() => setViewInvoiceId(null)}>
                  Close
                </Button>
              </div>
            </div>

            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <Label>Date</Label>
                <div className="text-sm text-[var(--ink)]">{String(viewedInvoice.invoice_date).slice(0, 10)}</div>
              </div>
              <div>
                <Label>Due date</Label>
                <div className="text-sm text-[var(--ink)]">
                  {viewedInvoice.due_date ? String(viewedInvoice.due_date).slice(0, 10) : '—'}
                </div>
              </div>
              <div>
                <Label>Total</Label>
                <div className="text-sm text-[var(--ink)]">{money(viewedInvoice.total)}</div>
              </div>
              <div>
                <Label>Balance due</Label>
                <div className="text-sm text-[var(--ink)]">{money(viewedInvoice.balance_due)}</div>
              </div>
            </div>

            {viewedInvoice.description ? (
              <div className="mb-4">
                <Label>Notes</Label>
                <div className="text-sm text-[var(--ink)]">{viewedInvoice.description}</div>
              </div>
            ) : null}

            <div className="mb-2 text-sm text-[var(--muted)]">Line items</div>
            {(viewedInvoice.lines ?? []).length ? (
              <div className="overflow-x-auto rounded-md border border-[var(--line)]">
                <table className="w-full text-left text-sm">
                  <thead className="bg-[var(--surface)] text-[var(--muted)]">
                    <tr>
                      <th className="px-3 py-2 font-normal">Description</th>
                      <th className="px-3 py-2 font-normal text-right">Qty</th>
                      <th className="px-3 py-2 font-normal text-right">Unit price</th>
                      <th className="px-3 py-2 font-normal text-right">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(viewedInvoice.lines ?? []).map((line, index) => (
                      <tr key={line.id ?? index} className="border-t border-[var(--line)]">
                        <td className="px-3 py-2">{line.description}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">{money(line.quantity ?? 1)}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">{money(line.unit_price)}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">{money(line.line_total)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="rounded-md border border-dashed border-[var(--line)] px-3 py-4 text-sm text-[var(--muted)]">
                No line items on this invoice.
              </div>
            )}

            <div className="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-[var(--line)] pt-4">
              <div className="grid gap-1 text-sm">
                <div className="flex gap-6">
                  <span className="text-[var(--muted)]">Subtotal</span>
                  <span>{money(viewedInvoice.subtotal ?? viewedInvoice.total)}</span>
                </div>
                <div className="flex gap-6">
                  <span className="text-[var(--muted)]">Tax</span>
                  <span>{money(viewedInvoice.tax_total ?? 0)}</span>
                </div>
                <div className="flex gap-6">
                  <span className="text-[var(--muted)]">Paid</span>
                  <span>{money(viewedInvoice.amount_paid ?? 0)}</span>
                </div>
              </div>
              {viewedCanPay ? (
                <Button type="button" onClick={() => startPayInvoice(viewedInvoice)}>
                  Pay invoice
                </Button>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
