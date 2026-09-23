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

function withEmbedPreview(url) {
  if (!url) return url
  try {
    const u = new URL(url, window.location.href)
    u.searchParams.set('embed', '1')
    return u.pathname + u.search + u.hash
  } catch {
    return url + (String(url).includes('?') ? '&' : '?') + 'embed=1'
  }
}

function truncateName(name, max = 16) {
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

function DocPreviewThumb({ isImage, previewUrl, missing, kindLabel }) {
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
  if (previewUrl) {
    return (
      <div className="vv-gmail-thumb vv-gmail-thumb--embed">
        <iframe
          src={previewUrl}
          title="Document preview"
          loading="lazy"
          tabIndex={-1}
          scrolling="no"
          sandbox="allow-same-origin allow-scripts allow-popups"
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

function GmailAttachCard({
  iconClass,
  name,
  sub,
  onView,
  viewHref,
  downloadHref,
  previewUrl,
  isImage,
  onDelete,
  canDelete,
  missing,
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

  return (
    <article
      className={`vv-gmail-card${missing ? ' vv-gmail-card--missing' : ''}`}
      title={sub ? `${name}\n${sub}` : name}
    >
      <button type="button" className="vv-gmail-card-hit" onClick={open} disabled={!!missing} aria-label={`Open ${name}`}>
        <DocPreviewThumb
          isImage={!!isImage}
          previewUrl={
            previewUrl || viewHref || downloadHref
              ? withEmbedPreview(previewUrl || viewHref || downloadHref)
              : undefined
          }
          missing={missing}
          kindLabel={kind.label}
        />
      </button>

      <div className="vv-gmail-card-foot">
        <span className={`vv-gmail-type vv-gmail-type--${kind.tone}`} aria-hidden="true">
          {kind.label}
        </span>
        <span className="vv-gmail-fname">{truncateName(name)}</span>
        <span className="vv-gmail-dogear" aria-hidden="true" />
      </div>

      {!missing && (
        <div className="vv-gmail-card-actions">
          {downloadHref ? (
            <a
              href={downloadHref}
              download
              rel="noopener noreferrer"
              className="vv-gmail-action"
              title="Download"
              aria-label={`Download ${name}`}
              onClick={(e) => e.stopPropagation()}
            >
              <i className="fas fa-download" aria-hidden="true" />
            </a>
          ) : null}
          {canDelete && onDelete ? (
            <button
              type="button"
              className="vv-gmail-action vv-gmail-action--danger"
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
      )}
    </article>
  )
}

export default function DocumentsCard({ data, onPreview, onDeleteAttachment }) {
  const { attachments, salesOrderDocs, purchaseOrderDocs, swiftProxy, documents, permissions } = data
  const poDocs = Array.isArray(purchaseOrderDocs) ? purchaseOrderDocs : []
  if (!documents.hasSupporting && !documents.mismatch) return null

  const count = Number(documents.headerCount) || 0
  const countLabel = count === 1 ? '1 attachment' : `${count} attachments`

  return (
    <section className="vv-card vv-card-docs documents-card no-print" id="attachments">
      <div className="vv-card-head vv-gmail-head">
        <h2 className="vv-card-title vv-gmail-title">
          Supporting Documents
          <span className="vv-gmail-count"> · {countLabel}</span>
        </h2>
      </div>
      <div className="vv-card-body">
        <div className="vv-gmail-list" role="list">
          {salesOrderDocs.map((so) => (
            <GmailAttachCard
              key={`so-${so.id}`}
              iconClass="fa-file-pdf"
              name={`${so.orderNumber}.pdf`}
              sub="Sales Order"
              viewHref={so.pdfLink}
              downloadHref={so.pdfLink}
              previewUrl={so.pdfLink}
            />
          ))}

          {poDocs.map((po) => (
            <GmailAttachCard
              key={`po-${po.id}`}
              iconClass="fa-file-invoice"
              name={`${po.poNumber}.pdf`}
              sub={po.supplierName ? `Purchase Order — ${po.supplierName}` : 'Purchase Order'}
              viewHref={po.viewLink}
              downloadHref={po.viewLink}
              previewUrl={po.viewLink}
            />
          ))}

          {attachments.map((att) => (
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
              canDelete={permissions.canDeleteAttachment && att.id > 0}
              onDelete={() => onDeleteAttachment(att.id)}
            />
          ))}

          {swiftProxy && (
            <GmailAttachCard
              iconClass="fa-file-pdf"
              name="SWIFT Payment Proof.pdf"
              sub="PDF"
              onView={() => onPreview(swiftProxy, 'swift', false)}
              downloadHref={swiftProxy}
              previewUrl={swiftProxy}
            />
          )}
        </div>
      </div>
    </section>
  )
}
