import { useEffect, useState } from 'react'
import { BookPlus, Loader2, Tags, BarChart3, Trash2, Check, X } from 'lucide-react'
import {
  approveDeleteRequest,
  cancelDeleteRequest,
  createBook,
  deskUrl,
  fetchInit,
  formatMoney,
  rejectDeleteRequest,
  requestDeleteBook,
} from '../api/cashbook.js'

export default function BooksPage() {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [books, setBooks] = useState([])
  const [summary, setSummary] = useState(null)
  const [isAdmin, setIsAdmin] = useState(false)
  const [userId, setUserId] = useState(0)
  const [showCreate, setShowCreate] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleteReason, setDeleteReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({ name: '', opening_balance: '0', notes: '' })

  async function load() {
    setLoading(true)
    setError('')
    try {
      const data = await fetchInit()
      setBooks(data.books || [])
      setSummary(data.summary || null)
      setIsAdmin(Boolean(data.user?.is_admin || data.capabilities?.approve_delete))
      setUserId(Number(data.user?.id) || 0)
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
    setInfo('')
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

  async function onRequestDelete(e) {
    e.preventDefault()
    if (!deleteTarget) return
    setSaving(true)
    setError('')
    setInfo('')
    try {
      await requestDeleteBook(deleteTarget.id, deleteReason)
      setDeleteTarget(null)
      setDeleteReason('')
      setInfo('Delete requested. An admin must approve before the cash book and its records are removed.')
      await load()
    } catch (err) {
      setError(err.message || 'Could not request delete')
    } finally {
      setSaving(false)
    }
  }

  async function onApprove(reqId) {
    setSaving(true)
    setError('')
    setInfo('')
    try {
      await approveDeleteRequest(reqId)
      setInfo('Cash book and its records deleted.')
      await load()
    } catch (err) {
      setError(err.message || 'Could not approve delete')
    } finally {
      setSaving(false)
    }
  }

  async function onRejectOrCancel(reqId, asCancel) {
    setSaving(true)
    setError('')
    setInfo('')
    try {
      if (asCancel) {
        await cancelDeleteRequest(reqId)
        setInfo('Delete request cancelled.')
      } else {
        await rejectDeleteRequest(reqId)
        setInfo('Delete request rejected.')
      }
      await load()
    } catch (err) {
      setError(err.message || 'Could not update delete request')
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
      {info ? <div className="cb-info">{info}</div> : null}

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
                <th className="cb-col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              {books.map((b) => {
                const pending = b.delete_request
                const canCancel =
                  pending && (isAdmin || Number(pending.requested_by) === userId)
                return (
                  <tr
                    key={b.id}
                    className={`cb-table-row-link${pending ? ' is-pending-delete' : ''}`}
                    onClick={() => { window.location.href = deskUrl('book', { id: b.id }) }}
                  >
                    <td>
                      <strong>{b.name}</strong>
                      {pending ? (
                        <span className="cb-badge cb-badge-warn">Pending delete</span>
                      ) : null}
                    </td>
                    <td>{b.entry_count}</td>
                    <td>{formatMoney(b.opening_balance)}</td>
                    <td>
                      <strong>{formatMoney(b.balance)}</strong>
                    </td>
                    <td className="cb-col-actions" onClick={(e) => e.stopPropagation()}>
                      {pending ? (
                        <div className="cb-row-actions">
                          {isAdmin ? (
                            <>
                              <button
                                type="button"
                                className="cb-icon-btn cb-icon-btn-ok"
                                disabled={saving}
                                title="Approve and permanently delete"
                                aria-label="Approve delete"
                                onClick={() => onApprove(pending.id)}
                              >
                                <Check size={18} />
                              </button>
                              <button
                                type="button"
                                className="cb-icon-btn cb-icon-btn-danger"
                                disabled={saving}
                                title="Reject delete request"
                                aria-label="Reject delete"
                                onClick={() => onRejectOrCancel(pending.id, false)}
                              >
                                <X size={18} />
                              </button>
                            </>
                          ) : canCancel ? (
                            <button
                              type="button"
                              className="cb-icon-btn cb-icon-btn-danger"
                              disabled={saving}
                              title="Cancel delete request"
                              aria-label="Cancel delete request"
                              onClick={() => onRejectOrCancel(pending.id, true)}
                            >
                              <X size={18} />
                            </button>
                          ) : (
                            <span className="cb-muted-hint">Awaiting admin</span>
                          )}
                        </div>
                      ) : (
                        <button
                          type="button"
                          className="cb-icon-btn cb-icon-btn-danger"
                          disabled={saving}
                          title="Request delete (needs admin approval)"
                          aria-label="Request delete"
                          onClick={() => {
                            setDeleteTarget(b)
                            setDeleteReason('')
                            setError('')
                            setInfo('')
                          }}
                        >
                          <Trash2 size={16} />
                        </button>
                      )}
                    </td>
                  </tr>
                )
              })}
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

      {deleteTarget ? (
        <div className="cb-modal-backdrop" onClick={() => !saving && setDeleteTarget(null)}>
          <form
            className="cb-modal"
            onClick={(e) => e.stopPropagation()}
            onSubmit={onRequestDelete}
          >
            <h3>Delete cash book?</h3>
            <p className="cb-modal-copy">
              Request deletion of <strong>{deleteTarget.name}</strong>
              {Number(deleteTarget.entry_count) > 0
                ? ` and all ${deleteTarget.entry_count} entries`
                : ''}
              . An admin must approve before anything is removed.
            </p>
            <div className="cb-field">
              <label htmlFor="cb-del-reason">Reason (optional)</label>
              <textarea
                id="cb-del-reason"
                className="cb-textarea"
                rows={2}
                value={deleteReason}
                onChange={(e) => setDeleteReason(e.target.value)}
                placeholder="Why should this book be deleted?"
              />
            </div>
            <div className="cb-modal-actions">
              <button type="button" className="cb-btn" disabled={saving} onClick={() => setDeleteTarget(null)}>
                Cancel
              </button>
              <button type="submit" className="cb-btn cb-btn-danger" disabled={saving}>
                {saving ? 'Submitting...' : 'Request delete'}
              </button>
            </div>
          </form>
        </div>
      ) : null}
    </div>
  )
}
