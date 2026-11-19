import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { readBoot } from '../lib/boot'
import {
  BankingMovementsList,
  sortMovementsNewestFirst,
  type BankMovement,
} from '../components/BankingMovementsList'
import { EmptyState, ErrorBanner, PageHeader, SectionTitle } from '../components/ui'

type DirectionFilter = 'all' | 'in' | 'out'

function movementDirection(txn: BankMovement): 'in' | 'out' | 'flat' {
  const inAmt = txn.credit && Number(txn.credit) ? Number(txn.credit) : 0
  const outAmt = txn.debit && Number(txn.debit) ? Number(txn.debit) : 0
  if (inAmt > 0 && outAmt <= 0) return 'in'
  if (outAmt > 0 && inAmt <= 0) return 'out'
  return 'flat'
}

function txnDate(txn: BankMovement): string {
  return String(txn.transaction_date || '').slice(0, 10)
}

function buildTransactionsPath(dateFrom: string, dateTo: string): string {
  const params = new URLSearchParams()
  params.set('limit', '1000')
  if (dateFrom) params.set('date_from', dateFrom)
  if (dateTo) params.set('date_to', dateTo)
  return `/bank-transactions?${params.toString()}`
}

export function BankingMovementsPage() {
  const { token, companyId, bookId } = useAuth()
  const ready = Boolean(token && companyId)
  const hostedInErp = Boolean(readBoot().hostedInErp)
  const [query, setQuery] = useState('')
  const [direction, setDirection] = useState<DirectionFilter>('all')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const txns = useQuery({
    queryKey: ['bank-transactions', companyId, bookId, 'all', dateFrom, dateTo],
    enabled: ready,
    queryFn: () =>
      apiRequest<{ data: BankMovement[] }>(buildTransactionsPath(dateFrom, dateTo), {
        token,
        companyId,
        bookId,
      }),
  })

  const rows = txns.data?.data ?? []

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    return sortMovementsNewestFirst(rows).filter((txn) => {
      const dir = movementDirection(txn)
      if (direction === 'in' && dir !== 'in') return false
      if (direction === 'out' && dir !== 'out') return false
      const day = txnDate(txn)
      if (dateFrom && day && day < dateFrom) return false
      if (dateTo && day && day > dateTo) return false
      if (!q) return true
      const hay = [txn.description, txn.reference, txn.account_name]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return hay.includes(q)
    })
  }, [rows, query, direction, dateFrom, dateTo])

  const inCount = useMemo(
    () =>
      rows.filter((txn) => {
        if (movementDirection(txn) !== 'in') return false
        const day = txnDate(txn)
        if (dateFrom && day && day < dateFrom) return false
        if (dateTo && day && day > dateTo) return false
        return true
      }).length,
    [rows, dateFrom, dateTo],
  )
  const outCount = useMemo(
    () =>
      rows.filter((txn) => {
        if (movementDirection(txn) !== 'out') return false
        const day = txnDate(txn)
        if (dateFrom && day && day < dateFrom) return false
        if (dateTo && day && day > dateTo) return false
        return true
      }).length,
    [rows, dateFrom, dateTo],
  )

  const hasDateFilter = Boolean(dateFrom || dateTo)
  const clearDates = () => {
    setDateFrom('')
    setDateTo('')
  }

  return (
    <div className="ua-banking">
      <PageHeader
        title="Money in and out"
        subtitle={`${filtered.length} movement${filtered.length === 1 ? '' : 's'}`}
        breadcrumb={
          hostedInErp ? undefined : (
            <>
              <Link to="/banking">Balances</Link>
              <span className="ua-breadcrumb__sep" aria-hidden="true">
                /
              </span>
              <span className="ua-breadcrumb__current">Money in and out</span>
            </>
          )
        }
      />

      {!ready ? (
        <ErrorBanner message="Company session not ready. Re-open Accounting from ERP." />
      ) : null}
      {txns.isError ? <ErrorBanner message="Could not load movements." /> : null}

      <section className="ua-banking-panel">
        <div className="ua-banking-panel__head ua-banking-panel__head--split">
          <div className="ua-banking-moves-filters" role="tablist" aria-label="Movement type">
            <button
              type="button"
              role="tab"
              aria-selected={direction === 'all'}
              className={`ua-banking-filter${direction === 'all' ? ' is-active' : ''}`}
              onClick={() => setDirection('all')}
            >
              All ({rows.length})
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={direction === 'in'}
              className={`ua-banking-filter${direction === 'in' ? ' is-active' : ''}`}
              onClick={() => setDirection('in')}
            >
              Money in ({inCount})
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={direction === 'out'}
              className={`ua-banking-filter${direction === 'out' ? ' is-active' : ''}`}
              onClick={() => setDirection('out')}
            >
              Money out ({outCount})
            </button>
          </div>
          <div className="ua-banking-panel__tools">
            <label className="ua-banking-date">
              <span>From</span>
              <input
                type="date"
                className="ua-banking-date__input"
                value={dateFrom}
                max={dateTo || undefined}
                onChange={(e) => setDateFrom(e.target.value)}
                aria-label="From date"
              />
            </label>
            <label className="ua-banking-date">
              <span>To</span>
              <input
                type="date"
                className="ua-banking-date__input"
                value={dateTo}
                min={dateFrom || undefined}
                onChange={(e) => setDateTo(e.target.value)}
                aria-label="To date"
              />
            </label>
            {hasDateFilter ? (
              <button type="button" className="ua-banking-date-clear" onClick={clearDates}>
                Clear dates
              </button>
            ) : null}
            <input
              className="ua-banking-search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search movements"
              aria-label="Search movements"
            />
          </div>
        </div>
        <div className="ua-banking-panel__body">
          {txns.isLoading && rows.length === 0 ? (
            <div className="ua-banking-panel__note">Loading…</div>
          ) : filtered.length ? (
            <>
              <div className="ua-banking-panel__subhead">
                <SectionTitle>
                  {direction === 'in'
                    ? 'Money in'
                    : direction === 'out'
                      ? 'Money out'
                      : 'All movements'}
                </SectionTitle>
                <span className="ua-banking-panel__count">
                  Showing {filtered.length}
                  {filtered.length !== rows.length ? ` of ${rows.length}` : ''}
                  {hasDateFilter
                    ? ` · ${dateFrom || '…'} → ${dateTo || '…'}`
                    : ''}
                </span>
              </div>
              <BankingMovementsList
                items={filtered}
                accountName={(txn) => txn.account_name || '—'}
              />
            </>
          ) : (
            <EmptyState
              title="No movements found"
              body={
                query || direction !== 'all' || hasDateFilter
                  ? 'Try a different search, date range, or filter.'
                  : 'When money comes in or goes out, it will show here.'
              }
            />
          )}
        </div>
      </section>
    </div>
  )
}
