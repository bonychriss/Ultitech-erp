import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table } from '../components/ui'

type StatementTab = 'pl' | 'bs' | 'cf' | 'equity' | 'gl'

export function FinancialStatementsPage() {
  const { token, companyId } = useAuth()
  const [tab, setTab] = useState<StatementTab>('pl')
  const year = new Date().getFullYear()
  const [from, setFrom] = useState(`${year}-01-01`)
  const [to, setTo] = useState(`${year}-12-31`)
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10))
  const [accountId, setAccountId] = useState('')

  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{ data: Array<{ id: number; code: string; name: string }> }>('/accounts', { token, companyId }),
  })

  const pl = useQuery({
    queryKey: ['pl', companyId, from, to],
    enabled: Boolean(token && companyId && tab === 'pl'),
    queryFn: () => apiRequest<any>(`/reports/profit-and-loss?from=${from}&to=${to}`, { token, companyId }),
  })

  const bs = useQuery({
    queryKey: ['bs', companyId, asOf],
    enabled: Boolean(token && companyId && tab === 'bs'),
    queryFn: () => apiRequest<any>(`/reports/balance-sheet?as_of=${asOf}`, { token, companyId }),
  })

  const cf = useQuery({
    queryKey: ['cf', companyId, from, to],
    enabled: Boolean(token && companyId && tab === 'cf'),
    queryFn: () => apiRequest<any>(`/reports/cash-flow?from=${from}&to=${to}`, { token, companyId }),
  })

  const equity = useQuery({
    queryKey: ['equity', companyId, from, to],
    enabled: Boolean(token && companyId && tab === 'equity'),
    queryFn: () => apiRequest<any>(`/reports/equity?from=${from}&to=${to}`, { token, companyId }),
  })

  const gl = useQuery({
    queryKey: ['gl', companyId, from, to, accountId],
    enabled: Boolean(token && companyId && tab === 'gl'),
    queryFn: () => {
      const qs = new URLSearchParams({ from, to })
      if (accountId) qs.set('account_id', accountId)
      return apiRequest<any>(`/reports/general-ledger?${qs.toString()}`, { token, companyId })
    },
  })

  const activeError = useMemo(() => {
    const q = { pl, bs, cf, equity, gl }[tab]
    return q.isError ? (q.error as Error).message : ''
  }, [tab, pl, bs, cf, equity, gl])

  const tabs: Array<{ id: StatementTab; label: string }> = [
    { id: 'pl', label: 'Profit & Loss' },
    { id: 'bs', label: 'Balance Sheet' },
    { id: 'cf', label: 'Cash Flow' },
    { id: 'equity', label: 'Equity' },
    { id: 'gl', label: 'General Ledger' },
  ]

  return (
    <div>
      <PageHeader title="Financial Statements" subtitle="Professional reports from the general ledger" />

      <div className="mb-4 flex flex-wrap gap-2">
        {tabs.map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={() => setTab(item.id)}
            className={`rounded-lg px-3 py-2 text-sm ${tab === item.id ? 'bg-[var(--brand)] text-white' : 'border border-[var(--line)] bg-white text-[var(--ink)]'}`}
          >
            {item.label}
          </button>
        ))}
      </div>

      <Card className="mb-4">
        <div className="grid gap-3 md:grid-cols-4">
          {tab !== 'bs' ? (
            <>
              <div>
                <Label>From</Label>
                <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              </div>
              <div>
                <Label>To</Label>
                <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
              </div>
            </>
          ) : (
            <div>
              <Label>As of</Label>
              <Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
            </div>
          )}
          {tab === 'gl' ? (
            <div>
              <Label>Account (optional)</Label>
              <Select value={accountId} onChange={(e) => setAccountId(e.target.value)}>
                <option value="">All accounts</option>
                {(accounts.data?.data ?? []).map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} — {a.name}
                  </option>
                ))}
              </Select>
            </div>
          ) : null}
        </div>
      </Card>

      {activeError ? <ErrorBanner message={activeError} /> : null}

      {tab === 'pl' && pl.data ? (
        <div className="grid gap-4">
          <Card>
            <div className="mb-3 text-sm text-[var(--muted)]">
              Period {pl.data.period.from} to {pl.data.period.to}
            </div>
            <div className="mb-2 font-normal ua-empty-title">Revenue</div>
            <AmountTable rows={pl.data.revenue} />
            <div className="mb-2 mt-4 font-normal ua-empty-title">Expenses</div>
            <AmountTable rows={pl.data.expenses} />
            <div className="mt-4 grid gap-2 border-t border-[var(--line)] pt-3 text-sm md:grid-cols-3">
              <div>Total revenue: <strong>{pl.data.totals.revenue}</strong></div>
              <div>Total expenses: <strong>{pl.data.totals.expenses}</strong></div>
              <div>Net profit: <strong className="text-[var(--ok)]">{pl.data.totals.net_profit}</strong></div>
            </div>
          </Card>
        </div>
      ) : null}

      {tab === 'bs' && bs.data ? (
        <Card>
          <div className="mb-3 text-sm text-[var(--muted)]">As of {bs.data.as_of}</div>
          <div className="mb-2 font-normal ua-empty-title">Assets</div>
          <AmountTable rows={bs.data.assets} />
          <div className="mb-2 mt-4 font-normal ua-empty-title">Liabilities</div>
          <AmountTable rows={bs.data.liabilities} />
          <div className="mb-2 mt-4 font-normal ua-empty-title">Equity</div>
          <AmountTable rows={bs.data.equity} />
          <div className="mt-4 grid gap-2 border-t border-[var(--line)] pt-3 text-sm md:grid-cols-3">
            <div>Assets: <strong>{bs.data.totals.assets}</strong></div>
            <div>Liabilities + Equity: <strong>{bs.data.totals.liabilities_and_equity}</strong></div>
            <div className={bs.data.totals.balanced ? 'text-[var(--ok)]' : 'text-[var(--danger)]'}>
              {bs.data.totals.balanced ? 'Balanced' : 'Out of balance'}
            </div>
          </div>
        </Card>
      ) : null}

      {tab === 'cf' && cf.data ? (
        <Card>
          <div className="mb-3 text-sm text-[var(--muted)]">
            Period {cf.data.period.from} to {cf.data.period.to}
          </div>
          <div className="grid gap-2 text-sm md:grid-cols-2">
            <div>Net profit: <strong>{cf.data.operating_activities.net_profit}</strong></div>
            <div>Customer receipts: <strong>{cf.data.operating_activities.customer_receipts}</strong></div>
            <div>Payments: <strong>{cf.data.operating_activities.supplier_and_other_payments}</strong></div>
            <div>Net operating cash: <strong>{cf.data.operating_activities.net_cash_from_operating}</strong></div>
            <div>Opening cash: <strong>{cf.data.cash_reconciliation.opening_cash}</strong></div>
            <div>Closing cash: <strong>{cf.data.cash_reconciliation.closing_cash}</strong></div>
            <div className={cf.data.cash_reconciliation.reconciled ? 'text-[var(--ok)]' : 'text-[var(--danger)]'}>
              Cash reconciliation: {cf.data.cash_reconciliation.reconciled ? 'OK' : 'Check'}
            </div>
          </div>
        </Card>
      ) : null}

      {tab === 'equity' && equity.data ? (
        <Card>
          <div className="mb-3 text-sm text-[var(--muted)]">
            Period {equity.data.period.from} to {equity.data.period.to}
          </div>
          <div className="grid gap-2 text-sm md:grid-cols-2">
            <div>Opening equity: <strong>{equity.data.opening_equity}</strong></div>
            <div>Net profit: <strong>{equity.data.net_profit}</strong></div>
            <div>Other movements: <strong>{equity.data.other_movements}</strong></div>
            <div>Closing equity: <strong>{equity.data.closing_equity}</strong></div>
          </div>
        </Card>
      ) : null}

      {tab === 'gl' && gl.data ? (
        gl.data.data?.length ? (
          <Table headers={['Date', 'Journal', 'Account', 'Description', 'Debit', 'Credit', 'Balance']}>
            {gl.data.data.map((row: any, idx: number) => (
              <tr key={`${row.journal_id}-${idx}`} className="border-t border-[var(--line)]">
                <td className="px-4 py-3">{String(row.journal_date).slice(0, 10)}</td>
                <td className="px-4 py-3">{row.journal_number}</td>
                <td className="px-4 py-3">{row.account_code}</td>
                <td className="px-4 py-3">{row.description}</td>
                <td className="px-4 py-3">{row.debit}</td>
                <td className="px-4 py-3">{row.credit}</td>
                <td className="px-4 py-3">{row.running_balance}</td>
              </tr>
            ))}
          </Table>
        ) : (
          <EmptyState title="No ledger activity" body="Post journals, invoices or bills in the selected period." />
        )
      ) : null}
    </div>
  )
}

function AmountTable({ rows }: { rows: Array<{ account_code: string; account_name: string; amount: string }> }) {
  if (!rows?.length) {
    return <p className="text-sm text-[var(--muted)]">No lines for this section.</p>
  }

  return (
    <Table headers={['Code', 'Account', 'Amount']}>
      {rows.map((row) => (
        <tr key={`${row.account_code}-${row.account_name}`} className="border-t border-[var(--line)]">
          <td className="px-4 py-3 font-normal">{row.account_code}</td>
          <td className="px-4 py-3">{row.account_name}</td>
          <td className="px-4 py-3">{row.amount}</td>
        </tr>
      ))}
    </Table>
  )
}
