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
    <div className="cb-page">
      <div className="cb-toolbar">
        <div>
          <a className="cb-back-link" href={booksUrl()}>
            <ArrowLeft size={16} /> All books
          </a>
          <h2 style={{ marginTop: '0.35rem' }}>Categories</h2>
        </div>
      </div>

      {error ? <div className="cb-error">{error}</div> : null}

      <form
        onSubmit={onAdd}
        style={{
          display: 'grid',
          gridTemplateColumns: '1fr auto auto',
          gap: '0.5rem',
          marginBottom: '1.25rem',
        }}
      >
        <input
          className="cb-input"
          required
          placeholder="Category name"
          value={name}
          onChange={(e) => setName(e.target.value)}
        />
        <select className="cb-select" value={entryType} onChange={(e) => setEntryType(e.target.value)}>
          <option value="both">In and out</option>
          <option value="in">Cash in</option>
          <option value="out">Cash out</option>
        </select>
        <button type="submit" className="cb-btn cb-btn-primary" disabled={saving}>
          <Plus size={16} /> Add
        </button>
      </form>

      {loading ? (
        <div className="cb-loading">
          <Loader2 className="cb-spin" size={20} /> Loading...
        </div>
      ) : categories.length === 0 ? (
        <div className="cb-empty">No categories yet.</div>
      ) : (
        <div className="cb-table-wrap">
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
                  <td style={{ textAlign: 'right' }}>
                    <button type="button" className="cb-btn" onClick={() => onDelete(c.id)}>
                      <Trash2 size={14} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
