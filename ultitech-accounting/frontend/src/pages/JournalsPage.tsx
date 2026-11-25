import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest, ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import {
  currencyCode,
  isManualJournal,
  moneyWithCurrency,
  presentJournalListDescription,
  sourceLabel,
  statusClass,
  statusLabel,
  type Journal,
} from '../lib/journals'
import { Button, EmptyState, ErrorBanner, Input, Label, PageHeader, Select } from '../components/ui'

type Account = {
  id: number
  code: string
  name: string
  is_postable?: boolean
  allow_manual_entry?: boolean
  status?: string
}

type StatusFilter = 'all' | 'draft' | 'posted' | 'reversed'
type SourceFilter = 'all' | 'manual' | 'system'

type DraftLine = {
  key: string
  account_id: string
  description: string
  debit: string
  credit: string
}

function monthRangeIso(): { from: string; to: string } {
  const now = new Date()
  const y = now.getFullYear()
  const m = now.getMonth()
  const from = new Date(y, m, 1)
  const to = new Date(y, m + 1, 0)
  const iso = (d: Date) => d.toISOString().slice(0, 10)
  return { from: iso(from), to: iso(to) }
}

function isIsoDate(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
}

function newDraftLine(): DraftLine {
  return {
    key: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    account_id: '',
    description: '',
    debit: '',
    credit: '',
  }
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

function BookIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
      <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
    </svg>
  )
}

function DraftIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
    </svg>
  )
}

function ManualIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M12 3v18" />
      <path d="M5 8h14" />
      <path d="M5 16h14" />
    </svg>
  )
}

function BalanceIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M12 3v18" />
      <path d="M5 7h14" />
      <path d="M7 7l-3 7a4 4 0 0 0 8 0L9 7" />
      <path d="M17 7l-3 7a4 4 0 0 0 8 0l-3-7" />
    </svg>
  )
}

export function JournalsPage() {
  const { token, companyId, bookId } = useAuth()
  const client = useQueryClient()
  const navigate = useNavigate()
  const ready = Boolean(token && companyId)

  const [error, setError] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [sourceFilter, setSourceFilter] = useState<SourceFilter>('all')
  const [dateFrom, setDateFrom] = useState(() => monthRangeIso().from)
  const [dateTo, setDateTo] = useState(() => monthRangeIso().to)
  const [search, setSearch] = useState('')
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [filterPanelPos, setFilterPanelPos] = useState<{ top: number; right: number } | null>(null)
  const filterRef = useRef<HTMLDivElement | null>(null)
  const filterBtnRef = useRef<HTMLButtonElement | null>(null)

  const [createOpen, setCreateOpen] = useState(false)
  const [createError, setCreateError] = useState('')
  const [journalDate, setJournalDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [description, setDescription] = useState('')
  const [reference, setReference] = useState('')
  const [currency, setCurrency] = useState('TZS')
  const [lines, setLines] = useState<DraftLine[]>(() => [newDraftLine(), newDraftLine()])
  const [postAfterCreate, setPostAfterCreate] = useState(true)

  const accounts = useQuery({
    queryKey: ['accounts', companyId, bookId, 'journals'],
    enabled: ready,
    queryFn: () =>
      apiRequest<{ data: Account[] }>('/accounts?scope=working', { token, companyId, bookId }),
  })

  const currencies = useQuery({
    queryKey: ['currencies'],
    enabled: ready,
    queryFn: () => apiRequest<{ data: Array<{ code: string }> }>('/currencies', { token, companyId, bookId }),
  })

  const journalsQuery = useQuery({
    queryKey: ['journals', companyId, bookId, dateFrom, dateTo],
    enabled: ready,
    queryFn: () => {
      const params = new URLSearchParams({ limit: '500' })
      if (isIsoDate(dateFrom)) params.set('date_from', dateFrom)
      if (isIsoDate(dateTo)) params.set('date_to', dateTo)
      return apiRequest<{ data: Journal[] }>(`/journals?${params.toString()}`, {
        token,
        companyId,
        bookId,
      })
    },
  })

  const accountOptions = useMemo(() => {
    const rows = accounts.data?.data ?? []
    return rows.filter((a) => {
      const status = String(a.status || 'active').toLowerCase()
      if (status === 'inactive') return false
      if (a.is_postable === false) return false
      return true
    })
  }, [accounts.data])

  const journals = journalsQuery.data?.data ?? []

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return journals.filter((journal) => {
      const status = String(journal.status || '').toLowerCase()
      if (statusFilter !== 'all' && status !== statusFilter) return false
      const manual = isManualJournal(journal)
      if (sourceFilter === 'manual' && !manual) return false
      if (sourceFilter === 'system' && manual) return false
      if (!q) return true
      const hay = [
        journal.journal_number,
        journal.description,
        journal.reference,
        journal.source_type,
        journal.source?.code,
        journal.source?.name,
        statusLabel(journal.status),
        sourceLabel(journal),
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return hay.includes(q)
    })
  }, [journals, search, statusFilter, sourceFilter])

  const kpi = useMemo(() => {
    let posted = 0
    let drafts = 0
    let reversed = 0
    let manual = 0
    let system = 0
    let debitTotal = 0
    let creditTotal = 0
    let balanced = 0

    for (const row of filtered) {
      const status = String(row.status || '').toLowerCase()
      if (status === 'posted') posted += 1
      else if (status === 'draft') drafts += 1
      else if (status === 'reversed') reversed += 1

      if (isManualJournal(row)) manual += 1
      else system += 1

      const debit = Number(row.total_debit) || 0
      const credit = Number(row.total_credit) || 0
      debitTotal += debit
      creditTotal += credit
      if (Math.abs(debit - credit) <= 0.009) balanced += 1
    }

    return {
      posted,
      drafts,
      reversed,
      manual,
      system,
      debitTotal,
      creditTotal,
      balanced,
      count: filtered.length,
      inBalance: Math.abs(debitTotal - creditTotal) <= 0.009,
    }
  }, [filtered])

  const draftTotals = useMemo(() => {
    let debit = 0
    let credit = 0
    for (const line of lines) {
      debit += Number(String(line.debit).replace(/,/g, '')) || 0
      credit += Number(String(line.credit).replace(/,/g, '')) || 0
    }
    return {
      debit,
      credit,
      diff: Math.round((debit - credit) * 100) / 100,
      balanced: Math.abs(debit - credit) <= 0.009 && debit > 0,
    }
  }, [lines])

  const hasActiveFilters =
    statusFilter !== 'all' ||
    sourceFilter !== 'all' ||
    search.trim() !== '' ||
    dateFrom !== monthRangeIso().from ||
    dateTo !== monthRangeIso().to

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
    if (!filtersOpen && !createOpen) return
    const onDoc = (e: MouseEvent) => {
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
      if (createOpen) setCreateOpen(false)
      else if (filtersOpen) setFiltersOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    window.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      window.removeEventListener('keydown', onKey)
    }
  }, [filtersOpen, createOpen])

  useEffect(() => {
    if (!createOpen) {
      document.documentElement.classList.remove('ua-modal-open')
      document.body.classList.remove('ua-modal-open')
      return
    }
    document.documentElement.classList.add('ua-modal-open')
    document.body.classList.add('ua-modal-open')
    return () => {
      document.documentElement.classList.remove('ua-modal-open')
      document.body.classList.remove('ua-modal-open')
    }
  }, [createOpen])

  const openJournal = (journal: Journal) => {
    navigate(`/journals/${journal.id}`)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payloadLines = lines
        .map((line) => {
          const debit = Number(String(line.debit).replace(/,/g, '')) || 0
          const credit = Number(String(line.credit).replace(/,/g, '')) || 0
          return {
            account_id: Number(line.account_id),
            debit: debit > 0 ? String(debit) : '0',
            credit: credit > 0 ? String(credit) : '0',
            description: line.description.trim() || null,
          }
        })
        .filter((line) => line.account_id > 0 && (Number(line.debit) > 0 || Number(line.credit) > 0))

      if (payloadLines.length < 2) {
        throw new Error('Add at least two lines with accounts and amounts.')
      }
      if (!draftTotals.balanced) {
        throw new Error(
          `Journal is out of balance by ${moneyWithCurrency(Math.abs(draftTotals.diff), currency)}.`,
        )
      }

      const draft = await apiRequest<{ data: Journal }>('/journals', {
        method: 'POST',
        token,
        companyId,
        bookId,
        body: {
          journal_date: journalDate,
          description: description.trim() || 'Manual journal',
          reference: reference.trim() || null,
          currency_code: currencyCode(currency),
          lines: payloadLines,
        },
      })

      if (!postAfterCreate) return draft.data

      return (
        await apiRequest<{ data: Journal }>(`/journals/${draft.data.id}/post`, {
          method: 'POST',
          token,
          companyId,
          bookId,
          idempotencyKey: crypto.randomUUID(),
        })
      ).data
    },
    onSuccess: async (journal) => {
      setCreateError('')
      setError('')
      setCreateOpen(false)
      setDescription('')
      setReference('')
      setLines([newDraftLine(), newDraftLine()])
      await client.invalidateQueries({ queryKey: ['journals', companyId, bookId] })
      navigate(`/journals/${journal.id}`)
    },
    onError: (err) => {
      setCreateError((err as ApiError | Error).message || 'Could not save journal.')
    },
  })

  const openCreate = () => {
    setCreateError('')
    setJournalDate(new Date().toISOString().slice(0, 10))
    setDescription('')
    setReference('')
    setCurrency('TZS')
    setLines([newDraftLine(), newDraftLine()])
    setPostAfterCreate(true)
    setCreateOpen(true)
  }

  const clearFilters = () => {
    const month = monthRangeIso()
    setStatusFilter('all')
    setSourceFilter('all')
    setDateFrom(month.from)
    setDateTo(month.to)
    setSearch('')
  }

  const onCreateSubmit = (event: FormEvent) => {
    event.preventDefault()
    saveMutation.mutate()
  }

  const updateLine = (key: string, patch: Partial<DraftLine>) => {
    setLines((prev) => prev.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  }

  const searchField = (
    <input
      type="search"
      className="ua-revenue-search"
      value={search}
      onChange={(e) => setSearch(e.target.value)}
      placeholder="Search number, description, source…"
      aria-label="Search journals"
    />
  )

  const filterOptions = [
    ['all', 'All', journals.length],
    ['posted', 'Posted', journals.filter((j) => String(j.status).toLowerCase() === 'posted').length],
    ['draft', 'Draft', journals.filter((j) => String(j.status).toLowerCase() === 'draft').length],
    ['reversed', 'Reversed', journals.filter((j) => String(j.status).toLowerCase() === 'reversed').length],
  ] as const

  return (
    <div className="ua-revenue ua-journals">
      <PageHeader
        title="Journal Entries"
        breadcrumb={
          <>
            <span>Accounting</span>
            <span className="ua-breadcrumb__sep" aria-hidden="true">
              /
            </span>
            <span className="ua-breadcrumb__current">Journal Entries</span>
          </>
        }
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
            <Button type="button" onClick={openCreate} disabled={!ready}>
              New journal
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
              aria-label="Filter journals"
              style={{ top: filterPanelPos.top, right: filterPanelPos.right }}
            >
              <div className="ua-revenue-filter-panel__head">
                <div className="ua-revenue-filter-panel__heading">Filters</div>
                <button
                  type="button"
                  className="ua-revenue-filter-clear"
                  disabled={!hasActiveFilters}
                  onClick={clearFilters}
                >
                  Reset
                </button>
              </div>

              <div className="ua-revenue-filter-panel__title">Date range</div>
              <div className="ua-revenue-filter-dates">
                <div>
                  <Label>From</Label>
                  <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                </div>
                <div>
                  <Label>To</Label>
                  <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                </div>
              </div>

              <div className="ua-revenue-filter-panel__title">Status</div>
              <div className="ua-revenue-filter-chips">
                {filterOptions.map(([value, label, count]) => (
                  <button
                    key={value}
                    type="button"
                    className={`ua-revenue-chip${statusFilter === value ? ' is-active' : ''}`}
                    onClick={() => setStatusFilter(value)}
                  >
                    {label}
                    <span>{count}</span>
                  </button>
                ))}
              </div>

              <div className="ua-revenue-filter-panel__title">Source</div>
              <div className="ua-revenue-filter-chips">
                {(
                  [
                    ['all', 'All'],
                    ['manual', 'Manual'],
                    ['system', 'System'],
                  ] as const
                ).map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    className={`ua-revenue-chip${sourceFilter === value ? ' is-active' : ''}`}
                    onClick={() => setSourceFilter(value)}
                  >
                    {label}
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

      {!ready ? <ErrorBanner message="Company session not ready. Re-open Accounting from ERP." /> : null}
      {error ? <ErrorBanner message={error} /> : null}
      {journalsQuery.isError ? (
        <ErrorBanner
          message={(journalsQuery.error as Error)?.message || 'Unable to load journals.'}
        />
      ) : null}

      <section className="ua-revenue-kpis" aria-label="Journals summary">
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Posted</span>
            <strong className="ua-revenue-kpi__value">{kpi.posted.toLocaleString('en-US')}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--teal" aria-hidden="true">
            <BookIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Drafts</span>
            <strong className="ua-revenue-kpi__value">{kpi.drafts.toLocaleString('en-US')}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--amber" aria-hidden="true">
            <DraftIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Manual</span>
            <strong className="ua-revenue-kpi__value">{kpi.manual.toLocaleString('en-US')}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--violet" aria-hidden="true">
            <ManualIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">System</span>
            <strong className="ua-revenue-kpi__value">{kpi.system.toLocaleString('en-US')}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--blue" aria-hidden="true">
            <BalanceIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Debits</span>
            <strong className="ua-revenue-kpi__value">{moneyWithCurrency(kpi.debitTotal)}</strong>
          </div>
          <span className="ua-revenue-kpi__badge ua-revenue-kpi__badge--indigo" aria-hidden="true">
            <BalanceIcon />
          </span>
        </article>
        <article className="ua-revenue-kpi">
          <div className="ua-revenue-kpi__text">
            <span className="ua-revenue-kpi__label">Balance check</span>
            <strong
              className={`ua-revenue-kpi__value${kpi.inBalance ? '' : ' ua-revenue-kpi__value--rose'}`}
            >
              {kpi.inBalance ? 'In balance' : 'Out of balance'}
            </strong>
            <span className="ua-revenue-kpi__sub">
              Credits {moneyWithCurrency(kpi.creditTotal)}
            </span>
          </div>
          <span
            className={`ua-revenue-kpi__badge ${
              kpi.inBalance ? 'ua-revenue-kpi__badge--teal' : 'ua-revenue-kpi__badge--rose'
            }`}
            aria-hidden="true"
          >
            <BalanceIcon />
          </span>
        </article>
      </section>

      <section className="ua-revenue-desk" aria-label="Journal list">
        <div className="ua-revenue-desk__toolbar">
          <div className="ua-revenue-desk__heading">
            <h2 className="ua-revenue-desk__title">General ledger journals</h2>
            <p className="ua-revenue-desk__meta">
              {kpi.count.toLocaleString('en-US')} shown
              {statusFilter !== 'all' ? ` · ${statusLabel(statusFilter)}` : ''}
              {sourceFilter !== 'all' ? ` · ${sourceFilter}` : ''}
              {` · ${dateFrom || '…'} → ${dateTo || '…'}`}
            </p>
          </div>
        </div>

        <div className="ua-revenue-desk__body">
          {journalsQuery.isLoading ? (
            <div className="ua-revenue-table-card ua-revenue-loading">Loading journals…</div>
          ) : null}

          {!journalsQuery.isLoading && filtered.length ? (
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
                    <th scope="col">Description</th>
                    <th scope="col" className="is-num">
                      Debit
                    </th>
                    <th scope="col" className="is-num">
                      Credit
                    </th>
                    <th scope="col">Status</th>
                    <th scope="col">
                      <span className="sr-only">Open</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((journal) => (
                    <tr
                      key={journal.id}
                      className="ua-revenue-row"
                      onClick={() => openJournal(journal)}
                    >
                      <td className="ua-revenue-cell ua-revenue-cell--doc">
                        <div className="ua-revenue-docnum">{journal.journal_number}</div>
                        <div className="ua-revenue-docdate">
                          {String(journal.journal_date).slice(0, 10)}
                        </div>
                      </td>
                      <td className="ua-revenue-cell ua-revenue-cell--customer">
                        <div className="ua-revenue-payee">
                          {presentJournalListDescription(journal.description)}
                        </div>
                        <div className="ua-revenue-po-link">{sourceLabel(journal)}</div>
                      </td>
                      <td className="ua-revenue-cell ua-revenue-cell--money ua-revenue-cell--total">
                        {moneyWithCurrency(journal.total_debit, journal.currency_code)}
                      </td>
                      <td className="ua-revenue-cell ua-revenue-cell--money">
                        {moneyWithCurrency(journal.total_credit, journal.currency_code)}
                      </td>
                      <td className="ua-revenue-cell ua-revenue-cell--status">
                        <span className={statusClass(journal.status)}>
                          {statusLabel(journal.status)}
                        </span>
                      </td>
                      <td className="ua-revenue-cell ua-revenue-cell--actions">
                        <button
                          type="button"
                          className="ua-revenue-menu__btn"
                          aria-label={`Open ${journal.journal_number}`}
                          onClick={(e) => {
                            e.stopPropagation()
                            openJournal(journal)
                          }}
                        >
                          ›
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}

          {!journalsQuery.isLoading && !filtered.length ? (
            <div className="ua-revenue-table-card ua-revenue-table-card--empty">
              <EmptyState
                title="No journals in this view"
                body={
                  hasActiveFilters
                    ? 'Try clearing filters or widening the date range.'
                    : 'Create a manual journal, or post activity from Revenue, Purchases, or Balances.'
                }
              />
            </div>
          ) : null}
        </div>
      </section>

      {createOpen
        ? createPortal(
            <div
              className="ua-journals-modal-overlay"
              role="presentation"
              onClick={() => !saveMutation.isPending && setCreateOpen(false)}
            >
              <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="ua-journal-create-title"
                className="ua-journals-modal"
                onClick={(e) => e.stopPropagation()}
              >
                <form className="ua-journals-modal__form" onSubmit={onCreateSubmit}>
                  <div className="ua-journals-modal__head">
                    <h2 id="ua-journal-create-title">New journal entry</h2>
                    <button
                      type="button"
                      className="ua-journals-modal__close"
                      aria-label="Close"
                      disabled={saveMutation.isPending}
                      onClick={() => setCreateOpen(false)}
                    >
                      ×
                    </button>
                  </div>

                  <div className="ua-journals-modal__body">
                    {createError ? <ErrorBanner message={createError} /> : null}

                    <div className="ua-journals-form-grid">
                      <div>
                        <Label>Date</Label>
                        <Input
                          type="date"
                          value={journalDate}
                          onChange={(e) => setJournalDate(e.target.value)}
                          required
                        />
                      </div>
                      <div>
                        <Label>Currency</Label>
                        <Select value={currency} onChange={(e) => setCurrency(e.target.value)}>
                          {(currencies.data?.data?.length
                            ? currencies.data.data.map((row) => row.code)
                            : ['TZS']
                          ).map((code) => (
                            <option key={code} value={code}>
                              {code}
                            </option>
                          ))}
                        </Select>
                      </div>
                      <div className="ua-journals-form-grid__wide">
                        <Label>Description</Label>
                        <Input
                          value={description}
                          onChange={(e) => setDescription(e.target.value)}
                          placeholder="What is this journal for?"
                          required
                        />
                      </div>
                      <div className="ua-journals-form-grid__wide">
                        <Label>Reference</Label>
                        <Input
                          value={reference}
                          onChange={(e) => setReference(e.target.value)}
                          placeholder="Optional reference"
                        />
                      </div>
                    </div>

                    <div className="ua-journals-lines-head">
                      <div className="ua-journals-lines-title">Lines</div>
                      <button
                        type="button"
                        className="ua-journals-add-line"
                        onClick={() => setLines((prev) => [...prev, newDraftLine()])}
                      >
                        Add line
                      </button>
                    </div>

                    <div className="ua-journals-lines">
                      {lines.map((line, index) => (
                        <div key={line.key} className="ua-journals-line">
                          <div className="ua-journals-line__no">{index + 1}</div>
                          <div className="ua-journals-line__account">
                            <Label>Account</Label>
                            <Select
                              value={line.account_id}
                              onChange={(e) => updateLine(line.key, { account_id: e.target.value })}
                              required
                            >
                              <option value="">Select account</option>
                              {accountOptions.map((account) => (
                                <option key={account.id} value={account.id}>
                                  {account.code} - {account.name}
                                </option>
                              ))}
                            </Select>
                          </div>
                          <div>
                            <Label>Debit</Label>
                            <Input
                              inputMode="decimal"
                              value={line.debit}
                              onChange={(e) =>
                                updateLine(line.key, {
                                  debit: e.target.value,
                                  credit: e.target.value.trim() ? '' : line.credit,
                                })
                              }
                              placeholder="0.00"
                            />
                          </div>
                          <div>
                            <Label>Credit</Label>
                            <Input
                              inputMode="decimal"
                              value={line.credit}
                              onChange={(e) =>
                                updateLine(line.key, {
                                  credit: e.target.value,
                                  debit: e.target.value.trim() ? '' : line.debit,
                                })
                              }
                              placeholder="0.00"
                            />
                          </div>
                          <div className="ua-journals-line__memo">
                            <Label>Memo</Label>
                            <Input
                              value={line.description}
                              onChange={(e) => updateLine(line.key, { description: e.target.value })}
                              placeholder="Optional"
                            />
                          </div>
                          <button
                            type="button"
                            className="ua-journals-line__remove"
                            aria-label={`Remove line ${index + 1}`}
                            disabled={lines.length <= 2}
                            onClick={() =>
                              setLines((prev) => prev.filter((row) => row.key !== line.key))
                            }
                          >
                            ×
                          </button>
                        </div>
                      ))}
                    </div>

                    <div className={`ua-journals-balance${draftTotals.balanced ? ' is-ok' : ' is-bad'}`}>
                      <span>
                        Debit {moneyWithCurrency(draftTotals.debit, currency)} · Credit{' '}
                        {moneyWithCurrency(draftTotals.credit, currency)}
                      </span>
                      <strong>
                        {draftTotals.balanced
                          ? 'Balanced'
                          : `Difference ${moneyWithCurrency(Math.abs(draftTotals.diff), currency)}`}
                      </strong>
                    </div>

                    <label className="ua-journals-post-toggle">
                      <input
                        type="checkbox"
                        checked={postAfterCreate}
                        onChange={(e) => setPostAfterCreate(e.target.checked)}
                      />
                      Post immediately after saving
                    </label>
                  </div>

                  <div className="ua-journals-modal__actions">
                    <Button
                      type="submit"
                      pill
                      disabled={!ready || saveMutation.isPending || !draftTotals.balanced}
                    >
                      {saveMutation.isPending
                        ? postAfterCreate
                          ? 'Posting...'
                          : 'Saving...'
                        : postAfterCreate
                          ? 'Create & post'
                          : 'Save draft'}
                    </Button>
                    <Button
                      type="button"
                      variant="secondary"
                      pill
                      disabled={saveMutation.isPending}
                      onClick={() => setCreateOpen(false)}
                    >
                      Cancel
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
