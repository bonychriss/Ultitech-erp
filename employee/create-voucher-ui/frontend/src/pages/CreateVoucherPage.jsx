import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Plus, Trash2, UserPlus, Loader2, X, CheckCircle2, AlertCircle,
  FileText, Info, Search, ChevronDown, Link2, UploadCloud, Check, Save,
} from 'lucide-react'
import { CFG, CURRENCIES, IS_EDIT, IS_LIMITED, currencySymbol, currencyMeta, formatMoney } from '../config.js'

const SECTIONS = [
  { id: 'cv-general', label: 'General' },
  { id: 'cv-items', label: 'Payment Details' },
  { id: 'cv-description', label: 'Description' },
  { id: 'cv-approvals', label: 'Approvals' },
  { id: 'cv-attachments', label: 'Attachments' },
]

function newItem(seed = {}) {
  return {
    key: Math.random().toString(36).slice(2),
    payment_type: seed.payment_type || '',
    budget_type: seed.budget_type || '',
    amount: seed.amount != null && seed.amount !== '' ? String(seed.amount) : '',
    item_description: seed.item_description || seed.description || '',
  }
}

function initialItems() {
  const rows = CFG.initial?.items
  if (Array.isArray(rows) && rows.length > 0) {
    return rows.map((row) => newItem(row))
  }
  return [newItem()]
}

function initialLinkedSO() {
  const ids = CFG.initial?.linked_sales_order_ids
  if (Array.isArray(ids) && ids.length > 0) {
    return new Set(ids.map((id) => Number(id)).filter((id) => id > 0))
  }
  return new Set()
}

export default function CreateVoucherPage() {
  const formRef = useRef(null)
  const actionInputRef = useRef(null)
  const fileInputRef = useRef(null)
  const submittedRef = useRef(false)
  const autoDraftedRef = useRef(false)
  const latestRef = useRef({})

  const init = CFG.initial || {}
  const [payees, setPayees] = useState(CFG.payees)
  const [payeeId, setPayeeId] = useState(init.payee_id ? String(init.payee_id) : '')
  const [currency, setCurrency] = useState(init.currency || CFG.currencies[0] || 'TZS')
  const [dateCreated, setDateCreated] = useState(init.date_created || CFG.today)
  const [purpose, setPurpose] = useState(init.purpose || CFG.purposes[0]?.value || 'general')
  const [isRestricted, setIsRestricted] = useState(!!init.is_restricted)
  const [items, setItems] = useState(initialItems)
  const [description, setDescription] = useState(init.description || '')
  const [applicant, setApplicant] = useState(init.applicant || '')
  const [departmentManager, setDepartmentManager] = useState(init.department_manager || '')
  const [checkedBy, setCheckedBy] = useState(init.checked_by || '')
  const [files, setFiles] = useState([])
  const [existingAttachments, setExistingAttachments] = useState(() => [...CFG.attachments])
  const [selectedSO, setSelectedSO] = useState(initialLinkedSO)
  const [soSearch, setSoSearch] = useState('')
  const [soOpen, setSoOpen] = useState(false)
  const [currencyOpen, setCurrencyOpen] = useState(false)
  const currencyRef = useRef(null)

  const [submitting, setSubmitting] = useState(false)
  const [showErrors, setShowErrors] = useState(false)
  const [formError, setFormError] = useState(CFG.error || '')
  const [flash, setFlash] = useState(CFG.flash || null)
  const [activeSection, setActiveSection] = useState(SECTIONS[0].id)

  // New payee modal
  const [payeeModalOpen, setPayeeModalOpen] = useState(false)
  const [payeeSaving, setPayeeSaving] = useState(false)
  const [payeeError, setPayeeError] = useState('')
  const [np, setNp] = useState({ name: '', type: 'Other', tin: '', person: '', phone: '', email: '', address: '' })

  const payeeName = useMemo(() => {
    const p = payees.find((x) => String(x.id) === String(payeeId))
    return p ? p.name : ''
  }, [payees, payeeId])

  const total = useMemo(
    () => items.reduce((sum, it) => sum + (parseFloat(it.amount) || 0), 0),
    [items],
  )

  const salesOrders = CFG.salesOrders || []
  const filteredSO = useMemo(() => {
    const q = soSearch.trim().toLowerCase()
    if (!q) return salesOrders
    return salesOrders.filter((so) =>
      [so.order_number, so.customer_name, so.salesperson_name, so.status]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q)),
    )
  }, [salesOrders, soSearch])

  // Scroll-spy
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
  }, [])

  // Keep a live snapshot for the unload handler (which binds once).
  latestRef.current = { payeeId, description, items }

  // Auto-save an editable DRAFT if the user leaves the page with meaningful,
  // unsubmitted input. Reuses the existing action=draft backend flow.
  // Disabled in edit mode — never create a new draft from an existing voucher.
  useEffect(() => {
    if (IS_EDIT) return undefined
    function hasMeaningfulInput() {
      const s = latestRef.current
      if (s.payeeId) return true
      if ((s.description || '').trim()) return true
      return (s.items || []).some(
        (it) =>
          (parseFloat(it.amount) || 0) > 0 ||
          (it.item_description || '').trim() !== '' ||
          it.budget_type !== '',
      )
    }
    function autoSaveDraft() {
      if (submittedRef.current || autoDraftedRef.current) return
      if (!formRef.current || !hasMeaningfulInput()) return
      autoDraftedRef.current = true
      try {
        const fd = new FormData(formRef.current)
        fd.set('action', 'draft')
        let sent = false
        if (navigator.sendBeacon) {
          sent = navigator.sendBeacon(CFG.postUrl, fd)
        }
        if (!sent) {
          fetch(CFG.postUrl, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true })
        }
      } catch {
        /* best-effort */
      }
    }
    window.addEventListener('pagehide', autoSaveDraft)
    return () => window.removeEventListener('pagehide', autoSaveDraft)
  }, [])

  // Close currency dropdown on outside click
  useEffect(() => {
    if (!currencyOpen) return
    function onDoc(e) {
      if (currencyRef.current && !currencyRef.current.contains(e.target)) setCurrencyOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [currencyOpen])

  const selectedCurrency = currencyMeta(currency)

  function scrollToSection(id) {
    const el = document.getElementById(id)
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  function updateItem(key, patch) {
    setItems((prev) => prev.map((it) => (it.key === key ? { ...it, ...patch } : it)))
  }
  // Payment type is chosen once (on Item 1) and applied to every item.
  function setPaymentTypeAll(value) {
    setItems((prev) => prev.map((it) => ({ ...it, payment_type: value })))
  }
  function addItem() {
    setItems((prev) => {
      const master = prev[0]?.payment_type || ''
      return [...prev, { ...newItem(), payment_type: master }]
    })
  }
  function removeItem(key) {
    setItems((prev) => (prev.length > 1 ? prev.filter((it) => it.key !== key) : prev))
  }

  function toggleSO(id) {
    setSelectedSO((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  // Sync the React-managed file list back into the real <input> so the form submits it.
  function syncFileInput(list) {
    if (!fileInputRef.current) return
    const dt = new DataTransfer()
    list.forEach((f) => dt.items.add(f))
    fileInputRef.current.files = dt.files
  }
  function onFileChange(e) {
    const picked = Array.from(e.target.files || [])
    if (picked.length === 0) return
    const existing = new Set(files.map((f) => `${f.name}__${f.size}`))
    const merged = [...files]
    picked.forEach((f) => {
      const key = `${f.name}__${f.size}`
      if (!existing.has(key)) { merged.push(f); existing.add(key) }
    })
    setFiles(merged)
    syncFileInput(merged)
  }
  function removeFile(idx) {
    const next = files.filter((_, i) => i !== idx)
    setFiles(next)
    syncFileInput(next)
  }

  async function removeExistingAttachment(att) {
    if (IS_LIMITED) return
    if (!window.confirm(`Remove attachment "${att.original_name}"?`)) return
    const fd = new FormData()
    fd.append('attachment_id', String(att.id))
    try {
      const res = await fetch(CFG.deleteAttachmentUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      const data = await res.json()
      if (!data.ok) {
        setFormError(data.error || 'Could not delete attachment.')
        return
      }
      setExistingAttachments((prev) => prev.filter((a) => a.id !== att.id))
    } catch {
      setFormError('Could not delete attachment.')
    }
  }

  function attachmentHref(att) {
    const rel = String(att.file_path || '').replace(/^\/+/, '')
    return `${CFG.proxyPdfUrl}?file=${encodeURIComponent(rel)}`
  }

  function validate() {
    if (IS_LIMITED) return ''
    if (!payeeId) return 'Please select a payee.'
    if (!dateCreated) return 'Please choose a date.'
    if (!description.trim()) return 'Please enter a description.'
    const validItems = items.filter(
      (it) => it.payment_type && it.budget_type && (parseFloat(it.amount) || 0) > 0,
    )
    if (validItems.length === 0)
      return 'Please add at least one payment item with a payment type, budget type and amount.'
    if (!applicant || !departmentManager || !checkedBy)
      return 'Please select Applicant, Department Manager, and Checked By.'
    return ''
  }

  function handleSubmit(e) {
    e.preventDefault()
    const err = validate()
    if (err) {
      setShowErrors(true)
      setFormError(IS_LIMITED ? err : 'Please complete the highlighted required fields.')
      setTimeout(() => {
        const el = formRef.current?.querySelector('.is-invalid, .cv-err')
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
        else window.scrollTo({ top: 0, behavior: 'smooth' })
      }, 30)
      return
    }
    setFormError('')
    submittedRef.current = true
    if (actionInputRef.current) {
      actionInputRef.current.value = IS_EDIT ? 'update' : 'create'
    }
    setSubmitting(true)
    formRef.current.submit()
  }

  async function submitNewPayee() {
    setPayeeError('')
    const name = np.name.trim()
    if (!name) { setPayeeError('Payee name is required.'); return }
    if (!np.phone.trim() && !np.email.trim()) {
      setPayeeError('Provide a phone number or email.'); return
    }
    const contactParts = [np.person, np.phone, np.email, np.address].map((s) => s.trim()).filter(Boolean)
    const fd = new FormData()
    fd.append('action', 'ajax_create_payee')
    fd.append('name', name)
    fd.append('type', np.type)
    fd.append('tin', np.tin.trim())
    fd.append('contact', contactParts.join(' | '))
    setPayeeSaving(true)
    try {
      const res = await fetch(CFG.postUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      const data = await res.json()
      if (!data.success) { setPayeeError(data.message || 'Could not add payee.'); return }
      const created = { id: data.id, name: data.name, type: data.type }
      setPayees((prev) => [...prev, created].sort((a, b) => a.name.localeCompare(b.name)))
      setPayeeId(String(data.id))
      setPayeeModalOpen(false)
      setNp({ name: '', type: 'Other', tin: '', person: '', phone: '', email: '', address: '' })
    } catch {
      setPayeeError('Network error. Please try again.')
    } finally {
      setPayeeSaving(false)
    }
  }

  const linkedIdsCsv = Array.from(selectedSO).join(',')
  const validItemCount = items.filter(
    (it) => it.payment_type && it.budget_type && (parseFloat(it.amount) || 0) > 0,
  ).length
  const attachmentCount = existingAttachments.length + files.length
  const fieldsLocked = IS_LIMITED

  // Per-field required state (live)
  const filled = {
    payee: !!payeeId,
    date: !!dateCreated,
    description: !!description.trim(),
    items: validItemCount > 0,
    applicant: !!applicant,
    departmentManager: !!departmentManager,
    checkedBy: !!checkedBy,
  }
  const requiredKeys = Object.keys(filled)
  const requiredDone = requiredKeys.filter((k) => filled[k]).length
  const requiredTotal = requiredKeys.length

  // Marker shown next to a required label: green check when filled, red asterisk otherwise
  const mark = (isFilled) =>
    isFilled
      ? <Check size={13} className="cv-ok" aria-label="filled" />
      : <span className="req" aria-label="required">*</span>
  // Class helper: green when filled, red when empty after a submit attempt
  const invCls = (ok) => (ok ? ' is-valid' : (showErrors ? ' is-invalid' : ''))
  // Inline error line
  const fieldErr = (ok, msg) =>
    showErrors && !ok ? (
      <div className="cv-err"><AlertCircle size={12} /> {msg}</div>
    ) : null

  return (
    <div className="cv-shell">
      <div className="cv-topbar">
        <div>
          <h1>{IS_EDIT ? 'Edit Payment Voucher' : 'Create Payment Voucher'}</h1>
          <p>
            {IS_LIMITED
              ? 'Update classification details for this approved voucher.'
              : IS_EDIT
                ? (CFG.voucherNo ? `Updating ${CFG.voucherNo}` : 'Update this payment voucher.')
                : 'Prepare a new payment voucher and route it for approval.'}
          </p>
        </div>
        {IS_EDIT && CFG.voucherNo ? (
          <div className="cv-topbar-meta">
            <span className="cv-pill">{CFG.voucherNo}</span>
            {CFG.statusLabel ? <span className="cv-pill cv-pill--muted">{CFG.statusLabel}</span> : null}
          </div>
        ) : null}
      </div>

      {flash && (
        <div className="cv-alert cv-alert--success">
          <CheckCircle2 size={18} />
          <div>
            <strong>{flash.title || 'Success'}</strong>
            {flash.message ? <span>{flash.message}</span> : null}
          </div>
          <button type="button" className="cv-alert-x" onClick={() => setFlash(null)} aria-label="Dismiss">
            <X size={16} />
          </button>
        </div>
      )}

      {formError && (
        <div className="cv-alert cv-alert--error">
          <AlertCircle size={18} />
          <div><span>{formError}</span></div>
          <button type="button" className="cv-alert-x" onClick={() => setFormError('')} aria-label="Dismiss">
            <X size={16} />
          </button>
        </div>
      )}

      <form
        ref={formRef}
        className="cv-layout"
        method="POST"
        action={CFG.postUrl}
        encType="multipart/form-data"
        onSubmit={handleSubmit}
      >
        <input ref={actionInputRef} type="hidden" name="action" value={IS_EDIT ? 'update' : 'create'} readOnly />
        {IS_LIMITED ? <input type="hidden" name="limited_classification_update" value="1" readOnly /> : null}
        <input type="hidden" name="payee_name" value={payeeName} readOnly />
        <input type="hidden" name="prepared_by" value={CFG.preparedBy || init.prepared_by || ''} readOnly />
        <input type="hidden" name="general_manager" value={init.general_manager || ''} readOnly />
        <input type="hidden" name="linked_sales_order_ids" value={linkedIdsCsv} readOnly />
        <input type="hidden" name="linked_sales_order_id" value={Array.from(selectedSO)[0] || ''} readOnly />
        <input type="hidden" name="supporting_documents" value={attachmentCount} readOnly />

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
          {/* GENERAL */}
          <section id="cv-general" className="cv-section">
            <header className="cv-section-head">
              <h2>General Information</h2>
              <p>Who is being paid, in which currency, and when.</p>
            </header>

            <div className="cv-row">
              <label className="cv-label">Payee {mark(filled.payee)}</label>
              <div className="cv-field">
                <div className="cv-payee-row">
                  <select
                    name="payee_id"
                    className={`cv-select${invCls(filled.payee)}`}
                    value={payeeId}
                    onChange={(e) => setPayeeId(e.target.value)}
                    required
                    disabled={fieldsLocked}
                  >
                    <option value="">Select payee</option>
                    {payees.map((p) => (
                      <option key={p.id} value={p.id}>{p.name}</option>
                    ))}
                  </select>
                  {!fieldsLocked && (
                    <button type="button" className="cv-btn-ghost" onClick={() => setPayeeModalOpen(true)}>
                      <UserPlus size={15} /> New
                    </button>
                  )}
                </div>
                {fieldErr(filled.payee, 'Please select a payee.')}
              </div>
            </div>

            <div className="cv-row">
              <label className="cv-label">Currency</label>
              <div className="cv-field cv-field--narrow">
                <div className={`cv-currency${currencyOpen ? ' is-open' : ''}`} ref={currencyRef}>
                  <button
                    type="button"
                    className="cv-currency-btn"
                    onClick={() => !fieldsLocked && setCurrencyOpen((v) => !v)}
                    aria-haspopup="listbox"
                    aria-expanded={currencyOpen}
                    disabled={fieldsLocked}
                  >
                    <img className="cv-flag" src={`https://flagcdn.com/32x24/${selectedCurrency.flag}.png`} alt="" loading="lazy" />
                    <span className="cv-currency-code">{selectedCurrency.code}</span>
                    <span className="cv-currency-name">{selectedCurrency.name}</span>
                    <ChevronDown size={16} className="cv-currency-chev" />
                  </button>
                  {currencyOpen && (
                    <div className="cv-currency-menu" role="listbox">
                      {CURRENCIES.map((c) => (
                        <button
                          key={c.code}
                          type="button"
                          role="option"
                          aria-selected={c.code === currency}
                          className={`cv-currency-opt${c.code === currency ? ' is-sel' : ''}`}
                          onClick={() => { setCurrency(c.code); setCurrencyOpen(false) }}
                        >
                          <img className="cv-flag" src={`https://flagcdn.com/32x24/${c.flag}.png`} alt="" loading="lazy" />
                          <span className="cv-currency-code">{c.code}</span>
                          <span className="cv-currency-name">{c.name}</span>
                        </button>
                      ))}
                    </div>
                  )}
                  <input type="hidden" name="currency" value={currency} />
                </div>
              </div>
            </div>

            <div className="cv-row">
              <label className="cv-label">Date {mark(filled.date)}</label>
              <div className="cv-field cv-field--narrow">
                <input type="date" name="date_created" className={`cv-input${invCls(filled.date)}`} value={dateCreated} onChange={(e) => setDateCreated(e.target.value)} required disabled={fieldsLocked} />
                {fieldErr(filled.date, 'Please choose a date.')}
              </div>
            </div>

            <div className="cv-row">
              <label className="cv-label">Purpose</label>
              <div className="cv-field cv-field--narrow">
                <select name="voucher_purpose" className={`cv-select${purpose ? ' is-valid' : ''}`} value={purpose} onChange={(e) => setPurpose(e.target.value)}>
                  {CFG.purposes.map((p) => (<option key={p.value} value={p.value}>{p.label}</option>))}
                </select>
              </div>
            </div>

            {CFG.canRestrict && (
              <div className="cv-row">
                <label className="cv-label">Restricted</label>
                <div className="cv-field">
                  <label className="cv-check">
                    <input type="checkbox" name="is_restricted" value="1" checked={isRestricted} onChange={(e) => setIsRestricted(e.target.checked)} disabled={fieldsLocked} />
                    <span>Mark this voucher as restricted (visible to Finance/Admin only)</span>
                  </label>
                </div>
              </div>
            )}

            {salesOrders.length > 0 && (
              <div className="cv-row cv-row--top">
                <label className="cv-label">Link Sales Orders</label>
                <div className="cv-field">
                  <div className={`cv-so${soOpen ? ' is-open' : ''}`}>
                    <button
                      type="button"
                      className="cv-so-toggle"
                      onClick={() => setSoOpen((v) => !v)}
                      aria-expanded={soOpen}
                    >
                      <Link2 size={15} />
                      <span className="cv-so-toggle-label">
                        {selectedSO.size > 0
                          ? `${selectedSO.size} sales order${selectedSO.size > 1 ? 's' : ''} linked`
                          : 'Link sales orders (optional)'}
                      </span>
                      <ChevronDown size={16} className="cv-so-chevron" />
                    </button>
                    {soOpen && (
                      <div className="cv-so-body">
                        <div className="cv-so-search">
                          <Search size={14} />
                          <input
                            type="text"
                            className="cv-so-search-input"
                            placeholder="Search sales orders..."
                            value={soSearch}
                            onChange={(e) => setSoSearch(e.target.value)}
                          />
                        </div>
                        <div className="cv-so-list">
                          {filteredSO.length === 0 ? (
                            <div className="cv-so-empty">No sales orders found</div>
                          ) : (
                            filteredSO.slice(0, 50).map((so) => (
                              <label key={so.id} className={`cv-so-item${selectedSO.has(so.id) ? ' is-checked' : ''}`}>
                                <input type="checkbox" checked={selectedSO.has(so.id)} onChange={() => toggleSO(so.id)} />
                                <span className="cv-so-no">{so.order_number}</span>
                                <span className="cv-so-cust">{so.customer_name}</span>
                                <span className="cv-so-status">{so.status}</span>
                              </label>
                            ))
                          )}
                        </div>
                        {selectedSO.size > 0 && (
                          <div className="cv-so-count">{selectedSO.size} sales order{selectedSO.size > 1 ? 's' : ''} linked</div>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              </div>
            )}
          </section>

          {/* ITEMS */}
          <section id="cv-items" className="cv-section">
            <header className="cv-section-head">
              <h2>Payment Details {mark(filled.items)}</h2>
              <p>Break down the payment into one or more line items.</p>
            </header>

            {fieldErr(filled.items, 'Add at least one item with a payment type, budget type and amount greater than 0.')}

            <div className="cv-items">
              {items.map((it, idx) => (
                <div className="cv-item" key={it.key}>
                  <div className="cv-item-head">
                    <span className="cv-item-no">Item {idx + 1}</span>
                    {items.length > 1 && !fieldsLocked && (
                      <button type="button" className="cv-item-del" onClick={() => removeItem(it.key)} aria-label="Remove item">
                        <Trash2 size={15} />
                      </button>
                    )}
                  </div>
                  <div className="cv-item-grid">
                    <div className="cv-item-cell">
                      <label>Payment Type</label>
                      {idx === 0 ? (
                        <select
                          className={`cv-select${it.payment_type ? ' is-valid' : ''}`}
                          value={it.payment_type}
                          name="payment_type[]"
                          onChange={(e) => setPaymentTypeAll(e.target.value)}
                          disabled={fieldsLocked}
                        >
                          <option value="">Select...</option>
                          {CFG.paymentTypes.map((t) => (<option key={t} value={t}>{t}</option>))}
                        </select>
                      ) : (
                        <>
                          <select
                            className={`cv-select${it.payment_type ? ' is-valid' : ''}`}
                            value={it.payment_type}
                            disabled
                            title="Payment type is set on Item 1"
                          >
                            <option value="">Select...</option>
                            {CFG.paymentTypes.map((t) => (<option key={t} value={t}>{t}</option>))}
                          </select>
                          <input type="hidden" name="payment_type[]" value={it.payment_type} />
                        </>
                      )}
                    </div>
                    <div className="cv-item-cell">
                      <label>Budget Type</label>
                      <select
                        className={`cv-select${it.budget_type ? ' is-valid' : ''}`}
                        value={it.budget_type}
                        name="budget_type[]"
                        onChange={(e) => updateItem(it.key, { budget_type: e.target.value })}
                        disabled={fieldsLocked}
                      >
                        <option value="">Select...</option>
                        {CFG.budgetTypes.map((t) => (<option key={t} value={t}>{t}</option>))}
                      </select>
                    </div>
                    <div className="cv-item-cell">
                      <label>Amount</label>
                      <div className="cv-amount">
                        <span className="cv-amount-cur">{currencySymbol(currency)}</span>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          className={`cv-input${(parseFloat(it.amount) || 0) > 0 ? ' is-valid' : ''}`}
                          placeholder="0.00"
                          value={it.amount}
                          name="amount[]"
                          onChange={(e) => updateItem(it.key, { amount: e.target.value })}
                          disabled={fieldsLocked}
                        />
                      </div>
                    </div>
                    <div className="cv-item-cell cv-item-cell--wide">
                      <label>Item Description</label>
                      <input
                        type="text"
                        className="cv-input"
                        placeholder="What is this line for?"
                        value={it.item_description}
                        name="item_description[]"
                        onChange={(e) => updateItem(it.key, { item_description: e.target.value })}
                        disabled={fieldsLocked}
                      />
                    </div>
                  </div>
                  {/* payee name carried per item to satisfy backend name[] */}
                  <input type="hidden" name="name[]" value={payeeName} readOnly />
                </div>
              ))}
            </div>

            <div className="cv-items-foot">
              {!fieldsLocked && (
                <button type="button" className="cv-btn-ghost" onClick={addItem}>
                  <Plus size={15} /> Add Item
                </button>
              )}
              <div className="cv-total">
                <span>Total ({validItemCount} item{validItemCount === 1 ? '' : 's'})</span>
                <strong>{formatMoney(currency, total)}</strong>
              </div>
            </div>
          </section>

          {/* DESCRIPTION */}
          <section id="cv-description" className="cv-section">
            <header className="cv-section-head">
              <h2>Description</h2>
              <p>Explain the purpose and context of this payment.</p>
            </header>
            <div className="cv-row cv-row--top">
              <label className="cv-label">Description {mark(filled.description)}</label>
              <div className="cv-field">
                <textarea
                  name="description"
                  className={`cv-textarea${invCls(filled.description)}`}
                  rows={7}
                  placeholder="Describe what this voucher is for..."
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  required
                  disabled={fieldsLocked}
                />
                {fieldErr(filled.description, 'Please enter a description.')}
              </div>
            </div>
          </section>

          {/* APPROVALS */}
          <section id="cv-approvals" className="cv-section">
            <header className="cv-section-head">
              <h2>Approval Routing</h2>
              <p>Choose who applies for, reviews and checks this voucher.</p>
            </header>

            <div className="cv-row">
              <label className="cv-label">Applicant {mark(filled.applicant)}</label>
              <div className="cv-field cv-field--narrow">
                <select name="applicant" className={`cv-select${invCls(filled.applicant)}`} value={applicant} onChange={(e) => setApplicant(e.target.value)} required disabled={fieldsLocked}>
                  <option value="">Select...</option>
                  {CFG.users.map((u) => (<option key={u.full_name} value={u.full_name}>{u.full_name}</option>))}
                </select>
                {fieldErr(filled.applicant, 'Please select an applicant.')}
              </div>
            </div>
            <div className="cv-row">
              <label className="cv-label">Department Manager {mark(filled.departmentManager)}</label>
              <div className="cv-field cv-field--narrow">
                <select name="department_manager" className={`cv-select${invCls(filled.departmentManager)}`} value={departmentManager} onChange={(e) => setDepartmentManager(e.target.value)} required disabled={fieldsLocked}>
                  <option value="">Select...</option>
                  {CFG.users.map((u) => (<option key={u.full_name} value={u.full_name}>{u.full_name}</option>))}
                </select>
                {fieldErr(filled.departmentManager, 'Please select a department manager.')}
              </div>
            </div>
            <div className="cv-row">
              <label className="cv-label">Checked By {mark(filled.checkedBy)}</label>
              <div className="cv-field cv-field--narrow">
                <select name="checked_by" className={`cv-select${invCls(filled.checkedBy)}`} value={checkedBy} onChange={(e) => setCheckedBy(e.target.value)} required disabled={fieldsLocked}>
                  <option value="">Select...</option>
                  {CFG.financeUsers.map((u) => (<option key={u.full_name} value={u.full_name}>{u.full_name}</option>))}
                </select>
                {fieldErr(filled.checkedBy, 'Please select who checks this voucher.')}
              </div>
            </div>
          </section>

          {/* ATTACHMENTS */}
          <section id="cv-attachments" className="cv-section">
            <header className="cv-section-head">
              <h2>Attachments</h2>
              <p>Upload supporting documents (PDF, images, Office files).</p>
            </header>
            <div className="cv-row cv-row--top">
              <label className="cv-label">Supporting Files</label>
              <div className="cv-field">
                {existingAttachments.length > 0 && (
                  <ul className="cv-file-list cv-file-list--existing">
                    {existingAttachments.map((att) => (
                      <li key={att.id}>
                        <FileText size={13} className="cv-file-ic" />
                        <a className="cv-file-nm" href={attachmentHref(att)} target="_blank" rel="noreferrer" title={att.original_name}>
                          {att.original_name}
                        </a>
                        {att.size_bytes ? (
                          <span className="cv-file-sz">{(Number(att.size_bytes) / 1024).toFixed(0)} KB</span>
                        ) : null}
                        {!IS_LIMITED && (
                          <button
                            type="button"
                            className="cv-file-rm"
                            onClick={() => removeExistingAttachment(att)}
                            title="Remove this file"
                            aria-label={`Remove ${att.original_name}`}
                          >
                            <X size={14} />
                          </button>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
                {!IS_LIMITED && (
                  <label className="cv-file">
                    <input
                      ref={fileInputRef}
                      type="file"
                      name="supporting_files[]"
                      multiple
                      accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx,image/*,application/pdf"
                      onChange={onFileChange}
                    />
                    <UploadCloud size={28} className="cv-file-icon" aria-hidden="true" />
                    <span className="cv-file-title">Upload a file</span>
                    <span className="cv-file-sub">Click to browse, or drag &amp; drop files here</span>
                  </label>
                )}
                {files.length > 0 && (
                  <ul className="cv-file-list">
                    {files.map((f, i) => (
                      <li key={`${f.name}-${i}`}>
                        <FileText size={13} className="cv-file-ic" />
                        <span className="cv-file-nm" title={f.name}>{f.name}</span>
                        <span className="cv-file-sz">{(f.size / 1024).toFixed(0)} KB</span>
                        <button
                          type="button"
                          className="cv-file-rm"
                          onClick={() => removeFile(i)}
                          title="Remove this file"
                          aria-label={`Remove ${f.name}`}
                        >
                          <X size={14} />
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
                <div className="cv-row cv-row--sub">
                  <label className="cv-sublabel">Attached documents</label>
                  <div className="cv-doc-count">
                    <strong>{attachmentCount}</strong>
                    <span>{attachmentCount === 1 ? 'file attached' : 'files attached'}</span>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <div className="cv-actions">
            <a href={CFG.cancelUrl} className="cv-btn-cancel">Cancel</a>
            <button type="submit" className="cv-btn-save" disabled={submitting}>
              {submitting ? <Loader2 size={16} className="cv-spin" /> : <Save size={16} />}
              {submitting
                ? (IS_EDIT ? 'Saving...' : 'Submitting...')
                : (IS_LIMITED ? 'Save Classification' : IS_EDIT ? 'Update Voucher' : 'Create Voucher')}
            </button>
          </div>
          {!IS_EDIT && (
            <p className="cv-actions-hint">
              <Info size={13} /> If you leave this page without submitting, your work is saved automatically as an editable draft.
            </p>
          )}
        </div>
      </form>

      {payeeModalOpen && (
        <div className="cv-modal-overlay" onMouseDown={(e) => { if (e.target === e.currentTarget) setPayeeModalOpen(false) }}>
          <div className="cv-modal" role="dialog" aria-modal="true">
            <div className="cv-modal-head">
              <h3>Add New Payee</h3>
              <button type="button" className="cv-alert-x" onClick={() => setPayeeModalOpen(false)} aria-label="Close"><X size={18} /></button>
            </div>
            {payeeError && <div className="cv-modal-error"><AlertCircle size={15} /> {payeeError}</div>}
            <div className="cv-modal-body">
              <div className="cv-modal-field">
                <label>Payee Name <span className="req">*</span></label>
                <input className="cv-input" value={np.name} onChange={(e) => setNp({ ...np, name: e.target.value })} autoFocus />
              </div>
              <div className="cv-modal-field">
                <label>Type</label>
                <select className="cv-select" value={np.type} onChange={(e) => setNp({ ...np, type: e.target.value })}>
                  <option>Other</option>
                  <option>Supplier</option>
                  <option>Employee</option>
                  <option>Customer</option>
                  <option>Government</option>
                </select>
              </div>
              <div className="cv-modal-field">
                <label>TIN</label>
                <input className="cv-input" value={np.tin} onChange={(e) => setNp({ ...np, tin: e.target.value })} />
              </div>
              <div className="cv-modal-field">
                <label>Contact Person</label>
                <input className="cv-input" value={np.person} onChange={(e) => setNp({ ...np, person: e.target.value })} />
              </div>
              <div className="cv-modal-field">
                <label>Phone</label>
                <input className="cv-input" value={np.phone} onChange={(e) => setNp({ ...np, phone: e.target.value })} />
              </div>
              <div className="cv-modal-field">
                <label>Email</label>
                <input className="cv-input" value={np.email} onChange={(e) => setNp({ ...np, email: e.target.value })} />
              </div>
              <div className="cv-modal-field cv-modal-field--full">
                <label>Address</label>
                <input className="cv-input" value={np.address} onChange={(e) => setNp({ ...np, address: e.target.value })} />
              </div>
            </div>
            <div className="cv-modal-foot">
              <button type="button" className="cv-btn-cancel" onClick={() => setPayeeModalOpen(false)}>Cancel</button>
              <button type="button" className="cv-btn-save" onClick={submitNewPayee} disabled={payeeSaving}>
                {payeeSaving ? <Loader2 size={16} className="cv-spin" /> : <UserPlus size={16} />}
                {payeeSaving ? 'Saving�' : 'Add Payee'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
