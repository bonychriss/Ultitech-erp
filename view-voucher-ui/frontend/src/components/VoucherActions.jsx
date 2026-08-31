import { useCallback, useEffect, useRef, useState } from 'react'
import { downloadVoucherPdf, printVoucher } from '../utils/pdf.js'

export default function VoucherActions({
  data,
  onMarkPaid,
  onApprove,
  onAdminAction,
  onMarkPosted,
}) {
  const [open, setOpen] = useState(false)
  const [dlState, setDlState] = useState('idle')
  const menuRef = useRef(null)

  const { voucher, permissions, actions, userPendingApprovals, pendingCount, notifyTarget, shareUrl } = data

  useEffect(() => {
    function onDocClick(e) {
      if (!menuRef.current) return
      if (!menuRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('click', onDocClick)
    return () => document.removeEventListener('click', onDocClick)
  }, [])

  const handleDownload = useCallback(async (e) => {
    e.preventDefault()
    if (dlState === 'loading') return
    setDlState('loading')
    try {
      await downloadVoucherPdf(voucher.voucher_no)
      setDlState('success')
      setTimeout(() => { setDlState('idle') }, 2000)
    } catch {
      setDlState('error')
      setTimeout(() => { setDlState('idle') }, 2500)
    }
  }, [dlState, voucher.voucher_no])

  const mainApproval = userPendingApprovals[0]
  const rolesStr = userPendingApprovals.map((u) => u.role).join(', ')
  const isGmFinalApproval = !!mainApproval?.is_final_approval

  return (
    <div className="vv-header-actions no-print" ref={menuRef}>
      <div className="vv-actions-dropdown">
        <button
          type="button"
          className="vv-actions-btn dropdown-btn-actions"
          onClick={() => setOpen((v) => !v)}
          aria-haspopup="true"
          aria-expanded={open}
        >
          <i className="fas fa-ellipsis-v" aria-hidden="true" /> Actions
        </button>
        <div className={`vv-actions-menu${open ? ' show-dropdown' : ''}`}>
          <a
            href="#"
            className="dropdown-item"
            onClick={handleDownload}
            aria-disabled={dlState === 'loading'}
          >
            {dlState === 'loading' ? (
              <><i className="fas fa-spinner fa-spin" aria-hidden="true" /> Preparing PDF...</>
            ) : dlState === 'success' ? (
              <><i className="fas fa-check" aria-hidden="true" /> Downloaded</>
            ) : dlState === 'error' ? (
              <><i className="fas fa-exclamation-circle" aria-hidden="true" /> Download failed</>
            ) : (
              <><i className="fas fa-download" aria-hidden="true" /> Download PDF</>
            )}
          </a>

          <button type="button" className="dropdown-item" onClick={() => { printVoucher(); setOpen(false) }}>
            <i className="fas fa-print" aria-hidden="true" /> Print
          </button>

          <a
            href={actions.backUrl}
            className="dropdown-item"
            onClick={(e) => {
              e.preventDefault()
              if (actions.returnFinance) {
                window.location.href = actions.backUrl
                return
              }
              if (window.erpNavBack && typeof window.erpNavBack.go === 'function') {
                if (window.erpNavBack.go(actions.backUrl)) return
              }
              window.location.href = actions.backUrl
            }}
          >
            <i className="fas fa-arrow-left" aria-hidden="true" /> Back
          </a>

          {permissions.canEdit && (
            <a href={actions.editHref} className="dropdown-item">
              <i className="fas fa-edit" aria-hidden="true" /> Edit
            </a>
          )}

          {permissions.canMarkPaid && (
            <button type="button" className="dropdown-item dropdown-item--success" onClick={() => { onMarkPaid(); setOpen(false) }}>
              <i className="fas fa-check-circle" aria-hidden="true" /> Mark Paid
            </button>
          )}

          {permissions.canPost && (
            <button type="button" className="dropdown-item" onClick={() => { onMarkPosted(); setOpen(false) }}>
              <i className="fas fa-lock" aria-hidden="true" /> Mark Posted
            </button>
          )}

          {mainApproval && (
            <button
              type="button"
              className="dropdown-item dropdown-item--primary"
              onClick={() => { onApprove(mainApproval, rolesStr); setOpen(false) }}
            >
              <i className="fas fa-thumbs-up" aria-hidden="true" />
              {isGmFinalApproval
                ? 'Approve as General Manager'
                : `Approve ${userPendingApprovals.length > 1 ? 'All Roles' : ''}`}
            </button>
          )}

          {permissions.canFinalApprove && !permissions.blockFinalApproveOnConfirming && (
            <button type="button" className="dropdown-item dropdown-item--success" onClick={() => { onAdminAction('approved'); setOpen(false) }}>
              <i className="fas fa-check-double" aria-hidden="true" /> Final Approve
            </button>
          )}

          {permissions.canReject && (
            <button type="button" className="dropdown-item dropdown-item--danger" onClick={() => { onAdminAction('rejected'); setOpen(false) }}>
              <i className="fas fa-times-circle" aria-hidden="true" /> Reject
            </button>
          )}

          {pendingCount > 0 && (
            <div className="dropdown-item dropdown-item--muted dropdown-item--pending">
              <i className="fas fa-clock" aria-hidden="true" /> {pendingCount} Pending
            </div>
          )}

          {notifyTarget && (
            <a href={notifyTarget.link} target="_blank" rel="noopener noreferrer" className="dropdown-item dropdown-item--success">
              <i className="fab fa-whatsapp" aria-hidden="true" /> Notify {notifyTarget.role}
            </a>
          )}

          {shareUrl && (
            <a href={shareUrl} target="_blank" rel="noopener noreferrer" className="dropdown-item dropdown-item--success">
              <i className="fab fa-whatsapp" aria-hidden="true" /> Send to Group
            </a>
          )}
        </div>
      </div>
    </div>
  )
}
