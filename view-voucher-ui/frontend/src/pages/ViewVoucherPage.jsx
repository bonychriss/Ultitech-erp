import { useCallback, useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { Loader2 } from 'lucide-react'
import { CFG } from '../config.js'
import { showToast } from '../toast.js'
import VoucherPreview from '../components/VoucherPreview.jsx'
import VoucherActions from '../components/VoucherActions.jsx'
import ApprovalFlowCard from '../components/ApprovalFlowCard.jsx'
import ApprovalCommentsCard from '../components/ApprovalCommentsCard.jsx'
import DocumentsCard from '../components/DocumentsCard.jsx'
import { MarkPaidModal, AdminApprovalModal, DocPreviewModal, ApproveModal } from '../components/Modals.jsx'
import { downloadVoucherPdf, printVoucher } from '../utils/pdf.js'

export default function ViewVoucherPage() {
  const [data, setData] = useState(CFG.data)
  const [loading, setLoading] = useState(!CFG.data)
  const [error, setError] = useState('')

  const [markPaidOpen, setMarkPaidOpen] = useState(false)
  const [adminAction, setAdminAction] = useState(null)
  const [approveTarget, setApproveTarget] = useState(null)
  const [approveRoles, setApproveRoles] = useState('')
  const [preview, setPreview] = useState({ open: false, url: '', kind: 'supporting', isImage: false })

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const url = new URL(CFG.apiUrl, window.location.href)
      if (CFG.voucherId) url.searchParams.set('id', String(CFG.voucherId))
      const qs = window.location.search.replace(/^\?/, '')
      if (qs) {
        new URLSearchParams(qs).forEach((v, k) => {
          if (k !== 'id') url.searchParams.set(k, v)
        })
      }
      const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } })
      const json = await res.json()
      if (!json.ok || !json.data) {
        setError(json.error || 'Could not load voucher')
        return
      }
      setData(json.data)
    } catch {
      setError('Network error loading voucher')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { if (!CFG.data) load() }, [load])

  useEffect(() => {
    const f = CFG.flash || {}
    const no = data?.voucher?.voucher_no || ''
    if (f.created || f.updated) {
      showToast(`Voucher created${no ? `: ${no}` : ''}`)
    } else if (f.posted) {
      showToast(`Voucher ${no} posted and finalized.`.replace('  ', ' '))
    } else if (f.postError) {
      showToast(f.postError, 'error')
    } else if (f.paid) {
      showToast('Payment successful. Voucher has been marked as paid.')
    } else if (f.payError) {
      showToast(`Mark paid failed: ${f.payError}`, 'error')
    }
  }, [data])

  useEffect(() => {
    if (loading || !data) return

    const url = new URL(window.location.href)
    const wantsPrint = url.searchParams.get('print') === '1'
    const wantsDownload = url.searchParams.get('download') === '1'
    const wantsPay = url.searchParams.get('pay') === '1'
    if (!wantsPrint && !wantsDownload && !wantsPay) return

    let cleaned = false
    const cleanParams = () => {
      if (cleaned) return
      cleaned = true
      ;['print', 'download', 'pay'].forEach((key) => url.searchParams.delete(key))
      const next = `${url.pathname}${url.searchParams.toString() ? `?${url.searchParams}` : ''}${url.hash}`
      window.history.replaceState({}, '', next)
    }

    if (wantsPay) {
      if (data.permissions?.canMarkPaid) {
        setMarkPaidOpen(true)
      } else if (!data.voucher?.is_paid) {
        showToast('This voucher is not ready to mark paid yet.', 'error')
      }
      cleanParams()
    }

    if (wantsPrint) {
      cleanParams()
      setTimeout(() => {
        const run = async () => {
          for (let i = 0; i < 20; i += 1) {
            if (document.getElementById('voucherFull')) break
            await new Promise((r) => setTimeout(r, 150))
          }
          printVoucher()
        }
        void run()
      }, 200)
    }

    if (wantsDownload) {
      cleanParams()
      const voucherNo = data.voucher?.voucher_no || String(data.voucher?.id || 'voucher')
      const run = async () => {
        // Wait for the printable preview node to mount.
        for (let i = 0; i < 20; i += 1) {
          if (document.getElementById('voucherFull')) break
          await new Promise((r) => setTimeout(r, 150))
        }
        try {
          await downloadVoucherPdf(voucherNo)
        } catch {
          showToast('Could not prepare the PDF download.', 'error')
        }
      }
      void run()
    }
  }, [loading, data])

  const deleteAttachment = useCallback(async (att) => {
    const id = att.id
    if (typeof window.Swal === 'undefined') {
      if (!window.confirm('Delete this attachment?')) return
    } else {
      const r = await window.Swal.fire({ title: 'Delete Attachment?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, delete it!' })
      if (!r.isConfirmed) return
    }
    const fd = new FormData()
    fd.append('attachment_id', String(id))
    if (id < 0) {
      fd.append('voucher_id', String(data.voucher.id))
      fd.append('file_name', String(att.fileName || ''))
    }
    try {
      const res = await fetch(data.actions.deleteAttachmentUrl, { method: 'POST', body: fd })
      const j = await res.json()
      if (j.ok) {
        setData((prev) => ({
          ...prev,
          attachments: prev.attachments.filter((a) => a.id !== id),
        }))
        if (window.Swal) window.Swal.fire('Deleted!', 'The attachment has been removed.', 'success')
      } else if (window.Swal) {
        window.Swal.fire('Error', j.error || 'Failed to delete', 'error')
      }
    } catch {
      if (window.Swal) window.Swal.fire('Error', 'A system error occurred.', 'error')
    }
  }, [data])

  const unlinkOrder = useCallback(async (kind, doc) => {
    const isPurchase = kind === 'purchase'
    const number = isPurchase ? (doc.poNumber || `PO-${doc.id}`) : (doc.orderNumber || `SO-${doc.id}`)
    const typeLabel = isPurchase ? 'purchase order' : 'sales order'
    const text = `${number} will be removed from this voucher's supporting documents. The ${typeLabel} itself is not deleted.`
    if (typeof window.Swal === 'undefined') {
      if (!window.confirm(text)) return
    } else {
      const r = await window.Swal.fire({
        title: `Remove ${number}?`,
        text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, remove it',
        confirmButtonColor: '#dc2626',
      })
      if (!r.isConfirmed) return
    }
    const fd = new FormData()
    fd.append('unlink_order', kind)
    fd.append('voucher_id', String(data.voucher.id))
    fd.append('order_id', String(doc.id))
    try {
      const res = await fetch(data.actions.deleteAttachmentUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      const j = await res.json()
      if (j.ok) {
        const listKey = isPurchase ? 'purchaseOrderDocs' : 'salesOrderDocs'
        setData((prev) => ({
          ...prev,
          [listKey]: (prev[listKey] || []).filter((d) => d.id !== doc.id),
        }))
        if (window.Swal) window.Swal.fire('Removed', `${number} is no longer attached to this voucher.`, 'success')
      } else if (window.Swal) {
        window.Swal.fire('Error', j.error || 'Could not remove the document', 'error')
      } else {
        alert(j.error || 'Could not remove the document')
      }
    } catch {
      if (window.Swal) window.Swal.fire('Error', 'A system error occurred.', 'error')
    }
  }, [data])

  const postTarget = `${window.location.pathname}${window.location.search}`
  const headerMount = typeof document !== 'undefined' ? document.getElementById('vv-actions-header-mount') : null

  const markPosted = useCallback(async () => {
    if (!window.confirm('Finalize (post) this voucher? This locks further changes for non-admin users.')) return
    try {
      const fd = new FormData()
      fd.append('mark_posted', '1')
      const res = await fetch(postTarget, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        redirect: 'manual',
      })
      const redirected = res.type === 'opaqueredirect' || (res.status >= 300 && res.status < 400)
      if (!redirected && !res.ok) throw new Error(`Request failed (${res.status})`)
      if (window.erpNavBack && typeof window.erpNavBack.pruneSameDocument === 'function') {
        try { window.erpNavBack.pruneSameDocument(window.location.href) } catch { /* ignore */ }
      }
      // Prefer redirect Location when present so flash query params (posted=1) apply.
      let next = window.location.href
      try {
        const loc = res.headers.get('Location')
        if (loc) next = new URL(loc, window.location.href).href
      } catch { /* keep current */ }
      window.location.replace(next)
    } catch (err) {
      const msg = err instanceof Error && err.message ? err.message : 'Could not post voucher'
      if (window.Swal) window.Swal.fire('Error', msg, 'error')
      else alert(msg)
    }
  }, [postTarget])

  if (loading) {
    return <div className="vv-react-loading"><Loader2 size={22} style={{ verticalAlign: -5, marginRight: 8 }} />Loading voucher...</div>
  }
  if (error || !data) {
    return (
      <div className="vv-react-error">
        <h2>{error || 'Voucher unavailable'}</h2>
        <a
          className="erp-nav-back-ignore"
          href={data?.actions?.backUrl || 'employee/dashboard.php'}
          onClick={(e) => {
            const fallback = data?.actions?.backUrl || 'employee/dashboard.php'
            if (data?.actions?.returnFinance) return
            if (window.erpNavBack && typeof window.erpNavBack.go === 'function') {
              e.preventDefault()
              if (!window.erpNavBack.go(fallback)) window.location.href = fallback
            }
          }}
        >Return to Dashboard</a>
      </div>
    )
  }

  const actionProps = {
    data,
    onMarkPaid: () => setMarkPaidOpen(true),
    onApprove: (approval, roles) => { setApproveTarget(approval); setApproveRoles(roles) },
    onAdminAction: (action) => setAdminAction(action),
    onMarkPosted: markPosted,
  }

  return (
    <div className="vv-react-root">
      {headerMount ? createPortal(<VoucherActions {...actionProps} />, headerMount) : null}
      <div className="page-shell">
        <div className="vv-view-layout">
          <div className="vv-voucher-toolbar vv-mobile-toolbar no-print voucher-actions-bar">
            {(data.actions?.fromPurchases || data.actions?.fromExpenses) ? (
              <a
                className="vv-purchases-back erp-nav-back-ignore"
                href={data.actions.backUrl}
                onClick={(e) => {
                  e.preventDefault()
                  window.location.href = data.actions.backUrl
                }}
              >
                <i className="fas fa-arrow-left" aria-hidden="true" />
                {data.actions.backLabel || (data.actions?.fromExpenses ? 'Back to Expenses' : 'Back to Purchases')}
              </a>
            ) : data.status?.label ? (
              <span className={`vv-status-badge ${data.status.className || 'vv-status-pending'}`}>
                {data.status.label}
              </span>
            ) : (
              <span className="vv-toolbar-spacer" aria-hidden="true" />
            )}
            <VoucherActions {...actionProps} />
          </div>


          <div className="vv-preview-section">
            <div className="vv-preview-body voucher-scroll">
              <VoucherPreview data={data} />
            </div>
          </div>

          <ApprovalCommentsCard
            data={data}
            onCommentsChange={(updater) => {
              setData((prev) => ({
                ...prev,
                comments: typeof updater === 'function' ? updater(prev.comments || []) : updater,
              }))
            }}
          />

          <ApprovalFlowCard data={data} />

          <DocumentsCard
            data={data}
            onPreview={(url, kind, isImage = false) => setPreview({ open: true, url, kind, isImage: Boolean(isImage) })}
            onDeleteAttachment={deleteAttachment}
            onUnlinkOrder={unlinkOrder}
          />
        </div>
      </div>

      <MarkPaidModal open={markPaidOpen} onClose={() => setMarkPaidOpen(false)} data={data} />
      <AdminApprovalModal open={!!adminAction} onClose={() => setAdminAction(null)} action={adminAction} postUrl={postTarget} />
      <ApproveModal
        open={!!approveTarget}
        onClose={() => setApproveTarget(null)}
        approval={approveTarget}
        rolesStr={approveRoles}
        data={data}
        onSuccess={(result) => {
          const remainingCount = Number(result?.remaining_count || 0)
          try {
            if (typeof window.ultitechForgetPvHighlight === 'function') {
              window.ultitechForgetPvHighlight(data?.voucher?.id, data?.voucher?.voucher_no)
            }
            if (remainingCount > 0) {
              sessionStorage.setItem('ultitech_pv_coach_reshow', '1')
              if (typeof window.ultitechRequestPvCoachReshow === 'function') {
                window.ultitechRequestPvCoachReshow()
              }
            }
          } catch { /* ignore */ }
          if (window.erpNavBack && typeof window.erpNavBack.pruneSameDocument === 'function') {
            try { window.erpNavBack.pruneSameDocument(window.location.href) } catch { /* ignore */ }
          }
          try {
            const next = new URL(window.location.href)
            if (remainingCount > 0) {
              next.searchParams.set('pv_coach', '1')
            } else {
              next.searchParams.delete('pv_coach')
            }
            window.location.replace(next.toString())
          } catch {
            window.location.replace(window.location.href)
          }
        }}
      />
      <DocPreviewModal
        open={preview.open}
        onClose={() => setPreview({ open: false, url: '', kind: 'supporting', isImage: false })}
        url={preview.url}
        kind={preview.kind}
        isImage={preview.isImage}
      />
    </div>
  )
}
