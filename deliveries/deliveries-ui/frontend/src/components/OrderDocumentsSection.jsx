import { useRef, useState } from 'react'
import {
  FileText, Download, ExternalLink, Copy, CheckCircle2, MessageCircle, Receipt, Share2,
} from 'lucide-react'
import { copyTextToClipboard, shareOrCopyLink } from '../utils/clipboard.js'

function DocCard({ title, subtitle, viewUrl, downloadUrl, tone = 'blue', icon: Icon = FileText }) {
  const tones = {
    blue: 'cv-doc-card--blue',
    green: 'cv-doc-card--green',
    purple: 'cv-doc-card--purple',
  }
  return (
    <div className={`cv-doc-card ${tones[tone] || tones.blue}`}>
      <div className="cv-doc-card-icon">
        <Icon size={20} aria-hidden="true" />
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
  const [copyError, setCopyError] = useState('')
  const inputRef = useRef(null)
  const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function'

  if (!documents?.hasDocuments) return null

  const dn = documents.deliveryNote
  const inv = documents.invoice
  const receipt = documents.receipt
  const canDownload = documents.canDownload || isClientSigned
  const clientLink = shareUrl || documents.shareUrl || ''

  async function copyLink() {
    if (!clientLink) return
    setCopyError('')
    const ok = await copyTextToClipboard(clientLink, inputRef.current)
    if (ok) {
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
      return
    }
    try {
      inputRef.current?.focus()
      inputRef.current?.select()
      inputRef.current?.setSelectionRange(0, clientLink.length)
    } catch { /* ignore */ }
    setCopyError('Tap and hold the link, then choose Copy.')
  }

  async function shareLink() {
    if (!clientLink) return
    setCopyError('')
    const result = await shareOrCopyLink(
      {
        title: 'Delivery documents',
        text: `View and download your delivery documents here: ${clientLink}`,
        url: clientLink,
      },
      inputRef.current,
    )
    if (result === 'copied') {
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } else if (result === 'failed') {
      setCopyError('Tap and hold the link, then choose Copy.')
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
              ref={inputRef}
              id="od-doc-share-url"
              type="text"
              readOnly
              value={clientLink}
              className="cv-input cv-sign-share-input"
              onFocus={(e) => {
                e.target.select()
                try { e.target.setSelectionRange(0, e.target.value.length) } catch { /* ignore */ }
              }}
              onClick={(e) => {
                e.target.select()
                try { e.target.setSelectionRange(0, e.target.value.length) } catch { /* ignore */ }
              }}
            />
            <button type="button" className="cv-sign-icon-btn" onClick={copyLink} title="Copy link" aria-label="Copy link">
              {copied ? <CheckCircle2 size={16} /> : <Copy size={16} />}
            </button>
            {canNativeShare ? (
              <button type="button" className="cv-sign-icon-btn" onClick={shareLink} title="Share link" aria-label="Share link">
                <Share2 size={16} />
              </button>
            ) : null}
          </div>
          {copied ? <p className="cv-doc-share-status" role="status">Link copied</p> : null}
          {copyError ? <p className="cv-doc-share-status cv-doc-share-status--err">{copyError}</p> : null}
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
          subtitle={canDownload ? 'Signed delivery note' : 'Preview — sign to finalize'}
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
