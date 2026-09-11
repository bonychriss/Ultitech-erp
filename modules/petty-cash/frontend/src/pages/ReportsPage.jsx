import { useEffect, useState } from 'react'
import { ArrowLeft, Loader2 } from 'lucide-react'
import { booksUrl, fetchBooks, fetchReport, formatMoney, todayISO } from '../api/cashbook.js'

function monthStart() {
  const d = new Date()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  return `${d.getFullYear()}-${m}-01`
}

export default function ReportsPage() {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [books, setBooks] = useState([])
  const [report, setReport] = useState(null)
  const [filters, setFilters] = useState({
    date_from: monthStart(),
    date_to: todayISO(),
    book_id: '',
  })

  async function load() {
    setLoading(true)
    setError('')
    try {
      const [booksRes, reportRes] = await Promise.all([
        fetchBooks('all'),
        fetchReport({
          date_from: filters.date_from || undefined,
          date_to: filters.date_to || undefined,
          book_id: filters.book_id || undefined,
        }),
      ])
      setBooks(booksRes.books || [])
      setReport(reportRes.report || null)
    } catch (e) {
      setError(e.message || 'Failed to load report')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.date_from, filters.date_to, filters.book_id])

  const summary = report?.summary

  return (
    <div className="cb-page">
      <div className="cb-toolbar">
        <div>
          <a className="cb-back-link" href={booksUrl()}>
            <ArrowLeft size={16} /> All books
          </a>
          <h2 style={{ marginTop: '0.35rem' }}>Reports</h2>
        </div>
      </div>

      <div className="cb-filters">
        <input
          type="date"
          className="cb-input"
          value={filters.date_from}
          onChange={(e) => setFilters((f) => ({ ...f, date_from: e.target.value }))}
        />
        <input
          type="date"
          className="cb-input"
          value={filters.date_to}
          onChange={(e) => setFilters((f) => ({ ...f, date_to: e.target.value }))}
        />
        <select
          className="cb-select"
          value={filters.book_id}
          onChange={(e) => setFilters((f) => ({ ...f, book_id: e.target.value }))}
        >
          <option value="">All books</option>
          {books.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </div>

      {error ? <div className="cb-error">{error}</div> : null}

      {loading ? (
        <div className="cb-loading">
          <Loader2 className="cb-spin" size={20} /> Loading report...
        </div>
      ) : (
        <>
          {summary ? (
            <div className="cb-summary">
              <div className="cb-kpi">
                <span className="cb-kpi-label">Cash in</span>
                <div className="cb-kpi-value in">{formatMoney(summary.total_in)}</div>
              </div>
              <div className="cb-kpi">
                <span className="cb-kpi-label">Cash out</span>
                <div className="cb-kpi-value out">{formatMoney(summary.total_out)}</div>
              </div>
              <div className="cb-kpi">
                <span className="cb-kpi-label">Net</span>
                <div className="cb-kpi-value">{formatMoney(summary.net)}</div>
              </div>
            </div>
          ) : null}

          <h2 style={{ fontSize: '1rem', margin: '0 0 0.75rem' }}>By book</h2>
          {(report?.by_book || []).length === 0 ? (
            <div className="cb-empty" style={{ marginBottom: '1.25rem' }}>
              No entries in this range.
            </div>
          ) : (
            <div className="cb-table-wrap" style={{ marginBottom: '1.5rem' }}>
              <table className="cb-table">
                <thead>
                  <tr>
                    <th>Book</th>
                    <th>In</th>
                    <th>Out</th>
                    <th>Net</th>
                    <th>Entries</th>
                  </tr>
                </thead>
                <tbody>
                  {report.by_book.map((r) => (
                    <tr key={r.book_id}>
                      <td>{r.book_name}</td>
                      <td className="cb-entry-amt in">{formatMoney(r.total_in)}</td>
                      <td className="cb-entry-amt out">{formatMoney(r.total_out)}</td>
                      <td>{formatMoney(r.net)}</td>
                      <td>{r.entry_count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <h2 style={{ fontSize: '1rem', margin: '0 0 0.75rem' }}>By category</h2>
          {(report?.by_category || []).length === 0 ? (
            <div className="cb-empty">No category breakdown.</div>
          ) : (
            <div className="cb-table-wrap">
              <table className="cb-table">
                <thead>
                  <tr>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Total</th>
                    <th>Count</th>
                  </tr>
                </thead>
                <tbody>
                  {report.by_category.map((r, i) => (
                    <tr key={`${r.category_name}-${r.entry_type}-${i}`}>
                      <td>{r.category_name}</td>
                      <td>{r.entry_type === 'in' ? 'Cash in' : 'Cash out'}</td>
                      <td className={`cb-entry-amt ${r.entry_type}`}>{formatMoney(r.total)}</td>
                      <td>{r.count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  )
}
