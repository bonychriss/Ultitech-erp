import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Check, CheckCircle2, ChevronDown, Coins, Landmark, Paperclip, Smartphone, Wallet, X } from 'lucide-react'
import '../pay-modal.css'

const ACCEPTED_PROOF = '.pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx'
const TYPE_LABELS = { bank: 'Bank account', cash: 'Cash account', mobile: 'Mobile money' }
const STOCK_PAYMENT_METHODS = ['Bank Transfer', 'RTGS / SWIFT', 'Cash', 'Mobile Money', 'Cheque']

function PaymentAccountIcon({ type, size, imageUrl }) {
  const [imageFailed, setImageFailed] = useState(false)
  useEffect(() => { setImageFailed(false) }, [imageUrl])
  if (imageUrl && !imageFailed) {
    return <img className="vv-pay-modal__account-logo" src={imageUrl} alt="" loading="lazy" onError={() => setImageFailed(true)} />
  }
  const Icon = type === 'bank' ? Landmark : type === 'mobile' ? Smartphone : type === 'cash' ? Coins : Wallet
  return <Icon size={size} aria-hidden="true" />
}

function toNumber(value) {
  const n = Number(value)
  return Number.isFinite(n) ? n : 0
}

function formatMoney(currency, value) {
  return `${currency} ${toNumber(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function todayIso() {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export default function MarkPaidModal({ voucher, accounts, actionUrl, module, formatAmount, onClose, stockPurchase = false, returnTo = '' }) {
  const fileInputRef = useRef(null)
  const pickerRef = useRef(null)
  const xhrRef = useRef(null)
  const reloadTimerRef = useRef(null)
  const [accountId, setAccountId] = useState('')
  const [accountError, setAccountError] = useState('')
  const [proofName, setProofName] = useState('')
  const [isDragging, setIsDragging] = useState(false)
  const [uploadState, setUploadState] = useState('idle')
  const [uploadProgress, setUploadProgress] = useState(0)
  const [uploadError, setUploadError] = useState('')
  const isUploading = uploadState === 'uploading' || uploadState === 'saving'

  const currency = voucher.currency || 'TZS'
  const total = toNumber(voucher.total_amount)
  const paidSoFar = toNumber(voucher.paid_so_far)
  const balanceDue = Math.max(0, Math.round((total - paidSoFar) * 100) / 100)
  const [amount, setAmount] = useState(balanceDue ? balanceDue.toFixed(2) : '')

  function closeModal() {
    if (!isUploading) onClose()
  }

  useEffect(() => {
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    function onKey(e) {
      if (e.key === 'Escape' && !isUploading) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = prev
      document.removeEventListener('keydown', onKey)
    }
  }, [isUploading, onClose])

  useEffect(() => () => {
    if (xhrRef.current && xhrRef.current.readyState !== XMLHttpRequest.DONE) xhrRef.current.abort()
    if (reloadTimerRef.current) window.clearTimeout(reloadTimerRef.current)
  }, [])

  function setProofFile(file) {
    setProofName(file?.name || '')
    setUploadState(file ? 'ready' : 'idle')
    setUploadProgress(0)
    setUploadError('')
  }

  function handleDrop(event) {
    event.preventDefault()
    setIsDragging(false)
    if (isUploading) return
    const file = event.dataTransfer.files?.[0]
    if (!file || !fileInputRef.current) return
    const transfer = new DataTransfer()
    transfer.items.add(file)
    fileInputRef.current.files = transfer.files
    setProofFile(file)
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (isUploading) return
    const form = event.currentTarget
    if (!accountId) {
      setAccountError('Choose a source account to continue.')
      pickerRef.current?.querySelector('summary')?.focus()
      return
    }
    if (!form.reportValidity()) return

    setAccountError('')
    setUploadError('')

    if (stockPurchase) {
      // The stock purchase endpoint finishes with a redirect back to the dashboard.
      setUploadState('saving')
      form.submit()
      return
    }

    setUploadProgress(0)
    setUploadState('uploading')

    const xhr = new XMLHttpRequest()
    xhrRef.current = xhr
    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) setUploadProgress(Math.round((e.loaded / e.total) * 100))
    })
    xhr.upload.addEventListener('load', () => {
      setUploadProgress(100)
      setUploadState('saving')
    })
    xhr.addEventListener('load', () => {
      xhrRef.current = null
      let result = null
      try {
        result = JSON.parse(xhr.responseText)
      } catch {
        result = null
      }
      if (xhr.status >= 200 && xhr.status < 300 && result?.ok === true) {
        setUploadProgress(100)
        setUploadState('success')
        reloadTimerRef.current = window.setTimeout(() => window.location.reload(), 800)
        return
      }
      setUploadError(result?.error || 'The payment could not be confirmed as saved. Please try again.')
      setUploadState('error')
    })
    xhr.addEventListener('error', () => {
      xhrRef.current = null
      setUploadError('Network error while uploading the payment proof. Please try again.')
      setUploadState('error')
    })
    xhr.addEventListener('abort', () => {
      xhrRef.current = null
      setUploadError('The upload was interrupted. Please try again.')
      setUploadState('error')
    })
    xhr.open('POST', actionUrl)
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
    xhr.setRequestHeader('Accept', 'application/json')
    xhr.send(new FormData(form))
  }

  const rawList = accounts || []
  const list = stockPurchase
    ? [...rawList.filter((a) => !a.currency || a.currency === currency), ...rawList.filter((a) => a.currency && a.currency !== currency)]
    : rawList
  const selected = list.find((a) => String(a.id) === accountId)
  const selectedType = ['bank', 'cash', 'mobile'].includes(selected?.type) ? selected.type : 'cash'
  const payAmount = toNumber(amount)
  const afterBalance = stockPurchase && selected ? toNumber(selected.current_balance) - payAmount : null
  const isPartial = stockPurchase && payAmount > 0 && payAmount < balanceDue - 0.009

  return createPortal(
    <div className="vv-pay-overlay" role="dialog" aria-modal="true" onMouseDown={(e) => { if (e.target === e.currentTarget) closeModal() }}>
      <div className="vv-pay-modal" onMouseDown={(e) => e.stopPropagation()}>
        <header className="vv-pay-modal__header">
          <div className="vv-pay-modal__icon"><CheckCircle2 size={27} strokeWidth={2.2} /></div>
          <div className="vv-pay-modal__heading">
            <h3>Mark Voucher as Paid</h3>
            <p>Select the source account and attach the payment proof.</p>
          </div>
          <button type="button" className="vv-pay-modal__close" onClick={closeModal} disabled={isUploading} aria-label="Close dialog">
            <X size={21} />
          </button>
        </header>
        <div className="vv-pay-modal__summary">
          <div><strong>Voucher:</strong> {voucher.voucher_no}</div>
          <div><strong>{stockPurchase ? 'Supplier' : 'Payee'}:</strong> {voucher.payee_name}</div>
          <div><strong>Amount:</strong> {formatAmount(currency, voucher.total_amount)}</div>
          {stockPurchase && paidSoFar > 0 ? (
            <>
              <div><strong>Already paid:</strong> {formatAmount(currency, paidSoFar)}</div>
              <div><strong>Balance due:</strong> {formatAmount(currency, balanceDue)}</div>
            </>
          ) : null}
        </div>
        <form action={actionUrl} method="POST" encType="multipart/form-data" onSubmit={handleSubmit}>
          <input type="hidden" name="voucher_id" value={voucher.id} />
          {stockPurchase ? null : <input type="hidden" name="mark_paid" value="1" />}
          {stockPurchase && returnTo ? <input type="hidden" name="return_to" value={returnTo} /> : null}
          <input type="hidden" name="account_id" value={accountId} />
          {module ? <input type="hidden" name="module" value={module} /> : null}
          <div className="vv-pay-modal__field">
            <label id="ed-pay-account-label">Source Account</label>
            <details className={`vv-pay-modal__account-picker${accountError ? ' has-error' : ''}`} ref={pickerRef}>
              <summary
                aria-labelledby="ed-pay-account-label ed-pay-account-value"
                aria-invalid={Boolean(accountError)}
                aria-disabled={isUploading}
                onClick={(e) => { if (isUploading) e.preventDefault() }}
              >
                <span className={`vv-pay-modal__account-icon is-${selected ? selectedType : 'cash'}`}>
                  <PaymentAccountIcon type={selected?.type} size={19} imageUrl={selected?.image_url} />
                </span>
                <span className="vv-pay-modal__account-summary" id="ed-pay-account-value">
                  <strong>{selected?.name || 'Select a payment account'}</strong>
                  <small>
                    {selected
                      ? `${TYPE_LABELS[selected.type] || 'Payment account'} · ${formatMoney(selected.currency, selected.current_balance)}`
                      : 'Choose where this payment will be paid from'}
                  </small>
                </span>
                <ChevronDown className="vv-pay-modal__account-chevron" size={18} aria-hidden="true" />
              </summary>
              <div className="vv-pay-modal__account-options">
                {list.length > 0 ? list.map((a) => (
                  <button
                    key={a.id}
                    type="button"
                    className="vv-pay-modal__account-option"
                    disabled={isUploading}
                    aria-current={String(a.id) === accountId ? 'true' : undefined}
                    onClick={() => {
                      setAccountId(String(a.id))
                      setAccountError('')
                      if (pickerRef.current) pickerRef.current.open = false
                    }}
                  >
                    <span className={`vv-pay-modal__account-option-icon is-${a.type}`}>
                      <PaymentAccountIcon type={a.type} size={17} imageUrl={a.image_url} />
                    </span>
                    <span className="vv-pay-modal__account-option-copy">
                      <strong>{a.name}</strong>
                      <small>{TYPE_LABELS[a.type] || 'Payment account'}</small>
                    </span>
                    <span className="vv-pay-modal__account-balance">
                      <strong>{formatMoney(a.currency, a.current_balance)}</strong>
                      {String(a.id) === accountId && <Check size={16} aria-label="Selected" />}
                    </span>
                  </button>
                )) : (
                  <p className="vv-pay-modal__account-empty">No active cash, bank, or mobile-money accounts are available.</p>
                )}
              </div>
            </details>
            {accountError && <span className="vv-pay-modal__account-error" role="alert">{accountError}</span>}
            {afterBalance !== null ? (
              <span className={`vv-pay-modal__hint${afterBalance < 0 ? ' is-negative' : ''}`}>
                Balance after payment: {formatMoney(selected.currency, afterBalance)}
                {selected.currency && selected.currency !== currency ? ' (account currency differs from voucher)' : ''}
              </span>
            ) : null}
          </div>
          {stockPurchase ? (
            <>
              <div className="vv-pay-modal__grid">
                <label className="vv-pay-modal__field">
                  <span className="vv-pay-modal__label">Amount to pay ({currency})</span>
                  <input
                    className="vv-pay-modal__input"
                    type="number"
                    name="payment_amount"
                    required
                    min="0.01"
                    step="0.01"
                    max={balanceDue || undefined}
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                  />
                </label>
                <label className="vv-pay-modal__field">
                  <span className="vv-pay-modal__label">Payment date</span>
                  <input className="vv-pay-modal__input" type="date" name="payment_date" required defaultValue={todayIso()} />
                </label>
                <label className="vv-pay-modal__field">
                  <span className="vv-pay-modal__label">Payment method</span>
                  <select className="vv-pay-modal__input" name="payment_method" required defaultValue="Bank Transfer">
                    {STOCK_PAYMENT_METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
                  </select>
                </label>
                <label className="vv-pay-modal__field">
                  <span className="vv-pay-modal__label">Reference no.</span>
                  <input className="vv-pay-modal__input" type="text" name="payment_reference_no" required placeholder="Bank / SWIFT reference" />
                </label>
              </div>
              {isPartial ? <p className="vv-pay-modal__hint">Partial payment: the voucher stays open until the balance is paid.</p> : null}
              <label className="vv-pay-modal__field">
                <span className="vv-pay-modal__label">Notes (optional)</span>
                <input className="vv-pay-modal__input" type="text" name="payment_notes" placeholder="e.g. first installment" />
              </label>
            </>
          ) : null}
          <div className="vv-pay-modal__field">
            <label>SWIFT Proof (Required)</label>
            <label
              className={`vv-pay-modal__dropzone${isDragging ? ' is-dragging' : ''}${isUploading ? ' is-disabled' : ''}`}
              onDragOver={(e) => { e.preventDefault(); if (!isUploading) setIsDragging(true) }}
              onDragLeave={() => setIsDragging(false)}
              onDrop={handleDrop}
            >
              <input
                ref={fileInputRef}
                type="file"
                name="swift_file"
                required
                disabled={isUploading && !stockPurchase}
                accept={ACCEPTED_PROOF}
                onChange={(e) => setProofFile(e.target.files?.[0])}
              />
              <Paperclip size={27} />
              <span className="vv-pay-modal__file-name">
                {proofName ? <strong>{proofName}</strong> : <><strong>Choose file</strong> or drag and drop</>}
              </span>
            </label>
            {uploadState !== 'idle' && (
              <div className={`vv-pay-modal__upload-status is-${uploadState}`} role={uploadState === 'error' ? 'alert' : 'status'} aria-live="polite">
                {isUploading && <span className="vv-pay-modal__spinner" aria-hidden="true" />}
                {(uploadState === 'ready' || uploadState === 'success') && <CheckCircle2 size={17} aria-hidden="true" />}
                <div className="vv-pay-modal__upload-copy">
                  <span>
                    {uploadState === 'ready' && 'File attached. Click Mark as Paid to upload and save the proof.'}
                    {uploadState === 'uploading' && `Uploading proof… ${uploadProgress}%`}
                    {uploadState === 'saving' && (stockPurchase ? 'Saving payment…' : 'Upload complete. Saving payment and confirming proof…')}
                    {uploadState === 'success' && 'Payment proof saved. Refreshing…'}
                    {uploadState === 'error' && uploadError}
                  </span>
                  {isUploading && (
                    <div className="vv-pay-modal__progress" role="progressbar" aria-label="Payment proof upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow={uploadState === 'saving' ? 100 : uploadProgress}>
                      <span className={uploadState === 'saving' ? 'is-indeterminate' : ''} style={{ width: `${uploadState === 'saving' ? 100 : uploadProgress}%` }} />
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
          <footer className="vv-pay-modal__footer">
            <button type="button" className="vv-pay-modal__button vv-pay-modal__button--cancel" onClick={closeModal} disabled={isUploading}>Cancel</button>
            <button type="submit" className="vv-pay-modal__button vv-pay-modal__button--submit" disabled={isUploading || uploadState === 'success'}>
              {uploadState === 'uploading' ? 'Uploading…' : uploadState === 'saving' ? 'Saving…' : uploadState === 'success' ? 'Paid' : 'Mark as Paid'}
            </button>
          </footer>
        </form>
      </div>
    </div>,
    document.body,
  )
}
