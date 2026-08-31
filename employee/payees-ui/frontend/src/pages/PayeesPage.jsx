import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  Plus, Search, Pencil, Trash2, X, Users, Building2, User,
  Briefcase, Landmark, Loader2, CheckCircle2, AlertCircle,
} from 'lucide-react'
import { CFG } from '../config.js'

const TYPE_META = {
  Supplier: { icon: Building2, cls: 'pt-supplier' },
  Staff: { icon: User, cls: 'pt-staff' },
  'Service Provider': { icon: Briefcase, cls: 'pt-service' },
  Government: { icon: Landmark, cls: 'pt-gov' },
  Other: { icon: Users, cls: 'pt-other' },
}

function TypeBadge({ type }) {
  const meta = TYPE_META[type] || TYPE_META.Other
  const Icon = meta.icon
  return (
    <span className={`px-type ${meta.cls}`}>
      <Icon size={12} strokeWidth={2.2} />
      {type || 'Other'}
    </span>
  )
}

const EMPTY_FORM = { id: 0, name: '', type: 'Other', tin: '', contact_details: '' }

export default function PayeesPage() {
  const [payees, setPayees] = useState(CFG.payees || [])
  const [loading, setLoading] = useState(true)
  const [query, setQuery] = useState('')
  const [toast, setToast] = useState(null)

  const [modalOpen, setModalOpen] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formErr, setFormErr] = useState('')
  const [deletingId, setDeletingId] = useState(0)
  const nameRef = useRef(null)
  const toastTimer = useRef(null)

  const isEdit = form.id > 0

  const showToast = useCallback((type, text) => {
    setToast({ type, text })
    if (toastTimer.current) window.clearTimeout(toastTimer.current)
    toastTimer.current = window.setTimeout(() => setToast(null), 3200)
  }, [])

  const loadPayees = useCallback(async () => {
    setLoading(true)
    try {
      const res = await fetch(CFG.apiUrl, { headers: { Accept: 'application/json' } })
      const data = await res.json()
      if (data && data.ok && Array.isArray(data.payees)) {
        setPayees(data.payees)
      }
    } catch {
      /* keep any server-injected initial list */
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { loadPayees() }, [loadPayees])

  useEffect(() => {
    if (modalOpen) {
      const t = window.setTimeout(() => nameRef.current && nameRef.current.focus(), 60)
      return () => window.clearTimeout(t)
    }
    return undefined
  }, [modalOpen])

  useEffect(() => {
    function onKey(e) { if (e.key === 'Escape') setModalOpen(false) }
    if (modalOpen) window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [modalOpen])

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return payees
    return payees.filter((p) =>
      [p.name, p.type, p.tin, p.contact_details]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q)),
    )
  }, [payees, query])

  const openCreate = () => { setForm(EMPTY_FORM); setFormErr(''); setModalOpen(true) }
  const openEdit = (p) => {
    setForm({
      id: p.id, name: p.name || '', type: p.type || 'Other',
      tin: p.tin || '', contact_details: p.contact_details || '',
    })
    setFormErr('')
    setModalOpen(true)
  }

  async function submitForm(e) {
    e.preventDefault()
    if (!form.name.trim()) { setFormErr('Payee name is required.'); return }
    setSaving(true)
    setFormErr('')
    try {
      const res = await fetch(CFG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ ...form, action: isEdit ? 'edit' : 'create' }),
      })
      const data = await res.json()
      if (!data || !data.ok) {
        setFormErr((data && data.error) || 'Could not save the payee.')
        return
      }
      const saved = data.payee
      setPayees((prev) => {
        if (isEdit) return prev.map((p) => (p.id === saved.id ? saved : p))
        return [...prev, saved].sort((a, b) => a.name.localeCompare(b.name))
      })
      setModalOpen(false)
      showToast('ok', data.message || 'Saved.')
    } catch {
      setFormErr('Network error. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  async function deletePayee(p) {
    if (!window.confirm(`Remove "${p.name}"? This payee will no longer appear in lists.`)) return
    setDeletingId(p.id)
    try {
      const res = await fetch(CFG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ action: 'delete', id: p.id }),
      })
      const data = await res.json()
      if (data && data.ok) {
        setPayees((prev) => prev.filter((x) => x.id !== p.id))
        showToast('ok', data.message || 'Payee removed.')
      } else {
        showToast('err', (data && data.error) || 'Could not remove payee.')
      }
    } catch {
      showToast('err', 'Network error. Please try again.')
    } finally {
      setDeletingId(0)
    }
  }

  return (
    <div className="px-shell">
      {toast && (
        <div className={`px-toast ${toast.type === 'ok' ? 'px-toast-ok' : 'px-toast-err'}`}>
          {toast.type === 'ok' ? <CheckCircle2 size={16} /> : <AlertCircle size={16} />}
          <span>{toast.text}</span>
        </div>
      )}

      <div className="px-head">
        <div>
          <h1>Manage Payees</h1>
          <p>{payees.length} payee{payees.length === 1 ? '' : 's'} on record</p>
        </div>
        <button type="button" className="px-btn-primary" onClick={openCreate}>
          <Plus size={16} strokeWidth={2.4} /> Add New Payee
        </button>
      </div>

      <div className="px-search">
        <Search size={16} className="px-search-ic" />
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search payees by name, type, TIN or contact..."
        />
        {query && (
          <button type="button" className="px-search-clear" onClick={() => setQuery('')} aria-label="Clear">
            <X size={15} />
          </button>
        )}
      </div>

      <div className="px-card">
        <table className="px-table">
          <thead>
            <tr>
              <th className="px-col-name">Name</th>
              <th className="px-col-type">Type</th>
              <th className="px-col-tin">TIN</th>
              <th className="px-col-contact">Contact</th>
              <th className="px-col-act">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={5} className="px-state">
                  <Loader2 size={20} className="px-spin" /> Loading payees...
                </td>
              </tr>
            ) : filtered.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-state">
                  {query
                    ? `No payees match "${query}".`
                    : 'No payees yet. Click "Add New Payee" to create one.'}
                </td>
              </tr>
            ) : (
              filtered.map((p) => (
                <tr key={p.id} onClick={() => openEdit(p)}>
                  <td className="px-name">{p.name}</td>
                  <td><TypeBadge type={p.type} /></td>
                  <td className="px-muted">{p.tin ? p.tin : '-'}</td>
                  <td className="px-muted px-contact">{p.contact_details ? p.contact_details : '-'}</td>
                  <td className="px-actions" onClick={(e) => e.stopPropagation()}>
                    <button type="button" className="px-ico" title="Edit" onClick={() => openEdit(p)}>
                      <Pencil size={15} />
                    </button>
                    <button
                      type="button"
                      className="px-ico px-ico-del"
                      title="Delete"
                      disabled={deletingId === p.id}
                      onClick={() => deletePayee(p)}
                    >
                      {deletingId === p.id ? <Loader2 size={15} className="px-spin" /> : <Trash2 size={15} />}
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {modalOpen && (
        <div className="px-modal-ov" onMouseDown={(e) => { if (e.target === e.currentTarget) setModalOpen(false) }}>
          <div className="px-modal" role="dialog" aria-modal="true">
            <div className="px-modal-head">
              <h2>{isEdit ? 'Edit Payee' : 'Add Payee'}</h2>
              <button type="button" className="px-x" onClick={() => setModalOpen(false)} aria-label="Close">
                <X size={20} />
              </button>
            </div>
            <form onSubmit={submitForm}>
              <div className="px-body">
                {formErr && (
                  <div className="px-form-err"><AlertCircle size={14} /> {formErr}</div>
                )}
                <div className="px-field">
                  <label>Name <span className="px-req">*</span></label>
                  <input
                    ref={nameRef}
                    type="text"
                    value={form.name}
                    onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                    placeholder="e.g. ABC Supplies Ltd"
                    required
                  />
                </div>
                <div className="px-field">
                  <label>Type</label>
                  <select
                    value={form.type}
                    onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}
                  >
                    {CFG.types.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                </div>
                <div className="px-field">
                  <label>TIN <span className="px-opt">(optional)</span></label>
                  <input
                    type="text"
                    value={form.tin}
                    onChange={(e) => setForm((f) => ({ ...f, tin: e.target.value }))}
                    placeholder="Tax Identification Number"
                  />
                </div>
                <div className="px-field">
                  <label>Contact Details</label>
                  <textarea
                    rows={2}
                    value={form.contact_details}
                    onChange={(e) => setForm((f) => ({ ...f, contact_details: e.target.value }))}
                    placeholder="Phone, email or address"
                  />
                </div>
              </div>
              <div className="px-foot">
                <button type="button" className="px-btn-cancel" onClick={() => setModalOpen(false)}>Cancel</button>
                <button type="submit" className="px-btn-primary" disabled={saving}>
                  {saving ? <Loader2 size={16} className="px-spin" /> : <CheckCircle2 size={16} />}
                  {saving ? 'Saving...' : (isEdit ? 'Update Payee' : 'Save Payee')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
