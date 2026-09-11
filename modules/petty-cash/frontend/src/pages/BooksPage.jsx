import { useEffect, useState } from 'react'
import { BookPlus, Loader2, Tags, BarChart3 } from 'lucide-react'
import {
  createBook,
  deskUrl,
  fetchInit,
  formatMoney,
} from '../api/cashbook.js'

export default function BooksPage() {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [books, setBooks] = useState([])
  const [summary, setSummary] = useState(null)
  const [showCreate, setShowCreate] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({ name: '', opening_balance: '0', notes: '' })

  async function load() {
    setLoading(true)
    setError('')
    try {
      const data = await fetchInit()
      setBooks(data.books || [])
      setSummary(data.summary || null)
    } catch (e) {
      setError(e.message || 'Failed to load cash books')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
  }, [])

  async function onCreate(e) {
    e.preventDefault()
    setSaving(true)
    setError('')
    try {
      await createBook({
        name: form.name,
        opening_balance: Number(form.opening_balance) || 0,
        notes: form.notes,
      })
      setShowCreate(false)
      setForm({ name: '', opening_balance: '0', notes: '' })
      await load()
    } catch (err) {
      setError(err.message || 'Could not create book')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="cb-page">
        <div className="cb-loading">
          <Loader2 className="cb-spin" size={20} /> Loading cash books...
        </div>
      </div>
    )
  }

  return (
    <div className="cb-page">
      <div className="cb-toolbar">
        <div className="cb-toolbar-actions" style={{ marginLeft: 'auto' }}>
          <a className="cb-text-link" href={deskUrl('categories')}>
            <Tags size={16} /> Categories
          </a>
          <a className="cb-text-link" href={deskUrl('reports')}>
            <BarChart3 size={16} /> Reports
          </a>
          <button type="button" className="cb-btn cb-btn-primary cb-btn-pill" onClick={() => setShowCreate(true)}>
            <BookPlus size={16} /> New book
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
            <span className="cb-kpi-label">Net balance</span>
            <div className="cb-kpi-value">{formatMoney(summary.total_balance)}</div>
          </div>
        </div>
      ) : null}

      {books.length === 0 ? (
        <div className="cb-empty">
          <p>No cash books yet. Create one to start recording daily cash in and cash out.</p>
          <button type="button" className="cb-btn cb-btn-primary cb-btn-pill" onClick={() => setShowCreate(true)}>
            <BookPlus size={16} /> Create cash book
          </button>
        </div>
      ) : (
        <div className="cb-table-wrap">
          <table className="cb-table">
            <thead>
              <tr>
                <th>Book</th>
                <th>Entries</th>
                <th>Opening</th>
                <th>Balance</th>
              </tr>
            </thead>
            <tbody>
              {books.map((b) => (
                <tr
                  key={b.id}
                  className="cb-table-row-link"
                  onClick={() => { window.location.href = deskUrl('book', { id: b.id }) }}
                >
                  <td>
                    <strong>{b.name}</strong>
                  </td>
                  <td>{b.entry_count}</td>
                  <td>{formatMoney(b.opening_balance)}</td>
                  <td>
                    <strong>{formatMoney(b.balance)}</strong>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showCreate ? (
        <div className="cb-modal-backdrop" onClick={() => !saving && setShowCreate(false)}>
          <form
            className="cb-modal"
            onClick={(e) => e.stopPropagation()}
            onSubmit={onCreate}
          >
            <h3>New cash book</h3>
            <div className="cb-field">
              <label htmlFor="cb-name">Name</label>
              <input
                id="cb-name"
                className="cb-input"
                required
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                placeholder="e.g. Office float"
              />
            </div>
            <div className="cb-field">
              <label htmlFor="cb-open">Opening balance</label>
              <input
                id="cb-open"
                className="cb-input"
                type="number"
                step="0.01"
                value={form.opening_balance}
                onChange={(e) => setForm((f) => ({ ...f, opening_balance: e.target.value }))}
              />
            </div>
            <div className="cb-field">
              <label htmlFor="cb-notes">Notes</label>
              <textarea
                id="cb-notes"
                className="cb-textarea"
                rows={2}
                value={form.notes}
                onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
              />
            </div>
            <div className="cb-modal-actions">
              <button type="button" className="cb-btn" disabled={saving} onClick={() => setShowCreate(false)}>
                Cancel
              </button>
              <button type="submit" className="cb-btn cb-btn-primary" disabled={saving}>
                {saving ? 'Saving...' : 'Create'}
              </button>
            </div>
          </form>
        </div>
      ) : null}
    </div>
  )
}
