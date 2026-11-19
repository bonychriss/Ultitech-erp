function fileKind(iconClass, name = '') {
  const lower = String(name).toLowerCase()
  if (iconClass.includes('fa-file-image') || /\.(png|jpe?g|gif|webp|bmp)$/i.test(lower)) {
    return { label: 'IMG', tone: 'image' }
  }
  if (/\.(xlsx?|csv)$/i.test(lower)) return { label: 'XLS', tone: 'sheet' }
  if (/\.(docx?)$/i.test(lower)) return { label: 'DOC', tone: 'doc' }
  if (iconClass.includes('fa-file-invoice') || iconClass.includes('fa-file-pdf') || /\.pdf$/i.test(lower)) {
    return { label: 'PDF', tone: 'pdf' }
  }
  return { label: 'FILE', tone: 'file' }
}

function withPoEmbed(url) {
  if (!url) return url
  try {
    const u = new URL(url, window.location.href)
    u.searchParams.set('embed', '1')
    return u.pathname + u.search + u.hash
  } catch {
    return url + (String(url).includes('?') ? '&' : '?') + 'embed=1'
  }
}

function isHtmlDocUrl(url) {
  const s = String(url || '')
  return /po-document\.php|view_po\.php|print.*\.php/i.test(s)
}

function pdfPreviewUrl(url) {
  if (!url) return url
  const base = String(url).split('#')[0]
  return `${base}#page=1&view=FitH&toolbar=0&navpanes=0&scrollbar=0`
}

function truncateName(name, max = 28) {
  const s = String(name || '')
  if (s.length <= max) return s
  const dot = s.lastIndexOf('.')
  if (dot > 0 && s.length - dot <= 5) {
    const ext = s.slice(dot)
    const base = s.slice(0, Math.max(1, max - ext.length - 1))
    return `${base}…${ext}`
  }
  return `${s.slice(0, max - 1)}…`
}

function DocPreviewThumb({ isImage, previewUrl, missing, kindLabel, htmlEmbed }) {
  if (missing) {
    return (
      <div className="vv-gmail-thumb vv-gmail-thumb--empty">
        <i className="fas fa-exclamation-triangle" aria-hidden="true" />
        <span>Unavailable</span>
      </div>
    )
  }
  if (isImage && previewUrl) {
    return (
      <div className="vv-gmail-thumb vv-gmail-thumb--image">
        <img src={previewUrl} alt="" loading="lazy" />
      </div>
    )
  }
  if (htmlEmbed && previewUrl) {
    return (
      <div className="vv-gmail-thumb vv-gmail-thumb--embed">
        <iframe
          src={withPoEmbed(previewUrl)}
          title="Document preview"
          loading="lazy"
          tabIndex={-1}
          scrolling="no"
          sandbox="allow-same-origin allow-scripts allow-popups"
        />
      </div>
    )
  }
  if (previewUrl && kindLabel === 'PDF') {
    return (
      <div className="vv-gmail-thumb vv-gmail-thumb--pdf">
        <iframe
          src={pdfPreviewUrl(previewUrl)}
          title="PDF preview"
          loading="lazy"
          tabIndex={-1}
          scrolling="no"
        />
      </div>
    )
  }
  return (
    <div className="vv-gmail-thumb vv-gmail-thumb--placeholder">
      <span className={`vv-gmail-thumb-badge vv-gmail-thumb-badge--${String(kindLabel).toLowerCase()}`}>
        {kindLabel}
      </span>
    </div>
  )
}

function paymentInstallmentLabel(index, total) {
  if (total <= 1) return 'Payment proof'
  const ordinals = ['First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth']
  const head = ordinals[index] || `${index + 1}th`
  return `${head} payment`
}

function GmailAttachCard({
  iconClass,
  name,
  sub,
  caption,
  onView,
  viewHref,
  downloadHref,
  previewUrl,
  isImage,
  onDelete,
  canDelete,
  missing,
  htmlEmbed,
}) {
  const kind = fileKind(iconClass, name)
  const open = () => {
    if (missing) return
    if (viewHref) {
      window.open(viewHref, '_blank', 'noopener,noreferrer')
      return
    }
    onView?.()
  }

  const thumbUrl = previewUrl || viewHref || downloadHref || undefined
  const captionText = String(caption || '').trim()

  return (
    <article
      className={`vv-gmail-card${missing ? ' vv-gmail-card--missing' : ''}${captionText ? ' vv-gmail-card--captioned' : ''}`}
      title={sub ? `${name}\n${sub}` : name}
      role="listitem"
    >
      <button type="button" className="vv-gmail-card-hit" onClick={open} disabled={!!missing} aria-label={`Open ${name}`}>
        <DocPreviewThumb
          isImage={!!isImage}
          previewUrl={thumbUrl}
          missing={missing}
          kindLabel={kind.label}
          htmlEmbed={htmlEmbed || isHtmlDocUrl(thumbUrl)}
        />
      </button>

      <div className="vv-gmail-card-foot">
        <span className={`vv-gmail-type vv-gmail-type--${kind.tone}`} aria-hidden="true">
          {kind.label}
        </span>
        <span className="vv-gmail-fname">{truncateName(name)}</span>
        {!missing && downloadHref ? (
          <a
            href={downloadHref}
            download
            rel="noopener noreferrer"
            className="vv-gmail-foot-action"
            title="Download"
            aria-label={`Download ${name}`}
            onClick={(e) => e.stopPropagation()}
          >
            <i className="fas fa-download" aria-hidden="true" />
          </a>
        ) : null}
        {!missing && canDelete && onDelete ? (
          <button
            type="button"
            className="vv-gmail-foot-action vv-gmail-foot-action--danger"
            title="Remove"
            aria-label={`Delete ${name}`}
            onClick={(e) => {
              e.stopPropagation()
              onDelete()
            }}
          >
            <i className="fas fa-trash" aria-hidden="true" />
          </button>
        ) : null}
      </div>
      <div className={`vv-gmail-card-caption${captionText ? '' : ' is-empty'}`} aria-hidden={!captionText}>
        {captionText || '\u00a0'}
      </div>
    </article>
  )
}

function DocsSection({ id, title, countLabel, children, footer }) {
  return (
    <section className="vv-card vv-card-docs documents-card no-print" id={id}>
      <div className="vv-card-head vv-gmail-head">
        <h2 className="vv-card-title vv-gmail-title">
          {title}
          {countLabel ? <span className="vv-gmail-count"> · {countLabel}</span> : null}
        </h2>
      </div>
      <div className="vv-card-body">
        <div className="vv-gmail-list" role="list">
          {children}
        </div>
        {footer || null}
      </div>
    </section>
  )
}

export default function DocumentsCard({ data, onPreview, onDeleteAttachment, onUnlinkOrder }) {
  const {
    attachments,
    purchaseOrderDocs,
    salesOrderDocs,
    swiftProofs,
    swiftProxy,
    documents,
    permissions,
  } = data
  const poDocs = Array.isArray(purchaseOrderDocs) ? purchaseOrderDocs : []
  const soDocs = Array.isArray(salesOrderDocs) ? salesOrderDocs : []
  const supportFiles = Array.isArray(attachments) ? attachments : []
  const proofs = Array.isArray(swiftProofs) && swiftProofs.length > 0
    ? swiftProofs
    : (swiftProxy
      ? [{
          id: 0,
          name: 'SWIFT Payment Proof.pdf',
          proxyLink: swiftProxy,
          isImage: false,
          typeLabel: 'PDF',
          label: 'Payment proof',
          missing: false,
        }]
      : [])

  const supportCount = supportFiles.length + soDocs.length + poDocs.length
  const proofCount = proofs.length
  if (supportCount === 0 && proofCount === 0 && !documents?.mismatch) return null

  const supportLabel = String(supportCount)
  const proofLabel = String(proofCount)

  return (
    <div className="vv-docs-stack no-print">
      {(supportCount > 0 || documents?.mismatch) ? (
        <DocsSection id="attachments" title="Supporting Documents" countLabel={supportCount > 0 ? supportLabel : null}>
          {soDocs.map((so) => {
            const viewUrl = withPoEmbed(String(so.pdfLink || '').replace(/([?&])download=1(&|$)/, '$1').replace(/[?&]$/, ''))
            const downloadUrl = (() => {
              const base = String(so.pdfLink || '').replace(/([?&])download=1(&|$)/, '$1').replace(/[?&]$/, '')
              if (!base) return undefined
              return base + (base.includes('?') ? '&' : '?') + 'autodownload=1'
            })()
            return (
              <GmailAttachCard
                key={`so-${so.id}`}
                iconClass="fa-file-pdf"
                name={`${so.orderNumber}.pdf`}
                sub="Sales Order"
                onView={() => onPreview(viewUrl, 'supporting', false)}
                downloadHref={downloadUrl}
                previewUrl={viewUrl}
                htmlEmbed
                canDelete={!!permissions.canUnlinkOrders}
                onDelete={onUnlinkOrder ? () => onUnlinkOrder('sales', so) : undefined}
              />
            )
          })}

          {supportFiles.map((att) => (
            <GmailAttachCard
              key={att.id}
              iconClass={att.isImage ? 'fa-file-image' : 'fa-file-pdf'}
              name={att.name}
              sub={att.fileSizeLabel || att.typeLabel}
              isImage={!!att.isImage}
              missing={!!att.missing}
              onView={att.missing ? undefined : () => onPreview(att.proxyLink, 'supporting', att.isImage)}
              downloadHref={att.missing ? undefined : att.proxyLink}
              previewUrl={att.missing ? undefined : att.proxyLink}
              canDelete={permissions.canDeleteAttachment && att.id !== 0}
              onDelete={() => onDeleteAttachment(att)}
            />
          ))}

          {poDocs.map((po) => (
            <GmailAttachCard
              key={`po-${po.id}`}
              iconClass="fa-file-invoice"
              name={`${po.poNumber || `PO-${po.id}`}.pdf`}
              sub={po.createdBy
                ? `Purchase Order \u00b7 Created by ${po.createdBy}`
                : 'Purchase Order'}
              viewHref={po.viewLink}
              downloadHref={po.viewLink}
              previewUrl={po.viewLink}
              htmlEmbed
              canDelete={!!permissions.canUnlinkOrders}
              onDelete={onUnlinkOrder ? () => onUnlinkOrder('purchase', po) : undefined}
            />
          ))}
        </DocsSection>
      ) : null}

      {proofCount > 0 ? (
        <DocsSection
          id="swift-proofs"
          title="SWIFT Payment Proofs"
          countLabel={proofLabel}
        >
          {proofs.map((proof, idx) => (
            <GmailAttachCard
              key={`swift-${proof.id || proof.fileName || idx}`}
              iconClass={proof.isImage ? 'fa-file-image' : 'fa-file-pdf'}
              name={proof.name || `SWIFT Payment Proof ${idx + 1}.pdf`}
              sub={proof.label || proof.fileSizeLabel || proof.typeLabel || 'Payment proof'}
              caption={paymentInstallmentLabel(idx, proofs.length)}
              isImage={!!proof.isImage}
              missing={!!proof.missing}
              onView={proof.missing || !proof.proxyLink
                ? undefined
                : () => onPreview(proof.proxyLink, 'swift', !!proof.isImage)}
              downloadHref={proof.missing ? undefined : proof.proxyLink}
              previewUrl={proof.missing ? undefined : proof.proxyLink}
            />
          ))}
        </DocsSection>
      ) : null}
    </div>
  )
}
