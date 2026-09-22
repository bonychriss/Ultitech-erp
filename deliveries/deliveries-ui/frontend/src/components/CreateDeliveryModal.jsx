import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  Loader2, AlertCircle, X, Save, Receipt, ChevronDown,
} from 'lucide-react'
import { CFG } from '../config.js'
import UploadOverlay, { waitForOverlayPaint } from '../components/UploadOverlay.jsx'

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

/**
 * Compact single-popup create-delivery form.
 * @param {{ open: boolean, onClose: () => void, createDispatch?: boolean }} props
 */
export default function CreateDeliveryModal({ open, onClose, createDispatch: createDispatchProp = false }) {
  const bootData = CFG.data || {}
  const [data, setData] = useState(bootData)
  const [loading, setLoading] = useState(false)
  const [requestKind, setRequestKind] = useState('delivery')
  const [form, setForm] = useState(EMPTY_FORM)
  const [employeeIds, setEmployeeIds] = useState([])
  const [employeeQuery, setEmployeeQuery] = useState('')
  const [employeesOpen, setEmployeesOpen] = useState(false)
  const [invoiceOpen, setInvoiceOpen] = useState(false)
  const [invoiceQuery, setInvoiceQuery] = useState('')
  const [sheetMode, setSheetMode] = useState(() => (
    typeof window !== 'undefined' ? window.matchMedia('(max-width: 900px)').matches : true
  ))
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState('')
  const [showErrors, setShowErrors] = useState(false)
  const [receiptFile, setReceiptFile] = useState(null)
  const receiptRef = useRef(null)
  const panelRef = useRef(null)
  const employeesRef = useRef(null)
  const invoiceRef = useRef(null)

  const urls = data.urls || {}
  const invoices = data.invoices || []
  const employees = data.employees || []
  const user = data.currentUser || bootData.currentUser || {}
  const createDispatch = Boolean(createDispatchProp || CFG.createDispatch || data.createDispatch)
  const isClientVisit = requestKind === 'client_visit'

  useEffect(() => {
    if (!open) return undefined
    setRequestKind('delivery')
    setForm(EMPTY_FORM)
    setEmployeeIds([])
    setEmployeeQuery('')
    setEmployeesOpen(false)
    setInvoiceOpen(false)
    setInvoiceQuery('')
    setFormError('')
    setShowErrors(false)
    setReceiptFile(null)
    if (receiptRef.current) receiptRef.current.value = ''

    const hasBoot = Boolean(data.currentUser || bootData.currentUser)
    const needsEmployees = !(Array.isArray(data.employees) && data.employees.length > 0)
      && !(Array.isArray(bootData.employees) && bootData.employees.length > 0)
    if (hasBoot && !needsEmployees) return undefined
    if (!CFG.createInitUrl) return undefined

    let alive = true
    setLoading(true)
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
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape' && !submitting) {
        if (invoiceOpen) {
          setInvoiceOpen(false)
          return
        }
        if (employeesOpen) {
          setEmployeesOpen(false)
          return
        }
        onClose()
      }
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
  }, [open, submitting, onClose, employeesOpen, invoiceOpen])

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 900px)')
    const sync = () => setSheetMode(mq.matches)
    sync()
    mq.addEventListener('change', sync)
    return () => mq.removeEventListener('change', sync)
  }, [])

  useEffect(() => {
    if (!employeesOpen || sheetMode) return undefined
    const onPointerDown = (e) => {
      if (employeesRef.current && !employeesRef.current.contains(e.target)) {
        setEmployeesOpen(false)
      }
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('touchstart', onPointerDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('touchstart', onPointerDown)
    }
  }, [employeesOpen, sheetMode])

  useEffect(() => {
    if (!invoiceOpen || sheetMode) return undefined
    const onPointerDown = (e) => {
      if (invoiceRef.current && !invoiceRef.current.contains(e.target)) {
        setInvoiceOpen(false)
      }
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('touchstart', onPointerDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('touchstart', onPointerDown)
    }
  }, [invoiceOpen, sheetMode])

  useEffect(() => {
    if (!isClientVisit) setEmployeesOpen(false)
  }, [isClientVisit])

  useEffect(() => {
    if (isClientVisit) setInvoiceOpen(false)
  }, [isClientVisit])

  const setField = useCallback((key, value) => {
    setForm((f) => ({ ...f, [key]: value }))
  }, [])

  function toggleEmployee(id) {
    const key = String(id)
    setEmployeeIds((prev) => (
      prev.includes(key) ? prev.filter((x) => x !== key) : [...prev, key]
    ))
  }

  function onInvoiceChange(invoiceId) {
    if (!invoiceId) {
      setForm((f) => ({ ...f, invoice_id: '', invoice_ref: '' }))
      setInvoiceOpen(false)
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
      destination: inv.customer_address || f.destination,
    }))
    setInvoiceOpen(false)
  }

  const filled = {
    employees: employeeIds.length > 0,
    client_name: !!form.client_name.trim(),
    destination: !!form.destination.trim(),
  }

  const invCls = (ok) => (ok ? ' is-valid' : (showErrors ? ' is-invalid' : ''))
  const fieldErr = (ok, msg) =>
    showErrors && !ok ? (
      <div className="cv-err"><AlertCircle size={12} /> {msg}</div>
    ) : null

  function validate() {
    if (isClientVisit && !filled.employees) {
      return 'Please select at least one employee who asked for this visit.'
    }
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
      setFormError(err)
      return
    }
    setFormError('')
    setSubmitting(true)
    await waitForOverlayPaint()
    try {
      const fd = new FormData()
      fd.append('csrf_token', data.csrfToken || '')
      fd.append('request_kind', requestKind)
      Object.entries(form).forEach(([k, v]) => {
        if (isClientVisit && (k === 'invoice_id' || k === 'invoice_ref')) return
        if (v !== '' && v != null) fd.append(k, String(v))
      })
      if (isClientVisit) {
        employeeIds.forEach((id) => fd.append('employee_ids[]', id))
      }
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
      onClose()
      window.location.reload()
    } catch {
      setFormError('Network error. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  if (!open) return null

  const title = createDispatch
    ? 'New dispatch'
    : (isClientVisit ? 'Client visit' : 'New delivery')
  const submitLabel = createDispatch
    ? 'Save Dispatch'
    : (isClientVisit ? 'Record Visit' : 'Record Delivery')
  const overlayMessage = receiptFile
    ? 'Uploading receipt...'
    : (isClientVisit ? 'Saving client visit...' : 'Saving delivery...')

  const q = employeeQuery.trim().toLowerCase()
  const filteredEmployees = !q
    ? employees
    : employees.filter((emp) => {
      const name = String(emp.full_name || '').toLowerCase()
      const dept = String(emp.department || '').toLowerCase()
      return name.includes(q) || dept.includes(q)
    })
  const selectedEmployees = employees.filter((emp) => employeeIds.includes(String(emp.id)))

  const iq = invoiceQuery.trim().toLowerCase()
  const filteredInvoices = !iq
    ? invoices
    : invoices.filter((inv) => {
      const num = String(inv.invoice_number || '').toLowerCase()
      const name = String(inv.customer_name || '').toLowerCase()
      return num.includes(iq) || name.includes(iq)
    })
  const selectedInvoice = invoices.find((inv) => String(inv.id) === String(form.invoice_id))
  const selectedInvoiceLabel = selectedInvoice
    ? `${selectedInvoice.invoice_number || 'Invoice'}${selectedInvoice.customer_name ? ` - ${selectedInvoice.customer_name}` : ''}`
    : ''

  const modal = (
    <div
      className={`dlv-create-modal${sheetMode ? ' dlv-create-modal--sheet' : ''}`}
      role="dialog"
      aria-modal="true"
      aria-labelledby="dlv-create-modal-title"
    >
      <button type="button" className="dlv-create-modal__backdrop" aria-label="Close" onClick={() => !submitting && onClose()} />
      <div className="dlv-create-modal__panel" ref={panelRef}>
        <UploadOverlay
          active={submitting}
          message={overlayMessage}
          hint={receiptFile?.name || ''}
        />

        <div className="dlv-create-modal__handle" aria-hidden="true" />
        <div className="dlv-create-modal__head">
          <h2 id="dlv-create-modal-title">{title}</h2>
          <button
            type="button"
            className="dlv-create-modal__close"
            onClick={() => !submitting && onClose()}
            aria-label="Close"
            disabled={submitting}
          >
            <X size={18} aria-hidden="true" />
          </button>
        </div>

        {!createDispatch ? (
          <div className="dlv-create-kind" role="radiogroup" aria-label="Request type">
            <label className={`dlv-create-kind__option${!isClientVisit ? ' is-checked' : ''}`}>
              <input
                type="radio"
                name="dlv_request_kind"
                value="delivery"
                checked={!isClientVisit}
                onChange={() => setRequestKind('delivery')}
                disabled={submitting}
              />
              <span className="dlv-create-kind__mark" aria-hidden="true" />
              <span className="dlv-create-kind__label">Delivery</span>
            </label>
            <label className={`dlv-create-kind__option${isClientVisit ? ' is-checked' : ''}`}>
              <input
                type="radio"
                name="dlv_request_kind"
                value="client_visit"
                checked={isClientVisit}
                onChange={() => setRequestKind('client_visit')}
                disabled={submitting}
              />
              <span className="dlv-create-kind__mark" aria-hidden="true" />
              <span className="dlv-create-kind__label">Client visit</span>
            </label>
          </div>
        ) : null}

        {loading ? (
          <div className="dlv-create-modal__loading" role="status">
            <Loader2 className="cv-spin" size={22} aria-hidden="true" />
            <span>Loading form...</span>
          </div>
        ) : (
          <form className="dlv-create-modal__form" onSubmit={handleSubmit}>
            <div className="dlv-create-modal__body">
            {isClientVisit ? (
              <p className="dlv-create-kind__hint">
                Record employees who asked you to drive them to visit their clients.
              </p>
            ) : null}

            {formError ? (
              <div className="cv-alert cv-alert--error">
                <AlertCircle size={16} aria-hidden="true" />
                <div><span>{formError}</span></div>
                <button type="button" className="cv-alert-x" onClick={() => setFormError('')} aria-label="Dismiss">
                  <X size={14} />
                </button>
              </div>
            ) : null}

            {isClientVisit ? (
              <>
                <div
                  className={`dlv-create-employees${showErrors && !filled.employees ? ' is-invalid' : ''}${employeesOpen ? ' is-open' : ''}`}
                  ref={employeesRef}
                >
                  <span className="dlv-create-employees__label">Employee(s) <em>*</em></span>
                  <button
                    type="button"
                    className={`dlv-create-employees__trigger${showErrors && !filled.employees ? ' is-invalid' : ''}`}
                    onClick={() => {
                      if (submitting) return
                      setEmployeesOpen((v) => !v)
                      setInvoiceOpen(false)
                    }}
                    aria-expanded={employeesOpen}
                    aria-haspopup="listbox"
                    disabled={submitting}
                  >
                    <span className={`dlv-create-employees__value${selectedEmployees.length === 0 ? ' is-placeholder' : ''}`}>
                      {selectedEmployees.length === 0
                        ? 'Who asked for the visit'
                        : selectedEmployees.map((emp) => emp.full_name || `User #${emp.id}`).join(', ')}
                    </span>
                    <ChevronDown size={16} className="dlv-create-employees__chevron" aria-hidden="true" />
                  </button>
                  {employeesOpen ? (sheetMode
                    ? createPortal(
                      <>
                        <button
                          type="button"
                          className="dlv-create-picker__scrim"
                          aria-label="Close employee list"
                          onClick={() => setEmployeesOpen(false)}
                        />
                        <div className="dlv-create-employees__dropdown dlv-create-picker__sheet--footer" role="listbox" aria-label="Employees">
                          <div className="dlv-create-picker__handle" aria-hidden="true" />
                          <div className="dlv-create-picker__sheet-head">
                            <strong>Select employee(s)</strong>
                            <button
                              type="button"
                              className="dlv-create-picker__sheet-close"
                              onClick={() => setEmployeesOpen(false)}
                              aria-label="Close"
                            >
                              <X size={16} aria-hidden="true" />
                            </button>
                          </div>
                          <input
                            className="cv-input dlv-create-employees__search"
                            type="search"
                            value={employeeQuery}
                            onChange={(e) => setEmployeeQuery(e.target.value)}
                            placeholder="Search employee name..."
                            disabled={submitting}
                            autoFocus
                          />
                          <div className="dlv-create-employees__list">
                            {filteredEmployees.length === 0 ? (
                              <div className="dlv-create-employees__empty">No employees found.</div>
                            ) : (
                              filteredEmployees.map((emp) => {
                                const id = String(emp.id)
                                const checked = employeeIds.includes(id)
                                return (
                                  <label key={emp.id} className={`dlv-create-employees__row${checked ? ' is-checked' : ''}`}>
                                    <input
                                      type="checkbox"
                                      checked={checked}
                                      onChange={() => toggleEmployee(emp.id)}
                                      disabled={submitting}
                                    />
                                    <span className="dlv-create-employees__check" aria-hidden="true" />
                                    <span className="dlv-create-employees__name">{emp.full_name || `User #${emp.id}`}</span>
                                    {emp.department ? (
                                      <span className="dlv-create-employees__dept">{emp.department}</span>
                                    ) : null}
                                  </label>
                                )
                              })
                            )}
                          </div>
                          <div className="dlv-create-picker__sheet-actions">
                            <button
                              type="button"
                              className="cv-btn-save"
                              onClick={() => setEmployeesOpen(false)}
                            >
                              Done
                            </button>
                          </div>
                        </div>
                      </>,
                      document.body,
                    )
                    : (
                      <div className="dlv-create-employees__dropdown" role="listbox" aria-label="Employees">
                        <input
                          className="cv-input dlv-create-employees__search"
                          type="search"
                          value={employeeQuery}
                          onChange={(e) => setEmployeeQuery(e.target.value)}
                          placeholder="Search employee name..."
                          disabled={submitting}
                          autoFocus
                        />
                        <div className="dlv-create-employees__list">
                          {filteredEmployees.length === 0 ? (
                            <div className="dlv-create-employees__empty">No employees found.</div>
                          ) : (
                            filteredEmployees.map((emp) => {
                              const id = String(emp.id)
                              const checked = employeeIds.includes(id)
                              return (
                                <label key={emp.id} className={`dlv-create-employees__row${checked ? ' is-checked' : ''}`}>
                                  <input
                                    type="checkbox"
                                    checked={checked}
                                    onChange={() => toggleEmployee(emp.id)}
                                    disabled={submitting}
                                  />
                                  <span className="dlv-create-employees__check" aria-hidden="true" />
                                  <span className="dlv-create-employees__name">{emp.full_name || `User #${emp.id}`}</span>
                                  {emp.department ? (
                                    <span className="dlv-create-employees__dept">{emp.department}</span>
                                  ) : null}
                                </label>
                              )
                            })
                          )}
                        </div>
                      </div>
                    )
                  ) : null}
                  {fieldErr(filled.employees, 'Select at least one employee')}
                </div>

                <div className="dlv-create-grid">
                  <label className="dlv-create-field">
                    <span>Client Name <em>*</em></span>
                    <input
                      className={`cv-input${invCls(filled.client_name)}`}
                      value={form.client_name}
                      onChange={(e) => setField('client_name', e.target.value)}
                      placeholder="Client visited"
                      required
                      autoFocus
                    />
                    {fieldErr(filled.client_name, 'Required')}
                  </label>
                  <label className="dlv-create-field">
                    <span>Client Phone</span>
                    <input
                      className="cv-input"
                      value={form.client_phone}
                      onChange={(e) => setField('client_phone', e.target.value)}
                      placeholder="e.g. 07xx xxx xxx"
                    />
                  </label>
                </div>

                <div className="dlv-create-grid">
                  <label className="dlv-create-field">
                    <span>From</span>
                    <input
                      className="cv-input"
                      value={form.pickup}
                      onChange={(e) => setField('pickup', e.target.value)}
                      placeholder="Office, depot..."
                    />
                  </label>
                  <label className="dlv-create-field">
                    <span>To <em>*</em></span>
                    <input
                      className={`cv-input${invCls(filled.destination)}`}
                      value={form.destination}
                      onChange={(e) => setField('destination', e.target.value)}
                      placeholder="Client address or area"
                      required
                    />
                    {fieldErr(filled.destination, 'Required')}
                  </label>
                </div>

                <div className="dlv-create-grid">
                  <label className="dlv-create-field">
                    <span>Route cost</span>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      className="cv-input"
                      value={form.route_cost}
                      onChange={(e) => setField('route_cost', e.target.value)}
                      placeholder="0.00"
                    />
                  </label>
                  <label className="dlv-create-field">
                    <span>Visit notes</span>
                    <input
                      className="cv-input"
                      value={form.description}
                      onChange={(e) => setField('description', e.target.value)}
                      placeholder="Optional notes"
                    />
                  </label>
                </div>
              </>
            ) : (
              <>
                {invoices.length > 0 ? (
                  <div
                    className={`dlv-create-picker${invoiceOpen ? ' is-open' : ''}`}
                    ref={invoiceRef}
                  >
                    <span className="dlv-create-picker__label">Select Invoice</span>
                    <button
                      type="button"
                      className="dlv-create-picker__trigger"
                      onClick={() => {
                        if (submitting) return
                        setInvoiceOpen((v) => !v)
                        setEmployeesOpen(false)
                      }}
                      aria-expanded={invoiceOpen}
                      aria-haspopup="listbox"
                      disabled={submitting}
                    >
                      <span className={`dlv-create-picker__value${!selectedInvoice ? ' is-placeholder' : ''}`}>
                        {selectedInvoiceLabel || 'Optional - pick to autofill client'}
                      </span>
                      <ChevronDown size={16} className="dlv-create-picker__chevron" aria-hidden="true" />
                    </button>
                    {invoiceOpen ? (sheetMode
                      ? createPortal(
                        <>
                          <button
                            type="button"
                            className="dlv-create-picker__scrim"
                            aria-label="Close invoice list"
                            onClick={() => setInvoiceOpen(false)}
                          />
                          <div className="dlv-create-picker__sheet dlv-create-picker__sheet--footer" role="listbox" aria-label="Invoices">
                            <div className="dlv-create-picker__handle" aria-hidden="true" />
                            <div className="dlv-create-picker__sheet-head">
                              <strong>Select invoice</strong>
                              <button
                                type="button"
                                className="dlv-create-picker__sheet-close"
                                onClick={() => setInvoiceOpen(false)}
                                aria-label="Close"
                              >
                                <X size={16} aria-hidden="true" />
                              </button>
                            </div>
                            <input
                              className="cv-input dlv-create-picker__search"
                              type="search"
                              value={invoiceQuery}
                              onChange={(e) => setInvoiceQuery(e.target.value)}
                              placeholder="Search invoice or client..."
                              disabled={submitting}
                              autoFocus
                            />
                            <div className="dlv-create-picker__list">
                              <button
                                type="button"
                                className={`dlv-create-picker__row${!form.invoice_id ? ' is-checked' : ''}`}
                                onClick={() => onInvoiceChange('')}
                                disabled={submitting}
                              >
                                <span>No invoice (optional)</span>
                              </button>
                              {filteredInvoices.length === 0 ? (
                                <div className="dlv-create-picker__empty">No invoices found.</div>
                              ) : (
                                filteredInvoices.map((inv) => {
                                  const checked = String(inv.id) === String(form.invoice_id)
                                  return (
                                    <button
                                      key={inv.id}
                                      type="button"
                                      className={`dlv-create-picker__row${checked ? ' is-checked' : ''}`}
                                      onClick={() => onInvoiceChange(String(inv.id))}
                                      disabled={submitting}
                                    >
                                      <span className="dlv-create-picker__row-main">
                                        {inv.invoice_number || `Invoice #${inv.id}`}
                                      </span>
                                      {inv.customer_name ? (
                                        <span className="dlv-create-picker__row-sub">{inv.customer_name}</span>
                                      ) : null}
                                    </button>
                                  )
                                })
                              )}
                            </div>
                          </div>
                        </>,
                        document.body,
                      )
                      : (
                        <div className="dlv-create-picker__sheet" role="listbox" aria-label="Invoices">
                          <div className="dlv-create-picker__sheet-head">
                            <strong>Select invoice</strong>
                            <button
                              type="button"
                              className="dlv-create-picker__sheet-close"
                              onClick={() => setInvoiceOpen(false)}
                              aria-label="Close"
                            >
                              <X size={16} aria-hidden="true" />
                            </button>
                          </div>
                          <input
                            className="cv-input dlv-create-picker__search"
                            type="search"
                            value={invoiceQuery}
                            onChange={(e) => setInvoiceQuery(e.target.value)}
                            placeholder="Search invoice or client..."
                            disabled={submitting}
                            autoFocus
                          />
                          <div className="dlv-create-picker__list">
                            <button
                              type="button"
                              className={`dlv-create-picker__row${!form.invoice_id ? ' is-checked' : ''}`}
                              onClick={() => onInvoiceChange('')}
                              disabled={submitting}
                            >
                              <span>No invoice (optional)</span>
                            </button>
                            {filteredInvoices.length === 0 ? (
                              <div className="dlv-create-picker__empty">No invoices found.</div>
                            ) : (
                              filteredInvoices.map((inv) => {
                                const checked = String(inv.id) === String(form.invoice_id)
                                return (
                                  <button
                                    key={inv.id}
                                    type="button"
                                    className={`dlv-create-picker__row${checked ? ' is-checked' : ''}`}
                                    onClick={() => onInvoiceChange(String(inv.id))}
                                    disabled={submitting}
                                  >
                                    <span className="dlv-create-picker__row-main">
                                      {inv.invoice_number || `Invoice #${inv.id}`}
                                    </span>
                                    {inv.customer_name ? (
                                      <span className="dlv-create-picker__row-sub">{inv.customer_name}</span>
                                    ) : null}
                                  </button>
                                )
                              })
                            )}
                          </div>
                        </div>
                      )
                    ) : null}
                  </div>
                ) : null}

                <div className="dlv-create-grid">
                  <label className="dlv-create-field">
                    <span>Client Name <em>*</em></span>
                    <input
                      className={`cv-input${invCls(filled.client_name)}`}
                      value={form.client_name}
                      onChange={(e) => setField('client_name', e.target.value)}
                      placeholder="Business or contact name"
                      required
                      autoFocus
                    />
                    {fieldErr(filled.client_name, 'Required')}
                  </label>
                  <label className="dlv-create-field">
                    <span>Client Phone</span>
                    <input
                      className="cv-input"
                      value={form.client_phone}
                      onChange={(e) => setField('client_phone', e.target.value)}
                      placeholder="e.g. 07xx xxx xxx"
                    />
                  </label>
                </div>

                <div className="dlv-create-grid">
                  <label className="dlv-create-field">
                    <span>From</span>
                    <input
                      className="cv-input"
                      value={form.pickup}
                      onChange={(e) => setField('pickup', e.target.value)}
                      placeholder="Warehouse, shop, depot..."
                    />
                  </label>
                  <label className="dlv-create-field">
                    <span>To <em>*</em></span>
                    <input
                      className={`cv-input${invCls(filled.destination)}`}
                      value={form.destination}
                      onChange={(e) => setField('destination', e.target.value)}
                      placeholder="Full address, site, or area"
                      required
                    />
                    {fieldErr(filled.destination, 'Required')}
                  </label>
                </div>

                <div className="dlv-create-grid">
                  <label className="dlv-create-field">
                    <span>Route cost</span>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      className="cv-input"
                      value={form.route_cost}
                      onChange={(e) => setField('route_cost', e.target.value)}
                      placeholder="0.00"
                    />
                  </label>
                  <label className="dlv-create-field">
                    <span>Description</span>
                    <input
                      className="cv-input"
                      value={form.description}
                      onChange={(e) => setField('description', e.target.value)}
                      placeholder="What you are delivering (optional)"
                    />
                  </label>
                </div>
              </>
            )}

            <label className={`cv-file dlv-create-file${submitting && receiptFile ? ' cv-file--uploading' : ''}`}>
              <input
                ref={receiptRef}
                type="file"
                name="receipt_file"
                accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,image/*"
                onChange={(e) => setReceiptFile(e.target.files?.[0] || null)}
              />
              <Receipt size={18} className="cv-file-icon" aria-hidden="true" />
              <span className="cv-file-title">{receiptFile ? receiptFile.name : 'Attach receipt (optional)'}</span>
              <span className="cv-file-sub">PDF, image, or document</span>
            </label>
            {receiptFile ? (
              <button
                type="button"
                className="cv-btn-ghost"
                onClick={() => {
                  setReceiptFile(null)
                  if (receiptRef.current) receiptRef.current.value = ''
                }}
              >
                <X size={14} aria-hidden="true" /> Remove receipt
              </button>
            ) : null}
            </div>

            <div className="dlv-create-modal__actions">
              <button type="button" className="cv-btn-cancel" onClick={onClose} disabled={submitting}>
                Cancel
              </button>
              <button type="submit" className="cv-btn-save" disabled={submitting}>
                {submitting ? <Loader2 size={16} className="cv-spin" /> : <Save size={16} />}
                {submitting ? 'Submitting...' : submitLabel}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  )

  return createPortal(modal, document.body)
}
