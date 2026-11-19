import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table, SectionTitle } from '../components/ui'

type Customer = {id: number; code: string; name: string }
type Account = {id: number; code: string; name: string }
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
type BankAccount = { id: number; code: string; name: string; account_type?: string }
type CashAccount = { id: number; code: string; name: string }
type PayFilter = 'all' | 'unpaid' | 'partial' | 'paid'
type DepositKind = 'bank' | 'cash'

function money(value: string | number | undefined) {
  const n = Number(value || 0)
  if (!Number.isFinite(n)) return '0.00'
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
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

export function RevenuePage() {
  const { token, companyId, bookId, embed, classicAccountingUrl } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [customerId, setCustomerId] = useState('')
  const [amount, setAmount] = useState('')
  const [description, setDescription] = useState('')
  const [revenueAccountId, setRevenueAccountId] = useState('')
  const [receiptInvoiceId, setReceiptInvoiceId] = useState('')
  const [receiptAmount, setReceiptAmount] = useState('')
  const [bankAccountId, setBankAccountId] = useState('')
  const [manualDate, setManualDate] = useState(todayIso)
  const [manualCustomerId, setManualCustomerId] = useState('')
  const [manualDescription, setManualDescription] = useState('')
  const [manualAmount, setManualAmount] = useState('')
  const [manualRevenueAccountId, setManualRevenueAccountId] = useState('')
  const [manualDepositKind, setManualDepositKind] = useState<DepositKind>('bank')
  const [manualBankAccountId, setManualBankAccountId] = useState('')
  const [manualCashAccountId, setManualCashAccountId] = useState('')
  const [manualReference, setManualReference] = useState('')
  const [manualNotice, setManualNotice] = useState('')
  const [payFilter, setPayFilter] = useState<PayFilter>('all')
  const [openMenuId, setOpenMenuId] = useState<number | null>(null)
  const [viewInvoiceId, setViewInvoiceId] = useState<number | null>(null)
  const receiptCardRef = useRef<HTMLDivElement | null>(null)
  const menuRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (openMenuId == null && viewInvoiceId == null) return
    const onDoc = (e: MouseEvent) => {
      if (openMenuId != null && menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setOpenMenuId(null)
      }
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return
      if (viewInvoiceId != null) setViewInvoiceId(null)
      else setOpenMenuId(null)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [openMenuId, viewInvoiceId])

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
    setReceiptInvoiceId(String(invoice.id))
    setReceiptAmount(String(invoice.balance_due || ''))
    if (!bankAccountId && (banks.data?.data?.length ?? 0) > 0) {
      setBankAccountId(String(banks.data!.data[0].id))
    }
    requestAnimationFrame(() => {
      receiptCardRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    })
  }

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
    if (!manualRevenueAccountId && otherIncomeAccount) {
      setManualRevenueAccountId(String(otherIncomeAccount.id))
    }
  }, [manualRevenueAccountId, otherIncomeAccount])

  useEffect(() => {
    if (!manualBankAccountId && (banks.data?.data?.length ?? 0) > 0) {
      setManualBankAccountId(String(banks.data!.data[0].id))
    }
  }, [manualBankAccountId, banks.data])

  useEffect(() => {
    if (!manualCashAccountId && (cashAccounts.data?.data?.length ?? 0) > 0) {
      setManualCashAccountId(String(cashAccounts.data!.data[0].id))
    }
  }, [manualCashAccountId, cashAccounts.data])
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
    if (payFilter === 'all') return receivableInvoices
    return receivableInvoices.filter((i) => paymentBucket(i) === payFilter)
  }, [receivableInvoices, payFilter])
  const openInvoices = useMemo(
    () => receivableInvoices.filter((i) => {
      const bucket = paymentBucket(i)
      return bucket === 'unpaid' || bucket === 'partial'
    }),
    [receivableInvoices],
  )
  const viewedInvoice = useMemo(
    () => (invoices.data?.data ?? []).find((i) => i.id === viewInvoiceId) ?? null,
    [invoices.data, viewInvoiceId],
  )
  const viewedBucket = viewedInvoice ? paymentBucket(viewedInvoice) : null
  const viewedCanPay = viewedBucket === 'unpaid' || viewedBucket === 'partial'

  const createInvoice = useMutation({
    mutationFn: () =>
      apiRequest('/customer-invoices', {
        method: 'POST',
        token,
        companyId,
        bookId,
        body: {
          customer_id: Number(customerId),
          invoice_date: todayIso(),
          currency_code: 'TZS',
          description,
          lines: [
            {
              description,
              quantity: 1,
              unit_price: amount,
              revenue_account_id: Number(revenueAccountId),
            },
          ],
        },
      }),
    onSuccess: async () => {
      setError('')
      setDescription('')
      setAmount('')
      await Promise.all([
        client.invalidateQueries({ queryKey: ['customer-invoices', companyId] }),
        client.invalidateQueries({ queryKey: ['trial-balance', companyId] }),
        client.invalidateQueries({ queryKey: ['journals', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const createReceipt = useMutation({
    mutationFn: () => {
      const invoice = openInvoices.find((i) => String(i.id) === receiptInvoiceId)
      if (!invoice) throw new Error('Select an invoice')
      return apiRequest('/customer-receipts', {
        method: 'POST',
        token,
        companyId,
        bookId,
        body: {
          customer_id: invoice.customer?.id,
          receipt_date: todayIso(),
          currency_code: 'TZS',
          amount: receiptAmount || invoice.balance_due,
          bank_account_id: Number(bankAccountId),
          allocations: [
            {
              invoice_id: invoice.id,
              amount: receiptAmount || invoice.balance_due,
            },
          ],
        },
      })
    },
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({ queryKey: ['customer-invoices', companyId] }),
        client.invalidateQueries({ queryKey: ['trial-balance', companyId] }),
        client.invalidateQueries({ queryKey: ['bank-transactions', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const createManualRevenue = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = {
        revenue_date: manualDate || todayIso(),
        currency_code: 'TZS',
        description: manualDescription.trim(),
        amount: manualAmount,
        revenue_account_id: Number(manualRevenueAccountId),
        reference: manualReference.trim() || undefined,
      }
      if (manualCustomerId) body.customer_id = Number(manualCustomerId)
      if (manualDepositKind === 'bank') {
        body.bank_account_id = Number(manualBankAccountId)
      } else {
        body.cash_account_id = Number(manualCashAccountId)
      }
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
      setManualNotice(number ? `Recorded as ${number} (paid).` : 'Manual revenue recorded.')
      setManualDescription('')
      setManualAmount('')
      setManualReference('')
      setManualCustomerId('')
      setManualDate(todayIso())
      await Promise.all([
        client.invalidateQueries({ queryKey: ['customer-invoices', companyId] }),
        client.invalidateQueries({ queryKey: ['customers', companyId] }),
        client.invalidateQueries({ queryKey: ['trial-balance', companyId] }),
        client.invalidateQueries({ queryKey: ['journals', companyId] }),
        client.invalidateQueries({ queryKey: ['bank-transactions', companyId] }),
      ])
      setPayFilter('paid')
    },
    onError: (err) => {
      setManualNotice('')
      setError((err as Error).message)
    },
  })

  const onInvoice = (e: FormEvent) => {
    e.preventDefault()
    createInvoice.mutate()
  }

  const onReceipt = (e: FormEvent) => {
    e.preventDefault()
    createReceipt.mutate()
  }

  const onManualRevenue = (e: FormEvent) => {
    e.preventDefault()
    setManualNotice('')
    if (manualDepositKind === 'bank' && !manualBankAccountId) {
      setError('Select a bank or mobile account to deposit into.')
      return
    }
    if (manualDepositKind === 'cash' && !manualCashAccountId) {
      setError('Select a cash account to deposit into.')
      return
    }
    createManualRevenue.mutate()
  }

  return (
    <div>
      <PageHeader
        title="Revenue"
        subtitle="Sales invoices sync from ERP. Receive payments on open AR, or record manual / other income here."
      />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Post sales invoice</SectionTitle>
          <p className="mb-3 text-sm text-[var(--muted)]">
            Billed revenue on account (AR). Use when the customer will pay later.
          </p>
          <form className="space-y-3" onSubmit={onInvoice}>
            <div>
              <Label>Customer</Label>
              <Select value={customerId} onChange={(e) => setCustomerId(e.target.value)} required>
                <option value="">Select customer</option>
                {(customers.data?.data ?? []).map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.code} — {c.name}
                  </option>
                ))}
              </Select>
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
              <Label>Description</Label>
              <Input value={description} onChange={(e) => setDescription(e.target.value)} required />
            </div>
            <div>
              <Label>Amount (TZS)</Label>
              <Input value={amount} onChange={(e) => setAmount(e.target.value)} required />
            </div>
            <Button disabled={createInvoice.isPending}>{createInvoice.isPending ? 'Posting…' : 'Create & post invoice'}</Button>
          </form>
        </Card>

        <Card className="scroll-mt-4">
          <div ref={receiptCardRef}>
            <SectionTitle>Receive payment</SectionTitle>
            <p className="mb-3 text-sm text-[var(--muted)]">
              Collect against an open sales invoice (including ERP-synced invoices).
            </p>
            <form className="space-y-3" onSubmit={onReceipt}>
              <div>
                <Label>Open invoice</Label>
                <Select value={receiptInvoiceId} onChange={(e) => setReceiptInvoiceId(e.target.value)} required>
                  <option value="">Select invoice</option>
                  {openInvoices.map((invoice) => (
                    <option key={invoice.id} value={invoice.id}>
                      {invoice.invoice_number} — {invoice.customer?.name} — due {invoice.balance_due}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
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
              <div>
                <Label>Amount (optional = full balance)</Label>
                <Input value={receiptAmount} onChange={(e) => setReceiptAmount(e.target.value)} />
              </div>
              <Button disabled={createReceipt.isPending}>{createReceipt.isPending ? 'Posting…' : 'Create & post receipt'}</Button>
            </form>
          </div>
        </Card>
      </div>

      <div className="mt-6">
        <Card>
          <SectionTitle>Manual revenue</SectionTitle>
          <p className="mb-3 text-sm text-[var(--muted)]">
            Other income or cash sale paid now — posts income and deposits to bank/mobile/cash in one step.
            Leave customer blank for walk-in / other income.
          </p>
          {manualNotice ? (
            <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
              {manualNotice}
            </div>
          ) : null}
          <form className="grid gap-3 md:grid-cols-2" onSubmit={onManualRevenue}>
            <div>
              <Label>Date</Label>
              <Input type="date" value={manualDate} onChange={(e) => setManualDate(e.target.value)} required />
            </div>
            <div>
              <Label>Customer (optional)</Label>
              <Select value={manualCustomerId} onChange={(e) => setManualCustomerId(e.target.value)}>
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
                value={manualDescription}
                onChange={(e) => setManualDescription(e.target.value)}
                placeholder="e.g. Scrap sale, service fee, interest"
                required
              />
            </div>
            <div>
              <Label>Revenue account</Label>
              <Select
                value={manualRevenueAccountId}
                onChange={(e) => setManualRevenueAccountId(e.target.value)}
                required
              >
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
              <Input value={manualAmount} onChange={(e) => setManualAmount(e.target.value)} required />
            </div>
            <div>
              <Label>Deposit to</Label>
              <Select
                value={manualDepositKind}
                onChange={(e) => setManualDepositKind(e.target.value as DepositKind)}
              >
                <option value="bank">Bank / mobile</option>
                <option value="cash">Cash</option>
              </Select>
            </div>
            <div>
              {manualDepositKind === 'bank' ? (
                <>
                  <Label>Bank / mobile account</Label>
                  <Select
                    value={manualBankAccountId}
                    onChange={(e) => setManualBankAccountId(e.target.value)}
                    required
                  >
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
                  <Select
                    value={manualCashAccountId}
                    onChange={(e) => setManualCashAccountId(e.target.value)}
                    required
                  >
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
                value={manualReference}
                onChange={(e) => setManualReference(e.target.value)}
                placeholder="Receipt no. / narration"
              />
            </div>
            <div className="md:col-span-2">
              <Button disabled={createManualRevenue.isPending}>
                {createManualRevenue.isPending ? 'Posting…' : 'Record manual revenue'}
              </Button>
            </div>
          </form>
        </Card>
      </div>

      <div className="mt-6">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <SectionTitle>
            Invoices ({filteredInvoices.length}
            {payFilter === 'all' ? ` · ${unpaidCount} unpaid · ${partialCount} partial · ${paidCount} paid` : ''})
          </SectionTitle>
          <div className="flex flex-wrap gap-1.5">
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
          <Table headers={['Number', 'Customer', 'Date', 'Total', 'Balance', 'Status', '']}>
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
                  <td className="px-4 py-3 font-normal">{invoice.invoice_number}</td>
                  <td className="max-w-[280px] truncate px-4 py-3" title={invoice.customer?.name || ''}>
                    {invoice.customer?.name}
                  </td>
                  <td className="px-4 py-3">{String(invoice.invoice_date).slice(0, 10)}</td>
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
              title={payFilter === 'all' ? 'No invoices yet' : `No ${paymentLabel(payFilter === 'partial' ? 'partial' : payFilter).toLowerCase()} invoices`}
              body={
                payFilter === 'all'
                  ? 'Sales invoices created in the system appear here after ERP sync. You can also post an invoice above.'
                  : 'Try another filter to see unpaid, partially paid, or paid invoices.'
              }
            />
          )
        )}
      </div>

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
