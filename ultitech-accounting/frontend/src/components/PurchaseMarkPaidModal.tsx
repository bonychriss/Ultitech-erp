import { useEffect, useRef, useState, type FormEvent, type DragEvent } from 'react'
import { createPortal } from 'react-dom'
import './purchase-pay-modal.css'

export type PayFinAccount = {
  id: number
  name: string
  type: string
  currency: string
  current_balance: number
  image_url?: string
}

export type PayPriorPayment = {
  id?: number
  amount?: number | string
  date?: string
  paidAt?: string
  account?: string
  account_name?: string
}

export type PayVoucherPayload = {
  id: number
  voucher_no: string
  payee_name: string
  currency: string
  total_amount: number | string
  paid_so_far?: number | string
  voucher_type?: string
}

type Props = {
  voucher: PayVoucherPayload
  accounts: PayFinAccount[]
  priorPayments?: PayPriorPayment[]
  actionUrl: string
  module?: string
  onClose: () => void
  onSuccess: () => void
}

const ACCEPTED_PROOF = '.pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx'
const TYPE_LABELS: Record<string, string> = {
  bank: 'Bank account',
  cash: 'Cash account',
  mobile: 'Mobile money',
}

function toNumber(value: unknown) {
  const n = Number(value)
  return Number.isFinite(n) ? n : 0
}

function formatMoney(currency: string, value: unknown) {
  return `${currency} ${toNumber(value).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

function formatPayDate(value: unknown) {
  const raw = String(value || '').trim()
  if (!raw) return ''
  const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T')
  const d = new Date(normalized.includes('T') ? normalized : `${raw}T00:00:00`)
  if (Number.isNaN(d.getTime())) return raw.slice(0, 10)
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

function IconCheck({ size = 16 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  )
}

function IconCheckCircle({ size = 27 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10" />
      <path d="M9 12l2 2 4-4" />
    </svg>
  )
}

function IconX({ size = 21 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M18 6 6 18" />
      <path d="m6 6 12 12" />
    </svg>
  )
}

function IconChevron({ size = 18 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="vv-pay-modal__account-chevron">
      <path d="m6 9 6 6 6-6" />
    </svg>
  )
}

function IconPaperclip({ size = 27 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48" />
    </svg>
  )
}

function PaymentAccountIcon({ type, size, imageUrl }: { type?: string; size: number; imageUrl?: string }) {
  const [imageFailed, setImageFailed] = useState(false)
  useEffect(() => {
    setImageFailed(false)
  }, [imageUrl])
  if (imageUrl && !imageFailed) {
    return (
      <img
        className="vv-pay-modal__account-logo"
        src={imageUrl}
        alt=""
        loading="lazy"
        onError={() => setImageFailed(true)}
      />
    )
  }
  const kind = type === 'bank' || type === 'mobile' || type === 'cash' ? type : 'cash'
  if (kind === 'bank') {
    return (
      <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M3 21h18" />
        <path d="M3 10h18" />
        <path d="M5 6l7-3 7 3" />
        <path d="M4 10v11" />
        <path d="M20 10v11" />
        <path d="M8 14v3" />
        <path d="M12 14v3" />
        <path d="M16 14v3" />
      </svg>
    )
  }
  if (kind === 'mobile') {
    return (
      <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <rect x="7" y="2" width="10" height="20" rx="2" />
        <path d="M11 18h2" />
      </svg>
    )
  }
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="8" cy="8" r="3" />
      <path d="M12 8h8" />
      <circle cx="16" cy="16" r="3" />
      <path d="M4 16h8" />
    </svg>
  )
}

export function PurchaseMarkPaidModal({
  voucher,
  accounts,
  priorPayments = [],
  actionUrl,
  module = 'purchases',
  onClose,
  onSuccess,
}: Props) {
  const fileInputRef = useRef<HTMLInputElement | null>(null)
  const pickerRef = useRef<HTMLDetailsElement | null>(null)
  const xhrRef = useRef<XMLHttpRequest | null>(null)
  const doneTimerRef = useRef<number | null>(null)

  const totalAmount = toNumber(voucher.total_amount)
  const priors = Array.isArray(priorPayments)
    ? priorPayments.filter((row) => toNumber(row?.amount) > 0.009)
    : []
  const paidSoFar = Math.max(
    0,
    priors.length > 0
      ? priors.reduce((sum, row) => sum + toNumber(row?.amount), 0)
      : toNumber(voucher.paid_so_far),
  )
  const balanceDue = Math.max(0, Math.round((totalAmount - paidSoFar) * 100) / 100)
  const defaultPayAmount = balanceDue > 0 ? balanceDue : totalAmount
  const currency = String(voucher.currency || 'TZS')

  const [accountId, setAccountId] = useState('')
  const [accountError, setAccountError] = useState('')
  const [payAmount, setPayAmount] = useState(
    defaultPayAmount > 0
      ? defaultPayAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/,/g, '')
      : '',
  )
  const [amountError, setAmountError] = useState('')
  const [proofName, setProofName] = useState('')
  const [isDragging, setIsDragging] = useState(false)
  const [uploadState, setUploadState] = useState<'idle' | 'ready' | 'uploading' | 'saving' | 'success' | 'error'>('idle')
  const [uploadProgress, setUploadProgress] = useState(0)
  const [uploadError, setUploadError] = useState('')
  const isUploading = uploadState === 'uploading' || uploadState === 'saving'
  const enteredAmount = toNumber(payAmount)
  const amountMeetsBalance = enteredAmount > 0 && enteredAmount >= balanceDue - 0.009

  function closeModal() {
    if (!isUploading) onClose()
  }

  useEffect(() => {
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !isUploading) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = prev
      document.removeEventListener('keydown', onKey)
    }
  }, [isUploading, onClose])

  useEffect(
    () => () => {
      if (xhrRef.current && xhrRef.current.readyState !== XMLHttpRequest.DONE) {
        xhrRef.current.abort()
      }
      if (doneTimerRef.current) window.clearTimeout(doneTimerRef.current)
    },
    [],
  )

  function setProofFile(file?: File | null) {
    setProofName(file?.name || '')
    setUploadState(file ? 'ready' : 'idle')
    setUploadProgress(0)
    setUploadError('')
  }

  function handleDrop(event: DragEvent) {
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

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (isUploading) return
    const form = event.currentTarget
    if (!accountId) {
      setAccountError('Choose a source account to continue.')
      pickerRef.current?.querySelector('summary')?.focus()
      return
    }
    const amountValue = toNumber(payAmount)
    if (!(amountValue > 0)) {
      setAmountError('Enter a valid amount to pay.')
      return
    }
    if (balanceDue > 0 && amountValue > balanceDue + 0.009) {
      setAmountError(`Amount cannot exceed the balance due of ${formatMoney(currency, balanceDue)}.`)
      return
    }
    if (!form.reportValidity()) return

    setAccountError('')
    setAmountError('')
    setUploadError('')
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
      let destination: URL | null = null
      try {
        destination = new URL(xhr.responseURL || '', window.location.origin)
      } catch {
        destination = null
      }

      const href = `${destination?.href || xhr.responseURL || ''}`
      const body = String(xhr.responseText || '')
      const sameOrigin = !destination || destination.origin === window.location.origin
      const onVoucherPage =
        !!destination && /view-voucher\.php$/i.test(destination.pathname) && destination.searchParams.get('id') === String(voucher.id)
      const paidFlag =
        !!destination &&
        (destination.searchParams.get('paid') === '1' || destination.searchParams.has('deducted'))
      const paidConfirmed =
        sameOrigin && (paidFlag || (onVoucherPage && /paid=1|deducted=|marked as paid/i.test(`${href}\n${body}`)))

      const textError =
        xhr.status >= 400 && body && !body.trim().startsWith('<') ? body.trim().slice(0, 300) : ''

      if (xhr.status >= 200 && xhr.status < 300 && paidConfirmed) {
        setUploadProgress(100)
        setUploadState('success')
        doneTimerRef.current = window.setTimeout(() => {
          onSuccess()
        }, 700)
        return
      }

      setUploadError(
        textError ||
          (xhr.status >= 400
            ? 'The payment could not be saved. Please try again.'
            : 'Payment may have been saved, but the confirmation redirect was unexpected. Refresh the list to verify.'),
      )
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
    xhr.send(new FormData(form))
  }

  const selected = accounts.find((a) => String(a.id) === accountId)
  const selectedType = ['bank', 'cash', 'mobile'].includes(selected?.type || '')
    ? (selected?.type as string)
    : 'cash'

  return createPortal(
    <div
      className="vv-pay-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="ua-purchase-pay-title"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) closeModal()
      }}
    >
      <div className="vv-pay-modal" onMouseDown={(e) => e.stopPropagation()}>
        <header className="vv-pay-modal__header">
          <div className="vv-pay-modal__icon">
            <IconCheckCircle />
          </div>
          <div className="vv-pay-modal__heading">
            <h3 id="ua-purchase-pay-title">Mark Voucher as Paid</h3>
            <p>Enter the amount to pay, select the source account, and attach the payment proof.</p>
          </div>
          <button
            type="button"
            className="vv-pay-modal__close"
            onClick={closeModal}
            disabled={isUploading}
            aria-label="Close dialog"
          >
            <IconX />
          </button>
        </header>

        <div className="vv-pay-modal__summary">
          <div>
            <strong>Voucher:</strong> {voucher.voucher_no}
          </div>
          <div>
            <strong>Supplier:</strong> {voucher.payee_name}
          </div>
          <div>
            <strong>Voucher total:</strong> {formatMoney(currency, totalAmount)}
          </div>
          {paidSoFar > 0.009 ? (
            <div>
              <strong>Paid so far:</strong> {formatMoney(currency, paidSoFar)}
            </div>
          ) : null}
          <div>
            <strong>Balance due:</strong> {formatMoney(currency, balanceDue)}
          </div>
        </div>

        {priors.length > 0 ? (
          <div className="vv-pay-modal__prior" aria-label="Previous payments">
            <div className="vv-pay-modal__prior-title">
              Previous payment{priors.length > 1 ? 's' : ''}
            </div>
            <ul className="vv-pay-modal__prior-list">
              {priors.map((row, idx) => {
                const when = formatPayDate(row?.paidAt || row?.date)
                const accountName = String(row?.account || row?.account_name || '').trim()
                return (
                  <li key={`${row?.id || row?.date || 'pay'}-${idx}`}>
                    <span className="vv-pay-modal__prior-amount">
                      {formatMoney(currency, row?.amount)}
                    </span>
                    <span className="vv-pay-modal__prior-meta">
                      {[when ? `on ${when}` : '', accountName ? `via ${accountName}` : '']
                        .filter(Boolean)
                        .join(' · ') || `Installment ${idx + 1}`}
                    </span>
                  </li>
                )
              })}
            </ul>
          </div>
        ) : null}

        <form action={actionUrl} method="POST" encType="multipart/form-data" onSubmit={handleSubmit}>
          <input type="hidden" name="voucher_id" value={voucher.id} />
          <input type="hidden" name="mark_paid" value="1" />
          <input type="hidden" name="account_id" value={accountId} />
          <input type="hidden" name="module" value={module} />

          <div className="vv-pay-modal__field">
            <label htmlFor="ua-pay-amount">Amount to pay</label>
            <div className="vv-pay-modal__amount-wrap">
              <span className="vv-pay-modal__amount-currency" aria-hidden="true">
                {currency}
              </span>
              <input
                id="ua-pay-amount"
                type="number"
                name="payment_amount"
                className={`vv-pay-modal__amount-input${amountError ? ' has-error' : ''}${
                  amountMeetsBalance ? ' is-full' : ' is-partial'
                }`}
                min="0.01"
                step="0.01"
                max={balanceDue > 0 ? balanceDue : undefined}
                value={payAmount}
                required
                disabled={isUploading}
                onChange={(e) => {
                  setPayAmount(e.target.value)
                  setAmountError('')
                }}
              />
            </div>
            <small className="vv-pay-modal__amount-hint">
              {amountMeetsBalance
                ? 'Full payment — voucher (and linked purchase, if any) will be marked paid.'
                : 'Partial payment — remaining balance stays open for later.'}
            </small>
            {amountError ? (
              <span className="vv-pay-modal__account-error" role="alert">
                {amountError}
              </span>
            ) : null}
          </div>

          <div className="vv-pay-modal__field">
            <label id="ua-pay-account-label">Source Account</label>
            <details
              className={`vv-pay-modal__account-picker${accountError ? ' has-error' : ''}`}
              ref={pickerRef}
            >
              <summary
                aria-labelledby="ua-pay-account-label ua-pay-account-value"
                aria-invalid={Boolean(accountError)}
                aria-disabled={isUploading}
                onClick={(e) => {
                  if (isUploading) e.preventDefault()
                }}
              >
                <span className={`vv-pay-modal__account-icon is-${selected ? selectedType : 'cash'}`}>
                  <PaymentAccountIcon type={selected?.type} size={19} imageUrl={selected?.image_url} />
                </span>
                <span className="vv-pay-modal__account-summary" id="ua-pay-account-value">
                  <strong>{selected?.name || 'Select a payment account'}</strong>
                  <small>
                    {selected
                      ? `${TYPE_LABELS[selected.type] || 'Payment account'} · ${formatMoney(
                          selected.currency,
                          selected.current_balance,
                        )}`
                      : 'Choose where this payment will be paid from'}
                  </small>
                </span>
                <IconChevron />
              </summary>
              <div className="vv-pay-modal__account-options">
                {accounts.length > 0 ? (
                  accounts.map((a) => (
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
                        {String(a.id) === accountId ? (
                          <span aria-label="Selected">
                            <IconCheck />
                          </span>
                        ) : null}
                      </span>
                    </button>
                  ))
                ) : (
                  <p className="vv-pay-modal__account-empty">
                    No active cash, bank, or mobile-money accounts are available.
                  </p>
                )}
              </div>
            </details>
            {accountError ? (
              <span className="vv-pay-modal__account-error" role="alert">
                {accountError}
              </span>
            ) : null}
          </div>

          <div className="vv-pay-modal__field">
            <label>SWIFT Proof (Required)</label>
            <label
              className={`vv-pay-modal__dropzone${isDragging ? ' is-dragging' : ''}${
                isUploading ? ' is-disabled' : ''
              }`}
              onDragOver={(e) => {
                e.preventDefault()
                if (!isUploading) setIsDragging(true)
              }}
              onDragLeave={() => setIsDragging(false)}
              onDrop={handleDrop}
            >
              <input
                ref={fileInputRef}
                type="file"
                name="swift_file"
                required
                disabled={isUploading}
                accept={ACCEPTED_PROOF}
                onChange={(e) => setProofFile(e.target.files?.[0])}
              />
              <IconPaperclip />
              <span className="vv-pay-modal__file-name">
                {proofName ? (
                  <strong>{proofName}</strong>
                ) : (
                  <>
                    <strong>Choose file</strong> or drag and drop
                  </>
                )}
              </span>
            </label>
            {uploadState !== 'idle' ? (
              <div
                className={`vv-pay-modal__upload-status is-${uploadState}`}
                role={uploadState === 'error' ? 'alert' : 'status'}
                aria-live="polite"
              >
                {isUploading ? <span className="vv-pay-modal__spinner" aria-hidden="true" /> : null}
                {(uploadState === 'ready' || uploadState === 'success') && <IconCheckCircle size={17} />}
                <div className="vv-pay-modal__upload-copy">
                  <span>
                    {uploadState === 'ready' &&
                      'File attached. Click Mark as Paid to upload and save the proof.'}
                    {uploadState === 'uploading' && `Uploading proof… ${uploadProgress}%`}
                    {uploadState === 'saving' &&
                      'Upload complete. Saving payment and confirming proof…'}
                    {uploadState === 'success' && 'Payment saved. Updating list…'}
                    {uploadState === 'error' && uploadError}
                  </span>
                  {isUploading ? (
                    <div
                      className="vv-pay-modal__progress"
                      role="progressbar"
                      aria-label="Payment proof upload progress"
                      aria-valuemin={0}
                      aria-valuemax={100}
                      aria-valuenow={uploadState === 'saving' ? 100 : uploadProgress}
                    >
                      <span
                        className={uploadState === 'saving' ? 'is-indeterminate' : ''}
                        style={{ width: `${uploadState === 'saving' ? 100 : uploadProgress}%` }}
                      />
                    </div>
                  ) : null}
                </div>
              </div>
            ) : null}
          </div>

          <footer className="vv-pay-modal__footer">
            <button
              type="button"
              className="vv-pay-modal__button vv-pay-modal__button--cancel"
              onClick={closeModal}
              disabled={isUploading}
            >
              Cancel
            </button>
            <button
              type="submit"
              className="vv-pay-modal__button vv-pay-modal__button--submit"
              disabled={isUploading || uploadState === 'success'}
            >
              {uploadState === 'uploading'
                ? 'Uploading…'
                : uploadState === 'saving'
                  ? 'Saving…'
                  : uploadState === 'success'
                    ? 'Paid'
                    : 'Mark as Paid'}
            </button>
          </footer>
        </form>
      </div>
    </div>,
    document.body,
  )
}
