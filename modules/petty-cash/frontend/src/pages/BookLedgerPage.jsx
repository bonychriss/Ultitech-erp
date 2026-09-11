import { useCallback, useEffect, useMemo, useState } from 'react'
import { ArrowDownCircle, ArrowUpCircle, Loader2, Pencil, Trash2, ArrowLeft, SlidersHorizontal, X } from 'lucide-react'
import {
  booksUrl,
  createEntry,
  deleteEntry,
  fetchCategories,
  fetchEntries,
  formatDate,
  formatMoney,
  todayISO,
  updateEntry,
} from '../api/cashbook.js'

function bookIdFromWindow() {
  if (typeof window === 'undefined') return 0
  const fromCfg = Number(window.__CASHBOOK_BOOK_ID__ || 0)
  if (fromCfg > 0) return fromCfg
  const p = new URLSearchParams(window.location.search)
  return Number(p.get('id') || 0) || 0
}

const emptyForm = {
  entry_date: todayISO(),
  amount: '',
  category_id: '',
  party_name: '',
  remark: '',
}

export default function BookLedgerPage() {
  const bookId = useMemo(() => bookIdFromWindow(), [])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [book, setBook] = useState(null)
  const [entries, setEntries] = useState([])
  const [summary, setSummary] = useState(null)
  const [categories, setCategories] = useState([])
  const [filters, setFilters] = useState({ date_from: '', date_to: '', entry_type: '' })
  const [modal, setModal] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [saving, setSaving] = useState(false)
  const [filterOpen, setFilterOpen] = useState(false)

  const filterActive = Boolean(filters.date_from || filters.date_to || filters.entry_type)

  const load = useCallback(async () => {
    if (bookId <= 0) {
      setError('Missing cash book id.')
      setLoading(false)
      return
    }
    setLoading(true)
    setError('')
    try {
      const query = {}
      if (filters.date_from) query.date_from = filters.date_from
      if (filters.date_to) query.date_to = filters.date_to
      if (filters.entry_type) query.entry_type = filters.entry_type
      const data = await fetchEntries(bookId, query)
      setBook(data.book || null)
      setEntries(data.entries || [])
      setSummary(data.summary || null)
      if (typeof window !== 'undefined' && data.book?.name) {
        const titleEl = document.querySelector('.employee-header-page-title')
        if (titleEl) titleEl.textContent = data.book.name
      }
    } catch (e) {
      setError(e.message || 'Failed to load ledger')
    } finally {
      setLoading(false)
    }
  }, [bookId, filters])

  useEffect(() => {
    load()
  }, [load])

  async function openModal(mode, entry = null) {
    setError('')
    const type = mode === 'edit' ? entry?.entry_type : mode
    try {
      const cats = await fetchCategories(type === 'in' || type === 'out' ? type : undefined)
      setCategories(cats.categories || [])
    } catch {
      setCategories([])
    }
    if (mode === 'edit' && entry) {
      setForm({
        entry_date: entry.entry_date || todayISO(),
        amount: String(entry.amount ?? ''),
        category_id: entry.category_id ? String(entry.category_id) : '',
        party_name: entry.party_name || '',
        remark: entry.remark || '',
      })
      setModal({ mode: 'edit', entry, entry_type: entry.entry_type })
    } else {
      setForm({ ...emptyForm, entry_date: todayISO() })
      setModal({ mode, entry_type: mode })
    }
  }

  async function onSave(e) {
    e.preventDefault()
    setSaving(true)
    setError('')
    try {
      const payload = {
        book_id: bookId,
        entry_type: modal.entry_type,
        entry_date: form.entry_date,
        amount: Number(form.amount),
        category_id: form.category_id ? Number(form.category_id) : null,
        party_name: form.party_name,
        remark: form.remark,
      }
      if (modal.mode === 'edit') {
        await updateEntry(modal.entry.id, payload)
      } else {
        await createEntry(payload)
      }
      setModal(null)
      await load()
    } catch (err) {
      setError(err.message || 'Could not save entry')
    } finally {
      setSaving(false)
    }
  }

  async function onDelete(entry) {
    if (!window.confirm('Delete this entry?')) return
    setError('')
    try {
      await deleteEntry(entry.id)
      await load()
    } catch (err) {
      setError(err.message || 'Could not delete entry')
    }
  }

  if (loading && !book) {
    return (
      <div className="cb-page">
        <div className="cb-loading">
          <Loader2 className="cb-spin" size={20} /> Loading ledger...
        </div>
      </div>
    )
  }

  return (
    <div className="cb-page">
      <div className="cb-toolbar">
        <a className="cb-back-link" href={booksUrl()}>
          <ArrowLeft size={16} /> All books
        </a>
        <div className="cb-toolbar-actions">
          <button type="button" className="cb-btn cb-btn-in cb-btn-pill cb-btn-sm" onClick={() => openModal('in')}>
            <ArrowDownCircle size={14} /> Cash in
          </button>
          <button type="button" className="cb-btn cb-btn-out cb-btn-pill cb-btn-sm" onClick={() => openModal('out')}>
            <ArrowUpCircle size={14} /> Cash out
          </button>
        </div>
      </div>

      {error ? <div className="cb-error">{error}</div> : null}

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
            <span className="cb-kpi-label">Balance</span>
            <div className="cb-kpi-value">{formatMoney(summary.closing_balance)}</div>
          </div>
        </div>
      ) : null}

      <div className="cb-filter-bar">
        <div className="cb-filter-anchor">
          <button
            type="button"
            className={`cb-btn cb-btn-pill cb-btn-sm cb-filter-btn${filterActive || filterOpen ? ' is-active' : ''}`}
            onClick={() => setFilterOpen((o) => !o)}
          >
            <SlidersHorizontal size={14} />
            Filter
            {filterActive ? <span className="cb-filter-dot" /> : null}
          </button>
          {filterOpen ? (
            <div className="cb-filter-panel">
              <div className="cb-filter-panel-head">
                <strong>Filters</strong>
                <button type="button" className="cb-filter-close" onClick={() => setFilterOpen(false)} aria-label="Close">
                  <X size={16} />
                </button>
              </div>
              <div className="cb-field">
                <label>From</label>
                <input
                  type="date"
                  className="cb-input"
                  value={filters.date_from}
                  onChange={(e) => setFilters((f) => ({ ...f, date_from: e.target.value }))}
                />
              </div>
              <div className="cb-field">
                <label>To</label>
                <input
                  type="date"
                  className="cb-input"
                  value={filters.date_to}
                  onChange={(e) => setFilters((f) => ({ ...f, date_to: e.target.value }))}
                />
              </div>
              <div className="cb-field">
                <label>Type</label>
                <select
                  className="cb-select"
                  value={filters.entry_type}
                  onChange={(e) => setFilters((f) => ({ ...f, entry_type: e.target.value }))}
                >
                  <option value="">All types</option>
                  <option value="in">Cash in</option>
                  <option value="out">Cash out</option>
                </select>
              </div>
              <div className="cb-filter-panel-actions">
                <button
                  type="button"
                  className="cb-btn cb-btn-sm"
                  onClick={() => setFilters({ date_from: '', date_to: '', entry_type: '' })}
                >
                  Clear
                </button>
                <button
                  type="button"
                  className="cb-btn cb-btn-primary cb-btn-pill cb-btn-sm"
                  onClick={() => setFilterOpen(false)}
                >
                  Done
                </button>
              </div>
            </div>
          ) : null}
        </div>
      </div>

      {entries.length === 0 ? (
        <div className="cb-empty">No entries in this period. Record cash in or cash out to begin.</div>
      ) : (
        <div className="cb-table-wrap">
          <table className="cb-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Category</th>
                <th>Particulars</th>
                <th>Amount</th>
                <th>Balance</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((row) => (
                <tr key={row.id}>
                  <td>{formatDate(row.entry_date)}</td>
                  <td>
                    <span className={`cb-entry-type ${row.entry_type}`}>
                      {row.entry_type === 'in' ? 'Cash in' : 'Cash out'}
                    </span>
                  </td>
                  <td>{row.category_name || '-'}</td>
                  <td>{[row.party_name, row.remark].filter(Boolean).join(' - ') || '-'}</td>
                  <td className={`cb-entry-amt ${row.entry_type}`}>
                    {row.entry_type === 'in' ? '+' : '-'}
                    {formatMoney(row.amount)}
                  </td>
                  <td>{formatMoney(row.balance_after)}</td>
                  <td>
                    <div className="cb-entry-actions-inline">
                      <button type="button" className="cb-icon-btn" onClick={() => openModal('edit', row)} title="Edit" aria-label="Edit">
                        <Pencil size={16} />
                      </button>
                      <button type="button" className="cb-icon-btn" onClick={() => onDelete(row)} title="Delete" aria-label="Delete">
                        <Trash2 size={16} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {modal ? (
        <div className="cb-modal-backdrop" onClick={() => !saving && setModal(null)}>
          <form className="cb-modal" onClick={(e) => e.stopPropagation()} onSubmit={onSave}>
            <h3>
              {modal.mode === 'edit'
                ? 'Edit entry'
                : modal.entry_type === 'in'
                  ? 'Cash in'
                  : 'Cash out'}
            </h3>
            <div className="cb-field">
              <label>Date</label>
              <input
                type="date"
                className="cb-input"
                required
                value={form.entry_date}
                onChange={(e) => setForm((f) => ({ ...f, entry_date: e.target.value }))}
              />
            </div>
            <div className="cb-field">
              <label>Amount</label>
              <input
                type="number"
                className="cb-input"
                min="0.01"
                step="0.01"
                required
                value={form.amount}
                onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))}
              />
            </div>
            <div className="cb-field">
              <label>Category</label>
              <select
                className="cb-select"
                value={form.category_id}
                onChange={(e) => setForm((f) => ({ ...f, category_id: e.target.value }))}
              >
                <option value="">- None -</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="cb-field">
              <label>Party / payee</label>
              <input
                className="cb-input"
                value={form.party_name}
                onChange={(e) => setForm((f) => ({ ...f, party_name: e.target.value }))}
                placeholder="Optional"
              />
            </div>
            <div className="cb-field">
              <label>Remark</label>
              <textarea
                className="cb-textarea"
                rows={2}
                value={form.remark}
                onChange={(e) => setForm((f) => ({ ...f, remark: e.target.value }))}
              />
            </div>
            <div className="cb-modal-actions">
              <button type="button" className="cb-btn cb-btn-pill cb-btn-sm" disabled={saving} onClick={() => setModal(null)}>
                Cancel
              </button>
              <button
                type="submit"
                className={`cb-btn cb-btn-pill cb-btn-sm ${modal.entry_type === 'in' ? 'cb-btn-in' : 'cb-btn-out'}`}
                disabled={saving}
              >
                {saving ? 'Saving...' : 'Save'}
              </button>
            </div>
          </form>
        </div>
      ) : null}
    </div>
  )
}
