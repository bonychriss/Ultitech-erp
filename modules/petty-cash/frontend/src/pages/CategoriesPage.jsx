import { useEffect, useState } from 'react'
import { Loader2, Plus, Trash2, ArrowLeft } from 'lucide-react'
import { booksUrl, createCategory, deleteCategory, fetchCategories } from '../api/cashbook.js'

export default function CategoriesPage() {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [categories, setCategories] = useState([])
  const [name, setName] = useState('')
  const [entryType, setEntryType] = useState('both')
  const [saving, setSaving] = useState(false)

  async function load() {
    setLoading(true)
    setError('')
    try {
      const data = await fetchCategories()
      setCategories(data.categories || [])
    } catch (e) {
      setError(e.message || 'Failed to load categories')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
  }, [])

  async function onAdd(e) {
    e.preventDefault()
    setSaving(true)
    setError('')
    try {
      await createCategory({ name, entry_type: entryType })
      setName('')
      setEntryType('both')
      await load()
    } catch (err) {
      setError(err.message || 'Could not create category')
    } finally {
      setSaving(false)
    }
  }

  async function onDelete(id) {
    if (!window.confirm('Delete this category?')) return
    try {
      await deleteCategory(id)
      await load()
    } catch (err) {
      setError(err.message || 'Could not delete category')
    }
  }

  return (
    <div className="cb-page cb-page-wide">
      <div className="cb-toolbar">
        <a className="cb-back-link" href={booksUrl()}>
          <ArrowLeft size={16} /> All books
        </a>
      </div>

      {error ? <div className="cb-error">{error}</div> : null}

      <div className="cb-split">
        <section className="cb-split-card">
          <div className="cb-split-card-head">
            <h3>Create</h3>
          </div>
          <form className="cb-create-form" onSubmit={onAdd}>
            <div className="cb-field">
              <label>Category name</label>
              <input
                className="cb-input"
                required
                placeholder="Category name"
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </div>
            <div className="cb-field">
              <label>Type</label>
              <select className="cb-select" value={entryType} onChange={(e) => setEntryType(e.target.value)}>
                <option value="both">In and out</option>
                <option value="in">Cash in</option>
                <option value="out">Cash out</option>
              </select>
            </div>
            <div className="cb-create-actions">
              <button type="submit" className="cb-btn cb-btn-primary cb-btn-pill cb-btn-sm" disabled={saving}>
                <Plus size={14} /> {saving ? 'Saving...' : 'Add'}
              </button>
            </div>
          </form>
        </section>

        <section className="cb-split-card">
          <div className="cb-split-card-head">
            <h3>Categories</h3>
          </div>
          {loading ? (
            <div className="cb-loading">
              <Loader2 className="cb-spin" size={20} /> Loading...
            </div>
          ) : categories.length === 0 ? (
            <div className="cb-empty cb-empty-compact">No categories yet. Create one on the left.</div>
          ) : (
            <div className="cb-table-wrap cb-table-wrap-flush">
              <table className="cb-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {categories.map((c) => (
                    <tr key={c.id}>
                      <td>{c.name}</td>
                      <td>{c.entry_type === 'both' ? 'In and out' : c.entry_type === 'in' ? 'Cash in' : 'Cash out'}</td>
                      <td>
                        <div className="cb-entry-actions-inline">
                          <button
                            type="button"
                            className="cb-icon-btn"
                            onClick={() => onDelete(c.id)}
                            title="Delete"
                            aria-label="Delete"
                          >
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
        </section>
      </div>
    </div>
  )
}
