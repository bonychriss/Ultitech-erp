import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  Plus, Trash2, UserPlus, Loader2, X, CheckCircle2, AlertCircle,
  FileText, Info, Search, ChevronDown, Link2, UploadCloud, Check, Save, Eye, ArrowLeft,
} from 'lucide-react'
import { CFG, CURRENCIES, IS_EDIT, IS_LIMITED, currencySymbol, currencyMeta, formatMoney } from '../config.js'

const CV_DRAFT_KEY = `cv_create_draft_v1_${CFG.module || 'voucher'}`

function readCreateDraft() {
  if (IS_EDIT || typeof sessionStorage === 'undefined') return null
  try {
    const raw = sessionStorage.getItem(CV_DRAFT_KEY)
    if (!raw) return null
    const data = JSON.parse(raw)
    if (!data || typeof data !== 'object') return null
    // Ignore stale drafts older than 7 days.
    const savedAt = Number(data.savedAt || 0)
    if (savedAt > 0 && Date.now() - savedAt > 7 * 24 * 60 * 60 * 1000) {
      sessionStorage.removeItem(CV_DRAFT_KEY)
      return null
    }
    return data
  } catch {
    return null
  }
}

function writeCreateDraft(snapshot) {
  if (IS_EDIT || typeof sessionStorage === 'undefined') return
  try {
    sessionStorage.setItem(CV_DRAFT_KEY, JSON.stringify({ ...snapshot, savedAt: Date.now() }))
  } catch {
    /* quota / private mode */
  }
}

function clearCreateDraft() {
  if (typeof sessionStorage === 'undefined') return
  try {
    sessionStorage.removeItem(CV_DRAFT_KEY)
  } catch {
    /* ignore */
  }
}

/** Snapshot restored once at boot (create mode only). */
const BOOT_DRAFT = readCreateDraft()

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
  if (!IS_EDIT && Array.isArray(BOOT_DRAFT?.items) && BOOT_DRAFT.items.length > 0) {
    return BOOT_DRAFT.items.map((row) => newItem(row))
  }
  const rows = CFG.initial?.items
  if (Array.isArray(rows) && rows.length > 0) {
    return rows.map((row) => newItem(row))
  }
  return [newItem()]
}

function initialLinkedSO() {
  if (!IS_EDIT && Array.isArray(BOOT_DRAFT?.linked_sales_order_ids) && BOOT_DRAFT.linked_sales_order_ids.length > 0) {
    return new Set(BOOT_DRAFT.linked_sales_order_ids.map((id) => Number(id)).filter((id) => id > 0))
  }
  const ids = CFG.initial?.linked_sales_order_ids
  if (Array.isArray(ids) && ids.length > 0) {
    return new Set(ids.map((id) => Number(id)).filter((id) => id > 0))
  }
  return new Set()
}

function initialLinkedPO() {
  if (!IS_EDIT) {
    if (Array.isArray(BOOT_DRAFT?.linked_stock_po_ids) && BOOT_DRAFT.linked_stock_po_ids.length > 0) {
      return new Set(BOOT_DRAFT.linked_stock_po_ids.map((id) => Number(id)).filter((id) => id > 0))
    }
    const singleDraft = Number(BOOT_DRAFT?.linked_stock_po_id || 0)
    if (singleDraft > 0) return new Set([singleDraft])
  }
  const ids = CFG.initial?.linked_stock_po_ids
  if (Array.isArray(ids) && ids.length > 0) {
    return new Set(ids.map((id) => Number(id)).filter((id) => id > 0))
  }
  const single = Number(CFG.initial?.linked_stock_po_id || 0)
  return single > 0 ? new Set([single]) : new Set()
}

function draftOr(key, fallback) {
  if (IS_EDIT || !BOOT_DRAFT || BOOT_DRAFT[key] === undefined || BOOT_DRAFT[key] === null) return fallback
  return BOOT_DRAFT[key]
}

function poDocumentHref(poId) {
  const base = String(CFG.poDocumentUrl || '').replace(/\?.*$/, '')
  if (!base || !poId) return ''
  const join = base.includes('?') ? '&' : '?'
  return `${base}${join}id=${encodeURIComponent(poId)}&embed=1`
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
  const [payeeId, setPayeeId] = useState(() => String(draftOr('payeeId', init.payee_id ? String(init.payee_id) : '') || ''))
  const [currency, setCurrency] = useState(() => String(draftOr('currency', init.currency || CFG.currencies[0] || 'TZS') || 'TZS'))
  const [dateCreated, setDateCreated] = useState(() => String(draftOr('dateCreated', init.date_created || CFG.today) || CFG.today))
  const [purpose, setPurpose] = useState(() => String(draftOr('purpose', init.purpose || CFG.purposes[0]?.value || 'general') || 'general'))
  const [isRestricted, setIsRestricted] = useState(() => !!draftOr('isRestricted', !!init.is_restricted))
  const [items, setItems] = useState(initialItems)
  const [description, setDescription] = useState(() => String(draftOr('description', init.description || '') || ''))
  const [applicant, setApplicant] = useState(() => String(draftOr('applicant', init.applicant || '') || ''))
  const [departmentManager, setDepartmentManager] = useState(() => String(draftOr('departmentManager', init.department_manager || '') || ''))
  const [checkedBy, setCheckedBy] = useState(() => String(draftOr('checkedBy', init.checked_by || '') || ''))
  const [files, setFiles] = useState([])
  const [fileStatus, setFileStatus] = useState({}) // key -> 'loading' | 'ready'
  const [existingAttachments, setExistingAttachments] = useState(() => [...CFG.attachments])
  const [selectedSO, setSelectedSO] = useState(initialLinkedSO)
  const [selectedPO, setSelectedPO] = useState(initialLinkedPO)
  const [poSearch, setPoSearch] = useState('')
  const [poOpen, setPoOpen] = useState(false)
  const [poViewDetail, setPoViewDetail] = useState(null)
  const [poViewLoading, setPoViewLoading] = useState(false)
  const [poViewError, setPoViewError] = useState('')
  const [soSearch, setSoSearch] = useState('')
  const [soOpen, setSoOpen] = useState(false)
  const [currencyOpen, setCurrencyOpen] = useState(false)
  const currencyRef = useRef(null)

  const isStockPurchase = purpose === 'stock_purchase'
  const isGeneralPurpose = !isStockPurchase
  const hasLinkedPo = selectedPO.size > 0
  const linkedStockPoId = hasLinkedPo ? String(Array.from(selectedPO)[0]) : ''
  const linkedPoIdsCsv = Array.from(selectedPO).join(',')
  const hasSupportingFiles = existingAttachments.length + files.length > 0

  const [submitting, setSubmitting] = useState(false)
  const [showErrors, setShowErrors] = useState(false)
  const [formError, setFormError] = useState(CFG.error || '')
  const [flash, setFlash] = useState(CFG.flash || null)

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

  const purchaseOrders = CFG.purchaseOrders || []
  const filteredPO = useMemo(() => {
    const q = poSearch.trim().toLowerCase()
    let rows = purchaseOrders
    if (q) {
      rows = purchaseOrders.filter((po) =>
        [po.po_number, po.supplier_name, po.status]
          .filter(Boolean)
          .some((v) => String(v).toLowerCase().includes(q)),
      )
    }
    // Keep selected POs at the very top of the popup list.
    if (selectedPO.size === 0) return rows
    const selected = []
    const rest = []
    rows.forEach((po) => {
      if (selectedPO.has(Number(po.id))) selected.push(po)
      else rest.push(po)
    })
    return [...selected, ...rest]
  }, [purchaseOrders, poSearch, selectedPO])

  const selectedPoRows = useMemo(
    () => purchaseOrders.filter((po) => selectedPO.has(Number(po.id))),
    [purchaseOrders, selectedPO],
  )

  // Keep a live snapshot for the unload handler (which binds once).
  latestRef.current = { payeeId, description, items }

  // Persist create-form input so browser refresh keeps the user's work.
  useEffect(() => {
    if (IS_EDIT) return undefined
    const t = window.setTimeout(() => {
      writeCreateDraft({
        payeeId,
        currency,
        dateCreated,
        purpose,
        isRestricted,
        description,
        applicant,
        departmentManager,
        checkedBy,
        items: items.map((it) => ({
          payment_type: it.payment_type || '',
          budget_type: it.budget_type || '',
          amount: it.amount || '',
          item_description: it.item_description || '',
        })),
        linked_sales_order_ids: Array.from(selectedSO),
        linked_stock_po_ids: Array.from(selectedPO),
      })
    }, 200)
    return () => window.clearTimeout(t)
  }, [
    payeeId,
    currency,
    dateCreated,
    purpose,
    isRestricted,
    description,
    applicant,
    departmentManager,
    checkedBy,
    items,
    selectedSO,
    selectedPO,
  ])

  // Auto-save an editable DRAFT if the user leaves the page with meaningful,
  // unsubmitted input. Reuses the existing action=draft backend flow.
  // Disabled in edit mode — never create a new draft from an existing voucher.
  // Browser refresh is primarily handled by sessionStorage above.
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
    function autoSaveDraft(ev) {
      if (submittedRef.current || autoDraftedRef.current) return
      if (!formRef.current || !hasMeaningfulInput()) return
      if (ev?.persisted) return
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
    setItems((prev) => {
      if (prev.length > 1) return prev.filter((it) => it.key !== key)
      // Last remaining row: clear it instead of removing the only line.
      return [{ ...newItem(), payment_type: '' }]
    })
  }

  function togglePO(id) {
    const n = Number(id)
    if (!(n > 0)) return
    setSelectedPO((prev) => {
      const next = new Set(prev)
      if (next.has(n)) next.delete(n)
      else next.add(n)
      return next
    })
  }

  function closePoModal() {
    setPoOpen(false)
    setPoSearch('')
    setPoViewDetail(null)
    setPoViewError('')
    setPoViewLoading(false)
  }

  async function openPoView(poId) {
    const id = Number(poId)
    if (!(id > 0)) return
    setPoViewError('')
    setPoViewLoading(true)
    // Show document frame immediately; use list row as fallback header while detail loads.
    const listRow = purchaseOrders.find((po) => Number(po.id) === id) || null
    setPoViewDetail({
      id,
      po_number: listRow?.po_number || `PO #${id}`,
      supplier_name: listRow?.supplier_name || '',
      status: listRow?.status || '',
      document_url: poDocumentHref(id),
      items: [],
    })
    try {
      const fd = new FormData()
      fd.append('action', 'ajax_view_po')
      fd.append('po_id', String(id))
      const res = await fetch(CFG.postUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      const data = await res.json()
      if (!data.success || !data.po) {
        // Keep document iframe even if summary AJAX fails.
        setPoViewDetail((prev) => ({
          ...(prev || {}),
          id,
          document_url: poDocumentHref(id),
        }))
        if (!poDocumentHref(id)) {
          setPoViewError(data.message || 'Could not load purchase order.')
        }
        return
      }
      setPoViewDetail({
        ...data.po,
        document_url: poDocumentHref(id),
      })
    } catch {
      if (!poDocumentHref(id)) {
        setPoViewError('Network error. Please try again.')
        setPoViewDetail(null)
      }
    } finally {
      setPoViewLoading(false)
    }
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
  function fileKey(f) {
    return `${f.name}__${f.size}__${f.lastModified || 0}`
  }
  function syncFileInput(list) {
    if (!fileInputRef.current) return
    const dt = new DataTransfer()
    list.forEach((f) => dt.items.add(f))
    fileInputRef.current.files = dt.files
  }
  function onFileChange(e) {
    const picked = Array.from(e.target.files || [])
    if (picked.length === 0) return
    const existing = new Set(files.map((f) => fileKey(f)))
    const merged = [...files]
    const newKeys = []
    picked.forEach((f) => {
      const key = fileKey(f)
      if (!existing.has(key)) {
        merged.push(f)
        existing.add(key)
        newKeys.push(key)
      }
    })
    if (newKeys.length > 0) {
      setFileStatus((prev) => {
        const next = { ...prev }
        newKeys.forEach((k) => { next[k] = 'loading' })
        return next
      })
    }
    setFiles(merged)
    // Reset then re-apply: clearing alone would wipe the FileList before submit.
    e.target.value = ''
    syncFileInput(merged)
  }
  function markFileReady(key) {
    setFileStatus((prev) => {
      if (prev[key] === 'ready') return prev
      return { ...prev, [key]: 'ready' }
    })
  }
  function removeFile(idx) {
    const removed = files[idx]
    const next = files.filter((_, i) => i !== idx)
    setFiles(next)
    syncFileInput(next)
    if (removed) {
      const key = fileKey(removed)
      setFileStatus((prev) => {
        if (!(key in prev)) return prev
        const copy = { ...prev }
        delete copy[key]
        return copy
      })
    }
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
    if (isStockPurchase && !hasLinkedPo)
      return 'Please select at least one Purchase Order for Stock Purchase vouchers.'
    if (isGeneralPurpose && !hasSupportingFiles)
      return 'Please upload at least one supporting file for General Payment vouchers.'
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
    clearCreateDraft()
    if (actionInputRef.current) {
      actionInputRef.current.value = IS_EDIT ? 'update' : 'create'
    }
    // Ensure React-managed File objects are actually on the input before native submit.
    syncFileInput(files)
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
    ...(isStockPurchase ? { purchaseOrder: hasLinkedPo } : {}),
    ...(isGeneralPurpose ? { supportingFiles: hasSupportingFiles } : {}),
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
      {IS_EDIT && CFG.voucherNo ? (
        <div className="cv-topbar">
          <div className="cv-topbar-meta">
            <span className="cv-pill">{CFG.voucherNo}</span>
            {CFG.statusLabel ? <span className="cv-pill cv-pill--muted">{CFG.statusLabel}</span> : null}
          </div>
        </div>
      ) : null}

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
        <input type="hidden" name="linked_stock_po_ids" value={isStockPurchase ? linkedPoIdsCsv : ''} readOnly />
        <input type="hidden" name="linked_stock_po_id" value={isStockPurchase ? linkedStockPoId : ''} readOnly />
        <input type="hidden" name="supporting_documents" value={attachmentCount} readOnly />

        <div className="cv-main">
          {/* GENERAL */}
          <section id="cv-general" className="cv-section">
            <header className="cv-section-head">
              <h2>General Information</h2>
              <p>Who is being paid, in which currency, and when.</p>
            </header>
            <div className="cv-card">
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

              <div className="cv-row cv-row--split">
                <div className="cv-split-col">
                  <label className="cv-label">Currency</label>
                  <div className="cv-field">
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
                <div className="cv-split-col">
                  <label className="cv-label">Date {mark(filled.date)}</label>
                  <div className="cv-field">
                    <input type="date" name="date_created" className={`cv-input${invCls(filled.date)}`} value={dateCreated} onChange={(e) => setDateCreated(e.target.value)} required disabled={fieldsLocked} />
                    {fieldErr(filled.date, 'Please choose a date.')}
                  </div>
                </div>
              </div>

              <div className="cv-row cv-row--split">
                <div className="cv-split-col">
                  <label className="cv-label">Purpose</label>
                  <div className="cv-field">
                    <select name="voucher_purpose" className={`cv-select${purpose ? ' is-valid' : ''}`} value={purpose} onChange={(e) => setPurpose(e.target.value)}>
                      {CFG.purposes.map((p) => (<option key={p.value} value={p.value}>{p.label}</option>))}
                    </select>
                  </div>
                </div>
                {CFG.canRestrict ? (
                  <div className="cv-split-col">
                    <label className="cv-label">Restricted</label>
                    <div className="cv-field">
                      <label className="cv-check">
                        <input type="checkbox" name="is_restricted" value="1" checked={isRestricted} onChange={(e) => setIsRestricted(e.target.checked)} disabled={fieldsLocked} />
                        <span>Mark this voucher as restricted (visible to Finance/Admin only)</span>
                      </label>
                    </div>
                  </div>
                ) : (
                  <div className="cv-split-col" aria-hidden="true" />
                )}
              </div>
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
            </div>
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
                  <div className="cv-item-grid">
                    <div className="cv-item-sn" aria-label={`Item ${idx + 1}`}>
                      <span className="cv-item-sn-num">{idx + 1}</span>
                    </div>
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
                    {!fieldsLocked && (
                      <div className="cv-item-actions">
                        <button type="button" className="cv-item-del" onClick={() => removeItem(it.key)} aria-label="Remove item" title="Remove item">
                          <Trash2 size={16} />
                        </button>
                      </div>
                    )}
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
            <div className="cv-card">
              <div className="cv-row cv-row--top">
                <label className="cv-label">Description {mark(filled.description)}</label>
                <div className="cv-field">
                  <textarea
                    name="description"
                    className={`cv-textarea${invCls(filled.description)}`}
                    rows={5}
                    placeholder="Describe what this voucher is for..."
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    required
                    disabled={fieldsLocked}
                  />
                  {fieldErr(filled.description, 'Please enter a description.')}
                </div>
              </div>
            </div>
          </section>

          {/* APPROVALS */}
          <section id="cv-approvals" className="cv-section">
            <header className="cv-section-head">
              <h2>Approval Routing</h2>
              <p>Choose who applies for, reviews and checks this voucher.</p>
            </header>

            <div className="cv-card">
              <div className="cv-row cv-row--split cv-row--split-3">
                <div className="cv-split-col">
                  <label className="cv-label">Applicant {mark(filled.applicant)}</label>
                  <div className="cv-field">
                    <select name="applicant" className={`cv-select${invCls(filled.applicant)}`} value={applicant} onChange={(e) => setApplicant(e.target.value)} required disabled={fieldsLocked}>
                      <option value="">Select...</option>
                      {CFG.users.map((u) => (<option key={u.full_name} value={u.full_name}>{u.full_name}</option>))}
                    </select>
                    {fieldErr(filled.applicant, 'Please select an applicant.')}
                  </div>
                </div>
                <div className="cv-split-col">
                  <label className="cv-label">Department Manager {mark(filled.departmentManager)}</label>
                  <div className="cv-field">
                    <select name="department_manager" className={`cv-select${invCls(filled.departmentManager)}`} value={departmentManager} onChange={(e) => setDepartmentManager(e.target.value)} required disabled={fieldsLocked}>
                      <option value="">Select...</option>
                      {CFG.users.map((u) => (<option key={u.full_name} value={u.full_name}>{u.full_name}</option>))}
                    </select>
                    {fieldErr(filled.departmentManager, 'Please select a department manager.')}
                  </div>
                </div>
                <div className="cv-split-col">
                  <label className="cv-label">Checked By {mark(filled.checkedBy)}</label>
                  <div className="cv-field">
                    <select name="checked_by" className={`cv-select${invCls(filled.checkedBy)}`} value={checkedBy} onChange={(e) => setCheckedBy(e.target.value)} required disabled={fieldsLocked}>
                      <option value="">Select...</option>
                      {CFG.financeUsers.map((u) => (<option key={u.full_name} value={u.full_name}>{u.full_name}</option>))}
                    </select>
                    {fieldErr(filled.checkedBy, 'Please select who checks this voucher.')}
                  </div>
                </div>
              </div>
            </div>
          </section>

          {/* ATTACHMENTS */}
          <section id="cv-attachments" className="cv-section">
            <header className="cv-section-head">
              <h2>Attachments</h2>
              <p>
                {isGeneralPurpose
                  ? 'Supporting files are required for General Payment vouchers (PDF, images, Office files).'
                  : 'Upload supporting documents (PDF, images, Office files).'}
              </p>
            </header>
            <div className="cv-card">
              {isStockPurchase && (
                <div className="cv-row cv-row--top">
                  <label className="cv-label">Purchase Order {mark(hasLinkedPo)}</label>
                  <div className="cv-field">
                    <p className="cv-attach-hint">Required for Stock Purchase vouchers. Select one or more POs from the system.</p>
                    <div className={`cv-po-trigger-wrap${showErrors && !hasLinkedPo ? ' is-invalid' : ''}${hasLinkedPo ? ' is-valid' : ''}`}>
                      <button
                        type="button"
                        className={`cv-so-toggle cv-po-trigger${invCls(hasLinkedPo)}`}
                        onClick={() => {
                          if (fieldsLocked) return
                          setPoSearch('')
                          setPoViewDetail(null)
                          setPoViewError('')
                          setPoViewLoading(false)
                          setPoOpen(true)
                        }}
                        disabled={fieldsLocked}
                      >
                        <Link2 size={15} />
                        <span className="cv-so-toggle-label">
                          {selectedPoRows.length === 0
                            ? 'Select purchase order(s)'
                            : selectedPoRows.length === 1
                              ? `${selectedPoRows[0].po_number}${selectedPoRows[0].supplier_name ? ` — ${selectedPoRows[0].supplier_name}` : ''}`
                              : `${selectedPoRows.length} purchase orders linked`}
                        </span>
                        <ChevronDown size={16} className="cv-so-chevron" />
                      </button>
                      {hasLinkedPo && !fieldsLocked && (
                        <>
                          {selectedPoRows.length === 1 && (
                            <button
                              type="button"
                              className="cv-po-clear-btn"
                              onClick={() => {
                                setPoSearch('')
                                setPoOpen(true)
                                openPoView(selectedPoRows[0].id)
                              }}
                              title={`View ${selectedPoRows[0].po_number}`}
                              aria-label={`View ${selectedPoRows[0].po_number}`}
                            >
                              <Eye size={14} />
                            </button>
                          )}
                          <button
                            type="button"
                            className="cv-po-clear-btn"
                            onClick={() => setSelectedPO(new Set())}
                            title="Clear Purchase Orders"
                            aria-label="Clear Purchase Orders"
                          >
                            <X size={14} />
                          </button>
                        </>
                      )}
                    </div>
                    {selectedPoRows.length > 1 && (
                      <ul className="cv-po-chips">
                        {selectedPoRows.map((po) => (
                          <li key={po.id} className="cv-po-chip">
                            <span className="cv-po-chip-no">{po.po_number}</span>
                            <button
                              type="button"
                              className="cv-po-chip-view"
                              title={`View ${po.po_number}`}
                              onClick={() => {
                                setPoSearch('')
                                setPoOpen(true)
                                openPoView(po.id)
                              }}
                            >
                              <Eye size={12} />
                            </button>
                            {!fieldsLocked && (
                              <button
                                type="button"
                                className="cv-po-chip-rm"
                                onClick={() => togglePO(po.id)}
                                title="Remove"
                                aria-label={`Remove ${po.po_number}`}
                              >
                                <X size={12} />
                              </button>
                            )}
                          </li>
                        ))}
                      </ul>
                    )}
                    {fieldErr(hasLinkedPo, 'Please select at least one Purchase Order for Stock Purchase.')}
                  </div>
                </div>
              )}

              <div className="cv-row cv-row--top">
                <label className="cv-label">
                  Supporting Files{isGeneralPurpose ? <> {mark(hasSupportingFiles)}</> : null}
                </label>
                <div className={`cv-field${isGeneralPurpose && showErrors && !hasSupportingFiles ? ' is-invalid' : ''}${isGeneralPurpose && hasSupportingFiles ? ' is-valid' : ''}`}>
                  {isGeneralPurpose && (
                    <p className="cv-attach-hint">Required for General Payment vouchers. Attach invoices, receipts, or other proof.</p>
                  )}
                  {selectedPoRows.length > 0 && (
                    <ul className="cv-file-list cv-file-list--existing">
                      {selectedPoRows.map((po) => (
                        <li key={`po-att-${po.id}`}>
                          <FileText size={13} className="cv-file-ic" />
                          <a
                            className="cv-file-nm"
                            href={`${CFG.poDocumentUrl || 'create-voucher-ui/po-document.php'}?id=${po.id}`}
                            target="_blank"
                            rel="noreferrer"
                            title={po.supplier_name ? `${po.po_number} — ${po.supplier_name}` : po.po_number}
                          >
                            {po.po_number}.pdf
                          </a>
                          <span className="cv-file-sz">Purchase Order</span>
                        </li>
                      ))}
                    </ul>
                  )}
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
                      <span className="cv-file-title">{isGeneralPurpose ? 'Upload a required file' : 'Upload a file'}</span>
                      <span className="cv-file-sub">Click to browse, or drag &amp; drop files here</span>
                    </label>
                  )}
                  {fieldErr(isGeneralPurpose ? hasSupportingFiles : true, 'Please upload at least one supporting file.')}
                  {files.length > 0 && (
                    <ul className="cv-file-list">
                      {files.map((f, i) => {
                        const key = fileKey(f)
                        const status = fileStatus[key] || 'ready'
                        const loading = status === 'loading'
                        return (
                          <li key={`${key}-${i}`} className={`cv-file-row${loading ? ' is-loading' : ' is-ready'}`}>
                            <div className="cv-file-row-main">
                              <FileText size={13} className="cv-file-ic" />
                              <span className="cv-file-nm" title={f.name}>{f.name}</span>
                              <span className="cv-file-sz">{(f.size / 1024).toFixed(0)} KB</span>
                              <span className={`cv-file-status${loading ? '' : ' is-ready'}`}>
                                {loading ? 'Attaching…' : 'Ready'}
                              </span>
                              <button
                                type="button"
                                className="cv-file-rm"
                                onClick={() => removeFile(i)}
                                title="Remove this file"
                                aria-label={`Remove ${f.name}`}
                              >
                                <X size={14} />
                              </button>
                            </div>
                            <div className="cv-file-progress" aria-hidden="true">
                              <span
                                className={`cv-file-progress-bar${loading ? ' is-animating' : ' is-done'}`}
                                onAnimationEnd={() => {
                                  if (loading) markFileReady(key)
                                }}
                              />
                            </div>
                          </li>
                        )
                      })}
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
              <Info size={13} /> Your progress is kept if you refresh this page. Leaving without submitting also saves an editable draft.
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
                {payeeSaving ? 'Saving…' : 'Add Payee'}
              </button>
            </div>
          </div>
        </div>
      )}

      {poOpen && createPortal(
        <div
          className="cv-modal-overlay"
          onMouseDown={(e) => { if (e.target === e.currentTarget) closePoModal() }}
        >
          <div
            className={`cv-modal cv-modal--po${poViewDetail || poViewLoading || poViewError ? ' cv-modal--po-detail' : ''}`}
            role="dialog"
            aria-modal="true"
            aria-labelledby="cv-po-modal-title"
          >
            <div className="cv-modal-head">
              {(poViewDetail || poViewLoading || poViewError) ? (
                <button
                  type="button"
                  className="cv-po-detail-back cv-po-detail-back--head"
                  onClick={() => {
                    setPoViewDetail(null)
                    setPoViewError('')
                    setPoViewLoading(false)
                  }}
                >
                  <ArrowLeft size={16} />
                  <span>Back to list</span>
                </button>
              ) : (
                <h3 id="cv-po-modal-title">Select Purchase Orders</h3>
              )}
              {(poViewDetail || poViewLoading || poViewError) && (
                <h3 id="cv-po-modal-title" className="cv-po-modal-title-center">
                  {poViewDetail?.po_number || 'Purchase Order'}
                  {poViewDetail?.status ? (
                    <span className="cv-po-detail-status cv-po-detail-status--inline">{poViewDetail.status}</span>
                  ) : null}
                </h3>
              )}
              <button type="button" className="cv-alert-x" onClick={closePoModal} aria-label="Close">
                <X size={18} />
              </button>
            </div>

            {(poViewLoading || poViewError || poViewDetail) ? (
              <div className="cv-po-detail">
                {poViewLoading && !poViewDetail?.document_url && (
                  <div className="cv-po-detail-loading">
                    <Loader2 size={22} className="cv-spin" />
                    <span>Loading purchase order…</span>
                  </div>
                )}

                {!poViewLoading && poViewError && !poViewDetail?.document_url && (
                  <div className="cv-po-detail-error">{poViewError}</div>
                )}

                {poViewDetail && poViewDetail.document_url ? (
                  <div className="cv-po-doc-frame-wrap">
                    {poViewLoading && (
                      <div className="cv-po-doc-frame-loading">
                        <Loader2 size={18} className="cv-spin" />
                        Loading document…
                      </div>
                    )}
                    <iframe
                      key={poViewDetail.document_url}
                      className="cv-po-doc-frame"
                      title={`Purchase Order ${poViewDetail.po_number}`}
                      src={poViewDetail.document_url}
                    />
                  </div>
                ) : null}

                {poViewDetail && !poViewDetail.document_url ? (
                  <>
                    <div className="cv-po-detail-hero">
                      <div>
                        <div className="cv-po-detail-no">{poViewDetail.po_number}</div>
                        <div className="cv-po-detail-supplier">{poViewDetail.supplier_name || '—'}</div>
                      </div>
                      <span className="cv-po-detail-status">{poViewDetail.status || '—'}</span>
                    </div>
                    <div className="cv-po-detail-meta">
                      <div>
                        <span className="cv-po-detail-k">Type</span>
                        <span className="cv-po-detail-v">{poViewDetail.purchase_type || '—'}</span>
                      </div>
                      <div>
                        <span className="cv-po-detail-k">Currency</span>
                        <span className="cv-po-detail-v">{poViewDetail.currency || '—'}</span>
                      </div>
                      <div>
                        <span className="cv-po-detail-k">Created</span>
                        <span className="cv-po-detail-v">
                          {poViewDetail.created_at
                            ? String(poViewDetail.created_at).slice(0, 10)
                            : '—'}
                        </span>
                      </div>
                    </div>
                    <div className="cv-po-detail-items-head">Line items</div>
                    {(poViewDetail.items || []).length === 0 ? (
                      <div className="cv-so-empty">No line items on this purchase order</div>
                    ) : (
                      <div className="cv-po-detail-table-wrap">
                        <table className="cv-po-detail-table">
                          <thead>
                            <tr>
                              <th>Item</th>
                              <th>Qty</th>
                              <th>Unit</th>
                              <th>Total</th>
                            </tr>
                          </thead>
                          <tbody>
                            {poViewDetail.items.map((it) => (
                              <tr key={it.id || `${it.product_name}-${it.quantity}`}>
                                <td>
                                  <div className="cv-po-detail-item-name">{it.product_name}</div>
                                  {it.product_code ? (
                                    <div className="cv-po-detail-item-code">{it.product_code}</div>
                                  ) : null}
                                </td>
                                <td>{Number(it.quantity || 0).toLocaleString()}</td>
                                <td>{formatMoney(poViewDetail.currency, it.unit_price)}</td>
                                <td>{formatMoney(poViewDetail.currency, it.line_total)}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                    <div className="cv-po-detail-totals">
                      <span>Total</span>
                      <strong>{formatMoney(poViewDetail.currency, poViewDetail.total_amount)}</strong>
                    </div>
                  </>
                ) : null}
              </div>
            ) : (
              <>
                <div className="cv-po-modal-search">
                  <Search size={16} />
                  <input
                    type="text"
                    className="cv-po-modal-search-input"
                    placeholder="Search purchase orders..."
                    value={poSearch}
                    onChange={(e) => setPoSearch(e.target.value)}
                    autoFocus
                  />
                </div>
                <div className="cv-po-modal-list">
                  {purchaseOrders.length === 0 ? (
                    <div className="cv-so-empty">No available purchase orders</div>
                  ) : filteredPO.length === 0 ? (
                    <div className="cv-so-empty">No purchase orders found</div>
                  ) : (
                    <>
                      {!poSearch.trim() && (
                        <div className="cv-po-modal-section-label">Current &amp; recent purchase orders</div>
                      )}
                      {filteredPO.map((po) => {
                        const checked = selectedPO.has(Number(po.id))
                        return (
                          <div
                            key={po.id}
                            className={`cv-po-modal-item${checked ? ' is-checked' : ''}`}
                          >
                            <label className="cv-po-modal-select">
                              <input
                                type="checkbox"
                                checked={checked}
                                onChange={() => togglePO(po.id)}
                                disabled={fieldsLocked}
                              />
                              <span className="cv-po-modal-no">{po.po_number}</span>
                              <span className="cv-po-modal-supplier">{po.supplier_name || '—'}</span>
                              <span className="cv-po-modal-status">{po.status || ''}</span>
                            </label>
                            <button
                              type="button"
                              className="cv-po-modal-view"
                              title={`View ${po.po_number}`}
                              onClick={() => openPoView(po.id)}
                            >
                              <Eye size={15} />
                              <span>View</span>
                            </button>
                          </div>
                        )
                      })}
                    </>
                  )}
                </div>
              </>
            )}

            <div className="cv-modal-foot">
              {(poViewDetail || poViewLoading || poViewError) ? (
                <>
                  {poViewDetail && !fieldsLocked && (
                    <button
                      type="button"
                      className="cv-btn-cancel"
                      onClick={() => {
                        const id = Number(poViewDetail.id)
                        if (id > 0 && !selectedPO.has(id)) togglePO(id)
                        setPoViewDetail(null)
                        setPoViewError('')
                      }}
                    >
                      {selectedPO.has(Number(poViewDetail.id)) ? 'Selected' : 'Select this PO'}
                    </button>
                  )}
                  <button
                    type="button"
                    className="cv-btn-save"
                    onClick={() => {
                      setPoViewDetail(null)
                      setPoViewError('')
                      setPoViewLoading(false)
                    }}
                  >
                    Done
                  </button>
                </>
              ) : (
                <>
                  {hasLinkedPo && (
                    <button
                      type="button"
                      className="cv-btn-cancel"
                      onClick={() => setSelectedPO(new Set())}
                      disabled={fieldsLocked}
                    >
                      Clear selection
                    </button>
                  )}
                  <span className="cv-po-modal-count">
                    {selectedPO.size > 0
                      ? `${selectedPO.size} selected`
                      : 'None selected'}
                  </span>
                  <button
                    type="button"
                    className="cv-btn-save"
                    onClick={closePoModal}
                  >
                    Done
                  </button>
                </>
              )}
            </div>
          </div>
        </div>,
        document.body,
      )}
    </div>
  )
}
