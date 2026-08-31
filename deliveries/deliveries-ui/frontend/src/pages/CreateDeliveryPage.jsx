import { useCallback, useEffect, useRef, useState } from 'react'
import {
  Loader2, CheckCircle2, AlertCircle, X, Check, Save, Receipt,
} from 'lucide-react'
import { CFG } from '../config.js'
import UploadOverlay, { waitForOverlayPaint } from '../components/UploadOverlay.jsx'

const SECTIONS = [
  { id: 'dlv-recipient', label: 'Recipient' },
  { id: 'dlv-details', label: 'Details' },
]

const EMPTY_FORM = {
  invoice_id: '',
  invoice_ref: '',
  client_name: '',
  client_phone: '',
  pickup: '',
  destination: '',
  route_cost: '',
  description: '',
}

function defaultDriverId(user) {
  return user?.id ? String(user.id) : ''
}

export default function CreateDeliveryPage() {
  const initial = CFG.data || {}
  const currentUser = initial.currentUser || {}

  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial.currentUser)
  const [form, setForm] = useState(EMPTY_FORM)
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState('')
  const [showErrors, setShowErrors] = useState(false)
  const [success, setSuccess] = useState(null)
  const [activeSection, setActiveSection] = useState(SECTIONS[0].id)
  const [receiptFile, setReceiptFile] = useState(null)
  const receiptRef = useRef(null)

  const urls = data.urls || {}
  const invoices = data.invoices || []
  const user = data.currentUser || currentUser
  const createDispatch = Boolean(CFG.createDispatch || data.createDispatch)

  useEffect(() => {
    if (initial.currentUser) return undefined
    if (!CFG.createInitUrl) {
      setLoading(false)
      return undefined
    }
    let alive = true
    ;(async () => {
      try {
        const res = await fetch(CFG.createInitUrl, { headers: { Accept: 'application/json' } })
        const payload = await res.json()
        if (alive && payload?.ok && payload.data) setData(payload.data)
      } catch {
        if (alive) setFormError('Could not load form data.')
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => { alive = false }
  }, [initial.currentUser])

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((e) => e.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio)
        if (visible[0]) setActiveSection(visible[0].target.id)
      },
      { rootMargin: '-40% 0px -50% 0px', threshold: [0, 0.25, 0.5, 1] },
    )
    SECTIONS.forEach((s) => {
      const el = document.getElementById(s.id)
      if (el) observer.observe(el)
    })
    return () => observer.disconnect()
  }, [loading, success])

  const setField = useCallback((key, value) => {
    setForm((f) => ({ ...f, [key]: value }))
  }, [])

  function onInvoiceChange(invoiceId) {
    if (!invoiceId) {
      setForm((f) => ({ ...f, invoice_id: '', invoice_ref: '' }))
      return
    }
    const inv = invoices.find((n) => String(n.id) === String(invoiceId))
    if (!inv) return
    setForm((f) => ({
      ...f,
      invoice_id: String(invoiceId),
      invoice_ref: inv.invoice_number || '',
      client_name: inv.customer_name || f.client_name,
      client_phone: inv.customer_phone || f.client_phone,
    }))
  }

  function scrollToSection(id) {
    const el = document.getElementById(id)
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const filled = {
    client_name: !!form.client_name.trim(),
    destination: !!form.destination.trim(),
  }

  const mark = (isFilled) =>
    isFilled
      ? <Check size={13} className="cv-ok" aria-label="filled" />
      : <span className="req" aria-label="required">*</span>
  const invCls = (ok) => (ok ? ' is-valid' : (showErrors ? ' is-invalid' : ''))
  const fieldErr = (ok, msg) =>
    showErrors && !ok ? (
      <div className="cv-err"><AlertCircle size={12} /> {msg}</div>
    ) : null

  function validate() {
    if (!filled.client_name) return 'Please enter the client name.'
    if (!filled.destination) return 'Please enter the destination address.'
    if (form.route_cost.trim() !== '' && (Number.isNaN(Number(form.route_cost)) || Number(form.route_cost) < 0)) {
      return 'Route cost must be a valid amount.'
    }
    return ''
  }

  async function handleSubmit(e) {
    e.preventDefault()
    if (submitting) return
    const err = validate()
    if (err) {
      setShowErrors(true)
      setFormError('Please complete the highlighted required fields.')
      setTimeout(() => {
        const el = document.querySelector('.is-invalid, .cv-err')
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
        else window.scrollTo({ top: 0, behavior: 'smooth' })
      }, 30)
      return
    }
    setFormError('')
    setSubmitting(true)
    await waitForOverlayPaint()
    try {
      const fd = new FormData()
      fd.append('csrf_token', data.csrfToken || '')
      Object.entries(form).forEach(([k, v]) => {
        if (v !== '' && v != null) fd.append(k, String(v))
      })
      const driverId = defaultDriverId(user)
      if (driverId) fd.append('driver_id', driverId)
      if (receiptFile) fd.append('receipt_file', receiptFile)

      const res = await fetch(CFG.createSubmitUrl, { method: 'POST', body: fd })
      const result = await res.json()
      if (!result?.ok) {
        setFormError(result?.error || 'Could not save delivery request.')
        return
      }
      const orderId = result.data?.orderId
      const dispatchNumber = result.data?.dispatchNumber
      const dispatchDashboard = urls.dispatchDashboard || data.urls?.dispatchDashboard
      if (createDispatch && dispatchDashboard) {
        const sep = dispatchDashboard.includes('?') ? '&' : '?'
        const created = dispatchNumber ? `&dispatch=${encodeURIComponent(dispatchNumber)}` : ''
        window.location.href = `${dispatchDashboard}${sep}created=1${created}`
        return
      }
      const myUrl = urls.myDeliveries || data.urls?.myDeliveries
      if (myUrl && orderId) {
        const sep = myUrl.includes('?') ? '&' : '?'
        window.location.href = `${myUrl}${sep}highlight=${orderId}&created=1`
        return
      }
      setForm(EMPTY_FORM)
      setShowErrors(false)
    } catch {
      setFormError('Network error. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <div className="cv-shell">
        <div className="cv-loading" role="status">
          <Loader2 className="cv-spin" aria-hidden="true" />
          <span>Loading form...</span>
        </div>
      </div>
    )
  }

  if (success) {
    return (
      <div className="cv-shell">
        <div className="cv-topbar">
          <div>
            <h1>New delivery</h1>
            <p>Your delivery has been created successfully.</p>
          </div>
        </div>
        <div className="cv-success-panel">
          <CheckCircle2 size={44} color="#10b981" aria-hidden="true" />
          <h2>{success.message || 'Delivery request sent!'}</h2>
          <div className="cv-success-links">
            <a href={urls.dashboard || 'index'}>&larr; Back to Dashboard</a>
            <a href={urls.createDelivery || 'create_delivery.php'}>Create Another Request</a>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="cv-shell">
      <UploadOverlay
        active={submitting}
        message={receiptFile ? 'Uploading receipt...' : 'Saving delivery...'}
        hint={receiptFile?.name || ''}
      />
      <div className="cv-topbar">
        <div>
          <h1>{createDispatch ? 'New dispatch' : 'New delivery'}</h1>
          {createDispatch && (
            <p>Submit without an invoice to create a delivery and dispatch note together.</p>
          )}
        </div>
      </div>

      {formError && (
        <div className="cv-alert cv-alert--error">
          <AlertCircle size={18} />
          <div><span>{formError}</span></div>
          <button type="button" className="cv-alert-x" onClick={() => setFormError('')} aria-label="Dismiss">
            <X size={16} />
          </button>
        </div>
      )}

      <form className="cv-layout" onSubmit={handleSubmit}>
        <nav className="cv-nav" aria-label="Form sections">
          {SECTIONS.map((s) => (
            <button
              key={s.id}
              type="button"
              className={`cv-nav-item${activeSection === s.id ? ' is-active' : ''}`}
              onClick={() => scrollToSection(s.id)}
            >
              {s.label}
            </button>
          ))}
        </nav>

        <div className="cv-main">
          {invoices.length > 0 && (
            <div className="cv-invoice-top">
              <div className="cv-row">
                <label className="cv-label" htmlFor="invoice_id">Select Invoice</label>
                <div className="cv-field">
                  <select
                    id="invoice_id"
                    className="cv-select"
                    value={form.invoice_id}
                    onChange={(e) => onInvoiceChange(e.target.value)}
                  >
                    <option value=""></option>
                    {invoices.map((inv) => (
                      <option key={inv.id} value={inv.id}>
                        {inv.invoice_number}{inv.customer_name ? ` - ${inv.customer_name}` : ''}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
            </div>
          )}

          <section id="dlv-recipient" className="cv-section">
            <header className="cv-section-head">
              <h2>Recipient &amp; Destination</h2>
              <p>Who you are delivering to, where the trip starts, and the drop-off location.</p>
            </header>

            <div className="cv-row">
              <label className="cv-label" htmlFor="client_name">Client Name {mark(filled.client_name)}</label>
              <div className="cv-field">
                <input
                  id="client_name"
                  className={`cv-input${invCls(filled.client_name)}`}
                  value={form.client_name}
                  onChange={(e) => setField('client_name', e.target.value)}
                  placeholder="Business or contact name"
                  required
                />
                {fieldErr(filled.client_name, 'Please enter the client name.')}
              </div>
            </div>

            <div className="cv-row">
              <label className="cv-label" htmlFor="client_phone">Client Phone</label>
              <div className="cv-field cv-field--narrow">
                <input
                  id="client_phone"
                  className="cv-input"
                  value={form.client_phone}
                  onChange={(e) => setField('client_phone', e.target.value)}
                  placeholder="e.g. 07xx xxx xxx"
                />
              </div>
            </div>

            <div className="cv-row cv-row--top">
              <label className="cv-label" htmlFor="pickup">From</label>
              <div className="cv-field">
                <input
                  id="pickup"
                  className="cv-input"
                  value={form.pickup}
                  onChange={(e) => setField('pickup', e.target.value)}
                  placeholder="Where the delivery starts (warehouse, shop, depot...)"
                />
              </div>
            </div>

            <div className="cv-row cv-row--top">
              <label className="cv-label" htmlFor="destination">To {mark(filled.destination)}</label>
              <div className="cv-field">
                <input
                  id="destination"
                  className={`cv-input${invCls(filled.destination)}`}
                  value={form.destination}
                  onChange={(e) => setField('destination', e.target.value)}
                  placeholder="Full address, site name, or area"
                  required
                />
                {fieldErr(filled.destination, 'Please enter the destination address.')}
              </div>
            </div>
            <div className="cv-row">
              <label className="cv-label" htmlFor="route_cost">Route cost</label>
              <div className="cv-field cv-field--narrow">
                <input
                  id="route_cost"
                  type="number"
                  min="0"
                  step="0.01"
                  className={`cv-input${invCls(form.route_cost.trim() === '' || (!Number.isNaN(Number(form.route_cost)) && Number(form.route_cost) >= 0))}`}
                  value={form.route_cost}
                  onChange={(e) => setField('route_cost', e.target.value)}
                  placeholder="0.00"
                />
                {fieldErr(
                  form.route_cost.trim() === '' || (!Number.isNaN(Number(form.route_cost)) && Number(form.route_cost) >= 0),
                  'Please enter a valid route cost.',
                )}
              </div>
            </div>
          </section>

          <section id="dlv-details" className="cv-section">
            <header className="cv-section-head">
              <h2>Package Details</h2>
              <p>Describe what you are delivering (optional).</p>
            </header>

            <div className="cv-row cv-row--top">
              <label className="cv-label" htmlFor="description">Description</label>
              <div className="cv-field">
                <textarea
                  id="description"
                  className="cv-textarea"
                  rows={3}
                  value={form.description}
                  onChange={(e) => setField('description', e.target.value)}
                  placeholder="Describe the items clearly..."
                />
              </div>
            </div>

            <div className="cv-row cv-row--top">
              <span className="cv-label">Attach receipt</span>
              <div className="cv-field">
                <label className={`cv-file${submitting && receiptFile ? ' cv-file--uploading' : ''}`}>
                  <input
                    ref={receiptRef}
                    type="file"
                    name="receipt_file"
                    accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,image/*"
                    onChange={(e) => setReceiptFile(e.target.files?.[0] || null)}
                  />
                  <Receipt size={22} className="cv-file-icon" aria-hidden="true" />
                  <span className="cv-file-title">Upload receipt</span>
                  <span className="cv-file-sub">
                    {receiptFile ? receiptFile.name : 'PDF, image, or document (optional)'}
                  </span>
                </label>
                {receiptFile && (
                  <button
                    type="button"
                    className="cv-btn-ghost"
                    style={{ marginTop: 8 }}
                    onClick={() => {
                      setReceiptFile(null)
                      if (receiptRef.current) receiptRef.current.value = ''
                    }}
                  >
                    <X size={14} aria-hidden="true" /> Remove receipt
                  </button>
                )}
              </div>
            </div>
          </section>

          <div className="cv-actions">
            <a
              href={createDispatch ? (urls.dispatchDashboard || urls.dashboard || 'index') : (urls.dashboard || 'index')}
              className="cv-btn-cancel"
            >
              Cancel
            </a>
            <button type="submit" className="cv-btn-save" disabled={submitting}>
              {submitting ? <Loader2 size={16} className="cv-spin" /> : <Save size={16} />}
              {submitting ? 'Submitting...' : (createDispatch ? 'Save Dispatch' : 'Record Delivery')}
            </button>
          </div>
        </div>
      </form>
    </div>
  )
}
