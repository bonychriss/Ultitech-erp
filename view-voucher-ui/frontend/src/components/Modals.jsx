import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Check, CheckCircle2, ChevronDown, Coins, Landmark, Paperclip, Smartphone, Wallet, X } from 'lucide-react'

function PaymentAccountIcon({ type, size, imageUrl }) {
  const [imageFailed, setImageFailed] = useState(false)
  useEffect(() => { setImageFailed(false) }, [imageUrl])
  if (imageUrl && !imageFailed) {
    return <img className="vv-pay-modal__account-logo" src={imageUrl} alt="" loading="lazy" onError={() => setImageFailed(true)} />
  }
  const Icon = type === 'bank' ? Landmark : type === 'mobile' ? Smartphone : type === 'cash' ? Coins : Wallet
  return <Icon size={size} aria-hidden="true" />
}

function VvModalOverlay({ open, onClose, children, id }) {
  useEffect(() => {
    if (!open) return undefined
    const prev = document.body.style.overflow
    document.body.classList.add('vv-modal-open')
    document.body.style.overflow = 'hidden'
    function onKey(e) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => {
      document.body.classList.remove('vv-modal-open')
      document.body.style.overflow = prev
      document.removeEventListener('keydown', onKey)
    }
  }, [open, onClose])

  if (!open) return null
  return createPortal(
    <div
      id={id}
      className="vv-react-modal-overlay no-print is-open"
      role="dialog"
      aria-modal="true"
      onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}
    >
      {children}
    </div>,
    document.body,
  )
}

export function MarkPaidModal({ open, onClose, data }) {
  if (!open) return null
  return <MarkPaidDialog onClose={onClose} data={data} />
}

function MarkPaidDialog({ onClose, data }) {
  const { voucher, finAccounts, actions } = data
  const fileInputRef = useRef(null)
  const accountPickerRef = useRef(null)
  const xhrRef = useRef(null)
  const redirectTimerRef = useRef(null)
  const [selectedAccountId, setSelectedAccountId] = useState('')
  const [accountError, setAccountError] = useState('')
  const [proofName, setProofName] = useState('')
  const [isDragging, setIsDragging] = useState(false)
  const [uploadState, setUploadState] = useState('idle')
  const [uploadProgress, setUploadProgress] = useState(0)
  const [uploadError, setUploadError] = useState('')
  const isUploading = uploadState === 'uploading' || uploadState === 'saving'

  function closeModal() {
    if (!isUploading) onClose()
  }

  useEffect(() => () => {
    if (xhrRef.current && xhrRef.current.readyState !== XMLHttpRequest.DONE) {
      xhrRef.current.abort()
    }
    if (redirectTimerRef.current) {
      window.clearTimeout(redirectTimerRef.current)
    }
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
    if (!selectedAccountId) {
      setAccountError('Choose a source account to continue.')
      accountPickerRef.current?.querySelector('summary')?.focus()
      return
    }
    if (!event.currentTarget.reportValidity()) return

    setAccountError('')
    setUploadError('')
    setUploadProgress(0)
    setUploadState('uploading')

    const xhr = new XMLHttpRequest()
    xhrRef.current = xhr
    xhr.upload.addEventListener('progress', (progressEvent) => {
      if (!progressEvent.lengthComputable) return
      setUploadProgress(Math.round((progressEvent.loaded / progressEvent.total) * 100))
    })
    xhr.upload.addEventListener('load', () => {
      setUploadProgress(100)
      setUploadState('saving')
    })
    xhr.addEventListener('load', () => {
      xhrRef.current = null
      let destination
      try {
        destination = new URL(xhr.responseURL)
      } catch {
        destination = null
      }

      const savedVoucherPage = destination
        && destination.origin === window.location.origin
        && destination.pathname.endsWith('/view-voucher.php')
        && destination.searchParams.get('id') === String(voucher.id)
        && destination.searchParams.get('paid') === '1'
      if (xhr.status >= 200 && xhr.status < 300 && savedVoucherPage) {
        setUploadProgress(100)
        setUploadState('success')
        redirectTimerRef.current = window.setTimeout(() => {
          window.location.assign(destination.href)
        }, 800)
        return
      }

      const responseMessage = xhr.responseText?.trim()
      setUploadError(xhr.status >= 400 && responseMessage && !responseMessage.startsWith('<')
        ? responseMessage.slice(0, 300)
        : 'The payment proof could not be confirmed as saved. Please try again.')
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
    xhr.open('POST', event.currentTarget.action)
    xhr.send(new FormData(event.currentTarget))
  }

  const selectedAccount = finAccounts.find((account) => String(account.id) === selectedAccountId)
  const selectedAccountType = ['bank', 'cash', 'mobile'].includes(selectedAccount?.type) ? selectedAccount.type : 'cash'
  const accountTypeLabel = (type) => ({
    bank: 'Bank account',
    cash: 'Cash account',
    mobile: 'Mobile money',
  }[type] || 'Payment account')

  return (
    <VvModalOverlay open onClose={closeModal}>
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
        <form className="vv-pay-modal__form" action={actions.markPaidUrl} method="POST" encType="multipart/form-data" onSubmit={handleSubmit}>
          <input type="hidden" name="voucher_id" value={voucher.id} />
          <input type="hidden" name="account_id" value={selectedAccountId} />
          {actions.returnFinance && <input type="hidden" name="return" value="finance" />}
          <div className="vv-pay-modal__field">
            <label id="vv-pay-account-label">Source Account</label>
            <details className={`vv-pay-modal__account-picker${accountError ? ' has-error' : ''}`} ref={accountPickerRef}>
              <summary
                aria-labelledby="vv-pay-account-label vv-pay-account-value"
                aria-invalid={Boolean(accountError)}
                aria-describedby={accountError ? 'vv-pay-account-error' : undefined}
                aria-controls="vv-pay-account-options"
                aria-disabled={isUploading}
                onClick={(event) => { if (isUploading) event.preventDefault() }}
              >
                <span className={`vv-pay-modal__account-icon is-${selectedAccount ? selectedAccountType : 'default'}`}>
                  <PaymentAccountIcon type={selectedAccount?.type} size={19} imageUrl={selectedAccount?.image_url} />
                </span>
                <span className="vv-pay-modal__account-summary" id="vv-pay-account-value">
                  <strong>{selectedAccount?.name || 'Select a payment account'}</strong>
                  {selectedAccount && (
                    <small>
                      {accountTypeLabel(selectedAccount.type)} · {selectedAccount.currency} {Number(selectedAccount.current_balance).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </small>
                  )}
                  {!selectedAccount && <small>Choose where this payment will be paid from</small>}
                </span>
                <ChevronDown className="vv-pay-modal__account-chevron" size={18} aria-hidden="true" />
              </summary>
              <div className="vv-pay-modal__account-options" id="vv-pay-account-options" aria-labelledby="vv-pay-account-label">
                {finAccounts.length > 0 ? finAccounts.map((account) => (
                  <button
                    key={account.id}
                    type="button"
                    className="vv-pay-modal__account-option"
                    disabled={isUploading}
                    aria-current={String(account.id) === selectedAccountId ? 'true' : undefined}
                    onClick={() => {
                      setSelectedAccountId(String(account.id))
                      setAccountError('')
                      if (accountPickerRef.current) accountPickerRef.current.open = false
                    }}
                  >
                    <span className={`vv-pay-modal__account-option-icon is-${account.type}`}>
                      <PaymentAccountIcon type={account.type} size={17} imageUrl={account.image_url} />
                    </span>
                    <span className="vv-pay-modal__account-option-copy">
                      <strong>{account.name}</strong>
                      <small>{accountTypeLabel(account.type)}</small>
                    </span>
                    <span className="vv-pay-modal__account-balance">
                      <strong>{account.currency} {Number(account.current_balance).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong>
                      {String(account.id) === selectedAccountId && <Check size={16} aria-label="Selected" />}
                    </span>
                  </button>
                )) : (
                  <p className="vv-pay-modal__account-empty">No active cash, bank, or mobile-money accounts are available.</p>
                )}
              </div>
            </details>
            {accountError && <span className="vv-pay-modal__account-error" id="vv-pay-account-error" role="alert">{accountError}</span>}
          </div>
          <div className="vv-pay-modal__field vv-pay-modal__proof-field">
            <label>SWIFT Proof (Required)</label>
            <label
              className={`vv-pay-modal__dropzone${isDragging ? ' is-dragging' : ''}${isUploading ? ' is-disabled' : ''}`}
              onDragOver={(event) => { event.preventDefault(); if (!isUploading) setIsDragging(true) }}
              onDragLeave={() => setIsDragging(false)}
              onDrop={handleDrop}
            >
              <input
                ref={fileInputRef}
                type="file"
                name="swift_file"
                required
                disabled={isUploading}
                accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx"
                onChange={(event) => setProofFile(event.target.files?.[0])}
              />
              <Paperclip size={27} />
              <span className="vv-pay-modal__file-name">
                {proofName
                  ? <><strong>{proofName}</strong></>
                  : <><strong>Choose file</strong> or drag and drop</>}
              </span>
            </label>
            {uploadState !== 'idle' && (
              <div className={`vv-pay-modal__upload-status is-${uploadState}`} role={uploadState === 'error' ? 'alert' : 'status'} aria-live="polite">
                {(uploadState === 'uploading' || uploadState === 'saving') && <span className="vv-pay-modal__spinner" aria-hidden="true" />}
                {(uploadState === 'ready' || uploadState === 'success') && <CheckCircle2 size={17} aria-hidden="true" />}
                <div className="vv-pay-modal__upload-copy">
                  <span>
                    {uploadState === 'ready' && 'File attached. Click Mark as Paid to upload and save the proof.'}
                    {uploadState === 'uploading' && `Uploading proof… ${uploadProgress}%`}
                    {uploadState === 'saving' && 'Upload complete. Saving payment and confirming proof…'}
                    {uploadState === 'success' && 'Payment proof saved. Opening voucher…'}
                    {uploadState === 'error' && uploadError}
                  </span>
                  {(uploadState === 'uploading' || uploadState === 'saving') && (
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
    </VvModalOverlay>
  )
}

export function AdminApprovalModal({ open, onClose, action, postUrl }) {
  const [comments, setComments] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!open) {
      setComments('')
      setSaving(false)
    }
  }, [open])

  if (!open) return null
  const isApprove = action === 'approved'

  async function submit(e) {
    e.preventDefault()
    if (saving) return
    setSaving(true)
    try {
      const fd = new FormData()
      fd.append('admin_action', action)
      fd.append('comments', comments)
      const res = await fetch(postUrl || window.location.href, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        redirect: 'manual',
      })
      const redirected = res.type === 'opaqueredirect' || (res.status >= 300 && res.status < 400)
      if (!redirected && !res.ok) {
        throw new Error(`Request failed (${res.status})`)
      }
      if (!redirected && res.ok) {
        // Server rendered an error into the page instead of redirecting.
        let msg = 'Could not complete this action. Please refresh and try again.'
        try {
          const html = await res.text()
          const m = html.match(/Error processing voucher:\s*([^<]+)/i)
          if (m && m[1]) msg = m[1].trim()
        } catch { /* ignore */ }
        throw new Error(msg)
      }
      // Replace current history entry so Back returns to the list, not a
      // duplicate voucher URL created by classic form POST + redirect.
      if (window.erpNavBack && typeof window.erpNavBack.pruneSameDocument === 'function') {
        try { window.erpNavBack.pruneSameDocument(window.location.href) } catch { /* ignore */ }
      }
      let next = window.location.href
      try {
        const loc = res.headers.get('Location')
        if (loc) next = new URL(loc, window.location.href).href
      } catch { /* keep current */ }
      window.location.replace(next)
    } catch (err) {
      const msg = err instanceof Error && err.message ? err.message : 'Network error'
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({ icon: 'error', title: 'Action failed', text: msg, confirmButtonColor: '#dc3545' })
      } else {
        alert(msg)
      }
      setSaving(false)
    }
  }

  return (
    <VvModalOverlay open={open} onClose={onClose} id="vv-admin-approval-modal">
      <div className="vv-react-modal" onMouseDown={(e) => e.stopPropagation()}>
        <div className="vv-react-modal__head">
          <h3>{isApprove ? 'Final Approve Voucher' : 'Reject Voucher'}</h3>
          <p>
            {isApprove
              ? 'Confirm your final GM approval for this voucher.'
              : 'Confirm rejection of this voucher.'}
          </p>
        </div>
        <form onSubmit={submit} className="vv-react-modal__form">
          <label className="vv-react-modal__field">
            <span>Comments (Optional)</span>
            <textarea
              name="comments"
              rows={4}
              value={comments}
              onChange={(e) => setComments(e.target.value)}
              placeholder="Add a reason or note..."
              className="vv-react-modal__textarea"
              disabled={saving}
            />
          </label>
          <div className="vv-react-modal__foot">
            <button type="button" onClick={onClose} className="vv-react-modal__btn vv-react-modal__btn--ghost" disabled={saving}>
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className={`vv-react-modal__btn ${isApprove ? 'vv-react-modal__btn--success' : 'vv-react-modal__btn--danger'}`}
            >
              {saving ? 'Saving...' : (isApprove ? 'Confirm Approval' : 'Confirm Rejection')}
            </button>
          </div>
        </form>
      </div>
    </VvModalOverlay>
  )
}

export function DocPreviewModal({ open, onClose, url, kind, isImage }) {
  if (!open || !url) return null
  const imgExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']
  const pathExt = url.split('?')[0].split('#')[0].split('.').pop().toLowerCase()
  let fileExt = ''
  try {
    fileExt = (new URL(url, window.location.href).searchParams.get('file') || '').split('.').pop().toLowerCase()
  } catch {
    fileExt = ''
  }
  const isImg = Boolean(isImage) || imgExt.includes(pathExt) || imgExt.includes(fileExt)
  const title =
    kind === 'swift' ? 'SWIFT Payment Proof' : kind === 'sales_order' ? 'Sales Order' : 'Supporting Document'

  const frameSrc = (() => {
    if (isImg || !url) return url
    // HTML print sheets (sales orders / PO docs) render cleaner with embed=1
    if (/orders\/print\.php|po-document\.php|view_po\.php/i.test(String(url))) {
      try {
        const u = new URL(url, window.location.href)
        u.searchParams.set('embed', '1')
        u.searchParams.delete('download')
        u.searchParams.delete('autodownload')
        return u.pathname + u.search + u.hash
      } catch {
        return url
      }
    }
    return url
  })()

  return (
    <div className="no-print" style={{ display: 'flex', position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)', zIndex: 3000 }} onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div style={{ background: '#fff', width: '100%', height: '100%', display: 'flex', flexDirection: 'column' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 12px', borderBottom: '1px solid #e5e7eb', flexShrink: 0 }}>
          <div style={{ fontWeight: 600, fontSize: 14 }}>{title}</div>
          <button type="button" onClick={onClose} className="btn-sm-black" style={{ background: '#111', color: '#fff', borderColor: '#111' }}>Close</button>
        </div>
        <div
          style={{
            padding: 16,
            overflow: 'auto',
            background: '#fff',
            flex: 1,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            width: '100%',
            minHeight: 0,
          }}
        >
          {isImg ? (
            <img
              src={url}
              alt="Preview"
              style={{
                display: 'block',
                maxWidth: 'min(100%, 960px)',
                maxHeight: 'calc(100vh - 96px)',
                width: 'auto',
                height: 'auto',
                objectFit: 'contain',
                margin: '0 auto',
              }}
            />
          ) : (
            <iframe
              src={frameSrc}
              title="PDF Preview"
              style={{
                width: 'min(100%, 960px)',
                height: 'calc(100vh - 96px)',
                border: 'none',
                margin: '0 auto',
                background: '#fff',
              }}
            />
          )}
        </div>
      </div>
    </div>
  )
}

export function ApproveModal({ open, onClose, approval, rolesStr, data, onSuccess }) {
  const canvasRef = useRef(null)
  const [useProfile, setUseProfile] = useState(!!data.profileSignature)
  const [confirmed, setConfirmed] = useState(false)
  const [comments, setComments] = useState('')
  const [saving, setSaving] = useState(false)
  const drawing = useRef(false)

  useEffect(() => {
    if (!open) {
      setConfirmed(false)
      setComments('')
      setSaving(false)
      setUseProfile(!!data.profileSignature)
    }
  }, [open, data.profileSignature])

  useEffect(() => {
    const canvas = canvasRef.current
    if (!open || !canvas || useProfile) return undefined
    const ctx = canvas.getContext('2d')
    if (!ctx) return undefined

    const ratio = window.devicePixelRatio || 1
    const w = canvas.parentElement ? canvas.parentElement.offsetWidth : 480
    const h = 120
    canvas.width = w * ratio
    canvas.height = h * ratio
    canvas.style.width = `${w}px`
    canvas.style.height = `${h}px`
    ctx.scale(ratio, ratio)

    const getX = (e) => {
      const r = canvas.getBoundingClientRect()
      return (e.touches ? e.touches[0].clientX : e.clientX) - r.left
    }
    const getY = (e) => {
      const r = canvas.getBoundingClientRect()
      return (e.touches ? e.touches[0].clientY : e.clientY) - r.top
    }
    const start = (ev) => { drawing.current = true; ctx.beginPath(); ctx.moveTo(getX(ev), getY(ev)); ev.preventDefault() }
    const move = (ev) => {
      if (!drawing.current) return
      ctx.lineTo(getX(ev), getY(ev))
      ctx.strokeStyle = '#000'
      ctx.lineWidth = 2
      ctx.lineCap = 'round'
      ctx.stroke()
      ev.preventDefault()
    }
    const end = (ev) => { drawing.current = false; ev.preventDefault() }

    canvas.addEventListener('mousedown', start)
    canvas.addEventListener('mousemove', move)
    canvas.addEventListener('mouseup', end)
    canvas.addEventListener('touchstart', start)
    canvas.addEventListener('touchmove', move)
    canvas.addEventListener('touchend', end)
    return () => {
      canvas.removeEventListener('mousedown', start)
      canvas.removeEventListener('mousemove', move)
      canvas.removeEventListener('mouseup', end)
      canvas.removeEventListener('touchstart', start)
      canvas.removeEventListener('touchmove', move)
      canvas.removeEventListener('touchend', end)
    }
  }, [open, useProfile])

  if (!open || !approval) return null

  const roleLabel = rolesStr || approval.role || 'Approver'
  const isFinalApproval = !!approval.is_final_approval

  async function submit() {
    setSaving(true)
    const fd = new FormData()
    fd.append('voucher_id', String(data.voucher.id))
    fd.append('approval_id', String(approval.id))
    fd.append('comments', comments)
    if (useProfile) {
      fd.append('use_profile_signature', '1')
    } else {
      try {
        const png = canvasRef.current.toDataURL('image/png')
        fd.append('signature', png)
      } catch {
        alert('Drawing failed')
        setSaving(false)
        return
      }
    }
    try {
      // Prefer employee/... approve URL. Naive view-voucher → approve_voucher rewrite
      // breaks for /ultimate/view-voucher.php (no approve at company root → 404 HTML).
      const pagePath = String(window.location.pathname || '')
      const fromEmployeePage = /\/employee\/view-voucher(\.php)?$/i.test(pagePath)
        ? pagePath
          .replace(/\/view-voucher\.php$/i, '/approve_voucher.php')
          .replace(/\/view-voucher$/i, '/approve_voucher')
        : ''
      let approveUrl = fromEmployeePage
      if (!approveUrl) {
        const rawApprove = String(data.actions?.approveUrl || '').trim() || 'employee/approve_voucher.php'
        try {
          approveUrl = new URL(rawApprove, window.location.href).href
        } catch {
          approveUrl = rawApprove
        }
      }
      const res = await fetch(approveUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        redirect: 'manual',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      })
      const redirected = res.type === 'opaqueredirect' || (res.status >= 300 && res.status < 400)
      if (redirected) {
        throw new Error('Session expired. Please refresh and log in again.')
      }
      const rawText = await res.text()
      const contentType = String(res.headers.get('content-type') || '').toLowerCase()
      let j = null
      try {
        j = rawText ? JSON.parse(rawText) : null
      } catch {
        j = null
      }
      if (res.status === 401 || res.status === 403) {
        throw new Error((j && j.message) || 'Session expired. Please refresh and log in again.')
      }
      if (!j) {
        const looksLogin = /log\s*in|sign\s*in|session expired/i.test(rawText || '')
        const snippet = String(rawText || '').replace(/\s+/g, ' ').trim().slice(0, 160)
        throw new Error(
          looksLogin
            ? 'Session expired. Please refresh and log in again.'
            : (snippet
              ? `Approval failed (${res.status}). ${snippet}`
              : `Approval failed (${res.status}). Empty response from ${approveUrl}`)
        )
      }
      if (!contentType.includes('application/json') && !(j && typeof j === 'object')) {
        throw new Error('Session expired. Please refresh and log in again.')
      }
      if (j && j.success) {
        const remainingCount = Number(j.remaining_count || 0)
        try {
          if (typeof window.ultitechForgetPvHighlight === 'function') {
            window.ultitechForgetPvHighlight(data?.voucher?.id, data?.voucher?.voucher_no)
          }
          if (remainingCount > 0) {
            sessionStorage.setItem('ultitech_pv_coach_reshow', '1')
            sessionStorage.setItem(
              'ultitech_pv_coach_reshow_payload',
              JSON.stringify({
                remaining_count: remainingCount,
                remaining: Array.isArray(j.remaining) ? j.remaining : [],
                at: Date.now(),
              })
            )
            if (typeof window.ultitechRequestPvCoachReshow === 'function') {
              window.ultitechRequestPvCoachReshow()
            }
          }
        } catch (_) { /* ignore */ }
        if (typeof onSuccess === 'function') {
          onSuccess(j)
        }
      } else {
        const msg = j && j.message ? j.message : 'Approve failed'
        if (typeof window.Swal !== 'undefined') {
          window.Swal.fire({ icon: 'error', title: 'Approval failed', text: msg, confirmButtonColor: '#dc3545' })
        } else {
          alert(msg)
        }
        setSaving(false)
      }
    } catch (err) {
      const raw = err instanceof Error && err.message ? err.message : 'Network error'
      const msg = /failed to fetch/i.test(raw)
        ? 'Could not reach the approval server. Check your connection and refresh the page.'
        : raw
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({ icon: 'error', title: 'Approval failed', text: msg, confirmButtonColor: '#dc3545' })
      } else {
        alert(msg)
      }
      setSaving(false)
    }
  }

  return (
    <VvModalOverlay open={open} onClose={onClose} id="approveModalAction">
      <div className="modal-content-clean" onMouseDown={(e) => e.stopPropagation()}>
        <div className="modal-header-clean">
          <h3>{isFinalApproval ? 'Final Approve Voucher' : 'Approve Voucher'}</h3>
          <p id="approveActionRole">
            <span>{approval.approver_name || 'Approver'}</span>
            <span className="approve-role-label"> ({roleLabel})</span>
          </p>
        </div>
        <div id="sigArea" className="signature-card-clean">
          <div className="signature-label-clean">Signature</div>
          {useProfile && data.profileSignature ? (
            <div id="profileSigPreviewBox">
              <img src={data.profileSignature} alt="My Signature" id="profileSigPreview" />
            </div>
          ) : (
            <div id="drawSigBox" style={{ width: '100%' }}>
              <canvas ref={canvasRef} id="sigCanvasAction" width={480} height={120} />
              <div id="drawLabel" style={{ fontSize: 12, color: '#666', marginTop: 6 }}>Draw your signature</div>
              <button id="clearSigAction" type="button" style={{ marginTop: 8, background: 'none', border: 'none', color: '#2563eb', cursor: 'pointer', fontSize: 12, fontWeight: 600 }} onClick={() => {
                const c = canvasRef.current
                const ctx = c && c.getContext('2d')
                if (ctx) ctx.clearRect(0, 0, c.width, c.height)
              }}>Clear Drawing</button>
            </div>
          )}
          {data.profileSignature && (
            <a href="#" id="drawToggleAction" className="sig-toggle-link" onClick={(e) => { e.preventDefault(); setUseProfile((v) => !v) }}>
              {useProfile ? 'Draw instead' : 'Use profile signature'}
            </a>
          )}
        </div>
        <label className="vv-react-modal__field" style={{ display: 'block', margin: '12px 0 0' }}>
          <span style={{ display: 'block', marginBottom: 6, fontSize: 13, fontWeight: 600, color: '#334155' }}>
            Comments (Optional)
          </span>
          <textarea
            name="comments"
            rows={3}
            value={comments}
            onChange={(e) => setComments(e.target.value)}
            placeholder="Add a reason or note..."
            className="vv-react-modal__textarea"
            disabled={saving}
            style={{ width: '100%', boxSizing: 'border-box' }}
          />
        </label>
        <div className="warning-alert-clean">
          <div className="warning-alert-clean__title">Warning</div>
          <p>
            {String(rolesStr || '').toLowerCase().includes('applicant') && String(rolesStr || '').toLowerCase().includes('department')
              ? 'This one signature will be applied as both Applicant and Department Manager, and cannot be reversed.'
              : 'Approving will record your signature and cannot be reversed.'}
          </p>
        </div>
        <label className="checkbox-confirm-clean">
          <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} />
          <span>I confirm this action cannot be undone.</span>
        </label>
        <div className="modal-footer-clean">
          <button type="button" id="closeSigAction" className="btn-modal-cancel" onClick={onClose}>Cancel</button>
          <button type="button" id="submitSigAction" className="btn-modal-submit" disabled={!confirmed || saving} onClick={submit}>
            {saving ? 'Processing...' : 'Submit'}
          </button>
        </div>
      </div>
    </VvModalOverlay>
  )
}