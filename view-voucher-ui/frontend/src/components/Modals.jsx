import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

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
  const { voucher, finAccounts, actions } = data
  return (
    <VvModalOverlay open={open} onClose={onClose}>
      <div className="modal-content-clean" onMouseDown={(e) => e.stopPropagation()}>
        <div className="modal-header-clean">
          <h3>Mark Voucher as Paid</h3>          <p>Select the account used for payment.</p>
        </div>
        <form action={actions.markPaidUrl} method="POST" encType="multipart/form-data">
          <input type="hidden" name="voucher_id" value={voucher.id} />
          {actions.returnFinance && <input type="hidden" name="return" value="finance" />}
          <div className="mb-3" style={{ marginBottom: 15 }}>
            <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, fontSize: 14, color: '#374151' }}>Source Account</label>
            <select name="account_id" required style={{ width: '100%', padding: 10, border: '1px solid #d1d5db', borderRadius: 6, fontSize: 14 }}>
              <option value="">Select Account...</option>
              {finAccounts.map((acc) => (
                <option key={acc.id} value={acc.id}>
                  {acc.name} ({acc.currency} {Number(acc.current_balance).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })})
                </option>
              ))}
            </select>
          </div>
          <div className="mb-3" style={{ marginBottom: 20 }}>
            <label style={{ display: 'block', marginBottom: 8, fontWeight: 600, fontSize: 14, color: '#374151' }}>Attach SWIFT Proof (Required)</label>
            <input type="file" name="swift_file" required accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx" style={{ width: '100%', padding: 10, border: '1px solid #d1d5db', borderRadius: 6, fontSize: 14 }} />
          </div>
          <div className="modal-footer-clean">
            <button type="button" onClick={onClose} className="btn-modal-cancel">Cancel</button>
            <button type="submit" className="btn-modal-submit" style={{ background: '#065f46', borderColor: '#065f46' }}>Confirm & Mark Paid</button>
          </div>
        </form>
      </div>
    </VvModalOverlay>
  )
}

export function AdminApprovalModal({ open, onClose, action, postUrl }) {
  if (!open) return null
  const isApprove = action === 'approved'
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
        <form method="POST" action={postUrl} className="vv-react-modal__form">
          <input type="hidden" name="admin_action" value={action} />
          <label className="vv-react-modal__field">
            <span>Comments (Optional)</span>
            <textarea
              name="comments"
              rows={4}
              placeholder="Add a reason or note..."
              className="vv-react-modal__textarea"
            />
          </label>
          <div className="vv-react-modal__foot">
            <button type="button" onClick={onClose} className="vv-react-modal__btn vv-react-modal__btn--ghost">
              Cancel
            </button>
            <button
              type="submit"
              className={`vv-react-modal__btn ${isApprove ? 'vv-react-modal__btn--success' : 'vv-react-modal__btn--danger'}`}
            >
              {isApprove ? 'Confirm Approval' : 'Confirm Rejection'}
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
  const title = kind === 'swift' ? 'SWIFT Payment Proof' : 'Supporting Document'

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
              src={url}
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
  const [saving, setSaving] = useState(false)
  const drawing = useRef(false)

  useEffect(() => {
    if (!open) {
      setConfirmed(false)
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

  async function submit() {
    setSaving(true)
    const fd = new FormData()
    fd.append('voucher_id', String(data.voucher.id))
    fd.append('approval_id', String(approval.id))
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
      const res = await fetch(data.actions.approveUrl, { method: 'POST', body: fd })
      const j = await res.json()
      if (j && j.success) {
        onSuccess()
      } else {
        const msg = j && j.message ? j.message : 'Approve failed'
        if (typeof window.Swal !== 'undefined') {
          window.Swal.fire({ icon: 'error', title: 'Approval failed', text: msg, confirmButtonColor: '#dc3545' })
        } else {
          alert(msg)
        }
        setSaving(false)
      }
    } catch {
      alert('Network error')
      setSaving(false)
    }
  }

  const roleLabel = rolesStr || approval.role || 'Approver'
  const isFinalApproval = !!approval.is_final_approval

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
        <div className="warning-alert-clean">
          <div className="warning-alert-clean__title">Warning</div>
          <p>Approving will record your signature and cannot be reversed.</p>
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