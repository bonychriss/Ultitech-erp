import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { AlertCircle, Loader2, Save, X } from 'lucide-react'
import { CFG } from '../config.js'
import UploadOverlay, { waitForOverlayPaint } from './UploadOverlay.jsx'

const EMPTY = {
  supplier_name: '',
  collection_address: '',
  goods_description: '',
  expected_quantity: '',
  driver_id: '',
  vehicle_id: '',
  collection_date: '',
}

/**
 * Goods Collection create form — reuses create-delivery API with request_kind=goods_collection.
 */
export default function CreateGoodsCollectionModal({ open, onClose }) {
  const bootData = CFG.data || {}
  const [data, setData] = useState(bootData)
  const [loading, setLoading] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState('')
  const [showErrors, setShowErrors] = useState(false)
  const panelRef = useRef(null)

  const drivers = data.drivers || []
  const urls = data.urls || {}

  useEffect(() => {
    if (!open) return undefined
    setForm({
      ...EMPTY,
      collection_date: new Date().toISOString().slice(0, 10),
      driver_id: bootData.currentUser?.id ? String(bootData.currentUser.id) : '',
    })
    setFormError('')
    setShowErrors(false)

    if (!CFG.createInitUrl) return undefined
    let alive = true
    setLoading(true)
    ;(async () => {
      try {
        const res = await fetch(CFG.createInitUrl, { headers: { Accept: 'application/json' } })
        const payload = await res.json()
        if (alive && payload?.ok && payload.data) {
          setData(payload.data)
          const list = payload.data.drivers || []
          const uid = payload.data.currentUser?.id
          setForm((prev) => {
            if (prev.driver_id) return prev
            const match = uid && list.some((d) => Number(d.id) === Number(uid))
            return {
              ...prev,
              driver_id: match ? String(uid) : (list[0] ? String(list[0].id) : ''),
            }
          })
        }
      } catch {
        if (alive) setFormError('Could not load form data.')
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => { alive = false }
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape' && !submitting) onClose()
    }
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    document.body.classList.add('dlv-create-open')
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
      document.body.classList.remove('dlv-create-open')
    }
  }, [open, submitting, onClose])

  function setField(key, value) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  function validate() {
    if (!form.supplier_name.trim()) return 'Please enter the supplier / source name.'
    if (!form.collection_address.trim()) return 'Please enter the collection address.'
    if (!form.goods_description.trim()) return 'Please describe the goods to collect.'
    if (!form.expected_quantity.trim() || Number(form.expected_quantity) <= 0) {
      return 'Please enter a valid expected quantity.'
    }
    if (!form.driver_id) return 'Please assign a driver.'
    if (!form.collection_date) return 'Please set the collection date.'
    return ''
  }

  async function handleSubmit(e) {
    e.preventDefault()
    if (submitting) return
    const err = validate()
    if (err) {
      setShowErrors(true)
      setFormError(err)
      return
    }
    setFormError('')
    setSubmitting(true)
    await waitForOverlayPaint()
    try {
      const fd = new FormData()
      fd.append('csrf_token', data.csrfToken || '')
      fd.append('request_kind', 'goods_collection')
      fd.append('client_name', form.supplier_name.trim())
      fd.append('pickup', form.collection_address.trim())
      fd.append('destination', 'Office / Store')
      fd.append('description', form.goods_description.trim())
      fd.append('expected_quantity', String(form.expected_quantity).trim())
      fd.append('driver_id', form.driver_id)
      fd.append('vehicle_id', form.vehicle_id.trim())
      fd.append('deadline', form.collection_date)

      const res = await fetch(CFG.createSubmitUrl, { method: 'POST', body: fd })
      const result = await res.json()
      if (!result?.ok) {
        setFormError(result?.error || 'Could not save collection task.')
        return
      }
      const orderId = result.data?.orderId
      const myUrl = urls.myDeliveries || data.urls?.myDeliveries
      if (myUrl && orderId) {
        const sep = myUrl.includes('?') ? '&' : '?'
        window.location.href = `${myUrl}${sep}highlight=${orderId}&created=1`
        return
      }
      onClose()
      window.location.reload()
    } catch {
      setFormError('Network error. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  if (!open) return null

  const fieldErr = (ok, msg) =>
    showErrors && !ok ? (
      <div className="cv-err"><AlertCircle size={12} /> {msg}</div>
    ) : null

  return createPortal(
    <div className="dlv-create-modal" role="dialog" aria-modal="true" aria-labelledby="dlv-collection-title">
      <button type="button" className="dlv-create-modal__backdrop" aria-label="Close" onClick={() => !submitting && onClose()} />
      <div className="dlv-create-modal__panel" ref={panelRef}>
        <UploadOverlay active={submitting} message="Saving collection task..." />
        <div className="dlv-create-modal__head">
          <h2 id="dlv-collection-title">New Goods Collection</h2>
          <button type="button" className="dlv-create-modal__close" onClick={() => !submitting && onClose()} aria-label="Close" disabled={submitting}>
            <X size={18} aria-hidden="true" />
          </button>
        </div>

        {loading ? (
          <div className="dlv-create-modal__loading" role="status">
            <Loader2 className="cv-spin" size={22} aria-hidden="true" />
            <span>Loading…</span>
          </div>
        ) : (
          <form className="dlv-create-modal__form" onSubmit={handleSubmit}>
            <div className="dlv-create-modal__body">
              {formError ? (
                <div className="cv-alert cv-alert--error" role="alert">
                  <AlertCircle size={16} aria-hidden="true" />
                  <div><strong>Could not save</strong><span>{formError}</span></div>
                </div>
              ) : null}

              <label className="dlv-create-field" style={{ marginBottom: '0.75rem' }}>
                <span>Supplier / source name <em>*</em></span>
                <input
                  className={`cv-input${!form.supplier_name.trim() && showErrors ? ' is-invalid' : ''}`}
                  value={form.supplier_name}
                  onChange={(e) => setField('supplier_name', e.target.value)}
                  placeholder="e.g. Maxesh Company Ltd"
                  disabled={submitting}
                />
                {fieldErr(!!form.supplier_name.trim(), 'Required')}
              </label>

              <label className="dlv-create-field" style={{ marginBottom: '0.75rem' }}>
                <span>Collection address <em>*</em></span>
                <textarea
                  className={`cv-input${!form.collection_address.trim() && showErrors ? ' is-invalid' : ''}`}
                  rows={2}
                  value={form.collection_address}
                  onChange={(e) => setField('collection_address', e.target.value)}
                  placeholder="Where goods will be collected from"
                  disabled={submitting}
                />
                {fieldErr(!!form.collection_address.trim(), 'Required')}
              </label>

              <label className="dlv-create-field" style={{ marginBottom: '0.75rem' }}>
                <span>Goods description <em>*</em></span>
                <textarea
                  className={`cv-input${!form.goods_description.trim() && showErrors ? ' is-invalid' : ''}`}
                  rows={2}
                  value={form.goods_description}
                  onChange={(e) => setField('goods_description', e.target.value)}
                  placeholder="What is being collected"
                  disabled={submitting}
                />
                {fieldErr(!!form.goods_description.trim(), 'Required')}
              </label>

              <div className="dlv-create-grid">
                <label className="dlv-create-field">
                  <span>Expected quantity <em>*</em></span>
                  <input
                    className={`cv-input${!(Number(form.expected_quantity) > 0) && showErrors ? ' is-invalid' : ''}`}
                    type="number"
                    min="0.01"
                    step="any"
                    value={form.expected_quantity}
                    onChange={(e) => setField('expected_quantity', e.target.value)}
                    placeholder="0"
                    disabled={submitting}
                  />
                  {fieldErr(Number(form.expected_quantity) > 0, 'Required')}
                </label>
                <label className="dlv-create-field">
                  <span>Collection date <em>*</em></span>
                  <input
                    className={`cv-input${!form.collection_date && showErrors ? ' is-invalid' : ''}`}
                    type="date"
                    value={form.collection_date}
                    onChange={(e) => setField('collection_date', e.target.value)}
                    disabled={submitting}
                  />
                  {fieldErr(!!form.collection_date, 'Required')}
                </label>
              </div>

              <div className="dlv-create-grid" style={{ marginTop: '0.75rem' }}>
                <label className="dlv-create-field">
                  <span>Assigned driver <em>*</em></span>
                  <select
                    className={`cv-input${!form.driver_id && showErrors ? ' is-invalid' : ''}`}
                    value={form.driver_id}
                    onChange={(e) => setField('driver_id', e.target.value)}
                    disabled={submitting}
                  >
                    <option value="">Select driver</option>
                    {drivers.map((d) => (
                      <option key={d.id} value={String(d.id)}>{d.full_name}</option>
                    ))}
                  </select>
                  {fieldErr(!!form.driver_id, 'Required')}
                </label>
                <label className="dlv-create-field">
                  <span>Vehicle</span>
                  <input
                    className="cv-input"
                    value={form.vehicle_id}
                    onChange={(e) => setField('vehicle_id', e.target.value)}
                    placeholder="Plate / vehicle ID"
                    disabled={submitting}
                  />
                </label>
              </div>

              <p className="dlv-task-chooser__hint">
                Destination is our office/store. After arrival, a storekeeper can confirm the actual received quantity.
                Stock receiving should still be done in procurement/stock to avoid duplicate stock entries.
              </p>
            </div>

            <div className="dlv-create-modal__actions">
              <button type="button" className="cv-btn-cancel" onClick={onClose} disabled={submitting}>Cancel</button>
              <button type="submit" className="cv-btn-save" disabled={submitting}>
                {submitting ? <Loader2 size={16} className="dlv-spin" aria-hidden="true" /> : <Save size={16} aria-hidden="true" />}
                Create collection
              </button>
            </div>
          </form>
        )}
      </div>
    </div>,
    document.body,
  )
}
