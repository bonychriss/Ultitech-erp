import { useCallback, useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { Loader2 } from 'lucide-react'
import { CFG } from '../config.js'
import VoucherPreview from '../components/VoucherPreview.jsx'
import VoucherActions from '../components/VoucherActions.jsx'
import ApprovalFlowCard from '../components/ApprovalFlowCard.jsx'
import DocumentsCard from '../components/DocumentsCard.jsx'
import { MarkPaidModal, AdminApprovalModal, DocPreviewModal, ApproveModal } from '../components/Modals.jsx'

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
    if (typeof window.Swal === 'undefined') return
    if (f.created || f.updated) {
      window.Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Voucher Created!', html: `No: <strong>${data?.voucher?.voucher_no || ''}</strong>`, showConfirmButton: false, timer: 4000 })
    } else if (f.posted) {
      window.Swal.fire({ icon: 'success', title: 'Voucher Posted!', text: `Voucher No: ${data?.voucher?.voucher_no || ''} is now finalized.`, confirmButtonColor: '#059669' })
    } else if (f.postError) {
      window.Swal.fire({ icon: 'error', title: 'Oops...', text: f.postError, confirmButtonColor: '#dc3545' })
    } else if (f.paid) {
      window.Swal.fire({ icon: 'success', title: 'Payment Successful', text: 'Voucher has been marked as paid.', confirmButtonColor: '#059669' })
    } else if (f.payError) {
      window.Swal.fire({ icon: 'error', title: 'Oops...', text: `Mark paid failed: ${f.payError}`, confirmButtonColor: '#dc3545' })
    }
  }, [data])

  useEffect(() => {
    const url = new URL(window.location.href)
    if (url.searchParams.get('print') === '1') {
      setTimeout(() => window.print(), 500)
    }
  }, [loading])

  const deleteAttachment = useCallback(async (id) => {
    if (typeof window.Swal === 'undefined') {
      if (!window.confirm('Delete this attachment?')) return
    } else {
      const r = await window.Swal.fire({ title: 'Delete Attachment?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, delete it!' })
      if (!r.isConfirmed) return
    }
    const fd = new FormData()
    fd.append('attachment_id', String(id))
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

  const postTarget = `${window.location.pathname}${window.location.search}`
  const headerMount = typeof document !== 'undefined' ? document.getElementById('vv-actions-header-mount') : null

  const markPosted = useCallback(() => {
    if (!window.confirm('Finalize (post) this voucher? This locks further changes for non-admin users.')) return
    const form = document.createElement('form')
    form.method = 'POST'
    form.action = postTarget
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = 'mark_posted'
    input.value = '1'
    form.appendChild(input)
    document.body.appendChild(form)
    form.submit()
  }, [postTarget])

  if (loading) {
    return <div className="vv-react-loading"><Loader2 size={22} style={{ verticalAlign: -5, marginRight: 8 }} />Loading voucher...</div>
  }
  if (error || !data) {
    return (
      <div className="vv-react-error">
        <h2>{error || 'Voucher unavailable'}</h2>
        <a
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
            {data.status?.label ? (
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

          <ApprovalFlowCard data={data} />

          <DocumentsCard
            data={data}
            onPreview={(url, kind, isImage = false) => setPreview({ open: true, url, kind, isImage: Boolean(isImage) })}
            onDeleteAttachment={deleteAttachment}
          />

          {data.comments.length > 0 && (
            <div style={{ marginTop: 24, borderTop: '1px solid #e5e7eb', paddingTop: 20 }} className="no-print">
              <h3 style={{ fontSize: 16, fontWeight: 600, color: '#111', marginBottom: 12 }}>Approval Comments</h3>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {data.comments.map((vc, i) => (
                  <div key={i} style={{ background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: 8, padding: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                      <span style={{ fontSize: 13, fontWeight: 600, color: '#374151' }}>
                        {vc.full_name} <span style={{ fontWeight: 400, color: '#6b7280' }}>({vc.action.replace(/_/g, ' ')})</span>
                      </span>
                      <span style={{ fontSize: 11, color: '#9ca3af' }}>{new Date(vc.created_at).toLocaleString()}</span>
                    </div>
                    <div style={{ fontSize: 14, color: '#1f2937', lineHeight: 1.5, whiteSpace: 'pre-wrap' }}>{vc.comments}</div>
                  </div>
                ))}
              </div>
            </div>
          )}
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
        onSuccess={() => window.location.reload()}
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
