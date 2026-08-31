import { useState } from 'react'
import {
  FileText, Download, ExternalLink, Copy, CheckCircle2, MessageCircle, Receipt,
} from 'lucide-react'

function DocCard({ title, subtitle, viewUrl, downloadUrl, tone = 'blue', icon: Icon = FileText }) {
  const tones = {
    blue: 'cv-doc-card--blue',
    green: 'cv-doc-card--green',
    purple: 'cv-doc-card--purple',
  }
  return (
    <div className={`cv-doc-card ${tones[tone] || tones.blue}`}>
      <div className="cv-doc-card-icon">
        <Icon size={22} aria-hidden="true" />
      </div>
      <div className="cv-doc-card-body">
        <strong>{title}</strong>
        {subtitle && <span>{subtitle}</span>}
      </div>
      <div className="cv-doc-card-actions">
        {viewUrl && (
          <a href={viewUrl} target="_blank" rel="noopener noreferrer" className="cv-doc-btn">
            <ExternalLink size={14} aria-hidden="true" /> View
          </a>
        )}
        {downloadUrl && (
          <a href={downloadUrl} target="_blank" rel="noopener noreferrer" className="cv-doc-btn cv-doc-btn--primary" download>
            <Download size={14} aria-hidden="true" /> Download
          </a>
        )}
      </div>
    </div>
  )
}

export default function OrderDocumentsSection({ documents, isClientSigned, shareUrl = '' }) {
  const [copied, setCopied] = useState(false)

  if (!documents?.hasDocuments) return null

  const dn = documents.deliveryNote
  const inv = documents.invoice
  const receipt = documents.receipt
  const canDownload = documents.canDownload || isClientSigned
  const clientLink = shareUrl || documents.shareUrl || ''

  async function copyLink() {
    if (!clientLink) return
    try {
      await navigator.clipboard.writeText(clientLink)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      /* ignore */
    }
  }

  function shareWhatsApp() {
    if (!clientLink) return
    const text = `View and download your delivery documents here: ${clientLink}`
    window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener')
  }

  return (
    <div className="cv-doc-list">
      {clientLink && (
        <div className="cv-doc-share">
          <p className="cv-doc-share-lead">
            Send this link to the client so they can view the delivery note, invoice, receipt, and sign on their phone.
          </p>
          <label className="cv-sign-share-label" htmlFor="od-doc-share-url">Client documents link</label>
          <div className="cv-sign-share-row">
            <input
              id="od-doc-share-url"
              type="text"
              readOnly
              value={clientLink}
              className="cv-input cv-sign-share-input"
            />
            <button type="button" className="cv-sign-icon-btn" onClick={copyLink} title="Copy link">
              {copied ? <CheckCircle2 size={16} /> : <Copy size={16} />}
            </button>
          </div>
          <button type="button" className="cv-wa-btn" onClick={shareWhatsApp}>
            <MessageCircle size={16} aria-hidden="true" /> Share via WhatsApp
          </button>
        </div>
      )}

      {!canDownload && (
        <p className="cv-hint">Documents are available. Client signature will stamp the delivery note for final download.</p>
      )}

      {dn && (
        <DocCard
          title={`Delivery Note ${dn.number}`}
          subtitle={canDownload ? 'Signed delivery note' : 'Preview � sign to finalize'}
          viewUrl={dn.viewUrl}
          downloadUrl={canDownload ? dn.downloadUrl : dn.viewUrl}
          tone="blue"
        />
      )}

      {inv && (
        <DocCard
          title={`Invoice ${inv.number}`}
          subtitle="Linked sales invoice"
          viewUrl={inv.publicUrl || inv.viewUrl}
          downloadUrl={inv.downloadUrl || inv.publicUrl || inv.viewUrl}
          tone="green"
        />
      )}

      {receipt && (
        <DocCard
          title="Receipt"
          subtitle="Attached proof of payment"
          viewUrl={receipt.viewUrl || receipt.url}
          downloadUrl={receipt.downloadUrl || receipt.url}
          tone="purple"
          icon={Receipt}
        />
      )}
    </div>
  )
}
