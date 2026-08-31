import { useCallback, useEffect, useRef, useState } from 'react'
import {
  Loader2, AlertCircle, X, Check, Save, Plus, ShoppingCart, ImageIcon,
} from 'lucide-react'
import { CFG } from '../config.js'
import UploadOverlay, { waitForOverlayPaint } from '../components/UploadOverlay.jsx'

const SECTIONS = [
  { id: 'dn-customer', label: 'Customer' },
  { id: 'dn-items', label: 'Items' },
]

const EMPTY_FORM = {
  customer_id: '',
  customer_name: '',
  delivery_date: '',
  delivery_address: '',
  customer_phone: '',
}

function newItemRow(seed = {}) {
  return {
    key: `row-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    product_id: seed.product_id ? String(seed.product_id) : '',
    sku: seed.sku || '',
    description: seed.description || '',
    qty: seed.quantity ? String(seed.quantity) : '',
    unit: seed.unit || 'pcs',
    image_url: seed.image_url || '',
  }
}

function catalogueRowFromProduct(product, qty = '') {
  return newItemRow({
    product_id: product?.id,
    sku: product?.product_code || '',
    description: product?.name || '',
    quantity: qty,
    unit: 'pcs',
    image_url: product?.image_url || '',
  })
}

export default function CreateDeliveryNotePage() {
  const initial = CFG.data || {}
  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial.defaultDate)
  const [form, setForm] = useState({
    ...EMPTY_FORM,
    delivery_date: initial.defaultDate || new Date().toISOString().slice(0, 10),
  })
  const [items, setItems] = useState([newItemRow()])
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState('')
  const [showErrors, setShowErrors] = useState(false)
  const [activeSection, setActiveSection] = useState(SECTIONS[0].id)
  const [openDropdown, setOpenDropdown] = useState(null)

  const urls = data.urls || {}
  const customers = data.customers || []
  const products = data.products || []

  useEffect(() => {
    if (initial.defaultDate) return undefined
    if (!CFG.createNoteInitUrl) {
      setLoading(false)
      return undefined
    }
    let alive = true
    ;(async () => {
      try {
        const res = await fetch(CFG.createNoteInitUrl, { headers: { Accept: 'application/json' } })
        const payload = await res.json()
        if (alive && payload?.ok && payload.data) {
          setData(payload.data)
          if (payload.data.defaultDate) {
            setForm((f) => ({ ...f, delivery_date: payload.data.defaultDate }))
          }
        }
      } catch {
        if (alive) setFormError('Could not load form data.')
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => { alive = false }
  }, [initial.defaultDate])

  useEffect(() => {
    try {
      const raw = localStorage.getItem('sales_catalogue_items')
      if (!raw) return
      const picked = JSON.parse(raw)
      if (!Array.isArray(picked) || picked.length === 0) return
      const rows = picked.map((item) => {
        const productId = item.product_id ? String(item.product_id) : ''
        const product = productId ? products.find((p) => String(p.id) === productId) : null
        if (product) return catalogueRowFromProduct(product, item.quantity ? String(item.quantity) : '')
        return newItemRow({
          product_id: item.product_id,
          description: item.description || item.name || '',
          quantity: item.quantity,
          sku: item.sku || item.product_code || '',
        })
      })
      setItems(rows)
      localStorage.removeItem('sales_catalogue_items')
    } catch {
      // ignore malformed localStorage
    }
  }, [products])

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
  }, [loading])

  useEffect(() => {
    function onDocClick(e) {
      if (!e.target.closest('.cdn-product-wrap')) setOpenDropdown(null)
    }
    document.addEventListener('click', onDocClick)
    return () => document.removeEventListener('click', onDocClick)
  }, [])

  const setField = useCallback((key, value) => {
    setForm((f) => ({ ...f, [key]: value }))
  }, [])

  function scrollToSection(id) {
    const el = document.getElementById(id)
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  function onCustomerChange(customerId) {
    if (!customerId) {
      setForm((f) => ({ ...f, customer_id: '' }))
      return
    }
    const cust = customers.find((c) => String(c.id) === String(customerId))
    if (!cust) return
    const fullAddress = [cust.address, cust.city, cust.country].filter(Boolean).join(', ')
    const contactBits = [cust.contact_person, cust.phone].filter(Boolean)
    setForm((f) => ({
      ...f,
      customer_id: String(customerId),
      customer_name: cust.customer_name || f.customer_name,
      delivery_address: fullAddress || f.delivery_address,
      customer_phone: contactBits.length ? contactBits.join(' | ') : f.customer_phone,
    }))
  }

  function updateItem(key, patch) {
    setItems((rows) => rows.map((row) => (row.key === key ? { ...row, ...patch } : row)))
  }

  function removeItem(key) {
    setItems((rows) => {
      const next = rows.filter((row) => row.key !== key)
      return next.length ? next : [newItemRow()]
    })
  }

  function addItemRow() {
    setItems((rows) => [...rows, newItemRow()])
  }

  function productMatches(query) {
    const val = query.trim().toLowerCase()
    if (!val) return products.slice(0, 20)
    return products.filter((p) => {
      const name = (p.name || '').toLowerCase()
      const code = (p.product_code || '').toLowerCase()
      return name.includes(val) || code.includes(val)
    }).slice(0, 20)
  }

  function selectProduct(rowKey, product) {
    updateItem(rowKey, {
      product_id: String(product.id),
      sku: product.product_code || '',
      description: product.name || '',
      unit: 'pcs',
      image_url: product.image_url || '',
    })
    setOpenDropdown(null)
  }

  const filled = {
    customer_name: !!form.customer_name.trim(),
    delivery_date: !!form.delivery_date.trim(),
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
    if (!filled.customer_name) return 'Please enter the customer name.'
    if (!filled.delivery_date) return 'Please select a date.'
    const validItems = items.filter((row) => row.description.trim() && Number(row.qty) > 0)
    if (!validItems.length) return 'Please add at least one item with quantity.'
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
      const payload = {
        csrf_token: data.csrfToken || '',
        customer_name: form.customer_name.trim(),
        delivery_address: form.delivery_address.trim(),
        customer_phone: form.customer_phone.trim(),
        delivery_date: form.delivery_date,
        items: items
          .filter((row) => row.description.trim())
          .map((row) => ({
            product_id: row.product_id || null,
            sku: row.sku,
            description: row.description.trim(),
            qty: row.qty,
            unit: row.unit,
          })),
      }
      const res = await fetch(CFG.createNoteSubmitUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      })
      const result = await res.json()
      if (!result?.ok) {
        setFormError(result?.error || 'Could not save delivery note.')
        return
      }
      const redirect = result.data?.redirectUrl || urls.deliveryNotes
      if (redirect) {
        window.location.href = redirect
        return
      }
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

  return (
    <div className="cv-shell">
      <UploadOverlay active={submitting} message="Saving delivery note..." />
      <div className="cv-topbar cv-topbar--split">
        <div>
          <h1>Create Delivery Note</h1>
        </div>
        <a href={urls.deliveryNotes || 'delivery_notes.php'} className="cv-link-back">Back to List</a>
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
          <section id="dn-customer" className="cv-section">
            <header className="cv-section-head">
              <h2>Customer Details</h2>
              <p>Basic recipient details for the delivery note.</p>
            </header>

            {customers.length > 0 && (
              <div className="cv-row">
                <label className="cv-label" htmlFor="customer_id">Select Customer</label>
                <div className="cv-field">
                  <select
                    id="customer_id"
                    className="cv-select"
                    value={form.customer_id}
                    onChange={(e) => onCustomerChange(e.target.value)}
                  >
                    <option value="">-- Select from system customers --</option>
                    {customers.map((cust) => (
                      <option key={cust.id} value={cust.id}>{cust.customer_name}</option>
                    ))}
                  </select>
                  <span className="cv-hint">Optional: pick a customer to auto-fill recipient details.</span>
                </div>
              </div>
            )}

            <div className="cv-row">
              <label className="cv-label" htmlFor="customer_name">Customer Name {mark(filled.customer_name)}</label>
              <div className="cv-field">
                <input
                  id="customer_name"
                  className={`cv-input${invCls(filled.customer_name)}`}
                  value={form.customer_name}
                  onChange={(e) => setField('customer_name', e.target.value)}
                  placeholder="Enter customer name"
                  required
                />
                {fieldErr(filled.customer_name, 'Please enter the customer name.')}
              </div>
            </div>

            <div className="cv-row">
              <label className="cv-label" htmlFor="delivery_date">Date {mark(filled.delivery_date)}</label>
              <div className="cv-field cv-field--narrow">
                <input
                  id="delivery_date"
                  type="date"
                  className={`cv-input${invCls(filled.delivery_date)}`}
                  value={form.delivery_date}
                  onChange={(e) => setField('delivery_date', e.target.value)}
                  required
                />
                {fieldErr(filled.delivery_date, 'Please select a date.')}
              </div>
            </div>

            <div className="cv-row cv-row--top">
              <label className="cv-label" htmlFor="delivery_address">To</label>
              <div className="cv-field">
                <textarea
                  id="delivery_address"
                  className="cv-textarea"
                  rows={2}
                  value={form.delivery_address}
                  onChange={(e) => setField('delivery_address', e.target.value)}
                  placeholder="Enter delivery address (optional)"
                />
              </div>
            </div>

            <div className="cv-row cv-row--top">
              <label className="cv-label" htmlFor="customer_phone">Contact Details</label>
              <div className="cv-field">
                <textarea
                  id="customer_phone"
                  className="cv-textarea"
                  rows={2}
                  value={form.customer_phone}
                  onChange={(e) => setField('customer_phone', e.target.value)}
                  placeholder="e.g. +255 700 000 000"
                />
              </div>
            </div>
          </section>

          <section id="dn-items" className="cv-section">
            <header className="cv-section-head">
              <h2>Items</h2>
              <p>Search catalogue products and enter delivery quantities.</p>
              {urls.catalogue && (
                <a href={urls.catalogue} className="cdn-catalogue-link">
                  <ShoppingCart size={14} aria-hidden="true" /> Browse Catalogue
                </a>
              )}
            </header>

            <div className="cdn-items-head" aria-hidden="true">
              <span className="cdn-col-img" />
              <span className="cdn-col-sku">SKU / Code</span>
              <span className="cdn-col-desc">Product</span>
              <span className="cdn-col-qty">Qty</span>
              <span className="cdn-col-unit">Unit</span>
              <span className="cdn-col-action" />
            </div>

            <div className="cdn-items-list">
              {items.map((row) => {
                const matches = openDropdown === row.key ? productMatches(row.description) : []
                return (
                  <div key={row.key} className="cdn-item-row">
                    <div className="cdn-col-img">
                      <span className="cdn-mobile-label">Image</span>
                      <div className="cdn-img-box">
                        {row.image_url ? (
                          <img src={row.image_url} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
                        ) : (
                          <ImageIcon size={18} className="cdn-img-placeholder" aria-hidden="true" />
                        )}
                      </div>
                    </div>
                    <div className="cdn-col-sku">
                      <span className="cdn-mobile-label">SKU / Code</span>
                      <input className="cv-input" value={row.sku} readOnly placeholder="SKU" />
                    </div>
                    <div className="cdn-col-desc cdn-product-wrap">
                      <span className="cdn-mobile-label">Product</span>
                      <input
                        className="cv-input"
                        value={row.description}
                        placeholder="Search product..."
                        required
                        onChange={(e) => {
                          updateItem(row.key, { description: e.target.value, product_id: '', image_url: '' })
                          setOpenDropdown(row.key)
                        }}
                        onFocus={() => setOpenDropdown(row.key)}
                      />
                      {openDropdown === row.key && matches.length > 0 && (
                        <div className="cdn-dropdown">
                          {matches.map((product) => (
                            <button
                              key={product.id}
                              type="button"
                              className="cdn-dropdown-item"
                              onClick={() => selectProduct(row.key, product)}
                            >
                              {product.image_url ? (
                                <img src={product.image_url} alt="" className="cdn-dropdown-img" />
                              ) : (
                                <span className="cdn-dropdown-img cdn-dropdown-img--empty" />
                              )}
                              <span className="cdn-dropdown-text">{product.name}</span>
                              <span className="cdn-dropdown-meta">{product.product_code}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                    <div className="cdn-col-qty">
                      <span className="cdn-mobile-label">Quantity</span>
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        className="cv-input"
                        value={row.qty}
                        onChange={(e) => updateItem(row.key, { qty: e.target.value })}
                        placeholder="0.00"
                        required
                      />
                    </div>
                    <div className="cdn-col-unit">
                      <span className="cdn-mobile-label">Unit</span>
                      <input
                        className="cv-input"
                        value={row.unit}
                        onChange={(e) => updateItem(row.key, { unit: e.target.value })}
                        placeholder="Unit"
                      />
                    </div>
                    <div className="cdn-col-action">
                      <button type="button" className="cdn-remove" onClick={() => removeItem(row.key)} aria-label="Remove row">
                        <X size={18} />
                      </button>
                    </div>
                  </div>
                )
              })}
            </div>

            <button type="button" className="cdn-add-row" onClick={addItemRow}>
              <Plus size={14} aria-hidden="true" /> Add Item Row
            </button>
          </section>

          <div className="cv-actions">
            <a href={urls.deliveryNotes || 'delivery_notes.php'} className="cv-btn-cancel">Cancel</a>
            <button type="submit" className="cv-btn-save" disabled={submitting}>
              {submitting ? <Loader2 size={16} className="cv-spin" /> : <Save size={16} />}
              {submitting ? 'Saving...' : 'Generate Note'}
            </button>
          </div>
        </div>
      </form>
    </div>
  )
}
