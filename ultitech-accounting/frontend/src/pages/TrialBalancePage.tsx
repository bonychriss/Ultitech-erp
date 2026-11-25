import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useQuery } from '@tanstack/react-query'
import html2canvas from 'html2canvas'
import { jsPDF } from 'jspdf'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Button, EmptyState, ErrorBanner, Input, Label, PageHeader } from '../components/ui'

type TrialBalanceRow = {
  account_id: number
  account_code?: string | null
  account_name?: string | null
  account_type?: string | null
  account_type_code?: string | null
  fs_classification?: string | null
  normal_balance?: string | null
  currency_code?: string | null
  opening_debit?: string
  opening_credit?: string
  movement_debit?: string
  movement_credit?: string
  debit: string
  credit: string
}

type TrialBalanceResponse = {
  as_of?: string | null
  currency_code?: string | null
  book?: { id?: number; name?: string | null } | null
  totals: {
    debit: string
    credit: string
    difference?: string
    balanced: boolean
    accounts?: number
  }
  data: TrialBalanceRow[]
}

type TypeFilter = 'all' | string

type TbExportOpts = {
  rows: TrialBalanceRow[]
  totals: { debit: number; credit: number; balanced: boolean; accounts: number }
  asOf: string
  currency: string
}

function asOfLabel(asOf: string): string {
  const raw = asOf.trim()
  return raw ? raw.slice(0, 10) : 'All posted activity'
}

function exportFileName(ext: string, asOf: string): string {
  const stamp = asOf.trim() ? asOf.slice(0, 10) : new Date().toISOString().slice(0, 10)
  return `trial-balance-${stamp}.${ext}`
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

function downloadTrialBalanceExcel(opts: TbExportOpts): void {
  const rowsXml = opts.rows
    .map((row) => {
      const cells = [
        row.account_code || '',
        row.account_name || '',
        rowTypeLabel(row),
        Number(row.debit || 0),
        Number(row.credit || 0),
      ]
        .map((value, idx) => {
          if (idx >= 3) {
            return `<Cell ss:StyleID="Num"><Data ss:Type="Number">${Number(value)}</Data></Cell>`
          }
          return `<Cell><Data ss:Type="String">${escapeHtml(String(value))}</Data></Cell>`
        })
        .join('')
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
 </Styles>
 <Worksheet ss:Name="Trial Balance">
  <Table>
   <Column ss:Width="70"/>
   <Column ss:Width="220"/>
   <Column ss:Width="90"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Trial Balance</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">${escapeHtml(opts.currency)} | ${escapeHtml(asOfLabel(opts.asOf))} | ${opts.totals.accounts} accounts | ${opts.totals.balanced ? 'Balanced' : 'Out of balance'}</Data></Cell>
   </Row>
   <Row/>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Code</Data></Cell>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Account</Data></Cell>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Type</Data></Cell>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Debit</Data></Cell>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Credit</Data></Cell>
   </Row>
   ${rowsXml}
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Totals</Data></Cell>
    <Cell/><Cell/>
    <Cell ss:StyleID="Num"><Data ss:Type="Number">${opts.totals.debit}</Data></Cell>
    <Cell ss:StyleID="Num"><Data ss:Type="Number">${opts.totals.credit}</Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>`

  downloadBlob(
    exportFileName('xls', opts.asOf),
    new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8' }),
  )
}

function trialBalanceSheetHtml(opts: TbExportOpts): string {
  const when = asOfLabel(opts.asOf)
  const lineRows = opts.rows
    .map((row) => {
      const debit = Number(row.debit || 0)
      const credit = Number(row.credit || 0)
      return `<tr>
        <td>${escapeHtml(row.account_code || '—')}</td>
        <td>${escapeHtml(row.account_name || '—')}</td>
        <td>${escapeHtml(rowTypeLabel(row))}</td>
        <td class="num">${escapeHtml(amount(debit))}</td>
        <td class="num">${escapeHtml(amount(credit))}</td>
      </tr>`
    })
    .join('')

  return `
    <h1>Trial Balance</h1>
    <p class="ua-tb-print-sub">${escapeHtml(opts.currency)} · ${escapeHtml(when)} · ${opts.totals.accounts} accounts · ${opts.totals.balanced ? 'Balanced' : 'Out of balance'}</p>
    <table class="ua-tb-print-table">
      <thead>
        <tr>
          <th>Code</th>
          <th>Account</th>
          <th>Type</th>
          <th class="num">Debit</th>
          <th class="num">Credit</th>
        </tr>
      </thead>
      <tbody>${lineRows}</tbody>
      <tfoot>
        <tr>
          <td colspan="3">Totals</td>
          <td class="num">${escapeHtml(amount(opts.totals.debit))}</td>
          <td class="num">${escapeHtml(amount(opts.totals.credit))}</td>
        </tr>
      </tfoot>
    </table>
  `
}

function mountTrialBalanceSheet(opts: TbExportOpts, extraClass = ''): HTMLDivElement {
  document.getElementById('ua-tb-print-root')?.remove()
  const root = document.createElement('div')
  root.id = 'ua-tb-print-root'
  root.className = ['ua-tb-print-root', extraClass].filter(Boolean).join(' ')
  root.innerHTML = trialBalanceSheetHtml(opts)
  document.body.appendChild(root)
  return root
}

async function downloadTrialBalancePdf(opts: TbExportOpts): Promise<void> {
  // Capture the same print sheet markup. Keep it in-viewport (not left:-9999)
  // so html2canvas actually paints the table.
  const root = mountTrialBalanceSheet(opts, 'ua-tb-print-root--capture')
  root.setAttribute(
    'style',
    [
      'display:block',
      'position:fixed',
      'left:0',
      'top:0',
      'width:794px',
      'padding:28px 32px',
      'box-sizing:border-box',
      'background:#ffffff',
      'color:#0f172a',
      'z-index:2147483646',
      'pointer-events:none',
      'font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif',
    ].join(';'),
  )

  try {
    await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))

    const canvas = await html2canvas(root, {
      scale: 2,
      backgroundColor: '#ffffff',
      logging: false,
      useCORS: true,
      windowWidth: 794,
    })

    const imgData = canvas.toDataURL('image/png')
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' })
    const pageWidth = pdf.internal.pageSize.getWidth()
    const pageHeight = pdf.internal.pageSize.getHeight()
    const margin = 24
    const usableWidth = pageWidth - margin * 2
    const usableHeight = pageHeight - margin * 2
    const imgHeight = (canvas.height * usableWidth) / canvas.width

    let heightLeft = imgHeight
    let offsetY = 0

    while (heightLeft > 0) {
      if (offsetY > 0) pdf.addPage()
      pdf.addImage(imgData, 'PNG', margin, margin - offsetY, usableWidth, imgHeight)
      heightLeft -= usableHeight
      offsetY += usableHeight
    }

    pdf.save(exportFileName('pdf', opts.asOf))
  } finally {
    root.remove()
  }
}

function amount(value: string | number | undefined) {
  const n = Number(value || 0)
  if (!Number.isFinite(n) || Math.abs(n) < 0.00005) return '—'
  // ChatGPT-style ledger: whole units with thousands separators when clean.
  if (Math.abs(n - Math.round(n)) < 0.00005) {
    return Math.round(n).toLocaleString('en-US')
  }
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function typeLabel(codeOrName: string | null | undefined) {
  const raw = String(codeOrName || '').trim()
  if (!raw) return '—'
  const map: Record<string, string> = {
    assets: 'Asset',
    asset: 'Asset',
    liabilities: 'Liability',
    liability: 'Liability',
    equity: 'Equity',
    revenue: 'Revenue',
    income: 'Revenue',
    expenses: 'Expense',
    expense: 'Expense',
  }
  return map[raw.toLowerCase()] || raw
}

function rowTypeLabel(row: TrialBalanceRow) {
  return typeLabel(row.account_type_code || row.account_type)
}

function FilterIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
      <line x1="4" y1="6" x2="20" y2="6" />
      <line x1="4" y1="12" x2="20" y2="12" />
      <line x1="4" y1="18" x2="20" y2="18" />
      <circle cx="8" cy="6" r="2" fill="currentColor" stroke="none" />
      <circle cx="14" cy="12" r="2" fill="currentColor" stroke="none" />
      <circle cx="10" cy="18" r="2" fill="currentColor" stroke="none" />
    </svg>
  )
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/** Dedicated print sheet — same approach as journals (avoids blank ERP-shell previews). */
function printTrialBalance(opts: TbExportOpts): void {
  const prevTitle = document.title
  document.title = 'Trial Balance'
  const root = mountTrialBalanceSheet(opts)
  document.documentElement.classList.add('ua-printing-tb')
  document.body.classList.add('ua-printing-tb')

  const cleanup = () => {
    document.documentElement.classList.remove('ua-printing-tb')
    document.body.classList.remove('ua-printing-tb')
    root.remove()
    document.title = prevTitle
    window.removeEventListener('afterprint', cleanup)
  }
  window.addEventListener('afterprint', cleanup)
  window.setTimeout(() => {
    window.print()
    window.setTimeout(cleanup, 1500)
  }, 30)
}

export function TrialBalancePage() {
  const { token, companyId, bookId } = useAuth()
  const ready = Boolean(token && companyId)

  const [asOf, setAsOf] = useState('')
  const [draftAsOf, setDraftAsOf] = useState('')
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all')
  const [hideZeros, setHideZeros] = useState(true)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [actionsOpen, setActionsOpen] = useState(false)
  const [filterPanelPos, setFilterPanelPos] = useState<{ top: number; left: number; width: number } | null>(null)
  const filterBtnRef = useRef<HTMLButtonElement | null>(null)
  const filterRef = useRef<HTMLDivElement | null>(null)
  const actionsWrapRef = useRef<HTMLDivElement | null>(null)

  const query = useQuery({
    queryKey: ['trial-balance', companyId, bookId, asOf, hideZeros],
    enabled: ready,
    queryFn: () => {
      const qs = new URLSearchParams()
      if (asOf) qs.set('as_of', asOf)
      if (!hideZeros) qs.set('include_zeros', '1')
      const suffix = qs.toString() ? `?${qs.toString()}` : ''
      return apiRequest<TrialBalanceResponse>(`/trial-balance${suffix}`, { token, companyId, bookId })
    },
  })

  const rows = query.data?.data ?? []

  const typeOptions = useMemo(() => {
    const map = new Map<string, string>()
    for (const row of rows) {
      const code = String(row.account_type_code || '').trim()
      const name = String(row.account_type || code || '').trim()
      if (!code && !name) continue
      map.set(code || name, name || code)
    }
    return Array.from(map.entries())
      .map(([code, name]) => ({ code, name }))
      .sort((a, b) => a.name.localeCompare(b.name))
  }, [rows])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rows.filter((row) => {
      const debit = Number(row.debit || 0)
      const credit = Number(row.credit || 0)
      if (hideZeros && Math.abs(debit) < 0.00005 && Math.abs(credit) < 0.00005) return false
      if (typeFilter !== 'all') {
        const code = String(row.account_type_code || row.account_type || '')
        if (code !== typeFilter) return false
      }
      if (!q) return true
      const hay = [
        row.account_code,
        row.account_name,
        row.account_type,
        row.account_type_code,
        row.fs_classification,
        row.normal_balance,
      ]
        .map((v) => String(v || '').toLowerCase())
        .join(' ')
      return hay.includes(q)
    })
  }, [rows, search, typeFilter, hideZeros])

  const shownTotals = useMemo(() => {
    let debit = 0
    let credit = 0
    for (const row of filtered) {
      debit += Number(row.debit || 0)
      credit += Number(row.credit || 0)
    }
    const difference = debit - credit
    return {
      debit,
      credit,
      difference,
      balanced: Math.abs(difference) < 0.005,
      accounts: filtered.length,
    }
  }, [filtered])

  const hasActiveFilters = typeFilter !== 'all' || !hideZeros || Boolean(asOf)

  useEffect(() => {
    if (!filtersOpen || !filterBtnRef.current) {
      setFilterPanelPos(null)
      return
    }
    const place = () => {
      const rect = filterBtnRef.current!.getBoundingClientRect()
      const width = Math.min(320, Math.max(260, window.innerWidth - 24))
      const left = Math.min(Math.max(12, rect.right - width), window.innerWidth - width - 12)
      setFilterPanelPos({ top: rect.bottom + 8, left, width })
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
    if (!filtersOpen && !actionsOpen) return
    const onDoc = (e: MouseEvent) => {
      const t = e.target as Node
      if (filtersOpen) {
        if (!(filterRef.current?.contains(t) || filterBtnRef.current?.contains(t))) {
          setFiltersOpen(false)
        }
      }
      if (actionsOpen && !actionsWrapRef.current?.contains(t)) {
        setActionsOpen(false)
      }
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setFiltersOpen(false)
        setActionsOpen(false)
      }
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [filtersOpen, actionsOpen])

  const applyAsOf = () => {
    setAsOf(draftAsOf)
    setFiltersOpen(false)
  }

  const clearFilters = () => {
    setDraftAsOf('')
    setAsOf('')
    setTypeFilter('all')
    setHideZeros(true)
    setFiltersOpen(false)
  }

  const exportOpts = (): TbExportOpts | null => {
    if (!filtered.length) return null
    return {
      rows: filtered,
      totals: shownTotals,
      asOf: asOf || String(query.data?.as_of || ''),
      currency: String(query.data?.currency_code || 'TZS'),
    }
  }

  const onPrint = () => {
    const opts = exportOpts()
    if (!opts) return
    setActionsOpen(false)
    printTrialBalance(opts)
  }

  const onDownloadExcel = () => {
    const opts = exportOpts()
    if (!opts) return
    setActionsOpen(false)
    downloadTrialBalanceExcel(opts)
  }

  const onDownloadPdf = () => {
    const opts = exportOpts()
    if (!opts) return
    setActionsOpen(false)
    void downloadTrialBalancePdf(opts)
  }

  const searchField = (
    <input
      type="search"
      className="ua-revenue-search"
      value={search}
      onChange={(e) => setSearch(e.target.value)}
      placeholder="Search accounts…"
      aria-label="Search trial balance"
    />
  )

  return (
    <div className="ua-revenue ua-tb">
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
            <div ref={actionsWrapRef} className={`ua-tb-actions${actionsOpen ? ' is-open' : ''}`}>
              <Button
                type="button"
                variant="secondary"
                pill
                className="ua-tb-actions__trigger"
                disabled={!ready || !filtered.length}
                aria-haspopup="menu"
                aria-expanded={actionsOpen}
                onClick={() => {
                  setFiltersOpen(false)
                  setActionsOpen((open) => !open)
                }}
              >
                Actions
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
                  <path d="M6 9l6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </Button>
              {actionsOpen ? (
                <div className="ua-tb-actions__menu" role="menu" aria-label="Trial balance actions">
                  <button type="button" role="menuitem" className="ua-tb-actions__item" onClick={onDownloadExcel}>
                    <span className="ua-tb-actions__icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M14 3v5h5" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M8.5 13.5l2.2 2.5 4.3-5" strokeLinecap="round" strokeLinejoin="round" />
                      </svg>
                    </span>
                    Download Excel
                  </button>
                  <button type="button" role="menuitem" className="ua-tb-actions__item" onClick={onDownloadPdf}>
                    <span className="ua-tb-actions__icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M14 3v5h5" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M9 13h6M9 17h4" strokeLinecap="round" />
                      </svg>
                    </span>
                    Download PDF
                  </button>
                  <button type="button" role="menuitem" className="ua-tb-actions__item" onClick={onPrint}>
                    <span className="ua-tb-actions__icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                        <path d="M6 9V3h12v6" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M6 13h12v8H6z" strokeLinecap="round" strokeLinejoin="round" />
                      </svg>
                    </span>
                    Print
                  </button>
                </div>
              ) : null}
            </div>
          </div>
        }
      />

      {filtersOpen && filterPanelPos
        ? createPortal(
            <div
              ref={filterRef}
              className="ua-revenue-filter-panel"
              role="dialog"
              aria-label="Filter trial balance"
              style={{
                position: 'fixed',
                top: filterPanelPos.top,
                left: filterPanelPos.left,
                width: filterPanelPos.width,
                zIndex: 80,
              }}
            >
              <div className="ua-revenue-filter-panel__head">
                <span className="ua-revenue-filter-panel__heading">Filters</span>
                <button type="button" className="ua-revenue-filter-clear" onClick={clearFilters}>
                  Reset
                </button>
              </div>

              <div className="ua-revenue-filter-dates">
                <div>
                  <Label>As of (blank = all activity)</Label>
                  <Input type="date" value={draftAsOf} onChange={(e) => setDraftAsOf(e.target.value)} />
                </div>
              </div>

              <p className="ua-revenue-filter-panel__title">Account type</p>
              <div className="ua-revenue-filter-panel__options">
                <button
                  type="button"
                  className={`ua-revenue-filter-option${typeFilter === 'all' ? ' is-active' : ''}`}
                  onClick={() => setTypeFilter('all')}
                >
                  All types
                  <span className="ua-revenue-filter-option__count">{rows.length}</span>
                </button>
                {typeOptions.map((opt) => {
                  const count = rows.filter(
                    (r) => String(r.account_type_code || r.account_type || '') === opt.code,
                  ).length
                  return (
                    <button
                      key={opt.code}
                      type="button"
                      className={`ua-revenue-filter-option${typeFilter === opt.code ? ' is-active' : ''}`}
                      onClick={() => setTypeFilter(opt.code)}
                    >
                      {opt.name}
                      <span className="ua-revenue-filter-option__count">{count}</span>
                    </button>
                  )
                })}
              </div>

              <label className="ua-tb-check">
                <input
                  type="checkbox"
                  checked={hideZeros}
                  onChange={(e) => setHideZeros(e.target.checked)}
                />
                Hide zero balances
              </label>

              <div className="ua-revenue-filter-panel__footer">
                <Button type="button" className="w-full" onClick={applyAsOf}>
                  Apply
                </Button>
              </div>
            </div>,
            document.body,
          )
        : null}

      {!ready ? <ErrorBanner message="Company session not ready. Re-open Accounting from ERP." /> : null}
      {query.isError ? <ErrorBanner message={(query.error as Error).message} /> : null}

      {query.isLoading ? (
        <section className="ua-chatgpt-table ua-tb-print" aria-label="Trial balance">
          <div className="ua-chatgpt-table__loading">Loading trial balance…</div>
        </section>
      ) : !filtered.length ? (
        <section className="ua-chatgpt-table ua-tb-print" aria-label="Trial balance">
          <div className="ua-chatgpt-table__loading">
            <EmptyState
              title="No balances"
              body="Posted journals and openings for this as-of date will appear here."
            />
          </div>
        </section>
      ) : (
        <section className="ua-chatgpt-table ua-tb-print" aria-label="Trial balance">
          <div className="ua-chatgpt-table__scroll">
            <table className="ua-chatgpt-table__grid">
              <thead>
                <tr>
                  <th scope="col">Code</th>
                  <th scope="col">Account</th>
                  <th scope="col">Type</th>
                  <th scope="col" className="is-num">
                    Debit
                  </th>
                  <th scope="col" className="is-num">
                    Credit
                  </th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((row) => (
                  <tr key={row.account_id}>
                    <td className="ua-tb-cell-code">{row.account_code || '—'}</td>
                    <td className="ua-tb-cell-name">
                      <span className="ua-tb-name">{row.account_name || '—'}</span>
                      <span className="ua-tb-type-mobile">{rowTypeLabel(row)}</span>
                    </td>
                    <td className="ua-tb-cell-type">{rowTypeLabel(row)}</td>
                    <td className="ua-tb-cell-debit is-num">{amount(row.debit)}</td>
                    <td className="ua-tb-cell-credit is-num">{amount(row.credit)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="ua-chatgpt-table__totals" aria-label="Totals">
            <span>
              Totals
              <span className={`ua-tb-balance-pill${shownTotals.balanced ? ' is-ok' : ' is-bad'}`}>
                {shownTotals.balanced ? 'Balanced' : 'Out of balance'}
              </span>
            </span>
            <span className="is-num">{amount(shownTotals.debit)}</span>
            <span className="is-num">{amount(shownTotals.credit)}</span>
          </div>
        </section>
      )}
    </div>
  )
}
