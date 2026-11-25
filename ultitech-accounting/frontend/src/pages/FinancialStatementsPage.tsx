import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table } from '../components/ui'

type StatementTab = 'pl' | 'bs' | 'cf' | 'equity' | 'gl'
type ReportPeriod = 'this_year' | 'last_year' | 'this_quarter' | 'last_quarter' | 'custom'
type GroupBy = 'month' | 'quarter' | 'total'

type PlLine = {
  account_id: number
  account_code: string
  account_name: string
  amount: string
  amounts?: Record<string, string>
}

type PlColumn = { key: string; label: string; from?: string; to?: string }

type PlResponse = {
  period: { from: string; to: string }
  columns?: PlColumn[]
  income?: PlLine[]
  revenue?: PlLine[]
  expenses?: PlLine[]
  cogs?: PlLine[]
  operating_expenses?: PlLine[]
  totals: {
    revenue: string
    income?: string
    expenses: string
    net_profit: string
    income_by_column?: Record<string, string>
    expenses_by_column?: Record<string, string>
    net_profit_by_column?: Record<string, string>
  }
}

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`
}

function yearBounds(year: number): { from: string; to: string } {
  return { from: `${year}-01-01`, to: `${year}-12-31` }
}

function quarterBounds(ref: Date, offset = 0): { from: string; to: string } {
  const q = Math.floor(ref.getMonth() / 3) + offset
  const year = ref.getFullYear() + Math.floor(q / 4)
  const qi = ((q % 4) + 4) % 4
  const startMonth = qi * 3
  const from = new Date(year, startMonth, 1)
  const to = new Date(year, startMonth + 3, 0)
  return { from: isoDate(from), to: isoDate(to) }
}

function applyReportPeriod(period: ReportPeriod, ref = new Date()): { from: string; to: string } {
  const y = ref.getFullYear()
  if (period === 'this_year') return yearBounds(y)
  if (period === 'last_year') return yearBounds(y - 1)
  if (period === 'this_quarter') return quarterBounds(ref, 0)
  if (period === 'last_quarter') return quarterBounds(ref, -1)
  return { from: isoDate(ref), to: isoDate(ref) }
}

function money(value: string | number | undefined | null): string {
  const n = Number(value ?? 0)
  // Compact grouping so month columns stay readable in a full-year table.
  const abs = Math.abs(n).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
    useGrouping: true,
  })
  return n < 0 ? `-${abs}` : abs
}

/** Compact period headers so many month/quarter columns fit without horizontal scroll. */
function shortColumnLabel(label: string, key: string): string {
  if (key === 'total') return 'TOTAL'
  const q = label.match(/^([A-Z]{3})\s+(\d{4})-([A-Z]{3})\s+(\d{4})$/i)
  if (q) return `${q[1]}-${q[3]}`
  const m = label.match(/^([A-Z]{3})\s+(\d{4})$/i)
  if (m) return m[1]
  return label
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function downloadBlob(filename: string, blob: Blob): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function lineAmount(line: PlLine, colKey: string): string {
  if (line.amounts && line.amounts[colKey] != null) return line.amounts[colKey]
  if (colKey === 'total') return line.amount
  return '0.0000'
}

export function FinancialStatementsPage() {
  const { token, companyId, companies, embed } = useAuth()
  const companyName =
    companies.find((c) => c.id === companyId)?.legal_name ||
    embed.erpCompanyName ||
    'Company'
  const companyLogoUrl = String(embed.companyLogoUrl || '').trim()
  const [tab, setTab] = useState<StatementTab>('pl')
  const now = new Date()
  const initialPeriod = applyReportPeriod('this_year', now)
  const [reportPeriod, setReportPeriod] = useState<ReportPeriod>('this_year')
  const [from, setFrom] = useState(initialPeriod.from)
  const [to, setTo] = useState(initialPeriod.to)
  const [groupBy, setGroupBy] = useState<GroupBy>('month')
  const [expanded, setExpanded] = useState(true)
  const [actionsOpen, setActionsOpen] = useState(false)
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10))
  const [accountId, setAccountId] = useState('')
  const printRef = useRef<HTMLDivElement | null>(null)
  const actionsWrapRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!actionsOpen) return
    const onDoc = (e: MouseEvent) => {
      const el = actionsWrapRef.current
      if (el && !el.contains(e.target as Node)) setActionsOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [actionsOpen])

  const onReportPeriodChange = (period: ReportPeriod) => {
    setReportPeriod(period)
    if (period === 'custom') return
    const bounds = applyReportPeriod(period)
    setFrom(bounds.from)
    setTo(bounds.to)
  }

  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{ data: Array<{ id: number; code: string; name: string }> }>('/accounts', { token, companyId }),
  })

  const plQs = useMemo(() => {
    return new URLSearchParams({ from, to, columns: groupBy }).toString()
  }, [from, to, groupBy])

  const pl = useQuery({
    queryKey: ['pl', companyId, plQs],
    enabled: Boolean(token && companyId && tab === 'pl'),
    queryFn: () => apiRequest<PlResponse>(`/reports/profit-and-loss?${plQs}`, { token, companyId }),
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

  const plData = pl.data
  const columns: PlColumn[] = plData?.columns?.length
    ? plData.columns
    : [{ key: 'total', label: 'TOTAL' }]

  const incomeLines = useMemo(() => plData?.income ?? plData?.revenue ?? [], [plData])
  // Reference layout: one EXPENSES bucket (COGS + operating).
  const expenseLines = useMemo(() => {
    if (plData?.expenses?.length) return plData.expenses
    return [...(plData?.cogs ?? []), ...(plData?.operating_expenses ?? [])]
  }, [plData])

  const totals = plData?.totals
  const incomeTotal = totals?.income ?? totals?.revenue ?? '0'
  const expenseTotal = totals?.expenses ?? '0'
  const netProfit = totals?.net_profit ?? '0'

  const amountCells = (byCol: Record<string, string> | undefined, fallback: string, strong = false) =>
    columns.map((col) => {
      const raw = byCol?.[col.key] ?? (col.key === 'total' ? fallback : '0')
      const n = Number(raw)
      return (
        <td key={col.key} className={`ua-pl-num${strong ? ' is-strong' : ''}${n < 0 ? ' is-neg' : ''}`}>
          {money(raw)}
        </td>
      )
    })

  const buildExportRows = () => {
    if (!plData) return [] as Array<{ label: string; amounts: Record<string, string>; kind: string }>
    const rows: Array<{ label: string; amounts: Record<string, string>; kind: string }> = []
    rows.push({ label: 'INCOME', amounts: {}, kind: 'section' })
    incomeLines.forEach((line) => {
      const amounts: Record<string, string> = {}
      columns.forEach((c) => {
        amounts[c.key] = lineAmount(line, c.key)
      })
      rows.push({ label: line.account_name, amounts, kind: 'line' })
    })
    rows.push({
      label: 'TOTAL',
      amounts: totals?.income_by_column ?? { total: incomeTotal },
      kind: 'total',
    })
    rows.push({ label: 'EXPENSES', amounts: {}, kind: 'section' })
    expenseLines.forEach((line) => {
      const amounts: Record<string, string> = {}
      columns.forEach((c) => {
        amounts[c.key] = lineAmount(line, c.key)
      })
      rows.push({ label: line.account_name, amounts, kind: 'line' })
    })
    rows.push({
      label: 'TOTAL',
      amounts: totals?.expenses_by_column ?? { total: expenseTotal },
      kind: 'total',
    })
    rows.push({
      label: 'NET PROFIT',
      amounts: totals?.net_profit_by_column ?? { total: netProfit },
      kind: 'profit',
    })
    return rows
  }

  const onExportExcel = () => {
    if (!plData) return
    const rows = buildExportRows()
    const headerCells = ['', ...columns.map((c) => c.label)]
      .map((h) => `<Cell ss:StyleID="Bold"><Data ss:Type="String">${escapeHtml(h)}</Data></Cell>`)
      .join('')
    const body = rows
      .map((row) => {
        const cells = [
          `<Cell ss:StyleID="${row.kind === 'line' ? 'Normal' : 'Bold'}"><Data ss:Type="String">${escapeHtml(row.label)}</Data></Cell>`,
          ...columns.map((c) => {
            if (row.kind === 'section') return `<Cell><Data ss:Type="String"></Data></Cell>`
            const raw = row.amounts[c.key] ?? '0'
            return `<Cell ss:StyleID="Num"><Data ss:Type="Number">${Number(raw)}</Data></Cell>`
          }),
        ].join('')
        return `<Row>${cells}</Row>`
      })
      .join('')

    const xml = `<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Num"><NumberFormat ss:Format="#,##0.00"/></Style>
  <Style ss:ID="Bold"><Font ss:Bold="1"/></Style>
  <Style ss:ID="Normal"/>
 </Styles>
 <Worksheet ss:Name="Profit and Loss">
  <Table>
   <Row><Cell ss:StyleID="Bold"><Data ss:Type="String">Profit &amp; Loss</Data></Cell></Row>
   <Row><Cell><Data ss:Type="String">${escapeHtml(from)} to ${escapeHtml(to)} · Group by ${escapeHtml(groupBy)}</Data></Cell></Row>
   <Row/>
   <Row>${headerCells}</Row>
   ${body}
  </Table>
 </Worksheet>
</Workbook>`
    downloadBlob(`profit-and-loss-${from}_${to}.xls`, new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8' }))
  }

  const onPrint = () => {
    if (!printRef.current || !plData) return
    document.getElementById('ua-pl-print-root')?.remove()

    const colKeys = columns.map((c) => c.key)
    const th = columns
      .map((c) => `<th class="num">${escapeHtml(shortColumnLabel(c.label, c.key))}</th>`)
      .join('')

    const renderLines = (lines: PlLine[]) =>
      lines
        .map((line) => {
          const cells = colKeys
            .map((key) => `<td class="num">${escapeHtml(money(lineAmount(line, key)))}</td>`)
            .join('')
          return `<tr><td class="label">${escapeHtml(line.account_name)}</td>${cells}</tr>`
        })
        .join('')

    const renderTotal = (label: string, byCol: Record<string, string> | undefined, fallback: string) => {
      const cells = colKeys
        .map((key) => {
          const raw = byCol?.[key] ?? (key === 'total' ? fallback : '0')
          return `<td class="num">${escapeHtml(money(raw))}</td>`
        })
        .join('')
      return `<tr class="total"><td class="label">${escapeHtml(label)}</td>${cells}</tr>`
    }

    const incomeRows = expanded ? renderLines(incomeLines) : ''
    const expenseRows = expanded ? renderLines(expenseLines) : ''

    const logoHtml = companyLogoUrl
      ? `<img class="ua-pl-print-logo" src="${escapeHtml(companyLogoUrl)}" alt="" />`
      : `<div class="ua-pl-print-logo ua-pl-print-logo--fallback" aria-hidden="true">${escapeHtml(companyName.slice(0, 1).toUpperCase())}</div>`

    const root = document.createElement('div')
    root.id = 'ua-pl-print-root'
    root.className = 'ua-pl-print-root'
    root.innerHTML = `
      <div class="ua-pl-print-brand">
        ${logoHtml}
        <div class="ua-pl-print-brand__text">
          <div class="ua-pl-print-company">${escapeHtml(companyName)}</div>
          <h1>Profit &amp; Loss</h1>
          <p class="ua-pl-print-sub">${escapeHtml(from)} to ${escapeHtml(to)} · Group by ${escapeHtml(groupBy)}</p>
        </div>
      </div>
      <table class="ua-pl-print-table">
        <thead>
          <tr><th class="label"></th>${th}</tr>
        </thead>
        <tbody>
          <tr class="section"><td colspan="${columns.length + 1}">INCOME</td></tr>
          ${incomeRows}
          ${renderTotal('TOTAL', totals?.income_by_column, incomeTotal)}
          <tr class="section"><td colspan="${columns.length + 1}">EXPENSES</td></tr>
          ${expenseRows}
          ${renderTotal('TOTAL', totals?.expenses_by_column, expenseTotal)}
          ${renderTotal('NET PROFIT', totals?.net_profit_by_column, netProfit).replace('class="total"', 'class="total net"')}
        </tbody>
      </table>
    `
    document.body.appendChild(root)
    document.documentElement.classList.add('ua-printing-pl')
    document.body.classList.add('ua-printing-pl')

    const fitAndPrint = () => {
      const table = root.querySelector('.ua-pl-print-table') as HTMLTableElement | null
      if (table) {
        table.style.width = 'max-content'
        table.style.tableLayout = 'auto'
        const pageInnerPx = 1050
        const naturalW = Math.max(table.scrollWidth, table.offsetWidth)
        if (naturalW > pageInnerPx) {
          const scale = pageInnerPx / naturalW
          table.style.transform = `scale(${scale})`
          table.style.transformOrigin = 'top left'
          root.style.width = `${pageInnerPx}px`
          root.style.height = `${Math.ceil(table.scrollHeight * scale) + 96}px`
        }
      }
      window.print()
      window.setTimeout(cleanup, 1500)
    }

    const cleanup = () => {
      document.documentElement.classList.remove('ua-printing-pl')
      document.body.classList.remove('ua-printing-pl')
      root.remove()
      window.removeEventListener('afterprint', cleanup)
    }
    window.addEventListener('afterprint', cleanup)

    const logoImg = root.querySelector('.ua-pl-print-logo') as HTMLImageElement | null
    if (logoImg && companyLogoUrl) {
      if (logoImg.complete) {
        window.setTimeout(fitAndPrint, 40)
      } else {
        logoImg.onload = () => window.setTimeout(fitAndPrint, 40)
        logoImg.onerror = () => window.setTimeout(fitAndPrint, 40)
        window.setTimeout(fitAndPrint, 1200)
      }
    } else {
      window.setTimeout(fitAndPrint, 40)
    }
  }

  return (
    <div className={tab === 'pl' ? 'ua-pl' : undefined}>
      {tab !== 'pl' ? (
        <PageHeader title="Financial Statements" subtitle="Professional reports from the general ledger" />
      ) : null}

      <div className={`mb-4 flex flex-wrap gap-2${tab === 'pl' ? ' ua-pl-tabs' : ''}`}>
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

      {tab !== 'pl' ? (
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
      ) : null}

      {activeError ? <ErrorBanner message={activeError} /> : null}

      {tab === 'pl' ? (
        <div className="ua-pl-desk">
          <div className="ua-pl-head">
            <h1 className="ua-pl-title">
              Profit &amp; Loss
              <span className="ua-pl-star" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6">
                  <path d="m12 3.2 2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 15.6 7.2 18.1l.9-5.4-3.9-3.8 5.4-.8L12 3.2z" />
                </svg>
              </span>
            </h1>
            <div ref={actionsWrapRef} className={`ua-pl-actions ua-tb-actions${actionsOpen ? ' is-open' : ''}`}>
              <button
                type="button"
                className="ua-pl-btn ua-tb-actions__trigger"
                disabled={!plData}
                aria-haspopup="menu"
                aria-expanded={actionsOpen}
                onClick={() => setActionsOpen((open) => !open)}
              >
                Actions
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
                  <path d="M6 9l6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </button>
              {actionsOpen ? (
                <div className="ua-tb-actions__menu" role="menu" aria-label="Profit and loss actions">
                  <button
                    type="button"
                    role="menuitem"
                    className="ua-tb-actions__item"
                    onClick={() => {
                      setActionsOpen(false)
                      onPrint()
                    }}
                  >
                    <span className="ua-tb-actions__icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                        <path d="M6 9V3h12v6" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M6 13h12v8H6z" strokeLinecap="round" strokeLinejoin="round" />
                      </svg>
                    </span>
                    Print
                  </button>
                  <button
                    type="button"
                    role="menuitem"
                    className="ua-tb-actions__item"
                    onClick={() => {
                      setActionsOpen(false)
                      onExportExcel()
                    }}
                  >
                    <span className="ua-tb-actions__icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M14 3v5h5" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M8.5 13.5l2.2 2.5 4.3-5" strokeLinecap="round" strokeLinejoin="round" />
                      </svg>
                    </span>
                    Export
                  </button>
                </div>
              ) : null}
            </div>
          </div>

          <div className="ua-pl-filter-card">
            <div className="ua-pl-filterbar">
              <label className="ua-pl-chip">
                <span className="ua-pl-chip__key">Report period</span>
                <span className="ua-pl-chip__eq">=</span>
                <select
                  value={reportPeriod}
                  onChange={(e) => onReportPeriodChange(e.target.value as ReportPeriod)}
                  aria-label="Report period"
                >
                  <option value="this_year">This Year</option>
                  <option value="last_year">Last Year</option>
                  <option value="this_quarter">This Quarter</option>
                  <option value="last_quarter">Last Quarter</option>
                  <option value="custom">Custom</option>
                </select>
              </label>

              <label className="ua-pl-chip ua-pl-chip--date">
                <span className="ua-pl-chip__key">Start date</span>
                <span className="ua-pl-chip__eq">=</span>
                <input
                  type="date"
                  value={from}
                  onChange={(e) => {
                    setReportPeriod('custom')
                    setFrom(e.target.value)
                  }}
                  aria-label="Start date"
                />
              </label>

              <label className="ua-pl-chip ua-pl-chip--date">
                <span className="ua-pl-chip__key">End date</span>
                <span className="ua-pl-chip__eq">=</span>
                <input
                  type="date"
                  value={to}
                  onChange={(e) => {
                    setReportPeriod('custom')
                    setTo(e.target.value)
                  }}
                  aria-label="End date"
                />
              </label>

              <label className="ua-pl-chip">
                <span className="ua-pl-chip__key">Group by</span>
                <span className="ua-pl-chip__eq">=</span>
                <select
                  value={groupBy}
                  onChange={(e) => setGroupBy(e.target.value as GroupBy)}
                  aria-label="Group by"
                >
                  <option value="month">Month</option>
                  <option value="quarter">Quarter</option>
                  <option value="total">Total</option>
                </select>
              </label>

              <div className="ua-pl-filterbar__spacer" />

              <button type="button" className="ua-pl-chip-btn" onClick={() => setExpanded((v) => !v)}>
                {expanded ? 'Collapse All' : 'Expand All'}
              </button>
            </div>
          </div>

          {pl.isLoading ? (
            <p className="ua-pl-loading">Loading profit &amp; loss…</p>
          ) : null}

          {plData ? (
            <div className="ua-pl-sheet" ref={printRef}>
              <table className="ua-pl-table">
                <thead>
                  <tr>
                    <th className="ua-pl-label-col" />
                    {columns.map((col) => (
                      <th key={col.key} className="is-num" title={col.label}>
                        {shortColumnLabel(col.label, col.key)}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  <tr className="ua-pl-section-row">
                    <td colSpan={columns.length + 1}>
                      <span className="ua-pl-section-label">INCOME</span>
                    </td>
                  </tr>
                  {expanded
                    ? incomeLines.map((line) => (
                        <tr key={`inc-${line.account_id}`} className="ua-pl-row">
                          <td className="ua-pl-row__label">{line.account_name}</td>
                          {columns.map((col) => (
                            <td key={col.key} className="ua-pl-num">
                              {money(lineAmount(line, col.key))}
                            </td>
                          ))}
                        </tr>
                      ))
                    : null}
                  {expanded && !incomeLines.length ? (
                    <tr className="ua-pl-row ua-pl-row--empty">
                      <td colSpan={columns.length + 1}>No income in this period.</td>
                    </tr>
                  ) : null}
                  <tr className="ua-pl-row ua-pl-row--total">
                    <td>TOTAL</td>
                    {amountCells(totals?.income_by_column, incomeTotal, true)}
                  </tr>

                  <tr className="ua-pl-section-row">
                    <td colSpan={columns.length + 1}>
                      <span className="ua-pl-section-label">EXPENSES</span>
                    </td>
                  </tr>
                  {expanded
                    ? expenseLines.map((line) => (
                        <tr key={`exp-${line.account_id}`} className="ua-pl-row">
                          <td className="ua-pl-row__label">{line.account_name}</td>
                          {columns.map((col) => (
                            <td key={col.key} className="ua-pl-num">
                              {money(lineAmount(line, col.key))}
                            </td>
                          ))}
                        </tr>
                      ))
                    : null}
                  {expanded && !expenseLines.length ? (
                    <tr className="ua-pl-row ua-pl-row--empty">
                      <td colSpan={columns.length + 1}>No expenses in this period.</td>
                    </tr>
                  ) : null}
                  <tr className="ua-pl-row ua-pl-row--total">
                    <td>TOTAL</td>
                    {amountCells(totals?.expenses_by_column, expenseTotal, true)}
                  </tr>

                  <tr className="ua-pl-row ua-pl-row--net">
                    <td>NET PROFIT</td>
                    {amountCells(totals?.net_profit_by_column, netProfit, true)}
                  </tr>
                </tbody>
              </table>
            </div>
          ) : null}

          {!pl.isLoading && !plData && !pl.isError ? (
            <EmptyState title="No profit & loss data" body="Post revenue or expense journals in the selected year." />
          ) : null}
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
            <div>
              Assets: <strong>{bs.data.totals.assets}</strong>
            </div>
            <div>
              Liabilities + Equity: <strong>{bs.data.totals.liabilities_and_equity}</strong>
            </div>
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
            <div>
              Net profit: <strong>{cf.data.operating_activities.net_profit}</strong>
            </div>
            <div>
              Customer receipts: <strong>{cf.data.operating_activities.customer_receipts}</strong>
            </div>
            <div>
              Payments: <strong>{cf.data.operating_activities.supplier_and_other_payments}</strong>
            </div>
            <div>
              Net operating cash: <strong>{cf.data.operating_activities.net_cash_from_operating}</strong>
            </div>
            <div>
              Opening cash: <strong>{cf.data.cash_reconciliation.opening_cash}</strong>
            </div>
            <div>
              Closing cash: <strong>{cf.data.cash_reconciliation.closing_cash}</strong>
            </div>
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
            <div>
              Opening equity: <strong>{equity.data.opening_equity}</strong>
            </div>
            <div>
              Net profit: <strong>{equity.data.net_profit}</strong>
            </div>
            <div>
              Other movements: <strong>{equity.data.other_movements}</strong>
            </div>
            <div>
              Closing equity: <strong>{equity.data.closing_equity}</strong>
            </div>
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
